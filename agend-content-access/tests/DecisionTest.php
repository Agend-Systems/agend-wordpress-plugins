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
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-fragment-rules.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-block-attributes.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-conditions.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-providers.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-runtime.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-rules.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-access.php';

/**
 * Who may read a document, as the page gate sees it.
 *
 * The page's rules are read from what it stored (`Rules::from_post()`) and judged by
 * the one evaluator (`AccessTest`), so what is tested here is the page-level part:
 * the enforced reading of a stored value, the authoring preview, the anonymous
 * reader a feed is, and the keys the decision returns. Every uncertainty resolves to
 * a denial, so the tests that matter most are the ones asserting that a failure does
 * NOT grant.
 *
 * The pure policy evaluation this class used to hold, and its test of the viewer it
 * built, moved to `AccessTest` with the evaluator that replaced them.
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

	private function segmentsPolicy( string ...$ids ): array {
		return array( 'mode' => 'segments', 'segment_ids' => $ids );
	}

	private function entitlementsPolicy( string ...$keys ): array {
		return array( 'mode' => 'selected_entitlements', 'entitlement_keys' => $keys );
	}

	/** Signs in a member with a bearer, which is what makes a visitor somebody to Agend. */
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

	/** Signs in an identified visitor holding the given plans, or none. */
	private function asMember( string ...$tiers ): void {
		$this->signedInMember( array( 'is_member' => array() !== $tiers, 'tier_ids' => $tiers ) );
	}

	/**
	 * Stored values this version cannot read: each is invalid under
	 * `Policy::parse()`. `segments` and `selected_entitlements` appear only in
	 * shapes it refuses, because a well formed one is readable and enforced.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function unreadablePolicies(): array {
		return array(
			'authenticated, a gateway mode this plugin lacks' => array( array( 'mode' => 'authenticated' ) ),
			'segments with nothing selected'                  => array( array( 'mode' => 'segments', 'segment_ids' => array() ) ),
			'a segment id that is not a uuid'                 => array(
				array( 'mode' => 'segments', 'segment_ids' => array( 'not-a-uuid' ) ),
			),
			'an entitlement key in upper case'                => array(
				array( 'mode' => 'selected_entitlements', 'entitlement_keys' => array( 'Journal' ) ),
			),
			'a plan id that is not a uuid'                    => array(
				array( 'mode' => 'selected_tiers', 'tier_ids' => array( 'not-a-uuid' ) ),
			),
			'an extra top level key'                          => array(
				array( 'mode' => 'active_member', 'note' => 'smuggled' ),
			),
		);
	}

	/**
	 * The defect. 0.1.0 read all of these as "no policy" and served the page to
	 * an anonymous visitor.
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

	/** The one-line shape of the hazard, kept separate so it reads as the headline case. */
	#[Test]
	public function a_segments_policy_that_cannot_be_read_does_not_make_the_post_public(): void {
		$this->storePolicy( array( 'mode' => 'segments', 'segment_ids' => array() ) );
		$this->asVisitor();

		$this->assertNotSame(
			Agend_Content_Access_Decision::STATE_GRANTED,
			Agend_Content_Access_Decision::for_post( self::POST_ID )['state']
		);
	}

	/**
	 * The enforced reading must not over-reach into the modes this version DOES
	 * read. A well formed segments policy is stored as itself, handed to the
	 * viewer so the visitor's segments are fetched, and judged as segments: it
	 * grants a member of the segment and withholds the page from everybody else.
	 * Reading it as unreadable would deny the very visitors it names.
	 */
	#[Test]
	public function a_readable_segments_policy_is_enforced_as_segments(): void {
		$policy = $this->segmentsPolicy( self::SEG_A );
		$this->storePolicy( $policy );
		$this->asVisitor();

		$anonymous = Agend_Content_Access_Decision::for_post( self::POST_ID );

		$this->assertSame( Agend_Content_Access_Decision::STATE_AUTH, $anonymous['state'] );
		$this->assertSame( $policy, $anonymous['policy'], 'the policy is the stored one, not the unsatisfiable stand-in' );
		$this->assertSame( 0, $this->segmentCalls(), 'an anonymous visitor costs no segment call' );

		$this->signedInMember();
		$this->segmentsAre( array( array( 'id' => self::SEG_A, 'slug' => 'vic' ) ) );
		Agend_Content_Access_Decision::reset_cache();

		$inside = Agend_Content_Access_Decision::for_post( self::POST_ID );

		$this->assertSame( Agend_Content_Access_Decision::STATE_GRANTED, $inside['state'], 'a member of the named segment is admitted' );
		$this->assertSame( $policy, $inside['policy'] );
		$this->assertSame( 1, $this->segmentCalls(), 'the viewer was built for the segments policy' );

		$this->segmentsAre( array( array( 'id' => self::SEG_B, 'slug' => 'trainees' ) ) );
		Agend_Test_WP::$transients = array();
		Agend_Content_Access_Decision::reset_cache();

		$this->assertSame(
			Agend_Content_Access_Decision::STATE_RESTRICTED,
			Agend_Content_Access_Decision::for_post( self::POST_ID )['state'],
			'a member outside the segment is restricted, not asked to join'
		);
	}

	#[Test]
	public function a_readable_entitlements_policy_is_enforced_as_entitlements(): void {
		$policy = $this->entitlementsPolicy( self::ENT_A );
		$this->storePolicy( $policy );
		$this->asVisitor();
		$this->signedInMember( array( 'capabilities' => array( array( 'gate_key' => self::ENT_A ) ) ) );

		$decision = Agend_Content_Access_Decision::for_post( self::POST_ID );

		$this->assertSame( Agend_Content_Access_Decision::STATE_GRANTED, $decision['state'] );
		$this->assertSame( $policy, $decision['policy'] );
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
	public function an_editor_is_judged_like_anybody_else_and_is_denied_an_unreadable_stored_policy(): void {
		$this->storePolicy( array( 'mode' => 'authenticated' ) );
		$GLOBALS['agend_test_current_user_can']['edit_post'] = true;

		$decision = Agend_Content_Access_Decision::for_post( self::POST_ID );

		$this->assertNotSame( Agend_Content_Access_Decision::STATE_GRANTED, $decision['state'] );
		$this->assertArrayNotHasKey( 'preview', $decision );
		$this->assertFalse( method_exists( Agend_Content_Access_Decision::class, 'can_preview' ), 'no capability grants a page' );
	}

}
