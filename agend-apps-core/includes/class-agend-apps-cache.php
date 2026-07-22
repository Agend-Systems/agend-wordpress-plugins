<?php
/**
 * Cache manager class.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages transient-based caching for Agend API responses.
 *
 * All transient keys are prefixed with `agend_apps_` to prevent collisions.
 * Cart transients use identity-scoped keys and must only be cleared individually
 * during normal operation — wildcard clearing is reserved for the admin UI.
 */
class Agend_Apps_Cache {

	/**
	 * Map of all cacheable endpoint keys with their labels and default TTLs.
	 *
	 * @var array<string, array{label: string, default_ttl: int}>
	 */
	private static $keys = array(
		'cart_get'                 => array(
			'label'       => 'Cart Session',
			'default_ttl' => 60,
		),
		'cart_get_anonymous'       => array(
			'label'       => 'Anonymous Cart',
			'default_ttl' => 30,
		),
		'directory_listings'       => array(
			'label'       => 'Directory Listings',
			'default_ttl' => 300,
		),
		'directory_listing_single' => array(
			'label'       => 'Single Listing',
			'default_ttl' => 300,
		),
		'directory_categories'     => array(
			'label'       => 'Categories',
			'default_ttl' => 600,
		),
		'directory_search'         => array(
			'label'       => 'Directory Search',
			'default_ttl' => 120,
		),
		'directory_listing_reviews' => array(
			'label'       => 'Listing Reviews',
			'default_ttl' => 120,
		),
		'events_list'              => array(
			'label'       => 'Events List',
			'default_ttl' => 120,
		),
		'events_single'            => array(
			'label'       => 'Single Event',
			'default_ttl' => 300,
		),
		'events_tickets'           => array(
			'label'       => 'Event Tickets',
			'default_ttl' => 300,
		),
		'events_attendee_fields'   => array(
			'label'       => 'Event Attendee Fields',
			'default_ttl' => 300,
		),
		'events_categories'        => array(
			'label'       => 'Event Categories',
			'default_ttl' => 600,
		),
		'events_venues'            => array(
			'label'       => 'Event Venues',
			'default_ttl' => 600,
		),
		'events_embed'             => array(
			'label'       => 'Events Embed Feed',
			'default_ttl' => 300,
		),
		'lms_courses'              => array(
			'label'       => 'LMS Courses',
			'default_ttl' => 300,
		),
		'lms_course_single'        => array(
			'label'       => 'Single Course',
			'default_ttl' => 300,
		),
		'lms_course_lessons'       => array(
			'label'       => 'Course Lessons',
			'default_ttl' => 300,
		),
		'lms_paths'                => array(
			'label'       => 'Learning Paths',
			'default_ttl' => 300,
		),
		'lms_path_single'          => array(
			'label'       => 'Single Learning Path',
			'default_ttl' => 300,
		),
		'lms_discovery'            => array(
			'label'       => 'LMS Discovery Blocks',
			'default_ttl' => 300,
		),
		'crm_tiers'                => array(
			'label'       => 'Membership Tiers',
			'default_ttl' => 3600,
		),
		'crm_tier_single'          => array(
			'label'       => 'Single Membership Tier',
			'default_ttl' => 3600,
		),
		'crm_types'                => array(
			'label'       => 'CRM Deal Types',
			'default_ttl' => 3600,
		),
		'crm_stages'               => array(
			'label'       => 'CRM Pipeline Stages',
			'default_ttl' => 3600,
		),
		'cms_content'              => array(
			'label'       => 'CMS Content List',
			'default_ttl' => 300,
		),
		'cms_content_single'       => array(
			'label'       => 'Single CMS Content',
			'default_ttl' => 300,
		),
		'jobs_list'                => array(
			'label'       => 'Jobs List',
			'default_ttl' => 300,
		),
		'jobs_single'              => array(
			'label'       => 'Single Job',
			'default_ttl' => 300,
		),
	);

	/**
	 * Returns the full endpoint key map.
	 *
	 * @return array<string, array{label: string, default_ttl: int}>
	 */
	public static function get_all_keys(): array {
		return self::$keys;
	}

	/**
	 * Clears all cached transients matching the given endpoint key prefix.
	 *
	 * Uses a `LIKE` query against the options table so that parameterised
	 * variants (e.g. search results with different query strings) are all
	 * removed in a single operation. Both the transient value and its
	 * corresponding timeout entry are deleted.
	 *
	 * @param string $endpoint_key Endpoint key from Agend_Apps_Cache::get_all_keys().
	 * @return bool True on success, false if no rows were affected or on failure.
	 */
	public static function clear( string $endpoint_key ): bool {
		global $wpdb;

		$value_pattern   = $wpdb->esc_like( '_transient_agend_apps_' . $endpoint_key ) . '%';
		$timeout_pattern = $wpdb->esc_like( '_transient_timeout_agend_apps_' . $endpoint_key ) . '%';

		$result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$value_pattern,
				$timeout_pattern
			)
		);

		return false !== $result && $result > 0;
	}

	/**
	 * Clears all cached transients for every registered endpoint key.
	 */
	public static function clear_all(): void {
		foreach ( array_keys( self::$keys ) as $key ) {
			self::clear( $key );
		}
	}

	/**
	 * Builds a deterministic transient cache key for a given endpoint and parameter set.
	 *
	 * The returned string is suitable for use as the `$cache_key` argument to
	 * `Agend_Apps_API::get_cached()`. The full transient name stored by WordPress
	 * will be `agend_apps_{returned_key}`.
	 *
	 * @param string $endpoint_key Endpoint key, e.g. `directory_listings`.
	 * @param array  $params       Optional. Query parameters that affect the response. Default empty.
	 * @return string Cache key prefixed with the endpoint key.
	 */
	public static function build_key( string $endpoint_key, array $params = array() ): string {
		return $endpoint_key . '_' . md5( $endpoint_key . serialize( $params ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
	}

	/**
	 * Returns the count of currently stored Agend transients.
	 *
	 * Counts value entries only (excludes `_transient_timeout_` rows) to
	 * give an accurate picture of how many cached responses exist.
	 *
	 * @return int Number of stored Agend transients.
	 */
	public static function count_stored(): int {
		global $wpdb;

		$count = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s",
				$wpdb->esc_like( '_transient_agend_apps_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_agend_apps_' ) . '%'
			)
		);

		return (int) $count;
	}
}
