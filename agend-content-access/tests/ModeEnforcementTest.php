<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Decision as Decision;
use Agend_Content_Access_Elementor;
use Agend_Content_Access_Fragment_Rules as FragmentRules;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use WP_Error;

require_once __DIR__ . '/has-elementor-double.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-policy.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-fragment-rules.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-catalogue.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-meta-box.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-viewer-facts.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-decision.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-frontend.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-conditions.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-providers.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-runtime.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-elementor.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-block-attributes.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-rules.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-access.php';

/**
 * The three callers that decide for a visitor: a whole post, an Elementor
 * element and a replacement fragment.
 *
 * Each must hand `Decision::viewer()` the policy it is about to evaluate, or a
 * segments policy would be decided against a viewer that never loaded any
 * segments and deny a visitor it admits. The opposite failure matters as much:
 * none of them may fetch segments when the policy in hand does not need them,
 * because that is a gateway call on every protected page.
 */
#[CoversClass( Decision::class )]
#[CoversClass( Agend_Content_Access_Elementor::class )]
#[CoversClass( FragmentRules::class )]
final class ModeEnforcementTest extends TestCase {

	private const POST_ID = 4242;
	private const TIER_A  = '11111111-1111-1111-1111-111111111111';
	private const SEG_A   = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1';
	private const SEG_B   = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbb2';
	private const ENT_A   = 'directory.access';
	private const ENT_B   = 'journal';

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['agend_test_current_user_id']                 = 0;
		$GLOBALS['agend_test_posts']                           = array();
		$GLOBALS['agend_test_post_meta']                       = array();
		$GLOBALS['agend_test_current_user_can']['edit_post']   = false;
		Agend_Test_WP::$queried_object_id                      = self::POST_ID;
	}

	/** Signs in a member and answers the two gateway calls. */
	private function memberHolding( array $segment_ids = array(), array $entitlement_keys = array() ): void {
		$GLOBALS['agend_test_current_user_id'] = 7;

		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'member-token' );
		Agend_Test_WP::set_filter(
			'agend_apps_crm_get_my_entitlements_response',
			array(
				'data' => array(
					'is_member'    => true,
					'tier_ids'     => array( self::TIER_A ),
					'capabilities' => array_map(
						static fn( string $key ): array => array( 'gate_key' => $key ),
						$entitlement_keys
					),
				),
			)
		);
		Agend_Test_WP::set_filter(
			'agend_apps_crm_get_my_segments_response',
			array(
				'data' => array_map(
					static fn( string $id ): array => array( 'id' => $id, 'slug' => 'slug-' . substr( $id, 0, 4 ) ),
					$segment_ids
				),
			)
		);
	}

	/**
	 * Starts over as a different visitor on the same site.
	 *
	 * The memos go AND the per-member transients with them. Both are right in
	 * production, where one request has one visitor, and both would otherwise
	 * carry the first visitor's answer into the second. The recorded gateway
	 * requests are kept, so a count at the end of a test covers every visitor.
	 */
	private function newVisitor(): void {
		Decision::reset_cache();
		Agend_Test_WP::$transients = array();
	}

	private function segmentCalls(): int {
		return count(
			array_filter(
				Agend_Test_WP::$requests,
				static fn( array $request ): bool => '/crm/me/segments' === $request['url']
			)
		);
	}

	private function documentPolicy( ?array $policy ): void {
		if ( null !== $policy ) {
			$GLOBALS['agend_test_post_meta'][ self::POST_ID ]['_agend_access_policy'] = $policy;
		}
	}

	private function segmentsPolicy( string ...$ids ): array {
		return array( 'mode' => 'segments', 'segment_ids' => $ids );
	}

	// -----------------------------------------------------------------------
	// A whole post
	// -----------------------------------------------------------------------

	#[Test]
	public function a_post_restricted_to_a_segment_admits_a_member_of_it(): void {
		$this->documentPolicy( $this->segmentsPolicy( self::SEG_A ) );
		$this->memberHolding( array( self::SEG_A ) );

		$decision = Decision::for_post( self::POST_ID );

		$this->assertSame( Decision::STATE_GRANTED, $decision['state'] );
		$this->assertFalse( $decision['degraded'] );
		$this->assertSame( 1, $this->segmentCalls() );
	}

	#[Test]
	public function a_post_restricted_to_a_segment_turns_away_an_identified_visitor_outside_it(): void {
		$this->documentPolicy( $this->segmentsPolicy( self::SEG_A ) );
		$this->memberHolding( array( self::SEG_B ) );

		$this->assertSame( Decision::STATE_RESTRICTED, Decision::for_post( self::POST_ID )['state'] );
	}

	#[Test]
	public function a_post_restricted_to_a_segment_asks_an_anonymous_visitor_to_sign_in_without_a_call(): void {
		$this->documentPolicy( $this->segmentsPolicy( self::SEG_A ) );

		$this->assertSame( Decision::STATE_AUTH, Decision::for_post( self::POST_ID )['state'] );
		$this->assertSame( array(), Agend_Test_WP::$requests );
	}

	#[Test]
	public function a_segment_outage_on_a_post_denies_and_reports_degraded(): void {
		$this->documentPolicy( $this->segmentsPolicy( self::SEG_A ) );
		$this->memberHolding( array( self::SEG_A ) );
		Agend_Test_WP::set_filter( 'agend_apps_crm_get_my_segments_response', new WP_Error( 'down', 'Gateway unreachable' ) );

		$decision = Decision::for_post( self::POST_ID );

		$this->assertSame( Decision::STATE_RESTRICTED, $decision['state'] );
		$this->assertTrue( $decision['degraded'] );
	}

	#[Test]
	public function a_post_restricted_to_an_entitlement_admits_a_holder_with_no_segment_call(): void {
		$this->documentPolicy( array( 'mode' => 'selected_entitlements', 'entitlement_keys' => array( self::ENT_A ) ) );
		$this->memberHolding( array(), array( self::ENT_A ) );

		$this->assertSame( Decision::STATE_GRANTED, Decision::for_post( self::POST_ID )['state'] );
		$this->assertSame( 0, $this->segmentCalls() );
	}

	#[Test]
	public function a_post_restricted_to_an_entitlement_turns_away_a_member_without_it(): void {
		$this->documentPolicy( array( 'mode' => 'selected_entitlements', 'entitlement_keys' => array( self::ENT_A ) ) );
		$this->memberHolding( array(), array( self::ENT_B ) );

		$this->assertSame( Decision::STATE_RESTRICTED, Decision::for_post( self::POST_ID )['state'] );
	}

	/** Public and unset documents never even look up the visitor. */
	#[Test]
	public function a_public_or_unrestricted_post_makes_no_call_at_all(): void {
		$this->memberHolding( array( self::SEG_A ) );

		$this->documentPolicy( array( 'mode' => 'public' ) );
		$this->assertSame( Decision::STATE_GRANTED, Decision::for_post( self::POST_ID )['state'] );

		$GLOBALS['agend_test_post_meta'] = array();
		$this->assertSame( Decision::STATE_GRANTED, Decision::for_post( self::POST_ID )['state'] );

		$this->assertSame( array(), Agend_Test_WP::$requests );
	}

	#[Test]
	public function a_plan_or_members_post_never_fetches_segments(): void {
		$this->memberHolding( array( self::SEG_A ) );

		foreach ( array( array( 'mode' => 'active_member' ), array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A ) ) ) as $policy ) {
			$GLOBALS['agend_test_post_meta'] = array();
			$this->documentPolicy( $policy );
			$this->newVisitor();

			$this->assertSame( Decision::STATE_GRANTED, Decision::for_post( self::POST_ID )['state'] );
		}

		$this->assertSame( 0, $this->segmentCalls() );
	}

	#[Test]
	public function an_editor_previewing_a_segments_post_costs_no_call(): void {
		$GLOBALS['agend_test_current_user_can']['edit_post'] = true;
		$this->documentPolicy( $this->segmentsPolicy( self::SEG_A ) );

		$decision = Decision::for_post( self::POST_ID );

		$this->assertSame( Decision::STATE_GRANTED, $decision['state'] );
		$this->assertTrue( $decision['preview'] );
		$this->assertSame( array(), Agend_Test_WP::$requests );
	}

	// -----------------------------------------------------------------------
	// An Elementor element
	// -----------------------------------------------------------------------

	/** An object with the one method `maybe_suppress()` reads from an element. */
	private function element( array $settings ): object {
		$element = new class() {
			public array $settings = array();

			public function get_settings_for_display(): array {
				return $this->settings;
			}
		};

		$element->settings = $settings;

		return $element;
	}

	private function shows( array $settings ): bool {
		return (bool) ( new Agend_Content_Access_Elementor() )->maybe_suppress( true, $this->element( $settings ) );
	}

	private function segmentsElement( string ...$ids ): array {
		return array( 'agend_access_mode' => 'segments', 'agend_access_segment_ids' => $ids );
	}

	#[Test]
	public function an_element_restricted_to_a_segment_shows_to_a_member_of_it_and_hides_from_others(): void {
		$this->memberHolding( array( self::SEG_A ) );
		$this->assertTrue( $this->shows( $this->segmentsElement( self::SEG_A ) ) );

		$this->newVisitor();
		$this->memberHolding( array( self::SEG_B ) );
		$this->assertFalse( $this->shows( $this->segmentsElement( self::SEG_A ) ) );
	}

	#[Test]
	public function an_element_restricted_to_a_segment_hides_from_a_signed_out_visitor_without_a_call(): void {
		$this->assertFalse( $this->shows( $this->segmentsElement( self::SEG_A ) ) );
		$this->assertSame( array(), Agend_Test_WP::$requests );
	}

	/** Thirty segment-restricted elements on a page are one segments call. */
	#[Test]
	public function many_segment_elements_on_one_page_cost_one_segments_call(): void {
		$this->memberHolding( array( self::SEG_A ) );

		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertTrue( $this->shows( $this->segmentsElement( self::SEG_A ) ) );
		}

		$this->assertSame( 1, $this->segmentCalls() );
	}

	#[Test]
	public function an_element_restricted_to_an_entitlement_follows_the_visitors_entitlements(): void {
		$settings = array( 'agend_access_mode' => 'selected_entitlements', 'agend_access_entitlement_keys' => array( self::ENT_A ) );

		$this->memberHolding( array(), array( self::ENT_A ) );
		$this->assertTrue( $this->shows( $settings ) );

		$this->newVisitor();
		$this->memberHolding( array(), array( self::ENT_B ) );
		$this->assertFalse( $this->shows( $settings ) );

		$this->assertSame( 0, $this->segmentCalls() );
	}

	/**
	 * Changed from "a plan element on a segments page is unintersectable and hides from everyone".
	 *
	 * The element is shown when ITS rules allow AND the page's rules allow, so it admits exactly
	 * the people who satisfy both. The gateway's fold of two unlike sets to nobody was never needed
	 * to keep an element from widening its page, and it is still what the EXPORT says (see
	 * `RulesTest`): Agend serves such an element to nobody, which is narrower than this, never wider.
	 */
	#[Test]
	public function a_plan_element_on_a_segments_page_admits_only_who_satisfies_both(): void {
		$plan = array( 'agend_access_mode' => 'selected_tiers', 'agend_access_tier_ids' => array( self::TIER_A ) );

		$this->documentPolicy( $this->segmentsPolicy( self::SEG_A ) );
		$this->memberHolding( array( self::SEG_A ) );

		$this->assertTrue( $this->shows( $plan ), 'in the segment AND on the plan' );

		$this->newVisitor();
		$this->memberHolding( array( self::SEG_B ) );

		$this->assertFalse( $this->shows( $plan ), 'on the plan, but outside the page\'s segment' );

		$this->newVisitor();
		$GLOBALS['agend_test_current_user_id'] = 0;
		Agend_Test_WP::$filters                = array();

		$this->assertFalse( $this->shows( $plan ), 'nobody at all' );
	}

	#[Test]
	public function a_segment_element_on_a_segments_page_needs_both_lists(): void {
		$this->documentPolicy( $this->segmentsPolicy( self::SEG_A, self::SEG_B ) );

		$this->memberHolding( array( self::SEG_A ) );
		$this->assertFalse( $this->shows( $this->segmentsElement( self::SEG_B ) ), 'in A only, the element names B' );

		$this->newVisitor();
		$this->memberHolding( array( self::SEG_B ) );
		$this->assertTrue( $this->shows( $this->segmentsElement( self::SEG_B ) ) );
	}

	#[Test]
	public function an_element_whose_segments_outside_the_page_are_named_hides_even_from_those_members(): void {
		$this->documentPolicy( $this->segmentsPolicy( self::SEG_A ) );
		$this->memberHolding( array( self::SEG_B ) );

		// Document [A], fragment [B]: a member of B alone fails the page's rule.
		$this->assertFalse( $this->shows( $this->segmentsElement( self::SEG_B ) ) );
		$this->assertLessThanOrEqual( 1, $this->segmentCalls(), 'however many rules ask, the visitor\'s segments are fetched once' );
	}

	#[Test]
	public function an_element_that_inherits_makes_no_segment_call(): void {
		$this->documentPolicy( $this->segmentsPolicy( self::SEG_A ) );
		$this->memberHolding( array( self::SEG_A ) );

		$this->assertTrue( $this->shows( array() ) );
		$this->assertSame( 0, $this->segmentCalls() );
	}

	// -----------------------------------------------------------------------
	// Replacement content
	// -----------------------------------------------------------------------

	private function publishFallback( ?array $policy ): void {
		$GLOBALS['agend_test_posts'][ self::POST_ID ] = (object) array(
			'ID'           => self::POST_ID,
			'post_status'  => 'publish',
			'post_content' => 'SEGMENT-ONLY-CONTENT',
			'post_title'   => 'Fallback',
			'post_type'    => 'page',
		);

		$this->documentPolicy( $policy );
	}

	#[Test]
	public function a_segment_restricted_fallback_renders_for_a_member_of_that_segment_only(): void {
		$this->publishFallback( $this->segmentsPolicy( self::SEG_A ) );

		$this->memberHolding( array( self::SEG_A ) );
		$this->assertStringContainsString( 'SEGMENT-ONLY-CONTENT', FragmentRules::fallback_html( self::POST_ID ) );

		$this->newVisitor();
		$this->memberHolding( array( self::SEG_B ) );
		$this->assertSame( '', FragmentRules::fallback_html( self::POST_ID ) );
	}

	#[Test]
	public function a_segment_restricted_fallback_is_withheld_from_a_signed_out_visitor_without_a_call(): void {
		$this->publishFallback( $this->segmentsPolicy( self::SEG_A ) );

		$this->assertSame( '', FragmentRules::fallback_html( self::POST_ID ) );
		$this->assertSame( array(), Agend_Test_WP::$requests );
	}

	#[Test]
	public function an_entitlement_restricted_fallback_follows_the_visitors_entitlements(): void {
		$this->publishFallback( array( 'mode' => 'selected_entitlements', 'entitlement_keys' => array( self::ENT_A ) ) );

		$this->memberHolding( array(), array( self::ENT_A ) );
		$this->assertStringContainsString( 'SEGMENT-ONLY-CONTENT', FragmentRules::fallback_html( self::POST_ID ) );

		$this->newVisitor();
		$this->memberHolding( array(), array( self::ENT_B ) );
		$this->assertSame( '', FragmentRules::fallback_html( self::POST_ID ) );
		$this->assertSame( 0, $this->segmentCalls() );
	}

	#[Test]
	public function a_plan_fallback_makes_no_segment_call(): void {
		$this->publishFallback( array( 'mode' => 'active_member' ) );
		$this->memberHolding( array( self::SEG_A ) );

		$this->assertStringContainsString( 'SEGMENT-ONLY-CONTENT', FragmentRules::fallback_html( self::POST_ID ) );
		$this->assertSame( 0, $this->segmentCalls() );
	}
}
