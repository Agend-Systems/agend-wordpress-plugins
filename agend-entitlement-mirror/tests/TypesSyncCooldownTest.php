<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\EntitlementMirror;

use Agend_Entitlement_Sync;
use Agend_Test_Mirror_Gateway;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use WP_Error;

require_once AGEND_TESTS_ROOT . '/agend-entitlement-mirror/includes/class-entitlement-mirror-settings.php';
require_once AGEND_TESTS_ROOT . '/agend-entitlement-mirror/includes/class-entitlement-collector.php';
require_once AGEND_TESTS_ROOT . '/agend-entitlement-mirror/includes/class-entitlement-sync.php';

/**
 * `maybe_sync_types_for_new_keys()`'s failure cooldown: one failed types push
 * must not repeat for every remaining member in a sweep.
 */
#[CoversClass( Agend_Entitlement_Sync::class )]
final class TypesSyncCooldownTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Agend_Test_WP::$options['agend_entitlement_mirror_categories'] = 'Membership';
		Agend_Test_WP::$options['agend_entitlement_mirror_source_key'] = 'upbeat';
		Agend_Test_Mirror_Gateway::$get_contacts_response = array(
			'data' => array( array( 'id' => 'contact-existing' ) ),
		);
	}

	/**
	 * @param string $gate_key Gate key for the single fixture entry.
	 * @return array<int, array{gate_key: string, name: string, starts_at: null, expires_at: null, quantity_allowed: null, quantity_remaining: null}>
	 */
	private function entriesFor( string $gate_key ): array {
		return array(
			array(
				'gate_key'           => $gate_key,
				'name'               => 'Some Tier',
				'starts_at'          => null,
				'expires_at'         => null,
				'quantity_allowed'   => null,
				'quantity_remaining' => null,
			),
		);
	}

	#[Test]
	public function a_failed_types_sync_sets_the_cooldown_transient(): void {
		Agend_Test_Mirror_Gateway::$types_response = new WP_Error( 'agend_apps_http_error', 'Internal Server Error' );

		Agend_Entitlement_Sync::reconcile_member( 'MEM-A', $this->entriesFor( 'membership.new_tier' ), array( 'email' => '', 'first_name' => '', 'last_name' => '' ) );

		$this->assertNotFalse( Agend_Test_WP::$transients[ Agend_Entitlement_Sync::TYPES_COOLDOWN_TRANSIENT ] ?? false );
	}

	#[Test]
	public function a_second_unknown_key_during_the_cooldown_does_not_retry_the_types_endpoint(): void {
		Agend_Test_Mirror_Gateway::$types_response = new WP_Error( 'agend_apps_http_error', 'Internal Server Error' );

		Agend_Entitlement_Sync::reconcile_member( 'MEM-A', $this->entriesFor( 'membership.new_tier' ), array( 'email' => '', 'first_name' => '', 'last_name' => '' ) );

		$this->assertCount( 1, Agend_Test_Mirror_Gateway::$types_calls, 'the first failed attempt is one call' );

		// A second, DIFFERENT member with a DIFFERENT unknown gate_key must
		// still be held off by the cooldown -- the cooldown is global, not
		// per-member or per-key.
		Agend_Entitlement_Sync::reconcile_member( 'MEM-B', $this->entriesFor( 'membership.another_new_tier' ), array( 'email' => '', 'first_name' => '', 'last_name' => '' ) );

		$this->assertCount( 1, Agend_Test_Mirror_Gateway::$types_calls, 'the cooldown must prevent a second types push for a different member/key' );
	}

	#[Test]
	public function the_reconcile_call_itself_still_proceeds_during_the_types_cooldown(): void {
		Agend_Test_Mirror_Gateway::$types_response = new WP_Error( 'agend_apps_http_error', 'Internal Server Error' );

		Agend_Entitlement_Sync::reconcile_member( 'MEM-A', $this->entriesFor( 'membership.new_tier' ), array( 'email' => '', 'first_name' => '', 'last_name' => '' ) );
		Agend_Entitlement_Sync::reconcile_member( 'MEM-B', $this->entriesFor( 'membership.another_new_tier' ), array( 'email' => '', 'first_name' => '', 'last_name' => '' ) );

		$this->assertCount( 2, Agend_Test_Mirror_Gateway::$reconcile_calls, 'a types-sync failure must not block the grants reconcile itself' );
	}

	#[Test]
	public function once_the_cooldown_transient_is_cleared_the_types_endpoint_is_retried(): void {
		Agend_Test_Mirror_Gateway::$types_response = new WP_Error( 'agend_apps_http_error', 'Internal Server Error' );

		Agend_Entitlement_Sync::reconcile_member( 'MEM-A', $this->entriesFor( 'membership.new_tier' ), array( 'email' => '', 'first_name' => '', 'last_name' => '' ) );

		delete_transient( Agend_Entitlement_Sync::TYPES_COOLDOWN_TRANSIENT );
		Agend_Test_Mirror_Gateway::$types_response = array( 'data' => array( 'types' => array() ) );

		Agend_Entitlement_Sync::reconcile_member( 'MEM-B', $this->entriesFor( 'membership.another_new_tier' ), array( 'email' => '', 'first_name' => '', 'last_name' => '' ) );

		$this->assertCount( 2, Agend_Test_Mirror_Gateway::$types_calls, 'once the cooldown expires the next unknown key must retry' );
	}

	#[Test]
	public function a_custom_cooldown_ttl_filter_is_honoured(): void {
		Agend_Test_Mirror_Gateway::$types_response = new WP_Error( 'agend_apps_http_error', 'Internal Server Error' );

		$captured_ttl = null;
		Agend_Test_WP::$filters['agend_entitlement_mirror_types_cooldown'] = static function ( $ttl ) use ( &$captured_ttl ) {
			$captured_ttl = $ttl;
			return 60;
		};

		Agend_Entitlement_Sync::reconcile_member( 'MEM-A', $this->entriesFor( 'membership.new_tier' ), array( 'email' => '', 'first_name' => '', 'last_name' => '' ) );

		$this->assertSame( Agend_Entitlement_Sync::TYPES_COOLDOWN_TTL, $captured_ttl );
	}
}
