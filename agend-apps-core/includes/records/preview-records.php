<?php
/**
 * Editor preview records for the Agend field widgets.
 *
 * A field widget rendered inside a card/detail template has no
 * {@see Agend_Apps_Records_Record_Context} frame while it is being edited in
 * Elementor (the widget preview panel calls `render()` directly, outside any
 * catalogue or SSR detail render), so it needs a real-shaped record to preview
 * against. This file resolves one: the first upcoming event, the first course,
 * or the first directory listing, falling back to a synthetic placeholder
 * record when neither the host wrappers nor the gateway are available (a fresh
 * install, or the plugin activated without Agend Apps Core).
 *
 * A listing is resolved through its detail endpoint rather than its card
 * payload, because the content blocks a detail template is built from (hours,
 * gallery, custom fields, reviews ...) read keys the list payload does not
 * carry, and a block that previews empty tells a template author nothing.
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
 * @param string $type 'event', 'course' or 'listing'.
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

	if ( 'listing' === $type ) {
		return array(
			'_agend_preview_placeholder' => true,
			'slug'                       => 'sample-listing',
			'name'                       => 'Sample Listing',
			'logo_url'                   => '',
			'hero_image_url'             => '',
			'short_description'          => 'A sample directory listing used for template preview.',
			'description'                => '<p>A longer sample listing description used for template preview. It shows how the About block renders a listing\'s formatted body text.</p>',
			'categories'                 => array(
				array( 'name' => 'Professional Services' ),
				array( 'name' => 'Consulting' ),
			),
			'tags'                       => array(
				array( 'name' => 'Accredited' ),
				array( 'name' => 'Sydney' ),
			),
			'phone'                      => '+61 2 9000 0000',
			'email'                      => 'hello@example.com',
			'website'                    => 'https://example.com',
			'linkedin_url'               => 'https://www.linkedin.com/company/example',
			'city'                       => 'Sydney',
			'state'                      => 'NSW',
			'postcode'                   => '2000',
			'address_line_1'             => '1 Sample Street',
			'average_rating'             => 4.5,
			'review_count'               => 2,
			'rating_breakdown'           => array( '5' => 1, '4' => 1, '3' => 0, '2' => 0, '1' => 0 ),
			'is_featured'                => true,
			'updated_at'                 => gmdate( 'c', time() - WEEK_IN_SECONDS ),
			// Empty on purpose: every other key here stands in for real data,
			// but there is no image this plugin could ship that would not
			// read as a listing's own photo. The Gallery block previews as
			// "nothing to show for this block" until the site has a listing
			// with images, which is what it would honestly render.
			'gallery_images'             => array(),
			'locations'                  => array(
				array(
					'name'           => 'Sydney office',
					'address_line_1' => '1 Sample Street',
					'city'           => 'Sydney',
					'state'          => 'NSW',
					'postcode'       => '2000',
					'phone'          => '+61 2 9000 0000',
				),
			),
			'business_hours'             => array(
				'monday'    => array( 'open' => '9:00am', 'close' => '5:00pm' ),
				'tuesday'   => array( 'open' => '9:00am', 'close' => '5:00pm' ),
				'wednesday' => array( 'open' => '9:00am', 'close' => '5:00pm' ),
				'thursday'  => array( 'open' => '9:00am', 'close' => '5:00pm' ),
				'friday'    => array( 'open' => '9:00am', 'close' => '4:00pm' ),
				'saturday'  => array( 'closed' => true ),
				'sunday'    => array( 'closed' => true ),
			),
			'custom_fields'              => array(
				array( 'label' => 'ABN', 'type' => 'text', 'value' => '00 000 000 000' ),
				array( 'label' => 'Established', 'type' => 'text', 'value' => '2014' ),
				array( 'label' => 'Services', 'type' => 'array', 'value' => array( 'Advisory', 'Audit' ) ),
			),
			'achievements'               => array(
				array( 'name' => 'Sample Credential', 'type' => 'certificate', 'earned_at' => gmdate( 'c', time() - ( 30 * DAY_IN_SECONDS ) ) ),
			),
		);
	}

	return array();
}

/**
 * The reviews response a placeholder listing previews against.
 *
 * The reviews block fetches per slug when the render context does not already
 * carry a response {@see agend_apps_records_fragment_listing_reviews()}. A
 * placeholder listing's slug names no real listing, so previewing it would
 * spend a gateway round trip to be told so; this stands in for that call and
 * gives the block something to lay out.
 *
 * @return array<string, mixed> A reviews list response, gateway shape.
 */
function agend_apps_records_preview_placeholder_reviews(): array {
	return array(
		'data' => array(
			array(
				'reviewer_name' => 'Sample Reviewer',
				'rating'        => 5,
				'is_verified'   => true,
				'created_at'    => gmdate( 'c', time() - ( 14 * DAY_IN_SECONDS ) ),
				'content'       => 'A sample review used for template preview.',
			),
			array(
				'reviewer_name' => 'Second Reviewer',
				'rating'        => 4,
				'is_verified'   => false,
				'created_at'    => gmdate( 'c', time() - ( 40 * DAY_IN_SECONDS ) ),
				'content'       => 'A second sample review, so the list previews as a list.',
			),
		),
	);
}

/**
 * The render context an editor preview record is rendered with.
 *
 * The same `extra` shape a live catalogue or SSR detail render pushes onto
 * {@see Agend_Apps_Records_Record_Context}, minus the things that only exist
 * on a real page (a host page, a real detail URL).
 *
 * @param string               $type   'event', 'course' or 'listing'.
 * @param array<string, mixed> $record The preview record.
 * @return array<string, mixed> The render context.
 */
function agend_apps_records_preview_extra( string $type, array $record ): array {
	$extra = array(
		'slug'       => isset( $record['slug'] ) ? (string) $record['slug'] : '',
		'detail_url' => '#',
		'is_detail'  => false,
	);

	if ( 'listing' === $type && ! empty( $record['_agend_preview_placeholder'] ) ) {
		$extra['reviews'] = agend_apps_records_preview_placeholder_reviews();
	}

	return $extra;
}

/**
 * Fetches one real record for the given type, for editor preview.
 *
 * Cached in a transient for 10 minutes -- the record is only ever used to
 * populate a preview, so a slightly stale one is harmless, and the cache
 * spares the editor a live gateway call on every template edit.
 *
 * @param string $type 'event', 'course' or 'listing'.
 * @return array<string, mixed> The record, or the synthetic placeholder when
 *                               no real record can be resolved.
 */
function agend_apps_records_preview_record( string $type ): array {
	$fetchers = array(
		'event'   => 'agend_apps_records_fetch_preview_event',
		'course'  => 'agend_apps_records_fetch_preview_course',
		'listing' => 'agend_apps_records_fetch_preview_listing',
	);

	if ( ! isset( $fetchers[ $type ] ) ) {
		return array();
	}

	$cache_key = 'agend_elementor_preview_record_' . $type;
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$record = $fetchers[ $type ]();

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
 * Fetches the first directory listing, then re-reads it through the detail
 * endpoint so the preview carries the keys only a detail payload has
 * (gallery, locations, hours, custom fields, achievements).
 *
 * Achievements are requested only when the site has opted in, matching
 * {@see agend_apps_records_ssr_resolve_listing()}: the gateway 403s the whole
 * detail for a key without the `directory.achievements.browse` scope, and a
 * 403 here would cost the preview every other detail-only key with it.
 *
 * A failed detail read falls back to the list payload rather than to the
 * synthetic placeholder: a real listing missing its detail-only keys still
 * previews the right name, image and categories, which a made-up one does
 * not. The placeholder is reached only when there is no listing to read at
 * all {@see agend_apps_records_preview_record()}.
 *
 * @return array<string, mixed> The listing record, or an empty array when the
 *                               wrapper is unavailable or nothing is found.
 */
function agend_apps_records_fetch_preview_listing(): array {
	if ( ! function_exists( 'agend_apps_directory_get_listings' ) ) {
		return array();
	}

	$card = agend_apps_records_first_response_item( agend_apps_directory_get_listings( array( 'limit' => 1 ) ) );
	$slug = isset( $card['slug'] ) ? (string) $card['slug'] : '';

	if ( '' === $slug || ! function_exists( 'agend_apps_directory_get_listing' ) ) {
		return $card;
	}

	$query = ( function_exists( 'agend_apps_records_show_achievements_enabled' ) && agend_apps_records_show_achievements_enabled() )
		? array( 'include' => 'achievements' )
		: array();

	$response = agend_apps_directory_get_listing( $slug, $query );
	$detail   = ( ! is_wp_error( $response ) && ! empty( $response['data'] ) && is_array( $response['data'] ) )
		? $response['data']
		: array();

	return array() !== $detail ? $detail : $card;
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
