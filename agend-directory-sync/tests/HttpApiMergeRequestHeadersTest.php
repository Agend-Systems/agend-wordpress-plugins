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
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-config.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-http-api-source.php';

/**
 * `merge_request_headers()` precedence (SPEC-DIR-20260731 headers feature):
 * the computed auth-mode headers always win over a same-named custom header,
 * so an operator cannot accidentally (or otherwise) override the
 * Authorization/token header the configured auth mode sets.
 */
#[CoversClass( Agend_Directory_Sync_Http_Api_Source::class )]
final class HttpApiMergeRequestHeadersTest extends TestCase {

	#[Test]
	public function auth_mode_authorization_wins_over_a_custom_authorization_header(): void {
		$merged = Agend_Directory_Sync_Http_Api_Source::merge_request_headers(
			array( 'Authorization' => 'Bearer custom-should-lose' ),
			array( 'Authorization' => 'Bearer auth-mode-should-win' )
		);

		$this->assertSame( 'Bearer auth-mode-should-win', $merged['Authorization'] );
	}

	#[Test]
	public function unrelated_custom_headers_pass_through_unchanged(): void {
		$merged = Agend_Directory_Sync_Http_Api_Source::merge_request_headers(
			array( 'Prefer' => 'odata.include-annotations="*"' ),
			array( 'Authorization' => 'Bearer auth-mode-token' )
		);

		$this->assertSame(
			array(
				'Prefer'        => 'odata.include-annotations="*"',
				'Authorization' => 'Bearer auth-mode-token',
			),
			$merged
		);
	}

	#[Test]
	public function no_auth_headers_leaves_custom_headers_untouched(): void {
		$merged = Agend_Directory_Sync_Http_Api_Source::merge_request_headers(
			array( 'Prefer' => 'odata.include-annotations="*"' ),
			array()
		);

		$this->assertSame( array( 'Prefer' => 'odata.include-annotations="*"' ), $merged );
	}
}
