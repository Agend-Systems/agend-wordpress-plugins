<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Access;
use Agend_Content_Access_Condition_Sets;
use Agend_Content_Access_Decision;
use Agend_Content_Access_Elementor as ElementorControl;
use Agend_Content_Access_Exporter as Exporter;
use Agend_Content_Access_Fragment_Rules as FragmentRules;
use Agend_Content_Access_Frontend;
use Agend_Content_Access_Policy as Policy;
use Agend_Content_Access_Rules as Rules;
use Agend_Test_WP;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

require_once __DIR__ . '/fixtures/elementor-active-settings-double.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-sets.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-exporter.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-block-parser.php';

/**
 * Every place that decides something decides it through the rule list.
 *
 * `RulesTest` and `AccessTest` cover what the rules are and how they are judged. This is
 * the wiring: a block is shown only when its own rules AND its post's rules allow, a section
 * likewise, a fallback is only shown to somebody its own page admits, and the connector
 * exports the rules as a policy that never says MORE than they do. Each of these is a place
 * a fail-open mistake would hide, so the tests are mostly "this denies".
 */
#[CoversClass( \Agend_Content_Access_Blocks::class )]
#[CoversClass( ElementorControl::class )]
#[CoversClass( Rules::class )]
final class RulesEnforcementTest extends BlockEnforcementTestCase {

	private const SECRET   = '<p>SECRET</p>';
	private const MEMBER   = 'agend_membership:is_member';
	private const FALLBACK = 4343;

	protected function setUp(): void {
		parent::setUp();

		$this->inTheLoopOf( self::PUBLIC_PAGE );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['agend_test_is_feed'], $GLOBALS['agend_test_is_admin'], $GLOBALS['agend_test_current_user_roles'] );

		parent::tearDown();
	}

	// -----------------------------------------------------------------------
	// Fixtures
	// -----------------------------------------------------------------------

	/** A page's rules, in the new stored shape. */
	private function pageRules( int $id, array $rules, string $match = 'any', bool $invert = false ): void {
		$GLOBALS['agend_test_post_meta'][ $id ][ Rules::META_KEY ] = array(
			'rules'  => $rules,
			'match'  => $match,
			'invert' => $invert,
		);
	}

	/** A block's attribute in the new shape. */
	private function ruled( array $rules, string $match = 'any', bool $invert = false, int $fallback = 0 ): array {
		$attribute = array( 'rules' => $rules, 'match' => $match, 'invert' => $invert );

		if ( $fallback > 0 ) {
			$attribute['fallbackId'] = $fallback;
		}

		return $attribute;
	}

	private function secret( array $attribute ): string {
		return $this->paragraph( 'SECRET', $attribute );
	}

	/** A section as Elementor hands it over. */
	private function element( array $settings ): object {
		return new class( $settings ) {
			/** @var array<string, mixed> */
			public array $settings;

			public function __construct( array $settings ) {
				$this->settings = $settings;
			}

			public function get_settings_for_display(): array {
				return $this->settings;
			}
		};
	}

	private function shows( array $settings ): bool {
		return (bool) ( new ElementorControl() )->maybe_suppress( true, $this->element( $settings ) );
	}

	/** Starts over as a different visitor on the same site. */
	private function newVisitor(): void {
		Agend_Content_Access_Decision::reset_cache();
		Agend_Test_WP::$transients = array();
	}

	// -----------------------------------------------------------------------
	// A block, in the new shape
	// -----------------------------------------------------------------------

	#[Test]
	public function a_block_with_new_rules_is_shown_to_somebody_they_admit_and_removed_for_everybody_else(): void {
		$block = $this->secret( $this->ruled( array( 'agend_membership:' . self::TIER_A ) ) );

		$this->assertSame( '', do_blocks( $block ), 'anonymous' );

		$this->newVisitor();
		$this->asMember( self::TIER_B );
		$this->assertSame( '', do_blocks( $block ), 'a member on another plan' );

		$this->newVisitor();
		$this->asMember( self::TIER_A );
		$this->assertSame( self::SECRET, do_blocks( $block ) );
	}

	#[Test]
	public function a_reversed_block_is_the_join_banner_hidden_from_members(): void {
		$banner = $this->secret( $this->ruled( array( self::MEMBER ), 'any', true ) );

		$this->assertSame( self::SECRET, do_blocks( $banner ), 'an anonymous visitor is not a member' );

		$this->newVisitor();
		$this->asMember( self::TIER_A );
		$this->assertSame( '', do_blocks( $banner ) );
	}

	/** The reverse of an outage is not a disclosure. */
	#[Test]
	#[DataProvider( 'reverseOrNot' )]
	public function a_block_over_a_provider_that_cannot_answer_is_removed( bool $invert ): void {
		$this->assertSame( '', do_blocks( $this->secret( $this->ruled( array( 'plugin_gone:x' ), 'any', $invert ) ) ) );

		$this->asMember( self::TIER_A );
		$this->assertSame( '', do_blocks( $this->secret( $this->ruled( array( 'plugin_gone:x', self::MEMBER ), 'any', $invert ) ) ) );
	}

	/** @return array<string, array{0: bool}> */
	public static function reverseOrNot(): array {
		return array(
			'as written' => array( false ),
			'reversed'   => array( true ),
		);
	}

	#[Test]
	public function a_block_whose_new_rules_cannot_be_read_is_removed_for_everyone(): void {
		$this->asMember( self::TIER_A );

		foreach ( array(
			'{"agendAccess":{"rules":"agend_membership:is_member"}}',
			'{"agendAccess":{"rules":["agend_membership:is_member"],"match":"some"}}',
			'{"agendAccess":{"rules":["agend_membership:is_member"],"invert":"yes"}}',
			'{"agendAccess":{"rules":[5]}}',
		) as $json ) {
			$this->assertSame( '', do_blocks( $this->rawAttribute( $json, 'SECRET' ) ), $json );
		}
	}

	#[Test]
	public function a_reverse_over_no_rules_removes_the_block_for_everyone(): void {
		$this->asMember( self::TIER_A );

		$this->assertSame( '', do_blocks( $this->secret( array( 'rules' => array(), 'invert' => true ) ) ) );
	}

	#[Test]
	public function a_block_with_an_empty_rule_list_is_an_ordinary_block_and_costs_no_lookup(): void {
		$this->assertSame( self::SECRET, do_blocks( $this->secret( array( 'rules' => array(), 'match' => 'all', 'invert' => false ) ) ) );
		$this->assertSame( 0, $this->identity_lookups );
		$this->assertSame( array(), $this->uncacheable, 'and is not kept out of caches' );
	}

	/** A block with rules asks Agend about the visitor at most once, however many blocks there are. */
	#[Test]
	public function many_blocks_with_rules_make_one_entitlements_call_and_one_segments_call(): void {
		$this->asMemberOf( array( self::SEG_A ), array( self::ENT_A ) );

		$markup = '';

		for ( $i = 0; $i < 6; $i++ ) {
			$markup .= $this->secret( $this->ruled( array( 'agend_segments:' . self::SEG_A, 'agend_entitlements:' . self::ENT_A ) ) );
			$markup .= $this->secret( $this->ruled( array( 'agend_membership:' . self::TIER_A ) ) );
		}

		$this->assertSame( str_repeat( self::SECRET, 12 ), do_blocks( $markup ) );
		$this->assertLessThanOrEqual( 1, $this->gatewayCalls( '/crm/me/entitlements' ) );
		$this->assertSame( 1, $this->gatewayCalls( '/crm/me/segments' ) );
		$this->assertSame( 1, $this->identity_lookups );
	}

	// -----------------------------------------------------------------------
	// A block AND the post it belongs to
	// -----------------------------------------------------------------------

	/** The page's rules alone would admit the visitor, and the block's own do not. */
	#[Test]
	public function a_block_is_removed_when_its_own_rules_deny_even_though_the_pages_admit(): void {
		$this->registerPost( 301, $this->secret( $this->ruled( array( 'agend_membership:' . self::TIER_B ) ) ) );
		$this->pageRules( 301, array( 'agend_membership:' . self::TIER_A ) );

		$this->asMember( self::TIER_A );

		$this->assertSame( '', $this->renderPost( 301 ) );
	}

	/** The block's rules alone would admit the visitor, and the page's do not: a block never widens its page. */
	#[Test]
	public function a_block_is_removed_when_the_pages_rules_deny_even_though_its_own_admit(): void {
		$this->registerPost( 302, $this->secret( $this->ruled( array( self::MEMBER ) ) ) );
		$this->pageRules( 302, array( 'agend_membership:' . self::TIER_B ) );

		$this->asMember( self::TIER_A );

		$this->assertSame( '', $this->renderPost( 302 ) );
	}

	#[Test]
	public function a_block_is_shown_only_to_somebody_both_admit(): void {
		$this->registerPost( 303, $this->secret( $this->ruled( array( 'agend_membership:' . self::TIER_A ) ) ) );
		$this->pageRules( 303, array( 'agend_membership:' . self::TIER_B ) );

		$this->asMember( self::TIER_A, self::TIER_B );

		$this->assertSame( self::SECRET, $this->renderPost( 303 ) );
	}

	/** A block that carries only conditions is narrowed by its page too. */
	#[Test]
	public function a_conditions_only_block_is_narrowed_by_its_page_as_well(): void {
		$this->registerPost( 304, $this->paragraph( 'SECRET', array( 'conditionsEnabled' => true, 'conditions' => array( 'agend_wordpress:logged_out' ) ) ) );
		$this->pageRules( 304, array( self::MEMBER ) );

		$this->assertSame( '', $this->renderPost( 304 ), 'the conditions admit a signed-out visitor, the page does not' );
	}

	/** An old page policy is the page's rules too. */
	#[Test]
	public function an_old_page_policy_narrows_a_block_in_the_new_shape(): void {
		$this->registerPost( 305, $this->secret( $this->ruled( array( self::MEMBER ) ) ), array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_B ) ) );

		$this->asMember( self::TIER_A );

		$this->assertSame( '', $this->renderPost( 305 ) );
	}

	/** A page whose stored rules cannot be read is nobody's, and so is every block on it. */
	#[Test]
	public function a_block_on_a_page_whose_new_rules_cannot_be_read_is_removed(): void {
		$this->registerPost( 306, $this->secret( $this->ruled( array( self::MEMBER ) ) ) );
		$GLOBALS['agend_test_post_meta'][306][ Rules::META_KEY ] = array( 'rules' => 'agend_membership:is_member' );

		$this->asMember( self::TIER_A );

		$this->assertSame( '', $this->renderPost( 306 ) );
	}

	#[Test]
	public function a_block_is_judged_against_the_post_it_belongs_to_not_the_page_it_appears_on(): void {
		$this->pageRules( self::PUBLIC_PAGE, array( 'agend_membership:' . self::TIER_B ) );
		$this->registerPost( 307, $this->secret( $this->ruled( array( self::MEMBER ) ) ) );

		$this->asMember( self::TIER_A );

		$this->assertSame( self::SECRET, $this->renderPost( 307 ), 'a post with no rules of its own is not narrowed by the page it is listed on' );
	}

	/** The backstop applies the same decision, page and block together, to a block that skipped the front door. */
	#[Test]
	public function the_backstop_judges_a_block_and_its_post_together_too(): void {
		register_block_type(
			'core/navigation',
			array(
				'skip_inner_blocks' => true,
				'render_callback'   => static function ( array $a, string $c, $block ): string {
					$html = '';

					foreach ( $block->inner_blocks as $inner_block ) {
						$html .= $inner_block->render();
					}

					return '<nav>' . $html . '</nav>';
				},
			)
		);
		register_block_type(
			'core/navigation-link',
			array( 'render_callback' => static fn( array $a ): string => '<li>' . ( $a['label'] ?? '' ) . '</li>' )
		);

		$link = static fn( array $access ): string => '<!-- wp:navigation-link ' . wp_json_encode( array( 'label' => 'Secret', 'agendAccess' => $access ) ) . ' /-->';
		$nav  = static fn( string $link_markup ): string => '<!-- wp:navigation -->' . $link_markup . '<!-- /wp:navigation -->';

		$this->registerPost( 308, '' );
		$this->pageRules( 308, array( 'agend_membership:' . self::TIER_B ) );
		$this->inTheLoopOf( 308 );

		$this->asMember( self::TIER_A );
		$this->assertSame( '<nav></nav>', do_blocks( $nav( $link( $this->ruled( array( self::MEMBER ) ) ) ) ), 'its own rules admit them and its post\'s do not' );

		$this->newVisitor();
		$this->asMember( self::TIER_A, self::TIER_B );
		$this->assertSame( '<nav><li>Secret</li></nav>', do_blocks( $nav( $link( $this->ruled( array( self::MEMBER ) ) ) ) ) );

		$this->newVisitor();
		$this->asMember( self::TIER_A, self::TIER_B );
		$this->assertSame( '<nav></nav>', do_blocks( $nav( $link( $this->ruled( array( 'agend_membership:' . self::TIER_A ), 'any', true ) ) ) ), 'and a reverse' );
	}

	// -----------------------------------------------------------------------
	// An old policy and old conditions on one block
	// -----------------------------------------------------------------------

	/** Both must pass, so combining them can only narrow. */
	#[Test]
	public function a_v1_block_with_a_policy_and_conditions_needs_both(): void {
		$block = $this->paragraph(
			'SECRET',
			array( 'mode' => 'active_member', 'conditionsEnabled' => true, 'conditions' => array( 'agend_wordpress:role_editor' ) )
		);

		$GLOBALS['agend_test_current_user_id']    = 7;
		$GLOBALS['agend_test_current_user_roles'] = array( 'subscriber' );
		$this->asMember( self::TIER_A );
		$this->assertSame( '', do_blocks( $block ), 'a member, not an editor' );

		$this->newVisitor();
		$GLOBALS['agend_test_current_user_roles'] = array( 'editor' );
		$this->asMember( self::TIER_A );
		$this->assertSame( self::SECRET, do_blocks( $block ), 'a member and an editor' );

		$this->newVisitor();
		$GLOBALS['agend_test_current_user_roles'] = array( 'editor' );
		$this->asMember();
		$this->assertSame( '', do_blocks( $block ), 'an editor, not a member' );
	}

	/** A block that carries the new shape and v1 keys has to satisfy both, so mixing them can only narrow. */
	#[Test]
	public function a_block_with_the_new_shape_and_v1_keys_needs_both(): void {
		$block = $this->paragraph(
			'SECRET',
			array( 'rules' => array( self::MEMBER ), 'mode' => 'selected_tiers', 'tierIds' => array( self::TIER_B ) )
		);

		$this->asMember( self::TIER_A );
		$this->assertSame( '', do_blocks( $block ), 'a member, but not on the v1 plan' );

		$this->newVisitor();
		$this->asMember( self::TIER_B );
		$this->assertSame( self::SECRET, do_blocks( $block ) );
	}

	// -----------------------------------------------------------------------
	// Replacement content
	// -----------------------------------------------------------------------

	private function publishFallback( string $content = 'FALLBACK TEXT' ): void {
		$this->registerPost( self::FALLBACK, $content );
		$this->theContentRunsBlocks();
	}

	#[Test]
	public function a_block_whose_own_rules_deny_shows_its_public_replacement(): void {
		$this->publishFallback();

		$this->assertSame(
			'FALLBACK TEXT',
			do_blocks( $this->secret( $this->ruled( array( self::MEMBER ), 'any', false, self::FALLBACK ) ) )
		);
	}

	/** The replacement is for a block its OWN rules removed. A block its post's rules removed is protected content. */
	#[Test]
	public function a_block_the_pages_rules_removed_gets_no_replacement(): void {
		$this->publishFallback();
		$this->registerPost( 309, $this->secret( $this->ruled( array( 'agend_wordpress:logged_in' ), 'any', false, self::FALLBACK ) ) );
		$this->pageRules( 309, array( self::MEMBER ) );
		$GLOBALS['agend_test_current_user_id'] = 7;

		$this->assertSame( '', $this->renderPost( 309 ), 'signed in, so the block admits them; the page does not' );
	}

	/** An audience with a replacement beside it has never shown the replacement. */
	#[Test]
	public function a_v1_audience_hides_its_block_without_a_replacement(): void {
		$this->publishFallback();

		$this->assertSame( '', do_blocks( $this->paragraph( 'SECRET', array( 'mode' => 'active_member', 'fallbackId' => self::FALLBACK ) ) ) );
	}

	#[Test]
	public function a_restricted_replacement_is_not_shown_to_somebody_its_own_page_refuses(): void {
		$this->registerPost( self::FALLBACK, 'MEMBERS ONLY FALLBACK' );
		$this->pageRules( self::FALLBACK, array( 'agend_membership:' . self::TIER_B ) );
		$this->theContentRunsBlocks();

		$this->assertSame( '', FragmentRules::fallback_html( self::FALLBACK ), 'an anonymous visitor' );

		$this->newVisitor();
		$this->asMember( self::TIER_A );
		$this->assertSame( '', FragmentRules::fallback_html( self::FALLBACK ), 'a member of another plan' );

		$this->newVisitor();
		$this->asMember( self::TIER_B );
		$this->assertSame( 'MEMBERS ONLY FALLBACK', FragmentRules::fallback_html( self::FALLBACK ) );
		$this->assertSame( '', FragmentRules::fallback_html( self::FALLBACK, true ), 'and never in a feed' );
	}

	#[Test]
	public function a_replacement_whose_page_rules_cannot_be_read_is_refused(): void {
		$this->registerPost( self::FALLBACK, 'FALLBACK TEXT' );
		$GLOBALS['agend_test_post_meta'][ self::FALLBACK ][ Rules::META_KEY ] = 'members';

		$this->asMember( self::TIER_A );

		$this->assertSame( '', FragmentRules::fallback_html( self::FALLBACK ) );
	}

	#[Test]
	public function the_picker_offers_only_pages_with_no_rules(): void {
		$register = static function ( int $id, string $title, array $meta ): void {
			$GLOBALS['agend_test_posts'][ $id ]     = array( 'ID' => $id, 'post_status' => 'publish', 'post_type' => 'page', 'post_title' => $title, 'post_content' => '' );
			$GLOBALS['agend_test_post_meta'][ $id ] = $meta;
		};

		$register( 801, 'Nothing stored', array() );
		$register( 802, 'Empty rule list', array( Rules::META_KEY => array( 'rules' => array() ) ) );
		$register( 803, 'Has rules', array( Rules::META_KEY => array( 'rules' => array( self::MEMBER ) ) ) );
		$register( 804, 'Has reversed rules', array( Rules::META_KEY => array( 'rules' => array( self::MEMBER ), 'invert' => true ) ) );
		$register( 805, 'Unreadable rules', array( Rules::META_KEY => 'members' ) );
		$register( 806, 'Old policy', array( Policy::META_KEY => array( 'mode' => 'active_member' ) ) );
		$register( 807, 'Explicitly public', array( Policy::META_KEY => array( 'mode' => 'public' ) ) );

		$offered = array_map( 'intval', array_keys( Agend_Content_Access_Condition_Sets::fallback_options() ) );

		$this->assertContains( 801, $offered );
		$this->assertContains( 802, $offered );
		$this->assertContains( 807, $offered );
		foreach ( array( 803, 804, 805, 806 ) as $id ) {
			$this->assertNotContains( $id, $offered, 'page ' . $id );
		}
	}

	// -----------------------------------------------------------------------
	// A feed
	// -----------------------------------------------------------------------

	#[Test]
	public function a_feed_judges_a_block_for_nobody(): void {
		$GLOBALS['agend_test_is_feed'] = true;
		$this->asMemberOf( array( self::SEG_A ), array( self::ENT_A ) );

		foreach ( array(
			$this->ruled( array( self::MEMBER ) ),
			$this->ruled( array( 'agend_segments:' . self::SEG_A ) ),
			$this->ruled( array( 'agend_entitlements:' . self::ENT_A ) ),
			$this->ruled( array( 'agend_wordpress:logged_in' ) ),
		) as $attribute ) {
			$this->assertSame( '', do_blocks( $this->secret( $attribute ) ), (string) wp_json_encode( $attribute ) );
		}

		$this->assertSame( self::SECRET, do_blocks( $this->secret( $this->ruled( array( self::MEMBER ), 'any', true ) ) ), 'nobody is not a member, so a reversed rule shows' );
		$this->assertSame( 0, $this->gatewayCalls( '/crm/me/entitlements' ) );
		$this->assertSame( 0, $this->gatewayCalls( '/crm/me/segments' ) );
		$this->assertSame( 0, $this->identity_lookups );

		unset( $GLOBALS['agend_test_is_feed'] );
	}

	// -----------------------------------------------------------------------
	// Authoring is unchanged
	// -----------------------------------------------------------------------

	#[Test]
	public function an_author_is_never_filtered_for_a_post_they_may_edit(): void {
		$this->registerPost( 310, $this->secret( $this->ruled( array( self::MEMBER ) ) ) );
		$this->pageRules( 310, array( 'agend_membership:' . self::TIER_B ) );
		$this->asEditor();
		$GLOBALS['agend_test_is_admin'] = true;

		$this->assertSame( self::SECRET, $this->renderPost( 310 ) );
		$this->assertSame( 0, $this->identity_lookups );

		unset( $GLOBALS['agend_test_is_admin'] );
	}

	// -----------------------------------------------------------------------
	// An Elementor section
	// -----------------------------------------------------------------------

	#[Test]
	public function a_section_with_new_rules_follows_them(): void {
		$settings = array( 'agend_access_rules' => array( 'agend_membership:' . self::TIER_A ) );

		$this->assertFalse( $this->shows( $settings ), 'anonymous' );

		$this->newVisitor();
		$this->asMember( self::TIER_B );
		$this->assertFalse( $this->shows( $settings ) );

		$this->newVisitor();
		$this->asMember( self::TIER_A );
		$this->assertTrue( $this->shows( $settings ) );
	}

	#[Test]
	public function a_section_with_no_rules_inherits_its_page_and_costs_nothing(): void {
		$this->assertTrue( $this->shows( array() ) );
		$this->assertTrue( $this->shows( array( 'agend_access_rules' => array(), 'agend_access_match' => 'any', 'agend_access_invert' => '' ) ) );
		$this->assertSame( 0, $this->identity_lookups );
	}

	#[Test]
	public function a_section_that_cannot_be_read_is_hidden(): void {
		$this->asMember( self::TIER_A );

		$this->assertFalse( $this->shows( array( 'agend_access_rules' => 'agend_membership:is_member' ) ) );
		$this->assertFalse( $this->shows( array( 'agend_access_rules' => array( self::MEMBER ), 'agend_access_invert' => 'no' ) ) );
	}

	/** The section's rules AND its page's. */
	#[Test]
	public function a_section_is_shown_only_when_its_own_rules_and_its_pages_both_allow(): void {
		Agend_Test_WP::$queried_object_id = 311;
		$this->registerPost( 311, '' );
		$this->pageRules( 311, array( 'agend_membership:' . self::TIER_B ) );

		$own = array( 'agend_access_rules' => array( self::MEMBER ) );

		$this->asMember( self::TIER_A );
		$this->assertFalse( $this->shows( $own ), 'its own rules admit them, its page\'s do not' );

		$this->newVisitor();
		$this->asMember( self::TIER_A, self::TIER_B );
		$this->assertTrue( $this->shows( $own ) );
		$this->assertFalse( $this->shows( array( 'agend_access_rules' => array( 'agend_membership:' . self::TIER_A ), 'agend_access_invert' => 'yes' ) ), 'a reverse of what they hold' );
	}

	#[Test]
	public function a_conditions_only_section_is_narrowed_by_its_page_too(): void {
		Agend_Test_WP::$queried_object_id = 312;
		$this->registerPost( 312, '' );
		$this->pageRules( 312, array( self::MEMBER ) );

		$this->assertFalse( $this->shows( array( 'agend_conditions_enabled' => 'yes', 'agend_conditions' => array( 'agend_wordpress:logged_out' ) ) ) );
	}

	#[Test]
	public function a_section_with_an_old_policy_and_old_conditions_needs_both(): void {
		$settings = array(
			'agend_access_mode'        => 'active_member',
			'agend_conditions_enabled' => 'yes',
			'agend_conditions'         => array( 'agend_wordpress:role_editor' ),
		);

		$GLOBALS['agend_test_current_user_roles'] = array( 'subscriber' );
		$this->asMember( self::TIER_A );
		$this->assertFalse( $this->shows( $settings ), 'a member, not an editor' );

		$this->newVisitor();
		$GLOBALS['agend_test_current_user_roles'] = array( 'editor' );
		$this->asMember( self::TIER_A );
		$this->assertTrue( $this->shows( $settings ), 'a member and an editor' );

		$this->newVisitor();
		$GLOBALS['agend_test_current_user_roles'] = array( 'editor' );
		$this->asMember();
		$this->assertFalse( $this->shows( $settings ), 'an editor, not a member' );
	}

	#[Test]
	public function a_section_in_a_feed_is_judged_for_nobody(): void {
		$GLOBALS['agend_test_is_feed'] = true;
		$this->asMember( self::TIER_A );

		$this->assertFalse( $this->shows( array( 'agend_access_rules' => array( self::MEMBER ) ) ) );
		$this->assertTrue( $this->shows( array( 'agend_access_rules' => array( self::MEMBER ), 'agend_access_invert' => 'yes' ) ) );
		$this->assertSame( 0, $this->identity_lookups );

		unset( $GLOBALS['agend_test_is_feed'] );
	}

	#[Test]
	public function a_section_replaces_itself_only_when_the_rules_carrying_the_fallback_denied(): void {
		$this->publishFallback();

		$conditions = array(
			'agend_conditions_enabled'     => 'yes',
			'agend_conditions'             => array( 'agend_wordpress:logged_in' ),
			'agend_conditions_fallback_id' => (string) self::FALLBACK,
		);

		$output = function ( array $settings ): string {
			ob_start();
			( new ElementorControl() )->maybe_render_fallback( $this->element( $settings ) );

			return (string) ob_get_clean();
		};

		$this->assertSame( 'FALLBACK TEXT', $output( $conditions ), 'the conditions deny an anonymous visitor' );

		$GLOBALS['agend_test_current_user_id'] = 7;
		$this->assertSame( '', $output( $conditions + array( 'agend_access_mode' => 'active_member' ) ), 'the conditions admit them: the audience that hid it gets no replacement' );
	}

	// -----------------------------------------------------------------------
	// An Elementor section, as Elementor presents it
	// -----------------------------------------------------------------------

	/**
	 * What `get_settings_for_display()` returns for an element that stores these settings:
	 * the controls' defaults added, and every setting whose control is hidden read as
	 * null. Suppression and the fallback read THIS, not what is stored, so a control that
	 * is hidden can only be safe if it never changes who sees the element.
	 */
	private function presented( array $stored ): array {
		return ElementorActiveSettings::for_display( ElementorControl::access_controls(), $stored );
	}

	/** Whether an element that stores these settings is shown to the visitor. */
	private function showsStored( array $stored ): bool {
		return $this->shows( $this->presented( $stored ) );
	}

	/** The replacement content an element that stores these settings renders. */
	private function replacementFor( array $stored ): string {
		ob_start();
		( new ElementorControl() )->maybe_render_fallback( $this->element( $this->presented( $stored ) ) );

		return (string) ob_get_clean();
	}

	/** A visitor on plan B, in segment B: holds nothing plan A or segment A asks for. */
	private function asPlanBInSegmentB(): void {
		$this->asMember( self::TIER_B );

		Agend_Test_WP::set_filter(
			'agend_apps_crm_get_my_segments_response',
			array( 'data' => array( array( 'id' => self::SEG_B, 'slug' => 'slug-bbbb' ) ) )
		);
	}

	#[Test]
	public function a_stored_rule_list_is_enforced_through_what_elementor_presents(): void {
		$stored = array( 'agend_access_rules' => array( 'agend_membership:' . self::TIER_A ) );

		$this->assertFalse( $this->showsStored( $stored ), 'anonymous' );

		$this->newVisitor();
		$this->asMember( self::TIER_B );
		$this->assertFalse( $this->showsStored( $stored ), 'a member on another plan' );

		$this->newVisitor();
		$this->asMember( self::TIER_A );
		$this->assertTrue( $this->showsStored( $stored ) );
	}

	#[Test]
	public function a_stored_reverse_is_the_join_banner_and_match_all_needs_every_rule(): void {
		$banner = array( 'agend_access_rules' => array( self::MEMBER ), 'agend_access_invert' => 'yes' );

		$this->assertTrue( $this->showsStored( $banner ), 'anonymous' );

		$this->newVisitor();
		$this->asMember( self::TIER_A );
		$this->assertFalse( $this->showsStored( $banner ), 'a member' );

		$all = array( 'agend_access_rules' => array( self::MEMBER, 'agend_wordpress:role_editor' ), 'agend_access_match' => 'all' );

		$this->assertFalse( $this->showsStored( $all ), 'a member who is not an editor' );
	}

	/** Emptying the list is how an author removes the rule: nothing is left to restrict the element. */
	#[Test]
	public function an_emptied_list_shows_the_element_even_with_a_reverse_and_a_replacement_still_stored(): void {
		$this->assertTrue(
			$this->showsStored( array( 'agend_access_rules' => array(), 'agend_access_match' => 'all', 'agend_access_invert' => 'yes', 'agend_conditions_fallback_id' => (string) self::FALLBACK ) )
		);
		$this->assertSame( 0, $this->identity_lookups );
	}

	/** An element nobody has set a rule on costs no lookup, with the controls registered. */
	#[Test]
	public function an_untouched_element_costs_no_lookup_with_the_controls_registered(): void {
		$this->assertTrue( $this->showsStored( array() ) );
		$this->assertSame( 0, $this->identity_lookups );
	}

	/** An element saved before the rule list, whose older setting has no control now, is still enforced. */
	#[Test]
	public function an_older_audience_is_still_enforced_though_it_has_no_list_of_its_own(): void {
		$stored = array( 'agend_access_mode' => 'selected_tiers', 'agend_access_tier_ids' => array( self::TIER_A ) );

		$this->assertFalse( $this->showsStored( $stored ), 'anonymous' );

		$this->newVisitor();
		$this->asMember( self::TIER_B );
		$this->assertFalse( $this->showsStored( $stored ), 'a member on another plan' );

		$this->newVisitor();
		$this->asMember( self::TIER_A );
		$this->assertTrue( $this->showsStored( $stored ) );
	}

	#[Test]
	public function older_display_conditions_are_still_enforced_with_their_lists_unregistered(): void {
		$stored = array(
			'agend_conditions_enabled' => 'yes',
			'agend_conditions'         => array( 'agend_wordpress:logged_in' ),
			'agend_conditions_invert'  => 'yes',
		);

		$this->assertTrue( $this->showsStored( $stored ), 'anonymous: the reverse of signed in' );

		$GLOBALS['agend_test_current_user_id'] = 7;
		$this->assertFalse( $this->showsStored( $stored ), 'signed in' );
	}

	/** Choosing Remove writes `inherit`, and the lists left behind are inert. */
	#[Test]
	public function removing_the_older_audience_removes_it_and_nothing_else(): void {
		$removed = array(
			'agend_access_mode'     => 'inherit',
			'agend_access_tier_ids' => array( self::TIER_A ),
			'agend_access_rules'    => array( 'agend_wordpress:logged_out' ),
		);

		$this->assertTrue( $this->showsStored( $removed ), 'anonymous: the new rule admits them and the older audience is gone' );

		$GLOBALS['agend_test_current_user_id'] = 7;
		$this->assertFalse( $this->showsStored( $removed ), 'the new rule still applies' );
		$this->assertTrue( $this->showsStored( array( 'agend_access_mode' => 'inherit', 'agend_access_tier_ids' => array( self::TIER_A ) ) ), 'with no rule left it inherits its page' );
	}

	#[Test]
	public function switching_the_older_conditions_off_removes_them_and_nothing_else(): void {
		$stored = array(
			'agend_conditions_enabled' => '',
			'agend_conditions'         => array( 'agend_wordpress:logged_in' ),
			'agend_access_rules'       => array( 'agend_wordpress:logged_out' ),
		);

		$this->assertTrue( $this->showsStored( $stored ), 'anonymous: the new rule admits them, and the older condition no longer says signed in' );
	}

	/**
	 * The point of keeping the older setting: an element with rules AND an older
	 * audience is shown only to somebody who passes both, so adding a rule beside an
	 * older restriction can never widen it.
	 */
	#[Test]
	public function an_element_with_new_rules_and_an_older_audience_needs_both(): void {
		$stored = array(
			'agend_access_rules'       => array( 'agend_membership:' . self::TIER_A ),
			'agend_access_mode'        => 'segments',
			'agend_access_segment_ids' => array( self::SEG_B ),
		);

		$this->asMemberOf( array( self::SEG_A ) );
		$this->assertFalse( $this->showsStored( $stored ), 'passes the rule on plan A, not the older segment' );

		$this->newVisitor();
		$this->asPlanBInSegmentB();
		$this->assertFalse( $this->showsStored( $stored ), 'passes the older segment, not the rule on plan A' );

		$this->newVisitor();
		$this->asMemberOf( array( self::SEG_B ) );
		$this->assertTrue( $this->showsStored( $stored ), 'passes both' );

		$this->newVisitor();
		$this->anonymous();
		$this->assertFalse( $this->showsStored( $stored ), 'passes neither' );
	}

	#[Test]
	public function an_element_with_new_rules_and_older_display_conditions_needs_both(): void {
		$stored = array(
			'agend_access_rules'       => array( self::MEMBER ),
			'agend_conditions_enabled' => 'yes',
			'agend_conditions'         => array( 'agend_wordpress:role_editor' ),
		);

		$GLOBALS['agend_test_current_user_roles'] = array( 'subscriber' );
		$this->asMember( self::TIER_A );
		$this->assertFalse( $this->showsStored( $stored ), 'a member, not an editor' );

		$this->newVisitor();
		$GLOBALS['agend_test_current_user_roles'] = array( 'editor' );
		$this->asMember();
		$this->assertFalse( $this->showsStored( $stored ), 'an editor, not a member' );

		$this->newVisitor();
		$GLOBALS['agend_test_current_user_roles'] = array( 'editor' );
		$this->asMember( self::TIER_A );
		$this->assertTrue( $this->showsStored( $stored ), 'both' );
	}

	/** An older restriction narrows a section that has an empty rule list beside it. */
	#[Test]
	public function an_older_restriction_beside_an_emptied_list_still_narrows(): void {
		$stored = array( 'agend_access_rules' => array(), 'agend_access_mode' => 'active_member' );

		$this->assertFalse( $this->showsStored( $stored ), 'anonymous' );

		$this->newVisitor();
		$this->asMember( self::TIER_A );
		$this->assertTrue( $this->showsStored( $stored ) );
	}

	/** The page's rules still narrow a section whichever way it is set up. */
	#[Test]
	public function a_page_that_denies_removes_a_section_with_rules_or_with_an_older_setting(): void {
		Agend_Test_WP::$queried_object_id = 313;
		$this->registerPost( 313, '' );
		$this->pageRules( 313, array( 'agend_membership:' . self::TIER_B ) );
		$this->asMember( self::TIER_A );

		$this->assertFalse( $this->showsStored( array( 'agend_access_rules' => array( self::MEMBER ) ) ) );
		$this->assertFalse( $this->showsStored( array( 'agend_access_mode' => 'active_member' ) ) );
	}

	// -- replacement content --------------------------------------------

	#[Test]
	public function a_stored_replacement_shows_to_somebody_the_new_rules_refuse(): void {
		$this->publishFallback();

		$stored = array( 'agend_access_rules' => array( self::MEMBER ), 'agend_conditions_fallback_id' => (string) self::FALLBACK );

		$this->assertSame( 'FALLBACK TEXT', $this->replacementFor( $stored ), 'anonymous' );

		$this->newVisitor();
		$this->asMember( self::TIER_A );
		$this->assertSame( '', $this->replacementFor( $stored ), 'a member sees the section itself' );
	}

	#[Test]
	public function an_element_with_only_older_conditions_keeps_its_replacement_content(): void {
		$this->publishFallback();

		$stored = array(
			'agend_conditions_enabled'     => 'yes',
			'agend_conditions'             => array( 'agend_wordpress:logged_in' ),
			'agend_conditions_fallback_id' => (string) self::FALLBACK,
		);

		$this->assertSame( 'FALLBACK TEXT', $this->replacementFor( $stored ), 'the empty rule list beside them must not take it' );
	}

	/** An element a protected audience hid is protected content: no consolation render. */
	#[Test]
	public function an_older_audience_that_hid_the_element_gets_no_replacement(): void {
		$this->publishFallback();

		$stored = array(
			'agend_access_rules'           => array( 'agend_wordpress:logged_out' ),
			'agend_conditions_fallback_id' => (string) self::FALLBACK,
			'agend_access_mode'            => 'active_member',
		);

		$this->assertSame( '', $this->replacementFor( $stored ), 'the rules admit an anonymous visitor, the older audience does not: the replacement belongs to the rules' );

		$this->assertSame( 'FALLBACK TEXT', $this->replacementFor( array( 'agend_access_rules' => array( 'agend_wordpress:logged_in' ), 'agend_conditions_fallback_id' => (string) self::FALLBACK, 'agend_access_mode' => 'active_member' ) ), 'the rules refuse them, so the replacement shows' );
	}

	/** A replacement is not shown for an element that has nothing hiding it. */
	#[Test]
	public function an_element_with_no_rules_renders_no_replacement(): void {
		$this->publishFallback();

		$this->assertSame( '', $this->replacementFor( array( 'agend_conditions_fallback_id' => (string) self::FALLBACK ) ) );
	}

	// -----------------------------------------------------------------------
	// The page gate
	// -----------------------------------------------------------------------

	#[Test]
	public function the_page_gate_reads_the_new_rules_and_returns_them(): void {
		$this->registerPost( 320, '' );
		$this->pageRules( 320, array( 'agend_membership:' . self::TIER_A ) );

		$denied = Agend_Content_Access_Decision::for_post( 320 );

		$this->assertSame( Agend_Content_Access_Decision::STATE_AUTH, $denied['state'] );
		$this->assertInstanceOf( Rules::class, $denied['rules'] );
		$this->assertSame( array( 'agend_membership:' . self::TIER_A ), $denied['rules']->rules() );
		$this->assertSame( array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A ) ), $denied['policy'], 'for the gate copy that names plans' );

		$this->newVisitor();
		$this->asMember( self::TIER_A );
		$this->assertSame( Agend_Content_Access_Decision::STATE_GRANTED, Agend_Content_Access_Decision::for_post( 320 )['state'] );

		$this->newVisitor();
		$this->asMember( self::TIER_B );
		$this->assertSame( Agend_Content_Access_Decision::STATE_PLAN, Agend_Content_Access_Decision::for_post( 320 )['state'] );
	}

	#[Test]
	public function a_page_with_rules_no_policy_can_say_still_denies_and_names_nothing(): void {
		$this->registerPost( 321, '' );
		$this->pageRules( 321, array( 'agend_wordpress:role_editor' ) );

		$decision = Agend_Content_Access_Decision::for_post( 321 );

		$this->assertNotSame( Agend_Content_Access_Decision::STATE_GRANTED, $decision['state'] );
		$this->assertSame( Policy::UNSATISFIABLE, $decision['policy'] );
		$this->assertStringNotContainsString( 'plans', Agend_Content_Access_Frontend::render_gate( '', $decision['state'], $decision['policy'] ) );
	}

	#[Test]
	public function a_page_whose_new_rules_cannot_be_read_denies_everyone_but_an_editor(): void {
		$this->registerPost( 322, '' );
		$GLOBALS['agend_test_post_meta'][322][ Rules::META_KEY ] = array( 'rules' => array( self::MEMBER ), 'match' => 'some' );

		$this->asMember( self::TIER_A );
		$this->assertNotSame( Agend_Content_Access_Decision::STATE_GRANTED, Agend_Content_Access_Decision::for_post( 322 )['state'] );

		$this->asEditor();
		$preview = Agend_Content_Access_Decision::for_post( 322 );

		$this->assertSame( Agend_Content_Access_Decision::STATE_GRANTED, $preview['state'] );
		$this->assertTrue( $preview['preview'] );
	}

	#[Test]
	public function a_feed_judges_a_page_for_nobody(): void {
		$this->registerPost( 323, '' );
		$this->pageRules( 323, array( self::MEMBER ) );
		$this->asMember( self::TIER_A );

		$decision = Agend_Content_Access_Decision::for_post( 323, true );

		$this->assertNotSame( Agend_Content_Access_Decision::STATE_GRANTED, $decision['state'] );
		$this->assertSame( 0, $this->identity_lookups );
		$this->assertSame( array(), Agend_Test_WP::$requests );
	}

	#[Test]
	public function a_denied_rest_item_does_not_expose_either_stored_meta(): void {
		$this->registerPost( 324, '' );
		$this->pageRules( 324, array( 'agend_membership:' . self::TIER_A ) );

		$frontend = new Agend_Content_Access_Frontend();
		$response = new \WP_REST_Response(
			array(
				'id'      => 324,
				'content' => array( 'rendered' => 'BODY', 'protected' => false ),
				'excerpt' => array( 'rendered' => 'EXCERPT', 'protected' => false ),
				'meta'    => array(
					Policy::META_KEY    => array( 'mode' => 'active_member' ),
					Rules::META_KEY     => array( 'rules' => array( 'agend_membership:' . self::TIER_A ) ),
					'footnotes'         => '[]',
				),
			)
		);

		$filtered = $frontend->filter_rest_response( $response, get_post( 324 ), new \WP_REST_Request() )->get_data();

		$this->assertSame( '', $filtered['content']['rendered'] );
		$this->assertArrayNotHasKey( Policy::META_KEY, $filtered['meta'] );
		$this->assertArrayNotHasKey( Rules::META_KEY, $filtered['meta'] );
		$this->assertStringNotContainsString( self::TIER_A, (string) wp_json_encode( $filtered ) );
	}

	// -----------------------------------------------------------------------
	// Export
	// -----------------------------------------------------------------------

	private function exportedPost( int $id, array $meta ): \WP_Post {
		$GLOBALS['agend_test_posts'][ $id ]     = array(
			'ID'            => $id,
			'post_status'   => 'publish',
			'post_type'     => 'page',
			'post_title'    => 'Page ' . $id,
			'post_name'     => 'page-' . $id,
			'post_content'  => '',
			'post_author'   => 1,
		);
		$GLOBALS['agend_test_post_meta'][ $id ] = $meta;

		return get_post( $id );
	}

	#[Test]
	public function the_connector_exports_a_pages_rules_as_the_policy_that_says_them(): void {
		$post = $this->exportedPost( 330, array( Rules::META_KEY => array( 'rules' => array( 'agend_membership:' . self::TIER_A, 'agend_membership:' . self::TIER_B ) ) ) );

		$this->assertSame(
			array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A, self::TIER_B ) ),
			Exporter::record( $post )['access_policy']
		);
	}

	#[Test]
	public function the_connector_exports_rules_no_policy_can_say_as_nobody(): void {
		foreach ( array(
			array( 'rules' => array( 'agend_wordpress:role_editor' ) ),
			array( 'rules' => array( self::MEMBER ), 'invert' => true ),
			array( 'rules' => array( 'agend_membership:' . self::TIER_A, 'agend_membership:' . self::TIER_B ), 'match' => 'all' ),
		) as $rules ) {
			$post = $this->exportedPost( 331, array( Rules::META_KEY => $rules ) );

			$this->assertSame( Policy::UNSATISFIABLE, Exporter::record( $post )['access_policy'], (string) wp_json_encode( $rules ) );
		}
	}

	#[Test]
	public function the_connector_refuses_a_page_whose_new_rules_cannot_be_read(): void {
		$post = $this->exportedPost( 332, array( Rules::META_KEY => 'members' ) );

		$this->expectException( RuntimeException::class );

		Exporter::record( $post );
	}

	/** A page with no rules, or an old public policy, is exported as having none, as an unset page always was. */
	#[Test]
	public function a_page_with_no_rules_exports_no_policy(): void {
		$this->assertNull( Exporter::record( $this->exportedPost( 333, array() ) )['access_policy'] );
		$this->assertNull( Exporter::record( $this->exportedPost( 334, array( Rules::META_KEY => array( 'rules' => array() ) ) ) )['access_policy'] );
	}

	/** A revision that did not change when the rules did would let Agend keep serving the old audience. */
	#[Test]
	public function the_revision_changes_when_the_rules_do_even_when_the_policy_they_export_as_does_not(): void {
		$role   = Exporter::revision_of( $this->exportedPost( 335, array( Rules::META_KEY => array( 'rules' => array( 'agend_wordpress:role_editor' ) ) ) ) );
		$other  = Exporter::revision_of( $this->exportedPost( 335, array( Rules::META_KEY => array( 'rules' => array( 'agend_wordpress:role_author' ) ) ) ) );
		$same   = Exporter::revision_of( $this->exportedPost( 335, array( Rules::META_KEY => array( 'rules' => array( 'agend_wordpress:role_editor' ) ) ) ) );
		$none   = Exporter::revision_of( $this->exportedPost( 335, array() ) );
		$broken = Exporter::revision_of( $this->exportedPost( 335, array( Rules::META_KEY => 'members' ) ) );

		$this->assertNotSame( $role, $other );
		$this->assertSame( $role, $same );
		$this->assertNotSame( $role, $none );
		$this->assertNotSame( $broken, $none, 'unreadable rules have a marker of their own' );
		$this->assertNotSame( $broken, $role );
	}

	#[Test]
	public function the_exporter_folds_a_blocks_rules_into_the_fragment_policy(): void {
		$content = $this->paragraph( 'Open' )
			. $this->paragraph( 'Plan', $this->ruled( array( 'agend_membership:' . self::TIER_A ) ) )
			. $this->paragraph( 'Role', $this->ruled( array( 'agend_wordpress:role_editor' ) ) );
		$post    = $this->exportedPost( 336, array() );

		$GLOBALS['agend_test_posts'][336]['post_content'] = $content;

		$policies = array_map(
			static fn( array $fragment ): array => $fragment['policy'],
			Exporter::fragments_of( get_post( 336 ), null )
		);

		$this->assertSame( array( 'mode' => 'inherit' ), $policies[0] );
		$this->assertSame( array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A ) ), $policies[1] );
		$this->assertSame( Policy::UNSATISFIABLE, $policies[2], 'a role cannot be said, so Agend hides the block from everyone' );
		$this->assertInstanceOf( \WP_Post::class, $post );
	}
}
