<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * `success_url` is a `url` field, so it arrives in two shapes: Elementor's URL
 * control hands the renderer `array( 'url' => ... )`, while a block stores a
 * plain string. This surface's renderer read only the array shape, which was
 * correct while Elementor was the only editor and silently dropped the value
 * once a block could set it.
 */
final class MembershipsCatalogueUrlShapeTest extends TestCase {

	/**
	 * Deliberately does NOT load tests/render-doubles.php. Those doubles define
	 * the gateway wrappers, and PreviewRecordsTest asserts those functions are
	 * absent; a function definition leaks across every test sharing this
	 * process, so a test that pulls the doubles in without needing them breaks
	 * that one. Only the config builder is exercised here, which needs no
	 * gateway at all.
	 */
	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/palette.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/memberships-catalogue.php';
	}

	/**
	 * The config the front-end script receives, for one `success_url` value.
	 *
	 * @param mixed $success_url The setting value, in either shape.
	 * @return string The resolved successUrl.
	 */
	private function successUrlFor( $success_url ): string {
		$config = agend_apps_records_memberships_catalogue_build_config( array( 'success_url' => $success_url ) );

		return (string) $config['successUrl'];
	}

	#[Test]
	public function should_read_the_url_when_given_the_elementor_url_control_shape(): void {
		self::assertSame(
			'https://example.test/welcome/',
			$this->successUrlFor( array( 'url' => 'https://example.test/welcome/', 'is_external' => '', 'nofollow' => '' ) )
		);
	}

	#[Test]
	public function should_read_the_url_when_given_the_plain_string_a_block_stores(): void {
		self::assertSame(
			'https://example.test/welcome/',
			$this->successUrlFor( 'https://example.test/welcome/' )
		);
	}

	#[Test]
	public function should_resolve_to_an_empty_string_when_the_setting_is_absent(): void {
		$config = agend_apps_records_memberships_catalogue_build_config( array() );

		self::assertSame( '', (string) $config['successUrl'] );
	}

	#[Test]
	public function should_resolve_to_an_empty_string_when_the_url_control_holds_no_url(): void {
		self::assertSame( '', $this->successUrlFor( array( 'url' => '' ) ) );
	}
}
