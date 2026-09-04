<?php
/**
 * Marks the templates in use as detail layouts in Elementor's Saved
 * Templates list, so nobody deletes the live detail layout by accident.
 *
 * Elementor-specific: only `elementor_library` posts carry this list, so the
 * filter itself (as opposed to the labels it displays) stays out of the
 * agnostic settings file.
 *
 * @package Agend_Elementor
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
function agend_elementor_template_post_states( array $states, $post ): array {
	if ( ! ( $post instanceof WP_Post ) || 'elementor_library' !== $post->post_type ) {
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
add_filter( 'display_post_states', 'agend_elementor_template_post_states', 10, 2 );
