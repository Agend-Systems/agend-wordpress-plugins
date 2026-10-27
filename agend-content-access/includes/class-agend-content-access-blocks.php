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
 * WHY NOT THE DOCUMENT FOR THE QUERIED OBJECT. The Elementor driver reads the
 * document policy of `get_queried_object_id()`, which is right where a document
 * renders for the queried post. Blocks render inside query loops, template
 * parts and synced patterns, where the queried object is not the post the block
 * belongs to. The post a block belongs to is the post WordPress is rendering,
 * `get_post()`, and that is what this reads. Reading the queried object here
 * would judge a restricted post's block in a loop on a public page against the
 * public page, and fail open.
 *
 * The authoring carve-out (`Agend_Content_Access_Frontend::renders_for_visitors()`)
 * and a display-condition fallback that is itself restricted (`fallback_for()`)
 * are handled here as well, and each fails silently if it is missed.
 */
class Agend_Content_Access_Blocks {

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
	 * Registers hooks. Called on every request, front end and REST alike: a REST
	 * request never reaches `template_redirect`, and renders blocks all the same.
	 */
	public function __construct() {
		add_filter( 'pre_render_block', array( $this, 'pre_render_block' ), self::PRE_RENDER_PRIORITY, 3 );
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

		$settings   = Agend_Content_Access_Block_Attributes::settings_for_block( $parsed_block );
		$conditions = Agend_Content_Access_Fragment_Rules::conditions_enabled( $settings );
		$fragment   = Agend_Content_Access_Policy::from_fragment_settings( $settings );

		// The common case, and it must stay this cheap: no attribute, or one
		// that only says `inherit`, is a block like any other.
		if ( null === $fragment && ! $conditions ) {
			return null;
		}

		if ( self::is_authoring() ) {
			return null;
		}

		try {
			// Display conditions first, as Elementor does. They can only remove,
			// so a block hidden here is hidden whatever the policy says. Never
			// null from here: a block the conditions excluded must not fall
			// through to rendering.
			if ( $conditions && ! Agend_Content_Access_Fragment_Rules::conditions_pass( $settings ) ) {
				return $this->fallback_for( $settings );
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
			$post_id   = self::context_post_id();
			$document  = $post_id > 0 ? Agend_Content_Access_Policy::enforced_for_post( $post_id ) : null;
			$effective = Agend_Content_Access_Policy::effective_policy( $document, $fragment );

			// The viewer is built for THIS policy, so segments are fetched only
			// when the effective policy of this block is a segments policy.
			$state = Agend_Content_Access_Decision::evaluate(
				$effective,
				Agend_Content_Access_Decision::viewer( $effective )
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
	 * The id of the post WordPress is rendering, or 0 when there is none.
	 *
	 * `get_post()` with no argument, the global post: set by the main loop, by
	 * every `WP_Query` loop (`the_post()`, which a query loop block calls per
	 * item), and by the REST controllers. Never `get_queried_object_id()`: see
	 * the class docblock.
	 *
	 * A synced pattern renders inside the post that holds it, so its blocks
	 * belong to that post for this purpose, which can only narrow them further.
	 *
	 * @return int
	 */
	private static function context_post_id(): int {
		$post = get_post();

		return $post instanceof WP_Post ? (int) $post->ID : 0;
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
	 * @param array $settings The block's neutral settings.
	 * @return string The fallback markup, or '' for none.
	 */
	private function fallback_for( array $settings ): string {
		$fallback_id = isset( $settings[ Agend_Content_Access_Fragment_Rules::FALLBACK_KEY ] )
			? (int) $settings[ Agend_Content_Access_Fragment_Rules::FALLBACK_KEY ]
			: 0;

		// A fallback already being rendered is a cycle, and renders nothing.
		if ( $fallback_id <= 0 || isset( $this->fallbacks_open[ $fallback_id ] ) ) {
			return '';
		}

		$this->fallbacks_open[ $fallback_id ] = true;

		try {
			return Agend_Content_Access_Fragment_Rules::fallback_html( $fallback_id );
		} finally {
			unset( $this->fallbacks_open[ $fallback_id ] );
		}
	}
}
