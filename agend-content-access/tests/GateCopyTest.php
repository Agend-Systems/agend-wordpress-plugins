<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Catalogue;
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
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-viewer-facts.php';
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

	// -----------------------------------------------------------------------
	// access_restricted: segments and selected entitlements
	// -----------------------------------------------------------------------

	private const SEG_A = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1';

	private function segmentsPolicy(): array {
		return array( 'mode' => 'segments', 'segment_ids' => array( self::SEG_A ) );
	}

	private function entitlementsPolicy(): array {
		return array( 'mode' => 'selected_entitlements', 'entitlement_keys' => array( 'directory.access' ) );
	}

	#[Test]
	public function the_restricted_gate_says_the_content_is_limited_to_selected_members(): void {
		$html = Agend_Content_Access_Frontend::render_gate(
			'',
			Agend_Content_Access_Decision::STATE_RESTRICTED,
			$this->segmentsPolicy()
		);

		$this->assertStringContainsString( 'limited to selected members', $html );
		$this->assertStringNotContainsString( 'sign in', strtolower( $html ) );
		$this->assertStringNotContainsString( 'membership is required', $html );
	}

	/**
	 * A segment's name can describe the visitor ("Overdue renewals"), so the gate
	 * must not put a segment's name, or its id, on the page.
	 */
	#[Test]
	public function the_restricted_gate_names_no_segment(): void {
		// A catalogue that would happily name a plan, to show the gate is not
		// reading the policy or any catalogue for this state.
		$this->seedCatalogue();

		$html = Agend_Content_Access_Frontend::render_gate(
			'',
			Agend_Content_Access_Decision::STATE_RESTRICTED,
			$this->segmentsPolicy()
		);

		$this->assertStringNotContainsString( self::SEG_A, $html );
		$this->assertStringNotContainsString( 'Full Member', $html );
		// The decisive assertion: whatever segment the policy names, the markup
		// is identical to the one rendered with no policy at all, so there is
		// no path by which a name or id can reach the page.
		$this->assertSame(
			$html,
			Agend_Content_Access_Frontend::render_gate( '', Agend_Content_Access_Decision::STATE_RESTRICTED, null ),
			'the policy does not change the copy at all'
		);
	}

	#[Test]
	public function the_restricted_gate_for_an_entitlement_names_no_entitlement_key(): void {
		$html = Agend_Content_Access_Frontend::render_gate(
			'',
			Agend_Content_Access_Decision::STATE_RESTRICTED,
			$this->entitlementsPolicy()
		);

		$this->assertStringNotContainsString( 'directory.access', $html );
	}

	/** There is nothing to join or upgrade into, so no link, even with a portal configured. */
	#[Test]
	public function the_restricted_gate_has_no_call_to_action_while_the_others_do(): void {
		update_option( 'agend_apps_portal_url', 'https://portal.example.test/' );

		$restricted = Agend_Content_Access_Frontend::render_gate( '', Agend_Content_Access_Decision::STATE_RESTRICTED, null );

		$this->assertStringNotContainsString( 'agend-access-gate-cta', $restricted );
		$this->assertStringNotContainsString( 'portal.example.test', $restricted );
		$this->assertStringNotContainsString( '<a ', $restricted );

		foreach ( array( Agend_Content_Access_Decision::STATE_AUTH, Agend_Content_Access_Decision::STATE_MEMBERSHIP, Agend_Content_Access_Decision::STATE_PLAN ) as $state ) {
			$this->assertStringContainsString(
				'agend-access-gate-cta',
				Agend_Content_Access_Frontend::render_gate( '', $state, null ),
				$state . ' keeps its call to action'
			);
		}
	}

	#[Test]
	public function the_restricted_gate_still_shows_the_escaped_teaser(): void {
		$html = Agend_Content_Access_Frontend::render_gate(
			'Public teaser <b>text</b>',
			Agend_Content_Access_Decision::STATE_RESTRICTED,
			null
		);

		$this->assertStringContainsString( 'Public teaser', $html );
		$this->assertStringNotContainsString( '<b>', $html );
	}

	/** Plan names stay a tier-only feature: other arms name no plans, even when the catalogue could. */
	#[Test]
	public function plan_names_remain_tier_only(): void {
		$this->seedCatalogue();

		$this->assertSame( array(), Agend_Content_Access_Frontend::plan_names_for( $this->segmentsPolicy() ) );
		$this->assertSame( array(), Agend_Content_Access_Frontend::plan_names_for( $this->entitlementsPolicy() ) );
	}

	// -----------------------------------------------------------------------
	// Entitlements are named, segments never
	// -----------------------------------------------------------------------

	/**
	 * Seeds the cached catalogue sections the gate reads. The cache is the seam
	 * because the Core wrappers are functions that either exist or do not.
	 */
	private function seedEntitlements( array $items ): void {
		Agend_Test_WP::$transients[ Agend_Content_Access_Catalogue::ENTITLEMENTS_TRANSIENT ] = $items;
	}

	private function seedSegments(): void {
		Agend_Test_WP::$transients[ Agend_Content_Access_Catalogue::SEGMENTS_TRANSIENT ] = array(
			array( 'id' => self::SEG_A, 'slug' => 'overdue', 'name' => 'Overdue renewals' ),
		);
	}

	private function entitlementItems(): array {
		return array(
			array( 'gate_key' => 'directory.access', 'name' => 'Directory access', 'description' => 'Browse the directory', 'kind' => 'platform-gate' ),
			array( 'gate_key' => 'entitlement.early_bird', 'name' => 'Early bird', 'description' => '', 'kind' => 'entitlement' ),
			array( 'gate_key' => 'no.name', 'name' => 'no.name', 'description' => '', 'kind' => '' ),
		);
	}

	private function entitlementsPolicyFor( string ...$keys ): array {
		return array( 'mode' => 'selected_entitlements', 'entitlement_keys' => $keys );
	}

	#[Test]
	public function the_restricted_gate_names_the_entitlements_a_policy_admits(): void {
		$this->seedEntitlements( $this->entitlementItems() );

		$html = Agend_Content_Access_Frontend::render_gate(
			'',
			Agend_Content_Access_Decision::STATE_RESTRICTED,
			$this->entitlementsPolicyFor( 'directory.access', 'entitlement.early_bird' )
		);

		$this->assertStringContainsString( 'limited to people who hold: Directory access, Early bird.', $html );
		$this->assertStringNotContainsString( 'Platform:', $html, 'the editor\'s platform label is not for visitors' );
		$this->assertStringNotContainsString( 'limited to selected members', $html );
	}

	/** Nothing to join or upgrade into, named or not. */
	#[Test]
	public function the_restricted_gate_still_has_no_call_to_action_when_it_names_entitlements(): void {
		update_option( 'agend_apps_portal_url', 'https://portal.example.test/' );
		$this->seedEntitlements( $this->entitlementItems() );

		$html = Agend_Content_Access_Frontend::render_gate(
			'',
			Agend_Content_Access_Decision::STATE_RESTRICTED,
			$this->entitlementsPolicyFor( 'directory.access' )
		);

		$this->assertStringNotContainsString( '<a ', $html );
		$this->assertStringNotContainsString( 'portal.example.test', $html );
	}

	#[Test]
	public function an_entitlement_the_catalogue_cannot_name_is_skipped(): void {
		$this->seedEntitlements( $this->entitlementItems() );

		$this->assertSame(
			array( 'Early bird' ),
			Agend_Content_Access_Frontend::entitlement_names_for(
				$this->entitlementsPolicyFor( 'entitlement.early_bird', 'removed.benefit', 'no.name' )
			),
			'neither a key the catalogue lacks nor one with no name of its own is shown'
		);
	}

	#[Test]
	public function an_unreachable_entitlement_catalogue_falls_back_to_the_generic_sentence(): void {
		// Nothing cached and no Core wrapper: the section is empty and in error.
		$html = Agend_Content_Access_Frontend::render_gate(
			'',
			Agend_Content_Access_Decision::STATE_RESTRICTED,
			$this->entitlementsPolicyFor( 'directory.access' )
		);

		$this->assertStringContainsString( 'limited to selected members', $html );
		$this->assertStringNotContainsString( 'people who hold', $html );
		$this->assertStringNotContainsString( 'directory.access', $html );
	}

	#[Test]
	public function an_entitlement_name_is_escaped_and_its_key_never_shown(): void {
		$this->seedEntitlements(
			array( array( 'gate_key' => 'directory.access', 'name' => 'Directory <b>access</b>', 'description' => '', 'kind' => 'entitlement' ) )
		);

		$html = Agend_Content_Access_Frontend::render_gate(
			'',
			Agend_Content_Access_Decision::STATE_RESTRICTED,
			$this->entitlementsPolicyFor( 'directory.access' )
		);

		$this->assertStringNotContainsString( '<b>', $html );
		$this->assertStringContainsString( '&lt;b&gt;access&lt;/b&gt;', $html );
		$this->assertStringNotContainsString( 'directory.access', $html );
	}

	/**
	 * The decisive assertion for segments, now that entitlements are named: with
	 * both catalogues loaded and holding names that would be easy to leak, a
	 * segments policy renders exactly what no policy renders. There is no path by
	 * which a segment's name, id or slug can reach the page.
	 */
	#[Test]
	public function the_restricted_gate_for_a_segments_policy_names_nothing_even_with_both_catalogues_loaded(): void {
		$this->seedEntitlements( $this->entitlementItems() );
		$this->seedSegments();

		$html = Agend_Content_Access_Frontend::render_gate( '', Agend_Content_Access_Decision::STATE_RESTRICTED, $this->segmentsPolicy() );

		$this->assertSame(
			Agend_Content_Access_Frontend::render_gate( '', Agend_Content_Access_Decision::STATE_RESTRICTED, null ),
			$html
		);

		foreach ( array( 'Overdue renewals', 'overdue', self::SEG_A, 'Directory access', 'Early bird', 'people who hold' ) as $leak ) {
			$this->assertStringNotContainsString( $leak, $html );
		}
	}

	#[Test]
	public function entitlement_names_are_only_for_entitlement_policies(): void {
		$this->seedEntitlements( $this->entitlementItems() );
		$this->seedSegments();

		$this->assertSame( array(), Agend_Content_Access_Frontend::entitlement_names_for( $this->segmentsPolicy() ) );
		$this->assertSame( array(), Agend_Content_Access_Frontend::entitlement_names_for( $this->tiersPolicy( self::TIER_A ) ) );
		$this->assertSame( array(), Agend_Content_Access_Frontend::entitlement_names_for( array( 'mode' => 'active_member' ) ) );
		$this->assertSame( array(), Agend_Content_Access_Frontend::entitlement_names_for( null ) );
	}
}
