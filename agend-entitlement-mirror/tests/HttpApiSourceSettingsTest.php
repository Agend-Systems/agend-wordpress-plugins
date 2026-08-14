<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\EntitlementMirror;

use Agend_Entitlement_Mirror_Http_Api_Source;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-entitlement-mirror/includes/interface-source.php';
require_once AGEND_TESTS_ROOT . '/agend-entitlement-mirror/includes/class-http-api-source.php';

/**
 * Agend_Entitlement_Mirror_Http_Api_Source's settings resolution and
 * sanitisation. Pure/no-I/O paths only -- the actual request path
 * (wp_remote_get) is out of scope for this unit suite (see phpunit.xml.dist),
 * mirroring agend-directory-sync's own HTTP API source test coverage.
 */
#[CoversClass( Agend_Entitlement_Mirror_Http_Api_Source::class )]
final class HttpApiSourceSettingsTest extends TestCase {

	#[Test]
	public function it_is_unavailable_with_no_base_url_configured(): void {
		$source = new Agend_Entitlement_Mirror_Http_Api_Source();

		$this->assertFalse( $source->is_available() );
		$this->assertNotSame( '', $source->get_unavailable_reason() );
	}

	#[Test]
	public function it_is_available_once_a_base_url_is_configured(): void {
		Agend_Test_WP::$options[ Agend_Entitlement_Mirror_Http_Api_Source::OPTION_SETTINGS ] = array(
			'base_url' => 'https://example.test/api',
		);

		$source = new Agend_Entitlement_Mirror_Http_Api_Source();

		$this->assertTrue( $source->is_available() );
	}

	#[Test]
	public function resolve_settings_falls_back_to_documented_defaults(): void {
		$settings = Agend_Entitlement_Mirror_Http_Api_Source::resolve_settings();

		$this->assertSame( '', $settings['base_url'] );
		$this->assertSame( 'members/{member_id}/entitlements', $settings['entitlements_path'] );
		$this->assertSame( 'members/{member_id}/profile', $settings['profile_path'] );
		$this->assertSame( 'entitlement-types', $settings['types_path'] );
		$this->assertSame( 'members', $settings['members_path'] );
		$this->assertSame( 100, $settings['page_size'] );
	}

	#[Test]
	public function sanitize_settings_blanks_a_non_https_url_outside_local_development(): void {
		$settings = Agend_Entitlement_Mirror_Http_Api_Source::sanitize_settings(
			array( 'base_url' => 'http://example.test/api' )
		);

		$this->assertSame( '', $settings['base_url'] );
	}

	#[Test]
	public function sanitize_settings_keeps_an_https_url(): void {
		$settings = Agend_Entitlement_Mirror_Http_Api_Source::sanitize_settings(
			array( 'base_url' => 'https://example.test/api' )
		);

		$this->assertSame( 'https://example.test/api', $settings['base_url'] );
	}

	#[Test]
	public function sanitize_settings_clamps_page_size_to_the_documented_bounds(): void {
		$settings = Agend_Entitlement_Mirror_Http_Api_Source::sanitize_settings(
			array( 'page_size' => 9999 )
		);

		$this->assertSame( Agend_Entitlement_Mirror_Http_Api_Source::MAX_PAGE_SIZE, $settings['page_size'] );
	}

	#[Test]
	public function sanitize_settings_falls_back_to_default_paths_when_blank(): void {
		$settings = Agend_Entitlement_Mirror_Http_Api_Source::sanitize_settings(
			array( 'entitlements_path' => '' )
		);

		$this->assertSame( 'members/{member_id}/entitlements', $settings['entitlements_path'] );
	}
}
