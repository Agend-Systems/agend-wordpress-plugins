<?php
/**
 * Global stand-ins for the kiosk's entitlement inheritance switches, read by
 * the job credit store. Tests set the globals below.
 *
 * @package Agend\Tests
 */

if ( ! function_exists( 'agend_should_fetch_entitlements_from_account' ) ) {
	function agend_should_fetch_entitlements_from_account(): bool {
		return (bool) ( $GLOBALS['agend_test_inherit_account'] ?? false );
	}
}

if ( ! function_exists( 'agend_should_fetch_entitlements_from_ultimate_parent_account' ) ) {
	function agend_should_fetch_entitlements_from_ultimate_parent_account(): bool {
		return (bool) ( $GLOBALS['agend_test_inherit_parent'] ?? false );
	}
}
