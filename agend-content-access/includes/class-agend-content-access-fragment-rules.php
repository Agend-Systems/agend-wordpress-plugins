<?php
/**
 * Documents that carry rules of their own below the document policy.
 *
 * @package Agend_Content_Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Knows which posts hold section-level rules, and so which posts' RAW body is
 * more than the page shows.
 *
 * A document policy governs the whole document, and the gates elsewhere in this
 * plugin enforce it wherever the document renders. A section rule is different:
 * the page is public and one section of it is not. The rendered page withholds
 * that section from the people it is not for, but the post's raw body (what
 * `post_content` holds, what core search matches against, what an automatic
 * excerpt is cut from, what the REST edit context returns) is written by the page
 * builder with EVERY section in it. Anything that reads the raw body therefore
 * publishes the restricted section, and none of the render-time gates see it.
 *
 * This class is the one place that question is answered, so each reader asks the
 * same thing and gets the same answer.
 */
class Agend_Content_Access_Fragment_Rules {

	/**
	 * Whether a post carries section-level rules, decided from the document now.
	 *
	 * A LIVE check of the stored document, not a read of a cached flag, so it is
	 * correct for a post saved before any flag existed.
	 *
	 * @param WP_Post|int $post Post or post id.
	 * @return bool
	 */
	public static function carries( $post ): bool {
		$post_id = $post instanceof WP_Post ? (int) $post->ID : (int) $post;

		if ( $post_id <= 0 ) {
			return false;
		}

		$carries = self::carries_elementor_rules( $post_id );

		/**
		 * Filters whether a post carries section-level rules.
		 *
		 * The seam an editor integration plugs its own document format into, so a
		 * post built another way is covered by every reader that asks. Receives the
		 * answer so far: return it unchanged, or true, never false for a document
		 * that does carry rules.
		 *
		 * @param bool $carries Whether the post carries rules, as far as is known.
		 * @param int  $post_id Post id.
		 */
		return (bool) apply_filters( 'agend_content_access_post_carries_fragment_rules', $carries, $post_id );
	}

	/**
	 * Whether a post's Elementor document carries a fragment policy or display
	 * conditions anywhere in it.
	 *
	 * Reads the document whether or not Elementor is active now. Elementor keeps
	 * `_elementor_data` after it is deactivated, and the body it wrote into
	 * `post_content` stays too, so a site that removes Elementor is not thereby
	 * rid of the text of its restricted sections.
	 *
	 * @param int $post_id Post id.
	 * @return bool
	 */
	public static function carries_elementor_rules( int $post_id ): bool {
		if ( ! class_exists( 'Agend_Content_Access_Elementor', false ) ) {
			require_once __DIR__ . '/class-agend-content-access-elementor.php';
		}

		return Agend_Content_Access_Elementor::data_carries_policy(
			get_post_meta( $post_id, '_elementor_data', true )
		);
	}
}
