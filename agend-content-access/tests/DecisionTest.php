<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Decision;
use Agend_Content_Access_Policy;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use WP_Error;

require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-policy.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-decision.php';

/**
 * Who may read a policy-bearing document.
 *
 * Agend says what the visitor holds; this compares it against the policy. Every
 * uncertainty resolves to a denial, so the tests that matter most are the ones
 * asserting that a failure does NOT grant.
 */
#[CoversClass( Agend_Content_Access_Decision::class )]
final class DecisionTest extends TestCase {

	private const TIER_A = '11111111-1111-1111-1111-111111111111';
	private const TIER_B = '22222222-2222-2222-2222-222222222222';

	protected function setUp(): void {
		parent::setUp();
		Agend_Content_Access_Decision::reset_cache();
	}

	private function anonymous(): array {
		return array( 'identified' => false, 'tier_ids' => array(), 'degraded' => false );
	}

	private function member( string ...$tiers ): array {
		return array( 'identified' => true, 'tier_ids' => $tiers, 'degraded' => false );
	}

	private function identifiedNonMember(): array {
		return array( 'identified' => true, 'tier_ids' => array(), 'degraded' => false );
	}

	// -----------------------------------------------------------------------
	// Policy evaluation
	// -----------------------------------------------------------------------

	#[Test]
	public function no_policy_grants(): void {
		// Decision 2.9: a record with no policy predates the capability and
		// stays readable.
		$this->assertSame(
			Agend_Content_Access_Decision::STATE_GRANTED,
			Agend_Content_Access_Decision::evaluate( null, $this->anonymous() )
		);
	}

	#[Test]
	public function public_grants_everyone(): void {
		$this->assertSame(
			Agend_Content_Access_Decision::STATE_GRANTED,
			Agend_Content_Access_Decision::evaluate( array( 'mode' => 'public' ), $this->anonymous() )
		);
	}

	#[Test]
	public function anonymous_is_asked_to_sign_in_not_to_join(): void {
		$this->assertSame(
			Agend_Content_Access_Decision::STATE_AUTH,
			Agend_Content_Access_Decision::evaluate( array( 'mode' => 'active_member' ), $this->anonymous() )
		);
		$this->assertSame(
			Agend_Content_Access_Decision::STATE_AUTH,
			Agend_Content_Access_Decision::evaluate(
				array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A ) ),
				$this->anonymous()
			)
		);
	}

	#[Test]
	public function an_identified_non_member_is_asked_to_join(): void {
		$this->assertSame(
			Agend_Content_Access_Decision::STATE_MEMBERSHIP,
			Agend_Content_Access_Decision::evaluate(
				array( 'mode' => 'active_member' ),
				$this->identifiedNonMember()
			)
		);
	}

	#[Test]
	public function any_tier_satisfies_all_active_members(): void {
		$this->assertSame(
			Agend_Content_Access_Decision::STATE_GRANTED,
			Agend_Content_Access_Decision::evaluate(
				array( 'mode' => 'active_member' ),
				$this->member( self::TIER_B )
			)
		);
	}

	#[Test]
	public function one_intersecting_tier_satisfies_selected_plans(): void {
		$this->assertSame(
			Agend_Content_Access_Decision::STATE_GRANTED,
			Agend_Content_Access_Decision::evaluate(
				array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A, self::TIER_B ) ),
				$this->member( self::TIER_B )
			)
		);
	}

	/** A paying member on the wrong plan must not be told to go and join. */
	#[Test]
	public function a_member_on_the_wrong_plan_gets_the_plan_prompt(): void {
		$this->assertSame(
			Agend_Content_Access_Decision::STATE_PLAN,
			Agend_Content_Access_Decision::evaluate(
				array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A ) ),
				$this->member( self::TIER_B )
			)
		);
	}

	#[Test]
	public function an_identified_non_member_gets_the_join_prompt_even_for_plan_policies(): void {
		$this->assertSame(
			Agend_Content_Access_Decision::STATE_MEMBERSHIP,
			Agend_Content_Access_Decision::evaluate(
				array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A ) ),
				$this->identifiedNonMember()
			)
		);
	}

	#[Test]
	public function an_unrecognised_mode_denies_rather_than_grants(): void {
		// An unreadable restriction is not evidence of no restriction.
		$this->assertNotSame(
			Agend_Content_Access_Decision::STATE_GRANTED,
			Agend_Content_Access_Decision::evaluate( array( 'mode' => 'everyone' ), $this->member( self::TIER_A ) )
		);
	}

	#[Test]
	public function an_empty_tier_list_is_unsatisfiable(): void {
		$this->assertNotSame(
			Agend_Content_Access_Decision::STATE_GRANTED,
			Agend_Content_Access_Decision::evaluate(
				array( 'mode' => 'selected_tiers', 'tier_ids' => array() ),
				$this->member( self::TIER_A )
			)
		);
	}

	// -----------------------------------------------------------------------
	// Viewer resolution
	// -----------------------------------------------------------------------

	/**
	 * The criterion that keeps WordPress out of the authorisation business: a
	 * local account with no Agend session proves nothing about membership.
	 */
	#[Test]
	public function no_bearer_means_anonymous_and_no_gateway_call(): void {
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', '' );

		$viewer = Agend_Content_Access_Decision::viewer();

		$this->assertFalse( $viewer['identified'] );
		$this->assertSame( array(), $viewer['tier_ids'] );
		$this->assertSame( array(), Agend_Test_WP::$requests, 'an anonymous visitor must not trigger a gateway call' );
	}

	#[Test]
	public function a_bearer_resolves_the_tier_set_from_agend(): void {
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'member-token' );
		Agend_Test_WP::set_filter(
			'agend_apps_crm_get_my_entitlements_response',
			array( 'data' => array( 'tier_ids' => array( self::TIER_A ), 'is_member' => true ) )
		);

		$viewer = Agend_Content_Access_Decision::viewer();

		$this->assertTrue( $viewer['identified'] );
		$this->assertSame( array( self::TIER_A ), $viewer['tier_ids'] );
	}

	/**
	 * An outage must not read as a lapsed membership. The visitor is denied,
	 * but flagged so the failure is visible as an operational error.
	 */
	#[Test]
	public function a_gateway_failure_denies_and_is_flagged_degraded(): void {
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'member-token' );
		Agend_Test_WP::set_filter(
			'agend_apps_crm_get_my_entitlements_response',
			new WP_Error( 'http_request_failed', 'down' )
		);

		$viewer = Agend_Content_Access_Decision::viewer();

		$this->assertTrue( $viewer['degraded'] );
		$this->assertSame( array(), $viewer['tier_ids'] );
		$this->assertSame(
			Agend_Content_Access_Decision::STATE_AUTH,
			Agend_Content_Access_Decision::evaluate( array( 'mode' => 'active_member' ), $viewer )
		);
	}

	#[Test]
	public function a_malformed_entitlements_response_grants_nothing(): void {
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'member-token' );
		Agend_Test_WP::set_filter(
			'agend_apps_crm_get_my_entitlements_response',
			array( 'data' => array( 'tier_ids' => 'not-an-array' ) )
		);

		$viewer = Agend_Content_Access_Decision::viewer();

		$this->assertSame( array(), $viewer['tier_ids'] );
		$this->assertNotSame(
			Agend_Content_Access_Decision::STATE_GRANTED,
			Agend_Content_Access_Decision::evaluate( array( 'mode' => 'active_member' ), $viewer )
		);
	}

	#[Test]
	public function non_string_tier_ids_are_discarded(): void {
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'member-token' );
		Agend_Test_WP::set_filter(
			'agend_apps_crm_get_my_entitlements_response',
			array( 'data' => array( 'tier_ids' => array( self::TIER_A, 123, null, '' ) ) )
		);

		$this->assertSame(
			array( self::TIER_A ),
			Agend_Content_Access_Decision::viewer()['tier_ids']
		);
	}

	#[Test]
	public function the_viewer_is_resolved_once_per_request(): void {
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'member-token' );
		Agend_Test_WP::set_filter(
			'agend_apps_crm_get_my_entitlements_response',
			array( 'data' => array( 'tier_ids' => array( self::TIER_A ) ) )
		);

		Agend_Content_Access_Decision::viewer();
		$before = count( Agend_Test_WP::$requests );
		Agend_Content_Access_Decision::viewer();

		$this->assertSame( $before, count( Agend_Test_WP::$requests ) );
	}
}
