<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Decision;
use Agend_Content_Access_Elementor;
use Agend_Content_Access_Policy;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-policy.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-catalogue.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-meta-box.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-decision.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-frontend.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-elementor.php';

/**
 * Fragment policies on Elementor elements.
 *
 * These replace an experiment that failed OPEN: an enabled condition with no
 * rules, or a rule with a blank key, rendered the element to everyone. Most of
 * what follows asserts the opposite behaviour, because that is the regression
 * worth guarding.
 */
#[CoversClass( Agend_Content_Access_Elementor::class )]
final class ElementorFragmentTest extends TestCase {

	private const TIER_A = '11111111-1111-1111-1111-111111111111';
	private const TIER_B = '22222222-2222-2222-2222-222222222222';
	private const TIER_C = '33333333-3333-3333-3333-333333333333';

	private function tiers( string ...$ids ): array {
		return array( 'mode' => 'selected_tiers', 'tier_ids' => $ids );
	}

	// -----------------------------------------------------------------------
	// Reading a policy off element settings
	// -----------------------------------------------------------------------

	#[Test]
	public function an_element_with_no_setting_inherits_the_page(): void {
		$this->assertNull( Agend_Content_Access_Elementor::policy_from_settings( array() ) );
		$this->assertNull(
			Agend_Content_Access_Elementor::policy_from_settings(
				array( 'agend_access_mode' => 'inherit' )
			)
		);
	}

	#[Test]
	public function all_active_members_reads_as_a_members_policy(): void {
		$this->assertSame(
			array( 'mode' => 'active_member' ),
			Agend_Content_Access_Elementor::policy_from_settings(
				array( 'agend_access_mode' => 'active_member' )
			)
		);
	}

	#[Test]
	public function selected_plans_reads_the_chosen_tier_ids(): void {
		$policy = Agend_Content_Access_Elementor::policy_from_settings(
			array(
				'agend_access_mode'     => 'selected_tiers',
				'agend_access_tier_ids' => array( self::TIER_A, self::TIER_B ),
			)
		);

		$this->assertSame( array( self::TIER_A, self::TIER_B ), $policy['tier_ids'] );
	}

	/**
	 * The exact case the previous implementation got wrong. "Selected plans"
	 * with nothing selected is a restriction nobody satisfies, not an absent
	 * one.
	 */
	#[Test]
	public function selected_plans_with_nothing_selected_hides_the_element(): void {
		$policy = Agend_Content_Access_Elementor::policy_from_settings(
			array( 'agend_access_mode' => 'selected_tiers', 'agend_access_tier_ids' => array() )
		);

		$this->assertNotNull( $policy, 'an empty selection must not read as "no policy"' );
		$this->assertSame( array(), $policy['tier_ids'] );
		$this->assertNotSame(
			Agend_Content_Access_Decision::STATE_GRANTED,
			Agend_Content_Access_Decision::evaluate(
				$policy,
				array( 'identified' => true, 'tier_ids' => array( self::TIER_A ), 'degraded' => false )
			)
		);
	}

	#[Test]
	public function junk_tier_ids_are_discarded_and_do_not_grant(): void {
		$policy = Agend_Content_Access_Elementor::policy_from_settings(
			array(
				'agend_access_mode'     => 'selected_tiers',
				'agend_access_tier_ids' => array( 'not-a-uuid', '', self::TIER_A, self::TIER_A ),
			)
		);

		$this->assertSame( array( self::TIER_A ), $policy['tier_ids'] );
	}

	#[Test]
	public function an_unrecognised_mode_restricts_rather_than_being_ignored(): void {
		$policy = Agend_Content_Access_Elementor::policy_from_settings(
			array( 'agend_access_mode' => 'everyone' )
		);

		$this->assertSame( array( 'mode' => 'active_member' ), $policy );
	}

	// -----------------------------------------------------------------------
	// Intersection with the document policy
	// -----------------------------------------------------------------------

	#[Test]
	public function inherit_yields_the_document_policy(): void {
		$document = array( 'mode' => 'active_member' );

		$this->assertSame(
			$document,
			Agend_Content_Access_Policy::effective_policy( $document, null )
		);
	}

	#[Test]
	public function a_narrower_fragment_wins(): void {
		$this->assertSame(
			$this->tiers( self::TIER_A ),
			Agend_Content_Access_Policy::effective_policy(
				array( 'mode' => 'active_member' ),
				$this->tiers( self::TIER_A )
			)
		);
	}

	/** Decision 2.3: a section cannot open up a restricted page. */
	#[Test]
	#[DataProvider( 'broadeningAttempts' )]
	public function a_fragment_cannot_widen_the_document( array $document, array $fragment ): void {
		$this->assertSame(
			$document,
			Agend_Content_Access_Policy::effective_policy( $document, $fragment )
		);
	}

	public static function broadeningAttempts(): array {
		return array(
			'public section on a members page' => array(
				array( 'mode' => 'active_member' ),
				array( 'mode' => 'public' ),
			),
			'members section on a plans page' => array(
				array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A ) ),
				array( 'mode' => 'active_member' ),
			),
		);
	}

	#[Test]
	public function two_plan_policies_intersect(): void {
		$effective = Agend_Content_Access_Policy::effective_policy(
			$this->tiers( self::TIER_A, self::TIER_B ),
			$this->tiers( self::TIER_B, self::TIER_C )
		);

		$this->assertSame( array( self::TIER_B ), $effective['tier_ids'] );
	}

	/**
	 * Page allows only A, section allows only B. Nobody qualifies, including a
	 * member holding A. Resolving this to either tier would grant access
	 * neither policy allows.
	 */
	#[Test]
	public function a_disjoint_intersection_is_unsatisfiable(): void {
		$effective = Agend_Content_Access_Policy::effective_policy(
			$this->tiers( self::TIER_A ),
			$this->tiers( self::TIER_B )
		);

		$this->assertSame( array(), $effective['tier_ids'] );
		$this->assertNotSame(
			Agend_Content_Access_Decision::STATE_GRANTED,
			Agend_Content_Access_Decision::evaluate(
				$effective,
				array( 'identified' => true, 'tier_ids' => array( self::TIER_A ), 'degraded' => false )
			)
		);
	}

	#[Test]
	public function a_fragment_policy_on_an_unrestricted_page_simply_applies(): void {
		$this->assertSame(
			array( 'mode' => 'active_member' ),
			Agend_Content_Access_Policy::effective_policy( null, array( 'mode' => 'active_member' ) )
		);
	}

	// -----------------------------------------------------------------------
	// End to end: settings plus document policy plus viewer
	// -----------------------------------------------------------------------

	#[Test]
	public function a_qualifying_member_sees_a_restricted_section(): void {
		$fragment  = Agend_Content_Access_Elementor::policy_from_settings(
			array(
				'agend_access_mode'     => 'selected_tiers',
				'agend_access_tier_ids' => array( self::TIER_A ),
			)
		);
		$effective = Agend_Content_Access_Policy::effective_policy( null, $fragment );

		$this->assertSame(
			Agend_Content_Access_Decision::STATE_GRANTED,
			Agend_Content_Access_Decision::evaluate(
				$effective,
				array( 'identified' => true, 'tier_ids' => array( self::TIER_A ), 'degraded' => false )
			)
		);
	}

	#[Test]
	public function an_anonymous_visitor_does_not_see_a_restricted_section(): void {
		$fragment  = Agend_Content_Access_Elementor::policy_from_settings(
			array( 'agend_access_mode' => 'active_member' )
		);
		$effective = Agend_Content_Access_Policy::effective_policy( null, $fragment );

		$this->assertNotSame(
			Agend_Content_Access_Decision::STATE_GRANTED,
			Agend_Content_Access_Decision::evaluate(
				$effective,
				array( 'identified' => false, 'tier_ids' => array(), 'degraded' => false )
			)
		);
	}
}
