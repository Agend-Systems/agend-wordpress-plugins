<?php
/**
 * Editor preview records for the Agend field widgets.
 *
 * A field widget rendered inside a card/detail template has no
 * {@see Agend_Apps_Records_Record_Context} frame while it is being edited in
 * Elementor (the widget preview panel calls `render()` directly, outside any
 * catalogue or SSR detail render), so it needs a real-shaped record to preview
 * against. This file resolves one: the first upcoming event, or the first
 * course, falling back to a synthetic placeholder record when neither the
 * host wrappers nor the gateway are available (a fresh install, or the
 * plugin activated without Agend Apps Core).
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// WordPress core defines these; guarded here (rather than assumed) because
// this file is also loaded by the unit-test stub harness, which does not
// bootstrap wp-includes/default-constants.php.
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'WEEK_IN_SECONDS' ) ) {
	define( 'WEEK_IN_SECONDS', 7 * DAY_IN_SECONDS );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

/**
 * The synthetic placeholder record for a preview type.
 *
 * Flagged `_agend_preview_placeholder` so a template author can tell, if
 * they inspect it, that the values are illustrative rather than a real
 * record. Values are realistic (a real slug shape, a date a week out, a
 * plausible price) rather than empty strings, so a field widget's preview
 * looks like a populated card rather than a broken one.
 *
 * @param string $type 'event' or 'course'.
 * @return array<string, mixed> The placeholder record. Empty array for an
 *                               unknown type.
 */
function agend_apps_records_preview_placeholder_record( string $type ): array {
	if ( 'event' === $type ) {
		return array(
			'_agend_preview_placeholder' => true,
			'slug'                       => 'sample-event',
			'name'                       => 'Sample Event',
			'hero_image_url'             => '',
			'start_date'                 => gmdate( 'c', time() + WEEK_IN_SECONDS ),
			'end_date'                   => gmdate( 'c', time() + WEEK_IN_SECONDS + DAY_IN_SECONDS ),
			'timezone'                   => 'Australia/Sydney',
			'venue_type'                 => 'physical',
			'venue_name'                 => 'Sample Venue',
			'venue_city'                 => 'Sydney',
			'category'                   => 'Conference',
			'categories'                 => array( array( 'name' => 'Conference' ) ),
			'short_description'          => 'A sample event used for template preview.',
			'description'                => 'A longer sample description used for template preview.',
			'price_summary'              => array(
				'member_from'     => 0,
				'non_member_from' => 0,
			),
			'sold_out'                   => false,
			'sponsors'                   => array(),
		);
	}

	if ( 'course' === $type ) {
		return array(
			'_agend_preview_placeholder' => true,
			'slug'                       => 'sample-course',
			'title'                      => 'Sample Course',
			'image_url'                 => '',
			'category'                   => 'Professional Development',
			'difficulty'                 => 'beginner',
			'delivery_mode'              => 'self_paced',
			'description'                => 'A sample course description used for template preview.',
			'total_duration_minutes'     => 90,
			'lessons_count'              => 6,
			'base_price'                 => 0,
			'is_free'                    => true,
			'instructor_name'            => 'Sample Instructor',
			'learning_outcomes'          => array(
				'Understand the fundamentals',
				'Apply the concepts in practice',
				'Assess your own progress',
			),
		);
	}

	return array();
}

/**
 * Fetches one real record for the given type, for editor preview.
 *
 * Cached in a transient for 10 minutes -- the record is only ever used to
 * populate a preview, so a slightly stale one is harmless, and the cache
 * spares the editor a live gateway call on every template edit.
 *
 * @param string $type 'event' or 'course'.
 * @return array<string, mixed> The record, or the synthetic placeholder when
 *                               no real record can be resolved.
 */
function agend_apps_records_preview_record( string $type ): array {
	if ( 'event' !== $type && 'course' !== $type ) {
		return array();
	}

	$cache_key = 'agend_elementor_preview_record_' . $type;
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$record = 'event' === $type
		? agend_apps_records_fetch_preview_event()
		: agend_apps_records_fetch_preview_course();

	if ( array() === $record ) {
		$record = agend_apps_records_preview_placeholder_record( $type );
	}

	set_transient( $cache_key, $record, 10 * MINUTE_IN_SECONDS );

	return $record;
}

/**
 * Fetches the first upcoming event, retrying against all events when there is
 * no upcoming one (a site with only past events, or none scheduled yet).
 *
 * @return array<string, mixed> The event record, or an empty array when the
 *                               wrapper is unavailable or nothing is found.
 */
function agend_apps_records_fetch_preview_event(): array {
	if ( ! function_exists( 'agend_apps_events_get_events' ) ) {
		return array();
	}

	$response = agend_apps_events_get_events(
		array(
			'limit'     => 1,
			'timeframe' => 'upcoming',
		)
	);

	$item = agend_apps_records_first_response_item( $response );
	if ( array() !== $item ) {
		return $item;
	}

	$response = agend_apps_events_get_events(
		array(
			'limit'     => 1,
			'timeframe' => 'all',
		)
	);

	return agend_apps_records_first_response_item( $response );
}

/**
 * Fetches the first course.
 *
 * @return array<string, mixed> The course record, or an empty array when the
 *                               wrapper is unavailable or nothing is found.
 */
function agend_apps_records_fetch_preview_course(): array {
	if ( ! function_exists( 'agend_apps_lms_get_courses' ) ) {
		return array();
	}

	$response = agend_apps_lms_get_courses( array( 'limit' => 1 ) );

	return agend_apps_records_first_response_item( $response );
}

/**
 * Extracts the first item from a list-wrapper's `data` array.
 *
 * @param mixed $response The decoded response, or a WP_Error.
 * @return array<string, mixed> The first item, or an empty array when the
 *                               response is an error, empty, or malformed.
 */
function agend_apps_records_first_response_item( $response ): array {
	if ( is_wp_error( $response ) ) {
		return array();
	}
	if ( ! is_array( $response ) || empty( $response['data'] ) || ! is_array( $response['data'] ) ) {
		return array();
	}

	$first = reset( $response['data'] );

	return is_array( $first ) ? $first : array();
}
