<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\EntitlementMirror;

use Agend_Entitlement_Collector;
use Agend_Entitlement_Sync;
use Agend_Test_Mirror_Gateway;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-entitlement-mirror/includes/class-entitlement-mirror-settings.php';
require_once AGEND_TESTS_ROOT . '/agend-entitlement-mirror/includes/class-entitlement-collector.php';
require_once AGEND_TESTS_ROOT . '/agend-entitlement-mirror/includes/class-entitlement-sync.php';

/**
 * `Agend_Entitlement_Sync::reconcile_member()`'s payload to
 * `agend_apps_crm_reconcile_entitlement_grants()` must exactly match the
 * gateway's grants-endpoint contract.
 *
 * SPEC-CRM-20260805-member-entitlement-grants v1.2 US-5.1 AC6/AC11. Exercised
 * via `reconcile_member()` directly (not `sync_member()`) so the collector's
 * kiosk dependency and the coalesce lock stay out of scope for a test that is
 * purely about payload shape.
 */
#[CoversClass( Agend_Entitlement_Sync::class )]
final class ReconcileMemberPayloadShapeTest extends TestCase {

	private const MEMBER_ID = 'MEM-200';

	protected function setUp(): void {
		parent::setUp();
		Agend_Test_WP::$options['agend_entitlement_mirror_categories']    = 'Membership';
		Agend_Test_WP::$options['agend_entitlement_mirror_source_key']    = 'upbeat';
		Agend_Test_WP::$options['agend_entitlement_mirror_external_source'] = 'wordpress';
	}

	/**
	 * Builds two collector entries: one carrying every optional field, one
	 * carrying none, so the omission behaviour is exercised on the same
	 * payload rather than needing a separate test per field. Built as plain
	 * arrays in the Agend_Entitlement_Mirror_Source::fetch_member_entitlements()
	 * shape, since to_mirror_entry() is source-neutral.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function twoEntries(): array {
		$allowed = array( 'Membership' );

		$full = array(
			'category'           => 'Membership',
			'type'               => 'Gold Tier',
			'name'               => 'Gold Tier Access',
			'starts_at'          => new \DateTime( '2026-01-01 00:00:00', new \DateTimeZone( 'Australia/Sydney' ) ),
			'expires_at'         => new \DateTime( '2026-12-31 13:00:00', new \DateTimeZone( 'UTC' ) ),
			'quantity_allowed'   => '10',
			'quantity_remaining' => '7',
		);

		$sparse = array(
			'category'           => 'Membership',
			'type'               => 'Bronze Tier',
			'name'               => '',
			'starts_at'          => null,
			'expires_at'         => null,
			'quantity_allowed'   => null,
			'quantity_remaining' => null,
		);

		$rows = array(
			Agend_Entitlement_Collector::to_mirror_entry( $full, $allowed ),
			Agend_Entitlement_Collector::to_mirror_entry( $sparse, $allowed ),
		);

		return Agend_Entitlement_Collector::deduplicate_and_sort( $rows );
	}

	#[Test]
	public function the_payload_carries_external_identity_and_no_contact_id(): void {
		Agend_Entitlement_Sync::reconcile_member(
			self::MEMBER_ID,
			$this->twoEntries(),
			array( 'email' => '', 'first_name' => '', 'last_name' => '' )
		);

		$payload = Agend_Test_Mirror_Gateway::$reconcile_calls[0];

		$this->assertSame( 'wordpress', $payload['external_source'] );
		$this->assertSame( self::MEMBER_ID, $payload['external_id'] );
		$this->assertSame( 'upbeat', $payload['source_key'] );
		$this->assertArrayNotHasKey( 'contact_id', $payload );
	}

	#[Test]
	public function a_full_entry_carries_utc_rfc3339_dates_and_integer_quantities(): void {
		Agend_Entitlement_Sync::reconcile_member(
			self::MEMBER_ID,
			$this->twoEntries(),
			array( 'email' => '', 'first_name' => '', 'last_name' => '' )
		);

		$entries = Agend_Test_Mirror_Gateway::$reconcile_calls[0]['entries'];
		$gold    = current( array_filter( $entries, static fn( array $e ) => 'membership.gold_tier' === $e['gate_key'] ) );

		$this->assertNotFalse( $gold, 'the gold-tier entry must survive into the payload' );
		// Sydney is UTC+11 in January (AEDT); 00:00 local -> 13:00 the previous day in UTC.
		$this->assertSame( '2025-12-31T13:00:00Z', $gold['starts_at'] );
		$this->assertSame( '2026-12-31T13:00:00Z', $gold['expires_at'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $gold['starts_at'] );
		$this->assertSame( 10, $gold['quantity_allowed'] );
		$this->assertSame( 7, $gold['quantity_remaining'] );
		$this->assertIsInt( $gold['quantity_allowed'] );
		$this->assertIsInt( $gold['quantity_remaining'] );
	}

	#[Test]
	public function an_entry_with_no_dates_or_quantities_omits_those_keys_entirely(): void {
		Agend_Entitlement_Sync::reconcile_member(
			self::MEMBER_ID,
			$this->twoEntries(),
			array( 'email' => '', 'first_name' => '', 'last_name' => '' )
		);

		$entries = Agend_Test_Mirror_Gateway::$reconcile_calls[0]['entries'];
		$bronze  = current( array_filter( $entries, static fn( array $e ) => 'membership.bronze_tier' === $e['gate_key'] ) );

		$this->assertNotFalse( $bronze );
		$this->assertSame( array( 'gate_key' => 'membership.bronze_tier' ), $bronze );
	}

	#[Test]
	public function a_non_empty_profile_adds_its_fields_to_the_payload(): void {
		Agend_Entitlement_Sync::reconcile_member(
			self::MEMBER_ID,
			$this->twoEntries(),
			array(
				'email'      => 'jane@example.test',
				'first_name' => 'Jane',
				'last_name'  => 'Doe',
			)
		);

		$payload = Agend_Test_Mirror_Gateway::$reconcile_calls[0];

		$this->assertSame( 'jane@example.test', $payload['email'] );
		$this->assertSame( 'Jane', $payload['first_name'] );
		$this->assertSame( 'Doe', $payload['last_name'] );
	}

	#[Test]
	public function an_empty_profile_omits_first_name_and_email_keys_entirely(): void {
		Agend_Entitlement_Sync::reconcile_member(
			self::MEMBER_ID,
			$this->twoEntries(),
			array( 'email' => '', 'first_name' => '', 'last_name' => '' )
		);

		$payload = Agend_Test_Mirror_Gateway::$reconcile_calls[0];

		$this->assertArrayNotHasKey( 'email', $payload );
		$this->assertArrayNotHasKey( 'first_name', $payload );
		$this->assertArrayNotHasKey( 'last_name', $payload );
	}
}
