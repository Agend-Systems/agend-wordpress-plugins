<?php
/**
 * Test fixture: the four gateway reads the directory widgets that sit beside
 * a catalogue make on the server (a one-listing search for the total, the
 * categories, the facets, and the check that a facet set is the same for every
 * viewer).
 *
 * The answers are `$GLOBALS['agend_test_dw_search']`,
 * `$GLOBALS['agend_test_dw_categories']` and `$GLOBALS['agend_test_dw_facets']`
 * (decoded responses, or a WP_Error); each call is appended to
 * `$GLOBALS['agend_test_dw_requests']` as `array( <read>, <argument> )`, so a
 * test can also prove which reads a render made.
 *
 * Function declarations cannot be nested inside a test method, so this lives in
 * its own plain file, required by test classes that run in separate processes.
 *
 * @package Agend\Tests
 */

declare( strict_types=1 );

if ( ! function_exists( 'agend_apps_directory_search' ) ) {
	/**
	 * @param string $search_query Search term.
	 * @param array  $filters      Search parameters.
	 * @return array|WP_Error
	 */
	function agend_apps_directory_search( string $search_query, array $filters = array() ) {
		$GLOBALS['agend_test_dw_requests'][] = array( 'search', $filters );
		return $GLOBALS['agend_test_dw_search'] ?? array( 'data' => array() );
	}
}

if ( ! function_exists( 'agend_apps_directory_get_categories' ) ) {
	/** @return array|WP_Error */
	function agend_apps_directory_get_categories() {
		$GLOBALS['agend_test_dw_requests'][] = array( 'categories', null );
		return $GLOBALS['agend_test_dw_categories'] ?? array( 'data' => array() );
	}
}

if ( ! function_exists( 'agend_apps_directory_get_facets' ) ) {
	/**
	 * @param string[] $fields Facet names.
	 * @return array|WP_Error
	 */
	function agend_apps_directory_get_facets( array $fields = array() ) {
		$GLOBALS['agend_test_dw_requests'][] = array( 'facets', $fields );
		return $GLOBALS['agend_test_dw_facets'] ?? array( 'data' => array() );
	}
}

if ( ! function_exists( 'agend_apps_directory_facets_viewer_independent' ) ) {
	/**
	 * The real rule, restated: a facet set names at least one facet and no
	 * `custom.<key>` facet.
	 *
	 * @param string[] $fields Facet names.
	 */
	function agend_apps_directory_facets_viewer_independent( array $fields ): bool {
		if ( empty( $fields ) ) {
			return false;
		}
		foreach ( $fields as $name ) {
			if ( 0 === strpos( $name, 'custom.' ) ) {
				return false;
			}
		}
		return true;
	}
}
