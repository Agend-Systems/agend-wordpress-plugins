<?php
/**
 * Test fixture: declares `agend_apps_directory_get_listings()` and
 * `agend_apps_directory_get_listing()`, the two gateway calls an editor preview
 * of a directory listing makes.
 *
 * Each call is appended to `$GLOBALS['agend_test_directory_requests']` as
 * `array( 'listings' | 'listing', <slug or ''> )`, so a test can also prove a
 * visitor's render never asks. The answers are `$GLOBALS['agend_test_directory_listings']`
 * and `$GLOBALS['agend_test_directory_listing']` (decoded responses, or a
 * WP_Error).
 *
 * Function declarations cannot be nested inside a test method, so this lives
 * in its own plain file, required by the one test class that needs it. That
 * class runs in separate processes, so the functions never reach any other
 * test, which relies on them being absent to prove the "wrapper unavailable"
 * path.
 *
 * @package Agend\Tests
 */

declare( strict_types=1 );

if ( ! function_exists( 'agend_apps_directory_get_listings' ) ) {
	/**
	 * @param array $query Query parameters.
	 * @return array|WP_Error
	 */
	function agend_apps_directory_get_listings( array $query = array() ) {
		$GLOBALS['agend_test_directory_requests'][] = array( 'listings', '' );
		return $GLOBALS['agend_test_directory_listings'] ?? array( 'data' => array() );
	}
}

if ( ! function_exists( 'agend_apps_directory_get_listing' ) ) {
	/**
	 * @param string $listing_id Listing slug.
	 * @param array  $query      Query parameters.
	 * @return array|WP_Error
	 */
	function agend_apps_directory_get_listing( string $listing_id, array $query = array() ) {
		$GLOBALS['agend_test_directory_requests'][] = array( 'listing', $listing_id );
		return $GLOBALS['agend_test_directory_listing'] ?? array( 'data' => array() );
	}
}
