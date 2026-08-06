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

require_once AGEND_TESTS_ROOT . '/agend-entitlement-mirror/includes/class-entitlement-mirror-settings.php';
require_once AGEND_TESTS_ROOT . '/agend-entitlement-mirror/includes/class-entitlement-collector.php';
require_once AGEND_TESTS_ROOT . '/agend-entitlement-mirror/includes/class-entitlement-sync.php';

/**
 * Entitlement-type declaration: discovering new `gate_key`s from a member
 * sync, and the >200-entry chunking `sync_types()` does directly.
 *
 * SPEC-CRM-20260805-member-entitlement-grants v1.2 US-5.1 AC10 (the gateway
 * caps a types request at 200 entries; a larger catalogue must chunk rather
 * than fail the whole sync).
 */
#[CoversClass( Agend_Entitlement_Sync::class )]
final class EntitlementTypesSyncTest extends TestCase {

	private const MEMBER_ID = 'MEM-300';

	protected function setUp(): void {
		parent::setUp();
		Agend_Test_WP::$options['agend_entitlement_mirror_categories'] = 'Membership';
		Agend_Test_WP::$options['agend_entitlement_mirror_source_key'] = 'upbeat';
	}

	#[Test]
	public function reconciling_an_unknown_gate_key_declares_it_via_the_types_endpoint(): void {
		Agend_Test_WP::$options[ Agend_Entitlement_Sync::KNOWN_TYPES_OPTION ] = array( 'membership.silver_tier' );

		$entries = array(
			array(
				'gate_key'           => 'membership.gold_tier',
				'name'               => 'Gold Tier',
				'starts_at'          => null,
				'expires_at'         => null,
				'quantity_allowed'   => null,
				'quantity_remaining' => null,
			),
		);

		Agend_Entitlement_Sync::reconcile_member( self::MEMBER_ID, $entries, array( 'email' => '', 'first_name' => '', 'last_name' => '' ) );

		$this->assertCount( 1, Agend_Test_Mirror_Gateway::$types_calls );
		$this->assertSame(
			array(
				'source_key' => 'upbeat',
				'entries'    => array(
					array( 'gate_key' => 'membership.gold_tier', 'name' => 'Gold Tier' ),
				),
			),
			Agend_Test_Mirror_Gateway::$types_calls[0]
		);
	}

	#[Test]
	public function reconciling_only_already_known_gate_keys_never_calls_the_types_endpoint(): void {
		Agend_Test_WP::$options[ Agend_Entitlement_Sync::KNOWN_TYPES_OPTION ] = array( 'membership.gold_tier' );

		$entries = array(
			array(
				'gate_key'           => 'membership.gold_tier',
				'name'               => 'Gold Tier',
				'starts_at'          => null,
				'expires_at'         => null,
				'quantity_allowed'   => null,
				'quantity_remaining' => null,
			),
		);

		Agend_Entitlement_Sync::reconcile_member( self::MEMBER_ID, $entries, array( 'email' => '', 'first_name' => '', 'last_name' => '' ) );

		$this->assertCount( 0, Agend_Test_Mirror_Gateway::$types_calls );
	}

	/**
	 * @return array<int, array{gate_key: string, name: string}>
	 */
	private function catalogueOf( int $count ): array {
		$entries = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$entries[] = array(
				'gate_key' => sprintf( 'membership.tier_%03d', $i ),
				'name'     => sprintf( 'Tier %d', $i ),
			);
		}

		return $entries;
	}

	#[Test]
	public function a_catalogue_over_200_entries_is_chunked_into_calls_of_at_most_200(): void {
		Agend_Entitlement_Sync::sync_types( $this->catalogueOf( 250 ) );

		$this->assertCount( 2, Agend_Test_Mirror_Gateway::$types_calls, 'a 250-entry catalogue must chunk into exactly 2 calls' );
		$this->assertLessThanOrEqual( 200, count( Agend_Test_Mirror_Gateway::$types_calls[0]['entries'] ) );
		$this->assertLessThanOrEqual( 200, count( Agend_Test_Mirror_Gateway::$types_calls[1]['entries'] ) );
		$this->assertSame(
			250,
			count( Agend_Test_Mirror_Gateway::$types_calls[0]['entries'] ) + count( Agend_Test_Mirror_Gateway::$types_calls[1]['entries'] )
		);
	}

	#[Test]
	public function no_gate_key_is_duplicated_within_or_across_chunks(): void {
		Agend_Entitlement_Sync::sync_types( $this->catalogueOf( 250 ) );

		$all_keys = array();

		foreach ( Agend_Test_Mirror_Gateway::$types_calls as $call ) {
			foreach ( $call['entries'] as $entry ) {
				$all_keys[] = $entry['gate_key'];
			}
		}

		$this->assertCount( 250, $all_keys );
		$this->assertCount( 250, array_unique( $all_keys ), 'every gate_key must appear at most once across all chunks' );
	}

	#[Test]
	public function every_chunked_call_carries_the_configured_source_key(): void {
		Agend_Entitlement_Sync::sync_types( $this->catalogueOf( 250 ) );

		foreach ( Agend_Test_Mirror_Gateway::$types_calls as $call ) {
			$this->assertSame( 'upbeat', $call['source_key'] );
		}
	}
}
