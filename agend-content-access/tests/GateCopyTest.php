<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Decision;
use Agend_Content_Access_Frontend;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use WP_Error;

require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-policy.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-catalogue.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-meta-box.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-decision.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-frontend.php';

/**
 * What a denied visitor is told.
 *
 * The gate has to be useful without being a leak. Naming the qualifying plans
 * was reconsidered after finding that Agend Apps Core already serves the tier
 * catalogue unauthenticated at /agend-apps/v1/crm/tiers, so withholding names
 * here protected nothing and cost the member the one fact they need to act.
 */
#[CoversClass( Agend_Content_Access_Frontend::class )]
final class GateCopyTest extends TestCase {

	private const TIER_A = '11111111-1111-1111-1111-111111111111';
	private const TIER_B = '22222222-2222-2222-2222-222222222222';
	private const GONE   = '99999999-9999-9999-9999-999999999999';

	private function seedCatalogue(): void {
		Agend_Test_WP::$tiers_response = array(
			'data' => array(
				array( 'id' => self::TIER_A, 'name' => 'Full Member', 'slug' => 'full', 'tier_type' => 'individual', 'is_active' => true ),
				array( 'id' => self::TIER_B, 'name' => 'Corporate', 'slug' => 'corp', 'tier_type' => 'corporate', 'is_active' => true ),
			),
		);
	}

	private function tiersPolicy( string ...$ids ): array {
		return array( 'mode' => 'selected_tiers', 'tier_ids' => $ids );
	}

	#[Test]
	public function the_plan_gate_names_the_qualifying_plans(): void {
		$this->seedCatalogue();

		$html = Agend_Content_Access_Frontend::render_gate(
			'',
			Agend_Content_Access_Decision::STATE_PLAN,
			$this->tiersPolicy( self::TIER_A )
		);

		$this->assertStringContainsString( 'Full Member', $html );
	}

	#[Test]
	public function several_qualifying_plans_are_listed(): void {
		$this->seedCatalogue();

		$names = Agend_Content_Access_Frontend::plan_names_for(
			$this->tiersPolicy( self::TIER_A, self::TIER_B )
		);

		$this->assertSame( array( 'Full Member', 'Corporate' ), $names );
	}

	/**
	 * A visitor cannot act on "Unknown plan", and rendering it advertises that
	 * the policy is broken.
	 */
	#[Test]
	public function a_tier_the_catalogue_cannot_name_is_skipped(): void {
		$this->seedCatalogue();

		$names = Agend_Content_Access_Frontend::plan_names_for(
			$this->tiersPolicy( self::TIER_A, self::GONE )
		);

		$this->assertSame( array( 'Full Member' ), $names );
	}

	#[Test]
	public function an_unreachable_catalogue_falls_back_to_generic_copy(): void {
		Agend_Test_WP::$tiers_response = new WP_Error( 'http_request_failed', 'down' );

		$html = Agend_Content_Access_Frontend::render_gate(
			'',
			Agend_Content_Access_Decision::STATE_PLAN,
			$this->tiersPolicy( self::TIER_A )
		);

		// Degrades to a usable sentence rather than an empty list or a fatal.
		$this->assertStringContainsString( 'another plan', $html );
		$this->assertStringNotContainsString( 'members on: .', $html );
	}

	#[Test]
	public function the_membership_gate_asks_the_visitor_to_join(): void {
		$html = Agend_Content_Access_Frontend::render_gate(
			'',
			Agend_Content_Access_Decision::STATE_MEMBERSHIP,
			null
		);

		$this->assertStringContainsString( 'active membership is required', $html );
	}

	#[Test]
	public function the_anonymous_gate_asks_the_visitor_to_sign_in(): void {
		$html = Agend_Content_Access_Frontend::render_gate(
			'',
			Agend_Content_Access_Decision::STATE_AUTH,
			null
		);

		$this->assertStringContainsString( 'sign in', strtolower( $html ) );
	}

	#[Test]
	public function the_teaser_is_escaped_and_included(): void {
		$html = Agend_Content_Access_Frontend::render_gate(
			'A teaser with <script>alert(1)</script>',
			Agend_Content_Access_Decision::STATE_AUTH,
			null
		);

		$this->assertStringContainsString( 'A teaser with', $html );
		$this->assertStringNotContainsString( '<script>', $html );
	}

	#[Test]
	public function no_tier_ids_leak_into_the_gate_markup(): void {
		$this->seedCatalogue();

		$html = Agend_Content_Access_Frontend::render_gate(
			'',
			Agend_Content_Access_Decision::STATE_PLAN,
			$this->tiersPolicy( self::TIER_A )
		);

		// Names are deliberately public; the opaque identifiers are not useful
		// to a visitor and have no business in the page.
		$this->assertStringNotContainsString( self::TIER_A, $html );
	}

	#[Test]
	public function a_non_tier_policy_names_nothing(): void {
		$this->assertSame(
			array(),
			Agend_Content_Access_Frontend::plan_names_for( array( 'mode' => 'active_member' ) )
		);
		$this->assertSame( array(), Agend_Content_Access_Frontend::plan_names_for( null ) );
	}
}
