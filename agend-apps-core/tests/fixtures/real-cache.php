<?php
/**
 * Loads the real `Agend_Apps_Cache` under another name.
 *
 * tests/doubles.php defines a double of `Agend_Apps_Cache` at bootstrap, so
 * the real class cannot be loaded under its own name. A suite asserting what
 * the real key map or `clear()` does loads it through here instead, once per
 * process, as `Agend_Test_Real_Cache`.
 *
 * @package Agend\Tests
 */

declare( strict_types=1 );

/**
 * Returns the source of a plugin file with exactly one class declaration
 * renamed, refusing a file where the declaration is missing or repeated.
 */
function agend_test_renamed_class_source( string $file, string $class, string $new_class ): string {
	$source      = (string) file_get_contents( $file );
	$declaration = 'class ' . $class . ' {';

	if ( 1 !== substr_count( $source, $declaration ) ) {
		throw new \RuntimeException( sprintf( 'Expected exactly one "%s" in %s.', $declaration, $file ) );
	}

	return str_replace( $declaration, 'class ' . $new_class . ' {', $source );
}

function agend_test_load_real_cache(): void {
	if ( class_exists( 'Agend_Test_Real_Cache', false ) ) {
		return;
	}

	$source = agend_test_renamed_class_source( AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-cache.php', 'Agend_Apps_Cache', 'Agend_Test_Real_Cache' );

	// phpcs:ignore Squiz.PHP.Eval.Discouraged
	eval( '?>' . $source );
}
