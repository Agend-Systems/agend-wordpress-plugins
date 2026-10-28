<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Catalogue;
use Agend_Content_Access_Decision;
use Agend_Content_Access_Frontend;
use Agend_Content_Access_Policy;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use WP_Error;

require_once __DIR__ . '/has-elementor-double.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-policy.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-catalogue.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-meta-box.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-viewer-facts.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-runtime.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-decision.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-frontend.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-placeholder.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-destination.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-fragment-rules.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-block-attributes.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-conditions.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-providers.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-rules.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-access.php';

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
	private const SEG_A  = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1';
	private const SEG_B  = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbb2';
	private const ENT_A  = 'directory.access';
	private const ENT_B  = 'journal';

	private const MEMBERS_ONLY     = 51;
	private const PUBLIC_POST      = 52;
	private const TIER_A_ONLY      = 53;
	private const QUERIED          = 54;
	private const NO_POLICY        = 55;
	private const UNREADABLE       = 56;
	private const SEGMENT_ONLY     = 57;
	private const SEGMENT_OTHER    = 58;
	private const ENTITLEMENT_ONLY = 59;

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
		// A mode this plugin cannot read, which is enforced as nobody. Not a
		// `segments` policy: this version enforces those.
		$this->registerPost( self::UNREADABLE, array( 'mode' => 'authenticated' ) );
		$this->registerPost( self::SEGMENT_ONLY, array( 'mode' => 'segments', 'segment_ids' => array( self::SEG_A ) ) );
		$this->registerPost( self::SEGMENT_OTHER, array( 'mode' => 'segments', 'segment_ids' => array( self::SEG_A ) ) );
		$this->registerPost( self::ENTITLEMENT_ONLY, array( 'mode' => 'selected_entitlements', 'entitlement_keys' => array( self::ENT_A ) ) );

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
		$GLOBALS['agend_test_current_user_id'] = 7;

		Agend_Test_WP::$filters['agend_apps_bearer_token'] = function (): string {
			++$this->identity_lookups;

			return 'member-token';
		};

		Agend_Test_WP::set_filter(
			'agend_apps_crm_get_my_entitlements_response',
			array( 'data' => array( 'is_member' => true, 'tier_ids' => $tiers ) )
		);
	}

	/**
	 * A signed-in member who holds the given entitlement keys and belongs to the
	 * given segments, on top of whatever plans `asMember()` gave them.
	 *
	 * @param string[] $segment_ids      Segment ids the gateway reports.
	 * @param string[] $entitlement_keys Entitlement gate keys the gateway reports.
	 */
	private function asMemberOf( array $segment_ids, array $entitlement_keys = array() ): void {
		$this->asMember( self::TIER_A );

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

	/** Gateway calls recorded for one endpoint, so a loop's cost is assertable. */
	private function gatewayCalls( string $endpoint ): int {
		return count(
			array_filter(
				Agend_Test_WP::$requests,
				static fn( array $request ): bool => $endpoint === $request['url']
			)
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
	public function an_editor_who_can_edit_the_post_is_gated_in_a_loop_like_any_reader(): void {
		$this->asEditor();
		$this->frontend();

		$html = $this->render( self::MEMBERS_ONLY );

		$this->assertStringNotContainsString( self::BODY, $html );
		$this->assertStringContainsString( 'agend-access-gate', $html );
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
	// A listing: segments and selected entitlements
	// -----------------------------------------------------------------------

	#[Test]
	public function an_anonymous_visitor_gets_the_gate_for_a_segment_post_in_a_loop_with_no_gateway_call(): void {
		$this->frontend();

		$html = $this->render( self::SEGMENT_ONLY );

		$this->assertSame( 1, $this->gateCount( $html ) );
		$this->assertStringContainsString( 'Please sign in to read it.', $html, 'an unidentified visitor is asked to sign in' );
		$this->assertStringNotContainsString( self::BODY, $html );
		$this->assertSame( array(), Agend_Test_WP::$requests, 'nothing to look up for a visitor with no session' );
	}

	#[Test]
	public function a_member_of_the_segment_reads_it_in_a_loop_and_the_whole_loop_costs_one_segments_call(): void {
		$this->asMemberOf( array( self::SEG_A ) );
		$this->frontend();

		$first  = $this->render( self::SEGMENT_ONLY );
		$second = $this->render( self::SEGMENT_OTHER );
		$third  = $this->render( self::SEGMENT_ONLY );

		$this->assertSame( self::BODY, $first );
		$this->assertSame( self::BODY, $second );
		$this->assertSame( self::BODY, $third );
		$this->assertSame( array( self::SEGMENT_ONLY, self::SEGMENT_OTHER, self::SEGMENT_ONLY ), $this->uncacheable );
		$this->assertSame( 1, $this->gatewayCalls( '/crm/me/segments' ), 'segments are fetched once per request, however many posts ask' );
		// Changed from exactly one: a segment rule needs the visitor's segments and nothing else,
		// so it no longer also asks for the member standing it never reads. At most once.
		$this->assertLessThanOrEqual( 1, $this->gatewayCalls( '/crm/me/entitlements' ), 'and the member standing is never asked twice' );
		$this->assertSame( 1, $this->identity_lookups, 'and the bearer' );
	}

	#[Test]
	public function an_identified_visitor_outside_the_segment_is_gated_as_restricted_in_a_loop(): void {
		update_option( 'agend_apps_portal_url', 'https://portal.example.test/' );
		$this->asMemberOf( array( self::SEG_B ) );
		$this->frontend();

		$html = $this->render( self::SEGMENT_ONLY );

		$this->assertStringContainsString( 'This content is limited to selected members.', $html );
		$this->assertStringNotContainsString( 'agend-access-gate-cta', $html, 'there is nothing to join or upgrade into' );
		$this->assertStringNotContainsString( self::SEG_A, $html, 'a segment is never named' );
		$this->assertStringNotContainsString( self::BODY, $html );
		$this->assertSame( array(), $this->uncacheable );
	}

	#[Test]
	public function a_segment_outage_denies_in_a_loop_and_reports_the_post_once(): void {
		$this->asMemberOf( array( self::SEG_A ) );
		Agend_Test_WP::set_filter( 'agend_apps_crm_get_my_segments_response', new WP_Error( 'down', 'Gateway unreachable' ) );

		$reports = array();
		add_action(
			'agend_content_access_degraded',
			static function ( int $post_id ) use ( &$reports ): void {
				$reports[] = $post_id;
			}
		);

		$frontend = $this->frontend();
		$this->inTheLoopOf( self::SEGMENT_ONLY );

		$html = $frontend->withhold_in_loop( self::BODY );
		$frontend->filter_excerpt( '', get_post( self::SEGMENT_ONLY ) );

		$this->assertStringContainsString( 'agend-access-gate', $html );
		$this->assertStringNotContainsString( self::BODY, $html );
		$this->assertSame( array( self::SEGMENT_ONLY ), $reports, 'a body and an excerpt for one post are one outage' );
		$this->assertSame( 1, $this->gatewayCalls( '/crm/me/segments' ), 'a failed lookup is not retried for the next post either' );
	}

	#[Test]
	public function a_selected_entitlement_post_in_a_loop_is_gated_for_a_member_who_does_not_hold_it(): void {
		$this->asMemberOf( array(), array( self::ENT_B ) );
		$this->frontend();

		$html = $this->render( self::ENTITLEMENT_ONLY );

		$this->assertSame( 1, $this->gateCount( $html ) );
		$this->assertStringContainsString( 'This content is limited to selected members.', $html );
		$this->assertStringNotContainsString( self::BODY, $html );
		$this->assertSame( array(), $this->uncacheable );
		$this->assertSame( 0, $this->gatewayCalls( '/crm/me/segments' ), 'an entitlements policy never needs segments' );
	}

	/** The loop pass hands `render_gate()` the enforced policy, so it names entitlements as the post's own page does. */
	#[Test]
	public function the_loop_gate_names_the_entitlements_as_the_singular_gate_does(): void {
		Agend_Test_WP::$transients[ Agend_Content_Access_Catalogue::ENTITLEMENTS_TRANSIENT ] = array(
			array( 'gate_key' => self::ENT_A, 'name' => 'Directory access', 'description' => '', 'kind' => 'platform-gate' ),
		);
		$this->asMemberOf( array(), array( self::ENT_B ) );
		$this->frontend();

		$html = $this->render( self::ENTITLEMENT_ONLY );

		$this->assertStringContainsString( 'limited to people who hold: Directory access.', $html );
		$this->assertStringNotContainsString( self::ENT_A, $html, 'the gate key is a configuration detail' );
		$this->assertSame(
			Agend_Content_Access_Frontend::render_gate(
				'',
				Agend_Content_Access_Decision::STATE_RESTRICTED,
				Agend_Content_Access_Policy::enforced_for_post( self::ENTITLEMENT_ONLY )
			),
			$html,
			'the same markup the singular path would print'
		);
	}

	#[Test]
	public function a_selected_entitlement_post_in_a_loop_is_gated_for_an_anonymous_visitor(): void {
		$this->frontend();

		$html = $this->render( self::ENTITLEMENT_ONLY );

		$this->assertStringContainsString( 'Please sign in to read it.', $html );
		$this->assertStringNotContainsString( self::BODY, $html );
		$this->assertSame( array(), Agend_Test_WP::$requests );
	}

	#[Test]
	public function a_selected_entitlement_post_in_a_loop_is_read_by_a_holder_with_no_segments_call(): void {
		$this->asMemberOf( array(), array( self::ENT_A ) );
		$this->frontend();

		$this->assertSame( self::BODY, $this->render( self::ENTITLEMENT_ONLY ) );
		$this->assertSame( array( self::ENTITLEMENT_ONLY ), $this->uncacheable );
		$this->assertSame( 0, $this->gatewayCalls( '/crm/me/segments' ) );
	}

	/**
	 * Each gate names the entitlements it admits, which reads the entitlement
	 * catalogue. A failure is never stored, so without the catalogue's request memo
	 * a listing of gated posts would ask Agend, and wait out its timeout, once per
	 * post. Separate process because the Core wrapper is a function that cannot be
	 * undefined once declared.
	 */
	#[Test]
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function a_listing_of_gated_posts_asks_for_a_failing_entitlement_catalogue_once(): void {
		require_once __DIR__ . '/fixtures/core-entitlement-types-double.php';
		Agend_Test_WP::set_filter( 'agend_apps_crm_get_entitlement_types_response', new WP_Error( 'http_request_failed', 'down' ) );

		$this->asMemberOf( array(), array( self::ENT_B ) );
		$this->frontend();

		foreach ( array_fill( 0, 6, self::ENTITLEMENT_ONLY ) as $id ) {
			$html = $this->render( $id );

			$this->assertStringContainsString( 'This content is limited to selected members.', $html, 'the generic copy when no name can be had' );
			$this->assertStringNotContainsString( self::BODY, $html );
		}

		$this->assertSame( 1, $this->gatewayCalls( '/crm/entitlements/types' ), 'one catalogue call for the whole listing' );
		$this->assertArrayNotHasKey( Agend_Content_Access_Catalogue::ENTITLEMENTS_TRANSIENT, Agend_Test_WP::$transients, 'the failure is not stored' );
	}

	/**
	 * The bound the loop pass must keep: one call each for standing and segments,
	 * whatever mix of policies the page lists, because the loop reuses the
	 * facts source's per-request memo and adds no lookup of its own.
	 */
	#[Test]
	public function a_mixed_listing_costs_at_most_one_entitlements_call_and_one_segments_call(): void {
		$this->asMemberOf( array( self::SEG_A ), array( self::ENT_A ) );
		$this->frontend();

		$bodies = array(
			$this->render( self::MEMBERS_ONLY ),
			$this->render( self::SEGMENT_ONLY ),
			$this->render( self::TIER_A_ONLY ),
			$this->render( self::ENTITLEMENT_ONLY ),
			$this->render( self::SEGMENT_OTHER ),
			$this->render( self::PUBLIC_POST, 'A PUBLIC BODY' ),
			$this->render( self::MEMBERS_ONLY ),
		);

		$this->assertSame(
			array( self::BODY, self::BODY, self::BODY, self::BODY, self::BODY, 'A PUBLIC BODY', self::BODY ),
			$bodies
		);
		$this->assertSame( 1, $this->gatewayCalls( '/crm/me/entitlements' ) );
		$this->assertSame( 1, $this->gatewayCalls( '/crm/me/segments' ) );
		$this->assertSame( 1, $this->identity_lookups );
	}

	#[Test]
	public function a_gated_listing_costs_no_more_calls_than_a_granted_one(): void {
		$this->asMemberOf( array( self::SEG_B ), array( self::ENT_B ) );
		$this->frontend();

		foreach ( array( self::SEGMENT_ONLY, self::SEGMENT_OTHER, self::ENTITLEMENT_ONLY, self::SEGMENT_ONLY ) as $id ) {
			$this->assertStringContainsString( 'agend-access-gate', $this->render( $id ) );
		}

		$this->assertSame( 1, $this->gatewayCalls( '/crm/me/entitlements' ) );
		$this->assertSame( 1, $this->gatewayCalls( '/crm/me/segments' ) );
	}

	/** The excerpt filter decides through the same path, so it shares the memo rather than adding to it. */
	#[Test]
	public function excerpts_and_bodies_of_segment_posts_share_one_segments_call(): void {
		$this->asMemberOf( array( self::SEG_A ) );
		$this->frontend();
		$this->installTrimExcerptFor( self::SEGMENT_ONLY );

		$this->assertSame( self::BODY, $this->excerptOf( self::SEGMENT_ONLY ) );
		$this->assertSame( self::BODY, $this->render( self::SEGMENT_OTHER ) );

		$this->assertSame( 1, $this->gatewayCalls( '/crm/me/segments' ) );
		$this->assertLessThanOrEqual( 1, $this->gatewayCalls( '/crm/me/entitlements' ), 'changed from exactly one: a segment rule never reads the member standing' );
	}

	// -----------------------------------------------------------------------
	// Where the gate does and does not apply
	// -----------------------------------------------------------------------

	#[Test]
	public function a_wp_admin_screen_is_left_alone_for_a_user_who_may_edit_the_post(): void {
		$this->asEditor();
		$GLOBALS['agend_test_is_admin'] = true;
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
	public function an_elementor_authoring_request_is_left_alone_for_a_user_who_may_edit_the_post(): void {
		$this->asEditor();
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
		$this->assertSame( array( '' ), $frontend->suppress_builder_data( 'kept', self::MEMBERS_ONLY, '_elementor_data', true ) );
		$this->assertSame( array(), $frontend->suppress_builder_data( 'kept', self::MEMBERS_ONLY, '_elementor_data', false ) );
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
	public function the_queried_post_shows_one_placeholder_and_is_not_gated_twice(): void {
		$this->withManualExcerpt( self::QUERIED, 'A public summary.' );
		$frontend = $this->frontend();
		$this->requestTheQueriedPage( $frontend );

		$GLOBALS['agend_test_in_the_loop'] = true;
		$html                              = $this->render( self::QUERIED );

		$this->assertSame( 1, substr_count( $html, 'class="agend-access-placeholder ' ), 'one placeholder, not a placeholder inside a gate' );
		$this->assertSame( 0, $this->gateCount( $html ), 'the loop gate is not what a page\'s own URL shows' );
		$this->assertSame( 1, substr_count( $html, 'A public summary.' ) );
		$this->assertStringNotContainsString( self::BODY, $html );

		$this->assertContains( 'filter_the_content@1', $this->registrations( 'the_content' ), 'the singular filter is still what handles it' );
		$this->assertSame( array(), ( new \ReflectionProperty( $frontend, 'withheld' ) )->getValue( $frontend ), 'the loop pass left it to the singular path' );
	}

	/** The 403 and the bare message are gone: a page with no excerpt is the placeholder with no summary. */
	#[Test]
	public function the_queried_post_without_an_excerpt_shows_the_placeholder_not_a_403(): void {
		$frontend = $this->frontend();
		$this->requestTheQueriedPage( $frontend );

		$GLOBALS['agend_test_in_the_loop'] = true;
		$html                              = $this->render( self::QUERIED );

		$this->assertSame( array( 200 ), Agend_Test_WP::$status_codes );
		$this->assertStringContainsString( 'agend-access-placeholder', $html );
		$this->assertStringNotContainsString( 'agend-access-placeholder__summary', $html );
		$this->assertStringNotContainsString( self::BODY, $html );
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
	public function an_editor_on_the_live_site_gets_no_auto_excerpt_of_a_restricted_post(): void {
		$this->asEditor();
		$this->frontend();
		$this->installTrimExcerptFor( self::MEMBERS_ONLY );

		$this->assertSame( '', $this->excerptOf( self::MEMBERS_ONLY ) );
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

	/**
	 * The anonymous reading is built, not resolved: a feed asks for no bearer, no
	 * member standing and no segments, for any policy mode and whoever fetched it.
	 */
	#[Test]
	public function a_feed_never_looks_up_a_bearer_or_segments_for_any_policy_mode(): void {
		$this->asMemberOf( array( self::SEG_A ), array( self::ENT_A ) );
		$this->frontend();

		foreach ( array( self::MEMBERS_ONLY, self::TIER_A_ONLY, self::SEGMENT_ONLY, self::ENTITLEMENT_ONLY, self::UNREADABLE ) as $id ) {
			$this->assertSame( '', $this->feedContentOf( $id ), 'post ' . $id );

			$this->feedItem( $id );
			$this->assertSame( '', Agend_Test_WP::apply_registered( 'the_excerpt_rss', self::BODY ), 'summary of post ' . $id );
			$this->assertSame( '', Agend_Test_WP::apply_registered( 'get_the_excerpt', '', get_post( $id ) ), 'excerpt of post ' . $id );
		}

		$this->assertSame( 0, $this->identity_lookups, 'no bearer' );
		$this->assertSame( array(), Agend_Test_WP::$requests, 'no entitlements and no segments call' );
		$this->assertSame( array(), $this->uncacheable );
	}

	#[Test]
	public function a_feed_item_for_a_segment_or_entitlement_post_shows_only_its_manual_excerpt(): void {
		$this->withManualExcerpt( self::SEGMENT_ONLY, 'A segment summary.' );
		$this->withManualExcerpt( self::ENTITLEMENT_ONLY, 'An entitlement summary.' );
		$this->frontend();

		$this->assertSame( '<p>A segment summary.</p>', $this->feedContentOf( self::SEGMENT_ONLY ) );
		$this->assertSame( '<p>An entitlement summary.</p>', $this->feedContentOf( self::ENTITLEMENT_ONLY ) );
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
		$this->assertSame( Agend_Content_Access_Decision::STATE_GRANTED, $public['state'] );
		$this->assertSame( 0, $this->identity_lookups );
	}

	/** The anonymous reading uses the enforced policy, so a stored value this version cannot read is not served. */
	#[Test]
	public function the_anonymous_decision_denies_an_unreadable_stored_policy(): void {
		$decision = Agend_Content_Access_Decision::for_post( self::UNREADABLE, true );

		$this->assertNotSame( Agend_Content_Access_Decision::STATE_GRANTED, $decision['state'] );
		$this->assertSame( Agend_Content_Access_Policy::UNSATISFIABLE, $decision['policy'], 'the enforced reading, not null' );
	}

	#[Test]
	public function the_anonymous_decision_never_fetches_segments_or_standing(): void {
		$this->asMemberOf( array( self::SEG_A ), array( self::ENT_A ) );

		$segment     = Agend_Content_Access_Decision::for_post( self::SEGMENT_ONLY, true );
		$entitlement = Agend_Content_Access_Decision::for_post( self::ENTITLEMENT_ONLY, true );

		$this->assertSame( Agend_Content_Access_Decision::STATE_AUTH, $segment['state'], 'a member of the segment is still nobody here' );
		$this->assertSame( Agend_Content_Access_Decision::STATE_AUTH, $entitlement['state'] );
		$this->assertFalse( $segment['degraded'] );
		$this->assertSame( array(), Agend_Test_WP::$requests );
		$this->assertSame( 0, $this->identity_lookups );
	}

	/** The two readings of one post do not contaminate each other through the memo. */
	#[Test]
	public function an_anonymous_decision_does_not_change_what_the_default_decision_returns(): void {
		$this->asMemberOf( array( self::SEG_A ) );

		$this->assertSame( Agend_Content_Access_Decision::STATE_AUTH, Agend_Content_Access_Decision::for_post( self::SEGMENT_ONLY, true )['state'] );
		$this->assertSame( Agend_Content_Access_Decision::STATE_GRANTED, Agend_Content_Access_Decision::for_post( self::SEGMENT_ONLY )['state'] );
		$this->assertSame( Agend_Content_Access_Decision::STATE_AUTH, Agend_Content_Access_Decision::for_post( self::SEGMENT_ONLY, true )['state'] );
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
