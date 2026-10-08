<?php
/**
 * Test fixture: declares the two WooCommerce page lookups
 * `agend_apps_saml_link_destination_blocked()` consults behind their own
 * `function_exists()` guards (`wc_get_page_permalink()` and
 * `wc_get_page_id()`), so the cart, checkout and My Account arms of the
 * sign-in destination check are reachable from a test at all. Without it
 * WooCommerce is absent, both guards are false, and the exclusion that keeps a
 * member signing in mid-purchase from being detoured would ship untested.
 *
 * Backed by `$GLOBALS['agend_test_wc_pages']`, a map of page slug (`cart`,
 * `checkout`, `myaccount`) to `array( 'url' => string, 'id' => int )`, reset to
 * empty for every test. With nothing set they report an unset page (an empty
 * permalink, id -1), exactly as WooCommerce does, so merely declaring them
 * changes nothing for any other test.
 *
 * Function declarations cannot be nested in a test method, so this lives in its
 * own plain file, required from `setUp()` for the discovery-order reason
 * `fixtures/saml-idp-stub.php` documents.
 *
 * @package Agend\Tests
 */

declare( strict_types=1 );

if ( ! function_exists( 'wc_get_page_permalink' ) ) {
	function wc_get_page_permalink( string $page ): string {
		return (string) ( $GLOBALS['agend_test_wc_pages'][ $page ]['url'] ?? '' );
	}
}

if ( ! function_exists( 'wc_get_page_id' ) ) {
	function wc_get_page_id( string $page ): int {
		return (int) ( $GLOBALS['agend_test_wc_pages'][ $page ]['id'] ?? -1 );
	}
}
