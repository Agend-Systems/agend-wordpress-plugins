<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Catalogue;
use Agend_Content_Access_Meta_Box;
use Agend_Content_Access_Policy;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use WP_Error;

require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-policy.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-catalogue.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-meta-box.php';

/**
 * What the editor panel renders from.
 *
 * `payload()` is the single point where data crosses into the editor page, so
 * "no secret reaches the browser" is asserted here rather than left to review
 * of the template.
 */
#[CoversClass( Agend_Content_Access_Meta_Box::class )]
final class MetaBoxPayloadTest extends TestCase {

	private const TIER_A  = '11111111-1111-1111-1111-111111111111';
	private const GONE    = '99999999-9999-9999-9999-999999999999';

	private function catalogue(): array {
		Agend_Test_WP::$tiers_response = array(
			'data' => array(
				array(
					'id'              => self::TIER_A,
					'name'            => 'Full Member',
					'slug'            => 'full-member',
					'tier_type'       => 'individual',
					'is_active'       => true,
					'price_cents'     => 45000,
					'formatted_price' => '$450.00',
					'member_count'    => 317,
					'app_access'      => array( 'lms' => 'full' ),
				),
			),
		);

		return Agend_Content_Access_Catalogue::get( true );
	}

	#[Test]
	public function no_policy_yields_an_empty_mode_rather_than_defaulting(): void {
		$payload = Agend_Content_Access_Meta_Box::payload( null, $this->catalogue() );

		// Not 'public'. An unset policy and an explicitly public one are
		// different states (Decision 2.9) and the panel must not conflate them.
		$this->assertSame( '', $payload['mode'] );
		$this->assertSame( array(), $payload['selected'] );
	}

	#[Test]
	public function a_stored_mode_is_reflected(): void {
		$payload = Agend_Content_Access_Meta_Box::payload(
			array( 'mode' => 'active_member' ),
			$this->catalogue()
		);

		$this->assertSame( 'active_member', $payload['mode'] );
	}

	/**
	 * The guarantee behind US-3.2 criterion 8. Everything crossing into the page
	 * comes from the reduced catalogue, so pricing and internals cannot appear
	 * even if the template were careless.
	 */
	#[Test]
	public function the_payload_carries_no_pricing_or_internals(): void {
		$payload = Agend_Content_Access_Meta_Box::payload(
			array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A ) ),
			$this->catalogue()
		);

		$serialised = (string) json_encode( $payload );

		foreach ( array( '45000', 'formatted_price', 'member_count', 'app_access', 'test-api-key', 'api.example.test' ) as $forbidden ) {
			$this->assertStringNotContainsString( $forbidden, $serialised );
		}
	}

	#[Test]
	public function an_unavailable_selection_is_surfaced_for_repair(): void {
		$payload = Agend_Content_Access_Meta_Box::payload(
			array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A, self::GONE ) ),
			$this->catalogue()
		);

		$this->assertCount( 2, $payload['selected'], 'a vanished plan is kept, not dropped' );
		$this->assertSame( 1, $payload['unavailable_count'] );
	}

	#[Test]
	public function an_all_available_selection_reports_no_repairs_needed(): void {
		$payload = Agend_Content_Access_Meta_Box::payload(
			array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A ) ),
			$this->catalogue()
		);

		$this->assertSame( 0, $payload['unavailable_count'] );
	}

	#[Test]
	public function a_catalogue_outage_disables_plan_selection_but_keeps_the_stored_policy(): void {
		Agend_Test_WP::$tiers_response = new WP_Error( 'http_request_failed', 'down' );
		$catalogue                     = Agend_Content_Access_Catalogue::get( true );

		$payload = Agend_Content_Access_Meta_Box::payload(
			array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A ) ),
			$catalogue
		);

		$this->assertFalse( $payload['can_select'] );
		$this->assertTrue( $payload['stale'] );
		// The stored selection survives the outage and is still shown.
		$this->assertCount( 1, $payload['selected'] );
		$this->assertSame( 'selected_tiers', $payload['mode'] );
	}

	#[Test]
	public function only_active_plans_are_offered_for_selection(): void {
		$payload = Agend_Content_Access_Meta_Box::payload( null, $this->catalogue() );

		$this->assertCount( 1, $payload['plans'] );
		$this->assertSame( self::TIER_A, $payload['plans'][0]['id'] );
	}

	#[Test]
	public function the_meta_key_is_protected_by_its_underscore_prefix(): void {
		// WordPress treats an underscore-prefixed key as protected, which keeps
		// it out of the custom-fields UI where it could be hand-edited into a
		// shape the gateway rejects.
		$this->assertStringStartsWith( '_', Agend_Content_Access_Policy::META_KEY );
	}
}
