<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Elementor as ElementorControl;
use Agend_Content_Access_Fragment_Rules;
use Agend_Content_Access_Frontend;
use Agend_Content_Access_Policy;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

require_once __DIR__ . '/has-elementor-double.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-policy.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-catalogue.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-meta-box.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-decision.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-fragment-rules.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-frontend.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-placeholder.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-destination.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-conditions.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-providers.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-runtime.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/interface-agend-content-access-document-parser.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-elementor-parser.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-elementor.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-block-attributes.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-blocks.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-viewer-facts.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-rules.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-access.php';

/**
 * Elementor's preview of a post is authoring for THAT post, and for nothing else.
 *
 * The carve-out used to cover the whole request. Anybody who can edit one post
 * could put a query loop, a related posts widget or a template shortcode in it
 * that lists restricted posts they cannot read, open its preview, and have every
 * gate stand down for the lot. The previewed post still renders in full, which is
 * the point of previewing it; every other post rendered during the request is
 * what a visitor to the same page would be shown, and is gated as theirs is.
 */
#[CoversClass( Agend_Content_Access_Frontend::class )]
#[CoversClass( ElementorControl::class )]
#[CoversClass( Agend_Content_Access_Fragment_Rules::class )]
final class PreviewCarveOutTest extends TestCase {

	private const OWN        = 42;
	private const RESTRICTED = 300;
	private const WITH_RULES = 301;
	private const TEMPLATE   = 302;

	private const BODY = 'THE PROTECTED BODY';

	private Agend_Content_Access_Frontend $frontend;

	/** @var object Preview double the suite installed, put back after each test. */
	private $original_preview;

	protected function setUp(): void {
		parent::setUp();

		$this->original_preview = \Elementor\Plugin::$instance->preview;

		Agend_Test_WP::$did_action['elementor/loaded'] = 1;

		// Elementor, which has checked the edit right, calls the author's own post
		// previewable. WordPress itself says they may edit nothing, so that nothing
		// but the carve-out can explain the previewed post rendering in full.
		\Elementor\Plugin::$instance->preview = new class() {
			public bool $is_preview_mode = false;

			public function is_preview_mode( $post_id = 0 ): bool {
				return isset( $_GET['elementor-preview'] )
					&& 42 === (int) $post_id
					&& 42 === (int) $_GET['elementor-preview'];
			}
		};

		$this->post( self::OWN, 'page', 'MY OWN BODY', array( 'mode' => 'active_member' ) );
		$this->post( self::RESTRICTED, 'post', self::BODY, array( 'mode' => 'active_member' ) );
		$this->post( self::WITH_RULES, 'post', 'OPENING AND SECTION SECRET', null );
		$GLOBALS['agend_test_post_meta'][ self::WITH_RULES ]['_elementor_data'] = $this->rules();

		$GLOBALS['agend_test_current_user_can']['edit_post'] = false;
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', '' );

		$this->frontend = new Agend_Content_Access_Frontend();
	}

	protected function tearDown(): void {
		unset(
			$_GET['elementor-preview'],
			$GLOBALS['agend_test_is_singular'],
			$GLOBALS['agend_test_queried_object']
		);

		\Elementor\Plugin::$instance->preview = $this->original_preview;

		parent::tearDown();
	}

	// -----------------------------------------------------------------------
	// Fixtures
	// -----------------------------------------------------------------------

	private function post( int $id, string $type, string $body, ?array $policy ): void {
		$GLOBALS['agend_test_posts'][ $id ] = array(
			'ID'           => $id,
			'post_status'  => 'publish',
			'post_type'    => $type,
			'post_title'   => 'Post ' . $id,
			'post_content' => $body,
			'post_excerpt' => '',
		);

		if ( null !== $policy ) {
			$GLOBALS['agend_test_post_meta'][ $id ][ Agend_Content_Access_Policy::META_KEY ] = $policy;
		}
	}

	private function rules(): string {
		return (string) wp_json_encode(
			array(
				array(
					'id'       => 'a',
					'elType'   => 'section',
					'settings' => array( 'agend_access_mode' => 'active_member' ),
					'elements' => array(),
				),
			)
		);
	}

	/** The author is previewing their own post: a request for it, flagged. */
	private function previewOwnPost(): void {
		Agend_Test_WP::$queried_object_id     = self::OWN;
		$GLOBALS['agend_test_is_singular']    = true;
		$GLOBALS['agend_test_queried_object'] = get_post( self::OWN );
		$_GET['elementor-preview']            = (string) self::OWN;
	}

	/** A visitor to the same page: no flag. */
	private function visitOwnPost(): void {
		$this->previewOwnPost();
		unset( $_GET['elementor-preview'] );
	}

	/** Runs `the_content` for a post, as a loop or a widget listing it would. */
	private function render( int $id ): string {
		$GLOBALS['post'] = get_post( $id );

		return (string) Agend_Test_WP::apply_registered( 'the_content', (string) get_post( $id )->post_content );
	}

	/** An Elementor element and the document the manager says is rendering it. */
	private function elementIn( ?int $document_id ): object {
		\Elementor\Plugin::$instance->documents = new class( $document_id ) {
			private ?int $id;

			public function __construct( ?int $id ) {
				$this->id = $id;
			}

			public function get_current(): ?object {
				if ( null === $this->id ) {
					return null;
				}

				return new class( $this->id ) {
					private int $id;

					public function __construct( int $id ) {
						$this->id = $id;
					}

					public function get_main_id(): int {
						return $this->id;
					}
				};
			}
		};

		return new class() {
			public function get_settings_for_display(): array {
				return array( ElementorControl::MODE_KEY => 'active_member' );
			}
		};
	}

	// -----------------------------------------------------------------------
	// A loop inside the previewed post
	// -----------------------------------------------------------------------

	#[Test]
	public function a_restricted_post_listed_inside_the_preview_is_gated(): void {
		$this->previewOwnPost();
		$this->frontend->gate_request();

		$html = $this->render( self::RESTRICTED );

		$this->assertStringContainsString( 'agend-access-gate', $html );
		$this->assertStringNotContainsString( self::BODY, $html );
	}

	#[Test]
	public function the_previewed_post_itself_still_renders_in_full(): void {
		$this->previewOwnPost();
		$this->frontend->gate_request();

		$this->assertSame( 'MY OWN BODY', $this->render( self::OWN ), 'a members-only post, which the author is previewing' );
	}

	/** The realistic case: WordPress agrees the author may edit their own post, and only that. */
	#[Test]
	public function an_author_who_can_edit_their_own_post_still_has_the_other_one_gated(): void {
		$GLOBALS['agend_test_current_user_can_for']['edit_post'][ self::OWN ] = true;
		$this->previewOwnPost();

		$this->assertSame( 'MY OWN BODY', $this->render( self::OWN ) );
		$this->assertStringNotContainsString( self::BODY, $this->render( self::RESTRICTED ) );
	}

	#[Test]
	public function a_builder_cannot_restore_a_restricted_post_listed_inside_the_preview(): void {
		$this->previewOwnPost();
		add_filter(
			'the_content',
			static function () {
				return 'THE BUILDER RENDERED BODY';
			},
			9999999
		);

		$html = $this->render( self::RESTRICTED );

		$this->assertStringNotContainsString( 'THE BUILDER RENDERED BODY', $html, 'the closing pass still has the last word' );
		$this->assertStringContainsString( 'agend-access-gate', $html );
	}

	#[Test]
	public function a_restricted_posts_builder_document_is_blanked_inside_the_preview(): void {
		$this->previewOwnPost();
		$GLOBALS['post'] = get_post( self::RESTRICTED );
		$this->frontend->withhold_in_loop( self::BODY );

		$this->assertSame( array(), $this->frontend->suppress_builder_data( 'kept', self::RESTRICTED, '_elementor_data', true ) );
	}

	// -----------------------------------------------------------------------
	// Excerpts
	// -----------------------------------------------------------------------

	#[Test]
	public function the_excerpt_of_another_post_is_gated_inside_the_preview(): void {
		$this->previewOwnPost();

		$this->assertSame( '', $this->frontend->filter_excerpt( self::BODY, get_post( self::RESTRICTED ) ) );
		$this->assertSame( 'MY OWN BODY', $this->frontend->filter_excerpt( 'MY OWN BODY', get_post( self::OWN ) ) );
	}

	#[Test]
	public function the_excerpt_of_another_post_that_carries_section_rules_is_empty_inside_the_preview(): void {
		$this->previewOwnPost();

		$this->assertSame( '', $this->frontend->filter_generated_excerpt( 'OPENING AND SECTION SECRET', get_post( self::WITH_RULES ) ) );
	}

	#[Test]
	public function the_previewed_post_keeps_its_own_excerpt_even_when_it_carries_section_rules(): void {
		$this->previewOwnPost();
		$GLOBALS['agend_test_post_meta'][ self::OWN ]['_elementor_data'] = $this->rules();

		$this->assertSame( 'MY OWN BODY', $this->frontend->filter_generated_excerpt( 'MY OWN BODY', get_post( self::OWN ) ) );
	}

	// -----------------------------------------------------------------------
	// The Latest Posts block lists posts of its own choosing
	// -----------------------------------------------------------------------

	#[Test]
	public function a_latest_posts_block_inside_the_preview_is_switched_to_excerpts(): void {
		$this->previewOwnPost();
		$block = array(
			'blockName' => 'core/latest-posts',
			'attrs'     => array( 'displayPostContent' => true, 'displayPostContentRadio' => 'full_post' ),
		);

		$filtered = Agend_Test_WP::apply_registered( 'render_block_data', $block, $block, null );

		$this->assertSame( 'excerpt', $filtered['attrs']['displayPostContentRadio'] );
	}

	// -----------------------------------------------------------------------
	// Elementor suppression
	// -----------------------------------------------------------------------

	#[Test]
	public function a_section_of_another_document_is_suppressed_inside_the_preview(): void {
		$this->previewOwnPost();

		$this->assertFalse( ( new ElementorControl() )->maybe_suppress( true, $this->elementIn( self::TEMPLATE ) ), 'a template the previewed post embeds' );
		$this->assertFalse( ( new ElementorControl() )->maybe_suppress( true, $this->elementIn( self::RESTRICTED ) ), 'a restricted post rendered by a widget' );
	}

	#[Test]
	public function a_section_of_the_previewed_document_is_not_suppressed(): void {
		$this->previewOwnPost();

		$this->assertTrue( ( new ElementorControl() )->maybe_suppress( true, $this->elementIn( self::OWN ) ) );
	}

	/** Fail closed: an element whose document Elementor does not name is not the previewed post's. */
	#[Test]
	public function a_section_whose_document_is_not_known_is_suppressed_inside_the_preview(): void {
		$this->previewOwnPost();

		$element = $this->elementIn( null );

		$this->assertFalse( ( new ElementorControl() )->maybe_suppress( true, $element ), 'a manager that names no current document' );

		\Elementor\Plugin::$instance->documents = null;

		$this->assertFalse( ( new ElementorControl() )->maybe_suppress( true, $element ), 'no manager at all' );
	}

	#[Test]
	public function the_replacement_content_of_another_document_follows_the_same_rule(): void {
		$this->previewOwnPost();
		$GLOBALS['agend_test_posts'][88] = array( 'ID' => 88, 'post_status' => 'publish', 'post_type' => 'page', 'post_content' => 'SIGN-IN-PROMPT' );
		$element                         = new class() {
			public function get_settings_for_display(): array {
				return array(
					ElementorControl::CONDITIONS_ENABLED_KEY  => 'yes',
					ElementorControl::CONDITIONS_KEY          => array( 'agend_wordpress:logged_in' ),
					ElementorControl::CONDITIONS_FALLBACK_KEY => '88',
				);
			}
		};

		$this->elementIn( self::OWN );
		ob_start();
		( new ElementorControl() )->maybe_render_fallback( $element );
		$own = (string) ob_get_clean();

		$this->elementIn( self::TEMPLATE );
		ob_start();
		( new ElementorControl() )->maybe_render_fallback( $element );
		$other = (string) ob_get_clean();

		$this->assertSame( '', $own, 'the author sees the page as they are building it' );
		$this->assertStringContainsString( 'SIGN-IN-PROMPT', $other );
	}

	// -----------------------------------------------------------------------
	// Where the carve-out still covers everything
	// -----------------------------------------------------------------------

	/** Every post the user may edit: an editor, who may edit them all. See `AuthoringPerPostTest` for the user who may not. */
	#[Test]
	public function elementors_editor_is_authoring_for_every_post_the_user_may_edit(): void {
		$GLOBALS['agend_test_current_user_can']['edit_post'] = true;
		$this->visitOwnPost();
		\Elementor\Plugin::$instance->editor->is_edit_mode = true;

		$this->assertStringNotContainsString( 'agend-access-gate', $this->render( self::RESTRICTED ) );
		$this->assertTrue( ( new ElementorControl() )->maybe_suppress( true, $this->elementIn( self::TEMPLATE ) ) );
		$this->assertSame( self::BODY, $this->frontend->filter_excerpt( self::BODY, get_post( self::RESTRICTED ) ) );
	}

	#[Test]
	public function a_wp_admin_screen_is_authoring_for_every_post_the_user_may_edit(): void {
		$GLOBALS['agend_test_current_user_can']['edit_post'] = true;
		$GLOBALS['agend_test_is_admin']                      = true;

		$this->assertSame( self::BODY, $this->render( self::RESTRICTED ) );
		$this->assertTrue( Agend_Content_Access_Frontend::is_authoring_for_post( 0 ) );
	}

	/** The control: with no flag the same page gates both posts, the author's own included. */
	#[Test]
	public function without_the_preview_flag_every_post_is_gated(): void {
		$this->visitOwnPost();

		$this->assertStringContainsString( 'agend-access-gate', $this->render( self::RESTRICTED ) );
		$this->assertStringContainsString( 'agend-access-gate', $this->render( self::OWN ) );
	}

	// -----------------------------------------------------------------------
	// The question itself
	// -----------------------------------------------------------------------

	#[Test]
	public function the_preview_is_authoring_for_the_previewed_post_only(): void {
		$this->previewOwnPost();

		$this->assertTrue( Agend_Content_Access_Frontend::is_authoring_context(), 'the request as a whole, which is what the page gate asks' );
		$this->assertTrue( Agend_Content_Access_Frontend::is_authoring_for_post( self::OWN ) );
		$this->assertFalse( Agend_Content_Access_Frontend::is_authoring_for_post( self::RESTRICTED ) );
		$this->assertFalse( Agend_Content_Access_Frontend::is_authoring_for_post( 0 ), 'an unknown post is never the previewed one' );

		$this->assertFalse( Agend_Content_Access_Frontend::renders_for_visitors( self::OWN ) );
		$this->assertTrue( Agend_Content_Access_Frontend::renders_for_visitors( self::RESTRICTED ) );
		$this->assertTrue( Agend_Content_Access_Frontend::renders_for_visitors() );
	}

	#[Test]
	public function a_preview_flag_on_another_page_is_authoring_for_nothing(): void {
		Agend_Test_WP::$queried_object_id = self::RESTRICTED;
		$_GET['elementor-preview']        = (string) self::OWN;

		$this->assertFalse( Agend_Content_Access_Frontend::is_authoring_for_post( self::OWN ) );
		$this->assertFalse( Agend_Content_Access_Frontend::is_authoring_for_post( self::RESTRICTED ) );
	}
}
