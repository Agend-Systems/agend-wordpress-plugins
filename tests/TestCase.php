<?php
/**
 * Shared base test case.
 *
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests;

use Agend_Test_WP;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Resets the WordPress stub state before every test.
 *
 * The stubs are process-global by necessity (PHP cannot redeclare a function),
 * so without this reset a transient written by one test would be visible to the
 * next and tests would pass or fail depending on their order.
 */
abstract class TestCase extends PHPUnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		Agend_Test_WP::reset();
	}

	/**
	 * Content cache keys, excluding the API client's own rate-limit transients.
	 *
	 * Those are not per-identity and are never what a caching assertion is
	 * about, so filtering them here keeps the assertions readable.
	 *
	 * @return string[]
	 */
	protected function cachedContentKeys(): array {
		return array_values(
			array_filter(
				array_keys( Agend_Test_WP::$transients ),
				static fn( string $key ): bool => ! str_contains( $key, 'rate_limit' )
			)
		);
	}
}
