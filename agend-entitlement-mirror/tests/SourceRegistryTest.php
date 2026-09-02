<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\EntitlementMirror;

use Agend_Entitlement_Mirror_Source_Registry;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-entitlement-mirror/includes/interface-source.php';
require_once AGEND_TESTS_ROOT . '/agend-entitlement-mirror/includes/class-upbeat-source.php';
require_once AGEND_TESTS_ROOT . '/agend-entitlement-mirror/includes/class-http-api-source.php';
require_once AGEND_TESTS_ROOT . '/agend-entitlement-mirror/includes/class-source-registry.php';

/**
 * Agend_Entitlement_Mirror_Source_Registry resolves the configured
 * `agend_entitlement_mirror_data_source` option to a registered source,
 * falling back to `upbeat` on an unset or unknown value so an existing
 * install never changes source on upgrade.
 */
#[CoversClass( Agend_Entitlement_Mirror_Source_Registry::class )]
final class SourceRegistryTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Agend_Entitlement_Mirror_Source_Registry::reset();

		// The http_api source registers itself via the
		// `agend_entitlement_mirror_sources` filter from the plugin's own
		// bootstrap function (agend_entitlement_mirror_bootstrap()), which
		// this test does not load. Register it the same way here so
		// register_defaults() sees it, matching production. add_filter(),
		// not Agend_Test_WP::set_filter() -- the latter always returns a
		// fixed value regardless of input, which would discard the
		// registry's own `upbeat` registration rather than add to it.
		add_filter(
			'agend_entitlement_mirror_sources',
			static function ( array $sources ): array {
				$http_api                        = new \Agend_Entitlement_Mirror_Http_Api_Source();
				$sources[ $http_api->get_key() ] = $http_api;
				return $sources;
			}
		);
	}

	protected function tearDown(): void {
		Agend_Entitlement_Mirror_Source_Registry::reset();
		parent::tearDown();
	}

	#[Test]
	public function an_unset_option_resolves_to_upbeat(): void {
		$this->assertSame( 'upbeat', Agend_Entitlement_Mirror_Source_Registry::active()->get_key() );
	}

	#[Test]
	public function an_unknown_option_value_resolves_to_upbeat(): void {
		Agend_Test_WP::$options[ Agend_Entitlement_Mirror_Source_Registry::OPTION_DATA_SOURCE ] = 'not-a-real-source';

		$this->assertSame( 'upbeat', Agend_Entitlement_Mirror_Source_Registry::active()->get_key() );
	}

	#[Test]
	public function a_registered_option_value_resolves_to_that_source(): void {
		Agend_Test_WP::$options[ Agend_Entitlement_Mirror_Source_Registry::OPTION_DATA_SOURCE ] = 'http_api';

		$this->assertSame( 'http_api', Agend_Entitlement_Mirror_Source_Registry::active()->get_key() );
	}

	#[Test]
	public function both_built_in_sources_are_registered_by_default(): void {
		$sources = Agend_Entitlement_Mirror_Source_Registry::all();

		$this->assertArrayHasKey( 'upbeat', $sources );
		$this->assertArrayHasKey( 'http_api', $sources );
	}

	#[Test]
	public function a_filter_registered_source_is_selectable(): void {
		$fake = new class() implements \Agend_Entitlement_Mirror_Source {
			public function get_key(): string {
				return 'fake';
			}
			public function get_label(): string {
				return 'Fake';
			}
			public function is_available(): bool {
				return true;
			}
			public function get_unavailable_reason(): string {
				return '';
			}
			public function fetch_member_entitlements( string $member_id ): array {
				return array();
			}
			public function fetch_member_profile( string $member_id ): array {
				return array( 'email' => '', 'first_name' => '', 'last_name' => '' );
			}
			public function fetch_entitlement_types(): array {
				return array();
			}
			public function enumerate_members( int $max ): iterable {
				return array();
			}
		};

		Agend_Test_WP::set_filter(
			'agend_entitlement_mirror_sources',
			array( 'upbeat' => new \Agend_Entitlement_Mirror_Upbeat_Source(), 'fake' => $fake )
		);
		Agend_Test_WP::$options[ Agend_Entitlement_Mirror_Source_Registry::OPTION_DATA_SOURCE ] = 'fake';

		$this->assertSame( 'fake', Agend_Entitlement_Mirror_Source_Registry::active()->get_key() );
	}
}
