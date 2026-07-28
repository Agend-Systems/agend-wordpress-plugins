<?php
/**
 * Test doubles for Agend classes and functions the units under test depend on.
 *
 * Declared once at bootstrap, with mutable behaviour driven through
 * {@see Agend_Test_WP}. Kept separate from wp-stubs.php so the WordPress
 * surface and the Agend surface stay distinguishable.
 *
 * @package Agend\Tests
 */

declare( strict_types=1 );

if ( ! class_exists( 'Agend_Apps_Settings' ) ) {
	/** Settings double. Values are fixed; nothing under test varies by them. */
	class Agend_Apps_Settings {
		public static function get_api_key(): string {
			return 'test-api-key';
		}

		public static function get_base_url(): string {
			return 'https://api.example.test/v1';
		}

		public static function get_vercel_bypass_token(): string {
			return '';
		}

		public static function get_cache_ttl( string $endpoint_key ): int {
			return 300;
		}
	}
}

if ( ! class_exists( 'Agend_Apps_Cache' ) ) {
	/** Cache-key builder double, matching Core's deterministic key shape. */
	class Agend_Apps_Cache {
		public static function build_key( string $prefix, array $query = array() ): string {
			return $prefix . ( array() === $query ? '' : '_' . md5( (string) wp_json_encode( $query ) ) );
		}

		public static function get_all_keys(): array {
			return array();
		}
	}
}

// The real Core API client, loaded once here rather than doubled.
//
// It declares agend_apps_api() and agend_apps_get_bearer_token() WITHOUT
// function_exists guards, so a double for either would fatal the moment a test
// required the real file. Loading it after the Settings and Cache doubles above
// gives every test both the genuine client and the two functions the Content
// Access dependency probe looks for.
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-api.php';

if ( ! function_exists( 'agend_apps_crm_get_my_entitlements' ) ) {
	/**
	 * Resolved member standing double.
	 *
	 * Tests drive the outcome with
	 * `Agend_Test_WP::set_filter( 'agend_apps_crm_get_my_entitlements_response', ... )`,
	 * including returning a WP_Error to exercise an outage.
	 *
	 * It records a request so callers can assert on call COUNT, which is what
	 * makes the per-request memoisation testable.
	 *
	 * @return mixed
	 */
	function agend_apps_crm_get_my_entitlements() {
		Agend_Test_WP::$requests[] = array(
			'url'     => '/crm/me/entitlements',
			'headers' => array(),
		);

		return apply_filters(
			'agend_apps_crm_get_my_entitlements_response',
			array( 'data' => array( 'tier_ids' => array(), 'is_member' => false ) )
		);
	}
}

if ( ! function_exists( 'agend_apps_crm_get_tiers' ) ) {
	/**
	 * Tier catalogue double.
	 *
	 * Returns whatever the test placed in Agend_Test_WP::$tiers_response,
	 * including a WP_Error when the test is exercising an outage.
	 *
	 * @param array $query Ignored.
	 * @return mixed
	 */
	function agend_apps_crm_get_tiers( array $query = array() ) {
		return Agend_Test_WP::$tiers_response;
	}
}

if ( ! function_exists( 'agend_apps_crm_get_my_segments' ) ) {
	/**
	 * Member segments double.
	 *
	 * Tests drive the outcome with
	 * `Agend_Test_WP::$filters['agend_apps_crm_get_my_segments_response']`,
	 * including a WP_Error to exercise an outage. Records a request so call
	 * COUNT is assertable, which is what makes the per-viewer cache testable.
	 *
	 * @return mixed
	 */
	function agend_apps_crm_get_my_segments() {
		Agend_Test_WP::$requests[] = array(
			'url'     => '/crm/me/segments',
			'headers' => array(),
		);

		return apply_filters(
			'agend_apps_crm_get_my_segments_response',
			array( 'data' => array() )
		);
	}
}
