<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Decision;
use Agend_Content_Access_Frontend;
use Agend_Content_Access_Policy;
use Agend_Test_WP;
use Agend_Test_WP_Die_Exception;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use WP_Error;

require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-policy.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-catalogue.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-meta-box.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-decision.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-frontend.php';

/**
 * A restricted post must be gated wherever its body is produced, not only on
 * its own URL.
 *
 * `gate_request()` handles the QUERIED post. Before these filters every other
 * place a restricted post rendered printed its full body to anyone: the blog
 * index, archives and search, widgets, feeds, auto-generated excerpts, and the
 * query loops of block themes. These tests drive the same chain WordPress runs
 * (see `Agend_Test_WP::apply_registered()`), because what matters is what
 * reaches output after every filter has had its turn, and one filter on its own
 * proves nothing about that.
 */
#[CoversClass( Agend_Content_Access_Frontend::class )]
#[CoversClass( Agend_Content_Access_Decision::class )]
final class LoopGatingTest extends TestCase {

	private const TIER_A = '11111111-1111-1111-1111-111111111111';
	private const TIER_B = '22222222-2222-2222-2222-222222222222';

	private const MEMBERS_ONLY = 51;
	private const PUBLIC_POST  = 52;
	private const TIER_A_ONLY  = 53;
	private const QUERIED      = 54;
	private const NO_POLICY    = 55;
	private const UNREADABLE   = 56;

	private const BODY = 'THE PROTECTED BODY';

	/** @var int Calls to the bearer lookup: the identity lookup the gate must avoid for public posts. */
	private int $identity_lookups = 0;

	/** @var int[] Post ids passed to `agend_content_access_uncacheable_render`. */
	private array $uncacheable = array();

	/** @var string What the stand-in for `wp_trim_excerpt()` derived from the body. */
	private string $derived = '';

	protected function setUp(): void {
		parent::setUp();

		$this->identity_lookups = 0;
		$this->uncacheable      = array();
		$this->derived          = '';

		$this->registerPost( self::MEMBERS_ONLY, array( 'mode' => 'active_member' ) );
		$this->registerPost( self::PUBLIC_POST, array( 'mode' => 'public' ), 'A PUBLIC BODY' );
		$this->registerPost( self::TIER_A_ONLY, array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A ) ) );
		$this->registerPost( self::QUERIED, array( 'mode' => 'active_member' ) );
		$this->registerPost( self::NO_POLICY, null, 'AN UNSET BODY' );
		$this->registerPost( self::UNREADABLE, array( 'mode' => 'segments', 'segment_ids' => array( self::TIER_A ) ) );

		Agend_Test_WP::$queried_object_id = self::QUERIED;

		$this->asVisitor();
		$this->anonymous();

		add_action(
			'agend_content_access_uncacheable_render',
			function ( int $post_id ): void {
				$this->uncacheable[] = $post_id;
			}
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['agend_test_is_singular'], $GLOBALS['agend_test_queried_object'] );

		parent::tearDown();
	}

	// -----------------------------------------------------------------------
	// Fixtures
	// -----------------------------------------------------------------------

	private function registerPost( int $id, ?array $policy, string $body = self::BODY, string $excerpt = '' ): void {
		$GLOBALS['agend_test_posts'][ $id ] = array(
			'ID'           => $id,
			'post_status'  => 'publish',
			'post_type'    => 'post',
			'post_title'   => 'Post ' . $id,
			'post_content' => $body,
			'post_excerpt' => $excerpt,
		);

		if ( null !== $policy ) {
			$GLOBALS['agend_test_post_meta'][ $id ] = array( Agend_Content_Access_Policy::META_KEY => $policy );
		}
	}

	private function withManualExcerpt( int $id, string $excerpt ): void {
		$GLOBALS['agend_test_posts'][ $id ]['post_excerpt'] = $excerpt;
	}

	/** The visitor cannot edit the post, which is what makes them a visitor. */
	private function asVisitor(): void {
		$GLOBALS['agend_test_current_user_can']['edit_post'] = false;
	}

	private function asEditor(): void {
		$GLOBALS['agend_test_current_user_can']['edit_post'] = true;
	}

	/** Counts every identity lookup and resolves to no bearer. */
	private function anonymous(): void {
		Agend_Test_WP::$filters['agend_apps_bearer_token'] = function (): string {
			++$this->identity_lookups;

			return '';
		};
	}

	private function asMember( string ...$tiers ): void {
		Agend_Test_WP::$filters['agend_apps_bearer_token'] = function (): string {
			++$this->identity_lookups;

			return 'member-token';
		};

		Agend_Test_WP::set_filter(
			'agend_apps_crm_get_my_entitlements_response',
			array( 'data' => array( 'tier_ids' => $tiers ) )
		);
	}

	private function frontend(): Agend_Content_Access_Frontend {
		return new Agend_Content_Access_Frontend();
	}

	/** Puts a post "in the loop" the way a theme's `the_post()` does. */
	private function inTheLoopOf( int $id ): void {
		$GLOBALS['post'] = get_post( $id );
	}

	/**
	 * Renders a post's body through everything registered on `the_content`.
	 *
	 * Core's own filters are stood in for, because the order they run in is
	 * exactly what the gate has to be correct against: `do_blocks` (9) records
	 * what it was handed, a builder at priority 9999999 ignores its input
	 * and renders from stored data, and `wpautop` (10) wraps what is left.
	 */
	private function render( int $id, ?string $raw = null ): string {
		$this->inTheLoopOf( $id );

		return (string) Agend_Test_WP::apply_registered(
			'the_content',
			$raw ?? (string) get_post( $id )->post_content
		);
	}

	/** Core's content filters and a builder, as the chain would carry them. */
	private function installCoreContentFilters( array &$seen_by_blocks ): void {
		add_filter(
			'the_content',
			static function ( $content ) use ( &$seen_by_blocks ) {
				$seen_by_blocks[] = $content;

				return $content;
			},
			9
		);
		add_filter( 'the_content', 'wpautop', 10 );
		add_filter(
			'the_content',
			static function () {
				return 'THE BUILDER RENDERED BODY';
			},
			9999999
		);
	}

	private function gateCount( string $html ): int {
		return substr_count( $html, 'class="agend-access-gate"' );
	}

	/** @return string[] Method names registered on a hook, with their priority, "method@priority". */
	private function registrations( string $hook ): array {
		return array_map(
			static fn( array $entry ): string => ( is_array( $entry['callback'] ) ? $entry['callback'][1] : '(closure)' ) . '@' . $entry['priority'],
			Agend_Test_WP::$filter_registry[ $hook ] ?? array()
		);
	}

	// -----------------------------------------------------------------------
	// Registration: the filters exist, at the priorities the design depends on
	// -----------------------------------------------------------------------

	/**
	 * Pinned because the failure is silent. With no registration the plugin looks
	 * fine on the restricted post's own URL and leaks it everywhere else.
	 */
	#[Test]
	public function the_content_is_gated_at_both_ends_of_the_chain(): void {
		$this->frontend();

		$registered = $this->registrations( 'the_content' );

		$this->assertContains( 'withhold_in_loop@1', $registered, 'before do_blocks (9), wpautop (10) and do_shortcode (11)' );
		$this->assertContains( 'enforce_withheld@' . PHP_INT_MAX, $registered, 'after a page builder, which re-derives the body very late' );
	}

	#[Test]
	public function excerpts_and_feeds_are_gated_after_the_filters_that_build_them(): void {
		$this->frontend();

		$this->assertContains( 'filter_excerpt@' . PHP_INT_MAX, $this->registrations( 'get_the_excerpt' ), 'after wp_trim_excerpt at 10' );
		$this->assertContains( 'filter_feed_content@' . PHP_INT_MAX, $this->registrations( 'the_content_feed' ) );
		$this->assertContains( 'filter_feed_excerpt@' . PHP_INT_MAX, $this->registrations( 'the_excerpt_rss' ) );
	}

	#[Test]
	public function the_get_the_excerpt_filter_receives_the_post(): void {
		$this->frontend();

		$entry = Agend_Test_WP::$filter_registry['get_the_excerpt'][0];

		$this->assertSame( 2, $entry['args'] );
	}

	// -----------------------------------------------------------------------
	// A listing: the body
	// -----------------------------------------------------------------------

	#[Test]
	public function an_anonymous_visitor_gets_the_gate_for_a_restricted_post_in_a_loop(): void {
		$this->frontend();

		$html = $this->render( self::MEMBERS_ONLY );

		$this->assertSame( 1, $this->gateCount( $html ) );
		$this->assertStringContainsString( 'Please sign in to read it.', $html );
		$this->assertStringNotContainsString( self::BODY, $html );
	}

	/**
	 * The ordering case. The body must not reach `do_blocks` or `do_shortcode`,
	 * where it would run side effects before being thrown away, and a builder
	 * that re-derives the post from its own data at the end must not win.
	 */
	#[Test]
	public function the_body_never_reaches_later_filters_and_a_builder_cannot_restore_it(): void {
		$seen = array();
		$this->frontend();
		$this->installCoreContentFilters( $seen );

		$html = $this->render( self::MEMBERS_ONLY );

		$this->assertStringContainsString( 'agend-access-gate', $html );
		$this->assertStringNotContainsString( 'THE BUILDER RENDERED BODY', $html, 'the closing pass must outlast a late re-derivation' );
		$this->assertStringNotContainsString( self::BODY, $html );

		$this->assertCount( 1, $seen );
		$this->assertStringNotContainsString( self::BODY, $seen[0], 'the body must be replaced before core renders blocks or shortcodes' );
		$this->assertStringContainsString( 'agend-access-gate', $seen[0] );
	}

	#[Test]
	public function a_public_post_in_a_loop_keeps_its_content_and_costs_no_identity_lookup(): void {
		$seen = array();
		$this->frontend();
		$this->installCoreContentFilters( $seen );

		// The builder double ignores its input, so only the part of the chain the
		// gate controls is compared.
		$html = $this->render( self::PUBLIC_POST, 'A PUBLIC BODY' );

		$this->assertSame( array( 'A PUBLIC BODY' ), $seen, 'handed to later filters untouched' );
		$this->assertStringNotContainsString( 'agend-access-gate', $html );
		$this->assertSame( 0, $this->identity_lookups, 'a public post must not trigger an identity lookup' );
		$this->assertSame( array(), Agend_Test_WP::$requests );
		$this->assertSame( array(), $this->uncacheable );
	}

	#[Test]
	public function a_post_with_no_policy_in_a_loop_keeps_its_content_and_costs_no_identity_lookup(): void {
		$this->frontend();

		$html = $this->render( self::NO_POLICY );

		$this->assertSame( 'AN UNSET BODY', $html );
		$this->assertSame( 0, $this->identity_lookups );
		$this->assertSame( array(), $this->uncacheable );
	}

	#[Test]
	public function a_member_on_the_right_plan_reads_the_body_and_the_page_is_marked_uncacheable(): void {
		$this->asMember( self::TIER_A );
		$this->frontend();

		$html = $this->render( self::TIER_A_ONLY );

		$this->assertSame( self::BODY, $html );
		$this->assertSame( array( self::TIER_A_ONLY ), $this->uncacheable );
		$this->assertSame( 1, $this->identity_lookups );
	}

	#[Test]
	public function every_member_post_in_a_listing_marks_the_page_and_one_lookup_serves_the_lot(): void {
		$this->asMember( self::TIER_A );
		$this->frontend();

		$this->render( self::MEMBERS_ONLY );
		$this->render( self::TIER_A_ONLY );
		$this->render( self::PUBLIC_POST, 'A PUBLIC BODY' );

		$this->assertSame( array( self::MEMBERS_ONLY, self::TIER_A_ONLY ), $this->uncacheable );
		$this->assertSame( 1, $this->identity_lookups, 'the viewer is resolved once per request' );
	}

	#[Test]
	public function a_member_on_the_wrong_plan_is_gated_with_the_plans_named(): void {
		Agend_Test_WP::$tiers_response = array(
			'data' => array(
				array( 'id' => self::TIER_A, 'name' => 'Full Member', 'slug' => 'full', 'tier_type' => 'individual', 'is_active' => true ),
			),
		);
		$this->asMember( self::TIER_B );
		$this->frontend();

		$html = $this->render( self::TIER_A_ONLY );

		$this->assertStringContainsString( 'Full Member', $html );
		$this->assertStringNotContainsString( self::BODY, $html );
		$this->assertSame( array(), $this->uncacheable, 'a denied render is not a member render of protected content' );
	}

	#[Test]
	public function an_editor_who_can_edit_the_post_sees_it_in_a_loop(): void {
		$this->asEditor();
		$this->frontend();

		$html = $this->render( self::MEMBERS_ONLY );

		$this->assertSame( self::BODY, $html );
		$this->assertSame( 0, $this->identity_lookups );
		$this->assertSame( array(), $this->uncacheable, 'an editor preview is a logged-in request, not a member render' );
	}

	#[Test]
	public function an_unreadable_stored_policy_is_gated_in_a_loop_for_everyone(): void {
		$this->frontend();

		$this->assertStringNotContainsString( self::BODY, $this->render( self::UNREADABLE ), 'anonymous' );
		$this->assertStringContainsString( 'agend-access-gate', $this->render( self::UNREADABLE ) );

		Agend_Content_Access_Decision::reset_cache();
		$this->asMember( self::TIER_A );

		$this->assertStringNotContainsString( self::BODY, $this->render( self::UNREADABLE ), 'a member holding a plan' );
		$this->assertStringContainsString( 'agend-access-gate', $this->render( self::UNREADABLE ) );
	}

	#[Test]
	public function a_gateway_outage_denies_in_a_loop_and_is_reported_once_per_post(): void {
		$this->asMember();
		Agend_Test_WP::set_filter( 'agend_apps_crm_get_my_entitlements_response', new WP_Error( 'http_request_failed', 'down' ) );

		$reports = array();
		add_action(
			'agend_content_access_degraded',
			static function ( int $post_id, string $state ) use ( &$reports ): void {
				$reports[] = array( $post_id, $state );
			},
			10,
			2
		);

		$frontend = $this->frontend();
		$this->inTheLoopOf( self::MEMBERS_ONLY );

		$html = $frontend->withhold_in_loop( self::BODY );
		$frontend->filter_excerpt( '', get_post( self::MEMBERS_ONLY ) );

		$this->assertStringContainsString( 'agend-access-gate', $html );
		$this->assertSame( array( array( self::MEMBERS_ONLY, 'authentication_required' ) ), $reports );
	}

	// -----------------------------------------------------------------------
	// Where the gate does and does not apply
	// -----------------------------------------------------------------------

	#[Test]
	public function a_wp_admin_screen_is_left_alone_for_a_user_who_may_edit_the_post(): void {
		$GLOBALS['agend_test_is_admin'] = true;
		$this->asEditor();
		$this->frontend();

		$this->assertSame( self::BODY, $this->render( self::MEMBERS_ONLY ) );
		$this->assertSame( 0, $this->identity_lookups );
	}

	/**
	 * `is_admin()` is true for admin-ajax.php, and "load more" and filter widgets
	 * print posts to anonymous visitors through it.
	 */
	#[Test]
	public function an_admin_ajax_request_is_still_gated(): void {
		$GLOBALS['agend_test_is_admin']   = true;
		$GLOBALS['agend_test_doing_ajax'] = true;
		$this->frontend();

		$html = $this->render( self::MEMBERS_ONLY );

		$this->assertStringContainsString( 'agend-access-gate', $html );
		$this->assertStringNotContainsString( self::BODY, $html );
	}

	#[Test]
	public function an_elementor_authoring_request_is_left_alone(): void {
		do_action( 'elementor/loaded' );
		\Elementor\Plugin::$instance->editor->is_edit_mode = true;
		$this->frontend();

		$this->assertSame( self::BODY, $this->render( self::MEMBERS_ONLY ) );
	}

	#[Test]
	public function content_with_no_current_post_is_left_alone(): void {
		$this->frontend();

		$this->assertSame(
			'some widget text',
			Agend_Test_WP::apply_registered( 'the_content', 'some widget text' )
		);
	}

	// -----------------------------------------------------------------------
	// Builder data
	// -----------------------------------------------------------------------

	#[Test]
	public function the_builder_document_of_a_withheld_post_is_blanked_and_nothing_else_is(): void {
		$frontend = $this->frontend();

		$this->assertSame( 'kept', $frontend->suppress_builder_data( 'kept', self::MEMBERS_ONLY, '_elementor_data', true ), 'nothing withheld yet' );

		$this->render( self::MEMBERS_ONLY );

		$this->assertArrayHasKey( 'get_post_metadata', Agend_Test_WP::$filters, 'registered once something is withheld' );
		$this->assertSame( array(), $frontend->suppress_builder_data( 'kept', self::MEMBERS_ONLY, '_elementor_data', true ) );
		$this->assertSame( array( array() ), $frontend->suppress_builder_data( 'kept', self::MEMBERS_ONLY, '_elementor_data', false ) );
		$this->assertSame( 'kept', $frontend->suppress_builder_data( 'kept', self::MEMBERS_ONLY, '_thumbnail_id', true ), 'other keys' );
		$this->assertSame( 'kept', $frontend->suppress_builder_data( 'kept', self::PUBLIC_POST, '_elementor_data', true ), 'other posts' );
	}

	#[Test]
	public function a_request_that_withholds_nothing_does_not_hook_post_meta(): void {
		$this->render( self::PUBLIC_POST, 'A PUBLIC BODY' );
		$this->frontend();
		$this->render( self::PUBLIC_POST, 'A PUBLIC BODY' );

		$this->assertArrayNotHasKey( 'get_post_metadata', Agend_Test_WP::$filters );
	}

	// -----------------------------------------------------------------------
	// The queried post: unchanged, and not gated twice
	// -----------------------------------------------------------------------

	private function requestTheQueriedPage( Agend_Content_Access_Frontend $frontend ): void {
		$GLOBALS['agend_test_is_singular']    = true;
		$GLOBALS['agend_test_queried_object'] = get_post( self::QUERIED );

		$frontend->gate_request();
	}

	#[Test]
	public function the_queried_post_keeps_its_teaser_path_and_is_not_gated_twice(): void {
		$this->withManualExcerpt( self::QUERIED, 'A public summary.' );
		$frontend = $this->frontend();
		$this->requestTheQueriedPage( $frontend );

		$GLOBALS['agend_test_in_the_loop'] = true;
		$html                              = $this->render( self::QUERIED );

		$this->assertSame( 1, $this->gateCount( $html ), 'one gate, not a gate inside a gate' );
		$this->assertSame( 1, substr_count( $html, 'A public summary.' ) );
		$this->assertStringNotContainsString( self::BODY, $html );

		$this->assertContains( 'filter_the_content@1', $this->registrations( 'the_content' ), 'the singular filter is still what handles it' );
		$this->assertSame( array(), ( new \ReflectionProperty( $frontend, 'withheld' ) )->getValue( $frontend ), 'the loop pass left it to the singular path' );
	}

	#[Test]
	public function the_queried_post_still_answers_403_without_an_excerpt(): void {
		$frontend = $this->frontend();

		$this->expectException( Agend_Test_WP_Die_Exception::class );

		$this->requestTheQueriedPage( $frontend );
	}

	/**
	 * The singular filter only acts for the main query's own loop. The same post
	 * printed by a sidebar widget, or a related-posts list on its own page, is a
	 * different render and has to be gated by the loop filter.
	 */
	#[Test]
	public function the_queried_post_rendered_outside_its_own_loop_is_gated_by_the_loop_filter(): void {
		$this->withManualExcerpt( self::QUERIED, 'A public summary.' );
		$frontend = $this->frontend();
		$this->requestTheQueriedPage( $frontend );

		$GLOBALS['agend_test_in_the_loop'] = false;
		$html                              = $this->render( self::QUERIED );

		$this->assertSame( 1, $this->gateCount( $html ) );
		$this->assertStringNotContainsString( self::BODY, $html );
	}

	#[Test]
	public function the_queried_post_granted_to_a_member_marks_the_page_uncacheable(): void {
		$this->asMember( self::TIER_A );
		$frontend = $this->frontend();
		$this->requestTheQueriedPage( $frontend );

		$GLOBALS['agend_test_in_the_loop'] = true;
		$html                              = $this->render( self::QUERIED );

		$this->assertSame( self::BODY, $html );
		$this->assertSame( array( self::QUERIED ), $this->uncacheable );
	}

	// -----------------------------------------------------------------------
	// Excerpts
	// -----------------------------------------------------------------------

	private function excerptOf( int $id ): string {
		$post = get_post( $id );

		return (string) Agend_Test_WP::apply_registered( 'get_the_excerpt', (string) $post->post_excerpt, $post );
	}

	#[Test]
	public function an_auto_excerpt_of_a_restricted_post_carries_none_of_the_body(): void {
		$this->frontend();
		$this->installTrimExcerptFor( self::MEMBERS_ONLY );

		$this->assertSame( '', $this->excerptOf( self::MEMBERS_ONLY ) );
	}

	/**
	 * The inner `the_content` pass in `wp_trim_excerpt()` runs for the post
	 * WordPress is rendering, not necessarily the one the excerpt is for, so the
	 * excerpt filter has to stand on its own.
	 */
	#[Test]
	public function an_auto_excerpt_is_empty_even_when_the_current_post_is_a_different_one(): void {
		$this->frontend();
		$this->installTrimExcerptFor( self::MEMBERS_ONLY );
		$this->inTheLoopOf( self::PUBLIC_POST );

		$this->assertSame( '', $this->excerptOf( self::MEMBERS_ONLY ) );
		$this->assertSame( self::BODY, $this->derived, 'the body did get through the inner pass, so the excerpt filter is what withheld it' );
	}

	#[Test]
	public function a_manual_excerpt_is_the_teaser_for_a_restricted_post(): void {
		$this->withManualExcerpt( self::MEMBERS_ONLY, 'A public summary.' );
		$this->frontend();
		$this->installTrimExcerptFor( self::MEMBERS_ONLY );

		$this->assertSame( 'A public summary.', $this->excerptOf( self::MEMBERS_ONLY ) );
	}

	#[Test]
	public function the_teaser_filter_applies_to_excerpts_as_it_does_to_the_gate(): void {
		Agend_Test_WP::set_filter( 'agend_content_access_teaser', 'A site specific teaser.' );
		$this->frontend();

		$this->assertSame( 'A site specific teaser.', $this->excerptOf( self::MEMBERS_ONLY ) );
	}

	#[Test]
	public function an_excerpt_for_a_public_post_is_untouched_and_costs_no_lookup(): void {
		$this->frontend();
		$this->installTrimExcerptFor( self::PUBLIC_POST );

		$this->assertSame( 'A PUBLIC BODY', $this->excerptOf( self::PUBLIC_POST ) );
		$this->assertSame( 0, $this->identity_lookups );
		$this->assertSame( array(), $this->uncacheable );
	}

	#[Test]
	public function an_excerpt_for_a_post_with_no_policy_is_untouched(): void {
		$this->frontend();

		$this->assertSame( 'A written excerpt', (string) Agend_Test_WP::apply_registered( 'get_the_excerpt', 'A written excerpt', get_post( self::NO_POLICY ) ) );
		$this->assertSame( 0, $this->identity_lookups );
	}

	#[Test]
	public function a_member_gets_the_full_excerpt_and_the_page_is_marked_uncacheable(): void {
		$this->asMember( self::TIER_A );
		$this->frontend();
		$this->installTrimExcerptFor( self::TIER_A_ONLY );

		$this->assertSame( self::BODY, $this->excerptOf( self::TIER_A_ONLY ) );
		$this->assertSame( array( self::TIER_A_ONLY ), array_values( array_unique( $this->uncacheable ) ), 'the inner content pass and the excerpt each mark it' );
	}

	#[Test]
	public function an_editor_sees_the_auto_excerpt(): void {
		$this->asEditor();
		$this->frontend();
		$this->installTrimExcerptFor( self::MEMBERS_ONLY );

		$this->assertSame( self::BODY, $this->excerptOf( self::MEMBERS_ONLY ) );
		$this->assertSame( array(), $this->uncacheable );
	}

	#[Test]
	public function an_unreadable_policy_empties_the_auto_excerpt_for_a_member_too(): void {
		$this->asMember( self::TIER_A );
		$this->frontend();
		$this->installTrimExcerptFor( self::UNREADABLE );

		$this->assertSame( '', $this->excerptOf( self::UNREADABLE ) );
	}

	#[Test]
	public function an_excerpt_asked_for_without_a_post_object_is_left_alone(): void {
		$frontend = $this->frontend();

		$this->assertSame( 'text', $frontend->filter_excerpt( 'text' ) );
		$this->assertSame( 'text', $frontend->filter_excerpt( 'text', 12 ) );
	}

	/**
	 * Stands in for `wp_trim_excerpt()` at priority 10 for one post: when no
	 * excerpt was written, derive one from the body by running it through
	 * `the_content` for whichever post WordPress currently has set up, which is
	 * what core does. What it derived is kept, so a test can show the body really
	 * was on its way out and that it is the excerpt filter that withheld it.
	 */
	private function installTrimExcerptFor( int $id ): void {
		add_filter(
			'get_the_excerpt',
			function ( $excerpt ) use ( $id ) {
				if ( '' !== trim( (string) $excerpt ) ) {
					return $excerpt;
				}

				if ( ! isset( $GLOBALS['post'] ) ) {
					$GLOBALS['post'] = get_post( $id );
				}

				$GLOBALS['agend_test_in_the_loop'] = true;

				$this->derived = trim( (string) Agend_Test_WP::apply_registered( 'the_content', (string) get_post( $id )->post_content ) );

				return $this->derived;
			},
			10,
			2
		);
	}

	// -----------------------------------------------------------------------
	// Feeds
	// -----------------------------------------------------------------------

	private function feedItem( int $id ): void {
		$GLOBALS['agend_test_is_feed'] = true;
		$this->inTheLoopOf( $id );
	}

	/** `get_the_content_feed()`: `the_content`, then `the_content_feed`. */
	private function feedContentOf( int $id ): string {
		$this->feedItem( $id );

		$content = Agend_Test_WP::apply_registered( 'the_content', (string) get_post( $id )->post_content );

		return (string) Agend_Test_WP::apply_registered( 'the_content_feed', $content, 'rss2' );
	}

	#[Test]
	public function a_feed_item_for_a_restricted_post_shows_only_its_manual_excerpt(): void {
		$this->withManualExcerpt( self::MEMBERS_ONLY, 'A public summary.' );
		$this->frontend();

		$content = $this->feedContentOf( self::MEMBERS_ONLY );

		$this->assertSame( '<p>A public summary.</p>', $content );
		$this->assertStringNotContainsString( 'agend-access-gate', $content, 'a sign-in prompt would point back at the feed' );
		$this->assertStringNotContainsString( self::BODY, $content );
	}

	#[Test]
	public function a_feed_item_for_a_restricted_post_with_no_excerpt_is_empty(): void {
		$this->frontend();

		$this->assertSame( '', $this->feedContentOf( self::MEMBERS_ONLY ) );
	}

	#[Test]
	public function the_feed_summary_of_a_restricted_post_is_its_manual_excerpt_or_nothing(): void {
		$this->frontend();
		$this->feedItem( self::MEMBERS_ONLY );

		$this->assertSame( '', Agend_Test_WP::apply_registered( 'the_excerpt_rss', self::BODY ) );

		$this->withManualExcerpt( self::MEMBERS_ONLY, 'A public summary.' );
		$this->feedItem( self::MEMBERS_ONLY );

		$this->assertSame( 'A public summary.', Agend_Test_WP::apply_registered( 'the_excerpt_rss', self::BODY ) );
	}

	#[Test]
	public function a_feed_item_for_a_public_post_is_untouched_and_costs_no_lookup(): void {
		$this->frontend();

		$this->assertSame( 'A PUBLIC BODY', $this->feedContentOf( self::PUBLIC_POST ) );

		$this->feedItem( self::PUBLIC_POST );
		$this->assertSame( 'A summary', Agend_Test_WP::apply_registered( 'the_excerpt_rss', 'A summary' ) );
		$this->assertSame( 0, $this->identity_lookups );
	}

	/**
	 * A feed response is fetched by aggregators with no session, and is routinely
	 * cached and shared. A render personalised to whoever fetched it first would
	 * be served to everyone after, so a feed never evaluates who is asking.
	 */
	#[Test]
	public function a_feed_never_evaluates_the_requester_even_for_a_member_or_an_editor(): void {
		$this->asMember( self::TIER_A );
		$this->frontend();

		$this->assertSame( '', $this->feedContentOf( self::TIER_A_ONLY ), 'member' );

		$this->asEditor();
		Agend_Content_Access_Decision::reset_cache();

		$this->assertSame( '', $this->feedContentOf( self::TIER_A_ONLY ), 'editor' );
		$this->assertSame( 0, $this->identity_lookups );
		$this->assertSame( array(), $this->uncacheable );
	}

	#[Test]
	public function an_unreadable_policy_is_withheld_from_a_feed(): void {
		$this->frontend();

		$this->assertSame( '', $this->feedContentOf( self::UNREADABLE ) );
	}

	#[Test]
	public function feed_filters_ignore_a_request_with_no_current_post(): void {
		$frontend = $this->frontend();

		$this->assertSame( 'x', $frontend->filter_feed_content( 'x' ) );
		$this->assertSame( 'x', $frontend->filter_feed_excerpt( 'x' ) );
	}

	/** The feed's `the_content` stage is gated too, so the body is never rendered on the way. */
	#[Test]
	public function a_feed_does_not_render_the_body_on_the_way_to_withholding_it(): void {
		$seen = array();
		$this->frontend();
		$this->installCoreContentFilters( $seen );

		$this->feedContentOf( self::MEMBERS_ONLY );

		$this->assertCount( 1, $seen );
		$this->assertStringNotContainsString( self::BODY, $seen[0] );
	}

	// -----------------------------------------------------------------------
	// Decision: the anonymous reading
	// -----------------------------------------------------------------------

	#[Test]
	public function the_anonymous_decision_denies_a_restricted_post_without_an_identity_lookup(): void {
		$this->asMember( self::TIER_A );
		$this->asEditor();

		$restricted = Agend_Content_Access_Decision::for_post( self::TIER_A_ONLY, true );
		$public     = Agend_Content_Access_Decision::for_post( self::PUBLIC_POST, true );

		$this->assertSame( Agend_Content_Access_Decision::STATE_AUTH, $restricted['state'] );
		$this->assertFalse( $restricted['preview'] );
		$this->assertSame( Agend_Content_Access_Decision::STATE_GRANTED, $public['state'] );
		$this->assertSame( 0, $this->identity_lookups );
	}

	#[Test]
	public function the_default_decision_is_unchanged(): void {
		$this->asMember( self::TIER_A );

		$this->assertSame( Agend_Content_Access_Decision::STATE_GRANTED, Agend_Content_Access_Decision::for_post( self::TIER_A_ONLY )['state'] );
	}

	// -----------------------------------------------------------------------
	// Page caches
	// -----------------------------------------------------------------------

	/**
	 * `define()` cannot be undone, so the constant is asserted in a process of
	 * its own. Every other test here asserts the same fact through the action the
	 * marker fires, which can be reset.
	 */
	#[Test]
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function a_member_render_defines_donotcachepage(): void {
		$this->assertFalse( defined( 'DONOTCACHEPAGE' ), 'a clean process starts without it' );

		$this->asMember( self::TIER_A );
		$this->frontend();
		$this->render( self::TIER_A_ONLY );

		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) );
		$this->assertTrue( DONOTCACHEPAGE );
	}

	#[Test]
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function an_anonymous_render_never_defines_donotcachepage(): void {
		$this->frontend();
		$this->render( self::MEMBERS_ONLY );
		$this->render( self::PUBLIC_POST, 'A PUBLIC BODY' );

		$this->assertFalse( defined( 'DONOTCACHEPAGE' ) );
	}

	#[Test]
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function a_constant_already_defined_by_another_plugin_is_left_alone(): void {
		define( 'DONOTCACHEPAGE', 'set elsewhere' );

		$this->asMember( self::TIER_A );
		$this->frontend();
		$this->render( self::TIER_A_ONLY );

		$this->assertSame( 'set elsewhere', DONOTCACHEPAGE );
	}
}
