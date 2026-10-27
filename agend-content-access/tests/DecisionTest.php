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
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-viewer-facts.php';
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
	private const SEG_A  = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1';
	private const SEG_B  = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbb2';
	private const ENT_A  = 'directory.access';
	private const ENT_B  = 'journal';

	protected function setUp(): void {
		parent::setUp();
		Agend_Content_Access_Decision::reset_cache();
	}

	private function anonymous(): array {
		return array( 'identified' => false, 'tier_ids' => array(), 'degraded' => false );
	}

	private function segmentsPolicy( string ...$ids ): array {
		return array( 'mode' => 'segments', 'segment_ids' => $ids );
	}

	private function entitlementsPolicy( string ...$keys ): array {
		return array( 'mode' => 'selected_entitlements', 'entitlement_keys' => $keys );
	}

	/** An identified viewer with the given facts, every other fact empty. */
	private function holding( array $facts ): array {
		return array_merge(
			array(
				'identified'       => true,
				'tier_ids'         => array(),
				'entitlement_keys' => array(),
				'segment_ids'      => array(),
				'degraded'         => false,
			),
			$facts
		);
	}

	/** Signs in a member with a bearer, as the document path requires. */
	private function signedInMember( array $entitlementsData = array() ): void {
		$GLOBALS['agend_test_current_user_id'] = 7;
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'member-token' );
		Agend_Test_WP::set_filter(
			'agend_apps_crm_get_my_entitlements_response',
			array( 'data' => array_merge( array( 'is_member' => true, 'tier_ids' => array( self::TIER_A ) ), $entitlementsData ) )
		);
	}

	private function segmentsAre( array $rows ): void {
		Agend_Test_WP::set_filter( 'agend_apps_crm_get_my_segments_response', array( 'data' => $rows ) );
	}

	private function segmentCalls(): int {
		return count(
			array_filter(
				Agend_Test_WP::$requests,
				static fn( array $request ): bool => '/crm/me/segments' === $request['url']
			)
		);
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
	// Segments and selected entitlements: evaluation
	// -----------------------------------------------------------------------

	/** The two arms differ only in which set the viewer is compared on. */
	#[Test]
	#[DataProvider( 'setArms' )]
	public function a_set_policy_is_granted_when_the_viewer_holds_any_named_entry( array $policy, string $viewer_key, array $held, array $outside ): void {
		$this->assertSame(
			Agend_Content_Access_Decision::STATE_GRANTED,
			Agend_Content_Access_Decision::evaluate( $policy, $this->holding( array( $viewer_key => $held ) ) )
		);
		$this->assertSame(
			Agend_Content_Access_Decision::STATE_GRANTED,
			Agend_Content_Access_Decision::evaluate( $policy, $this->holding( array( $viewer_key => array_merge( $outside, $held ) ) ) ),
			'one intersecting entry among others is enough'
		);
	}

	/** An unidentified visitor is asked to sign in, whatever the arm. */
	#[Test]
	#[DataProvider( 'setArms' )]
	public function an_unidentified_visitor_is_asked_to_sign_in( array $policy ): void {
		$this->assertSame(
			Agend_Content_Access_Decision::STATE_AUTH,
			Agend_Content_Access_Decision::evaluate( $policy, $this->anonymous() )
		);
	}

	/**
	 * Outside a segment or an entitlement is neither a membership problem nor a
	 * plan problem. Telling a member to join, or to change plan, would be wrong.
	 */
	#[Test]
	#[DataProvider( 'setArms' )]
	public function an_identified_visitor_outside_the_set_is_restricted_never_asked_to_join( array $policy, string $viewer_key, array $held, array $outside ): void {
		$this->assertSame(
			Agend_Content_Access_Decision::STATE_RESTRICTED,
			Agend_Content_Access_Decision::evaluate( $policy, $this->holding( array( $viewer_key => $outside ) ) )
		);
		$this->assertSame(
			Agend_Content_Access_Decision::STATE_RESTRICTED,
			Agend_Content_Access_Decision::evaluate( $policy, $this->holding( array() ) ),
			'holding nothing at all is still access_restricted'
		);
		$this->assertSame(
			Agend_Content_Access_Decision::STATE_RESTRICTED,
			Agend_Content_Access_Decision::evaluate( $policy, $this->holding( array( 'tier_ids' => array( self::TIER_A ) ) ) ),
			'a membership does not stand in for a segment or an entitlement'
		);
	}

	/** A viewer array that omits the fact holds none, so a missing fact can only deny. */
	#[Test]
	#[DataProvider( 'setArms' )]
	public function a_viewer_without_the_fact_is_denied( array $policy ): void {
		$this->assertSame(
			Agend_Content_Access_Decision::STATE_RESTRICTED,
			Agend_Content_Access_Decision::evaluate( $policy, array( 'identified' => true ) )
		);
	}

	/** An empty list is a disjoint intersection: nobody satisfies it, however much they hold. */
	#[Test]
	public function an_empty_set_policy_is_unsatisfiable(): void {
		$everything = $this->holding(
			array(
				'tier_ids'         => array( self::TIER_A ),
				'segment_ids'      => array( self::SEG_A ),
				'entitlement_keys' => array( self::ENT_A ),
			)
		);

		$this->assertSame( Agend_Content_Access_Decision::STATE_RESTRICTED, Agend_Content_Access_Decision::evaluate( $this->segmentsPolicy(), $everything ) );
		$this->assertSame( Agend_Content_Access_Decision::STATE_RESTRICTED, Agend_Content_Access_Decision::evaluate( $this->entitlementsPolicy(), $everything ) );
	}

	/** Each arm reads only its own fact. */
	#[Test]
	public function a_segment_does_not_satisfy_an_entitlement_policy_or_the_reverse(): void {
		$this->assertNotSame(
			Agend_Content_Access_Decision::STATE_GRANTED,
			Agend_Content_Access_Decision::evaluate(
				$this->entitlementsPolicy( self::ENT_A ),
				$this->holding( array( 'segment_ids' => array( self::ENT_A ) ) )
			)
		);
		$this->assertNotSame(
			Agend_Content_Access_Decision::STATE_GRANTED,
			Agend_Content_Access_Decision::evaluate(
				$this->segmentsPolicy( self::SEG_A ),
				$this->holding( array( 'entitlement_keys' => array( self::SEG_A ) ) )
			)
		);
	}

	public static function setArms(): array {
		return array(
			'segments'     => array(
				array( 'mode' => 'segments', 'segment_ids' => array( self::SEG_A ) ),
				'segment_ids',
				array( self::SEG_A ),
				array( self::SEG_B ),
			),
			'entitlements' => array(
				array( 'mode' => 'selected_entitlements', 'entitlement_keys' => array( self::ENT_A ) ),
				'entitlement_keys',
				array( self::ENT_A ),
				array( self::ENT_B ),
			),
		);
	}

	// -----------------------------------------------------------------------
	// Segments and selected entitlements: the viewer
	// -----------------------------------------------------------------------

	#[Test]
	public function the_viewer_carries_the_entitlement_keys_from_the_same_call(): void {
		$this->signedInMember(
			array( 'capabilities' => array( array( 'gate_key' => self::ENT_A ), array( 'gate_key' => 'Not A Key' ), array( 'gate_key' => self::ENT_B ) ) )
		);

		$viewer = Agend_Content_Access_Decision::viewer();

		$this->assertSame( array( self::ENT_A, self::ENT_B ), $viewer['entitlement_keys'] );
		$this->assertSame( array(), $viewer['segment_ids'] );
		$this->assertSame(
			Agend_Content_Access_Decision::STATE_GRANTED,
			Agend_Content_Access_Decision::evaluate( $this->entitlementsPolicy( self::ENT_B ), $viewer )
		);
		$this->assertSame( 1, count( Agend_Test_WP::$requests ), 'entitlements cost no extra call' );
	}

	/** The anonymous viewer has the full shape, so a caller can read any fact without a notice. */
	#[Test]
	public function an_anonymous_viewer_has_every_fact_empty(): void {
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', '' );

		$this->assertSame(
			array( 'identified' => false, 'tier_ids' => array(), 'entitlement_keys' => array(), 'segment_ids' => array(), 'degraded' => false ),
			Agend_Content_Access_Decision::viewer( $this->segmentsPolicy( self::SEG_A ) )
		);
		$this->assertSame( array(), Agend_Test_WP::$requests, 'no bearer, no network, not even for segments' );
	}

	/**
	 * Segments are a second gateway call and almost no page needs them, so none
	 * of these may trigger it.
	 */
	#[Test]
	#[DataProvider( 'policiesThatDoNotNeedSegments' )]
	public function segments_are_not_fetched_for_a_policy_that_does_not_need_them( ?array $policy ): void {
		$this->signedInMember();
		$this->segmentsAre( array( array( 'id' => self::SEG_A, 'slug' => 'vic' ) ) );

		$viewer = Agend_Content_Access_Decision::viewer( $policy );
		Agend_Content_Access_Decision::evaluate( $policy, $viewer );

		$this->assertSame( 0, $this->segmentCalls() );
		$this->assertSame( array(), $viewer['segment_ids'] );
	}

	public static function policiesThatDoNotNeedSegments(): array {
		return array(
			'no policy'                    => array( null ),
			'public'                       => array( array( 'mode' => 'public' ) ),
			'active members'               => array( array( 'mode' => 'active_member' ) ),
			'selected plans'               => array( array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A ) ) ),
			'selected entitlements'        => array( array( 'mode' => 'selected_entitlements', 'entitlement_keys' => array( self::ENT_A ) ) ),
			'segments with nothing to match' => array( array( 'mode' => 'segments', 'segment_ids' => array() ) ),
		);
	}

	#[Test]
	public function the_viewer_for_no_policy_never_fetches_segments(): void {
		$this->signedInMember();

		Agend_Content_Access_Decision::viewer();

		$this->assertSame( 0, $this->segmentCalls() );
	}

	#[Test]
	public function a_segments_policy_fetches_the_visitors_segments_and_grants_a_member_of_one(): void {
		$this->signedInMember();
		$this->segmentsAre( array( array( 'id' => self::SEG_B, 'slug' => 'trainees' ), array( 'id' => self::SEG_A, 'slug' => 'vic' ) ) );
		$policy = $this->segmentsPolicy( self::SEG_A );

		$viewer = Agend_Content_Access_Decision::viewer( $policy );

		$this->assertSame( array( self::SEG_B, self::SEG_A ), $viewer['segment_ids'] );
		$this->assertTrue( $viewer['identified'] );
		$this->assertFalse( $viewer['degraded'] );
		$this->assertSame( Agend_Content_Access_Decision::STATE_GRANTED, Agend_Content_Access_Decision::evaluate( $policy, $viewer ) );
		$this->assertSame( 1, $this->segmentCalls() );
	}

	#[Test]
	public function an_identified_visitor_in_no_matching_segment_is_restricted(): void {
		$this->signedInMember();
		$this->segmentsAre( array( array( 'id' => self::SEG_B, 'slug' => 'trainees' ) ) );
		$policy = $this->segmentsPolicy( self::SEG_A );

		$this->assertSame(
			Agend_Content_Access_Decision::STATE_RESTRICTED,
			Agend_Content_Access_Decision::evaluate( $policy, Agend_Content_Access_Decision::viewer( $policy ) )
		);
	}

	/** Once per request however many elements carry a segments policy. */
	#[Test]
	public function segments_are_fetched_once_per_request_however_many_policies_need_them(): void {
		$this->signedInMember();
		$this->segmentsAre( array( array( 'id' => self::SEG_A, 'slug' => 'vic' ) ) );

		foreach ( array( $this->segmentsPolicy( self::SEG_A ), $this->segmentsPolicy( self::SEG_B ), $this->segmentsPolicy( self::SEG_A, self::SEG_B ) ) as $policy ) {
			Agend_Content_Access_Decision::viewer( $policy );
		}

		$this->assertSame( 1, $this->segmentCalls() );
	}

	/** The loaded segments belong to the viewer built for that policy, not to every later one. */
	#[Test]
	public function a_segments_policy_does_not_leak_segments_into_later_viewers(): void {
		$this->signedInMember();
		$this->segmentsAre( array( array( 'id' => self::SEG_A, 'slug' => 'vic' ) ) );

		Agend_Content_Access_Decision::viewer( $this->segmentsPolicy( self::SEG_A ) );

		$this->assertSame( array(), Agend_Content_Access_Decision::viewer()['segment_ids'] );
		$this->assertSame(
			array(),
			Agend_Content_Access_Decision::viewer( array( 'mode' => 'active_member' ) )['segment_ids']
		);
	}

	#[Test]
	public function an_unidentified_visitor_triggers_no_segment_call(): void {
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', '' );
		$policy = $this->segmentsPolicy( self::SEG_A );

		$this->assertSame(
			Agend_Content_Access_Decision::STATE_AUTH,
			Agend_Content_Access_Decision::evaluate( $policy, Agend_Content_Access_Decision::viewer( $policy ) )
		);
		$this->assertSame( 0, $this->segmentCalls() );
	}

	/**
	 * An outage must not read as "in no segments". The visitor is denied, but
	 * flagged so it is logged as an operational error.
	 */
	#[Test]
	public function a_segment_lookup_failure_denies_and_is_flagged_degraded(): void {
		$this->signedInMember();
		Agend_Test_WP::set_filter( 'agend_apps_crm_get_my_segments_response', new WP_Error( 'down', 'Gateway unreachable' ) );
		$policy = $this->segmentsPolicy( self::SEG_A );

		$viewer = Agend_Content_Access_Decision::viewer( $policy );

		$this->assertTrue( $viewer['identified'], 'the membership lookup succeeded' );
		$this->assertTrue( $viewer['degraded'] );
		$this->assertSame( array(), $viewer['segment_ids'] );
		$this->assertSame( Agend_Content_Access_Decision::STATE_RESTRICTED, Agend_Content_Access_Decision::evaluate( $policy, $viewer ) );

		// One outage is one call, and the next viewer built for a segments policy is still degraded.
		$this->assertTrue( Agend_Content_Access_Decision::viewer( $policy )['degraded'] );
		$this->assertSame( 1, $this->segmentCalls() );
		// A policy that does not need segments is not degraded by it.
		$this->assertFalse( Agend_Content_Access_Decision::viewer( array( 'mode' => 'active_member' ) )['degraded'] );
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
