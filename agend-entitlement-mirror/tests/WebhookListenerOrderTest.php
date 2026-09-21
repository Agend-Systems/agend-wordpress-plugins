<?php
/**
 * The kiosk erases its cached entitlements for a member inside its own
 * `agend_webhook_entitlement_*` / `agend_webhook_contact_updated` handlers,
 * registered at the default priority. This plugin's listeners must run
 * AFTER those, or the collector reads the pre-change cache and mirrors the
 * stale state (found on PCA staging, 2026-09-21).
 *
 * @package Agend_Entitlement_Mirror
 */

declare(strict_types=1);

namespace Agend\Tests\EntitlementMirror;

use Agend_Entitlement_Mirror_Source_Registry;
use Agend_Entitlement_Sync;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass( Agend_Entitlement_Sync::class )]
final class WebhookListenerOrderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Agend_Test_WP::$options['agend_entitlement_mirror_enabled'] = '1';

		// The unit suite never defines the kiosk API class, so the default
		// Upbeat source reports unavailable. Register an available stand-in
		// so register() reaches its add_action() calls.
		$available = new class() implements \Agend_Entitlement_Mirror_Source {
			public function get_key(): string {
				return 'available';
			}
			public function get_label(): string {
				return 'Available';
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
				return array(
					'email'      => '',
					'first_name' => '',
					'last_name'  => '',
				);
			}
			public function fetch_entitlement_types(): array {
				return array();
			}
			public function enumerate_members( int $max ): iterable {
				return array();
			}
		};

		Agend_Entitlement_Mirror_Source_Registry::reset();
		Agend_Test_WP::set_filter( 'agend_entitlement_mirror_sources', array( 'available' => $available ) );
		Agend_Test_WP::$options[ Agend_Entitlement_Mirror_Source_Registry::OPTION_DATA_SOURCE ] = 'available';
	}

	protected function tearDown(): void {
		Agend_Entitlement_Mirror_Source_Registry::reset();
		parent::tearDown();
	}

	#[Test]
	public function webhook_listeners_register_after_the_kiosks_default_priority_handlers(): void {
		Agend_Entitlement_Sync::register();

		foreach ( array( 'agend_webhook_entitlement_created', 'agend_webhook_entitlement_updated', 'agend_webhook_contact_updated' ) as $hook ) {
			$this->assertArrayHasKey( $hook, Agend_Test_WP::$action_priorities, $hook . ' was not registered' );
			$this->assertSame( array( Agend_Entitlement_Sync::WEBHOOK_PRIORITY ), Agend_Test_WP::$action_priorities[ $hook ], $hook );
			$this->assertGreaterThan( 10, Agend_Entitlement_Sync::WEBHOOK_PRIORITY, 'must run after the kiosk cache erase at the default priority 10' );
		}
	}

	#[Test]
	public function nothing_registers_while_the_mirror_is_disabled(): void {
		Agend_Test_WP::$options['agend_entitlement_mirror_enabled'] = '0';

		Agend_Entitlement_Sync::register();

		$this->assertArrayNotHasKey( 'agend_webhook_entitlement_updated', Agend_Test_WP::$actions );
	}
}
