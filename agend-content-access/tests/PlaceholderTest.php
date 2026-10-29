<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Access as Access;
use Agend_Content_Access_Catalogue;
use Agend_Content_Access_Decision as Decision;
use Agend_Content_Access_Frontend;
use Agend_Content_Access_Placeholder as Placeholder;
use Agend_Content_Access_Rules as Rules;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use WP_Post;

require_once __DIR__ . '/has-elementor-double.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-policy.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-catalogue.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-meta-box.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-viewer-facts.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-decision.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-frontend.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-placeholder.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-fragment-rules.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-block-attributes.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-conditions.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-providers.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-runtime.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-rules.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-access.php';

/**
 * What a visitor sees in place of a page they are not allowed to read.
 *
 * The words follow why they were refused and nothing else about them. The tests
 * are about what is on the page: which heading, which message, which buttons,
 * and above all what is NOT, since this is shown to somebody the rules refused.
 */
#[CoversClass( Placeholder::class )]
#[CoversClass( Access::class )]
final class PlaceholderTest extends TestCase {

	private const TIER_A  = '11111111-1111-1111-1111-111111111111';
	private const SEG_A   = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1';
	private const PORTAL  = 'https://portal.example.test';
	private const MEMBER  = 'agend_membership:is_member';
	private const PLAN    = 'agend_membership:' . self::TIER_A;
	private const SEGMENT = 'agend_segments:' . self::SEG_A;

	// -----------------------------------------------------------------------
	// Fixtures
	// -----------------------------------------------------------------------

	/** A denial, as `Decision::for_post()` returns one. */
	private function decision( string $state, array $rules = array( self::MEMBER ), bool $invert = false ): array {
		$set = Rules::from_array( array( 'rules' => $rules, 'match' => 'any', 'invert' => $invert ) );

		return array(
			'state'    => $state,
			'policy'   => $set->to_policy(),
			'rules'    => $set,
			'preview'  => false,
			'degraded' => false,
		);
	}

	private function page( string $excerpt = '', string $body = 'THE PROTECTED BODY' ): WP_Post {
		return new WP_Post(
			array(
				'ID'           => 77,
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_title'   => 'A members page',
				'post_content' => $body,
				'post_excerpt' => $excerpt,
			)
		);
	}

	private function withPortal(): void {
		update_option( 'agend_apps_portal_url', self::PORTAL );
	}

	private function seedPlans(): void {
		Agend_Test_WP::$tiers_response = array(
			'data' => array(
				array( 'id' => self::TIER_A, 'name' => 'Full Member', 'slug' => 'full', 'tier_type' => 'individual', 'is_active' => true ),
			),
		);
	}

	/** @return string[] The visible text of each button, in order. */
	private function buttons( string $html ): array {
		preg_match_all( '/<a class="agend-access-placeholder__button[^"]*" href="[^"]*">([^<]*)<\/a>/', $html, $matches );

		return $matches[1];
	}

	// -----------------------------------------------------------------------
	// Copy by state
	// -----------------------------------------------------------------------

	#[Test]
	public function a_signed_out_visitor_to_a_members_page_is_asked_to_sign_in_and_offered_to_join(): void {
		$this->withPortal();

		$html = Placeholder::render( $this->decision( Decision::STATE_AUTH ), $this->page() );

		$this->assertStringContainsString( 'Sign in to read this', $html );
		$this->assertStringContainsString( 'Please sign in to read it', $html );
		$this->assertSame( array( 'Sign in', 'Join' ), $this->buttons( $html ) );
	}

	/** Joining is no help when no rule on the page is about membership. */
	#[Test]
	public function a_signed_out_visitor_to_a_page_with_no_membership_rule_is_not_offered_to_join(): void {
		$this->withPortal();

		$html = Placeholder::render( $this->decision( Decision::STATE_AUTH, array( 'agend_wordpress:role_editor' ) ), $this->page() );

		$this->assertSame( array( 'Sign in' ), $this->buttons( $html ) );
	}

	#[Test]
	public function a_visitor_who_holds_no_membership_is_asked_to_join(): void {
		$this->withPortal();

		$html = Placeholder::render( $this->decision( Decision::STATE_MEMBERSHIP ), $this->page() );

		$this->assertStringContainsString( 'Join to read this', $html );
		$this->assertStringContainsString( 'An active membership is required', $html );
		$this->assertSame( array( 'Join' ), $this->buttons( $html ) );
		$this->assertStringNotContainsString( 'Sign in', $html );
	}

	#[Test]
	public function a_member_on_the_wrong_plan_is_told_which_plans_would_do(): void {
		$this->withPortal();
		$this->seedPlans();

		$html = Placeholder::render( $this->decision( Decision::STATE_PLAN, array( self::PLAN ) ), $this->page() );

		$this->assertStringContainsString( 'Available on another plan', $html );
		$this->assertStringContainsString( 'This content is available to members on: Full Member.', $html );
		$this->assertSame( array( 'View membership options' ), $this->buttons( $html ) );
		$this->assertStringNotContainsString( self::TIER_A, $html, 'the plan is named, its id is not shown' );
	}

	#[Test]
	public function an_unreachable_plan_list_falls_back_to_generic_plan_copy(): void {
		$this->withPortal();
		Agend_Test_WP::$tiers_response = new \WP_Error( 'http_request_failed', 'down' );

		$html = Placeholder::render( $this->decision( Decision::STATE_PLAN, array( self::PLAN ) ), $this->page() );

		$this->assertStringContainsString( 'members on another plan', $html );
	}

	/**
	 * A segment's name can describe the visitor ("Overdue renewals"). It is not
	 * theirs to be told, so nothing about the segment reaches the page, and the
	 * markup is the same whichever segment it was.
	 */
	#[Test]
	public function the_restricted_message_never_names_a_segment_and_offers_nothing_to_click(): void {
		$this->withPortal();
		Agend_Test_WP::$transients[ Agend_Content_Access_Catalogue::SEGMENTS_TRANSIENT ] = array(
			array( 'id' => self::SEG_A, 'slug' => 'overdue', 'name' => 'Overdue renewals' ),
		);

		$html = Placeholder::render( $this->decision( Decision::STATE_RESTRICTED, array( self::SEGMENT ) ), $this->page() );

		$this->assertStringContainsString( 'This content is restricted', $html );
		$this->assertStringContainsString( 'limited to selected members', $html );
		$this->assertStringNotContainsString( 'Overdue renewals', $html );
		$this->assertStringNotContainsString( self::SEG_A, $html );
		$this->assertStringNotContainsString( 'overdue', $html );
		$this->assertStringNotContainsString( '<a ', $html );
		$this->assertSame(
			$html,
			Placeholder::render( $this->decision( Decision::STATE_RESTRICTED, array( 'agend_segments:bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbb2' ) ), $this->page() )
		);
	}

	#[Test]
	public function the_restricted_message_names_the_entitlements_a_page_needs_as_the_loop_gate_does(): void {
		Agend_Test_WP::$transients[ Agend_Content_Access_Catalogue::ENTITLEMENTS_TRANSIENT ] = array(
			array( 'gate_key' => 'directory.access', 'name' => 'Directory access', 'description' => '', 'kind' => 'platform-gate' ),
		);

		$html = Placeholder::render( $this->decision( Decision::STATE_RESTRICTED, array( 'agend_entitlements:directory.access' ) ), $this->page() );

		$this->assertStringContainsString( 'limited to people who hold: Directory access.', $html );
		$this->assertStringNotContainsString( 'directory.access', $html, 'the key is not for visitors' );
	}

	/** A state a later release adds is the one with nothing to say and nothing to click. */
	#[Test]
	public function a_state_this_version_does_not_know_reads_as_restricted(): void {
		$this->withPortal();

		foreach ( array( 'some_future_state', Decision::STATE_GRANTED, '' ) as $state ) {
			$html = Placeholder::render( $this->decision( $state ), $this->page() );

			$this->assertStringContainsString( 'This content is restricted', $html, $state );
			$this->assertSame( array(), $this->buttons( $html ), $state );
			$this->assertStringContainsString( 'agend-access-placeholder--access_restricted', $html, $state );
		}
	}

	// -----------------------------------------------------------------------
	// Links
	// -----------------------------------------------------------------------

	#[Test]
	public function the_buttons_go_to_the_portal_and_bring_the_visitor_back(): void {
		$this->withPortal();

		$html = Placeholder::render( $this->decision( Decision::STATE_AUTH ), $this->page() );

		$this->assertStringContainsString( 'href="' . self::PORTAL . '?return_to=', $html );
	}

	/** A button that goes nowhere is worse than no button. */
	#[Test]
	#[DataProvider( 'statesWithButtons' )]
	public function a_state_with_no_portal_configured_has_no_buttons_and_keeps_its_words( string $state ): void {
		$html = Placeholder::render( $this->decision( $state ), $this->page() );

		$this->assertSame( array(), $this->buttons( $html ) );
		$this->assertStringNotContainsString( '<a ', $html );
		$this->assertStringContainsString( '<h2 class="agend-access-placeholder__heading">', $html );
		$this->assertStringContainsString( 'agend-access-placeholder__message', $html );
	}

	/** @return array<string, array{0: string}> */
	public static function statesWithButtons(): array {
		return array(
			'signed out'       => array( Decision::STATE_AUTH ),
			'holds nothing'    => array( Decision::STATE_MEMBERSHIP ),
			'another plan'     => array( Decision::STATE_PLAN ),
		);
	}

	// -----------------------------------------------------------------------
	// The summary
	// -----------------------------------------------------------------------

	#[Test]
	public function the_manual_excerpt_is_shown_above_the_message(): void {
		$html = Placeholder::render( $this->decision( Decision::STATE_AUTH ), $this->page( 'A public summary.' ) );

		$this->assertStringContainsString( '<div class="agend-access-placeholder__summary"><p>A public summary.</p></div>', $html );
		$this->assertLessThan( strpos( $html, 'Sign in to read this' ), strpos( $html, 'A public summary.' ) );
	}

	/** An automatic excerpt is the first words of the body, which is what is being withheld. */
	#[Test]
	public function a_page_with_no_manual_excerpt_shows_no_summary_and_no_part_of_its_body(): void {
		$html = Placeholder::render( $this->decision( Decision::STATE_AUTH ), $this->page( '', 'THE PROTECTED BODY, which has a first paragraph.' ) );

		$this->assertStringNotContainsString( 'agend-access-placeholder__summary', $html );
		$this->assertStringNotContainsString( 'PROTECTED', $html );
	}

	#[Test]
	public function the_summary_is_escaped_and_cannot_run_a_shortcode(): void {
		$html = Placeholder::render(
			$this->decision( Decision::STATE_AUTH ),
			$this->page( 'A <script>alert(1)</script> summary with [some_shortcode] in it.' )
		);

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( '[some_shortcode]', $html, 'the shortcode pass over the content must not find it' );
		$this->assertStringContainsString( '&#91;some_shortcode&#93;', $html );
	}

	/** The content filters run over this markup again, so it must have nothing for them to break. */
	#[Test]
	public function the_markup_has_no_newlines_for_wpautop_to_turn_into_line_breaks(): void {
		$this->withPortal();

		$html = Placeholder::render( $this->decision( Decision::STATE_AUTH ), $this->page( "First line.\nSecond line.\n\nSecond paragraph." ) );

		$this->assertStringNotContainsString( "\n", $html );
	}

	// -----------------------------------------------------------------------
	// Filters
	// -----------------------------------------------------------------------

	#[Test]
	public function the_heading_and_message_are_filterable_and_still_escaped(): void {
		Agend_Test_WP::$filters['agend_content_access_placeholder_heading'] = static fn ( string $heading, string $state ): string => 'Heading for ' . $state . ' <i>x</i>';
		Agend_Test_WP::$filters['agend_content_access_placeholder_message'] = static fn ( string $message, string $state, $post ): string => 'Message for page ' . $post->ID . ' <b>x</b>';

		$html = Placeholder::render( $this->decision( Decision::STATE_MEMBERSHIP ), $this->page() );

		$this->assertStringContainsString( 'Heading for membership_required &lt;i&gt;x&lt;/i&gt;', $html );
		$this->assertStringContainsString( 'Message for page 77 &lt;b&gt;x&lt;/b&gt;', $html );
	}

	#[Test]
	public function the_buttons_are_filterable_and_anything_that_is_not_a_labelled_link_is_dropped(): void {
		$this->withPortal();

		Agend_Test_WP::$filters['agend_content_access_placeholder_buttons'] = static fn ( array $buttons ): array => array(
			array( 'label' => 'Apply', 'url' => 'https://example.test/apply', 'kind' => 'join' ),
			array( 'label' => '', 'url' => 'https://example.test/blank-label' ),
			array( 'label' => 'No link', 'url' => '' ),
			array( 'label' => 'Not a string', 'url' => array( 'https://example.test' ) ),
			'a string',
		);

		$html = Placeholder::render( $this->decision( Decision::STATE_AUTH ), $this->page() );

		$this->assertSame( array( 'Apply' ), $this->buttons( $html ) );
		$this->assertStringContainsString( 'agend-access-placeholder__button--join', $html );
	}

	#[Test]
	public function the_whole_markup_is_filterable(): void {
		Agend_Test_WP::$filters['agend_content_access_placeholder_html'] = static fn ( string $html, string $state, $post ): string => '<section data-state="' . $state . '" data-post="' . $post->ID . '"></section>';

		$this->assertSame(
			'<section data-state="authentication_required" data-post="77"></section>',
			Placeholder::render( $this->decision( Decision::STATE_AUTH ), $this->page() )
		);
	}

	// -----------------------------------------------------------------------
	// Style
	// -----------------------------------------------------------------------

	#[Test]
	public function the_stylesheet_is_enqueued_when_a_placeholder_is_rendered_and_not_before(): void {
		$this->assertSame( array(), Agend_Test_WP::$enqueued_styles );

		Placeholder::render( $this->decision( Decision::STATE_AUTH ), $this->page() );

		$this->assertSame( array( Placeholder::STYLE_HANDLE ), Agend_Test_WP::$enqueued_styles );
		$this->assertSame( array( Placeholder::CSS ), Agend_Test_WP::$inline_styles[ Placeholder::STYLE_HANDLE ] );
	}

	#[Test]
	public function rendering_twice_adds_the_style_once(): void {
		Placeholder::render( $this->decision( Decision::STATE_AUTH ), $this->page() );
		Placeholder::render( $this->decision( Decision::STATE_MEMBERSHIP ), $this->page() );

		$this->assertCount( 1, Agend_Test_WP::$inline_styles[ Placeholder::STYLE_HANDLE ] );
	}

	// -----------------------------------------------------------------------
	// The shortcode
	// -----------------------------------------------------------------------

	private function requestDeniedPage( string $excerpt = 'A public summary.' ): void {
		$GLOBALS['agend_test_posts'][ 77 ] = array(
			'ID'           => 77,
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_title'   => 'A members page',
			'post_content' => 'THE PROTECTED BODY',
			'post_excerpt' => $excerpt,
		);
		$GLOBALS['agend_test_post_meta'][ 77 ][ Rules::META_KEY ] = array( 'rules' => array( self::MEMBER ), 'match' => 'any', 'invert' => false );

		$GLOBALS['agend_test_current_user_can']['edit_post'] = false;
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', '' );

		$GLOBALS['agend_test_is_singular']    = true;
		$GLOBALS['agend_test_queried_object'] = get_post( 77 );

		( new Agend_Content_Access_Frontend() )->gate_request();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['agend_test_is_singular'], $GLOBALS['agend_test_queried_object'] );

		parent::tearDown();
	}

	#[Test]
	public function the_shortcode_is_registered(): void {
		Placeholder::init();

		$this->assertArrayHasKey( 'agend_access_message', $GLOBALS['agend_test_shortcodes'] );
		$this->assertSame( '<p>x</p>', do_shortcode( '<p>x</p>' ), 'and leaves other content alone' );
	}

	#[Test]
	public function in_a_denied_request_the_shortcode_renders_the_message_for_that_denial(): void {
		$this->withPortal();
		Placeholder::init();
		$this->requestDeniedPage();

		$html = do_shortcode( '[agend_access_message]' );

		$this->assertStringContainsString( 'Sign in to read this', $html );
		$this->assertSame( array( 'Sign in', 'Join' ), $this->buttons( $html ) );
		$this->assertStringNotContainsString( 'THE PROTECTED BODY', $html );
		$this->assertStringNotContainsString( 'agend-access-placeholder--preview', $html );
		$this->assertStringNotContainsString( 'agend-access-placeholder__summary', $html, 'the shortcode is the message, not the page\'s summary' );
	}

	#[Test]
	public function outside_a_denied_request_the_shortcode_renders_a_neutral_preview(): void {
		$this->withPortal();
		Placeholder::init();

		$html = do_shortcode( '[agend_access_message]' );

		$this->assertStringContainsString( 'Members-only content placeholder', $html );
		$this->assertStringContainsString( 'agend-access-placeholder--preview', $html );
		$this->assertSame( array(), $this->buttons( $html ), 'it names nobody and offers nothing' );
		$this->assertStringNotContainsString( 'portal.example.test', $html );
	}

	/** An author editing the page the pattern is for is not a denied visitor. */
	#[Test]
	public function for_somebody_who_may_edit_the_page_the_shortcode_is_the_preview(): void {
		Placeholder::init();
		$this->requestDeniedPage();
		$GLOBALS['agend_test_current_user_can']['edit_post'] = true;
		$GLOBALS['agend_test_is_admin'] = true;
		( new Agend_Content_Access_Frontend() )->gate_request();

		$this->assertNull( Agend_Content_Access_Frontend::current_gate() );
		$this->assertStringContainsString( 'Members-only content placeholder', do_shortcode( '[agend_access_message]' ) );

		unset( $GLOBALS['agend_test_is_admin'] );
	}

	#[Test]
	public function a_request_that_is_not_denied_leaves_no_denial_behind(): void {
		Placeholder::init();
		$this->requestDeniedPage();
		$this->assertNotNull( Agend_Content_Access_Frontend::current_gate() );

		unset( $GLOBALS['agend_test_post_meta'][ 77 ] );
		( new Agend_Content_Access_Frontend() )->gate_request();

		$this->assertNull( Agend_Content_Access_Frontend::current_gate(), 'a later request must not show this one\'s denial' );
	}

	// -----------------------------------------------------------------------
	// The block pattern
	// -----------------------------------------------------------------------

	#[Test]
	public function the_block_pattern_is_registered_in_an_agend_category(): void {
		Placeholder::init();
		do_action( 'init' );

		$pattern = $GLOBALS['agend_test_block_patterns']['agend-content-access/members-only'] ?? null;

		$this->assertIsArray( $pattern );
		$this->assertSame( 'Agend: Members-only content', $pattern['title'] );
		$this->assertSame( array( 'agend' ), $pattern['categories'] );
		$this->assertSame( 'Agend', $GLOBALS['agend_test_block_pattern_categories']['agend']['label'] );
	}

	#[Test]
	public function the_pattern_is_a_core_group_holding_a_core_shortcode_block_that_renders_the_message(): void {
		$blocks = parse_blocks( Placeholder::pattern_content() );

		$this->assertCount( 1, $blocks );
		$this->assertSame( 'core/group', $blocks[0]['blockName'] );

		$inner = array_values( array_filter( $blocks[0]['innerBlocks'], static fn ( array $block ): bool => null !== $block['blockName'] ) );

		$this->assertCount( 1, $inner );
		$this->assertSame( 'core/shortcode', $inner[0]['blockName'] );
		$this->assertSame( '[agend_access_message]', trim( $inner[0]['innerHTML'] ) );
	}

	// -----------------------------------------------------------------------
	// What a rule set offers
	// -----------------------------------------------------------------------

	/** @return array<string, array{0: array, 1: bool, 2: bool}> */
	public static function whatRulesOffer(): array {
		return array(
			'any current member'          => array( array( self::MEMBER ), false, true ),
			'a plan'                      => array( array( self::PLAN ), false, true ),
			'a member or a role'          => array( array( self::MEMBER, 'agend_wordpress:role_editor' ), false, true ),
			'a reversed membership rule'  => array( array( self::MEMBER ), true, false ),
			'a segment'                   => array( array( self::SEGMENT ), false, false ),
			'an entitlement'              => array( array( 'agend_entitlements:journal' ), false, false ),
			'a role'                      => array( array( 'agend_wordpress:role_editor' ), false, false ),
		);
	}

	#[Test]
	#[DataProvider( 'whatRulesOffer' )]
	public function only_a_membership_or_plan_rule_that_is_not_reversed_offers_membership( array $rules, bool $invert, bool $offers ): void {
		$set = Rules::from_array( array( 'rules' => $rules, 'match' => 'any', 'invert' => $invert ) );

		$this->assertSame( $offers, Access::offers_membership( $set ) );
	}

	#[Test]
	public function unreadable_rules_offer_membership_as_the_denial_state_reads_them(): void {
		$this->assertTrue( Access::offers_membership( Rules::unreadable() ) );
	}
}
