<?php
/**
 * Marks the templates in use as detail layouts in the block editor's
 * reusable-block (`wp_block`) admin list, so nobody deletes the live detail
 * layout by accident.
 *
 * Mirrors agend-elementor/includes/adapters/template-post-states.php for the
 * block-editor equivalent. The option names it reads
 * ({@see agend_apps_records_detail_template_labels()}) already store a plain
 * template post id regardless of which renderer produced it, so the same
 * template id is "in use" whether it happens to be an `elementor_library`
 * post or a `wp_block` post; the only thing specific to this file is the
 * `wp_block` post type check.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds a post state for each detail-template option pointing at the row.
 *
 * @param array   $states Post states.
 * @param WP_Post $post   The list row's post.
 * @return array
 */
function agend_apps_block_template_post_states( array $states, $post ): array {
	if ( ! ( $post instanceof WP_Post ) || 'wp_block' !== $post->post_type ) {
		return $states;
	}

	$keys = array(
		AGEND_APPS_RECORDS_EVENT_DETAIL_TEMPLATE_OPTION   => 'agend_event_detail',
		AGEND_APPS_RECORDS_COURSE_DETAIL_TEMPLATE_OPTION  => 'agend_course_detail',
		AGEND_APPS_RECORDS_LISTING_DETAIL_TEMPLATE_OPTION => 'agend_listing_detail',
	);

	foreach ( agend_apps_records_detail_template_labels() as $option => $label ) {
		if ( $post->ID === absint( get_option( $option, 0 ) ) ) {
			$states[ $keys[ $option ] ] = $label;
		}
	}

	return $states;
}
add_filter( 'display_post_states', 'agend_apps_block_template_post_states', 10, 2 );
