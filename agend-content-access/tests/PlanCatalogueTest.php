<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Catalogue;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use WP_Error;

require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-catalogue.php';

/**
 * The editor's plan picker: reduced, cached, and outage-safe.
 *
 * The failure modes this guards are all quiet ones. A blocklist reduction leaks
 * each new upstream field until somebody notices. A refresh that clobbers on
 * failure empties every editor's picker during an Agend blip. An empty picker
 * reads to an editor as "no plans exist", which is a different and wrong
 * statement from "we could not reach Agend".
 */
#[CoversClass( Agend_Content_Access_Catalogue::class )]
final class PlanCatalogueTest extends TestCase {

	private const ACTIVE_TIER   = '11111111-1111-1111-1111-111111111111';
	private const INACTIVE_TIER = '22222222-2222-2222-2222-222222222222';
	private const DELETED_TIER  = '99999999-9999-9999-9999-999999999999';

	/** A realistic payload, including everything the editor must NOT receive. */
	private function tierPayload(): array {
		return array(
			'data' => array(
				array(
					'id'              => self::ACTIVE_TIER,
					'account_id'      => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
					'name'            => 'Full Member',
					'slug'            => 'full-member',
					'tier_type'       => 'individual',
					'is_active'       => true,
					'price_cents'     => 45000,
					'formatted_price' => '$450.00',
					'member_count'    => 317,
					'benefits'        => array( 'Journal', 'Events discount' ),
					'seat_brackets'   => array( array( 'min' => 1, 'max' => 5 ) ),
					'app_access'      => array( 'lms' => 'full' ),
				),
				array(
					'id'          => self::INACTIVE_TIER,
					'name'        => 'Retired',
					'slug'        => 'retired',
					'tier_type'   => 'individual',
					'is_active'   => false,
					'price_cents' => 5000,
				),
			),
		);
	}

	#[Test]
	public function a_plan_carries_exactly_the_five_editor_fields(): void {
		Agend_Test_WP::$tiers_response = $this->tierPayload();

		$plans = Agend_Content_Access_Catalogue::get()['plans'];

		$this->assertCount( 2, $plans );
		$this->assertSame( array( 'id', 'name', 'slug', 'type', 'active' ), array_keys( $plans[0] ) );
		$this->assertSame( 'individual', $plans[0]['type'] );
		$this->assertTrue( $plans[0]['active'] );
	}

	/**
	 * Reduction is an allow list. A blocklist would let each newly added
	 * upstream field through until somebody spotted it.
	 */
	#[Test]
	#[DataProvider( 'forbiddenFields' )]
	public function reduction_drops_everything_the_editor_must_not_see( string $needle ): void {
		Agend_Test_WP::$tiers_response = $this->tierPayload();

		$serialised = (string) json_encode( Agend_Content_Access_Catalogue::get()['plans'] );

		$this->assertStringNotContainsString( $needle, $serialised );
	}

	public static function forbiddenFields(): array {
		return array(
			'price'           => array( '45000' ),
			'formatted price' => array( 'formatted_price' ),
			'member count'    => array( 'member_count' ),
			'the count value' => array( '317' ),
			'benefits'        => array( 'benefits' ),
			'seat brackets'   => array( 'seat_brackets' ),
			'app access'      => array( 'app_access' ),
			'account id'      => array( 'account_id' ),
		);
	}

	#[Test]
	public function the_reduced_catalogue_is_cached_and_reused(): void {
		Agend_Test_WP::$tiers_response = $this->tierPayload();
		Agend_Content_Access_Catalogue::get();

		// Upstream now returns nothing; a cache hit should still serve two.
		Agend_Test_WP::$tiers_response = array( 'data' => array() );
		$cached                        = Agend_Content_Access_Catalogue::get();

		$this->assertCount( 2, $cached['plans'] );
		$this->assertFalse( $cached['stale'] );
	}

	#[Test]
	public function force_refresh_bypasses_the_cache(): void {
		Agend_Test_WP::$tiers_response = $this->tierPayload();
		Agend_Content_Access_Catalogue::get();

		Agend_Test_WP::$tiers_response = array( 'data' => array() );

		$this->assertCount( 0, Agend_Content_Access_Catalogue::get( true )['plans'] );
	}

	#[Test]
	public function a_failed_refresh_serves_the_last_known_catalogue(): void {
		Agend_Test_WP::$tiers_response = $this->tierPayload();
		Agend_Content_Access_Catalogue::get();

		Agend_Test_WP::$tiers_response = new WP_Error( 'http_request_failed', 'Connection refused' );
		$outage                        = Agend_Content_Access_Catalogue::get( true );

		$this->assertCount( 2, $outage['plans'], 'an outage must not empty the picker' );
		$this->assertTrue( $outage['stale'] );
		$this->assertSame( 'Connection refused', $outage['error'] );
		$this->assertTrue( Agend_Content_Access_Catalogue::can_select_plans( $outage ) );
	}

	#[Test]
	public function a_failed_refresh_leaves_the_cached_catalogue_intact(): void {
		Agend_Test_WP::$tiers_response = $this->tierPayload();
		Agend_Content_Access_Catalogue::get();

		Agend_Test_WP::$tiers_response = new WP_Error( 'http_request_failed', 'down' );
		Agend_Content_Access_Catalogue::get( true );

		$this->assertCount(
			2,
			Agend_Test_WP::$transients[ Agend_Content_Access_Catalogue::TRANSIENT ]
		);
	}

	/**
	 * With nothing cached and Agend unreachable we cannot tell whether a
	 * submitted tier id is real, so a NEW selected-plans policy is blocked.
	 * Public and all-active-members stay saveable, so editing is never blocked
	 * outright.
	 */
	#[Test]
	public function with_no_catalogue_at_all_new_plan_selection_is_blocked(): void {
		Agend_Test_WP::$tiers_response = new WP_Error( 'http_request_failed', 'down' );

		$empty = Agend_Content_Access_Catalogue::get( true );

		$this->assertSame( array(), $empty['plans'] );
		$this->assertFalse( Agend_Content_Access_Catalogue::can_select_plans( $empty ) );
	}

	#[Test]
	public function only_active_plans_are_offered_for_a_new_selection(): void {
		$plans      = Agend_Content_Access_Catalogue::reduce( $this->tierPayload() );
		$selectable = Agend_Content_Access_Catalogue::selectable( $plans );

		$this->assertCount( 1, $selectable );
		$this->assertSame( self::ACTIVE_TIER, $selectable[0]['id'] );
	}

	/**
	 * A stored tier that has gone away is kept and flagged, never dropped.
	 * Dropping it would quietly change who can read the content.
	 */
	#[Test]
	public function a_stored_selection_is_annotated_never_dropped(): void {
		$plans = Agend_Content_Access_Catalogue::reduce( $this->tierPayload() );

		$annotated = Agend_Content_Access_Catalogue::annotate_selection(
			array( self::ACTIVE_TIER, self::INACTIVE_TIER, self::DELETED_TIER ),
			$plans
		);

		$this->assertCount( 3, $annotated );
		$this->assertTrue( $annotated[0]['available'] );
		$this->assertFalse( $annotated[1]['available'] );
		$this->assertSame( 'Retired', $annotated[1]['name'], 'a deactivated plan keeps its name for repair' );
		$this->assertFalse( $annotated[2]['available'] );
		$this->assertSame( self::DELETED_TIER, $annotated[2]['id'], 'a deleted plan keeps its id for repair' );
	}

	#[Test]
	public function a_tier_with_no_active_flag_is_treated_as_inactive(): void {
		$plans = Agend_Content_Access_Catalogue::reduce(
			array( 'data' => array( array( 'id' => self::ACTIVE_TIER, 'name' => 'Ambiguous' ) ) )
		);

		$this->assertFalse( $plans[0]['active'] );
		$this->assertSame( array(), Agend_Content_Access_Catalogue::selectable( $plans ) );
	}

	#[Test]
	public function malformed_rows_are_skipped(): void {
		$plans = Agend_Content_Access_Catalogue::reduce(
			array(
				'data' => array(
					array( 'name' => 'No id' ),
					'not-an-array',
					array( 'id' => '' ),
				),
			)
		);

		$this->assertSame( array(), $plans );
	}
}
