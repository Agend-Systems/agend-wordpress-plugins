<?php
/**
 * Test fixture: WordPress's `wp_hash()`, which the unit suite's WordPress
 * stubs do not provide. Declared in the global namespace (a function declared
 * in a namespaced test file would not be the one the plugin code calls), and
 * guarded so another test file that needs the same stub can declare it first.
 *
 * @package Agend\Tests
 */

declare( strict_types=1 );

if ( ! function_exists( 'wp_hash' ) ) {
	/**
	 * A salted hash, as WordPress's own: never the input itself.
	 *
	 * @param string $data   Value to hash.
	 * @param string $scheme Salt scheme.
	 * @param string $algo   Hash algorithm.
	 * @return string
	 */
	function wp_hash( $data, string $scheme = 'auth', string $algo = 'md5' ): string {
		return hash_hmac( $algo, (string) $data, 'agend-test-salt-' . $scheme );
	}
}
