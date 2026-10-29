<?php
/**
 * Stand-in for Core's segments wrapper.
 *
 * Core has had `agend_apps_crm_get_segments()` for a long time, but the unit
 * suite does not load Core's CRM file (it declares functions the shared doubles
 * already define, without `function_exists()` guards). Content Access probes
 * the wrapper with `function_exists()` anyway, and a PHP function cannot be
 * undefined once declared, so the tests that want Core to have it run in
 * separate processes and `require` this file only when they do. The tests that
 * show the message for a Core without it simply do not.
 *
 * The response is whatever the test supplies through
 * `agend_apps_crm_get_segments_response`, including a WP_Error to exercise an
 * outage or a refusal. Each call is recorded so call COUNT is assertable, which
 * is what makes the cache testable.
 *
 * Not a test file: it has no `Test.php` suffix, so PHPUnit does not collect it.
 *
 * @package Agend\Tests
 */

declare( strict_types=1 );

if ( ! function_exists( 'agend_apps_crm_get_segments' ) ) {
	function agend_apps_crm_get_segments( array $query = array() ) {
		Agend_Test_WP::$requests[] = array(
			'url'     => '/crm/segments',
			'headers' => array(),
		);

		return apply_filters(
			'agend_apps_crm_get_segments_response',
			array( 'success' => true, 'data' => array() )
		);
	}
}
