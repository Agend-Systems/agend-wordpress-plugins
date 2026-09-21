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
 * `Agend_Entitlement_Sync::reconcile_member()`'s reconcile fingerprint: a
 * member whose `[external_source, source_key, grant entries]` tuple has not
 * changed since the last successful reconcile is skipped before any gateway
 * call, so the nightly sweep and per-member sync stop burning a request per
 * member when nothing actually changed.
 */
#[CoversClass( Agend_Entitlement_Sync::class )]
final class ReconcileFingerprintSkipTest extends TestCase {

	private const MEMBER_ID = 'MEM-FP-1';

	protected function setUp(): void {
		parent::setUp();
		Agend_Test_WP::$options['agend_entitlement_mirror_categories'] = 'Membership';
		Agend_Test_WP::$options['agend_entitlement_mirror_source_key'] = 'upbeat';
		Agend_Test_Mirror_Gateway::$get_contacts_response = array(
			'data' => array( array( 'id' => 'contact-existing' ) ),
		);
	}

	/**
	 * @return array<int, array{gate_key: string, name: string, starts_at: string|null, expires_at: string|null, quantity_allowed: int|null, quantity_remaining: int|null}>
	 */
	private function entries(): array {
		return array(
			array(
				'gate_key'           => 'membership.gold_tier',
				'name'               => 'Gold Tier',
				'starts_at'          => null,
				'expires_at'         => null,
				'quantity_allowed'   => null,
				'quantity_remaining' => null,
			),
		);
	}

	/**
	 * @return array{email: string, first_name: string, last_name: string}
	 */
	private function profile(): array {
		return array(
			'email'      => 'member@example.test',
			'first_name' => 'Jane',
			'last_name'  => 'Doe',
		);
	}

	#[Test]
	public function a_first_call_reaches_the_gateway_and_stores_a_fingerprint(): void {
		$result = Agend_Entitlement_Sync::reconcile_member( self::MEMBER_ID, $this->entries(), $this->profile() );

		$this->assertNotTrue( $result, 'the first call must actually reach the gateway, not the true skip shortcut' );
		$this->assertCount( 1, Agend_Test_Mirror_Gateway::$reconcile_calls );
		$this->assertNotFalse(
			Agend_Test_WP::$transients[ 'agend_ent_mirror_fp_' . md5( self::MEMBER_ID ) ] ?? false,
			'a successful reconcile must store a fingerprint'
		);
	}

	#[Test]
	public function a_second_call_with_unchanged_entries_skips_the_gateway_entirely(): void {
		Agend_Entitlement_Sync::reconcile_member( self::MEMBER_ID, $this->entries(), $this->profile() );

		$result = Agend_Entitlement_Sync::reconcile_member( self::MEMBER_ID, $this->entries(), $this->profile() );

		$this->assertTrue( $result );
		$this->assertCount( 1, Agend_Test_Mirror_Gateway::$reconcile_calls, 'a second, unchanged call must not reach the gateway' );
		$this->assertCount( 0, Agend_Test_Mirror_Gateway::$get_contacts_calls, 'the fingerprint skip must pre-empt even the contact lookup' );
	}

	#[Test]
	public function a_changed_entries_set_reaches_the_gateway_again(): void {
		Agend_Entitlement_Sync::reconcile_member( self::MEMBER_ID, $this->entries(), $this->profile() );

		$changed = $this->entries();
		$changed[0]['gate_key'] = 'membership.platinum_tier';

		$result = Agend_Entitlement_Sync::reconcile_member( self::MEMBER_ID, $changed, $this->profile() );

		$this->assertNotTrue( $result );
		$this->assertCount( 2, Agend_Test_Mirror_Gateway::$reconcile_calls, 'a changed fingerprint must reach the gateway again' );
	}

	#[Test]
	public function force_bypasses_the_fingerprint_skip(): void {
		Agend_Entitlement_Sync::reconcile_member( self::MEMBER_ID, $this->entries(), $this->profile() );

		$result = Agend_Entitlement_Sync::reconcile_member( self::MEMBER_ID, $this->entries(), $this->profile(), true );

		$this->assertNotTrue( $result, '--force must bypass the skip even though the fingerprint is unchanged' );
		$this->assertCount( 2, Agend_Test_Mirror_Gateway::$reconcile_calls );
	}

	#[Test]
	public function a_wp_error_never_stores_a_fingerprint(): void {
		Agend_Test_Mirror_Gateway::$reconcile_response = new WP_Error(
			'agend_apps_http_error',
			'Internal Server Error',
			array( 'status_code' => 500 )
		);

		Agend_Entitlement_Sync::reconcile_member( self::MEMBER_ID, $this->entries(), $this->profile() );

		$this->assertFalse(
			Agend_Test_WP::$transients[ 'agend_ent_mirror_fp_' . md5( self::MEMBER_ID ) ] ?? false,
			'a WP_Error result must never store a fingerprint'
		);
	}

	#[Test]
	public function a_wp_error_means_the_very_next_call_still_reaches_the_gateway(): void {
		Agend_Test_Mirror_Gateway::$reconcile_response = new WP_Error(
			'agend_apps_http_error',
			'Internal Server Error',
			array( 'status_code' => 500 )
		);

		Agend_Entitlement_Sync::reconcile_member( self::MEMBER_ID, $this->entries(), $this->profile() );

		Agend_Test_Mirror_Gateway::$reconcile_response = array( 'data' => array() );

		$result = Agend_Entitlement_Sync::reconcile_member( self::MEMBER_ID, $this->entries(), $this->profile() );

		$this->assertNotTrue( $result );
		$this->assertCount( 2, Agend_Test_Mirror_Gateway::$reconcile_calls, 'no fingerprint was stored after the error, so the retry must reach the gateway' );
	}

	#[Test]
	public function the_deliberate_empty_skip_also_stores_a_fingerprint_and_is_skipped_next_time(): void {
		Agend_Test_Mirror_Gateway::$get_contacts_response = array( 'data' => array() );

		$result = Agend_Entitlement_Sync::reconcile_member( self::MEMBER_ID, array(), $this->profile() );

		$this->assertTrue( $result );
		$this->assertCount( 1, Agend_Test_Mirror_Gateway::$get_contacts_calls );

		$second = Agend_Entitlement_Sync::reconcile_member( self::MEMBER_ID, array(), $this->profile() );

		$this->assertTrue( $second );
		$this->assertCount( 1, Agend_Test_Mirror_Gateway::$get_contacts_calls, 'the second call must skip before the contact lookup' );
	}

	#[Test]
	public function a_custom_ttl_filter_is_honoured(): void {
		$captured_ttl = null;

		Agend_Test_WP::$filters['agend_entitlement_mirror_fingerprint_ttl'] = static function ( $ttl ) use ( &$captured_ttl ) {
			$captured_ttl = $ttl;
			return 3600;
		};

		Agend_Entitlement_Sync::reconcile_member( self::MEMBER_ID, $this->entries(), $this->profile() );

		$this->assertSame( Agend_Entitlement_Sync::FINGERPRINT_TTL, $captured_ttl, 'the filter must receive the default TTL' );
	}
}
