<?php
/**
 * Stand-in for Core's entitlement-types wrapper.
 *
 * `agend_apps_crm_get_entitlement_types()` arrives in a Core release that is
 * not guaranteed to be installed alongside this plugin, so Content Access
 * probes it with `function_exists()`. The unit suite has to show both worlds,
 * and a PHP function cannot be undefined once declared. The tests that need it
 * therefore run in separate processes and `require` this file only when they
 * want Core to have it.
 *
 * The response is whatever the test supplies through
 * `agend_apps_crm_get_entitlement_types_response`, including a WP_Error to
 * exercise an outage. Each call is recorded so call COUNT is assertable, which
 * is what makes the cache testable.
 *
 * Not a test file: it has no `Test.php` suffix, so PHPUnit does not collect it.
 *
 * @package Agend\Tests
 */

declare( strict_types=1 );

if ( ! function_exists( 'agend_apps_crm_get_entitlement_types' ) ) {
	function agend_apps_crm_get_entitlement_types() {
		Agend_Test_WP::$requests[] = array(
			'url'     => '/crm/entitlements/types',
			'headers' => array(),
		);

		return apply_filters(
			'agend_apps_crm_get_entitlement_types_response',
			array( 'success' => true, 'data' => array() )
		);
	}
}
