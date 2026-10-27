<?php
/**
 * Direct WordPress request gating.
 *
 * @package Agend_Content_Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stops a restricted document being served over its own WordPress URL.
 *
 * Without this, a policy would only govern the CMS projection and the original
 * permalink would remain an unguarded copy of the same content.
 *
 * The protected body is never rendered and then hidden. The decision happens on
 * `template_redirect`, before any template runs, and the body is REPLACED
 * rather than styled away (US-3.4 criterion 7). CSS and JavaScript hiding is
 * not access control: the content is still in the payload, and view-source
 * defeats it.
 *
 * `gate_request()` only ever sees the QUERIED post. A restricted post that
 * renders anywhere else, in a blog index, an archive, a search result, a
 * widget, a feed, or a block theme's query loop, is gated by the render filters
 * further down, at the point its body or auto-generated excerpt is produced.
 */
class Agend_Content_Access_Frontend {

	/**
	 * Priority of the `the_content` pass that withholds a body before anything
	 * renders it. Lower than core's `do_blocks` (9), `wpautop` (10) and
	 * `do_shortcode` (11).
	 */
	private const WITHHOLD_PRIORITY = 1;

	/**
	 * Decision for the current singular request, resolved once.
	 *
	 * @var array|null
	 */
	private ?array $decision = null;

	/**
	 * Gate markup produced for posts whose body was withheld from a render
	 * other than the gated singular, keyed by post id.
	 *
	 * Holding it lets the closing `the_content` pass restore the gate without
	 * deciding again, and lets `suppress_builder_data()` know which documents
	 * are withheld.
	 *
	 * @var array<int, string>
	 */
	private array $withheld = array();

	/**
	 * Posts whose outage denial has already been reported this request, keyed by
	 * post id. A post can be gated for its body and again for its excerpt, and
	 * one outage should count once.
	 *
	 * @var array<int, true>
	 */
	private array $degraded_reported = array();

	/**
	 * Registers hooks.
	 */
	public function __construct() {
		add_action( 'template_redirect', array( $this, 'gate_request' ), 1 );

		// Public REST representations must not carry what the page will not
		// (US-3.4 criterion 8). Registered unconditionally: a REST request never
		// reaches template_redirect.
		add_action( 'rest_api_init', array( $this, 'register_rest_filters' ) );

		$this->register_render_filters();
	}

	/**
	 * Gates a restricted post wherever its body or excerpt is produced.
	 *
	 * Registered on every request and decided when a filter runs, not here. Who
	 * is looking, and whether this is an authoring context, are not known at
	 * `plugins_loaded`, and a filter that is not registered cannot fail closed.
	 * `renders_for_visitors()` keeps wp-admin screens out.
	 *
	 * `the_content` is registered at BOTH ends of the chain because no single
	 * priority is safe.
	 *
	 * Early, at priority 1: the body is replaced before core's `do_blocks` (9),
	 * `wpautop` (10) and `do_shortcode` (11), or any page builder, sees it. So
	 * nothing with a side effect runs on content the visitor may not read: no
	 * enqueued script, no counter, no gateway call from a dynamic block. Hiding
	 * the result afterwards would leave all of that behind.
	 *
	 * An early replacement alone is not enough. Elementor re-derives a
	 * document's body from its own stored data, at a priority in the millions,
	 * and ignores whatever earlier filters left in `$content`. So a second pass
	 * at the very end restores the gate for every post the first pass withheld,
	 * whatever ran in between. A filter that only REPLACES content must come last to be the
	 * word that stands, and first so the body is never rendered. The withheld
	 * post's builder data is also blanked, so the builder is not run only to have
	 * its output thrown away.
	 *
	 * Excerpts and feeds run last, at PHP_INT_MAX. `wp_trim_excerpt()` (priority
	 * 10 on `get_the_excerpt`) is what builds an excerpt out of the body when
	 * none was written, so a gate that ran before it would hand it an empty
	 * string to fill with the opening of the protected post.
	 *
	 * The Latest Posts block is handled before it renders, on `render_block_data`:
	 * its "full post" display prints `$post->post_content` itself, so none of the
	 * filters above is ever offered the body. See `withhold_latest_posts_content()`.
	 */
	private function register_render_filters(): void {
		add_filter( 'the_content', array( $this, 'withhold_in_loop' ), self::WITHHOLD_PRIORITY );
		add_filter( 'the_content', array( $this, 'enforce_withheld' ), PHP_INT_MAX );
		add_filter( 'get_the_excerpt', array( $this, 'filter_excerpt' ), PHP_INT_MAX, 2 );
		add_filter( 'the_content_feed', array( $this, 'filter_feed_content' ), PHP_INT_MAX );
		add_filter( 'the_excerpt_rss', array( $this, 'filter_feed_excerpt' ), PHP_INT_MAX );
		add_filter( 'render_block_data', array( $this, 'withhold_latest_posts_content' ), PHP_INT_MAX, 3 );
	}

	/**
	 * Evaluates the current request and replaces the body when denied.
	 *
	 * Runs at priority 1 so the decision is made before themes, page builders
	 * and caching plugins get a chance to render or store anything.
	 */
	public function gate_request(): void {
		if ( self::is_authoring_context() ) {
			return;
		}

		if ( ! is_singular() || is_embed() ) {
			return;
		}

		$post = get_queried_object();

		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$decision = Agend_Content_Access_Decision::for_post( (int) $post->ID );

		if ( Agend_Content_Access_Decision::STATE_GRANTED === $decision['state'] ) {
			return;
		}

		$this->report_degraded( (int) $post->ID, $decision );

		$this->decision = $decision;

		$teaser = self::teaser_for( $post );

		if ( '' === $teaser ) {
			$this->send_forbidden();
			return;
		}

		$this->replace_body_with_teaser();
	}

	/**
	 * Whether this request is somebody AUTHORING the page rather than reading it.
	 *
	 * Gating never applies here. An editor building a page must see every
	 * section, including ones they have just restricted, or they cannot lay out
	 * or edit the very content the policy protects. A page builder that hides
	 * blocks from their author is not a security feature, it is a broken editor.
	 *
	 * This is safe because an authoring context requires an edit capability that
	 * WordPress has already checked: Elementor refuses to open its editor or
	 * preview for a user without `edit_post` on the target. So this is not a
	 * second, weaker gate; it is a short-circuit in front of a check that would
	 * grant anyway via `can_preview()`. It exists as an explicit early return so
	 * the intent is legible and so no later filter can accidentally reach a
	 * builder request.
	 *
	 * Note the boundary: this covers the EDITOR and its preview iframe. A public
	 * pageload of an Elementor page is a normal frontend request and is gated
	 * like any other, which is what `suppress_builder_data()` exists for.
	 *
	 * The preview iframe is recognised only on the page it is previewing. The
	 * `elementor-preview` query argument is attacker-controlled, and what makes it
	 * safe to honour is that the id it carries is the post this request serves.
	 *
	 * @return bool
	 */
	public static function is_authoring_context(): bool {
		if ( is_admin() ) {
			return true;
		}

		if ( ! agend_content_access_has_elementor() ) {
			return false;
		}

		$plugin = \Elementor\Plugin::$instance;

		if ( isset( $plugin->editor ) && $plugin->editor->is_edit_mode() ) {
			return true;
		}

		// The preview iframe is a normal frontend pageload flagged with
		// `?elementor-preview=<id>`, so `is_edit_mode()` is false there even
		// though it is exactly where the author is looking.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only mode check on an Elementor-set query var.
		$raw_preview = isset( $_GET['elementor-preview'] ) ? wp_unslash( $_GET['elementor-preview'] ) : 0;

		// An array, or anything that is not a plain number, is not a preview id.
		$preview_id = is_scalar( $raw_preview ) ? absint( $raw_preview ) : 0;

		if ( $preview_id <= 0 || ! isset( $plugin->preview ) ) {
			return false;
		}

		// The flag is only trusted on the page it names. Elementor's own
		// `is_preview_mode( $id )` checks that the user may edit `$id`, then
		// compares `$id` with the very query-string value it was just handed, so
		// it answers true for ANY URL once the visitor appends the id of a post
		// of theirs. A contributor could then open a restricted page with
		// `?elementor-preview=<their own draft>` and be treated as authoring it:
		// no page gate, no loop or excerpt gate, no section suppression. Requiring
		// the id to be the post this request actually serves ties the flag to the
		// one document the capability check was made against.
		if ( self::requested_object_id() !== $preview_id ) {
			return false;
		}

		return (bool) $plugin->preview->is_preview_mode( $preview_id );
	}

	/**
	 * The id of the post or page the request is for.
	 *
	 * Read from the MAIN query, not the global one: Elementor switches the global
	 * query while it renders dynamic content in a preview, and a check that
	 * followed it would stop recognising the very page being previewed. The main
	 * query is fixed by the URL, so nothing a visitor appends can change it.
	 *
	 * @return int Zero when the request is not for a single post.
	 */
	private static function requested_object_id(): int {
		$main = $GLOBALS['wp_the_query'] ?? null;

		if ( is_object( $main ) && method_exists( $main, 'get_queried_object_id' ) ) {
			return (int) $main->get_queried_object_id();
		}

		return (int) get_queried_object_id();
	}

	/**
	 * The public teaser for a restricted post.
	 *
	 * The manually written excerpt only. An AUTO-generated excerpt is the first
	 * 55 words of the protected body, so falling back to one would publish the
	 * opening of the very content being protected.
	 *
	 * @param WP_Post $post Post.
	 * @return string Teaser text, or '' when none is configured.
	 */
	public static function teaser_for( $post ): string {
		$teaser = has_excerpt( $post ) ? (string) $post->post_excerpt : '';

		/**
		 * Filters the teaser shown in place of restricted content.
		 *
		 * Returning a non-empty string turns a 403 into a teaser page. Whatever
		 * is returned is public: it is shown to anonymous visitors and is
		 * indexable, so it must not contain protected material.
		 *
		 * @param string  $teaser Teaser text.
		 * @param WP_Post $post   Post being gated.
		 */
		return (string) apply_filters( 'agend_content_access_teaser', $teaser, $post );
	}

	/**
	 * Swaps the rendered body for the teaser and a portal call to action.
	 */
	private function replace_body_with_teaser(): void {
		add_filter( 'the_content', array( $this, 'filter_the_content' ), 1 );

		// Page builders render from their own stored data rather than
		// `the_content`, so the raw document has to be emptied too or an
		// Elementor layout would render the protected sections regardless.
		add_filter( 'get_post_metadata', array( $this, 'suppress_builder_data' ), 10, 4 );

		// A teaser page is a different page from the article. Telling search
		// engines it is the article would index the teaser under the real
		// content's terms.
		add_action( 'wp_head', array( $this, 'print_noindex' ), 1 );
	}

	/**
	 * Replaces the post body with the teaser and gate.
	 *
	 * @param string $content Original content.
	 * @return string
	 */
	public function filter_the_content( $content ): string {
		if ( ! in_the_loop() || ! is_main_query() || null === $this->decision ) {
			return $content;
		}

		$post = get_post();

		return self::render_gate(
			self::teaser_for( $post ),
			$this->decision['state'],
			$this->decision['policy']
		);
	}

	/**
	 * Blanks page-builder document data for a gated post.
	 *
	 * @param mixed  $value     Existing short-circuit value.
	 * @param int    $object_id Post id.
	 * @param string $meta_key  Meta key.
	 * @param bool   $single    Whether a single value was requested.
	 * @return mixed Empty data for builder keys, otherwise the untouched value.
	 */
	public function suppress_builder_data( $value, $object_id, $meta_key, $single ) {
		// Elementor stores its document here. Returning an empty array stops the
		// builder rendering the protected layout from its own copy.
		if ( '_elementor_data' !== $meta_key ) {
			return $value;
		}

		// A post withheld from a loop render is gated as firmly as the queried
		// one. Otherwise the builder would render a restricted post's layout from
		// its own copy inside every archive that lists it.
		if ( isset( $this->withheld[ (int) $object_id ] ) ) {
			return $single ? array() : array( array() );
		}

		if ( null === $this->decision ) {
			return $value;
		}

		$queried = get_queried_object_id();

		if ( (int) $object_id !== (int) $queried ) {
			return $value;
		}

		return $single ? array() : array( array() );
	}

	/**
	 * Records an outage that denied a visitor who may well be entitled.
	 *
	 * An operational error, because a spike here is a broken gateway, not a
	 * surge of lapsed memberships.
	 *
	 * @param int   $post_id  Post id.
	 * @param array $decision Result of `Agend_Content_Access_Decision::for_post()`.
	 */
	private function report_degraded( int $post_id, array $decision ): void {
		if ( empty( $decision['degraded'] ) || isset( $this->degraded_reported[ $post_id ] ) ) {
			return;
		}

		$this->degraded_reported[ $post_id ] = true;

		do_action( 'agend_content_access_degraded', $post_id, $decision['state'] );
	}

	/**
	 * Whether the current render is one a visitor can end up reading.
	 *
	 * The opposite of `is_authoring_context()` except for one case. WordPress
	 * reports `is_admin()` for admin-ajax.php, but "load more" buttons, filters
	 * and infinite scroll print posts to ANONYMOUS visitors through it, so an
	 * is_admin() exclusion would leave exactly those renders open. wp-admin
	 * screens proper stay out: an editor there needs to see what they are
	 * editing, and `can_preview()` already covers any post they may edit.
	 *
	 * Public because the Elementor integration asks the same question before it
	 * suppresses a section, and must get the same answer as every other gate.
	 *
	 * @return bool
	 */
	public static function renders_for_visitors(): bool {
		if ( is_admin() ) {
			return wp_doing_ajax();
		}

		return ! self::is_authoring_context();
	}

	/**
	 * Whether this is an AJAX request made by Elementor's own editor.
	 *
	 * The one admin-ajax request that is authoring rather than reading: the editor
	 * rendering a widget to refresh its canvas, or fetching a template. Elementor
	 * puts itself in edit mode for those, and only for those: its edit mode is
	 * keyed on its own action names, so another plugin's AJAX handler never
	 * reports it, and a visitor cannot switch it on for one, because it also
	 * requires the edit capability on the document the editor names.
	 *
	 * @return bool
	 */
	public static function is_elementor_editor_ajax(): bool {
		if ( ! wp_doing_ajax() || ! agend_content_access_has_elementor() ) {
			return false;
		}

		$plugin = \Elementor\Plugin::$instance;

		return isset( $plugin->editor ) && $plugin->editor->is_edit_mode();
	}

	/**
	 * The post currently being rendered, when it should be gated at all.
	 *
	 * @return WP_Post|null
	 */
	private static function post_being_rendered(): ?WP_Post {
		if ( ! self::renders_for_visitors() ) {
			return null;
		}

		$post = get_post();

		return $post instanceof WP_Post ? $post : null;
	}

	/**
	 * The decision for a post rendered outside the gated singular.
	 *
	 * A feed is evaluated as an anonymous reader (see `Decision::for_post()`).
	 * Everything else is evaluated for the actual visitor.
	 *
	 * @param int $post_id Post id.
	 * @return array{state: string, policy: array|null, preview: bool, degraded: bool}
	 */
	private static function decision_for_render( int $post_id ): array {
		return Agend_Content_Access_Decision::for_post( $post_id, is_feed() );
	}

	/**
	 * Withholds a restricted post's body wherever it renders outside the gated
	 * singular: a blog index, an archive, search results, a widget, a related
	 * posts list, or a block theme's query loop.
	 *
	 * `gate_request()` bails unless the request is singular, and
	 * `filter_the_content()` only acts for the main query's own loop, so before
	 * this filter any other place a restricted post rendered printed its full
	 * body to whoever asked. Acts on the post WordPress is currently rendering
	 * (`get_post()`), which is how a `the_content` filter learns whose content
	 * it was handed.
	 *
	 * Posts with a public or unset policy cost nothing: `for_post()` returns for
	 * them before any identity lookup.
	 *
	 * @param string $content Original content.
	 * @return string
	 */
	public function withhold_in_loop( $content ) {
		$post = self::post_being_rendered();

		if ( null === $post ) {
			return $content;
		}

		// The queried post is already handled by `gate_request()` whenever
		// `filter_the_content()` will act, which is exactly this condition. It
		// is skipped here so that path is not changed and nothing is gated twice.
		// The same post rendered anywhere ELSE on the request (a sidebar, a
		// related list) does not meet it, and is gated here.
		if ( null !== $this->decision && in_the_loop() && is_main_query() ) {
			return $content;
		}

		$post_id  = (int) $post->ID;
		$decision = self::decision_for_render( $post_id );

		if ( Agend_Content_Access_Decision::STATE_GRANTED === $decision['state'] ) {
			self::note_granted_render( $post_id, $decision );
			return $content;
		}

		$this->report_degraded( $post_id, $decision );

		$gate = self::render_gate(
			self::teaser_for( $post ),
			$decision['state'],
			$decision['policy']
		);

		$this->withheld[ $post_id ] = $gate;

		// Registered on first use: it sits on every `get_post_meta()` call, so a
		// request that withholds nothing should not pay for it. Adding the same
		// callback again is a no-op.
		add_filter( 'get_post_metadata', array( $this, 'suppress_builder_data' ), 10, 4 );

		return $gate;
	}

	/**
	 * Restores the gate over a withheld post after every other filter has run.
	 *
	 * See `register_render_filters()` for why this second pass exists. It does
	 * not decide again: it only re-asserts what `withhold_in_loop()` already
	 * decided, so a post that was granted is untouched.
	 *
	 * @param string $content Content as the rest of the chain left it.
	 * @return string
	 */
	public function enforce_withheld( $content ) {
		if ( array() === $this->withheld ) {
			return $content;
		}

		$post = get_post();

		if ( $post instanceof WP_Post && isset( $this->withheld[ (int) $post->ID ] ) ) {
			return $this->withheld[ (int) $post->ID ];
		}

		return $content;
	}

	/**
	 * Gates a post's excerpt.
	 *
	 * An excerpt is not harmless. When none was written, `wp_trim_excerpt()`
	 * builds one from the first 55 words of the BODY, so every archive, search
	 * result, widget and SEO description would publish the opening of the
	 * protected post. A denied post therefore yields its manual excerpt only:
	 * that is the editor's deliberate public teaser, the same rule `teaser_for()`
	 * applies to the singular gate. With none written the excerpt is empty.
	 *
	 * @param string       $excerpt Excerpt as the chain built it.
	 * @param WP_Post|null $post    Post the excerpt is for.
	 * @return string
	 */
	public function filter_excerpt( $excerpt, $post = null ) {
		if ( ! $post instanceof WP_Post || ! self::renders_for_visitors() ) {
			return $excerpt;
		}

		$post_id  = (int) $post->ID;
		$decision = self::decision_for_render( $post_id );

		if ( Agend_Content_Access_Decision::STATE_GRANTED === $decision['state'] ) {
			self::note_granted_render( $post_id, $decision );
			return $excerpt;
		}

		$this->report_degraded( $post_id, $decision );

		return self::teaser_for( $post );
	}

	/**
	 * Stops the Latest Posts block printing the raw body of the posts it lists.
	 *
	 * Its "full post" display does not use the content filters. It prints
	 * `wp_kses_post( $post->post_content )` for each listed post, straight from the
	 * database row, so the body of every restricted post it lists went to an
	 * anonymous visitor and nothing in the chain above had a chance to object.
	 * Its other display, "excerpt", calls `get_the_excerpt()`, which is gated.
	 *
	 * So the block is rendered in excerpt mode instead, for anyone who is not
	 * authoring. THE FIDELITY COST IS REAL AND DELIBERATE: a Latest Posts block set
	 * to show full posts shows excerpts to every visitor, restricted or not. Doing
	 * better means predicting which posts the block will list, which means running
	 * the query core builds from the block's attributes, and that query is core's
	 * to change between releases. A copy of it that drifted would fail OPEN, back
	 * to printing raw bodies, silently. Switching the mode needs no knowledge of
	 * the query and cannot drift. A site that needs full posts in a list can print
	 * them with a Query Loop block, whose `the_content` is gated.
	 *
	 * Any value other than `excerpt` is switched, not only `full_post`, so a mode a
	 * later core adds is withheld until someone has decided what it prints.
	 *
	 * @param array      $parsed_block Block about to render.
	 * @param array|null $source_block Block as parsed. Unused.
	 * @param mixed      $parent_block Parent block, when nested. Unused.
	 * @return array
	 */
	public function withhold_latest_posts_content( $parsed_block, $source_block = null, $parent_block = null ) {
		if ( ! is_array( $parsed_block ) || 'core/latest-posts' !== ( $parsed_block['blockName'] ?? null ) ) {
			return $parsed_block;
		}

		if ( ! self::renders_for_visitors() ) {
			return $parsed_block;
		}

		$attributes = isset( $parsed_block['attrs'] ) && is_array( $parsed_block['attrs'] )
			? $parsed_block['attrs']
			: array();

		if (
			empty( $attributes['displayPostContent'] )
			|| ! isset( $attributes['displayPostContentRadio'] )
			|| 'excerpt' === $attributes['displayPostContentRadio']
		) {
			return $parsed_block;
		}

		$parsed_block['attrs']['displayPostContentRadio'] = 'excerpt';

		return $parsed_block;
	}

	/**
	 * Gates a feed item's full content.
	 *
	 * A feed reader is anonymous, so a restricted item carries its teaser and
	 * nothing else: no gate prompt either, because its sign-in link would point
	 * back at the feed. Always evaluated as anonymous, never for whoever fetched
	 * it, because a feed response is shared and cached.
	 *
	 * @param string $content Feed content.
	 * @return string
	 */
	public function filter_feed_content( $content ) {
		$post = get_post();

		if ( ! $post instanceof WP_Post ) {
			return $content;
		}

		$decision = Agend_Content_Access_Decision::for_post( (int) $post->ID, true );

		if ( Agend_Content_Access_Decision::STATE_GRANTED === $decision['state'] ) {
			return $content;
		}

		$teaser = self::teaser_for( $post );

		return '' === $teaser ? '' : wpautop( esc_html( $teaser ) );
	}

	/**
	 * Gates a feed item's summary.
	 *
	 * @param string $output Feed excerpt.
	 * @return string
	 */
	public function filter_feed_excerpt( $output ) {
		$post = get_post();

		if ( ! $post instanceof WP_Post ) {
			return $output;
		}

		$decision = Agend_Content_Access_Decision::for_post( (int) $post->ID, true );

		if ( Agend_Content_Access_Decision::STATE_GRANTED === $decision['state'] ) {
			return $output;
		}

		return self::teaser_for( $post );
	}

	/**
	 * Keeps a page that carries a member's render of restricted content out of
	 * page caches.
	 *
	 * Only when the body was granted to an identified visitor who is not merely
	 * previewing. Public and unset posts never reach a cache problem, and an
	 * editor's preview is a logged-in request that caches already bypass.
	 *
	 * @param int   $post_id  Post id.
	 * @param array $decision Result of `Agend_Content_Access_Decision::for_post()`.
	 */
	private static function note_granted_render( int $post_id, array $decision ): void {
		$policy = $decision['policy'];

		if (
			! empty( $decision['preview'] )
			|| null === $policy
			|| Agend_Content_Access_Policy::MODE_PUBLIC === ( $policy['mode'] ?? '' )
		) {
			return;
		}

		self::mark_uncacheable( $post_id );
	}

	/**
	 * Marks the current page as one no shared cache may store.
	 *
	 * A page cache keys on the URL, not on who asked. If a member's render of a
	 * listing is stored, the next anonymous visitor to that URL is served the
	 * member's copy, restricted bodies included, and the gate never runs. So the
	 * page is excluded two ways:
	 *
	 * - `DONOTCACHEPAGE`, the constant WP Super Cache, W3 Total Cache, WP Rocket
	 *   and LiteSpeed Cache all honour. Defined while the page renders, and caches
	 *   read it after it has rendered, so setting it mid-page works.
	 * - `Cache-Control: private, no-store`, for caches and proxies in front of
	 *   WordPress that ignore the constant. `nocache_headers()` is not enough on
	 *   its own: its `no-cache` forbids reuse without revalidation but still
	 *   PERMITS a cache to store the response, so a member's copy could sit in a
	 *   shared store. `no-store` forbids storing it, and `private` forbids shared
	 *   caches from keeping it at all. The header can only be sent while headers
	 *   are still unsent. In a classic theme output usually began before the loop,
	 *   so the constant is the dependable half and the header is best effort.
	 *
	 * @param int $post_id Post whose granted render caused this.
	 */
	private static function mark_uncacheable( int $post_id ): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		if ( ! headers_sent() ) {
			header( 'Cache-Control: private, no-store', true );
		}

		/**
		 * Fires when a page carries a member's render of a restricted post.
		 *
		 * For cache integrations that do not read `DONOTCACHEPAGE`, such as a CDN
		 * bypass rule or a host's own page cache.
		 *
		 * @param int $post_id Post whose granted render caused this.
		 */
		do_action( 'agend_content_access_uncacheable_render', $post_id );
	}

	/**
	 * Names the plans a selected-plans policy admits.
	 *
	 * Returns an empty array when the catalogue is unreachable or none of the
	 * policy's tiers resolve, so the caller falls back to generic copy. Naming
	 * nothing is better than naming "Unknown plan".
	 *
	 * Disclosure was considered and is not a concern: the tier catalogue is
	 * already served unauthenticated by Agend Apps Core at
	 * `/agend-apps/v1/crm/tiers`, so plan names are public by a route that
	 * predates this plugin. Withholding them here would have cost a member the
	 * one piece of information they need to act, and protected nothing.
	 *
	 * @param array|null $policy Canonical policy.
	 * @return string[] Plan names, possibly empty.
	 */
	public static function plan_names_for( ?array $policy ): array {
		$tier_ids = Agend_Content_Access_Policy::tier_ids( $policy );

		if ( array() === $tier_ids ) {
			return array();
		}

		$catalogue = Agend_Content_Access_Catalogue::get();
		$annotated = Agend_Content_Access_Catalogue::annotate_selection(
			$tier_ids,
			$catalogue['plans']
		);

		$names = array();

		foreach ( $annotated as $entry ) {
			// A tier the catalogue cannot name is skipped rather than rendered
			// as a placeholder: a visitor cannot act on "Unknown plan", and it
			// advertises that the policy is broken.
			if ( '' !== $entry['name'] ) {
				$names[] = $entry['name'];
			}
		}

		return $names;
	}

	/**
	 * Renders the gate markup.
	 *
	 * @param string     $teaser Teaser text.
	 * @param string     $state  Decision state.
	 * @param array|null $policy Policy, used to name the qualifying plans.
	 * @return string
	 */
	public static function render_gate( string $teaser, string $state, ?array $policy = null ): string {
		$portal = method_exists( 'Agend_Apps_Settings', 'get_portal_url' )
			? (string) Agend_Apps_Settings::get_portal_url()
			: '';

		switch ( $state ) {
			case Agend_Content_Access_Decision::STATE_MEMBERSHIP:
				$message = __( 'This content is for members. An active membership is required to read it.', 'agend-content-access' );
				$cta     = __( 'View membership options', 'agend-content-access' );
				break;
			case Agend_Content_Access_Decision::STATE_PLAN:
				$names = self::plan_names_for( $policy );

				// Name the plans. A member on the wrong plan otherwise has no
				// way to know what they need and ends up contacting support.
				$message = array() === $names
					? __( 'This content is available to members on another plan.', 'agend-content-access' )
					: sprintf(
						/* translators: %s: comma-separated membership plan names. */
						__( 'This content is available to members on: %s.', 'agend-content-access' ),
						implode( ', ', $names )
					);
				$cta     = __( 'View membership options', 'agend-content-access' );
				break;
			default:
				$message = __( 'This content is for members. Please sign in to read it.', 'agend-content-access' );
				$cta     = __( 'Sign in', 'agend-content-access' );
				break;
		}

		$html = '<div class="agend-access-gate">';

		if ( '' !== $teaser ) {
			$html .= '<div class="agend-access-teaser">' . wpautop( esc_html( $teaser ) ) . '</div>';
		}

		$html .= '<div class="agend-access-gate-prompt">';
		$html .= '<p>' . esc_html( $message ) . '</p>';

		if ( '' !== $portal ) {
			$return = home_url( add_query_arg( array() ) );
			$url    = add_query_arg( 'return_to', rawurlencode( $return ), $portal );

			$html .= '<p><a class="agend-access-gate-cta" href="' . esc_url( $url ) . '">'
				. esc_html( $cta ) . '</a></p>';
		}

		$html .= '</div></div>';

		return $html;
	}

	/**
	 * Sends a non-indexable 403 with no body.
	 */
	private function send_forbidden(): void {
		if ( ! headers_sent() ) {
			status_header( 403 );
			header( 'X-Robots-Tag: noindex, nofollow', true );
			nocache_headers();
		}

		wp_die(
			esc_html__( 'This content is for members.', 'agend-content-access' ),
			esc_html__( 'Members only', 'agend-content-access' ),
			array( 'response' => 403 )
		);
	}

	/**
	 * Marks a teaser page non-indexable.
	 */
	public function print_noindex(): void {
		echo '<meta name="robots" content="noindex, nofollow" />' . "\n";
	}

	/**
	 * Strips protected fields from public REST representations.
	 */
	public function register_rest_filters(): void {
		foreach ( Agend_Content_Access_Meta_Box::supported_post_types() as $post_type ) {
			add_filter(
				"rest_prepare_{$post_type}",
				array( $this, 'filter_rest_response' ),
				10,
				3
			);
		}
	}

	/**
	 * Removes the body from a REST item the requester cannot access, and the
	 * authoring source from one they may read but not edit.
	 *
	 * The public REST representation is a second copy of the same content, so a
	 * policy that governs only the rendered page leaves it readable through
	 * `/wp-json/wp/v2/posts`.
	 *
	 * `context=edit` is not a safe context in itself. The block editor loads a
	 * post for EDITING through this same route, so stripping the body for the
	 * people who edit it would empty the editor, and an edit capability on THAT
	 * post is the one thing that exempts a request. But WordPress allows
	 * `context=edit` on a COLLECTION to anybody holding `edit_posts`, which a
	 * contributor does. Without the per-post check, `/wp/v2/posts?context=edit`
	 * returned `content.raw` for every published post, restricted ones included.
	 * So the edit context is trusted per item, on `edit_post` for that post, and
	 * every other request is treated as the reader it is.
	 *
	 * @param WP_REST_Response $response Response.
	 * @param WP_Post          $post     Post.
	 * @param WP_REST_Request  $request  Request.
	 * @return WP_REST_Response
	 */
	public function filter_rest_response( $response, $post, $request ) {
		$post_id = (int) $post->ID;

		if ( 'edit' === $request->get_param( 'context' ) && current_user_can( 'edit_post', $post_id ) ) {
			return $response;
		}

		$decision = Agend_Content_Access_Decision::for_post( $post_id );
		$data     = $response->get_data();

		if ( ! is_array( $data ) ) {
			return $response;
		}

		if ( Agend_Content_Access_Decision::STATE_GRANTED === $decision['state'] ) {
			if ( ! self::exposes_authoring_source( $data ) || ! self::source_reveals_more_than_render( $post, $decision ) ) {
				return $response;
			}

			// Readable, but not editable by this requester: the rendered body
			// stays, the raw one goes.
			foreach ( array( 'content', 'excerpt' ) as $field ) {
				if ( isset( $data[ $field ] ) && is_array( $data[ $field ] ) ) {
					unset( $data[ $field ]['raw'], $data[ $field ]['block_version'] );
				}
			}

			$response->set_data( $data );

			return $response;
		}

		foreach ( array( 'content', 'excerpt' ) as $field ) {
			if ( isset( $data[ $field ] ) && is_array( $data[ $field ] ) ) {
				$data[ $field ]['rendered']  = '';
				$data[ $field ]['protected'] = true;
				unset( $data[ $field ]['raw'], $data[ $field ]['block_version'] );
			}
		}

		// Never expose the policy itself: its tier ids are the association's
		// plan configuration.
		unset( $data['meta'][ Agend_Content_Access_Policy::META_KEY ] );

		$data['agend_access'] = array( 'state' => $decision['state'] );

		$response->set_data( $data );

		return $response;
	}

	/**
	 * Whether a REST item carries the authoring source of its body.
	 *
	 * Only the `edit` context does. Asked first so a public listing, which never
	 * carries it, pays for no further check.
	 *
	 * @param array $data Response data.
	 * @return bool
	 */
	private static function exposes_authoring_source( array $data ): bool {
		foreach ( array( 'content', 'excerpt' ) as $field ) {
			if (
				isset( $data[ $field ] )
				&& is_array( $data[ $field ] )
				&& ( array_key_exists( 'raw', $data[ $field ] ) || array_key_exists( 'block_version', $data[ $field ] ) )
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a post's raw source says more than its rendered body does.
	 *
	 * True for a post with an audience, because a visitor entitled to read it is
	 * not thereby entitled to its authoring source. True for a post carrying
	 * section rules too, even when the post itself is public: a page builder
	 * stores the rendered text of EVERY section in the raw body, restricted ones
	 * included, so the raw body of such a page is a copy of what the sections
	 * were hiding. Only a post that is plainly public and carries nothing keeps
	 * its raw body, since there is nothing in it the rendered page withholds.
	 *
	 * @param WP_Post $post     Post.
	 * @param array   $decision Result of `Agend_Content_Access_Decision::for_post()`.
	 * @return bool
	 */
	private static function source_reveals_more_than_render( $post, array $decision ): bool {
		$policy = $decision['policy'];

		if ( null !== $policy && Agend_Content_Access_Policy::MODE_PUBLIC !== ( $policy['mode'] ?? '' ) ) {
			return true;
		}

		return Agend_Content_Access_Fragment_Rules::carries( $post );
	}
}
