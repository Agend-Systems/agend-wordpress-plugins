<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Access as Access;
use Agend_Content_Access_Decision as Decision;
use Agend_Content_Access_Policy as Policy;
use Agend_Content_Access_Rules as Rules;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use WP_Error;

require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-policy.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-fragment-rules.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-block-attributes.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-viewer-facts.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-conditions.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-providers.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-runtime.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-rules.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-decision.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-access.php';

/**
 * The one evaluator: does this visitor satisfy these rules?
 *
 * Every decision this plugin makes goes through `Access::verdict()`, so these are
 * the tests that matter most, and the ones that matter most among them assert that
 * an uncertainty does NOT grant: an unknown provider, a lookup that failed, rules
 * that cannot be read, a provider that threw, each with the result reversed too.
 *
 * The first half is the policy evaluation the document path used to have
 * (`DecisionTest` before it moved here), read through the compatibility conversion
 * and judged by the new evaluator. The tests are the same tests: what changed is
 * the thing they ask.
 */
#[CoversClass( Access::class )]
final class AccessTest extends TestCase {

	private const TIER_A = '11111111-1111-1111-1111-111111111111';
	private const TIER_B = '22222222-2222-2222-2222-222222222222';
	private const SEG_A  = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1';
	private const SEG_B  = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbb2';
	private const ENT_A  = 'directory.access';
	private const ENT_B  = 'journal';

	/** @var int Calls the bearer lookup has had: the identity lookup an anonymous reading must never cause. */
	private int $identity_lookups = 0;

	protected function setUp(): void {
		parent::setUp();

		$this->identity_lookups                       = 0;
		$GLOBALS['agend_test_current_user_id']        = 0;
		$GLOBALS['agend_test_current_user_roles']     = array();
		Agend_Test_WP::$filters['agend_apps_bearer_token'] = function (): string {
			++$this->identity_lookups;

			return '';
		};
	}

	protected function tearDown(): void {
		unset( $GLOBALS['agend_test_current_user_roles'] );

		parent::tearDown();
	}

	// -----------------------------------------------------------------------
	// Who is looking
	// -----------------------------------------------------------------------

	/**
	 * Signs in a visitor with a bearer and the gateway's answers for them.
	 *
	 * @param string[] $tiers        Plans held. A member when there is one.
	 * @param string[] $entitlements Entitlement gate keys held.
	 * @param string[] $segments     Segment ids the visitor belongs to.
	 */
	private function signedIn( array $tiers = array(), array $entitlements = array(), array $segments = array() ): void {
		$GLOBALS['agend_test_current_user_id'] = 7;

		Agend_Test_WP::$filters['agend_apps_bearer_token'] = function (): string {
			++$this->identity_lookups;

			return 'member-token';
		};
		Agend_Test_WP::set_filter(
			'agend_apps_crm_get_my_entitlements_response',
			array(
				'data' => array(
					'is_member'    => array() !== $tiers,
					'tier_ids'     => $tiers,
					'tier_slugs'   => array_map( static fn( string $id ): string => 'slug-' . substr( $id, 0, 4 ), $tiers ),
					'capabilities' => array_map( static fn( string $key ): array => array( 'gate_key' => $key ), $entitlements ),
				),
			)
		);
		Agend_Test_WP::set_filter(
			'agend_apps_crm_get_my_segments_response',
			array(
				'data' => array_map(
					static fn( string $id ): array => array( 'id' => $id, 'slug' => 'slug-' . substr( $id, 0, 4 ) ),
					$segments
				),
			)
		);
	}

	/** A different visitor on the same site: the memos and the cached standing go with the first. */
	private function newVisitor(): void {
		Decision::reset_cache();
		Agend_Test_WP::$transients = array();
	}

	private function calls( string $endpoint ): int {
		return count(
			array_filter(
				Agend_Test_WP::$requests,
				static fn( array $request ): bool => $endpoint === $request['url']
			)
		);
	}

	/** A plain rule set in the new shape. */
	private function rules( array $rules, string $match = 'any', bool $invert = false ): Rules {
		return Rules::from_array( array( 'rules' => $rules, 'match' => $match, 'invert' => $invert ) );
	}

	private function allows( Rules $rules, array $options = array() ): bool {
		return Access::verdict( $rules, $options )['allowed'];
	}

	private function state( Rules $rules, array $options = array() ): string {
		return Access::verdict( $rules, $options )['state'];
	}

	/**
	 * The state for a policy, for a visitor described the way the old pure function took one:
	 * `identified`, `tier_ids`, `entitlement_keys`, `segment_ids`. An unidentified visitor has no bearer.
	 */
	private function evaluate( ?array $policy, array $viewer ): string {
		$this->newVisitor();
		$GLOBALS['agend_test_current_user_id'] = 0;
		Agend_Test_WP::$filters                = array();

		if ( ! empty( $viewer['identified'] ) ) {
			$this->signedIn(
				$viewer['tier_ids'] ?? array(),
				$viewer['entitlement_keys'] ?? array(),
				$viewer['segment_ids'] ?? array()
			);
		}

		return $this->state( Rules::from_policy( $policy ) );
	}

	private function anonymous(): array {
		return array( 'identified' => false );
	}

	private function member( string ...$tiers ): array {
		return array( 'identified' => true, 'tier_ids' => $tiers );
	}

	private function identifiedNonMember(): array {
		return array( 'identified' => true, 'tier_ids' => array() );
	}

	private function holding( array $facts ): array {
		return array_merge( array( 'identified' => true ), $facts );
	}

	// -----------------------------------------------------------------------
	// Policy evaluation, through the compatibility conversion
	// -----------------------------------------------------------------------

	#[Test]
	public function no_policy_grants(): void {
		// Decision 2.9: a record with no policy predates the capability and stays readable.
		$this->assertSame( Decision::STATE_GRANTED, $this->evaluate( null, $this->anonymous() ) );
	}

	#[Test]
	public function public_grants_everyone(): void {
		$this->assertSame( Decision::STATE_GRANTED, $this->evaluate( array( 'mode' => 'public' ), $this->anonymous() ) );
	}

	#[Test]
	public function anonymous_is_asked_to_sign_in_not_to_join(): void {
		$this->assertSame( Decision::STATE_AUTH, $this->evaluate( array( 'mode' => 'active_member' ), $this->anonymous() ) );
		$this->assertSame(
			Decision::STATE_AUTH,
			$this->evaluate( array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A ) ), $this->anonymous() )
		);
	}

	#[Test]
	public function an_identified_non_member_is_asked_to_join(): void {
		$this->assertSame( Decision::STATE_MEMBERSHIP, $this->evaluate( array( 'mode' => 'active_member' ), $this->identifiedNonMember() ) );
	}

	#[Test]
	public function any_tier_satisfies_all_active_members(): void {
		$this->assertSame( Decision::STATE_GRANTED, $this->evaluate( array( 'mode' => 'active_member' ), $this->member( self::TIER_B ) ) );
	}

	#[Test]
	public function one_intersecting_tier_satisfies_selected_plans(): void {
		$this->assertSame(
			Decision::STATE_GRANTED,
			$this->evaluate( array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A, self::TIER_B ) ), $this->member( self::TIER_B ) )
		);
	}

	/** A paying member on the wrong plan must not be told to go and join. */
	#[Test]
	public function a_member_on_the_wrong_plan_gets_the_plan_prompt(): void {
		$this->assertSame(
			Decision::STATE_PLAN,
			$this->evaluate( array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A ) ), $this->member( self::TIER_B ) )
		);
	}

	#[Test]
	public function an_identified_non_member_gets_the_join_prompt_even_for_plan_policies(): void {
		$this->assertSame(
			Decision::STATE_MEMBERSHIP,
			$this->evaluate( array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A ) ), $this->identifiedNonMember() )
		);
	}

	#[Test]
	public function an_unrecognised_mode_denies_rather_than_grants(): void {
		// An unreadable restriction is not evidence of no restriction.
		$this->assertNotSame( Decision::STATE_GRANTED, $this->evaluate( array( 'mode' => 'everyone' ), $this->member( self::TIER_A ) ) );
	}

	#[Test]
	public function an_empty_tier_list_is_unsatisfiable(): void {
		$this->assertNotSame(
			Decision::STATE_GRANTED,
			$this->evaluate( array( 'mode' => 'selected_tiers', 'tier_ids' => array() ), $this->member( self::TIER_A ) )
		);
	}

	/**
	 * What a stored policy this version cannot read is enforced as. The three visitor states fall
	 * out of the evaluator with no special case, because unreadable rules count as naming a plan.
	 */
	#[Test]
	public function the_unsatisfiable_policy_denies_every_kind_of_visitor(): void {
		$policy = Policy::UNSATISFIABLE;

		$this->assertSame( Decision::STATE_AUTH, $this->evaluate( $policy, $this->anonymous() ) );
		$this->assertSame( Decision::STATE_MEMBERSHIP, $this->evaluate( $policy, $this->identifiedNonMember() ) );
		$this->assertSame( Decision::STATE_PLAN, $this->evaluate( $policy, $this->member( self::TIER_A, self::TIER_B ) ) );
	}

	// -----------------------------------------------------------------------
	// Segments and selected entitlements, through the compatibility conversion
	// -----------------------------------------------------------------------

	/** @return array<string, array{0: array, 1: string, 2: array, 3: array}> */
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

	#[Test]
	#[DataProvider( 'setArms' )]
	public function a_set_policy_is_granted_when_the_viewer_holds_any_named_entry( array $policy, string $viewer_key, array $held, array $outside ): void {
		$this->assertSame( Decision::STATE_GRANTED, $this->evaluate( $policy, $this->holding( array( $viewer_key => $held ) ) ) );
		$this->assertSame(
			Decision::STATE_GRANTED,
			$this->evaluate( $policy, $this->holding( array( $viewer_key => array_merge( $outside, $held ) ) ) ),
			'one intersecting entry among others is enough'
		);
	}

	/** An unidentified visitor is asked to sign in, whatever the arm. */
	#[Test]
	#[DataProvider( 'setArms' )]
	public function an_unidentified_visitor_is_asked_to_sign_in( array $policy ): void {
		$this->assertSame( Decision::STATE_AUTH, $this->evaluate( $policy, $this->anonymous() ) );
	}

	/**
	 * Outside a segment or an entitlement is neither a membership problem nor a plan problem.
	 * Telling a member to join, or to change plan, would be wrong.
	 */
	#[Test]
	#[DataProvider( 'setArms' )]
	public function an_identified_visitor_outside_the_set_is_restricted_never_asked_to_join( array $policy, string $viewer_key, array $held, array $outside ): void {
		$this->assertSame( Decision::STATE_RESTRICTED, $this->evaluate( $policy, $this->holding( array( $viewer_key => $outside ) ) ) );
		$this->assertSame( Decision::STATE_RESTRICTED, $this->evaluate( $policy, $this->holding( array() ) ), 'holding nothing at all is still access_restricted' );
		$this->assertSame(
			Decision::STATE_RESTRICTED,
			$this->evaluate( $policy, $this->holding( array( 'tier_ids' => array( self::TIER_A ) ) ) ),
			'a membership does not stand in for a segment or an entitlement'
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

		$this->assertNotSame( Decision::STATE_GRANTED, $this->evaluate( array( 'mode' => 'segments', 'segment_ids' => array() ), $everything ) );
		$this->assertNotSame( Decision::STATE_GRANTED, $this->evaluate( array( 'mode' => 'selected_entitlements', 'entitlement_keys' => array() ), $everything ) );
	}

	/** Each arm reads only its own fact. */
	#[Test]
	public function a_segment_does_not_satisfy_an_entitlement_policy_or_the_reverse(): void {
		$this->assertNotSame(
			Decision::STATE_GRANTED,
			$this->evaluate(
				array( 'mode' => 'selected_entitlements', 'entitlement_keys' => array( self::ENT_A ) ),
				$this->holding( array( 'segment_ids' => array( self::SEG_A ) ) )
			)
		);
		$this->assertNotSame(
			Decision::STATE_GRANTED,
			$this->evaluate(
				array( 'mode' => 'segments', 'segment_ids' => array( self::SEG_A ) ),
				$this->holding( array( 'entitlement_keys' => array( self::ENT_A ) ) )
			)
		);
	}

	// -----------------------------------------------------------------------
	// The visitor's facts, as the evaluator reads them
	// -----------------------------------------------------------------------

	#[Test]
	public function entitlement_keys_come_from_the_same_call_as_the_membership(): void {
		$this->signedIn( array( self::TIER_A ), array( self::ENT_A, self::ENT_B ) );

		$rules = $this->rules( array( 'agend_entitlements:' . self::ENT_B, 'agend_membership:is_member' ), 'all' );

		$this->assertTrue( $this->allows( $rules ) );
		$this->assertSame( 1, $this->calls( '/crm/me/entitlements' ), 'entitlements cost no extra call' );
		$this->assertSame( 0, $this->calls( '/crm/me/segments' ) );
	}

	/** A key that does not have the shape of a gate key is dropped, and so can never match one a rule names. */
	#[Test]
	public function a_malformed_gate_key_from_the_gateway_is_dropped(): void {
		$this->signedIn( array( self::TIER_A ), array( 'Not A Key', self::ENT_A ) );

		$this->assertFalse( $this->allows( $this->rules( array( 'agend_entitlements:Not A Key' ) ) ) );
		$this->assertTrue( $this->allows( $this->rules( array( 'agend_entitlements:' . self::ENT_A ) ) ) );
	}

	/** Segments are a second gateway call and almost no page needs them, so none of these may trigger it. */
	#[Test]
	#[DataProvider( 'policiesThatDoNotNeedSegments' )]
	public function segments_are_not_fetched_for_rules_that_do_not_name_one( ?array $policy ): void {
		$this->signedIn( array( self::TIER_A ), array(), array( self::SEG_A ) );

		$this->state( Rules::from_policy( $policy ) );

		$this->assertSame( 0, $this->calls( '/crm/me/segments' ) );
	}

	/** @return array<string, array{0: array|null}> */
	public static function policiesThatDoNotNeedSegments(): array {
		return array(
			'no policy'                      => array( null ),
			'public'                         => array( array( 'mode' => 'public' ) ),
			'active members'                 => array( array( 'mode' => 'active_member' ) ),
			'selected plans'                 => array( array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A ) ) ),
			'selected entitlements'          => array( array( 'mode' => 'selected_entitlements', 'entitlement_keys' => array( self::ENT_A ) ) ),
			'segments with nothing to match' => array( array( 'mode' => 'segments', 'segment_ids' => array() ) ),
		);
	}

	#[Test]
	public function a_segment_rule_fetches_the_visitors_segments_and_grants_a_member_of_one(): void {
		$this->signedIn( array( self::TIER_A ), array(), array( self::SEG_B, self::SEG_A ) );

		$this->assertTrue( $this->allows( $this->rules( array( 'agend_segments:' . self::SEG_A ) ) ) );
		$this->assertSame( 1, $this->calls( '/crm/me/segments' ) );
	}

	#[Test]
	public function an_identified_visitor_in_no_matching_segment_is_restricted(): void {
		$this->signedIn( array( self::TIER_A ), array(), array( self::SEG_B ) );

		$this->assertSame( Decision::STATE_RESTRICTED, $this->state( $this->rules( array( 'agend_segments:' . self::SEG_A ) ) ) );
	}

	/** Once per request however many rules name a segment. */
	#[Test]
	public function segments_are_fetched_once_per_request_however_many_rules_need_them(): void {
		$this->signedIn( array( self::TIER_A ), array(), array( self::SEG_A ) );

		foreach ( array( array( self::SEG_A ), array( self::SEG_B ), array( self::SEG_A, self::SEG_B ) ) as $ids ) {
			$this->allows( $this->rules( array_map( static fn( string $id ): string => 'agend_segments:' . $id, $ids ) ) );
		}

		$this->assertSame( 1, $this->calls( '/crm/me/segments' ) );
	}

	/** One request: at most one entitlements call and one segments call, however many rule sets and parts ask. */
	#[Test]
	public function a_request_makes_at_most_one_entitlements_call_and_one_segments_call(): void {
		$this->signedIn( array( self::TIER_A ), array( self::ENT_A ), array( self::SEG_A ) );

		$everything = Rules::all_of(
			$this->rules( array( 'agend_membership:' . self::TIER_A, 'agend_entitlements:' . self::ENT_A ) ),
			$this->rules( array( 'agend_segments:' . self::SEG_A ) ),
			$this->rules( array( 'agend_membership:is_member' ) )
		);

		for ( $i = 0; $i < 4; $i++ ) {
			$this->assertTrue( $this->allows( $everything ) );
			$this->assertTrue( $this->allows( $this->rules( array( 'agend_entitlements:' . self::ENT_B ), 'any', true ) ) );
		}

		// A denial asks for the state too, which reads the same standing.
		$this->assertFalse( $this->allows( $this->rules( array( 'agend_membership:' . self::TIER_B ) ) ) );

		$this->assertSame( 1, $this->calls( '/crm/me/entitlements' ) );
		$this->assertSame( 1, $this->calls( '/crm/me/segments' ) );
		$this->assertSame( 1, $this->identity_lookups, 'and the bearer is asked once' );
	}

	#[Test]
	public function an_unidentified_visitor_triggers_no_segment_call(): void {
		$this->assertSame( Decision::STATE_AUTH, $this->state( $this->rules( array( 'agend_segments:' . self::SEG_A ) ) ) );
		$this->assertSame( 0, $this->calls( '/crm/me/segments' ) );
		$this->assertSame( array(), Agend_Test_WP::$requests, 'no bearer, no network' );
	}

	/** An outage must not read as "in no segments". The visitor is denied, but flagged. */
	#[Test]
	public function a_segment_lookup_failure_denies_and_is_flagged_degraded(): void {
		$this->signedIn( array( self::TIER_A ) );
		Agend_Test_WP::set_filter( 'agend_apps_crm_get_my_segments_response', new WP_Error( 'down', 'Gateway unreachable' ) );

		$verdict = Access::verdict( $this->rules( array( 'agend_segments:' . self::SEG_A ) ) );

		$this->assertFalse( $verdict['allowed'] );
		$this->assertTrue( $verdict['degraded'] );
		$this->assertSame( array( 'agend_segments:' . self::SEG_A ), $verdict['unknown'] );
		$this->assertSame( Decision::STATE_RESTRICTED, $verdict['state'], 'the membership lookup succeeded, so they are known' );

		// One outage is one call.
		Access::verdict( $this->rules( array( 'agend_segments:' . self::SEG_A ) ) );
		$this->assertSame( 1, $this->calls( '/crm/me/segments' ) );

		// Rules that do not name a segment are not degraded by it.
		$this->assertFalse( Access::verdict( $this->rules( array( 'agend_membership:is_member' ) ) )['degraded'] );
	}

	// -----------------------------------------------------------------------
	// Who is a visitor
	// -----------------------------------------------------------------------

	/**
	 * The criterion that keeps WordPress out of the authorisation business: a local account with no
	 * Agend session proves nothing about membership.
	 */
	#[Test]
	public function no_bearer_means_anonymous_and_no_gateway_call(): void {
		$GLOBALS['agend_test_current_user_id'] = 7;

		$verdict = Access::verdict( $this->rules( array( 'agend_membership:is_member' ) ) );

		$this->assertFalse( $verdict['allowed'] );
		$this->assertSame( Decision::STATE_AUTH, $verdict['state'], 'a WordPress login is not an Agend session' );
		$this->assertSame( array(), Agend_Test_WP::$requests, 'an anonymous visitor must not trigger a gateway call' );
	}

	#[Test]
	public function a_bearer_resolves_the_standing_from_agend(): void {
		$this->signedIn( array( self::TIER_A ) );

		$this->assertTrue( $this->allows( $this->rules( array( 'agend_membership:' . self::TIER_A ) ) ) );
	}

	/** An outage must not read as a lapsed membership. The visitor is denied, flagged and asked to sign in. */
	#[Test]
	public function a_gateway_failure_denies_and_is_flagged_degraded(): void {
		$this->signedIn();
		Agend_Test_WP::set_filter( 'agend_apps_crm_get_my_entitlements_response', new WP_Error( 'http_request_failed', 'down' ) );

		$verdict = Access::verdict( Rules::from_policy( array( 'mode' => 'active_member' ) ) );

		$this->assertFalse( $verdict['allowed'] );
		$this->assertTrue( $verdict['degraded'] );
		$this->assertSame( Decision::STATE_AUTH, $verdict['state'] );
	}

	#[Test]
	public function a_malformed_entitlements_response_grants_nothing(): void {
		$this->signedIn();
		Agend_Test_WP::set_filter( 'agend_apps_crm_get_my_entitlements_response', array( 'data' => array( 'tier_ids' => 'not-an-array' ) ) );

		$this->assertFalse( $this->allows( Rules::from_policy( array( 'mode' => 'active_member' ) ) ) );
	}

	#[Test]
	public function non_string_tier_ids_are_discarded(): void {
		$this->signedIn();
		Agend_Test_WP::set_filter(
			'agend_apps_crm_get_my_entitlements_response',
			array( 'data' => array( 'is_member' => true, 'tier_ids' => array( self::TIER_A, 123, null, '' ) ) )
		);

		$this->assertTrue( $this->allows( $this->rules( array( 'agend_membership:' . self::TIER_A ) ) ) );
		$this->assertFalse( $this->allows( $this->rules( array( 'agend_membership:123' ) ) ) );
	}

	/**
	 * Membership is the gateway's flag AND holding a plan. The page gate decided it by the plans held
	 * and display conditions by the flag, and where they disagree the visitor is not a member.
	 */
	#[Test]
	public function a_member_is_somebody_the_gateway_calls_one_who_holds_a_plan(): void {
		$this->signedIn();
		Agend_Test_WP::set_filter(
			'agend_apps_crm_get_my_entitlements_response',
			array( 'data' => array( 'is_member' => true, 'tier_ids' => array() ) )
		);
		$this->assertFalse( $this->allows( $this->rules( array( 'agend_membership:is_member' ) ) ), 'the flag with no plan' );

		$this->newVisitor();
		Agend_Test_WP::set_filter(
			'agend_apps_crm_get_my_entitlements_response',
			array( 'data' => array( 'is_member' => false, 'tier_ids' => array( self::TIER_A ) ) )
		);
		$this->assertFalse( $this->allows( $this->rules( array( 'agend_membership:is_member' ) ) ), 'a plan with no flag' );
	}

	#[Test]
	public function the_standing_is_resolved_once_per_request(): void {
		$this->signedIn( array( self::TIER_A ) );

		$this->allows( $this->rules( array( 'agend_membership:is_member' ) ) );
		$before = count( Agend_Test_WP::$requests );
		$this->allows( $this->rules( array( 'agend_membership:is_member' ) ) );

		$this->assertSame( $before, count( Agend_Test_WP::$requests ) );
	}

	// -----------------------------------------------------------------------
	// The providers
	// -----------------------------------------------------------------------

	#[Test]
	public function a_plan_rule_matches_by_id_and_by_slug(): void {
		$this->signedIn( array( self::TIER_A ) );

		$this->assertTrue( $this->allows( $this->rules( array( 'agend_membership:' . self::TIER_A ) ) ), 'by id' );
		$this->assertTrue( $this->allows( $this->rules( array( 'agend_membership:slug-1111' ) ) ), 'by slug, for stored ESAC data' );
		$this->assertFalse( $this->allows( $this->rules( array( 'agend_membership:' . self::TIER_B ) ) ) );
	}

	/** A value shaped like an id is an id: a tier whose slug equals another tier's id does not admit its holders to it. */
	#[Test]
	public function a_plan_id_is_never_matched_against_a_slug(): void {
		$this->signedIn( array( self::TIER_A ) );
		Agend_Test_WP::set_filter(
			'agend_apps_crm_get_my_entitlements_response',
			array( 'data' => array( 'is_member' => true, 'tier_ids' => array( self::TIER_A ), 'tier_slugs' => array( self::TIER_B ) ) )
		);

		$this->assertFalse( $this->allows( $this->rules( array( 'agend_membership:' . self::TIER_B ) ) ) );
	}

	#[Test]
	public function a_segment_rule_matches_by_id_and_by_slug_and_never_across(): void {
		$this->signedIn( array( self::TIER_A ), array(), array( self::SEG_A ) );

		$this->assertTrue( $this->allows( $this->rules( array( 'agend_segments:' . self::SEG_A ) ) ), 'by id' );
		$this->assertTrue( $this->allows( $this->rules( array( 'agend_segments:slug-aaaa' ) ) ), 'by slug, for stored ESAC data' );
		$this->assertFalse( $this->allows( $this->rules( array( 'agend_segments:' . self::SEG_B ) ) ) );

		$this->newVisitor();
		Agend_Test_WP::set_filter(
			'agend_apps_crm_get_my_segments_response',
			array( 'data' => array( array( 'id' => self::SEG_A, 'slug' => self::SEG_B ) ) )
		);
		$this->assertFalse( $this->allows( $this->rules( array( 'agend_segments:' . self::SEG_B ) ) ), 'a slug shaped like another segment\'s id is not that segment' );
	}

	#[Test]
	public function an_entitlement_rule_matches_by_gate_key(): void {
		$this->signedIn( array(), array( self::ENT_A ) );

		$this->assertTrue( $this->allows( $this->rules( array( 'agend_entitlements:' . self::ENT_A ) ) ), 'held with no membership at all' );
		$this->assertFalse( $this->allows( $this->rules( array( 'agend_entitlements:' . self::ENT_B ) ) ) );
	}

	#[Test]
	public function wordpress_rules_read_the_login_and_the_roles_and_nothing_else(): void {
		$this->assertTrue( $this->allows( $this->rules( array( 'agend_wordpress:logged_out' ) ) ) );
		$this->assertFalse( $this->allows( $this->rules( array( 'agend_wordpress:logged_in' ) ) ) );

		$GLOBALS['agend_test_current_user_id']    = 9;
		$GLOBALS['agend_test_current_user_roles'] = array( 'editor' );

		$this->assertTrue( $this->allows( $this->rules( array( 'agend_wordpress:logged_in' ) ) ) );
		$this->assertTrue( $this->allows( $this->rules( array( 'agend_wordpress:role_editor' ) ) ) );
		$this->assertFalse( $this->allows( $this->rules( array( 'agend_wordpress:role_administrator' ) ) ) );
		$this->assertSame( array(), Agend_Test_WP::$requests, 'a WordPress rule is answered locally' );
	}

	#[Test]
	public function a_provider_a_site_registers_is_answered_by_the_site(): void {
		Agend_Test_WP::$filters['agend_content_access_check_condition'] =
			static fn( $result, $provider, $value ) => 'company' === $provider ? 'acme' === $value : $result;

		$this->assertTrue( $this->allows( $this->rules( array( 'company:acme' ) ) ) );
		$this->assertFalse( $this->allows( $this->rules( array( 'company:other' ) ) ) );
	}

	// -----------------------------------------------------------------------
	// Match and reverse
	// -----------------------------------------------------------------------

	#[Test]
	public function match_any_needs_one_rule_and_match_all_needs_every_rule(): void {
		$this->signedIn( array( self::TIER_A ), array( self::ENT_A ) );

		$both    = array( 'agend_entitlements:' . self::ENT_A, 'agend_entitlements:' . self::ENT_B );
		$neither = array( 'agend_entitlements:' . self::ENT_B, 'agend_segments:' . self::SEG_B );

		$this->assertTrue( $this->allows( $this->rules( $both, 'any' ) ) );
		$this->assertFalse( $this->allows( $this->rules( $both, 'all' ) ) );
		$this->assertFalse( $this->allows( $this->rules( $neither, 'any' ) ) );
		$this->assertTrue( $this->allows( $this->rules( array( 'agend_entitlements:' . self::ENT_A, 'agend_membership:is_member' ), 'all' ) ) );
	}

	/** A join banner: shown to people who are not members, hidden from members. */
	#[Test]
	public function reversing_the_result_flips_the_outcome(): void {
		$banner = $this->rules( array( 'agend_membership:is_member' ), 'any', true );

		$this->assertTrue( $this->allows( $banner ), 'a visitor with no session is not a member' );

		$this->newVisitor();
		$this->signedIn( array( self::TIER_A ) );
		$this->assertFalse( $this->allows( $banner ), 'a member is not shown the banner' );
	}

	/** The reverse of nothing is nobody: what the engine has always read it as. */
	#[Test]
	public function a_reverse_over_no_rules_denies_everyone(): void {
		$rules = Rules::from_array( array( 'rules' => array(), 'invert' => true ) );

		$this->assertFalse( $rules->is_empty() );
		$this->assertFalse( $this->allows( $rules ) );

		$this->signedIn( array( self::TIER_A ) );
		$this->assertFalse( $this->allows( $rules ) );
	}

	// -----------------------------------------------------------------------
	// Every uncertainty denies, and a reverse does not turn it into a grant
	// -----------------------------------------------------------------------

	/** @return array<string, array{0: bool}> */
	public static function reverseOrNot(): array {
		return array(
			'as written' => array( false ),
			'reversed'   => array( true ),
		);
	}

	#[Test]
	#[DataProvider( 'reverseOrNot' )]
	public function an_unknown_provider_denies( bool $invert ): void {
		$verdict = Access::verdict( $this->rules( array( 'plugin_gone:whatever' ), 'any', $invert ) );

		$this->assertFalse( $verdict['allowed'] );
		$this->assertTrue( $verdict['degraded'] );
		$this->assertSame( array( 'plugin_gone:whatever' ), $verdict['unknown'] );
	}

	#[Test]
	#[DataProvider( 'reverseOrNot' )]
	public function an_unknown_provider_denies_beside_rules_that_would_pass( bool $invert ): void {
		$this->signedIn( array( self::TIER_A ) );

		foreach ( array( 'any', 'all' ) as $match ) {
			$this->assertFalse(
				$this->allows( $this->rules( array( 'agend_membership:is_member', 'plugin_gone:whatever' ), $match, $invert ) ),
				"match {$match}: a rule that cannot be evaluated is a different rule"
			);
		}
	}

	#[Test]
	#[DataProvider( 'reverseOrNot' )]
	public function a_malformed_rule_denies( bool $invert ): void {
		$this->signedIn( array( self::TIER_A ) );

		$this->assertFalse( $this->allows( $this->rules( array( 'agend_membership:is_member', 'no_colon_at_all' ), 'any', $invert ) ) );
		$this->assertFalse( $this->allows( $this->rules( array( 'agend_membership:' ), 'any', $invert ) ) );
		$this->assertFalse( $this->allows( $this->rules( array( ':is_member' ), 'any', $invert ) ) );
	}

	/** A value a provider does not define is unknown, not false: a typo in a role must not read as "lacks it". */
	#[Test]
	#[DataProvider( 'reverseOrNot' )]
	public function a_value_a_built_in_provider_does_not_define_denies( bool $invert ): void {
		$this->assertFalse( $this->allows( $this->rules( array( 'agend_wordpress:logged_on' ), 'any', $invert ) ) );
	}

	/** The load-bearing case: an outage reversed must not disclose. */
	#[Test]
	#[DataProvider( 'reverseOrNot' )]
	public function a_lookup_failure_denies( bool $invert ): void {
		$this->signedIn( array( self::TIER_A ), array( self::ENT_A ), array( self::SEG_A ) );
		Agend_Test_WP::set_filter( 'agend_apps_crm_get_my_entitlements_response', new WP_Error( 'down', 'Gateway unreachable' ) );
		Agend_Test_WP::set_filter( 'agend_apps_crm_get_my_segments_response', new WP_Error( 'down', 'Gateway unreachable' ) );

		foreach ( array( 'agend_membership:is_member', 'agend_membership:' . self::TIER_A, 'agend_entitlements:' . self::ENT_A, 'agend_segments:' . self::SEG_A ) as $rule ) {
			$this->newVisitor();

			$this->assertFalse( $this->allows( $this->rules( array( $rule ), 'any', $invert ) ), $rule );
		}
	}

	#[Test]
	#[DataProvider( 'reverseOrNot' )]
	public function a_malformed_gateway_answer_denies( bool $invert ): void {
		$this->signedIn();

		foreach ( array( array(), array( 'success' => false, 'data' => array( 'tier_ids' => array() ) ), array( 'data' => 'ok' ), array( 'data' => array( 'is_member' => true ) ) ) as $body ) {
			$this->newVisitor();
			Agend_Test_WP::set_filter( 'agend_apps_crm_get_my_entitlements_response', $body );

			$this->assertFalse( $this->allows( $this->rules( array( 'agend_membership:is_member' ), 'any', $invert ) ), (string) wp_json_encode( $body ) );
		}
	}

	#[Test]
	public function rules_that_cannot_be_read_deny_everyone(): void {
		$unreadable = Rules::unreadable();

		$this->assertFalse( $this->allows( $unreadable ) );

		$this->signedIn( array( self::TIER_A, self::TIER_B ), array( self::ENT_A ), array( self::SEG_A ) );
		$this->assertFalse( $this->allows( $unreadable ), 'a member holding everything' );
		$this->assertFalse( $this->allows( $unreadable, array( 'anonymous' => true ) ) );

		foreach ( array( 'rules' => 'agend_membership:is_member', 'match' => 'some', 'invert' => 'yes' ) as $key => $value ) {
			$this->assertFalse( $this->allows( Rules::from_array( array( $key => $value ) ) ), "a {$key} that is not " . ( 'rules' === $key ? 'a list' : 'readable' ) );
		}
	}

	#[Test]
	#[DataProvider( 'reverseOrNot' )]
	public function a_provider_that_throws_denies( bool $invert ): void {
		Agend_Test_WP::$filters['agend_content_access_check_condition'] = static function (): bool {
			throw new RuntimeException( 'the site\'s provider broke' );
		};

		$verdict = Access::verdict( $this->rules( array( 'company:acme' ), 'any', $invert ) );

		$this->assertFalse( $verdict['allowed'] );
		$this->assertTrue( $verdict['degraded'] );
		$this->assertNotSame( Decision::STATE_GRANTED, $verdict['state'] );
	}

	/** A provider that answers with anything but a boolean has not answered. */
	#[Test]
	#[DataProvider( 'reverseOrNot' )]
	public function a_provider_that_does_not_answer_with_a_boolean_denies( bool $invert ): void {
		Agend_Test_WP::$filters['agend_content_access_check_condition'] = static fn( $result, $provider, $value ) => 1;

		$this->assertFalse( $this->allows( $this->rules( array( 'company:acme' ), 'any', $invert ) ) );
	}

	/** A rule set with no rules is the one thing that grants without asking anybody anything. */
	#[Test]
	public function no_rules_grants_without_any_lookup(): void {
		$verdict = Access::verdict( Rules::none() );

		$this->assertTrue( $verdict['allowed'] );
		$this->assertSame( Decision::STATE_GRANTED, $verdict['state'] );
		$this->assertFalse( $verdict['degraded'] );
		$this->assertSame( 0, $this->identity_lookups );
		$this->assertSame( array(), Agend_Test_WP::$requests );
	}

	// -----------------------------------------------------------------------
	// The intersection
	// -----------------------------------------------------------------------

	/** Both parts have to pass: that is what keeps a block from widening its page. */
	#[Test]
	public function a_composition_needs_every_part(): void {
		$page  = $this->rules( array( 'agend_membership:' . self::TIER_B ) );
		$block = $this->rules( array( 'agend_membership:' . self::TIER_A ) );
		$both  = Rules::all_of( $page, $block );

		$this->signedIn( array( self::TIER_A ) );
		$this->assertTrue( $this->allows( $block ) );
		$this->assertFalse( $this->allows( $page ) );
		$this->assertFalse( $this->allows( $both ), 'the block alone would admit them; the page does not' );

		$this->newVisitor();
		$this->signedIn( array( self::TIER_B ) );
		$this->assertFalse( $this->allows( $both ), 'the page alone would admit them; the block does not' );

		$this->newVisitor();
		$this->signedIn( array( self::TIER_A, self::TIER_B ) );
		$this->assertTrue( $this->allows( $both ) );
	}

	#[Test]
	public function a_composition_with_an_unreadable_part_denies_everyone(): void {
		$this->signedIn( array( self::TIER_A ) );

		$this->assertFalse( $this->allows( Rules::all_of( Rules::unreadable(), $this->rules( array( 'agend_membership:is_member' ) ) ) ) );
		$this->assertFalse( $this->allows( Rules::all_of( $this->rules( array( 'agend_membership:is_member' ) ), Rules::unreadable() ) ) );
	}

	#[Test]
	public function a_part_with_no_rules_adds_nothing(): void {
		$this->signedIn( array( self::TIER_A ) );

		$this->assertTrue( $this->allows( Rules::all_of( Rules::none(), $this->rules( array( 'agend_membership:is_member' ) ) ) ) );
		$this->assertFalse( $this->allows( Rules::all_of( Rules::none(), $this->rules( array( 'agend_membership:' . self::TIER_B ) ) ) ) );
	}

	// -----------------------------------------------------------------------
	// A feed is judged for nobody
	// -----------------------------------------------------------------------

	/** Feeds never look identity up: no bearer, no gateway call, and the visitor's own session cannot change the answer. */
	#[Test]
	public function the_anonymous_option_makes_no_identity_lookup(): void {
		$this->signedIn( array( self::TIER_A ), array( self::ENT_A ), array( self::SEG_A ) );
		Agend_Test_WP::$filters['agend_content_access_segment_facts'] = static function (): array {
			return array( 'slug-aaaa' );
		};

		$member = $this->rules( array( 'agend_membership:is_member', 'agend_membership:' . self::TIER_A, 'agend_entitlements:' . self::ENT_A, 'agend_segments:' . self::SEG_A, 'agend_segments:slug-aaaa' ), 'any' );

		$verdict = Access::verdict( $member, array( 'anonymous' => true ) );

		$this->assertFalse( $verdict['allowed'], 'a feed is judged for nobody, however signed in the fetcher is' );
		$this->assertSame( Decision::STATE_AUTH, $verdict['state'] );
		$this->assertSame( 0, $this->identity_lookups, 'no bearer was asked for' );
		$this->assertSame( array(), Agend_Test_WP::$requests, 'no gateway call' );
	}

	/** Nobody holds nothing, so a reversed rule over what they do not hold is the one thing a feed can show. */
	#[Test]
	public function the_anonymous_reading_answers_every_family_definitively(): void {
		$this->signedIn( array( self::TIER_A ) );

		$options = array( 'anonymous' => true );

		$this->assertTrue( $this->allows( $this->rules( array( 'agend_membership:is_member' ), 'any', true ), $options ) );
		$this->assertTrue( $this->allows( $this->rules( array( 'agend_wordpress:logged_out' ) ), $options ) );
		$this->assertFalse( $this->allows( $this->rules( array( 'agend_wordpress:logged_in' ) ), $options ) );
		$this->assertTrue( $this->allows( $this->rules( array( 'agend_segments:' . self::SEG_A ), 'any', true ), $options ) );
		$this->assertTrue( $this->allows( $this->rules( array( 'agend_entitlements:' . self::ENT_A ), 'any', true ), $options ) );
		$this->assertSame( array(), Agend_Test_WP::$requests );
	}

	// -----------------------------------------------------------------------
	// The state of a denial
	// -----------------------------------------------------------------------

	/**
	 * Only the words shown to the person depend on this, never whether they are admitted.
	 *
	 * @return array<string, array{0: array, 1: string, 2: string}> Visitor, rules (JSON), expected state.
	 */
	public static function stateTable(): array {
		$member    = 'agend_membership:is_member';
		$plan_b    = 'agend_membership:' . self::TIER_B;
		$segment_b = 'agend_segments:' . self::SEG_B;
		$ent_b     = 'agend_entitlements:' . self::ENT_B;

		return array(
			'signed out, a membership rule'                  => array( array( 'who' => 'nobody' ), array( 'rules' => array( $member ) ), Decision::STATE_AUTH ),
			'signed out, a plan rule'                        => array( array( 'who' => 'nobody' ), array( 'rules' => array( $plan_b ) ), Decision::STATE_AUTH ),
			'signed out, a segment rule'                     => array( array( 'who' => 'nobody' ), array( 'rules' => array( $segment_b ) ), Decision::STATE_AUTH ),
			'signed out, an entitlement rule'                => array( array( 'who' => 'nobody' ), array( 'rules' => array( $ent_b ) ), Decision::STATE_AUTH ),
			'signed out, a role rule'                        => array( array( 'who' => 'nobody' ), array( 'rules' => array( 'agend_wordpress:role_editor' ) ), Decision::STATE_AUTH ),
			'signed out, a reversed rule'                    => array( array( 'who' => 'nobody' ), array( 'rules' => array( 'agend_wordpress:logged_out' ), 'invert' => true ), Decision::STATE_AUTH ),
			'a WordPress user with no member session'        => array( array( 'who' => 'wp_user' ), array( 'rules' => array( $member ) ), Decision::STATE_AUTH ),
			'a WordPress user failing a role rule'           => array( array( 'who' => 'wp_user' ), array( 'rules' => array( 'agend_wordpress:role_editor' ) ), Decision::STATE_RESTRICTED ),
			'signed in, not a member, a membership rule'     => array( array( 'who' => 'non_member' ), array( 'rules' => array( $member ) ), Decision::STATE_MEMBERSHIP ),
			'signed in, not a member, a plan rule'           => array( array( 'who' => 'non_member' ), array( 'rules' => array( $plan_b ) ), Decision::STATE_MEMBERSHIP ),
			'signed in, not a member, a plan or a segment'   => array( array( 'who' => 'non_member' ), array( 'rules' => array( $plan_b, $segment_b ) ), Decision::STATE_MEMBERSHIP ),
			'signed in, not a member, only a segment'        => array( array( 'who' => 'non_member' ), array( 'rules' => array( $segment_b ) ), Decision::STATE_RESTRICTED ),
			'signed in, not a member, only an entitlement'   => array( array( 'who' => 'non_member' ), array( 'rules' => array( $ent_b ) ), Decision::STATE_RESTRICTED ),
			'a member failing a plan rule'                   => array( array( 'who' => 'member' ), array( 'rules' => array( $plan_b ) ), Decision::STATE_PLAN ),
			'a member failing a plan or a segment'           => array( array( 'who' => 'member' ), array( 'rules' => array( $plan_b, $segment_b ) ), Decision::STATE_PLAN ),
			'a member failing a segment'                     => array( array( 'who' => 'member' ), array( 'rules' => array( $segment_b ) ), Decision::STATE_RESTRICTED ),
			'a member failing an entitlement'                => array( array( 'who' => 'member' ), array( 'rules' => array( $ent_b ) ), Decision::STATE_RESTRICTED ),
			'a member failing a role rule'                   => array( array( 'who' => 'member' ), array( 'rules' => array( 'agend_wordpress:role_editor' ) ), Decision::STATE_RESTRICTED ),
			'a member failing a reversed membership rule'    => array( array( 'who' => 'member' ), array( 'rules' => array( $member ), 'invert' => true ), Decision::STATE_RESTRICTED ),
			'a member failing a reversed plan rule'          => array( array( 'who' => 'member' ), array( 'rules' => array( 'agend_membership:' . self::TIER_A ), 'invert' => true ), Decision::STATE_RESTRICTED ),
			'a member failing all of a plan and a segment'   => array( array( 'who' => 'member' ), array( 'rules' => array( 'agend_membership:' . self::TIER_A, $segment_b ), 'match' => 'all' ), Decision::STATE_RESTRICTED ),
			'a member failing all of two plans'              => array( array( 'who' => 'member' ), array( 'rules' => array( 'agend_membership:' . self::TIER_A, $plan_b ), 'match' => 'all' ), Decision::STATE_PLAN ),
			'a member, an unknown provider'                  => array( array( 'who' => 'member' ), array( 'rules' => array( 'plugin_gone:x' ) ), Decision::STATE_RESTRICTED ),
			'signed out, an unknown provider'                => array( array( 'who' => 'nobody' ), array( 'rules' => array( 'plugin_gone:x' ) ), Decision::STATE_AUTH ),
		);
	}

	#[Test]
	#[DataProvider( 'stateTable' )]
	public function a_denial_gets_the_state_that_says_what_would_help( array $visitor, array $rules, string $expected ): void {
		switch ( $visitor['who'] ) {
			case 'wp_user':
				$GLOBALS['agend_test_current_user_id'] = 9;
				break;
			case 'non_member':
				$this->signedIn();
				break;
			case 'member':
				$this->signedIn( array( self::TIER_A ) );
				break;
		}

		$verdict = Access::verdict( Rules::from_array( $rules ) );

		$this->assertFalse( $verdict['allowed'] );
		$this->assertSame( $expected, $verdict['state'] );
	}

	/** The plan the page names is not what failed, so the member is not told to change plan. */
	#[Test]
	public function the_state_is_about_the_part_that_denied(): void {
		$this->signedIn( array( self::TIER_A ) );

		$page_plan_passes_block_segment_fails = Rules::all_of(
			$this->rules( array( 'agend_membership:' . self::TIER_A ) ),
			$this->rules( array( 'agend_segments:' . self::SEG_B ) )
		);

		$this->assertSame( Decision::STATE_RESTRICTED, $this->state( $page_plan_passes_block_segment_fails ) );
	}

	/** Whatever the visitor is, a feed is judged for nobody, who is asked to sign in. */
	#[Test]
	public function an_anonymous_reading_is_always_asked_to_sign_in(): void {
		$this->signedIn( array( self::TIER_A ) );

		$this->assertSame( Decision::STATE_AUTH, $this->state( $this->rules( array( 'agend_membership:' . self::TIER_B ) ), array( 'anonymous' => true ) ) );
		$this->assertSame( Decision::STATE_AUTH, $this->state( Rules::unreadable(), array( 'anonymous' => true ) ) );
	}

	#[Test]
	public function the_state_of_a_denial_never_names_what_a_segment_is(): void {
		$this->signedIn( array( self::TIER_A ) );

		$verdict = Access::verdict( $this->rules( array( 'agend_segments:' . self::SEG_B ) ) );

		$this->assertSame( Decision::STATE_RESTRICTED, $verdict['state'] );
		$this->assertStringNotContainsString( self::SEG_B, (string) wp_json_encode( array_diff_key( $verdict, array( 'unknown' => 1 ) ) ) );
	}
}
