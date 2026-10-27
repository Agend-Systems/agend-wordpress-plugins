<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Frontend;
use Agend_Content_Access_Policy;
use Agend_Test_WP;
use Agend_Test_WP_Die_Exception;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

require_once __DIR__ . '/has-elementor-double.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-policy.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-catalogue.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-meta-box.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-decision.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-frontend.php';

/**
 * Elementor's `is_preview_mode()` as it behaves in a real install.
 *
 * Reproduced rather than approximated, because the defect is in what it does NOT
 * check: it asks whether the user may edit the id it was given, then compares
 * that id with the same query-string value it was handed, so it is true for any
 * id the user can edit, on any URL. The shared double ignores its argument, which
 * is why it could never show this.
 */
final class RealisticElementorPreview {

	/** @var bool Present so the suite's per-test reset can write to it. */
	public bool $is_preview_mode = false;

	/** @var int[] Posts the current user may edit. */
	public array $editable = array();

	public function is_preview_mode( $post_id = 0 ): bool {
		if ( ! isset( $_GET['elementor-preview'] ) ) {
			return false;
		}

		if ( empty( $post_id ) ) {
			$post_id = (int) get_the_ID();
		}

		if ( ! in_array( (int) $post_id, $this->editable, true ) ) {
			return false;
		}

		return (int) $post_id === (int) $_GET['elementor-preview'];
	}
}

/**
 * The preview flag must not turn gating off on a page it does not name.
 *
 * `?elementor-preview=<id>` is a query-string value anybody can append to any
 * URL. A contributor can edit their own post, so they hold a valid id, and 0.1.x
 * accepted it on every page: the page gate, the loop and excerpt gates and
 * Elementor's section suppression all stood down for whatever restricted page
 * they appended it to.
 */
#[CoversClass( Agend_Content_Access_Frontend::class )]
final class AuthoringContextTest extends TestCase {

	private const OWN_DRAFT  = 42;
	private const RESTRICTED = 300;

	/** @var object Preview double the suite installed, put back after each test. */
	private $original_preview;

	protected function setUp(): void {
		parent::setUp();

		$this->original_preview = \Elementor\Plugin::$instance->preview;

		Agend_Test_WP::$did_action['elementor/loaded'] = 1;

		$preview           = new RealisticElementorPreview();
		$preview->editable = array( self::OWN_DRAFT );

		\Elementor\Plugin::$instance->preview = $preview;

		foreach ( array( self::OWN_DRAFT, self::RESTRICTED ) as $id ) {
			$GLOBALS['agend_test_posts'][ $id ] = array(
				'ID'           => $id,
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_title'   => 'Page ' . $id,
				'post_content' => 'THE PROTECTED BODY',
			);
		}

		$GLOBALS['agend_test_post_meta'][ self::RESTRICTED ] = array(
			Agend_Content_Access_Policy::META_KEY => array( 'mode' => 'active_member' ),
		);

		// A contributor: may edit posts in general and their own draft, not this one.
		$GLOBALS['agend_test_current_user_can']['edit_posts'] = true;
		$GLOBALS['agend_test_current_user_can']['edit_post']  = false;

		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', '' );
	}

	protected function tearDown(): void {
		unset(
			$_GET['elementor-preview'],
			$GLOBALS['wp_the_query'],
			$GLOBALS['agend_test_is_singular'],
			$GLOBALS['agend_test_queried_object']
		);

		\Elementor\Plugin::$instance->preview = $this->original_preview;

		parent::tearDown();
	}

	/** The request is for this page, singular, the way template_redirect sees it. */
	private function requestPage( int $id ): void {
		Agend_Test_WP::$queried_object_id     = $id;
		$GLOBALS['agend_test_is_singular']    = true;
		$GLOBALS['agend_test_queried_object'] = get_post( $id );
	}

	private function preview( $value ): void {
		$_GET['elementor-preview'] = $value;
	}

	/** Stands in for `$wp_the_query`, the request's own query. */
	private function mainQuery( int $queried_id ): object {
		return new class( $queried_id ) {
			private int $queried_id;

			public function __construct( int $queried_id ) {
				$this->queried_id = $queried_id;
			}

			public function get_queried_object_id(): int {
				return $this->queried_id;
			}
		};
	}

	// -----------------------------------------------------------------------
	// The bypass
	// -----------------------------------------------------------------------

	/**
	 * Ported from the reviewer's scratch test, with the assertion flipped: that
	 * test proved the contributor WAS treated as authoring.
	 */
	#[Test]
	public function a_preview_id_for_another_post_is_not_authoring(): void {
		$this->requestPage( self::RESTRICTED );
		$this->preview( (string) self::OWN_DRAFT );

		$this->assertFalse( Agend_Content_Access_Frontend::is_authoring_context() );
	}

	#[Test]
	public function the_restricted_page_is_still_denied_to_a_contributor_who_appends_their_own_id(): void {
		$frontend = new Agend_Content_Access_Frontend();
		$this->requestPage( self::RESTRICTED );
		$this->preview( (string) self::OWN_DRAFT );

		$this->expectException( Agend_Test_WP_Die_Exception::class );

		$frontend->gate_request();
	}

	#[Test]
	public function a_restricted_post_in_a_loop_is_still_gated_when_the_contributor_appends_their_own_id(): void {
		$frontend = new Agend_Content_Access_Frontend();
		$this->requestPage( self::RESTRICTED );
		$this->preview( (string) self::OWN_DRAFT );
		$GLOBALS['post'] = get_post( self::RESTRICTED );

		$html = $frontend->withhold_in_loop( 'THE PROTECTED BODY' );

		$this->assertStringContainsString( 'agend-access-gate', $html );
		$this->assertStringNotContainsString( 'THE PROTECTED BODY', $html );
		$this->assertSame( '', $frontend->filter_excerpt( 'THE PROTECTED BODY', get_post( self::RESTRICTED ) ) );
	}

	#[Test]
	public function the_real_preview_of_the_authors_own_post_is_still_authoring(): void {
		$this->requestPage( self::OWN_DRAFT );
		$this->preview( (string) self::OWN_DRAFT );

		$this->assertTrue( Agend_Content_Access_Frontend::is_authoring_context() );
	}

	#[Test]
	public function a_real_preview_still_lets_the_author_see_the_page_they_are_editing(): void {
		$frontend = new Agend_Content_Access_Frontend();

		$GLOBALS['agend_test_post_meta'][ self::OWN_DRAFT ] = array(
			Agend_Content_Access_Policy::META_KEY => array( 'mode' => 'active_member' ),
		);

		$this->requestPage( self::OWN_DRAFT );
		$this->preview( (string) self::OWN_DRAFT );

		// Returns without gating, so nothing is thrown and no teaser filter is armed.
		$frontend->gate_request();

		$this->assertArrayNotHasKey( 'get_post_metadata', Agend_Test_WP::$filters );
	}

	// -----------------------------------------------------------------------
	// What counts as a preview id
	// -----------------------------------------------------------------------

	/** @return array<string, array{0: mixed}> */
	public static function notAPreviewId(): array {
		return array(
			'zero'                        => array( '0' ),
			'empty'                       => array( '' ),
			'negative, which absint flips' => array( '-' . self::OWN_DRAFT ),
			'not a number'                => array( 'abc' ),
		);
	}

	#[Test]
	#[DataProvider( 'notAPreviewId' )]
	public function a_value_that_is_not_a_positive_integer_id_is_not_a_preview( $value ): void {
		// The page the request serves is one the preview double would otherwise accept.
		$this->requestPage( self::OWN_DRAFT );
		$this->preview( $value );

		$this->assertFalse( Agend_Content_Access_Frontend::is_authoring_context() );
	}

	/**
	 * `absint()` turns any non-empty array into 1, so an array would otherwise
	 * read as the id of post 1.
	 */
	#[Test]
	public function an_array_is_not_a_preview_id_even_when_it_would_read_as_one(): void {
		\Elementor\Plugin::$instance->preview->editable = array( 1 );
		Agend_Test_WP::$queried_object_id               = 1;
		$this->preview( array( 'x' ) );

		$this->assertFalse( Agend_Content_Access_Frontend::is_authoring_context() );
	}

	#[Test]
	public function a_request_that_is_not_for_a_single_post_is_never_a_preview(): void {
		Agend_Test_WP::$queried_object_id = 0;
		$this->preview( (string) self::OWN_DRAFT );

		$this->assertFalse( Agend_Content_Access_Frontend::is_authoring_context() );
	}

	#[Test]
	public function a_preview_id_the_user_cannot_edit_is_not_authoring_even_on_its_own_page(): void {
		$this->requestPage( self::RESTRICTED );
		$this->preview( (string) self::RESTRICTED );

		$this->assertFalse( Agend_Content_Access_Frontend::is_authoring_context(), 'Elementor still has to confirm the edit right' );
	}

	// -----------------------------------------------------------------------
	// Which query the page is read from
	// -----------------------------------------------------------------------

	/**
	 * Elementor switches the global query while rendering dynamic content in a
	 * preview. The page being previewed is still the one the URL names.
	 */
	#[Test]
	public function the_page_is_read_from_the_main_query_not_a_switched_one(): void {
		$GLOBALS['wp_the_query'] = $this->mainQuery( self::OWN_DRAFT );

		Agend_Test_WP::$queried_object_id = self::RESTRICTED;
		$this->preview( (string) self::OWN_DRAFT );

		$this->assertTrue( Agend_Content_Access_Frontend::is_authoring_context() );
	}

	#[Test]
	public function a_switched_query_cannot_make_another_page_a_preview(): void {
		$GLOBALS['wp_the_query'] = $this->mainQuery( self::RESTRICTED );

		// The global query was switched to the contributor's own post, which is
		// what a page that runs a loop over it would leave behind.
		Agend_Test_WP::$queried_object_id = self::OWN_DRAFT;
		$this->preview( (string) self::OWN_DRAFT );

		$this->assertFalse( Agend_Content_Access_Frontend::is_authoring_context() );
	}

	// -----------------------------------------------------------------------
	// The other ways in are unchanged
	// -----------------------------------------------------------------------

	#[Test]
	public function the_editor_itself_is_still_authoring(): void {
		\Elementor\Plugin::$instance->editor->is_edit_mode = true;

		$this->assertTrue( Agend_Content_Access_Frontend::is_authoring_context() );
	}

	#[Test]
	public function a_wp_admin_screen_is_still_authoring(): void {
		$GLOBALS['agend_test_is_admin'] = true;

		$this->assertTrue( Agend_Content_Access_Frontend::is_authoring_context() );
	}

	#[Test]
	public function without_elementor_a_preview_argument_means_nothing(): void {
		unset( Agend_Test_WP::$did_action['elementor/loaded'] );
		$this->requestPage( self::OWN_DRAFT );
		$this->preview( (string) self::OWN_DRAFT );

		$this->assertFalse( Agend_Content_Access_Frontend::is_authoring_context() );
	}
}
