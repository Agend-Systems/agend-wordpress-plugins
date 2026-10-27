<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Blocks;
use Agend_Content_Access_Condition_Runtime;
use Agend_Content_Access_Fragment_Rules;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

require_once __DIR__ . '/BlockEnforcementTestCase.php';

/**
 * A feed is judged for nobody, blocks included.
 *
 * A feed's output is shared and routinely cached whoever fetched it, so a copy
 * personalised to the person who happened to ask would be served to every reader
 * after them. The page gate already judges a feed's DOCUMENT as an anonymous
 * reader. A block in it has to be judged the same way, its policy and its display
 * conditions both, and its fallback.
 */
#[CoversClass( Agend_Content_Access_Blocks::class )]
#[CoversClass( Agend_Content_Access_Fragment_Rules::class )]
#[CoversClass( Agend_Content_Access_Condition_Runtime::class )]
final class BlockFeedTest extends BlockEnforcementTestCase {

	private const FALLBACK = 4444;

	private const MEMBERS = array( 'mode' => 'active_member' );

	protected function tearDown(): void {
		unset( $GLOBALS['agend_test_current_user_roles'] );

		parent::tearDown();
	}

	private function feed(): void {
		$GLOBALS['agend_test_is_feed'] = true;
	}

	private function secret( array $access = self::MEMBERS ): string {
		return $this->paragraph( 'SECRET', $access );
	}

	// -----------------------------------------------------------------------
	// The policy
	// -----------------------------------------------------------------------

	/** The control: the same member sees the block on the page. */
	#[Test]
	public function outside_a_feed_a_member_sees_the_block(): void {
		$this->asMember( self::TIER_A );

		$this->assertSame( '<p>SECRET</p>', do_blocks( $this->secret() ) );
	}

	#[Test]
	public function in_a_feed_a_member_is_judged_as_nobody(): void {
		$this->asMember( self::TIER_A );
		$this->feed();

		$this->assertSame( '<p>PUBLIC</p>', do_blocks( $this->paragraph( 'PUBLIC' ) . $this->secret() ) );
	}

	/** A request that carries no session cannot identify anybody, so it is not looked up. */
	#[Test]
	public function a_feed_never_looks_the_visitor_up(): void {
		$this->asMember( self::TIER_A );
		$this->feed();

		do_blocks( $this->secret() );

		$this->assertSame( 0, $this->identity_lookups );
		$this->assertSame( 0, $this->gatewayCalls( 'crm/me/entitlements' ) );
	}

	/** @return array<string, array{0: array}> */
	public static function policies(): array {
		return array(
			'any plan'      => array( array( 'mode' => 'active_member' ) ),
			'selected plan' => array( array( 'mode' => 'selected_tiers', 'tierIds' => array( self::TIER_A ) ) ),
			'a segment'     => array( array( 'mode' => 'segments', 'segmentIds' => array( self::SEG_A ) ) ),
			'an entitlement' => array( array( 'mode' => 'selected_entitlements', 'entitlementKeys' => array( self::ENT_A ) ) ),
		);
	}

	#[Test]
	#[DataProvider( 'policies' )]
	public function a_member_who_holds_what_the_policy_asks_is_still_nobody_in_a_feed( array $policy ): void {
		$this->asMemberOf( array( self::SEG_A ), array( self::ENT_A ) );

		$this->assertSame( '<p>SECRET</p>', do_blocks( $this->secret( $policy ) ), 'on the page they are let in' );

		$this->feed();

		$this->assertSame( '', do_blocks( $this->secret( $policy ) ), 'in a feed they are not' );
	}

	/** The document's own policy still narrows, as ever, and a feed is no wider than the page. */
	#[Test]
	public function a_block_with_no_policy_of_its_own_still_renders_in_a_feed(): void {
		$this->asMember( self::TIER_A );
		$this->feed();

		$this->assertSame( '<p>PUBLIC</p>', do_blocks( $this->paragraph( 'PUBLIC' ) ) );
	}

	/** An unreadable restriction is nobody's, in a feed as anywhere. */
	#[Test]
	public function an_attribute_that_cannot_be_read_denies_in_a_feed_too(): void {
		$this->feed();

		$this->assertSame( '', do_blocks( $this->rawAttribute( '{"agendAccess":"members"}', 'SECRET' ) ) );
	}

	// -----------------------------------------------------------------------
	// The conditions
	// -----------------------------------------------------------------------

	/** @return array<string, array{0: string, 1: bool, 2: bool}> condition, shown on the page to the signed-in member, shown in a feed */
	public static function conditions(): array {
		return array(
			'logged in'     => array( 'agend_wordpress:logged_in', true, false ),
			'logged out'    => array( 'agend_wordpress:logged_out', false, true ),
			'a member'      => array( 'agend_membership:is_member', true, false ),
			'a plan'        => array( 'agend_membership:' . self::TIER_A, true, false ),
			'a segment'     => array( 'agend_segments:slug-aaaa', true, false ),
			'an entitlement' => array( 'agend_entitlements:' . self::ENT_A, true, false ),
			'a role'        => array( 'agend_wordpress:role_subscriber', true, false ),
		);
	}

	#[Test]
	#[DataProvider( 'conditions' )]
	public function a_condition_is_answered_for_nobody_in_a_feed( string $condition, bool $on_the_page, bool $in_a_feed ): void {
		$this->asMemberOf( array( self::SEG_A ), array( self::ENT_A ) );
		$GLOBALS['agend_test_current_user_roles'] = array( 'subscriber' );
		$block                                    = $this->paragraph( 'NOTE', array( 'conditionsEnabled' => true, 'conditions' => array( $condition ) ) );

		$this->assertSame( $on_the_page ? '<p>NOTE</p>' : '', do_blocks( $block ), 'on the page' );

		$this->feed();

		$this->assertSame( $in_a_feed ? '<p>NOTE</p>' : '', do_blocks( $block ), 'in a feed' );
	}

	/** Inverting reverses the anonymous answer, not the member's. */
	#[Test]
	public function an_inverted_condition_reverses_the_anonymous_answer_in_a_feed(): void {
		$this->asMember( self::TIER_A );
		$block = $this->paragraph( 'NOTE', array( 'conditionsEnabled' => true, 'conditions' => array( 'agend_wordpress:logged_in' ), 'conditionsInvert' => true ) );

		$this->assertSame( '', do_blocks( $block ), 'on the page the member fails the inverted rule' );

		$this->feed();

		$this->assertSame( '<p>NOTE</p>', do_blocks( $block ), 'nobody passes it' );
	}

	/** A condition nothing can answer still denies, in a feed as on the page. */
	#[Test]
	public function an_unknown_provider_still_denies_in_a_feed(): void {
		$this->feed();

		$this->assertSame( '', do_blocks( $this->paragraph( 'NOTE', array( 'conditionsEnabled' => true, 'conditions' => array( 'acme:thing' ) ) ) ) );
	}

	/** The segment filter supplies segments for the CURRENT visitor, and a feed has none. */
	#[Test]
	public function the_site_s_segment_filter_is_not_asked_for_a_feed(): void {
		$asked = 0;
		\Agend_Test_WP::$filters['agend_content_access_segment_facts'] = static function () use ( &$asked ): array {
			++$asked;

			return array( 'slug-aaaa' );
		};
		$block = $this->paragraph( 'NOTE', array( 'conditionsEnabled' => true, 'conditions' => array( 'agend_segments:slug-aaaa' ) ) );

		$this->assertSame( '<p>NOTE</p>', do_blocks( $block ), 'on the page the filter answers' );
		$this->assertSame( 1, $asked );

		$this->feed();

		$this->assertSame( '', do_blocks( $block ) );
		$this->assertSame( 1, $asked, 'and is not asked again for the feed' );
	}

	// -----------------------------------------------------------------------
	// The fallback
	// -----------------------------------------------------------------------

	private function excludedWithFallback(): string {
		// No signed-in member fails this, so the block is excluded for every viewer
		// and it is the fallback's own policy that is being decided.
		return $this->paragraph(
			'NOTE',
			array(
				'conditionsEnabled' => true,
				'conditions'        => array( 'agend_wordpress:role_nonexistent' ),
				'fallbackId'        => self::FALLBACK,
			)
		);
	}

	#[Test]
	public function a_members_only_fallback_is_shown_to_a_member_on_the_page_and_not_in_a_feed(): void {
		$this->theContentRunsBlocks();
		$this->registerPost( self::FALLBACK, 'MEMBERS ONLY FALLBACK', self::MEMBERS );
		$this->asMember( self::TIER_A );

		$this->assertSame( 'MEMBERS ONLY FALLBACK', do_blocks( $this->excludedWithFallback() ), 'on the page they may read it' );

		$this->feed();

		$this->assertSame( '', do_blocks( $this->excludedWithFallback() ), 'in a feed nobody may' );
	}

	#[Test]
	public function a_public_fallback_is_shown_in_a_feed(): void {
		$this->theContentRunsBlocks();
		$this->registerPost( self::FALLBACK, 'PUBLIC FALLBACK' );
		$this->feed();

		$this->assertSame( 'PUBLIC FALLBACK', do_blocks( $this->excludedWithFallback() ) );
	}

	/** A fallback is rendered through `the_content`, so its own blocks are judged for nobody as well. */
	#[Test]
	public function blocks_inside_a_fallback_are_judged_for_nobody_in_a_feed(): void {
		$this->theContentRunsBlocks();
		$this->registerPost( self::FALLBACK, $this->paragraph( 'OPEN' ) . $this->secret() );
		$this->asMember( self::TIER_A );
		$this->feed();

		$this->assertSame( '<p>OPEN</p>', do_blocks( $this->excludedWithFallback() ) );
	}

	// -----------------------------------------------------------------------
	// The checker itself
	// -----------------------------------------------------------------------

	#[Test]
	public function the_anonymous_checker_answers_every_built_in_family_for_nobody(): void {
		$this->asMemberOf( array( self::SEG_A ), array( self::ENT_A ) );

		$check = Agend_Content_Access_Condition_Runtime::anonymous_checker();

		$this->assertFalse( $check( 'agend_wordpress', 'logged_in' ) );
		$this->assertTrue( $check( 'agend_wordpress', 'logged_out' ) );
		$this->assertFalse( $check( 'agend_membership', 'is_member' ) );
		$this->assertFalse( $check( 'agend_membership', self::TIER_A ) );
		$this->assertFalse( $check( 'agend_segments', 'slug-aaaa' ) );
		$this->assertFalse( $check( 'agend_entitlements', self::ENT_A ) );
		$this->assertSame( 0, $this->identity_lookups, 'and looks nobody up' );
	}

	/** Callers that pass no checker are judged for the visitor, as they always were. */
	#[Test]
	public function conditions_pass_defaults_to_the_live_visitor(): void {
		$this->asMember( self::TIER_A );
		$settings = array(
			Agend_Content_Access_Fragment_Rules::ENABLED_KEY    => 'yes',
			Agend_Content_Access_Fragment_Rules::CONDITIONS_KEY => array( 'agend_membership:is_member' ),
		);

		$this->assertTrue( Agend_Content_Access_Fragment_Rules::conditions_pass( $settings ) );
		$this->assertFalse( Agend_Content_Access_Fragment_Rules::conditions_pass( $settings, Agend_Content_Access_Condition_Runtime::anonymous_checker() ) );
	}
}
