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
use PHPUnit\Framework\Attributes\DataProvider;
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

	/**
	 * What a stored policy this version cannot read is enforced as. It is the
	 * same empty plan list the gateway uses for "nobody", so the three visitor
	 * states fall out of the existing plan logic with no special case.
	 */
	#[Test]
	public function the_unsatisfiable_policy_denies_every_kind_of_visitor(): void {
		$policy = Agend_Content_Access_Policy::UNSATISFIABLE;

		$this->assertSame(
			Agend_Content_Access_Decision::STATE_AUTH,
			Agend_Content_Access_Decision::evaluate( $policy, $this->anonymous() )
		);
		$this->assertSame(
			Agend_Content_Access_Decision::STATE_MEMBERSHIP,
			Agend_Content_Access_Decision::evaluate( $policy, $this->identifiedNonMember() )
		);
		$this->assertSame(
			Agend_Content_Access_Decision::STATE_PLAN,
			Agend_Content_Access_Decision::evaluate( $policy, $this->member( self::TIER_A, self::TIER_B ) )
		);
	}

	// -----------------------------------------------------------------------
	// The decision for a stored post
	// -----------------------------------------------------------------------

	private const POST_ID = 31;

	private function storePolicy( $value ): void {
		$GLOBALS['agend_test_post_meta'][ self::POST_ID ] = array(
			Agend_Content_Access_Policy::META_KEY => $value,
		);
	}

	/** The visitor is not somebody who can edit the post. */
	private function asVisitor(): void {
		$GLOBALS['agend_test_current_user_can']['edit_post'] = false;
	}

	private function asMember( string ...$tiers ): void {
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'member-token' );
		Agend_Test_WP::set_filter(
			'agend_apps_crm_get_my_entitlements_response',
			array( 'data' => array( 'tier_ids' => $tiers ) )
		);
	}

	/**
	 * The defect. 0.1.0 read all of these as "no policy" and served the page to
	 * an anonymous visitor, including a `segments` or `selected_entitlements`
	 * policy written by a newer release and then rolled back from.
	 */
	#[Test]
	#[DataProvider( 'unreadablePolicies' )]
	public function an_anonymous_visitor_is_denied_a_post_whose_stored_policy_is_unreadable( $value ): void {
		$this->storePolicy( $value );
		$this->asVisitor();

		$decision = Agend_Content_Access_Decision::for_post( self::POST_ID );

		$this->assertNotSame( Agend_Content_Access_Decision::STATE_GRANTED, $decision['state'] );
		$this->assertSame( Agend_Content_Access_Decision::STATE_AUTH, $decision['state'] );
		$this->assertSame( Agend_Content_Access_Policy::UNSATISFIABLE, $decision['policy'] );
	}

	#[Test]
	#[DataProvider( 'unreadablePolicies' )]
	public function a_member_holding_plans_is_denied_a_post_whose_stored_policy_is_unreadable( $value ): void {
		$this->storePolicy( $value );
		$this->asVisitor();
		$this->asMember( self::TIER_A, self::TIER_B );

		$decision = Agend_Content_Access_Decision::for_post( self::POST_ID );

		$this->assertNotSame( Agend_Content_Access_Decision::STATE_GRANTED, $decision['state'] );
		$this->assertSame( Agend_Content_Access_Decision::STATE_PLAN, $decision['state'] );
	}

	#[Test]
	public function an_identified_non_member_is_denied_a_post_whose_stored_policy_is_unreadable(): void {
		$this->storePolicy( array( 'mode' => 'authenticated' ) );
		$this->asVisitor();
		$this->asMember();

		$this->assertSame(
			Agend_Content_Access_Decision::STATE_MEMBERSHIP,
			Agend_Content_Access_Decision::for_post( self::POST_ID )['state']
		);
	}

	/** The one-line shape of the rollback hazard, kept separate so it reads as the headline case. */
	#[Test]
	public function a_segments_policy_from_a_newer_release_does_not_make_the_post_public(): void {
		$this->storePolicy( array( 'mode' => 'segments', 'segment_ids' => array( self::TIER_A ) ) );
		$this->asVisitor();

		$this->assertNotSame(
			Agend_Content_Access_Decision::STATE_GRANTED,
			Agend_Content_Access_Decision::for_post( self::POST_ID )['state']
		);
	}

	/**
	 * The enforced reading must not over-reach: a post with nothing stored, and
	 * one that is explicitly public, stay readable by everybody.
	 */
	#[Test]
	public function a_post_with_no_stored_policy_stays_public(): void {
		$this->asVisitor();

		$decision = Agend_Content_Access_Decision::for_post( self::POST_ID );

		$this->assertSame( Agend_Content_Access_Decision::STATE_GRANTED, $decision['state'] );
		$this->assertNull( $decision['policy'] );
	}

	#[Test]
	public function a_post_with_an_empty_stored_value_stays_public(): void {
		$this->storePolicy( '' );
		$this->asVisitor();

		$this->assertSame(
			Agend_Content_Access_Decision::STATE_GRANTED,
			Agend_Content_Access_Decision::for_post( self::POST_ID )['state']
		);
	}

	#[Test]
	public function an_explicitly_public_post_stays_public_without_a_gateway_call(): void {
		$this->storePolicy( array( 'mode' => 'public' ) );
		$this->asVisitor();

		$decision = Agend_Content_Access_Decision::for_post( self::POST_ID );

		$this->assertSame( Agend_Content_Access_Decision::STATE_GRANTED, $decision['state'] );
		$this->assertSame( array(), Agend_Test_WP::$requests );
	}

	#[Test]
	public function a_readable_restricted_policy_still_follows_its_own_rules(): void {
		$this->storePolicy( array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A ) ) );
		$this->asVisitor();
		$this->asMember( self::TIER_A );

		$this->assertSame(
			Agend_Content_Access_Decision::STATE_GRANTED,
			Agend_Content_Access_Decision::for_post( self::POST_ID )['state']
		);
	}

	/**
	 * An author must still be able to open the page to repair it: authoring is
	 * the one capability that is not a membership claim. Everybody else stays out.
	 */
	#[Test]
	public function an_editor_can_still_preview_a_post_whose_stored_policy_is_unreadable(): void {
		$this->storePolicy( array( 'mode' => 'authenticated' ) );
		$GLOBALS['agend_test_current_user_can']['edit_post'] = true;

		$decision = Agend_Content_Access_Decision::for_post( self::POST_ID );

		$this->assertSame( Agend_Content_Access_Decision::STATE_GRANTED, $decision['state'] );
		$this->assertTrue( $decision['preview'] );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function unreadablePolicies(): array {
		return array(
			'authenticated, a gateway mode this plugin lacks' => array( array( 'mode' => 'authenticated' ) ),
			'segments'                                        => array(
				array( 'mode' => 'segments', 'segment_ids' => array( self::TIER_A ) ),
			),
			'selected entitlements'                           => array(
				array( 'mode' => 'selected_entitlements', 'entitlement_ids' => array( self::TIER_B ) ),
			),
			'a plan id that is not a uuid'                    => array(
				array( 'mode' => 'selected_tiers', 'tier_ids' => array( 'not-a-uuid' ) ),
			),
			'an extra top level key'                          => array(
				array( 'mode' => 'active_member', 'note' => 'smuggled' ),
			),
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
