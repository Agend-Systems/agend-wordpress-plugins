<?php
/**
 * Block editor enforcement: per-block policies and display conditions.
 *
 * @package Agend_Content_Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Removes the blocks a visitor may not see, and keeps the response and the
 * search results from giving them away.
 *
 * This is the block editor's equivalent of `Agend_Content_Access_Elementor`,
 * and the decision it makes is the same one: a block carries an `agendAccess`
 * attribute (see `Agend_Content_Access_Block_Attributes`), which reads as the
 * neutral fragment settings every editor shares, and the block is shown only
 * when the display conditions pass and the visitor satisfies the INTERSECTION
 * of the post's policy and the block's own. Suppression only ever removes. It
 * cannot reveal a block the document policy hides.
 *
 * It is an access-control boundary: it decides whether member-only content
 * inside an otherwise public page reaches a visitor, so every path that is not
 * clearly a grant is a denial.
 *
 * WHY `pre_render_block`. It fires for every block, static and dynamic, before
 * the block renders anything. Returning a non-null string from it skips the
 * block AND its inner blocks, so a denied container never constructs its
 * children, and no `render_callback` runs for content the visitor may not read.
 * `render_block` filters output that was already produced, with every side
 * effect already executed, which is the wrong order for access control.
 *
 * WHY `render_block` IS ALSO REGISTERED, AS A BACKSTOP. `pre_render_block` is
 * asked by `WP_Block::render()` for each inner block and by `render_block()` for
 * each top level one, but a block whose render callback renders its own children
 * (Navigation, Navigation Submenu, Navigation Link, Widget Group) calls
 * `$inner_block->render()` directly and never asks. A restriction on a link or a
 * submenu in a navigation was therefore never applied. `render_block` runs inside
 * `WP_Block::render()` for every block however it was reached, so it applies the
 * same decision to what a direct render produced. It only ever does work for a
 * block the main path did not already judge: see `render_block()`.
 *
 * WHY NOT THE DOCUMENT FOR THE QUERIED OBJECT. The Elementor driver reads the
 * document policy of `get_queried_object_id()`, which is right where a document
 * renders for the queried post. Blocks render inside query loops, template
 * parts and synced patterns, where the queried object is not the post the block
 * belongs to. In a loop the post a block belongs to is the post WordPress is
 * rendering, `get_post()`, and that is what this reads. Reading the queried object
 * there would judge a restricted post's block in a loop on a public page against
 * the public page, and fail open. Outside a loop the global post is only what the
 * main query left behind, and the queried object, or nothing, is the answer: see
 * `context_post_id()`.
 *
 * Four leak vectors beyond the render itself are closed here, and each fails
 * silently if it is missed: page caches (`mark_page_uncacheable()` and the
 * render-time marking), core search (`exclude_from_search()`), the authoring
 * carve-out (`Agend_Content_Access_Frontend::is_authoring_context()`), and a
 * display-condition fallback that is itself restricted (`fallback_for()`).
 */
class Agend_Content_Access_Blocks {

	/**
	 * Post meta flag: this document carries a fragment policy or enabled display
	 * conditions somewhere in it, block or Elementor.
	 *
	 * A block document is flagged from its block tree, synced patterns included;
	 * an Elementor document from its decoded `_elementor_data`. Both editors
	 * write the same flag so core search and the generated excerpt ask one
	 * question and cannot disagree about what a rule is.
	 *
	 * The key lives on `Agend_Content_Access_Frontend`, which does not depend on
	 * this class and also reads it. Protected (underscore) so it is not editable
	 * as a custom field and, never having been registered for REST, is not exposed
	 * there. Read by the search exclusion. Page caching never trusts it, because a
	 * flag can be stale (see `exclude_from_search()`); that decision scans the
	 * live tree.
	 *
	 * @var string
	 */
	const SEARCH_FLAG_META_KEY = Agend_Content_Access_Frontend::FRAGMENT_RULES_META_KEY;

	/**
	 * Priority of the `pre_get_posts` search exclusion: last.
	 *
	 * Another plugin's `pre_get_posts` callback may replace the `meta_query` this
	 * one wraps. Running after all of them means the exclusion is applied to the
	 * query WordPress is about to run, and nothing can overwrite it.
	 *
	 * @var int
	 */
	const SEARCH_EXCLUSION_PRIORITY = PHP_INT_MAX;

	/**
	 * Priority of the `pre_render_block` callback: first.
	 *
	 * A later callback may return its own markup for a block, a block-level
	 * cache does, and this callback honours a non-null value it is handed. Going
	 * first means a denied block is already `''` by the time any of them sees it,
	 * rather than whatever they would have produced.
	 *
	 * @var int
	 */
	const PRE_RENDER_PRIORITY = PHP_INT_MIN;

	/**
	 * The post meta key core stores a post's footnotes under: a JSON list of
	 * `{id, content}`, rendered by the Footnotes block and, as the registered
	 * `meta.footnotes` field, public in REST.
	 *
	 * @var string
	 */
	const FOOTNOTES_META_KEY = Agend_Content_Access_Frontend::FOOTNOTES_META_KEY;

	/**
	 * Priority of the `render_block` backstop: first, so a denied block is already
	 * `''` before any other filter on its output sees it.
	 *
	 * @var int
	 */
	const BACKSTOP_PRIORITY = PHP_INT_MIN;

	/**
	 * Priority of the `template_redirect` cache marking. After the page gate
	 * (priority 1), which may end the request with a 403 and has nothing to mark.
	 *
	 * @var int
	 */
	const TEMPLATE_REDIRECT_PRIORITY = 2;

	/**
	 * Posts this request has already been marked uncacheable for, by post id, so
	 * the action cache integrations listen on fires once per post and not once
	 * per block.
	 *
	 * @var array<int, true>
	 */
	private array $marked = array();

	/**
	 * Display-condition fallback posts being rendered right now, by post id.
	 *
	 * A fallback is rendered through `the_content`, so it is itself made of
	 * blocks that may carry conditions. A fallback that holds a block whose
	 * fallback is the same post would otherwise recurse until PHP gave up.
	 *
	 * @var array<int, true>
	 */
	private array $fallbacks_open = array();

	/**
	 * Blocks the `pre_render_block` path has judged and allowed, not yet seen by
	 * the `render_block` backstop: a count per block fingerprint.
	 *
	 * What stops the backstop deciding a block twice. `pre_render_block` never
	 * receives the `WP_Block` instance, which does not exist yet for an inner
	 * block, so a block cannot be remembered by instance. It is remembered by what
	 * decides it: the post it is judged against, its name and its `agendAccess`
	 * attribute. Two blocks with that in common get the same answer, so letting
	 * one stand in for the other is sound, and a count rather than a flag keeps N
	 * identical blocks accounted for N times.
	 *
	 * @var array<string, int>
	 */
	private array $granted = array();

	/**
	 * Whether the footnotes meta is being read by this class right now.
	 *
	 * The footnotes filter reads the stored value to filter it, and that read
	 * comes back through the same filter.
	 *
	 * @var bool
	 */
	private bool $reading_footnotes = false;

	/**
	 * Registers hooks. Called on every request, front end and REST alike: a REST
	 * request never reaches `template_redirect`, and renders blocks all the same.
	 */
	public function __construct() {
		add_filter( 'pre_render_block', array( $this, 'pre_render_block' ), self::PRE_RENDER_PRIORITY, 3 );
		add_filter( 'render_block', array( $this, 'render_block' ), self::BACKSTOP_PRIORITY, 3 );
		add_action( 'template_redirect', array( $this, 'mark_page_uncacheable' ), self::TEMPLATE_REDIRECT_PRIORITY );
		add_action( 'save_post', array( $this, 'sync_search_flag' ), 20 );
		add_action( 'elementor/document/after_save', array( $this, 'sync_search_flag_after_elementor_save' ) );
		add_action( 'pre_get_posts', array( $this, 'exclude_from_search' ), self::SEARCH_EXCLUSION_PRIORITY );
		add_filter( 'get_post_metadata', array( $this, 'filter_footnotes_meta' ), 10, 4 );

		// The block half of the generated excerpt: `Agend_Content_Access_Frontend`
		// owns the `get_the_excerpt` filter and asks this for the safe text.
		add_filter( 'agend_content_access_safe_generated_excerpt', array( $this, 'safe_generated_excerpt' ), 10, 2 );
	}

	// -----------------------------------------------------------------------
	// Suppression
	// -----------------------------------------------------------------------

	/**
	 * Removes a block the current visitor may not see.
	 *
	 * Returns the incoming value untouched when it is already non-null, because
	 * something earlier chose to render this block itself. Otherwise null, which
	 * lets WordPress render the block, or a string, which replaces it entirely:
	 * its inner blocks included.
	 *
	 * A block with neither a policy nor enabled conditions returns before any
	 * identity lookup, which is nearly every block on every page. That is the
	 * whole of the cost of this plugin to a page that does not use it.
	 *
	 * @param string|null           $pre          Short-circuit value so far.
	 * @param array                 $parsed_block The block, as `parse_blocks()` returns it.
	 * @param WP_Block|object|null  $parent_block Parent block, when this is an inner block.
	 * @return string|null Null to render the block, a string to render in its place.
	 */
	public function pre_render_block( $pre, $parsed_block = array(), $parent_block = null ) {
		if ( null !== $pre || ! is_array( $parsed_block ) ) {
			return $pre;
		}

		$rules = self::rules_of( $parsed_block );

		// The common case, and it must stay this cheap: no attribute, or one
		// that only says `inherit`, is a block like any other.
		if ( null === $rules ) {
			return null;
		}

		$verdict = $this->verdict_for( $parsed_block, $rules );

		if ( null === $verdict ) {
			$this->remember_grant( $parsed_block );
		}

		return $verdict;
	}

	/**
	 * Applies the same decision to a block that core rendered WITHOUT asking
	 * `pre_render_block`: the backstop.
	 *
	 * Navigation, Navigation Submenu, Navigation Link and Widget Group render
	 * their children by calling `$inner_block->render()` themselves, which never
	 * reaches `pre_render_block`, so a restriction on one of those children was
	 * never applied. `WP_Block::render()` ends by filtering the block's output
	 * through `render_block` whichever way it was reached, so this is where a
	 * block that skipped the front door is judged.
	 *
	 * It is a BACKSTOP and not the enforcement point: by now the block has
	 * rendered, its callback has run and its side effects have happened. What it
	 * guarantees is that no output of a denied block is returned, and that is the
	 * property that reaches the visitor. The main path stays the one that keeps a
	 * denied block from rendering at all, for the blocks that go through it.
	 *
	 * A block the main path already judged and allowed is not judged again
	 * (`$granted`). One it denied never gets here, because a short-circuited block
	 * is not rendered. What is left is exactly the blocks that skipped the front
	 * door, which are decided here from the parsed block as it stands NOW, after
	 * every `render_block_data` filter, so a block altered on the way in is judged
	 * as it will be shown.
	 *
	 * Returns the replacement for a denied block, which is `''` or the display
	 * condition fallback, exactly as the main path would have. Marks the response
	 * uncacheable, as the main path does, because the verdict is made the same way.
	 *
	 * @param string               $content      The block's rendered output.
	 * @param array                $parsed_block The block, as it stood when it rendered.
	 * @param WP_Block|object|null $instance     The block instance. Not needed to decide.
	 * @return string The output to keep.
	 */
	public function render_block( $content, $parsed_block = array(), $instance = null ) {
		if ( ! is_string( $content ) || ! is_array( $parsed_block ) ) {
			return $content;
		}

		$rules = self::rules_of( $parsed_block );

		if ( null === $rules || $this->consume_grant( $parsed_block ) ) {
			return $content;
		}

		$verdict = $this->verdict_for( $parsed_block, $rules );

		return null === $verdict ? $content : $verdict;
	}

	/**
	 * What a block carries that can decide it, or null when it carries nothing.
	 *
	 * @param array $parsed_block The block, as `parse_blocks()` returns it.
	 * @return array{settings: array, conditions: bool, fragment: array|null}|null
	 */
	private static function rules_of( array $parsed_block ): ?array {
		$settings   = Agend_Content_Access_Block_Attributes::settings_for_block( $parsed_block );
		$conditions = Agend_Content_Access_Fragment_Rules::conditions_enabled( $settings );
		$fragment   = Agend_Content_Access_Policy::from_fragment_settings( $settings );

		if ( null === $fragment && ! $conditions ) {
			return null;
		}

		return array(
			'settings'   => $settings,
			'conditions' => $conditions,
			'fragment'   => $fragment,
		);
	}

	/**
	 * The verdict on a block that carries rules: null to let it render, or the
	 * string to render in its place.
	 *
	 * Shared by the `pre_render_block` path and the `render_block` backstop, so the
	 * two cannot disagree. Every path that is not clearly a grant is a denial.
	 *
	 * @param array    $parsed_block The block.
	 * @param array    $rules        From `rules_of()`.
	 * @param int|null $post_id      The post the block belongs to, when the caller
	 *                               knows it and it is not the one being rendered.
	 *                               Null is the post in context.
	 * @return string|null
	 */
	private function verdict_for( array $parsed_block, array $rules, ?int $post_id = null ): ?string {
		if ( self::is_authoring() ) {
			return null;
		}

		$settings   = $rules['settings'];
		$conditions = $rules['conditions'];
		$fragment   = $rules['fragment'];
		$post_id    = null === $post_id ? self::context_post_id() : $post_id;

		// A FEED is judged for nobody. Its output is shared and routinely cached,
		// so a copy personalised to whoever fetched it would be served to everyone
		// after them: the page gate does the same for a document
		// (`Decision::for_post()` with `$anonymous`).
		$anonymous = is_feed();

		// Marked whatever the verdict. The block is personalised, so the page is
		// per-viewer: see `mark_uncacheable()`.
		$this->mark_uncacheable( $post_id );

		try {
			// Display conditions first, as Elementor does. They can only remove,
			// so a block hidden here is hidden whatever the policy says. Never
			// null from here: a block the conditions excluded must not fall
			// through to rendering.
			if (
				$conditions
				&& ! Agend_Content_Access_Fragment_Rules::conditions_pass(
					$settings,
					$anonymous ? Agend_Content_Access_Condition_Runtime::anonymous_checker() : null
				)
			) {
				return $this->fallback_for( $settings, $anonymous );
			}

			if ( null === $fragment ) {
				// Inherits the post, which the page-level gate already handled.
				return null;
			}

			// The enforced reading, of the post the block belongs to: an
			// unreadable document policy narrows the block to nobody instead of
			// vanishing and leaving the fragment alone. No post in context is a
			// block outside any post (a widget, a bare template part), which has
			// no document policy to narrow with, and the block's own still applies.
			$document  = $post_id > 0 ? Agend_Content_Access_Policy::enforced_for_post( $post_id ) : null;
			$effective = Agend_Content_Access_Policy::effective_policy( $document, $fragment );

			// The viewer is built for THIS policy, so segments are fetched only
			// when the effective policy of this block is a segments policy.
			$state = Agend_Content_Access_Decision::evaluate(
				$effective,
				$anonymous ? Agend_Content_Access_Decision::anonymous_viewer() : Agend_Content_Access_Decision::viewer( $effective )
			);

			return Agend_Content_Access_Decision::STATE_GRANTED === $state ? null : '';
		} catch ( \Throwable $error ) {
			// A decision that could not be made is a denial. The log names the
			// failure for an administrator and nothing reaches the visitor.
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				'[Agend Content Access] Denied a block: its access check failed (' . get_class( $error ) . ').'
			);

			return '';
		}
	}

	/**
	 * Records that the main path judged a block and allowed it.
	 *
	 * @param array $parsed_block The block.
	 */
	private function remember_grant( array $parsed_block ): void {
		$key = self::fingerprint( $parsed_block );

		$this->granted[ $key ] = ( $this->granted[ $key ] ?? 0 ) + 1;
	}

	/**
	 * Uses up one recorded grant for the block, if there is one.
	 *
	 * @param array $parsed_block The block.
	 * @return bool Whether the main path had already allowed this block.
	 */
	private function consume_grant( array $parsed_block ): bool {
		$key = self::fingerprint( $parsed_block );

		if ( empty( $this->granted[ $key ] ) ) {
			return false;
		}

		if ( 0 === --$this->granted[ $key ] ) {
			unset( $this->granted[ $key ] );
		}

		return true;
	}

	/**
	 * What decides a block: the post it is judged against, its name and its
	 * `agendAccess` attribute.
	 *
	 * Serialised rather than JSON encoded, because the attribute is whatever the
	 * stored markup held, and an encoder that fails on it would give every such
	 * block the same key.
	 *
	 * @param array $parsed_block The block.
	 * @return string
	 */
	private static function fingerprint( array $parsed_block ): string {
		$attrs = isset( $parsed_block['attrs'] ) && is_array( $parsed_block['attrs'] ) ? $parsed_block['attrs'] : array();

		return self::context_post_id() . ':' . md5(
			serialize( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Never unserialised: only hashed.
				array(
					isset( $parsed_block['blockName'] ) ? $parsed_block['blockName'] : null,
					isset( $attrs[ Agend_Content_Access_Block_Attributes::ATTRIBUTE ] ) ? $attrs[ Agend_Content_Access_Block_Attributes::ATTRIBUTE ] : null,
				)
			)
		);
	}

	/**
	 * Whether this render is somebody authoring, who is never filtered.
	 *
	 * The same question the loop gates ask (`renders_for_visitors()`), so the two
	 * cannot disagree about an admin-ajax render, which WordPress reports as
	 * `is_admin()` and which anonymous visitors reach through "load more" and
	 * filter widgets. `is_authoring_context()` alone would call that authoring.
	 *
	 * And the user must be able to `edit_posts`. `is_authoring_context()` accepts
	 * any wp-admin request, and a subscriber on a wp-admin screen, or a visitor
	 * on `admin-post.php`, is not authoring anything. Blocks are not exempt for
	 * editors on the front end either, as Elementor sections are not: only the
	 * editor's own renders are, which is what keeps an author able to repair the
	 * content a policy hides from them.
	 *
	 * @return bool
	 */
	private static function is_authoring(): bool {
		return ! Agend_Content_Access_Frontend::renders_for_visitors()
			&& current_user_can( 'edit_posts' );
	}

	/**
	 * The id of the post a block belongs to, or 0 when it belongs to none.
	 *
	 * Inside a loop it is the post WordPress is rendering, `get_post()`: set by the
	 * main loop, by every `WP_Query` loop (`the_post()`, which a query loop block
	 * calls per item), and by the REST controllers. Never `get_queried_object_id()`
	 * there: see the class docblock.
	 *
	 * OUTSIDE a loop the global post is not that post. WordPress sets it to the
	 * first post of the MAIN query before any template runs, and leaves it at the
	 * last once a loop has finished, so a header or footer template part on an
	 * archive was judged against whichever post happened to be listed first or
	 * last. That narrowed a block to the plan of an unrelated post and hid it from
	 * a member who was entitled to it. Outside a loop the block belongs to the
	 * queried post when the request is for one post, and to nothing otherwise: it is
	 * judged on its own policy alone, as a block in a widget is.
	 *
	 * "Inside a loop" is NOT `in_the_loop()` alone, which is true only for the main
	 * query's loop. A custom `WP_Query` loop, which is what a Query Loop block with
	 * its own query runs, leaves `in_the_loop()` false while the global post is the
	 * item being rendered, and treating that as "outside" would judge its blocks
	 * against the page and fail open. The global post is the main query's leftover
	 * only when it IS one of the main query's own post objects: a post read by any
	 * other query, or by a REST controller, is a distinct object even when it is the
	 * same post, and is a loop post.
	 *
	 * A synced pattern renders inside the post that holds it, so its blocks belong
	 * to that post for this purpose, which can only narrow them further.
	 *
	 * @return int
	 */
	private static function context_post_id(): int {
		$post = get_post();

		if ( $post instanceof WP_Post && ( in_the_loop() || ! self::is_main_query_leftover( $post ) ) ) {
			return (int) $post->ID;
		}

		$queried = is_singular() ? get_queried_object() : null;

		return $queried instanceof WP_Post ? (int) $queried->ID : 0;
	}

	/**
	 * Whether the post object is one the main query holds, which outside a loop is
	 * only ever the global post WordPress left behind.
	 *
	 * Compared by identity. The main query's `post` is the object the global post
	 * is set from, and its `posts` are the objects a loop steps through. Looked up
	 * on `$wp_the_query`, the real main query, and on `$wp_query`, which a theme can
	 * point at another query.
	 *
	 * @param WP_Post $post The global post.
	 * @return bool
	 */
	private static function is_main_query_leftover( WP_Post $post ): bool {
		foreach ( array( 'wp_the_query', 'wp_query' ) as $name ) {
			$query = isset( $GLOBALS[ $name ] ) && is_object( $GLOBALS[ $name ] ) ? $GLOBALS[ $name ] : null;

			if ( null === $query ) {
				continue;
			}

			if ( isset( $query->post ) && $query->post === $post ) {
				return true;
			}

			if ( isset( $query->posts ) && is_array( $query->posts ) && in_array( $post, $query->posts, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The replacement content for a block the display conditions excluded.
	 *
	 * Only a block the CONDITIONS excluded gets one. A block the access policy
	 * hides is protected content, and a consolation render there would blur the
	 * two, as it does for Elementor. The fallback is itself access-checked by
	 * `Fragment_Rules::fallback_html()`: only content this visitor could have
	 * read on its own is shown.
	 *
	 * @param array $settings  The block's neutral settings.
	 * @param bool  $anonymous Judge the fallback for nobody, as in a feed.
	 * @return string The fallback markup, or '' for none.
	 */
	private function fallback_for( array $settings, bool $anonymous = false ): string {
		$fallback_id = isset( $settings[ Agend_Content_Access_Fragment_Rules::FALLBACK_KEY ] )
			? (int) $settings[ Agend_Content_Access_Fragment_Rules::FALLBACK_KEY ]
			: 0;

		// A fallback already being rendered is a cycle, and renders nothing.
		if ( $fallback_id <= 0 || isset( $this->fallbacks_open[ $fallback_id ] ) ) {
			return '';
		}

		$this->fallbacks_open[ $fallback_id ] = true;

		try {
			return Agend_Content_Access_Fragment_Rules::fallback_html( $fallback_id, $anonymous );
		} finally {
			unset( $this->fallbacks_open[ $fallback_id ] );
		}
	}

	// -----------------------------------------------------------------------
	// Page caches
	// -----------------------------------------------------------------------

	/**
	 * Keeps a page whose post holds a restricted or conditioned block out of page
	 * caches, decided before anything renders.
	 *
	 * Runs on `template_redirect` for a singular request and scans the queried
	 * post's blocks, synced patterns included. This is the early decision: in a
	 * classic theme output has usually begun before the first block renders, and
	 * `Cache-Control` can only be sent while headers are unsent, so a mark left to
	 * render time would carry the constant alone. A restricted block in a loop, a
	 * template part or a widget is not in this post's content, and is caught by
	 * the render-time marking in `pre_render_block()` instead.
	 *
	 * The scan is live, never the saved search flag: a pattern edited after the
	 * page was saved changes what the page holds without touching the flag, and
	 * a cache decision that is stale in that direction serves a member's copy.
	 */
	public function mark_page_uncacheable(): void {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_queried_object();

		if ( ! $post instanceof WP_Post ) {
			return;
		}

		if ( self::tree_carries_rules( (string) $post->post_content ) ) {
			$this->mark_uncacheable( (int) $post->ID );
		}
	}

	/**
	 * Marks the response uncacheable, through the frontend's own marking.
	 *
	 * ANONYMOUS RENDERS ARE MARKED TOO. It is tempting to mark only a page a
	 * member saw, as the document gate does, since an anonymous render of a
	 * restricted block is just the block's absence. But a page with a restricted
	 * or conditioned block is PER-VIEWER for everybody, signed out included.
	 * A page cache keys on the URL, not on who asked, and a member of Agend is
	 * routinely not signed in to WordPress at all (their standing arrives as a
	 * bearer, which a cache does not see), so the cache cannot tell the two
	 * apart. Cache the anonymous copy and every member is served it, without the
	 * block they are entitled to; cache a member's, and every anonymous visitor
	 * is served the restricted block and the gate never runs. Personalisation
	 * for a signed-out visitor is still personalisation, so neither copy may be
	 * stored.
	 *
	 * @param int $post_id Post whose render carries the block, 0 for none.
	 */
	private function mark_uncacheable( int $post_id ): void {
		if ( isset( $this->marked[ $post_id ] ) ) {
			return;
		}

		$this->marked[ $post_id ] = true;

		Agend_Content_Access_Frontend::mark_uncacheable( $post_id );
	}

	// -----------------------------------------------------------------------
	// The tree check, shared by the cache marking and the search flag
	// -----------------------------------------------------------------------

	/**
	 * Whether the content holds a block with an audience policy or display
	 * conditions, anywhere in its tree.
	 *
	 * The tree is the parsed blocks with every synced pattern expanded in place
	 * (`Block_Attributes::expand()`, with its cycle, depth and budget guards), so
	 * a restriction inside a pattern counts for the page that uses it. A block
	 * counts by the same two tests the render path uses
	 * (`carries_policy()`, `carries_conditions()`), so the answer here and the
	 * decision at render time cannot disagree about what a rule is. A rule in a
	 * template part or a query loop's other posts is not in the content, and is
	 * not seen here.
	 *
	 * Content with no `wp:` in it has no block delimiter and cannot hold a block,
	 * so it is not parsed. That is the one shortcut, and it is a safe one:
	 * the parser requires the literal `wp:` after the comment opener. Not
	 * `has_blocks()`, which looks for `<!-- wp:` with exactly one space, and
	 * misses a delimiter the parser accepts.
	 *
	 * @param string $content Raw `post_content`.
	 * @return bool
	 */
	public static function tree_carries_rules( string $content ): bool {
		if ( false === strpos( $content, 'wp:' ) ) {
			return false;
		}

		$ledger = Agend_Content_Access_Block_Attributes::ledger();
		$blocks = Agend_Content_Access_Block_Attributes::expand( parse_blocks( $content ), $ledger );

		return self::blocks_carry_rules( $blocks );
	}

	/**
	 * Whether any of the blocks, or anything inside them, carries a rule.
	 *
	 * @param array $blocks Parsed blocks, already expanded.
	 * @return bool
	 */
	private static function blocks_carry_rules( array $blocks ): bool {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			if (
				Agend_Content_Access_Block_Attributes::carries_policy( $block )
				|| Agend_Content_Access_Block_Attributes::carries_conditions( $block )
			) {
				return true;
			}

			if (
				isset( $block['innerBlocks'] )
				&& is_array( $block['innerBlocks'] )
				&& self::blocks_carry_rules( $block['innerBlocks'] )
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a document carries a fragment policy or enabled display conditions,
	 * whichever editor wrote it.
	 *
	 * Elementor owns a document that has `_elementor_data`, exactly as the
	 * exporter decides (`Agend_Content_Access_Elementor_Parser::recognises()`),
	 * whatever `post_content` holds: Elementor writes a flattened copy of what it
	 * rendered into `post_content`, which is not a block tree and says nothing
	 * about the rules. Those documents are read from the decoded data with
	 * `Agend_Content_Access_Elementor::subtree_carries_policy()`, the same test the
	 * element cache exclusion uses. Everything else is read from its block tree.
	 *
	 * An Elementor document that does not decode is reported as carrying rules. It
	 * cannot be shown to have none, and the cost of a false yes is a post left out
	 * of search and an excerpt left blank, where a false no publishes text.
	 *
	 * @param WP_Post|mixed $post Post.
	 * @return bool
	 */
	public static function document_carries_rules( $post ): bool {
		if ( ! $post instanceof WP_Post ) {
			return false;
		}

		$raw = trim( (string) get_post_meta( (int) $post->ID, Agend_Content_Access_Elementor_Parser::META_KEY, true ) );

		if ( '' === $raw ) {
			return self::tree_carries_rules( (string) $post->post_content );
		}

		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) ) {
			return true;
		}

		foreach ( $decoded as $node ) {
			if ( ! is_array( $node ) || Agend_Content_Access_Elementor::subtree_carries_policy( $node ) ) {
				return true;
			}
		}

		return false;
	}

	// -----------------------------------------------------------------------
	// Footnotes
	// -----------------------------------------------------------------------

	/**
	 * Keeps the footnotes of a block the viewer may not see out of the footnotes
	 * block and out of REST.
	 *
	 * WHY. A footnote's text is not in the paragraph that cites it. The paragraph
	 * holds only an anchor, `<sup data-fn="<uuid>">`, and the text lives in the
	 * post's `footnotes` meta, which the Footnotes block renders in full and which
	 * is public in REST as `meta.footnotes`. Removing a restricted paragraph left
	 * its footnote readable in both places.
	 *
	 * WHAT. When the post holds a ruled block, each footnote whose id appears
	 * inside a ruled block THIS VIEWER is denied is removed from the value read.
	 * One filter on `get_post_metadata` covers both readers, since the block reads
	 * the meta with `get_post_meta()` and the REST field with `get_metadata()`.
	 * Whether a block is denied is the verdict the render path gives
	 * (`verdict_for()`, against the post whose meta it is), so the footnotes of a
	 * block a member may read stay for that member. The footnote list is not
	 * renumbered: the anchors in the text carry their own numbers, and a gap is
	 * what removing a paragraph leaves in a document anyway.
	 *
	 * FAIL CLOSED, because the mapping is by id and ids can be unreadable. A post
	 * that holds a ruled block and whose footnotes meta is not a JSON list returns
	 * no footnotes at all. So does an entry with no id, but only when a block was
	 * denied: with nothing denied there is nothing it could belong to. An id is
	 * matched anywhere in a denied block's markup and attributes, not just in a
	 * `data-fn` attribute, so an anchor written some other way still counts.
	 *
	 * NOT COVERED. A post's footnotes read for a post whose whole DOCUMENT is
	 * denied are not touched here: that is the page gate's, and REST strips them in
	 * `Agend_Content_Access_Frontend::filter_rest_response()`. A read of every meta
	 * key at once (`get_post_meta( $id )` with no key) returns the stored value:
	 * it is not a reader this closes, and answering it would put work on every such
	 * call on the site.
	 *
	 * Authors are never filtered: the block editor loads the footnotes to edit and
	 * saves what it was given, and a filtered read there would delete the hidden
	 * ones on the next save.
	 *
	 * @param mixed  $value     Short-circuit value so far, null for none.
	 * @param int    $object_id Post id.
	 * @param string $meta_key  Meta key being read.
	 * @param bool   $single    Whether a single value was asked for.
	 * @return mixed The value to return, or the incoming one to let WordPress read it.
	 */
	public function filter_footnotes_meta( $value, $object_id, $meta_key, $single ) {
		// The first test is the one every meta read on the site pays for.
		if ( self::FOOTNOTES_META_KEY !== $meta_key || null !== $value || $this->reading_footnotes ) {
			return $value;
		}

		$this->reading_footnotes = true;

		try {
			$stored = get_post_meta( (int) $object_id, self::FOOTNOTES_META_KEY, true );
		} finally {
			$this->reading_footnotes = false;
		}

		if ( ! is_string( $stored ) || '' === $stored ) {
			return $value;
		}

		$visible = $this->visible_footnotes( (int) $object_id, $stored );

		if ( $visible === $stored ) {
			return $value;
		}

		return $single ? $visible : array( $visible );
	}

	/**
	 * The post's footnotes without the ones anchored in a block this viewer is
	 * denied.
	 *
	 * @param int    $post_id Post the footnotes belong to.
	 * @param string $stored  The stored value, a JSON list of `{id, content}`.
	 * @return string The same string when nothing is removed, otherwise the JSON of what is left.
	 */
	private function visible_footnotes( int $post_id, string $stored ): string {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || false === strpos( (string) $post->post_content, 'wp:' ) ) {
			return $stored;
		}

		$blocks = parse_blocks( (string) $post->post_content );

		// The common case, and the cheap one: no ruled block, nothing to hide.
		if ( ! self::blocks_carry_rules( $blocks ) ) {
			return $stored;
		}

		$footnotes = json_decode( $stored, true );

		if ( ! is_array( $footnotes ) ) {
			return '[]';
		}

		$ids = array();

		foreach ( $footnotes as $footnote ) {
			if ( is_array( $footnote ) && isset( $footnote['id'] ) && is_string( $footnote['id'] ) && '' !== $footnote['id'] ) {
				$ids[] = $footnote['id'];
			}
		}

		$hidden = $this->denied_markup( $blocks, $ids, $post_id );
		$kept   = array();

		foreach ( $footnotes as $footnote ) {
			$id = is_array( $footnote ) && isset( $footnote['id'] ) && is_string( $footnote['id'] ) ? $footnote['id'] : '';

			if ( '' !== $hidden && ( '' === $id || false !== strpos( $hidden, $id ) ) ) {
				continue;
			}

			$kept[] = $footnote;
		}

		return count( $kept ) === count( $footnotes ) ? $stored : (string) wp_json_encode( $kept );
	}

	/**
	 * The markup and attributes of every ruled block this viewer is denied that
	 * mentions one of the ids, run together.
	 *
	 * A block whose subtree mentions none of the ids is not asked about, so a post
	 * with ruled blocks and no footnote anchors in them costs no decision. A denied
	 * block's whole subtree is taken, so a footnote anchored deeper inside it goes
	 * with it. One that is allowed is searched for ruled descendants.
	 *
	 * @param array    $blocks  Parsed blocks, as `parse_blocks()` returns them.
	 * @param string[] $ids     Footnote ids to look for.
	 * @param int      $post_id Post the blocks belong to.
	 * @return string
	 */
	private function denied_markup( array $blocks, array $ids, int $post_id ): string {
		$denied = '';

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$markup = self::markup_of( $block );

			if ( ! self::mentions_any( $markup, $ids ) ) {
				continue;
			}

			$rules = self::rules_of( $block );

			if ( null !== $rules && null !== $this->verdict_for( $block, $rules, $post_id ) ) {
				$denied .= $markup;
				continue;
			}

			if ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$denied .= $this->denied_markup( $block['innerBlocks'], $ids, $post_id );
			}
		}

		return $denied;
	}

	/**
	 * Everything a block says in one string: its markup, its attributes and its
	 * inner blocks'. Rich text lives in the markup for most blocks and in an
	 * attribute for some, and an id found in either is an anchor.
	 *
	 * @param array $block Parsed block.
	 * @return string
	 */
	private static function markup_of( array $block ): string {
		$markup = ( isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ? $block['innerHTML'] : '' )
			. ( isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? (string) wp_json_encode( $block['attrs'] ) : '' );

		if ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			foreach ( $block['innerBlocks'] as $inner_block ) {
				$markup .= is_array( $inner_block ) ? self::markup_of( $inner_block ) : '';
			}
		}

		return $markup;
	}

	/**
	 * Whether the text contains any of the ids.
	 *
	 * @param string   $text Text.
	 * @param string[] $ids  Ids.
	 * @return bool
	 */
	private static function mentions_any( string $text, array $ids ): bool {
		foreach ( $ids as $id ) {
			if ( false !== strpos( $text, $id ) ) {
				return true;
			}
		}

		return false;
	}

	// -----------------------------------------------------------------------
	// Generated excerpts
	// -----------------------------------------------------------------------

	/**
	 * The excerpt of a block document with every ruled subtree taken out of it.
	 *
	 * The block half of `Agend_Content_Access_Frontend::filter_generated_excerpt()`,
	 * which calls it through `agend_content_access_safe_generated_excerpt` for a
	 * document that carries rules and has no manual excerpt.
	 *
	 * WHY THE TREE IS REBUILT INSTEAD OF THE EXCERPT BEING JUDGED. Core builds an
	 * excerpt by rendering the body through `excerpt_remove_blocks()`, which renders
	 * the children of a Group, Columns or Column and never the wrapper, so a rule on
	 * a wrapper is never asked about (see `filter_generated_excerpt()`). Asking
	 * `pre_render_block` per block cannot fix that from outside, and the answer
	 * would be per viewer. So the ruled blocks are removed from the PARSED tree,
	 * wrapper or leaf, the rest is serialised with `serialize_blocks()`, and
	 * WordPress's own `wp_trim_excerpt()` runs over that markup, so the length, the
	 * "more" text, `excerpt_allowed_blocks` and the `the_content` pass all stay
	 * core's. No ruled text is in what it is given, so none can come out, and the
	 * excerpt is the same for every viewer.
	 *
	 * A block is removed when it carries a policy or enabled display conditions,
	 * the two tests the render path and the cache marking use. Synced patterns are
	 * expanded with the usual guards first, so a ruled block inside a pattern
	 * counts. A reference to a pattern that holds one is removed whole, and so is
	 * one that could not be expanded (a cycle, the depth or the reference budget):
	 * what is in it is not known. A reference to a pattern with no rules is kept
	 * as the reference it was, which core's excerpt does not render at all.
	 *
	 * The copy `wp_trim_excerpt()` reads is marked `raw`, so `get_post()` hands it
	 * back as it is instead of re-reading the stored post, which would put the
	 * original body back.
	 *
	 * An Elementor document is not rebuilt: its `post_content` is the flattened
	 * render, not a block tree. The incoming value, '', stands, which is how that
	 * driver's documents end up with no generated excerpt.
	 *
	 * @param string       $excerpt The safe excerpt so far. Returned for anything this cannot rebuild.
	 * @param WP_Post|null $post    Post the excerpt is for.
	 * @return string
	 */
	public function safe_generated_excerpt( $excerpt, $post = null ) {
		if (
			! $post instanceof WP_Post
			|| '' !== trim( (string) get_post_meta( (int) $post->ID, Agend_Content_Access_Elementor_Parser::META_KEY, true ) )
		) {
			return (string) $excerpt;
		}

		$ledger = Agend_Content_Access_Block_Attributes::ledger();
		$blocks = Agend_Content_Access_Block_Attributes::expand( parse_blocks( (string) $post->post_content ), $ledger );

		$safe               = clone $post;
		$safe->post_content = serialize_blocks( self::without_ruled_blocks( $blocks ) );
		$safe->post_excerpt = '';
		$safe->filter       = 'raw';

		return (string) wp_trim_excerpt( '', $safe );
	}

	/**
	 * The blocks with every ruled one, and everything inside it, taken out.
	 *
	 * @param array $blocks Parsed blocks, already expanded.
	 * @return array The blocks that are left, as a list.
	 */
	private static function without_ruled_blocks( array $blocks ): array {
		$kept = array();

		foreach ( $blocks as $block ) {
			$clean = is_array( $block ) ? self::without_ruled_block( $block ) : null;

			if ( null !== $clean ) {
				$kept[] = $clean;
			}
		}

		return $kept;
	}

	/**
	 * One block without its ruled descendants, or null when it is ruled itself.
	 *
	 * `serialize_block()` walks `innerContent` and takes the next inner block at
	 * each `null` in it, so a child cannot be dropped from `innerBlocks` alone: its
	 * placeholder has to go with it, or the block no longer serialises. A block
	 * whose placeholders do not match its children cannot be rebuilt reliably, and
	 * is removed.
	 *
	 * @param array $block Parsed block, already expanded.
	 * @return array|null
	 */
	private static function without_ruled_block( array $block ): ?array {
		if (
			Agend_Content_Access_Block_Attributes::carries_policy( $block )
			|| Agend_Content_Access_Block_Attributes::carries_conditions( $block )
		) {
			return null;
		}

		$children = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? array_values( $block['innerBlocks'] ) : array();

		if ( Agend_Content_Access_Block_Attributes::is_synced_reference( $block ) ) {
			// Unknown content, or content holding a rule: the whole reference goes.
			if ( ! Agend_Content_Access_Block_Attributes::is_expanded_reference( $block ) || self::blocks_carry_rules( $children ) ) {
				return null;
			}

			// The reference as it was written: its pattern's blocks are not its own.
			$block['innerBlocks'] = array();

			return $block;
		}

		if ( array() === $children ) {
			return $block;
		}

		$content = isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ? $block['innerContent'] : array();

		if ( count( $children ) !== count( array_filter( $content, 'is_null' ) ) ) {
			return null;
		}

		$kept_content  = array();
		$kept_children = array();
		$index         = 0;

		foreach ( $content as $chunk ) {
			if ( null !== $chunk ) {
				$kept_content[] = $chunk;
				continue;
			}

			$child = is_array( $children[ $index ] ) ? self::without_ruled_block( $children[ $index ] ) : null;

			++$index;

			if ( null !== $child ) {
				$kept_content[]  = null;
				$kept_children[] = $child;
			}
		}

		$block['innerContent'] = $kept_content;
		$block['innerBlocks']  = $kept_children;

		return $block;
	}

	// -----------------------------------------------------------------------
	// Core search
	// -----------------------------------------------------------------------

	/**
	 * Records on save whether the document carries a rule.
	 *
	 * The flag is what `exclude_from_search()` filters on, since a search query
	 * cannot parse blocks or Elementor data. It is set or cleared from
	 * `document_carries_rules()`, the same check for both editors.
	 *
	 * LIMITS, both documented in the README. A later edit to a synced pattern
	 * does not update the flag on the pages that use it, so a restriction added
	 * to a pattern is not hidden from search on those pages until each is saved
	 * again. And the flag guards core search only: a third-party indexer that
	 * reads raw `post_content` at save (Yoast, RankMath, ElasticPress and the
	 * like) is outside this plugin's reach, and serves that text through its own
	 * search UI.
	 *
	 * @param int $post_id Post being saved.
	 */
	public function sync_search_flag( $post_id ): void {
		$post_id = (int) $post_id;

		// An autosave is stored as a revision and is not the post.
		if ( $post_id <= 0 || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return;
		}

		if ( self::document_carries_rules( $post ) ) {
			update_post_meta( $post_id, self::SEARCH_FLAG_META_KEY, '1' );
			return;
		}

		delete_post_meta( $post_id, self::SEARCH_FLAG_META_KEY );
	}

	/**
	 * Records the flag again once Elementor has stored the document.
	 *
	 * Elementor writes `_elementor_data` after it has updated the post row, so on
	 * `save_post` the data this plugin would read is the PREVIOUS save's: a rule
	 * added in this save would not flag the post until the next one, and a rule
	 * removed would keep it out of search. `elementor/document/after_save` fires
	 * once the elements are stored.
	 *
	 * @param \Elementor\Core\Base\Document|object $document Document that was saved.
	 */
	public function sync_search_flag_after_elementor_save( $document ): void {
		if ( is_object( $document ) && method_exists( $document, 'get_main_id' ) ) {
			$this->sync_search_flag( (int) $document->get_main_id() );
		}
	}

	/**
	 * Keeps posts that carry a rule out of search for visitors.
	 *
	 * Core search matches the raw `post_content` with `LIKE` in SQL and never
	 * touches the render pipeline, so a public page with a members-only block
	 * matches a search for the protected text, and the hit alone tells the
	 * searcher the text is there. Suppression cannot help: the block is removed
	 * from the render, not from the question.
	 *
	 * Applies to any query that carries a search term, not only the main one,
	 * because the same query is what the REST API runs for `?search=` and for
	 * `/wp/v2/search`, and what a theme's live search runs. A query with no search
	 * term is untouched. Users who can `edit_others_posts` are not filtered: they
	 * can read the raw content of anyone's post anyway. Users who can only
	 * `edit_posts` ARE filtered, because search would let a contributor test text
	 * in posts that are not theirs: a search that returns a post tells the
	 * searcher the words are in it, which is the read this prevents.
	 *
	 * The cost is real and is why this is filterable: the post's public parts
	 * stop being searchable too. Return false from
	 * `agend_content_access_search_excludes_fragment_rules` to turn it off.
	 *
	 * The existing `meta_query` is wrapped, never appended to. Appending a
	 * clause to a query whose top-level relation is `OR` would read "or has no
	 * flag", which widens the result instead of narrowing it.
	 *
	 * @param WP_Query $query Query about to run.
	 */
	public function exclude_from_search( $query ): void {
		if ( ! $query instanceof WP_Query || ! self::is_search_query( $query ) ) {
			return;
		}

		/**
		 * Filters whether documents carrying a fragment policy or display
		 * conditions, in a block or an Elementor section, are excluded from search
		 * for users who cannot edit others' posts.
		 *
		 * Excluding them also hides their public parts from search, which is the
		 * price of not revealing that the protected text exists.
		 *
		 * @param bool     $exclude Whether to exclude. Default true.
		 * @param WP_Query $query   The search query.
		 */
		if ( ! apply_filters( 'agend_content_access_search_excludes_fragment_rules', true, $query ) ) {
			return;
		}

		if ( current_user_can( 'edit_others_posts' ) ) {
			return;
		}

		$clause   = array(
			'key'     => self::SEARCH_FLAG_META_KEY,
			'compare' => 'NOT EXISTS',
		);
		$existing = $query->get( 'meta_query' );

		$query->set(
			'meta_query',
			is_array( $existing ) && array() !== $existing
				? array(
					'relation' => 'AND',
					$existing,
					$clause,
				)
				: array( $clause )
		);
	}

	/**
	 * Whether the query searches for a term.
	 *
	 * Anything that is not plainly empty counts, including a value of an odd
	 * type, because a search term this cannot read is one WordPress may still
	 * match against the content.
	 *
	 * @param WP_Query $query Query.
	 * @return bool
	 */
	private static function is_search_query( WP_Query $query ): bool {
		$term = $query->get( 's' );

		return null !== $term && false !== $term && '' !== $term && array() !== $term;
	}
}
