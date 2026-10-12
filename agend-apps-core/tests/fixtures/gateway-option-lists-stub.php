<?php
/**
 * Test fixture: declares `agend_apps_directory_get_categories()` and
 * `agend_apps_lms_get_courses()`, two of the gateway calls an editor's option
 * lists make, and records each call in `$GLOBALS['agend_test_gateway_calls']`
 * so a test can also prove a visitor's render never makes one.
 *
 * The events wrappers the other lists read are declared, unrecorded, by
 * tests/doubles.php.
 *
 * Function declarations cannot be nested inside a test method, so this lives
 * in its own plain file, required by the one test class that needs it. That
 * class runs in separate processes, so the functions never reach any other
 * test, which relies on them being absent.
 *
 * @package Agend\Tests
 */

declare( strict_types=1 );

if ( ! function_exists( 'agend_apps_directory_get_categories' ) ) {
	/**
	 * @return array|WP_Error
	 */
	function agend_apps_directory_get_categories() {
		$GLOBALS['agend_test_gateway_calls'][] = 'directory_categories';
		return array(
			'data' => array(
				array( 'id' => 'cat-1', 'name' => 'Retail' ),
				array( 'id' => 'cat-2', 'name' => 'Health' ),
			),
		);
	}
}

if ( ! function_exists( 'agend_apps_lms_get_courses' ) ) {
	/**
	 * @param array $query Query parameters.
	 * @return array|WP_Error
	 */
	function agend_apps_lms_get_courses( array $query = array() ) {
		$GLOBALS['agend_test_gateway_calls'][] = 'lms_courses';
		return array(
			'data' => array(
				array( 'category' => 'Safety' ),
				array( 'category' => 'Leadership' ),
			),
		);
	}
}
