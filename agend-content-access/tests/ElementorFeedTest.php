<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Elementor as ElementorControl;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

require_once __DIR__ . '/BlockEnforcementTestCase.php';

/**
 * A feed is judged for nobody, Elementor sections included.
 *
 * A feed's output is shared and routinely cached whoever fetched it, so a copy
 * personalised to the person who happened to ask would be served to every reader
 * after them. The page gate judges a feed's document as an anonymous reader and
 * block enforcement does the same for blocks. A section's policy, its display
 * conditions and its fallback have to be judged that way too.
 */
#[CoversClass( ElementorControl::class )]
final class ElementorFeedTest extends BlockEnforcementTestCase {

	private const FALLBACK = 4444;

	protected function setUp(): void {
		parent::setUp();

		\Agend_Test_WP::$did_action['elementor/loaded'] = 1;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['agend_test_current_user_roles'] );

		parent::tearDown();
	}

	private function feed(): void {
		$GLOBALS['agend_test_is_feed'] = true;
	}

	/** An element carrying the given Elementor settings. */
	private function element( array $settings ): object {
		return new class( $settings ) {
			/** @var array<string, mixed> */
			private array $settings;

			public function __construct( array $settings ) {
				$this->settings = $settings;
			}

			public function get_settings_for_display(): array {
				return $this->settings;
			}
		};
	}

	/** @param array<string, mixed> $extra */
	private function restricted( string $mode = 'active_member', array $extra = array() ): object {
		return $this->element( array( ElementorControl::MODE_KEY => $mode ) + $extra );
	}

	/** @param string[] $conditions */
	private function conditioned( array $conditions, bool $invert = false, int $fallback = 0 ): object {
		return $this->element(
			array(
				ElementorControl::CONDITIONS_ENABLED_KEY  => 'yes',
				ElementorControl::CONDITIONS_KEY          => $conditions,
				ElementorControl::CONDITIONS_MATCH_KEY    => 'any',
				ElementorControl::CONDITIONS_INVERT_KEY   => $invert ? 'yes' : '',
				ElementorControl::CONDITIONS_FALLBACK_KEY => $fallback > 0 ? (string) $fallback : '',
			)
		);
	}

	private function suppress( object $element ): bool {
		return ! ( new ElementorControl() )->maybe_suppress( true, $element );
	}

	private function fallbackOutput( object $element ): string {
		ob_start();
		( new ElementorControl() )->maybe_render_fallback( $element );

		return (string) ob_get_clean();
	}

	// -----------------------------------------------------------------------
	// The policy
	// -----------------------------------------------------------------------

	/** The control: the same member sees the section on the page. */
	#[Test]
	public function outside_a_feed_a_member_sees_the_section(): void {
		$this->asMember( self::TIER_A );

		$this->assertFalse( $this->suppress( $this->restricted() ) );
	}

	#[Test]
	public function in_a_feed_a_member_is_judged_as_nobody(): void {
		$this->asMember( self::TIER_A );
		$this->feed();

		$this->assertTrue( $this->suppress( $this->restricted() ) );
	}

	/** A request that carries no session cannot identify anybody, so it is not looked up. */
	#[Test]
	public function a_feed_never_looks_the_visitor_up(): void {
		$this->asMember( self::TIER_A );
		$this->feed();

		$this->suppress( $this->restricted() );

		$this->assertSame( 0, $this->identity_lookups );
		$this->assertSame( 0, $this->gatewayCalls( 'crm/me/entitlements' ) );
	}

	/** @return array<string, array{0: array<string, mixed>}> */
	public static function policies(): array {
		return array(
			'any plan'       => array( array( ElementorControl::MODE_KEY => 'active_member' ) ),
			'selected plan'  => array( array( ElementorControl::MODE_KEY => 'selected_tiers', ElementorControl::TIERS_KEY => array( self::TIER_A ) ) ),
			'a segment'      => array( array( ElementorControl::MODE_KEY => 'segments', ElementorControl::SEGMENTS_KEY => array( self::SEG_A ) ) ),
			'an entitlement' => array( array( ElementorControl::MODE_KEY => 'selected_entitlements', ElementorControl::ENTITLEMENTS_KEY => array( self::ENT_A ) ) ),
		);
	}

	#[Test]
	#[DataProvider( 'policies' )]
	public function a_member_who_holds_what_the_policy_asks_is_still_nobody_in_a_feed( array $settings ): void {
		$this->asMemberOf( array( self::SEG_A ), array( self::ENT_A ) );

		$this->assertFalse( $this->suppress( $this->element( $settings ) ), 'on the page they are let in' );

		$this->feed();

		$this->assertTrue( $this->suppress( $this->element( $settings ) ), 'in a feed they are not' );
	}

	#[Test]
	public function a_section_with_no_policy_of_its_own_still_renders_in_a_feed(): void {
		$this->asMember( self::TIER_A );
		$this->feed();

		$this->assertFalse( $this->suppress( $this->element( array() ) ) );
	}

	// -----------------------------------------------------------------------
	// The conditions
	// -----------------------------------------------------------------------

	/** @return array<string, array{0: string, 1: bool, 2: bool}> condition, shown on the page to the signed-in member, shown in a feed */
	public static function conditions(): array {
		return array(
			'logged in'      => array( 'agend_wordpress:logged_in', true, false ),
			'logged out'     => array( 'agend_wordpress:logged_out', false, true ),
			'a member'       => array( 'agend_membership:is_member', true, false ),
			'a plan'         => array( 'agend_membership:' . self::TIER_A, true, false ),
			'a segment'      => array( 'agend_segments:slug-aaaa', true, false ),
			'an entitlement' => array( 'agend_entitlements:' . self::ENT_A, true, false ),
			'a role'         => array( 'agend_wordpress:role_subscriber', true, false ),
		);
	}

	#[Test]
	#[DataProvider( 'conditions' )]
	public function a_condition_is_answered_for_nobody_in_a_feed( string $condition, bool $on_the_page, bool $in_a_feed ): void {
		$this->asMemberOf( array( self::SEG_A ), array( self::ENT_A ) );
		$GLOBALS['agend_test_current_user_roles'] = array( 'subscriber' );

		$this->assertSame( ! $on_the_page, $this->suppress( $this->conditioned( array( $condition ) ) ), 'on the page' );

		$this->feed();

		$this->assertSame( ! $in_a_feed, $this->suppress( $this->conditioned( array( $condition ) ) ), 'in a feed' );
	}

	/** Inverting reverses the anonymous answer, not the member's. */
	#[Test]
	public function an_inverted_condition_reverses_the_anonymous_answer_in_a_feed(): void {
		$this->asMember( self::TIER_A );
		$element = $this->conditioned( array( 'agend_wordpress:logged_in' ), true );

		$this->assertTrue( $this->suppress( $element ), 'on the page the member fails the inverted rule' );

		$this->feed();

		$this->assertFalse( $this->suppress( $element ), 'nobody passes it' );
	}

	/** The segment filter supplies segments for the CURRENT visitor, and a feed has none. */
	#[Test]
	public function the_site_s_segment_filter_is_not_asked_for_a_feed(): void {
		$asked = 0;
		\Agend_Test_WP::$filters['agend_content_access_segment_facts'] = static function () use ( &$asked ): array {
			++$asked;

			return array( 'slug-aaaa' );
		};
		$element = $this->conditioned( array( 'agend_segments:slug-aaaa' ) );

		$this->assertFalse( $this->suppress( $element ), 'on the page the filter answers' );
		$this->assertSame( 1, $asked );

		$this->feed();

		$this->assertTrue( $this->suppress( $element ) );
		$this->assertSame( 1, $asked, 'and is not asked again for the feed' );
	}

	// -----------------------------------------------------------------------
	// The fallback
	// -----------------------------------------------------------------------

	/** No signed-in member fails this role, so the section is excluded for every viewer. */
	private function excludedWithFallback(): object {
		return $this->conditioned( array( 'agend_wordpress:role_nonexistent' ), false, self::FALLBACK );
	}

	#[Test]
	public function a_members_only_fallback_is_shown_to_a_member_on_the_page_and_not_in_a_feed(): void {
		$this->registerPost( self::FALLBACK, 'MEMBERS ONLY FALLBACK', array( 'mode' => 'active_member' ) );
		$this->theContentRunsBlocks();
		$this->asMember( self::TIER_A );

		$this->assertSame( 'MEMBERS ONLY FALLBACK', $this->fallbackOutput( $this->excludedWithFallback() ), 'on the page they may read it' );

		$this->feed();

		$this->assertSame( '', $this->fallbackOutput( $this->excludedWithFallback() ), 'in a feed nobody may' );
	}

	#[Test]
	public function a_public_fallback_is_shown_in_a_feed(): void {
		$this->registerPost( self::FALLBACK, 'PUBLIC FALLBACK' );
		$this->theContentRunsBlocks();
		$this->feed();

		$this->assertSame( 'PUBLIC FALLBACK', $this->fallbackOutput( $this->excludedWithFallback() ) );
	}

	/** The fallback is decided on the same conditions as the suppression, so the two cannot disagree in a feed. */
	#[Test]
	public function a_section_the_conditions_pass_for_nobody_has_no_fallback_in_a_feed(): void {
		$this->registerPost( self::FALLBACK, 'PUBLIC FALLBACK' );
		$this->theContentRunsBlocks();
		$this->asMember( self::TIER_A );
		$element = $this->conditioned( array( 'agend_wordpress:logged_in' ), true, self::FALLBACK );

		$this->assertSame( 'PUBLIC FALLBACK', $this->fallbackOutput( $element ), 'on the page the member fails the inverted rule' );

		$this->feed();

		$this->assertSame( '', $this->fallbackOutput( $element ), 'nobody fails it, so the section stays and nothing replaces it' );
		$this->assertFalse( $this->suppress( $element ) );
	}
}
