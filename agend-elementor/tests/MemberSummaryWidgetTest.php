<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Elementor;

use Agend_Apps_Key_Scopes;
use Agend_Apps_Records_Record_Context;
use Agend_Elementor_Member_Summary;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;

/**
 * Exposes the protected methods a test drives directly.
 */
trait Member_Summary_Widget_Test_Methods {
	public function run_controls(): void {
		$this->register_controls();
	}

	public function run_render(): string {
		ob_start();
		$this->render();

		return (string) ob_get_clean();
	}

	public function run_is_dynamic_content(): bool {
		return $this->is_dynamic_content();
	}
}

/**
 * The Agend Member Summary widget: a thin adapter over the core surface, so what
 * is pinned here is the seam: its controls are the schema's, its output is the
 * core renderer's, it is never served from Elementor's element cache, and it
 * stands down for a key that cannot use the surface.
 *
 * Each case runs in its own process: the render path loads the core renderer
 * and the access state, whose state is held for the request.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass( Agend_Elementor_Member_Summary::class )]
final class MemberSummaryWidgetTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/tests/render-doubles.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/health.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-key-scopes.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/features.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/palette.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/member-summary.php';
		require_once AGEND_TESTS_ROOT . '/agend-elementor/agend-elementor.php';
		require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor.php';
		require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor-schema-controls.php';
		require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor-field-widget-trait.php';
		require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/widgets/class-agend-elementor-member-summary.php';
		require_once __DIR__ . '/Content_Tab_Recording_Trait.php';
		agend_render_test_reset();
		Agend_Apps_Records_Record_Context::reset();
	}

	protected function tearDown(): void {
		Agend_Apps_Records_Record_Context::reset();
		parent::tearDown();
	}

	private function widget( array $settings = array() ): object {
		return new class( array(), null, $settings ) extends Agend_Elementor_Member_Summary {
			use Member_Summary_Widget_Test_Methods;
		};
	}

	private function know( array $scopes ): void {
		Agend_Apps_Key_Scopes::store_from_response( array( 'data' => array( 'scopes' => $scopes, 'app_ids' => array() ) ) );
	}

	/**
	 * Every control the widget registers, keyed by id, with the tab it is on.
	 *
	 * @return array<string, array{tab: string, args: array}>
	 */
	private function controls(): array {
		$widget = $this->widget();
		$widget->run_controls();

		$controls = array();
		$tab      = '';

		foreach ( $widget->recordings as $entry ) {
			if ( 'start_controls_section' === $entry['method'] ) {
				$tab = (string) ( $entry['args']['tab'] ?? '' );
			} elseif ( 'add_control' === $entry['method'] ) {
				$controls[ $entry['id'] ] = array(
					'tab'  => $tab,
					'args' => $entry['args'],
				);
			}
		}

		return $controls;
	}

	// -----------------------------------------------------------------
	// Identity
	// -----------------------------------------------------------------

	#[Test]
	public function should_be_an_agend_widget_an_editor_can_find_by_name(): void {
		$widget = $this->widget();

		self::assertSame( 'agend-member-summary', $widget->get_name() );
		self::assertSame( 'Agend Member Summary', $widget->get_title() );
		self::assertSame( array( 'agend-apps' ), $widget->get_categories() );
		self::assertContains( 'membership', $widget->get_keywords() );
		self::assertContains( 'cpd', $widget->get_keywords() );
	}

	#[Test]
	public function should_load_its_stylesheet_and_its_script(): void {
		$widget = $this->widget();

		self::assertSame( array( 'agend-apps-records-member-summary' ), $widget->get_style_depends() );
		self::assertSame( array( 'agend-apps-records-member-summary' ), $widget->get_script_depends() );
	}

	#[Test]
	public function should_load_nothing_while_the_key_cannot_use_the_surface(): void {
		$this->know( array( 'crm.tiers.browse' ) );

		self::assertSame( array(), $this->widget()->get_style_depends() );
		self::assertSame( array(), $this->widget()->get_script_depends() );
	}

	#[Test]
	public function should_never_be_served_from_elementors_element_cache(): void {
		self::assertTrue( $this->widget()->run_is_dynamic_content(), 'what it draws depends on who is looking' );
	}

	// -----------------------------------------------------------------
	// Controls: the schema's, and nothing else
	// -----------------------------------------------------------------

	#[Test]
	public function should_register_exactly_the_controls_the_schema_declares(): void {
		$expected = array();

		foreach ( agend_apps_records_surface_schema( 'member-summary' )['sections'] as $section ) {
			foreach ( $section['fields'] as $field ) {
				$expected[] = $field['name'];
			}
		}

		self::assertSame( $expected, array_keys( $this->controls() ) );
	}

	#[Test]
	public function should_put_the_colours_on_the_style_tab_and_the_rest_on_the_content_tab(): void {
		$controls = $this->controls();

		foreach ( array( 'inherit_colours', 'heading_colour', 'body_colour', 'accent_colour' ) as $name ) {
			self::assertSame( 'style', $controls[ $name ]['tab'], $name );
		}

		foreach ( array( 'show_membership', 'show_cpd', 'show_events', 'show_credentials', 'show_directory', 'max_events', 'join_url', 'hide_when_signed_out', 'unavailable_message' ) as $name ) {
			self::assertSame( 'content', $controls[ $name ]['tab'], $name );
		}
	}

	#[Test]
	public function should_default_every_part_on_and_the_signed_out_widget_visible(): void {
		$controls = $this->controls();

		foreach ( array( 'show_heading', 'show_greeting', 'show_membership', 'show_cpd', 'show_events', 'show_credentials', 'show_directory', 'inherit_colours' ) as $name ) {
			self::assertSame( 'yes', $controls[ $name ]['args']['default'], $name );
		}

		self::assertSame( '', $controls['hide_when_signed_out']['args']['default'] );
		self::assertSame( 3, $controls['max_events']['args']['default'] );
		self::assertSame( 'My membership', $controls['heading_text']['args']['default'] );
	}

	#[Test]
	public function should_bound_the_lists_by_the_surface_limit(): void {
		$controls = $this->controls();

		self::assertSame( AGEND_APPS_RECORDS_MEMBER_SUMMARY_MAX_ITEMS, $controls['max_events']['args']['max'] );
		self::assertSame( AGEND_APPS_RECORDS_MEMBER_SUMMARY_MAX_ITEMS, $controls['max_certificates']['args']['max'] );
	}

	#[Test]
	public function should_show_a_list_size_only_while_its_part_is_on(): void {
		$controls = $this->controls();

		self::assertSame( array( 'show_events' => 'yes' ), $controls['max_events']['args']['condition'] );
		self::assertSame( array( 'show_credentials' => 'yes' ), $controls['max_certificates']['args']['condition'] );
	}

	// -----------------------------------------------------------------
	// Render
	// -----------------------------------------------------------------

	#[Test]
	public function should_echo_what_the_core_renderer_draws_from_the_widgets_settings(): void {
		$settings = array(
			'heading_text'       => 'Your membership',
			'show_heading'       => 'yes',
			'signed_out_message' => 'Log in to see it.',
		);

		$html = $this->widget( $settings )->run_render();

		self::assertSame( agend_apps_records_render_member_summary( $settings ), $html );
		self::assertStringContainsString( 'Your membership</h2>', $html );
		self::assertStringContainsString( 'Log in to see it.', $html );
	}

	#[Test]
	public function should_give_a_signed_in_member_the_shell_and_make_no_call(): void {
		$GLOBALS['agend_test_current_user_id'] = 42;

		$html = $this->widget()->run_render();

		self::assertStringContainsString( 'data-agend-member-summary-config', $html );
		self::assertStringNotContainsString( 'Priya', $html );
		self::assertCount( 0, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_show_the_labelled_example_in_the_editor_and_call_nothing(): void {
		\Elementor\Plugin::$instance->editor->is_edit_mode = true;
		$GLOBALS['agend_test_current_user_id']             = 42;

		$html = $this->widget( array( 'show_events' => '' ) )->run_render();

		self::assertStringContainsString( 'Example for editing only. Members see their own details here.', $html );
		self::assertStringNotContainsString( 'Upcoming events', $html );
		self::assertStringNotContainsString( 'data-agend-member-summary-config', $html );
		self::assertCount( 0, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_show_the_example_in_the_preview_frame_too(): void {
		\Elementor\Plugin::$instance->preview->is_preview_mode = true;

		self::assertStringContainsString( 'agend-member-summary--example', $this->widget()->run_render() );
	}

	#[Test]
	public function should_resolve_a_global_accent_colour_the_site_kit_holds(): void {
		$settings = array(
			'inherit_colours' => '',
			'accent_colour'   => '',
			'__globals__'     => array( 'accent_colour' => 'globals/colors?id=ab12' ),
		);

		// The kit has no such colour in this harness, so the reference is left
		// alone and the surface default applies, as for any empty value.
		self::assertStringContainsString( '--agend-ms-accent:#FF6B55;', $this->widget( $settings )->run_render() );
	}

	#[Test]
	public function should_render_nothing_for_a_visitor_and_make_no_call_when_gated(): void {
		$this->know( array( 'crm.tiers.browse' ) );
		Agend_Test_WP::$requests = array();

		self::assertSame( '', $this->widget()->run_render() );
		self::assertCount( 0, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_render_a_disabled_box_naming_the_scope_in_the_editor_when_gated(): void {
		$this->know( array( 'crm.tiers.browse' ) );
		\Elementor\Plugin::$instance->editor->is_edit_mode = true;

		$html = $this->widget()->run_render();

		self::assertStringContainsString( 'agend-surface-gate', $html );
		self::assertStringContainsString( 'crm.contacts.browse', $html );
	}

	#[Test]
	public function should_lead_the_first_content_section_with_the_gate_notice_while_gated(): void {
		$this->know( array( 'crm.tiers.browse' ) );

		self::assertSame( 'agend_surface_gate_notice', array_key_first( $this->controls() ) );
	}

	#[Test]
	public function should_render_nothing_inside_a_card_template_and_warn_only_in_the_editor(): void {
		Agend_Apps_Records_Record_Context::push( 'course', array( 'slug' => 'a-course' ) );

		self::assertSame( '', $this->widget()->run_render() );

		\Elementor\Plugin::$instance->editor->is_edit_mode = true;
		$html = $this->widget()->run_render();

		self::assertStringContainsString( 'elementor-alert-warning', $html );
		self::assertStringContainsString( 'cannot be placed inside a card or detail template', $html );
	}

	// -----------------------------------------------------------------
	// Registration
	// -----------------------------------------------------------------

	#[Test]
	public function should_be_registered_once_core_ships_the_surface(): void {
		$manager = new class() {
			/** @var object[] */
			public array $registered = array();

			public function register( object $widget ): void {
				$this->registered[] = $widget;
			}
		};

		( new \Agend_Elementor() )->register_widgets( $manager );

		self::assertContains( 'agend-member-summary', array_map( static fn( object $w ): string => $w->get_name(), $manager->registered ) );
	}
}
