<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Frontend;
use Agend_Content_Access_Rules as Rules;
use Agend_Test_WP;
use Agend_Test_WP_Die_Exception;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

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

/** Thrown where the gate would have ended the request. */
final class DeniedPageEnded extends RuntimeException {}

/**
 * A page gate that throws where it would `exit`, and that can be pointed at a
 * template file that is not there.
 */
final class DeniedPageFrontend extends Agend_Content_Access_Frontend {

	/** @var string|null The template file to hand out, or null for the real one. */
	public static ?string $template = null;

	/** @var string[] Header lines the gate sent. */
	public static array $headers = array();

	protected function terminate(): void {
		throw new DeniedPageEnded( 'ended' );
	}

	protected static function send_header( string $line ): void {
		self::$headers[] = $line;
	}

	protected static function denied_template_file(): string {
		return self::$template ?? parent::denied_template_file();
	}
}

/**
 * A denied page is served with a template of the plugin's own, never the page's.
 *
 * The page gate used to swap only what `the_content` returned and let the page's
 * own template run. Everything a template prints that is not `the_content` went to
 * the visitor, and a builder that renders its layout from meta on `the_content`
 * overwrote the placeholder. These tests are about what must NOT reach a visitor:
 * the page's own template, the layout a builder renders, and the page's feed.
 */
#[CoversClass( Agend_Content_Access_Frontend::class )]
final class DeniedPageTemplateTest extends TestCase {

	private const DENIED = 100;
	private const PUBLIC = 101;

	private const BODY = 'THE PROTECTED BODY';

	private const MEMBER = 'agend_membership:is_member';

	/** @var int[] Posts passed to `agend_content_access_uncacheable_render`. */
	private array $uncacheable = array();

	protected function setUp(): void {
		parent::setUp();

		DeniedPageFrontend::$template = null;
		DeniedPageFrontend::$headers  = array();
		$this->uncacheable            = array();

		$this->page( self::DENIED, 'The members page', self::BODY );
		$this->page( self::PUBLIC, 'A public page', 'PUBLIC BODY' );

		$GLOBALS['agend_test_post_meta'][ self::DENIED ][ Rules::META_KEY ] = array( 'rules' => array( self::MEMBER ), 'match' => 'any', 'invert' => false );
		$GLOBALS['agend_test_current_user_can']['edit_post']                = false;
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', '' );

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
			$GLOBALS['post']
		);

		parent::tearDown();
	}

	private function page( int $id, string $title, string $content ): void {
		$GLOBALS['agend_test_posts'][ $id ] = array(
			'ID'           => $id,
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_title'   => $title,
			'post_content' => $content,
			'post_excerpt' => '',
		);
	}

	private function asMember(): void {
		$GLOBALS['agend_test_current_user_id'] = 7;

		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'member-token' );
		Agend_Test_WP::set_filter(
			'agend_apps_crm_get_my_entitlements_response',
			array( 'data' => array( 'is_member' => true, 'tier_ids' => array( '11111111-1111-1111-1111-111111111111' ) ) )
		);
	}

	/** Runs the page gate for a page, as `template_redirect` does. */
	private function gate( int $id = self::DENIED ): DeniedPageFrontend {
		$frontend = new DeniedPageFrontend();

		Agend_Test_WP::$queried_object_id     = $id;
		$GLOBALS['agend_test_is_singular']    = true;
		$GLOBALS['agend_test_queried_object'] = get_post( $id );

		try {
			$frontend->gate_request();
		} catch ( DeniedPageEnded $ended ) {
			// A refusal ends the request: the caller asserts on what it left.
			unset( $ended );
		}

		return $frontend;
	}

	/** The body of a page as the content filters leave it, inside the main loop. */
	private function body( int $id = self::DENIED ): string {
		$GLOBALS['agend_test_in_the_loop'] = true;
		$GLOBALS['post']                   = get_post( $id );

		return (string) Agend_Test_WP::apply_registered( 'the_content', (string) get_post( $id )->post_content );
	}

	/** What the denied page prints. */
	private function printed( Agend_Content_Access_Frontend $frontend ): string {
		ob_start();
		$frontend->print_denied_page();

		return (string) ob_get_clean();
	}

	/** @return string[] The callback names registered on a hook, with their priorities. */
	private function registered( string $hook ): array {
		return array_map(
			static fn( array $entry ): string => ( is_array( $entry['callback'] ) ? (string) $entry['callback'][1] : '(closure)' ) . '@' . $entry['priority'],
			Agend_Test_WP::$filter_registry[ $hook ] ?? array()
		);
	}

	// -----------------------------------------------------------------------
	// The template is replaced
	// -----------------------------------------------------------------------

	#[Test]
	public function a_denied_page_is_served_with_the_plugins_template_not_the_page_s_own(): void {
		$frontend = $this->gate();

		$this->assertContains( 'use_denied_template@' . PHP_INT_MAX, $this->registered( 'template_include' ), 'last, so nothing after it can put the page template back' );

		$template = $frontend->use_denied_template( '/themes/test/page-members.php' );

		$this->assertNotSame( '/themes/test/page-members.php', $template );
		$this->assertSame( 'denied-page.php', basename( (string) $template ) );
		$this->assertFileExists( (string) $template );
	}

	/** A theme builder's template, a canvas, a plugin's: all of them run before this one. */
	#[Test]
	public function nothing_that_chooses_a_template_can_choose_over_it(): void {
		add_filter( 'template_include', static fn( $template ) => '/plugins/builder/canvas.php', 99 );

		$this->gate();

		$this->assertSame(
			'denied-page.php',
			basename( (string) Agend_Test_WP::apply_registered( 'template_include', '/themes/test/page-members.php' ) )
		);
	}

	#[Test]
	public function the_plugins_template_prints_the_denied_page_through_the_gate(): void {
		$frontend = $this->gate();
		$template = $frontend->use_denied_template( '/themes/test/page-members.php' );

		ob_start();
		include (string) $template;
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'agend-access-placeholder', $html );
		$this->assertStringNotContainsString( self::BODY, $html );
		$this->assertSame( array( 'get_header', 'get_footer' ), $GLOBALS['agend_test_theme_calls'] );
	}

	#[Test]
	public function a_page_that_is_granted_keeps_its_own_template(): void {
		$this->asMember();

		$this->gate();

		$this->assertSame( array(), $this->registered( 'template_include' ) );
		$this->assertSame( '/themes/test/page-members.php', Agend_Test_WP::apply_registered( 'template_include', '/themes/test/page-members.php' ) );
	}

	#[Test]
	public function an_editor_of_the_page_keeps_its_own_template(): void {
		$GLOBALS['agend_test_current_user_can']['edit_post'] = true;

		$this->gate();

		$this->assertSame( array(), $this->registered( 'template_include' ) );
	}

	/** Only a broken deployment loses the file, and then nothing about the page is served. */
	#[Test]
	public function a_missing_template_file_ends_in_a_403_and_never_the_page_s_template(): void {
		DeniedPageFrontend::$template = '/nowhere/denied-page.php';

		$frontend = $this->gate();

		$this->expectException( Agend_Test_WP_Die_Exception::class );

		$frontend->use_denied_template( '/themes/test/page-members.php' );
	}

	// -----------------------------------------------------------------------
	// What it prints
	// -----------------------------------------------------------------------

	#[Test]
	public function a_classic_theme_gets_its_header_the_placeholder_and_its_footer_and_nothing_else(): void {
		$html = $this->printed( $this->gate() );

		$this->assertSame( array( 'get_header', 'get_footer' ), $GLOBALS['agend_test_theme_calls'] );
		$this->assertLessThan( strpos( $html, 'agend-access-placeholder' ), strpos( $html, '<!--get_header-->' ) );
		$this->assertLessThan( strpos( $html, '<!--get_footer-->' ), strpos( $html, 'agend-access-placeholder' ) );
		$this->assertStringContainsString( 'Sign in to read this', $html );
		$this->assertStringNotContainsString( self::BODY, $html );
		$this->assertStringNotContainsString( 'The members page', $html, 'the page prints nothing of itself, its title included' );
	}

	#[Test]
	public function a_block_theme_gets_its_header_and_footer_parts_in_a_document_with_the_placeholder_between(): void {
		$GLOBALS['agend_test_block_theme'] = true;

		$parts = array();

		add_filter(
			'pre_render_block',
			static function ( $pre, $block ) use ( &$parts ) {
				if ( 'core/template-part' !== ( $block['blockName'] ?? '' ) ) {
					return $pre;
				}

				$parts[] = $block['attrs'];

				return '<' . $block['attrs']['tagName'] . '>PART ' . $block['attrs']['slug'] . '</' . $block['attrs']['tagName'] . '>';
			},
			10,
			2
		);

		$html = $this->printed( $this->gate() );

		$this->assertSame(
			array(
				array( 'slug' => 'header', 'theme' => 'test-theme', 'tagName' => 'header' ),
				array( 'slug' => 'footer', 'theme' => 'test-theme', 'tagName' => 'footer' ),
			),
			$parts
		);
		$this->assertSame( array( 'wp_head', 'wp_body_open', 'wp_footer' ), $GLOBALS['agend_test_theme_calls'], 'no classic header or footer in a block theme' );

		$order = array( '<!--wp_head-->', '<!--wp_body_open-->', '<header>PART header</header>', 'agend-access-placeholder', '<footer>PART footer</footer>', '<!--wp_footer-->' );
		$last  = -1;

		foreach ( $order as $needle ) {
			$at = strpos( $html, $needle );

			$this->assertNotFalse( $at, $needle . ' is printed' );
			$this->assertGreaterThan( $last, $at, $needle . ' is in its place' );

			$last = $at;
		}

		$this->assertStringContainsString( '<!DOCTYPE html>', $html );
		$this->assertStringContainsString( 'wp-site-blocks', $html );
		$this->assertStringNotContainsString( self::BODY, $html );
	}

	#[Test]
	public function the_denied_page_never_reads_the_page_s_body_to_print_itself(): void {
		$frontend = $this->gate();

		$GLOBALS['agend_test_posts'][ self::DENIED ]['post_content'] = 'A DIFFERENT BODY';

		$this->assertStringNotContainsString( 'A DIFFERENT BODY', $this->printed( $frontend ) );
	}

	// -----------------------------------------------------------------------
	// The body, for anything that still asks
	// -----------------------------------------------------------------------

	/**
	 * Beaver Builder renders the layout from its own meta on `the_content`, at 10 and
	 * 11, and ignores what the filters before it left. The swap at priority 1 was
	 * undone by it.
	 */
	#[Test]
	public function a_builder_that_renders_the_layout_on_the_content_filter_cannot_overwrite_the_placeholder(): void {
		$this->gate();

		add_filter( 'the_content', static fn( $content ) => 'BB LAYOUT: ' . self::BODY, 11 );

		$html = $this->body();

		$this->assertStringContainsString( 'agend-access-placeholder', $html );
		$this->assertStringNotContainsString( self::BODY, $html );
	}

	#[Test]
	public function the_body_is_replaced_at_the_start_and_re_asserted_at_the_end(): void {
		$this->gate();

		$registered = $this->registered( 'the_content' );

		$this->assertContains( 'filter_the_content@1', $registered );
		$this->assertContains( 'reassert_placeholder@' . PHP_INT_MAX, $registered );
	}

	#[Test]
	public function the_re_assert_is_for_the_denied_page_only(): void {
		$this->gate();

		// Another page rendered on the request, a widget's or a related list's.
		$GLOBALS['agend_test_in_the_loop'] = false;
		$GLOBALS['post']                   = get_post( self::PUBLIC );

		$this->assertSame( 'PUBLIC BODY', Agend_Test_WP::apply_registered( 'the_content', 'PUBLIC BODY' ) );
	}

	/** The denied page listed in a loop elsewhere on the request is the loop gate's, not the placeholder's. */
	#[Test]
	public function the_re_assert_leaves_a_page_the_loop_gate_withheld_to_the_loop_gate(): void {
		$this->gate();

		$GLOBALS['agend_test_in_the_loop']     = true;
		$GLOBALS['agend_test_not_main_query']  = true;
		$GLOBALS['post']                       = get_post( self::DENIED );

		$html = (string) Agend_Test_WP::apply_registered( 'the_content', self::BODY );

		$this->assertStringContainsString( 'agend-access-gate', $html );
		$this->assertStringNotContainsString( 'agend-access-placeholder', $html );
		$this->assertStringNotContainsString( self::BODY, $html );
	}

	#[Test]
	public function a_page_that_is_granted_is_left_alone_by_every_pass(): void {
		$this->asMember();

		$this->gate();

		$this->assertSame( 'PUBLIC BODY', $this->body( self::PUBLIC ) );
		$this->assertNotContains( 'reassert_placeholder@' . PHP_INT_MAX, $this->registered( 'the_content' ) );
	}

	// -----------------------------------------------------------------------
	// The feed
	// -----------------------------------------------------------------------

	#[Test]
	public function the_comment_feed_of_a_denied_page_is_refused_with_a_403(): void {
		$GLOBALS['agend_test_is_feed']         = true;
		$GLOBALS['agend_test_is_comment_feed'] = true;

		$frontend = new DeniedPageFrontend();

		Agend_Test_WP::$queried_object_id     = self::DENIED;
		$GLOBALS['agend_test_is_singular']    = true;
		$GLOBALS['agend_test_queried_object'] = get_post( self::DENIED );

		try {
			$frontend->gate_request();
			$this->fail( 'the feed was served' );
		} catch ( DeniedPageEnded $ended ) {
			$this->assertSame( 'ended', $ended->getMessage() );
		}

		$this->assertSame( array( 403 ), Agend_Test_WP::$status_codes );
		$this->assertSame( array( self::DENIED ), $this->uncacheable, 'a cached 403 would be served to the next reader, who may be allowed' );
		$this->assertContains( 'Cache-Control: private, no-store', DeniedPageFrontend::$headers );
		$this->assertSame( array(), $this->registered( 'template_include' ), 'refused, not served with a template' );
	}

	/** A redirect on a feed would send a reader to a page, and a feed reader is not a browser. */
	#[Test]
	public function a_page_that_redirects_still_refuses_its_feed(): void {
		$GLOBALS['agend_test_post_meta'][ self::DENIED ][ Rules::META_KEY ]['denied'] = array( 'action' => 'redirect', 'target_id' => self::PUBLIC, 'fallback_id' => 0 );
		$GLOBALS['agend_test_is_feed']                                                  = true;
		$GLOBALS['agend_test_is_comment_feed']                                          = true;

		$this->gate();

		$this->assertSame( array( 403 ), Agend_Test_WP::$status_codes );
		$this->assertSame( array(), Agend_Test_WP::$redirects );
	}

	#[Test]
	public function the_feed_of_a_page_the_reader_may_see_is_not_refused(): void {
		$GLOBALS['agend_test_is_feed']         = true;
		$GLOBALS['agend_test_is_comment_feed'] = true;

		// A page with no rules: nothing is refused.
		$this->gate( self::PUBLIC );

		$this->assertSame( array(), Agend_Test_WP::$status_codes );
	}
}
