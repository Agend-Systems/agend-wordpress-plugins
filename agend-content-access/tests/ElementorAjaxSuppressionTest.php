<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Elementor as ElementorControl;
use Agend_Content_Access_Frontend;
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
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-conditions.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-providers.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-runtime.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-elementor.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-block-attributes.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-viewer-facts.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-rules.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-access.php';

/**
 * Section suppression on admin-ajax.php.
 *
 * WordPress reports `is_admin()` for admin-ajax.php, and the Elementor driver
 * used to treat every `is_admin()` request as authoring, so suppression stood
 * down for it. JetEngine, Ajax Load More and similar render Elementor templates
 * through admin-ajax.php for ANONYMOUS visitors ("load more", filters, tabs), so
 * every restricted section in those templates was printed. A request is
 * authoring only when somebody is authoring: a wp-admin screen, the editor, its
 * preview, or the editor's own AJAX.
 */
#[CoversClass( ElementorControl::class )]
#[CoversClass( Agend_Content_Access_Frontend::class )]
final class ElementorAjaxSuppressionTest extends TestCase {

	private const FALLBACK_ID = 88;

	/** @var object Preview double the suite installed, put back after each test. */
	private $original_preview;

	protected function setUp(): void {
		parent::setUp();

		$this->original_preview = \Elementor\Plugin::$instance->preview;

		Agend_Test_WP::$did_action['elementor/loaded'] = 1;
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', '' );

		$GLOBALS['agend_test_posts'][ self::FALLBACK_ID ] = array(
			'ID'           => self::FALLBACK_ID,
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_title'   => 'Sign in prompt',
			'post_content' => 'SIGN-IN-PROMPT-CONTENT',
		);
	}

	protected function tearDown(): void {
		unset( $_GET['elementor-preview'] );

		\Elementor\Plugin::$instance->preview = $this->original_preview;

		parent::tearDown();
	}

	// -----------------------------------------------------------------------
	// Fixtures
	// -----------------------------------------------------------------------

	/** An element, and a record of the render attributes it was given. */
	private function element( array $settings ): object {
		return new class( $settings ) {
			/** @var array<string, mixed> */
			private array $settings;

			/** @var array<string, array<string, string>> */
			public array $attributes = array();

			public function __construct( array $settings ) {
				$this->settings = $settings;
			}

			public function get_settings_for_display(): array {
				return $this->settings;
			}

			public function add_render_attribute( string $element, array $attributes ): void {
				$this->attributes[ $element ] = $attributes;
			}
		};
	}

	private function restricted(): object {
		return $this->element( array( ElementorControl::MODE_KEY => 'active_member' ) );
	}

	/** Conditions the anonymous visitor fails, with a public page to show instead. */
	private function conditioned(): object {
		return $this->element(
			array(
				ElementorControl::CONDITIONS_ENABLED_KEY  => 'yes',
				ElementorControl::CONDITIONS_KEY          => array( 'agend_wordpress:logged_in' ),
				ElementorControl::CONDITIONS_MATCH_KEY    => 'any',
				ElementorControl::CONDITIONS_FALLBACK_KEY => (string) self::FALLBACK_ID,
			)
		);
	}

	/** admin-ajax.php, as a visitor reaches it. */
	private function adminAjax(): void {
		$GLOBALS['agend_test_is_admin']   = true;
		$GLOBALS['agend_test_doing_ajax'] = true;
	}

	/**
	 * admin-ajax.php, as Elementor's own editor reaches it, rendering a document
	 * the user may edit. Which document it is rendering is the question of the
	 * per document tests below.
	 */
	private function elementorEditorAjax( int $document_id = 42 ): void {
		$this->adminAjax();
		\Elementor\Plugin::$instance->editor->is_edit_mode = true;

		$GLOBALS['agend_test_current_user_can_for']['edit_post'][ $document_id ] = true;

		$this->documentRendering( $document_id );
	}

	/** The document the manager says is rendering the element, as Elementor's stack names it. */
	private function documentRendering( ?int $document_id ): void {
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
	}

	private function fallbackOutput( object $element ): string {
		ob_start();
		( new ElementorControl() )->maybe_render_fallback( $element );

		return (string) ob_get_clean();
	}

	// -----------------------------------------------------------------------
	// An anonymous admin-ajax render
	// -----------------------------------------------------------------------

	#[Test]
	public function a_restricted_section_is_suppressed_on_anonymous_admin_ajax(): void {
		$this->adminAjax();

		$this->assertFalse( ( new ElementorControl() )->maybe_suppress( true, $this->restricted() ) );
	}

	#[Test]
	public function a_section_whose_display_conditions_fail_is_suppressed_on_anonymous_admin_ajax(): void {
		$this->adminAjax();

		$this->assertFalse( ( new ElementorControl() )->maybe_suppress( true, $this->conditioned() ) );
	}

	#[Test]
	public function the_replacement_content_renders_on_anonymous_admin_ajax(): void {
		$this->adminAjax();

		$this->assertStringContainsString( 'SIGN-IN-PROMPT-CONTENT', $this->fallbackOutput( $this->conditioned() ) );
	}

	#[Test]
	public function an_element_with_no_rules_is_untouched_on_admin_ajax(): void {
		$this->adminAjax();

		$this->assertTrue( ( new ElementorControl() )->maybe_suppress( true, $this->element( array() ) ) );
	}

	#[Test]
	public function a_member_who_qualifies_still_sees_the_section_on_admin_ajax(): void {
		$this->adminAjax();
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'member-token' );
		Agend_Test_WP::set_filter(
			'agend_apps_crm_get_my_entitlements_response',
			array( 'data' => array( 'is_member' => true, 'tier_ids' => array( '11111111-1111-1111-1111-111111111111' ) ) )
		);

		$this->assertTrue( ( new ElementorControl() )->maybe_suppress( true, $this->restricted() ) );
	}

	#[Test]
	public function a_restricted_section_is_not_marked_as_one_on_anonymous_admin_ajax(): void {
		$this->adminAjax();
		$element = $this->restricted();

		( new ElementorControl() )->maybe_mark_in_editor( $element );

		$this->assertSame( array(), $element->attributes, 'the editor markers say which sections carry a policy, which a visitor has no use for' );
	}

	#[Test]
	public function elementor_being_absent_is_not_an_editor(): void {
		unset( Agend_Test_WP::$did_action['elementor/loaded'] );
		$this->adminAjax();

		$this->assertFalse( Agend_Content_Access_Frontend::is_elementor_editor_ajax() );
		$this->assertFalse( ( new ElementorControl() )->maybe_suppress( true, $this->restricted() ) );
	}

	// -----------------------------------------------------------------------
	// Elementor's own editor
	// -----------------------------------------------------------------------

	#[Test]
	public function the_editors_own_ajax_is_not_suppressed(): void {
		$this->elementorEditorAjax();

		$this->assertTrue( ( new ElementorControl() )->maybe_suppress( true, $this->restricted() ) );
		$this->assertTrue( ( new ElementorControl() )->maybe_suppress( true, $this->conditioned() ) );
	}

	#[Test]
	public function the_editors_own_ajax_renders_no_replacement_content(): void {
		$this->elementorEditorAjax();

		$this->assertSame( '', $this->fallbackOutput( $this->conditioned() ) );
	}

	#[Test]
	public function the_editors_own_ajax_still_marks_restricted_sections(): void {
		$this->elementorEditorAjax();
		$element = $this->restricted();

		( new ElementorControl() )->maybe_mark_in_editor( $element );

		$this->assertSame( 'active_member', $element->attributes['_wrapper']['data-agend-access-mode'] );
	}

	#[Test]
	public function the_editor_marks_an_element_that_carries_new_rules(): void {
		$this->elementorEditorAjax();
		$element = $this->element( array( ElementorControl::RULES_KEY => array( 'agend_membership:is_member' ) ) );

		( new ElementorControl() )->maybe_mark_in_editor( $element );

		$this->assertSame( 'agend-access-restricted', $element->attributes['_wrapper']['class'] );
		$this->assertSame( 'active_member', $element->attributes['_wrapper']['data-agend-access-mode'], 'the audience the rules say, when a policy says it' );
		$this->assertArrayNotHasKey( 'data-agend-access-older', $element->attributes['_wrapper'] );
	}

	/** A list no policy can say (a role, a reverse, all of) is marked as plain rules. */
	#[Test]
	public function the_editor_marks_rules_no_policy_can_say_as_rules(): void {
		$this->elementorEditorAjax();
		$element = $this->element(
			array(
				ElementorControl::RULES_KEY  => array( 'agend_wordpress:role_editor' ),
				ElementorControl::INVERT_KEY => 'yes',
			)
		);

		( new ElementorControl() )->maybe_mark_in_editor( $element );

		$this->assertSame( 'rules', $element->attributes['_wrapper']['data-agend-access-mode'] );
	}

	/** The element that still holds an older setting says so, alone or beside new rules. */
	#[Test]
	public function the_editor_marks_an_element_that_still_holds_an_older_setting(): void {
		$this->elementorEditorAjax();

		foreach ( array(
			'an older audience'          => array( ElementorControl::MODE_KEY => 'active_member' ),
			'older display conditions'   => array( ElementorControl::CONDITIONS_ENABLED_KEY => 'yes', ElementorControl::CONDITIONS_KEY => array( 'agend_wordpress:logged_in' ) ),
			'an older audience and rules' => array( ElementorControl::MODE_KEY => 'active_member', ElementorControl::RULES_KEY => array( 'agend_wordpress:logged_in' ) ),
		) as $case => $settings ) {
			$element = $this->element( $settings );

			( new ElementorControl() )->maybe_mark_in_editor( $element );

			$this->assertSame( 'agend-access-restricted', $element->attributes['_wrapper']['class'], $case );
			$this->assertSame( 'yes', $element->attributes['_wrapper']['data-agend-access-older'], $case );
		}
	}

	#[Test]
	public function the_editor_marks_nothing_on_an_element_with_no_rules(): void {
		$this->elementorEditorAjax();

		foreach ( array(
			array(),
			array( ElementorControl::RULES_KEY => array(), ElementorControl::MATCH_KEY => 'any', ElementorControl::INVERT_KEY => '' ),
			array( ElementorControl::MODE_KEY => 'inherit', ElementorControl::CONDITIONS_ENABLED_KEY => '' ),
		) as $settings ) {
			$element = $this->element( $settings );

			( new ElementorControl() )->maybe_mark_in_editor( $element );

			$this->assertSame( array(), $element->attributes, (string) wp_json_encode( $settings ) );
		}
	}

	/** The editor's flag is only trusted on an AJAX request: a page load is decided as a page load. */
	#[Test]
	public function edit_mode_alone_is_not_an_editor_ajax_request(): void {
		\Elementor\Plugin::$instance->editor->is_edit_mode = true;

		$this->assertFalse( Agend_Content_Access_Frontend::is_elementor_editor_ajax() );
	}

	// -----------------------------------------------------------------------
	// The editor's AJAX is authoring for the document being rendered
	// -----------------------------------------------------------------------

	/**
	 * Elementor's edit mode holds for the whole editor request once the user may
	 * edit the one document the editor names, and the request can render others.
	 * A contributor's editor, open on their own draft, rendering a Loop Grid or a
	 * Template widget that pulls in a restricted document, is a visitor's render of
	 * that document.
	 */
	private function contributorEditingOwnDraft( int $rendering ): void {
		$GLOBALS['agend_test_current_user_can']['edit_post'] = false;

		$this->elementorEditorAjax( 42 );
		$this->documentRendering( $rendering );
	}

	#[Test]
	public function the_editor_ajax_of_a_contributor_does_not_stand_suppression_down_for_another_document(): void {
		$this->contributorEditingOwnDraft( 300 );

		$this->assertFalse( ( new ElementorControl() )->maybe_suppress( true, $this->restricted() ) );
		$this->assertFalse( ( new ElementorControl() )->maybe_suppress( true, $this->conditioned() ) );
		$this->assertStringContainsString( 'SIGN-IN-PROMPT-CONTENT', $this->fallbackOutput( $this->conditioned() ) );
	}

	#[Test]
	public function the_editor_ajax_of_a_contributor_does_not_mark_sections_of_another_document(): void {
		$this->contributorEditingOwnDraft( 300 );
		$element = $this->restricted();

		( new ElementorControl() )->maybe_mark_in_editor( $element );

		$this->assertSame( array(), $element->attributes );
	}

	/** The control: the same contributor, the same request, the document they are editing. */
	#[Test]
	public function the_editor_ajax_of_a_contributor_is_authoring_for_their_own_document(): void {
		$this->contributorEditingOwnDraft( 42 );

		$this->assertTrue( ( new ElementorControl() )->maybe_suppress( true, $this->restricted() ) );
		$this->assertTrue( ( new ElementorControl() )->maybe_suppress( true, $this->conditioned() ) );
		$this->assertSame( '', $this->fallbackOutput( $this->conditioned() ) );
	}

	/** An editor may edit every document, so the template their widget pulls in is theirs to see. */
	#[Test]
	public function the_editor_ajax_of_somebody_who_may_edit_the_other_document_is_authoring_for_it(): void {
		$this->elementorEditorAjax( 42 );
		$GLOBALS['agend_test_current_user_can_for']['edit_post'][300] = true;
		$this->documentRendering( 300 );

		$this->assertTrue( ( new ElementorControl() )->maybe_suppress( true, $this->restricted() ) );
	}

	/** A document that cannot be named is one the rule cannot be applied to: it is a visitor's. */
	#[Test]
	public function the_editor_ajax_is_a_visitors_when_the_document_cannot_be_identified(): void {
		$this->elementorEditorAjax( 42 );
		$this->documentRendering( null );

		$this->assertFalse( ( new ElementorControl() )->maybe_suppress( true, $this->restricted() ) );
		$this->assertStringContainsString( 'SIGN-IN-PROMPT-CONTENT', $this->fallbackOutput( $this->conditioned() ) );

		// Nor does Elementor name one when its document manager is absent altogether.
		\Elementor\Plugin::$instance->documents = null;

		$this->assertFalse( ( new ElementorControl() )->maybe_suppress( true, $this->restricted() ) );
	}

	#[Test]
	public function the_editor_ajax_is_a_visitors_when_the_document_has_no_id(): void {
		$this->elementorEditorAjax( 42 );
		$this->documentRendering( 0 );

		$this->assertFalse( ( new ElementorControl() )->maybe_suppress( true, $this->restricted() ) );
	}

	// -----------------------------------------------------------------------
	// The other authoring contexts are unchanged
	// -----------------------------------------------------------------------

	#[Test]
	public function a_wp_admin_screen_is_not_suppressed(): void {
		$GLOBALS['agend_test_is_admin'] = true;

		$this->assertTrue( ( new ElementorControl() )->maybe_suppress( true, $this->restricted() ) );
		$this->assertSame( '', $this->fallbackOutput( $this->conditioned() ) );
	}

	#[Test]
	public function the_editor_window_is_not_suppressed(): void {
		\Elementor\Plugin::$instance->editor->is_edit_mode = true;

		$this->assertTrue( ( new ElementorControl() )->maybe_suppress( true, $this->restricted() ) );
	}

	#[Test]
	public function a_real_preview_of_the_page_being_edited_is_not_suppressed(): void {
		$GLOBALS['agend_test_posts'][42]  = array( 'ID' => 42, 'post_status' => 'draft', 'post_type' => 'page' );
		Agend_Test_WP::$queried_object_id = 42;
		$_GET['elementor-preview']        = '42';

		\Elementor\Plugin::$instance->preview = new class() {
			public bool $is_preview_mode = false;

			public function is_preview_mode( $post_id = 0 ): bool {
				return 42 === (int) $post_id;
			}
		};

		// The document the element belongs to is the one being previewed.
		\Elementor\Plugin::$instance->documents = new class() {
			public function get_current(): object {
				return new class() {
					public function get_main_id(): int {
						return 42;
					}
				};
			}
		};

		$this->assertTrue( ( new ElementorControl() )->maybe_suppress( true, $this->restricted() ) );
	}

	#[Test]
	public function an_ordinary_frontend_visit_is_suppressed(): void {
		$this->assertFalse( ( new ElementorControl() )->maybe_suppress( true, $this->restricted() ) );
		$this->assertStringContainsString( 'SIGN-IN-PROMPT-CONTENT', $this->fallbackOutput( $this->conditioned() ) );
	}

	#[Test]
	public function an_element_already_hidden_stays_hidden(): void {
		$this->assertFalse( ( new ElementorControl() )->maybe_suppress( false, $this->element( array() ) ) );
	}
}
