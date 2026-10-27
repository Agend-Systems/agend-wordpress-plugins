<?php
/**
 * Stand-in for the plugin bootstrap's Elementor probe.
 *
 * `agend_content_access_has_elementor()` is declared in the plugin's main file,
 * which the unit suite does not load. `Frontend::is_authoring_context()` calls
 * it, so a test that drives `Elementor::maybe_suppress()` needs it declared.
 * The body is the production body, so an Elementor that has not fired
 * `elementor/loaded` reads as absent here exactly as it does there.
 *
 * Not a test file: it has no `Test.php` suffix, so PHPUnit does not collect it.
 *
 * @package Agend\Tests
 */

declare( strict_types=1 );

if ( ! function_exists( 'agend_content_access_has_elementor' ) ) {
	function agend_content_access_has_elementor(): bool {
		return did_action( 'elementor/loaded' ) > 0;
	}
}
