<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Destination as Destination;
use Agend_Content_Access_Frontend;
use Agend_Content_Access_Placeholder as Placeholder;
use Agend_Content_Access_Policy;
use Agend_Content_Access_Rules as Rules;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

require_once __DIR__ . '/has-elementor-double.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-policy.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-catalogue.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-meta-box.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-viewer-facts.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-decision.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-frontend.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-placeholder.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-destination.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-fragment-rules.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-block-attributes.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-conditions.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-providers.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-runtime.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-rules.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-access.php';

/** Thrown where the page gate would have ended the request after a redirect. */
final class RedirectSent extends RuntimeException {}

/**
 * A page gate that throws where it would `exit`, because a test cannot end the process.
 */
final class TerminatingFrontend extends Agend_Content_Access_Frontend {

	/** @var string[] Header lines the gate sent, in order: the command line keeps none of its own. */
	public static array $headers = array();

	protected function terminate(): void {
		throw new RedirectSent( 'redirected' );
	}

	protected static function send_header( string $line ): void {
		self::$headers[] = $line;
	}
}

/**
 * What a page does with somebody its rules do not admit.
 *
 * The page's own URL shows a placeholder in place of the body by default, or
 * redirects, or sends the visitor back where they came from. Every one of them
 * is a decision about an address or a body that somebody else influences, so the
 * tests are mostly about what must NOT happen: no redirect off this site, no
 * redirect to a page the visitor could not open, no redirect to the page they are
 * on, and never the page's own body.
 */
#[CoversClass( Agend_Content_Access_Frontend::class )]
#[CoversClass( Destination::class )]
#[CoversClass( Placeholder::class )]
final class PageDenialActionsTest extends TestCase {

	private const TIER_A = '11111111-1111-1111-1111-111111111111';
	private const TIER_B = '22222222-2222-2222-2222-222222222222';

	private const DENIED     = 100;
	private const TARGET     = 101;
	private const PATTERN    = 102;
	private const TEMPLATE   = 103;
	private const RESTRICTED = 104;
	private const PROTECTED  = 105;
	private const DRAFT      = 106;
	private const FALLBACK   = 107;
	private const DASHBOARD  = 108;
	private const PRIVATE    = 109;

	private const BODY = 'THE PROTECTED BODY';

	private const MEMBER = 'agend_membership:is_member';

	/** @var int[] Post ids passed to `agend_content_access_uncacheable_render`. */
	private array $uncacheable = array();

	protected function setUp(): void {
		parent::setUp();

		$this->uncacheable         = array();
		TerminatingFrontend::$headers = array();

		$this->page( self::DENIED, 'The members page', self::BODY );
		$this->page( self::TARGET, 'A public page', 'TARGET BODY' );
		$this->post( self::PATTERN, 'wp_block', 'A pattern', '<!-- wp:paragraph --><p>PATTERN BODY</p><!-- /wp:paragraph -->' );
		$this->post( self::TEMPLATE, 'elementor_library', 'A template', 'FLATTENED TEMPLATE COPY' );
		$this->page( self::RESTRICTED, 'A members page of its own', 'RESTRICTED TARGET BODY' );
		$GLOBALS['agend_test_post_meta'][ self::RESTRICTED ][ Rules::META_KEY ] = array( 'rules' => array( self::MEMBER ), 'match' => 'any', 'invert' => false );
		$this->post( self::PROTECTED, 'page', 'A protected page', 'PROTECTED TARGET BODY', array( 'post_password' => 'hunter2' ) );
		$this->post( self::DRAFT, 'page', 'A draft', 'DRAFT BODY', array( 'post_status' => 'draft' ) );
		$this->page( self::FALLBACK, 'Where to go if there is no referrer', 'FALLBACK BODY' );
		$this->page( self::DASHBOARD, 'The dashboard', 'DASHBOARD BODY' );
		$this->post( self::PRIVATE, 'private_notes', 'Private notes', 'PRIVATE NOTES' );

		$GLOBALS['agend_test_current_user_can']['edit_post'] = false;
		$this->anonymous();

		$_SERVER['REQUEST_URI'] = '/page-' . self::DENIED . '/';

		add_action(
			'agend_content_access_uncacheable_render',
			function ( int $post_id ): void {
				$this->uncacheable[] = $post_id;
			}
		);
	}

	protected function tearDown(): void {
		unset(
			$_SERVER['REQUEST_URI'],
			$GLOBALS['agend_test_is_singular'],
			$GLOBALS['agend_test_queried_object'],
			$GLOBALS['agend_test_in_the_loop'],
			$GLOBALS['agend_test_is_admin'],
			$GLOBALS['post']
		);

		parent::tearDown();
	}

	// -----------------------------------------------------------------------
	// Fixtures
	// -----------------------------------------------------------------------

	private function post( int $id, string $type, string $title, string $content = '', array $extra = array() ): void {
		$GLOBALS['agend_test_posts'][ $id ] = array_merge(
			array(
				'ID'           => $id,
				'post_status'  => 'publish',
				'post_type'    => $type,
				'post_title'   => $title,
				'post_content' => $content,
				'post_excerpt' => '',
			),
			$extra
		);
	}

	private function page( int $id, string $title, string $content ): void {
		$this->post( $id, 'page', $title, $content );
	}

	/** Gives the denied page its rules in the new stored shape, and the action to take. */
	private function deniedPage( array $denied = array(), array $rules = array( self::MEMBER ), bool $invert = false ): void {
		$stored = array( 'rules' => $rules, 'match' => 'any', 'invert' => $invert );

		if ( array() !== $denied ) {
			$stored['denied'] = $denied;
		}

		$GLOBALS['agend_test_post_meta'][ self::DENIED ][ Rules::META_KEY ] = $stored;
	}

	private function redirectTo( int $target ): void {
		$this->deniedPage( array( 'action' => 'redirect', 'target_id' => $target, 'fallback_id' => 0 ) );
	}

	private function back( int $fallback = 0 ): void {
		$this->deniedPage( array( 'action' => 'back', 'target_id' => 0, 'fallback_id' => $fallback ) );
	}

	private function placeholderOf( int $target ): void {
		$this->deniedPage( array( 'action' => 'placeholder', 'target_id' => $target, 'fallback_id' => 0 ) );
	}

	private function anonymous(): void {
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', '' );
	}

	private function asMember( string ...$tiers ): void {
		$GLOBALS['agend_test_current_user_id'] = 7;

		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'member-token' );
		Agend_Test_WP::set_filter(
			'agend_apps_crm_get_my_entitlements_response',
			array( 'data' => array( 'is_member' => true, 'tier_ids' => $tiers ) )
		);
	}

	private function request( int $id ): void {
		Agend_Test_WP::$queried_object_id     = $id;
		$GLOBALS['agend_test_is_singular']    = true;
		$GLOBALS['agend_test_queried_object'] = get_post( $id );
	}

	/**
	 * Runs the page gate for the denied page, as `template_redirect` does.
	 *
	 * @return array{0: Agend_Content_Access_Frontend, 1: string|null} The gate, and where it
	 *         redirected to, or null when it did not.
	 */
	private function gate( int $id = self::DENIED ): array {
		$frontend = new TerminatingFrontend();
		$this->request( $id );

		try {
			$frontend->gate_request();
		} catch ( RedirectSent $sent ) {
			$last = end( Agend_Test_WP::$redirects );

			return array( $frontend, false === $last ? null : $last['location'] );
		}

		return array( $frontend, null );
	}

	/** The body of the page as the content filters would leave it, after the gate has run. */
	private function body( int $id = self::DENIED ): string {
		$GLOBALS['agend_test_in_the_loop'] = true;
		$GLOBALS['post']                   = get_post( $id );

		return (string) Agend_Test_WP::apply_registered( 'the_content', (string) get_post( $id )->post_content );
	}

	/** Gates the denied page and returns what its body shows. */
	private function placeholderBody(): string {
		$this->gate();

		return $this->body();
	}

	// -----------------------------------------------------------------------
	// The placeholder is the default
	// -----------------------------------------------------------------------

	#[Test]
	public function a_page_with_rules_and_no_denial_setting_shows_the_built_in_placeholder_in_place_of_its_body(): void {
		$this->deniedPage();

		[ $frontend, $redirect ] = $this->gate();
		$html                    = $this->body();

		$this->assertNull( $redirect );
		$this->assertSame( array(), Agend_Test_WP::$redirects );
		$this->assertStringContainsString( 'agend-access-placeholder', $html );
		$this->assertStringContainsString( 'Sign in to read this', $html );
		$this->assertStringNotContainsString( self::BODY, $html );
		$this->assertContains( 'filter_the_content', $this->registeredMethods( 'the_content' ) );
	}

	/** A page restricted under 0.1.x has only the policy and no denial setting: it gets the placeholder. */
	#[Test]
	public function a_page_with_only_a_0_1_x_policy_shows_the_placeholder_and_leads_with_its_excerpt(): void {
		$GLOBALS['agend_test_post_meta'][ self::DENIED ][ Agend_Content_Access_Policy::META_KEY ] = array( 'mode' => 'active_member' );
		$GLOBALS['agend_test_posts'][ self::DENIED ]['post_excerpt']                                = 'A public summary.';

		$html = $this->placeholderBody();

		$this->assertStringContainsString( 'A public summary.', $html );
		$this->assertStringContainsString( 'Sign in to read this', $html );
		$this->assertStringNotContainsString( self::BODY, $html );
	}

	#[Test]
	public function the_placeholder_says_why_the_visitor_was_refused(): void {
		$this->deniedPage( array(), array( 'agend_membership:' . self::TIER_A ) );
		$this->asMember( self::TIER_B );

		$html = $this->placeholderBody();

		$this->assertStringContainsString( 'Available on another plan', $html );
		$this->assertStringNotContainsString( 'Sign in to read this', $html );
	}

	#[Test]
	public function the_placeholder_response_is_a_200_that_no_cache_and_no_search_engine_may_keep(): void {
		$this->deniedPage();

		[ $frontend ] = $this->gate();

		$this->assertSame( array( 200 ), Agend_Test_WP::$status_codes );
		$this->assertSame( array( self::DENIED ), $this->uncacheable, 'marked uncacheable: DONOTCACHEPAGE and Cache-Control private, no-store' );
		$this->assertContains( 'robots_noindex', $this->registeredMethods( 'wp_robots' ) );
		$this->assertSame(
			array( 'max-image-preview' => 'large', 'noindex' => true, 'nofollow' => true ),
			$frontend->robots_noindex( array( 'max-image-preview' => 'large' ) )
		);
	}

	/** What the browser and every cache in front of it are told, which is the point of a placeholder at a URL. */
	#[Test]
	public function the_placeholder_response_sends_the_cache_and_robots_headers(): void {
		$this->deniedPage();

		$this->gate();

		$this->assertContains( 'Cache-Control: private, no-store', TerminatingFrontend::$headers );
		$this->assertContains( 'X-Robots-Tag: noindex, nofollow', TerminatingFrontend::$headers );
	}

	/**
	 * `define()` cannot be undone, so the constant is asserted in a process of its
	 * own.
	 */
	#[Test]
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function the_placeholder_response_defines_donotcachepage(): void {
		$this->assertFalse( defined( 'DONOTCACHEPAGE' ), 'a clean process starts without it' );
		$this->deniedPage();

		$this->gate();

		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) );
		$this->assertTrue( DONOTCACHEPAGE );
	}

	#[Test]
	public function a_granted_visitor_gets_none_of_it(): void {
		$this->deniedPage( array(), array( 'agend_membership:' . self::TIER_A ) );
		$this->asMember( self::TIER_A );

		$this->gate();

		$this->assertSame( array(), TerminatingFrontend::$headers );
		$this->assertSame( array(), Agend_Test_WP::$status_codes );
		$this->assertSame( array(), $this->uncacheable );
	}

	#[Test]
	public function a_redirect_is_marked_uncacheable_too(): void {
		$this->redirectTo( self::TARGET );

		$this->gate();

		$this->assertSame( array( self::DENIED ), $this->uncacheable, 'a cached redirect would be sent to the next visitor, who may be allowed' );
		$this->assertContains( 'Cache-Control: private, no-store', TerminatingFrontend::$headers );
	}

	#[Test]
	public function the_built_in_stylesheet_is_enqueued_in_the_head_for_the_built_in_placeholder_only(): void {
		$this->deniedPage();
		$this->gate();

		$this->assertSame( array( Placeholder::STYLE_HANDLE ), Agend_Test_WP::$enqueued_styles );

		Agend_Test_WP::$enqueued_styles = array();
		$this->placeholderOf( self::TARGET );
		$this->gate();

		$this->assertSame( array(), Agend_Test_WP::$enqueued_styles, 'a chosen page brings its own styling' );
	}

	// -----------------------------------------------------------------------
	// A placeholder the author chose
	// -----------------------------------------------------------------------

	#[Test]
	public function a_chosen_public_page_is_shown_in_place_of_the_body(): void {
		$this->placeholderOf( self::TARGET );

		$html = $this->placeholderBody();

		$this->assertStringContainsString( 'TARGET BODY', $html );
		$this->assertStringNotContainsString( 'agend-access-placeholder__heading', $html, 'no built-in placeholder as well' );
		$this->assertStringNotContainsString( self::BODY, $html );
		$this->assertSame( array( 200 ), Agend_Test_WP::$status_codes );
	}

	/** The chosen page is rendered through `the_content`, the filter that builds the placeholder. */
	#[Test]
	public function rendering_a_chosen_page_does_not_ask_for_the_placeholder_again(): void {
		$this->placeholderOf( self::TARGET );

		$html = $this->placeholderBody();

		$this->assertSame( 1, substr_count( $html, 'TARGET BODY' ) );
	}

	#[Test]
	public function a_chosen_synced_pattern_is_shown_and_its_message_shortcode_speaks_for_this_denial(): void {
		Placeholder::init();
		$this->post( self::PATTERN, 'wp_block', 'A pattern', '<!-- wp:paragraph --><p>PATTERN BODY</p><!-- /wp:paragraph -->' . "\n" . '[agend_access_message]' );
		update_option( 'agend_apps_portal_url', 'https://portal.example.test' );
		$this->placeholderOf( self::PATTERN );

		$html = $this->placeholderBody();

		$this->assertStringContainsString( 'PATTERN BODY', $html );
		$this->assertStringContainsString( 'Sign in to read this', $html, 'the shortcode read the denial the gate stored' );
		$this->assertStringContainsString( 'portal.example.test', $html );
		$this->assertStringNotContainsString( self::BODY, $html );
	}

	#[Test]
	public function a_chosen_elementor_template_is_shown(): void {
		Agend_Test_WP::$did_action['elementor/loaded'] = 1;

		\Elementor\Plugin::$instance->frontend = new class() {
			public function get_builder_content_for_display( $post_id, $with_css = false ): string {
				return '<div class="elementor">TEMPLATE BODY</div>';
			}
		};
		$this->placeholderOf( self::TEMPLATE );

		$html = $this->placeholderBody();

		$this->assertStringContainsString( 'TEMPLATE BODY', $html );
		$this->assertStringNotContainsString( self::BODY, $html );
	}

	/** @return array<string, array{0: int}> */
	public static function placeholdersThatMayNotBeShown(): array {
		return array(
			'a page that does not exist'                 => array( 9999 ),
			'a page the visitor would be denied'        => array( self::RESTRICTED ),
			'a page with a password'                    => array( self::PROTECTED ),
			'a page that is not published'              => array( self::DRAFT ),
			'a post type that cannot carry rules'       => array( self::PRIVATE ),
			'the denied page itself'                    => array( self::DENIED ),
		);
	}

	/**
	 * The one thing a placeholder must never be is the restricted body, or another
	 * page the visitor is not allowed to read.
	 */
	#[Test]
	#[DataProvider( 'placeholdersThatMayNotBeShown' )]
	public function a_chosen_placeholder_that_may_not_be_shown_is_replaced_by_the_built_in_one( int $target ): void {
		$this->placeholderOf( $target );

		$html = $this->placeholderBody();

		$this->assertStringContainsString( 'agend-access-placeholder__heading', $html, 'the built-in placeholder' );
		$this->assertStringNotContainsString( self::BODY, $html );
		$this->assertStringNotContainsString( 'RESTRICTED TARGET BODY', $html );
		$this->assertStringNotContainsString( 'PROTECTED TARGET BODY', $html );
		$this->assertStringNotContainsString( 'DRAFT BODY', $html );
		$this->assertStringNotContainsString( 'PRIVATE NOTES', $html );
	}

	#[Test]
	public function an_elementor_template_with_no_elementor_to_render_it_is_replaced_by_the_built_in_placeholder(): void {
		$this->placeholderOf( self::TEMPLATE );

		$html = $this->placeholderBody();

		$this->assertStringContainsString( 'agend-access-placeholder__heading', $html );
		$this->assertStringNotContainsString( 'FLATTENED TEMPLATE COPY', $html );
	}

	/** The target's own rules are judged for THIS visitor, so one they pass is theirs to see. */
	#[Test]
	public function a_chosen_page_whose_rules_admit_this_visitor_is_shown(): void {
		$this->deniedPage( array( 'action' => 'placeholder', 'target_id' => self::RESTRICTED, 'fallback_id' => 0 ), array( 'agend_membership:' . self::TIER_B ) );
		$this->asMember( self::TIER_A );

		$html = $this->placeholderBody();

		$this->assertStringContainsString( 'RESTRICTED TARGET BODY', $html );
		$this->assertStringNotContainsString( self::BODY, $html );
	}

	// -----------------------------------------------------------------------
	// Elementor pages
	// -----------------------------------------------------------------------

	/**
	 * Elementor re-derives a page's body from its own stored data at a priority in
	 * the millions and ignores what earlier filters left. The data is blanked for the
	 * denied page, so what it has to work with is nothing.
	 */
	#[Test]
	public function an_elementor_page_shows_the_placeholder_and_not_its_builder_output(): void {
		$GLOBALS['agend_test_post_meta'][ self::DENIED ]['_elementor_data'] = '[{"id":"a","elType":"section","settings":{"title":"BUILDER SECTION"}}]';
		$GLOBALS['agend_test_post_meta'][ self::TARGET ]['_elementor_data'] = '[{"id":"b"}]';
		$this->deniedPage();
		$this->gate();

		add_filter(
			'the_content',
			static function ( $content ) {
				$data = get_post_meta( (int) get_post()->ID, '_elementor_data', true );

				return empty( $data ) ? $content : 'BUILDER OUTPUT ' . $data;
			},
			9999999
		);

		$html = $this->body();

		$this->assertStringNotContainsString( 'BUILDER', $html );
		$this->assertStringContainsString( 'agend-access-placeholder', $html );
		$this->assertSame( '', get_post_meta( self::DENIED, '_elementor_data', true ), 'the builder data of the denied page is blanked' );
		$this->assertSame( '[{"id":"b"}]', get_post_meta( self::TARGET, '_elementor_data', true ), 'and nobody else\'s' );
	}

	// -----------------------------------------------------------------------
	// Redirect
	// -----------------------------------------------------------------------

	#[Test]
	public function the_redirect_action_sends_a_302_to_the_chosen_page_before_any_body_is_prepared(): void {
		$this->redirectTo( self::TARGET );

		[ , $redirect ] = $this->gate();

		$this->assertSame( 'https://example.test/page-' . self::TARGET . '/', $redirect );
		$this->assertSame( 302, Agend_Test_WP::$redirects[0]['status'] );
		$this->assertNotContains( 'filter_the_content', $this->registeredMethods( 'the_content' ), 'no placeholder as well' );
		$this->assertSame( array(), Agend_Test_WP::$status_codes );
	}

	/** @return array<string, array{0: int}> */
	public static function redirectTargetsThatAreNoUse(): array {
		return array(
			'no page chosen'                              => array( 0 ),
			'a page that does not exist'                  => array( 9999 ),
			'a page that is not published'                => array( self::DRAFT ),
			'a page with a password'                      => array( self::PROTECTED ),
			'a page this visitor would be denied'         => array( self::RESTRICTED ),
			'the page being left'                         => array( self::DENIED ),
			'a pattern, which has no address'             => array( self::PATTERN ),
			'an Elementor template, which has none either' => array( self::TEMPLATE ),
			'a post type that cannot carry rules'         => array( self::PRIVATE ),
		);
	}

	#[Test]
	#[DataProvider( 'redirectTargetsThatAreNoUse' )]
	public function a_redirect_that_cannot_be_made_shows_the_placeholder_instead_and_never_the_body( int $target ): void {
		Agend_Test_WP::$did_action['elementor/loaded'] = 1;
		$this->redirectTo( $target );

		[ , $redirect ] = $this->gate();
		$html           = $this->body();

		$this->assertNull( $redirect );
		$this->assertSame( array(), Agend_Test_WP::$redirects );
		$this->assertStringContainsString( 'agend-access-placeholder__heading', $html );
		$this->assertStringNotContainsString( self::BODY, $html );
	}

	/**
	 * A permalink is whatever the site's `page_link` filters say, and a multi-domain
	 * or CDN set-up can put it anywhere. A redirect only ever goes to this site.
	 */
	#[Test]
	public function a_chosen_page_whose_permalink_is_on_another_host_is_not_a_destination(): void {
		$GLOBALS['agend_test_permalinks'][ self::TARGET ] = 'https://elsewhere.test/page/';
		$this->redirectTo( self::TARGET );

		[ , $redirect ] = $this->gate();

		$this->assertNull( $redirect );
		$this->assertSame( array(), Agend_Test_WP::$redirects );
		$this->assertStringContainsString( 'agend-access-placeholder__heading', $this->body() );
	}

	/** The built-in placeholder, not the page's own chosen one: a redirect action has none. */
	#[Test]
	public function a_redirect_that_falls_back_never_uses_the_redirect_target_as_a_placeholder(): void {
		$this->redirectTo( self::RESTRICTED );

		$this->gate();

		$this->assertStringNotContainsString( 'RESTRICTED TARGET BODY', $this->body() );
	}

	/** A redirect to the very address the visitor is on is a loop, whatever page it is. */
	#[Test]
	public function a_redirect_to_the_current_address_is_a_loop_and_shows_the_placeholder(): void {
		$this->redirectTo( self::TARGET );
		$_SERVER['REQUEST_URI'] = '/page-' . self::TARGET . '/';

		[ , $redirect ] = $this->gate();

		$this->assertNull( $redirect );
		$this->assertStringContainsString( 'agend-access-placeholder__heading', $this->body() );
	}

	#[Test]
	public function the_loop_guard_ignores_case_a_trailing_slash_the_query_and_the_fragment(): void {
		$this->redirectTo( self::TARGET );

		foreach ( array( '/PAGE-' . self::TARGET, '/page-' . self::TARGET . '/?utm_source=x', '/page-' . self::TARGET . '/#top' ) as $uri ) {
			Agend_Test_WP::$redirects = array();
			$_SERVER['REQUEST_URI']   = $uri;

			[ , $redirect ] = $this->gate();

			$this->assertNull( $redirect, $uri );
		}
	}

	// -----------------------------------------------------------------------
	// Reverse: the action is for whoever the rules deny
	// -----------------------------------------------------------------------

	/** A join page that sends the people who already are members to their dashboard. */
	#[Test]
	public function a_member_denied_by_a_reversed_rule_is_redirected(): void {
		$this->deniedPage( array( 'action' => 'redirect', 'target_id' => self::DASHBOARD, 'fallback_id' => 0 ), array( self::MEMBER ), true );
		$this->asMember( self::TIER_A );

		[ , $redirect ] = $this->gate();

		$this->assertSame( 'https://example.test/page-' . self::DASHBOARD . '/', $redirect, 'a member is sent to their dashboard' );
		$this->assertSame( 302, Agend_Test_WP::$redirects[0]['status'] );
	}

	/** ... and the people who are not members read the join page. */
	#[Test]
	public function a_visitor_who_is_not_a_member_reads_a_page_that_redirects_members(): void {
		$this->deniedPage( array( 'action' => 'redirect', 'target_id' => self::DASHBOARD, 'fallback_id' => 0 ), array( self::MEMBER ), true );

		[ , $redirect ] = $this->gate();

		$this->assertNull( $redirect );
		$this->assertSame( array(), Agend_Test_WP::$redirects );
		$this->assertNotContains( 'filter_the_content', $this->registeredMethods( 'the_content' ), 'shown the page, not a placeholder' );
		$this->assertNull( Agend_Content_Access_Frontend::current_gate() );
	}

	#[Test]
	public function a_member_denied_by_a_reversed_rule_sees_the_placeholder_when_that_is_the_action(): void {
		$this->deniedPage( array(), array( self::MEMBER ), true );
		$this->asMember( self::TIER_A );

		$html = $this->placeholderBody();

		$this->assertStringContainsString( 'This content is restricted', $html );
		$this->assertStringNotContainsString( self::BODY, $html );
	}

	// -----------------------------------------------------------------------
	// Back
	// -----------------------------------------------------------------------

	#[Test]
	public function back_sends_the_visitor_to_the_same_site_page_they_came_from_keeping_its_query(): void {
		$this->back( self::FALLBACK );
		$GLOBALS['agend_test_referer'] = 'https://example.test/blog/a-post/?page=2';

		[ , $redirect ] = $this->gate();

		$this->assertSame( 'https://example.test/blog/a-post/?page=2', $redirect );
		$this->assertSame( 302, Agend_Test_WP::$redirects[0]['status'] );
	}

	/** The address is rebuilt on this site's own scheme and host, not echoed from the header. */
	#[Test]
	public function back_normalises_the_scheme_and_host_of_the_referrer(): void {
		$this->back();
		$GLOBALS['agend_test_referer'] = 'http://EXAMPLE.test/blog/a-post/';

		[ , $redirect ] = $this->gate();

		$this->assertSame( 'https://example.test/blog/a-post/', $redirect );
	}

	/** @return array<string, array{0: string|null}> */
	public static function referrersThatAreNoUse(): array {
		return array(
			'none at all'                                     => array( null ),
			'an off-site referrer'                            => array( 'https://elsewhere.test/page' ),
			'this page'                                       => array( 'https://example.test/page-' . self::DENIED . '/' ),
			'this page on http'                               => array( 'http://example.test/page-' . self::DENIED . '/' ),
			'this page without its trailing slash'            => array( 'https://example.test/page-' . self::DENIED ),
			'this page with a query'                          => array( 'https://example.test/page-' . self::DENIED . '/?s=members' ),
			'this page in another case'                       => array( 'https://EXAMPLE.test/Page-' . self::DENIED . '/' ),
			'this page with a fragment'                       => array( 'https://example.test/page-' . self::DENIED . '/#comments' ),
		);
	}

	/**
	 * Each falls to the fallback page, and to the placeholder when there is none
	 * or it is no use.
	 */
	#[Test]
	#[DataProvider( 'referrersThatAreNoUse' )]
	public function back_falls_back_when_the_referrer_is_missing_off_site_or_this_page( ?string $referer ): void {
		if ( null !== $referer ) {
			$GLOBALS['agend_test_referer'] = $referer;
		}

		$this->back( self::FALLBACK );
		[ , $redirect ] = $this->gate();
		$this->assertSame( 'https://example.test/page-' . self::FALLBACK . '/', $redirect, 'the page the author chose for that' );

		Agend_Test_WP::$redirects = array();
		$this->back();
		[ , $redirect ] = $this->gate();
		$this->assertNull( $redirect, 'no fallback chosen' );
		$this->assertStringContainsString( 'agend-access-placeholder__heading', $this->body() );

		Agend_Test_WP::$redirects = array();
		$this->back( self::RESTRICTED );
		[ , $redirect ] = $this->gate();
		$this->assertNull( $redirect, 'a fallback this visitor could not open' );
		$this->assertStringNotContainsString( 'RESTRICTED TARGET BODY', $this->body() );
		$this->assertStringNotContainsString( self::BODY, $this->body() );
	}

	#[Test]
	public function back_does_not_use_the_fallback_when_there_is_a_good_referrer(): void {
		$this->back( self::FALLBACK );
		$GLOBALS['agend_test_referer'] = 'https://example.test/somewhere/';

		[ , $redirect ] = $this->gate();

		$this->assertSame( 'https://example.test/somewhere/', $redirect );
	}

	/**
	 * Never an open redirect. A referrer is whatever the visitor's browser, or a link on
	 * somebody else's site, says it is, so every address that is not plainly this site's
	 * is refused and the visitor goes to the fallback instead.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function openRedirectAttempts(): array {
		return array(
			'another host'                                 => array( 'https://evil.test/' ),
			'a host that starts with ours'                 => array( 'https://example.test.evil.test/' ),
			'a host that ends with ours'                   => array( 'https://evil-example.test/' ),
			'ours as user information'                     => array( 'https://example.test@evil.test/' ),
			'ours as user information and a password'      => array( 'https://example.test:secret@evil.test/' ),
			'a backslash before ours'                      => array( 'https://evil.test\\@example.test/' ),
			'a backslash path'                             => array( 'https://example.test/\\evil.test' ),
			'a protocol-relative address'                  => array( '//evil.test/' ),
			'a scheme that is not http'                    => array( 'javascript:alert(1)' ),
			'a data address'                               => array( 'data:text/html,<script>alert(1)</script>' ),
			'ftp on our host'                              => array( 'ftp://example.test/file' ),
			'our host on another port'                     => array( 'https://example.test:8443/x' ),
			'a header injection'                           => array( "https://example.test/x\r\nLocation: https://evil.test/" ),
			'a space'                                      => array( 'https://example.test/a b' ),
			'a relative path with no host'                 => array( '/just/a/path' ),
			'ours only in the query'                       => array( 'https://evil.test/?next=https://example.test/' ),
			'ours only in the fragment'                    => array( 'https://evil.test/#https://example.test/' ),
			'not an address'                               => array( 'not a url' ),
			'a host with no path that is not ours'         => array( 'https://evil.test' ),
		);
	}

	#[Test]
	#[DataProvider( 'openRedirectAttempts' )]
	public function back_is_never_an_open_redirect( string $referer ): void {
		$this->back( self::FALLBACK );
		$GLOBALS['agend_test_referer'] = $referer;

		[ , $redirect ] = $this->gate();

		$this->assertSame( 'https://example.test/page-' . self::FALLBACK . '/', $redirect, 'only ever the fallback page, which is on this site' );
		$this->assertCount( 1, Agend_Test_WP::$redirects );
		$this->assertStringStartsWith( 'https://example.test/', Agend_Test_WP::$redirects[0]['location'] );
	}

	#[Test]
	#[DataProvider( 'openRedirectAttempts' )]
	public function back_with_no_fallback_shows_the_placeholder_to_an_open_redirect_attempt( string $referer ): void {
		$this->back();
		$GLOBALS['agend_test_referer'] = $referer;

		[ , $redirect ] = $this->gate();

		$this->assertNull( $redirect );
		$this->assertSame( array(), Agend_Test_WP::$redirects, 'nothing is sent anywhere' );
		$this->assertStringNotContainsString( self::BODY, $this->body() );
	}

	// -----------------------------------------------------------------------
	// Who the action is for
	// -----------------------------------------------------------------------

	#[Test]
	public function somebody_who_may_edit_the_page_sees_it_whatever_the_action(): void {
		$this->redirectTo( self::TARGET );
		$GLOBALS['agend_test_current_user_can']['edit_post'] = true;

		[ , $redirect ] = $this->gate();

		$this->assertNull( $redirect );
		$this->assertSame( array(), Agend_Test_WP::$redirects );
		$this->assertNotContains( 'filter_the_content', $this->registeredMethods( 'the_content' ) );
		$this->assertSame( array(), Agend_Test_WP::$status_codes );
		$this->assertSame( array(), $this->uncacheable );
		$this->assertNull( Agend_Content_Access_Frontend::current_gate() );
	}

	#[Test]
	public function an_editing_session_for_the_page_is_not_redirected_either(): void {
		$this->redirectTo( self::TARGET );
		$GLOBALS['agend_test_current_user_can']['edit_post'] = true;
		$GLOBALS['agend_test_is_admin']                      = true;

		[ , $redirect ] = $this->gate();

		$this->assertNull( $redirect );
	}

	#[Test]
	public function a_visitor_the_rules_admit_is_never_redirected(): void {
		$this->deniedPage( array( 'action' => 'redirect', 'target_id' => self::TARGET, 'fallback_id' => 0 ), array( 'agend_membership:' . self::TIER_A ) );
		$this->asMember( self::TIER_A );

		[ , $redirect ] = $this->gate();

		$this->assertNull( $redirect );
		$this->assertSame( array(), Agend_Test_WP::$redirects );
		$this->assertNotContains( 'filter_the_content', $this->registeredMethods( 'the_content' ) );
	}

	/** Agend cannot be reached: the lookup for a signed-in visitor fails, so the denial is down to an outage. */
	private function outage(): void {
		$GLOBALS['agend_test_current_user_id'] = 7;

		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'member-token' );
		Agend_Test_WP::set_filter( 'agend_apps_crm_get_my_entitlements_response', new WP_Error( 'http_request_failed', 'down' ) );
	}

	/**
	 * An outage that could not tell who the visitor is still denies, and still never
	 * shows the body, but it does not redirect: the visitor may well be a member whom
	 * Agend could not confirm, and bouncing real members off the site for as long as
	 * the outage lasts is worse than showing them a placeholder.
	 */
	#[Test]
	public function a_denial_down_to_an_outage_shows_the_placeholder_instead_of_redirecting(): void {
		$this->outage();
		$this->redirectTo( self::TARGET );

		[ , $redirect ] = $this->gate();
		$html          = $this->body();

		$this->assertNull( $redirect );
		$this->assertSame( array(), Agend_Test_WP::$redirects );
		$this->assertStringContainsString( 'agend-access-placeholder', $html );
		$this->assertStringNotContainsString( self::BODY, $html );
	}

	#[Test]
	public function a_denial_down_to_an_outage_does_not_send_the_visitor_back_either(): void {
		$this->outage();
		$this->back( self::FALLBACK );
		$_SERVER['HTTP_REFERER'] = 'https://example.test/came-from/';
		$GLOBALS['agend_test_referer'] = 'https://example.test/came-from/';

		[ , $redirect ] = $this->gate();

		$this->assertNull( $redirect );
		$this->assertSame( array(), Agend_Test_WP::$redirects );
		$this->assertStringContainsString( 'agend-access-placeholder', $this->body() );

		unset( $_SERVER['HTTP_REFERER'] );
	}

	/** The built-in placeholder, because a redirect and a way back have no placeholder of their own. */
	#[Test]
	public function a_denial_down_to_an_outage_on_a_redirecting_page_shows_the_built_in_placeholder(): void {
		$this->outage();
		$this->post( self::PATTERN, 'wp_block', 'A pattern', '<!-- wp:paragraph --><p>PATTERN BODY</p><!-- /wp:paragraph -->' );
		$this->redirectTo( self::PATTERN );

		$this->gate();
		$html = $this->body();

		$this->assertStringContainsString( 'agend-access-placeholder', $html );
		$this->assertStringNotContainsString( 'PATTERN BODY', $html );
	}

	/** A page that chose its placeholder keeps showing it in an outage. */
	#[Test]
	public function a_denial_down_to_an_outage_on_a_page_with_a_chosen_placeholder_shows_that_one(): void {
		$this->outage();
		$this->post( self::PATTERN, 'wp_block', 'A pattern', '<!-- wp:paragraph --><p>PATTERN BODY</p><!-- /wp:paragraph -->' );
		$this->placeholderOf( self::PATTERN );

		$this->gate();
		$html = $this->body();

		$this->assertStringContainsString( 'PATTERN BODY', $html );
		$this->assertStringNotContainsString( self::BODY, $html );
	}

	/** Reverse does not change it: a join page that sends members to their dashboard must not do it in an outage. */
	#[Test]
	public function a_reversed_page_does_not_redirect_in_an_outage_either(): void {
		$this->outage();
		$this->deniedPage( array( 'action' => 'redirect', 'target_id' => self::DASHBOARD, 'fallback_id' => 0 ), array( self::MEMBER ), true );

		[ , $redirect ] = $this->gate();

		$this->assertNull( $redirect );
		$this->assertSame( array(), Agend_Test_WP::$redirects );
		$this->assertStringContainsString( 'agend-access-placeholder', $this->body() );
	}

	/** The other half of the rule: a denial that is not down to an outage still redirects. */
	#[Test]
	public function a_denial_that_is_not_an_outage_still_redirects(): void {
		$this->asMember( self::TIER_B );
		$this->deniedPage( array( 'action' => 'redirect', 'target_id' => self::TARGET, 'fallback_id' => 0 ), array( 'agend_membership:' . self::TIER_A ) );

		[ , $redirect ] = $this->gate();

		$this->assertSame( 'https://example.test/page-' . self::TARGET . '/', $redirect );
	}

	// -----------------------------------------------------------------------
	// Lists, feeds and REST are as they were
	// -----------------------------------------------------------------------

	/** Redirects and placeholders apply to the page's own URL only. */
	#[Test]
	public function lists_feeds_and_rest_keep_showing_only_the_summary_whatever_the_page_does_with_its_own_url(): void {
		foreach ( array( 'redirect', 'back', 'placeholder' ) as $action ) {
			Agend_Test_WP::$redirects = array();
			$this->deniedPage( array( 'action' => $action, 'target_id' => self::TARGET, 'fallback_id' => self::FALLBACK ) );
			$GLOBALS['agend_test_posts'][ self::DENIED ]['post_excerpt'] = 'A public summary.';

			$frontend = new TerminatingFrontend();
			$GLOBALS['post'] = get_post( self::DENIED );

			// A list: the loop gate, with the summary and no body.
			$html = $frontend->withhold_in_loop( self::BODY );
			$this->assertStringContainsString( 'agend-access-gate', $html, $action );
			$this->assertStringContainsString( 'A public summary.', $html, $action );
			$this->assertStringNotContainsString( self::BODY, $html, $action );

			// An excerpt, and a feed: the summary and nothing else.
			$this->assertSame( 'A public summary.', $frontend->filter_excerpt( 'EXCERPT OF THE BODY', get_post( self::DENIED ) ), $action );
			$this->assertSame( '<p>A public summary.</p>', $frontend->filter_feed_content( self::BODY ), $action );

			// REST: the body is withheld.
			$data = $frontend->filter_rest_response(
				new WP_REST_Response( array( 'id' => self::DENIED, 'content' => array( 'rendered' => self::BODY ), 'excerpt' => array( 'rendered' => 'x' ) ) ),
				get_post( self::DENIED ),
				new WP_REST_Request( 'GET', '/wp/v2/pages/' . self::DENIED )
			)->get_data();
			$this->assertSame( '', $data['content']['rendered'], $action );

			$this->assertSame( array(), Agend_Test_WP::$redirects, $action . ': nothing outside the page\'s own URL redirects' );
		}
	}

	// -----------------------------------------------------------------------
	// Fixtures for what is registered
	// -----------------------------------------------------------------------

	/** @return string[] Methods registered on a hook. */
	private function registeredMethods( string $hook ): array {
		return array_map(
			static fn( array $entry ): string => is_array( $entry['callback'] ) ? (string) $entry['callback'][1] : '(closure)',
			Agend_Test_WP::$filter_registry[ $hook ] ?? array()
		);
	}
}
