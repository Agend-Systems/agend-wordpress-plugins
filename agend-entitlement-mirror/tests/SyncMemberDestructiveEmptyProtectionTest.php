<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\EntitlementMirror;

use Agend_Entitlement_Sync;
use Agend_Entitlement_Mirror_Settings;
use Agend_Test_Mirror_Gateway;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-entitlement-mirror/includes/class-entitlement-mirror-settings.php';
require_once AGEND_TESTS_ROOT . '/agend-entitlement-mirror/includes/class-entitlement-collector.php';
require_once AGEND_TESTS_ROOT . '/agend-entitlement-mirror/includes/class-entitlement-sync.php';

/**
 * `Agend_Entitlement_Sync::sync_member()` must never let a reconcile call
 * reach the gateway with `entries: []` unless the collector positively
 * established that empty state.
 *
 * SPEC-CRM-20260805-member-entitlement-grants v1.2 US-5.1 AC6, AC12, AC13.
 * The reconcile endpoint's contract is destructive on an empty array: it
 * revokes every grant the source previously made for that member. AC13 is
 * the regression this file exists to pin down -- a collector failure must
 * short-circuit `sync_member()` before it ever reaches the reconcile call,
 * never fall through and report "the member holds nothing".
 */
#[CoversClass( Agend_Entitlement_Sync::class )]
final class SyncMemberDestructiveEmptyProtectionTest extends TestCase {

	private const MEMBER_ID = 'MEM-100';

	protected function setUp(): void {
		parent::setUp();
		Agend_Test_WP::$options['agend_entitlement_mirror_categories'] = 'Membership';
	}

	/**
	 * THE AC13 REGRESSION: a collector failure must produce ZERO gateway
	 * writes, not a reconcile call with an empty entries array.
	 *
	 * The collector stub seam (`agend_entitlement_mirror_raw_entitlements`)
	 * returning a non-array is the documented failure simulation -- it makes
	 * `Agend_Entitlement_Collector::collect()` throw exactly as a genuine
	 * kiosk API outage would. `entries: []` reaching the reconcile endpoint
	 * on that path would revoke everything the source ever granted this
	 * member, purely because the upstream read failed.
	 */
	#[Test]
	public function a_collector_failure_never_reaches_the_reconcile_or_types_endpoint(): void {
		Agend_Test_WP::set_filter( 'agend_entitlement_mirror_raw_entitlements', 'not-an-array' );

		$result = Agend_Entitlement_Sync::sync_member( self::MEMBER_ID );

		$this->assertFalse( $result, 'a collector failure must report the sync as failed' );
		$this->assertCount( 0, Agend_Test_Mirror_Gateway::$reconcile_calls, 'the reconcile endpoint must never be called after a collector failure' );
		$this->assertCount( 0, Agend_Test_Mirror_Gateway::$types_calls, 'the types endpoint must never be called after a collector failure' );
	}

	/**
	 * A kiosk outage (no stub configured, kiosk class absent) throws the same
	 * way -- the other route into the collector's failure path must be
	 * protected identically.
	 */
	#[Test]
	public function a_kiosk_unavailable_failure_also_never_reaches_the_reconcile_endpoint(): void {
		// No stub filter set: collect() falls through to the real kiosk read,
		// which throws because Iugo_Membership_Kiosk_API is not defined in
		// this suite.
		$result = Agend_Entitlement_Sync::sync_member( self::MEMBER_ID );

		$this->assertFalse( $result );
		$this->assertCount( 0, Agend_Test_Mirror_Gateway::$reconcile_calls );
		$this->assertCount( 0, Agend_Test_Mirror_Gateway::$types_calls );
	}

	/**
	 * AC12's positive half: a genuinely empty collector result for a member
	 * with no existing Agend contact is a deliberate no-op, not a call.
	 */
	#[Test]
	public function a_valid_empty_result_for_an_unknown_member_skips_the_reconcile_call(): void {
		Agend_Test_WP::set_filter( 'agend_entitlement_mirror_raw_entitlements', array() );
		Agend_Test_Mirror_Gateway::$get_contacts_response = array( 'data' => array() );

		$result = Agend_Entitlement_Sync::sync_member( self::MEMBER_ID );

		$this->assertTrue( $result, 'a deliberate skip is a successful sync' );
		$this->assertCount( 0, Agend_Test_Mirror_Gateway::$reconcile_calls );
	}

	/**
	 * AC12's other half: a genuinely empty collector result for a member who
	 * DOES have an existing Agend contact must reach the reconcile endpoint
	 * with `entries: []` -- that is how an upstream revocation is meant to
	 * propagate. The destructive-empty-array contract is only forbidden when
	 * the emptiness has NOT been positively established.
	 */
	#[Test]
	public function a_valid_empty_result_for_a_known_contact_reconciles_with_an_empty_entries_array(): void {
		Agend_Test_WP::set_filter( 'agend_entitlement_mirror_raw_entitlements', array() );
		Agend_Test_Mirror_Gateway::$get_contacts_response = array(
			'data' => array( array( 'id' => 'contact-existing' ) ),
		);

		$result = Agend_Entitlement_Sync::sync_member( self::MEMBER_ID );

		$this->assertTrue( $result );
		$this->assertCount( 1, Agend_Test_Mirror_Gateway::$reconcile_calls );
		$this->assertSame( array(), Agend_Test_Mirror_Gateway::$reconcile_calls[0]['entries'] );
		$this->assertSame( self::MEMBER_ID, Agend_Test_Mirror_Gateway::$reconcile_calls[0]['external_id'] );
	}

	/**
	 * A transport failure while checking for an existing contact must abort
	 * rather than fall through to "no contact exists" and skip -- a
	 * transient lookup blip must never be mistaken for a genuinely absent
	 * contact.
	 */
	#[Test]
	public function a_contact_lookup_failure_aborts_instead_of_skipping(): void {
		Agend_Test_WP::set_filter( 'agend_entitlement_mirror_raw_entitlements', array() );
		Agend_Test_Mirror_Gateway::$get_contacts_response = new \WP_Error( 'http_request_failed', 'down' );

		$result = Agend_Entitlement_Sync::sync_member( self::MEMBER_ID );

		$this->assertFalse( $result );
		$this->assertCount( 0, Agend_Test_Mirror_Gateway::$reconcile_calls );
	}
}
