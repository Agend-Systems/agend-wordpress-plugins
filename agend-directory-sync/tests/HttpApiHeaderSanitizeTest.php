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

require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/interface-source.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-http-api-source.php';

/**
 * Custom request headers sanitisation (SPEC-DIR-20260731 headers feature),
 * exercised through the public `sanitize_settings()` entry point since
 * `sanitize_headers()` itself is private, following the same pattern as the
 * existing `sanitize_variables()` coverage.
 */
#[CoversClass( Agend_Directory_Sync_Http_Api_Source::class )]
final class HttpApiHeaderSanitizeTest extends TestCase {

	private function headersFromTextarea( string $textarea ): array {
		$settings = Agend_Directory_Sync_Http_Api_Source::sanitize_settings(
			array( 'headers' => $textarea )
		);
		return $settings['headers'];
	}

	#[Test]
	public function it_parses_a_valid_header_line(): void {
		$headers = $this->headersFromTextarea( "X-Custom: some-value\n" );

		$this->assertSame( array( 'X-Custom' => 'some-value' ), $headers );
	}

	#[Test]
	public function it_drops_a_line_without_a_colon(): void {
		$headers = $this->headersFromTextarea( "X-Custom some-value\n" );

		$this->assertSame( array(), $headers );
	}

	#[Test]
	public function it_drops_a_line_with_an_invalid_header_name(): void {
		$headers = $this->headersFromTextarea( "X Custom Name: some-value\n" );

		$this->assertSame( array(), $headers );
	}

	#[Test]
	public function it_strips_crlf_and_control_characters_from_the_value(): void {
		// A textarea line already splits on line breaks before reaching
		// sanitize_headers(), so the injection guard is exercised against
		// the array form instead -- the shape a value would take if it
		// arrived with an embedded CR/LF some other way (e.g. a direct
		// options-table edit or a future non-textarea caller).
		$settings = Agend_Directory_Sync_Http_Api_Source::sanitize_settings(
			array( 'headers' => array( 'X-Custom' => "some\r\nvalue\x00here" ) )
		);

		$this->assertSame( array( 'X-Custom' => 'somevaluehere' ), $settings['headers'] );
	}

	#[Test]
	public function it_preserves_the_odata_prefer_example_verbatim(): void {
		$headers = $this->headersFromTextarea( 'Prefer: odata.include-annotations="*"' );

		$this->assertSame(
			array( 'Prefer' => 'odata.include-annotations="*"' ),
			$headers
		);
	}

	#[Test]
	public function it_round_trips_through_headers_to_textarea(): void {
		$headers  = array(
			'Prefer'   => 'odata.include-annotations="*"',
			'X-Custom' => 'some-value',
		);
		$textarea = Agend_Directory_Sync_Http_Api_Source::headers_to_textarea( $headers );

		$roundTripped = $this->headersFromTextarea( $textarea );

		$this->assertSame( $headers, $roundTripped );
	}

	#[Test]
	public function it_accepts_an_already_saved_array_form(): void {
		$settings = Agend_Directory_Sync_Http_Api_Source::sanitize_settings(
			array( 'headers' => array( 'X-Custom' => 'some-value' ) )
		);

		$this->assertSame( array( 'X-Custom' => 'some-value' ), $settings['headers'] );
	}
}
