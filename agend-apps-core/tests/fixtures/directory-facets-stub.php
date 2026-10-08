<?php
/**
 * Test fixture: declares `agend_apps_directory_get_facets()`, the gateway call
 * an editor preview of a Range brackets filter makes to read Agend's brackets
 * for a custom field.
 *
 * The answer is whatever a test puts in `$GLOBALS['agend_test_facets_response']`
 * (a decoded response, or a WP_Error), and each call's requested facet names
 * are appended to `$GLOBALS['agend_test_facets_requests']`, so a test can also
 * prove a live render never asks.
 *
 * Function declarations cannot be nested inside a test method, so this lives
 * in its own plain file, required by the one test class that needs it. That
 * class runs in separate processes, so the function never reaches any other
 * test, which relies on it being absent to prove the "API unavailable" path.
 *
 * @package Agend\Tests
 */

declare( strict_types=1 );

if ( ! function_exists( 'agend_apps_directory_get_facets' ) ) {
	/**
	 * @param string[] $fields Requested facet names.
	 * @return array|WP_Error
	 */
	function agend_apps_directory_get_facets( array $fields = array() ) {
		$GLOBALS['agend_test_facets_requests'][] = $fields;
		return $GLOBALS['agend_test_facets_response'] ?? array( 'data' => array() );
	}
}
