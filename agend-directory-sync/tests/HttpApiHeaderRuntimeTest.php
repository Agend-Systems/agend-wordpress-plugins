<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\DirectorySync;

use Agend_Directory_Sync_Http_Api_Source;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/interface-source.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-config.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-http-api-source.php';

/**
 * Connection-variable substitution into a custom header value, and the loud
 * failure on an unresolved `{placeholder}`, mirroring the existing
 * URL/token-URL/scope behaviour (SPEC-DIR-20260731 US-2.5, Decision 2.9;
 * extended to headers for OData/Dynamics support). Exercised through the
 * public `substitute_variables()` / `assert_no_unresolved_placeholders()`
 * building blocks `runtime_settings()` composes internally, since
 * `runtime_settings()` itself is private and instance-only.
 */
#[CoversClass( Agend_Directory_Sync_Http_Api_Source::class )]
final class HttpApiHeaderRuntimeTest extends TestCase {

	#[Test]
	public function it_substitutes_a_variable_into_a_header_value(): void {
		$value = Agend_Directory_Sync_Http_Api_Source::substitute_variables(
			'Bearer {api_key}',
			array( 'api_key' => 'secret-token' )
		);

		$this->assertSame( 'Bearer secret-token', $value );
	}

	#[Test]
	public function it_fails_loudly_naming_the_header_when_a_placeholder_is_unresolved(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Header "Prefer"' );

		Agend_Directory_Sync_Http_Api_Source::assert_no_unresolved_placeholders(
			'odata.include-annotations="{mode}"',
			'Header "Prefer"'
		);
	}

	#[Test]
	public function it_does_not_throw_once_the_header_value_is_fully_resolved(): void {
		$resolved = Agend_Directory_Sync_Http_Api_Source::substitute_variables(
			'odata.include-annotations="{mode}"',
			array( 'mode' => '*' )
		);

		// Assert no exception is thrown for the fully-resolved value.
		Agend_Directory_Sync_Http_Api_Source::assert_no_unresolved_placeholders( $resolved, 'Header "Prefer"' );
		$this->assertSame( 'odata.include-annotations="*"', $resolved );
	}
}
