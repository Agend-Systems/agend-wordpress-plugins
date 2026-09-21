<?php
/**
 * Test fixture: declares the three WooCommerce page conditionals
 * `agend_apps_saml_link_surface_blocked()` consults behind its own
 * `function_exists()` guards (`is_cart()`, `is_checkout()`,
 * `is_account_page()`), so the WooCommerce arms of that gate are reachable
 * from a test at all. Without this fixture those branches are dead in the
 * unit harness: WooCommerce is absent, every `function_exists()` is false,
 * and the exclusion the brief requires would ship untested.
 *
 * Each is backed by a global defaulting to false, so merely declaring them
 * changes nothing for any other test. That is safe here specifically because
 * `agend_apps_saml_link_surface_blocked()` is the ONLY caller of all three
 * anywhere in this repo (verified by grep); a second caller appearing later
 * would inherit these stubs process-wide, which is the usual cost of a
 * fixture that declares functions rather than a class it can name.
 *
 * Function declarations cannot be nested inside a test method (they would
 * leak out of it as global functions on first call and fatal on the second),
 * so this lives in its own plain file, required from `setUp()` for the same
 * discovery-order reason `fixtures/saml-idp-stub.php` documents.
 *
 * @package Agend\Tests
 */

declare( strict_types=1 );

if ( ! function_exists( 'is_cart' ) ) {
	function is_cart(): bool {
		return ! empty( $GLOBALS['agend_test_is_cart'] );
	}
}

if ( ! function_exists( 'is_checkout' ) ) {
	function is_checkout(): bool {
		return ! empty( $GLOBALS['agend_test_is_checkout'] );
	}
}

if ( ! function_exists( 'is_account_page' ) ) {
	function is_account_page(): bool {
		return ! empty( $GLOBALS['agend_test_is_account_page'] );
	}
}
