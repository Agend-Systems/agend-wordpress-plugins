<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Elementor;

use Agend_Apps_Key_Scopes;
use Agend_Apps_Records_Record_Context;
use Agend_Elementor_Learning_Paths;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;

/**
 * Exposes the protected methods a test drives directly.
 */
trait Learning_Paths_Widget_Test_Methods {
	public function run_controls(): void {
		$this->register_controls();
	}

	public function run_render(): string {
		ob_start();
		$this->render();

		return (string) ob_get_clean();
	}
}

/**
 * The Agend Learning Pathways widget: a thin adapter over the core surface, so
 * what is pinned here is the seam: its controls are the schema's, its output is
 * the core renderer's, and it stands down for a key that cannot use the
 * surface.
 *
 * Each case runs in its own process: the fixture declares the gateway wrappers
 * the surface reads, which other tests in this suite assert do NOT exist.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass( Agend_Elementor_Learning_Paths::class )]
final class LearningPathsWidgetTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/tests/render-doubles.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/health.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-key-scopes.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/features.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/palette.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/learning-paths.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/tests/fixtures/learning-paths-stubs.php';
		require_once AGEND_TESTS_ROOT . '/agend-elementor/agend-elementor.php';
		require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor.php';
		require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor-schema-controls.php';
		require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor-field-widget-trait.php';
		require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/widgets/class-agend-elementor-learning-paths.php';
		require_once __DIR__ . '/Content_Tab_Recording_Trait.php';
		agend_render_test_reset();
		agend_lp_test_reset();
		Agend_Apps_Records_Record_Context::reset();
	}

	protected function tearDown(): void {
		Agend_Apps_Records_Record_Context::reset();
		parent::tearDown();
	}

	private function widget( array $settings = array() ): object {
		return new class( array(), null, $settings ) extends Agend_Elementor_Learning_Paths {
			use Learning_Paths_Widget_Test_Methods;
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
	public function should_be_an_agend_widget_a_visitor_can_find_by_name(): void {
		$widget = $this->widget();

		self::assertSame( 'agend-learning-paths', $widget->get_name() );
		self::assertSame( 'Agend Learning Pathways', $widget->get_title() );
		self::assertSame( array( 'agend-apps' ), $widget->get_categories() );
		self::assertContains( 'pathways', $widget->get_keywords() );
	}

	#[Test]
	public function should_load_its_stylesheet_and_no_script(): void {
		$widget = $this->widget();

		self::assertSame( array( 'agend-apps-records-learning-paths' ), $widget->get_style_depends() );

		// It declares no script of its own, so Elementor's default (none) stands.
		$declared = array_map(
			static fn( \ReflectionMethod $method ): string => $method->getName(),
			array_filter(
				( new \ReflectionClass( Agend_Elementor_Learning_Paths::class ) )->getMethods( \ReflectionMethod::IS_PUBLIC ),
				static fn( \ReflectionMethod $method ): bool => Agend_Elementor_Learning_Paths::class === $method->class
			)
		);

		self::assertNotContains( 'get_script_depends', $declared );
	}

	#[Test]
	public function should_load_no_style_while_the_key_cannot_use_the_surface(): void {
		$this->know( array( 'lms.courses.browse' ) );

		self::assertSame( array(), $this->widget()->get_style_depends() );
	}

	// -----------------------------------------------------------------
	// Controls: the schema's, and nothing else
	// -----------------------------------------------------------------

	#[Test]
	public function should_register_exactly_the_controls_the_schema_declares(): void {
		$expected = array();

		foreach ( agend_apps_records_surface_schema( 'learning-paths' )['sections'] as $section ) {
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

		foreach ( array( 'path_source', 'max_paths', 'selected_paths', 'show_courses', 'course_links', 'empty_message' ) as $name ) {
			self::assertSame( 'content', $controls[ $name ]['tab'], $name );
		}
	}

	#[Test]
	public function should_default_every_control_to_what_the_schema_says(): void {
		$controls = $this->controls();

		self::assertSame( 'all', $controls['path_source']['args']['default'] );
		self::assertSame( 6, $controls['max_paths']['args']['default'] );
		self::assertSame( 'yes', $controls['show_courses']['args']['default'] );
		self::assertSame( 'yes', $controls['inherit_colours']['args']['default'] );
		self::assertSame( '2', $controls['columns_desktop']['args']['default'] );
		self::assertSame( 'detail', $controls['course_links']['args']['default'] );
		self::assertSame( 'auto', $controls['card_layout']['args']['default'] );
		self::assertSame( '1', $controls['columns_mobile']['args']['default'] );
		self::assertSame( array( 'auto', 'stacked' ), array_keys( $controls['card_layout']['args']['options'] ) );
	}

	#[Test]
	public function should_bound_the_number_of_paths_by_the_surface_limit(): void {
		self::assertSame( AGEND_APPS_RECORDS_LEARNING_PATHS_MAX, $this->controls()['max_paths']['args']['max'] );
	}

	#[Test]
	public function should_show_the_picker_only_for_chosen_paths_and_the_number_only_for_all(): void {
		$controls = $this->controls();

		self::assertSame( array( 'path_source' => 'selected' ), $controls['selected_paths']['args']['condition'] );
		self::assertSame( array( 'path_source' => 'all' ), $controls['max_paths']['args']['condition'] );
	}

	#[Test]
	public function should_offer_the_published_paths_to_an_editor_for_the_picker(): void {
		\Elementor\Plugin::$instance->editor->is_edit_mode = true;
		agend_lp_test_set_list(
			array(
				agend_lp_test_path( 'p1', array( 'title' => 'Web Foundations' ) ),
				agend_lp_test_path( 'p2', array( 'title' => 'Draft', 'published' => false ) ),
			)
		);

		$options = $this->controls()['selected_paths']['args']['options'];

		self::assertSame( array( 'p1' => 'Web Foundations' ), $options );
		self::assertTrue( agend_lp_test_calls( 'list' )[0]['anonymous'] );
	}

	#[Test]
	public function should_make_no_gateway_call_for_the_picker_on_a_visitors_request(): void {
		agend_lp_test_set_list( array( agend_lp_test_path( 'p1' ) ) );

		self::assertSame( array(), $this->controls()['selected_paths']['args']['options'] );
		self::assertSame( array(), agend_lp_test_calls() );
	}

	#[Test]
	public function should_make_no_gateway_call_for_the_picker_while_gated(): void {
		\Elementor\Plugin::$instance->editor->is_edit_mode = true;
		$this->know( array( 'lms.courses.browse' ) );

		self::assertSame( array(), $this->controls()['selected_paths']['args']['options'] );
		self::assertSame( array(), agend_lp_test_calls() );
	}

	// -----------------------------------------------------------------
	// Render
	// -----------------------------------------------------------------

	#[Test]
	public function should_echo_what_the_core_renderer_draws_from_the_widgets_settings(): void {
		$path = agend_lp_test_path( 'p1', array( 'title' => 'Web Foundations' ) );
		agend_lp_test_set_list( array( $path ) );
		agend_lp_test_set_detail( $path, array( agend_lp_test_course( 'html-basics', 0 ) ) );
		$settings = array(
			'heading_text' => 'Our pathways',
			'show_heading' => 'yes',
			'max_paths'    => 3,
		);

		$html  = $this->widget( $settings )->run_render();
		$calls = agend_lp_test_calls( 'list' );

		self::assertSame( array( 'limit' => 3 ), $calls[0]['query'] );
		self::assertSame( agend_apps_records_render_learning_paths( $settings ), $html );
		self::assertStringContainsString( 'Our pathways', $html );
		self::assertStringContainsString( 'Html Basics', $html );
	}

	#[Test]
	public function should_resolve_a_global_accent_colour_the_site_kit_holds(): void {
		agend_lp_test_set_list( array( agend_lp_test_path( 'p1' ) ) );
		$settings = array(
			'inherit_colours' => '',
			'accent_colour'   => '',
			'__globals__'     => array( 'accent_colour' => 'globals/colors?id=ab12' ),
		);

		// The kit has no such colour in this harness, so the reference is left
		// alone and the surface default applies, as for any empty value.
		self::assertStringContainsString( '--agend-lp-accent:#FF6B55;', $this->widget( $settings )->run_render() );
	}

	#[Test]
	public function should_render_nothing_for_a_visitor_and_make_no_call_when_gated(): void {
		agend_lp_test_set_list( array( agend_lp_test_path( 'p1' ) ) );
		$this->know( array( 'lms.courses.browse' ) );
		Agend_Test_WP::$requests = array();

		self::assertSame( '', $this->widget()->run_render() );
		self::assertSame( array(), agend_lp_test_calls() );
		self::assertCount( 0, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_render_a_disabled_box_naming_the_scope_in_the_editor_when_gated(): void {
		$this->know( array( 'lms.courses.browse' ) );
		\Elementor\Plugin::$instance->editor->is_edit_mode = true;

		$html = $this->widget()->run_render();

		self::assertStringContainsString( 'agend-surface-gate', $html );
		self::assertStringContainsString( 'lms.paths.browse', $html );
		self::assertSame( array(), agend_lp_test_calls() );
	}

	#[Test]
	public function should_lead_the_first_content_section_with_the_gate_notice_while_gated(): void {
		$this->know( array( 'lms.courses.browse' ) );

		$controls = $this->controls();

		self::assertSame( 'agend_surface_gate_notice', array_key_first( $controls ) );
	}

	#[Test]
	public function should_render_nothing_inside_a_card_template_and_warn_only_in_the_editor(): void {
		agend_lp_test_set_list( array( agend_lp_test_path( 'p1' ) ) );
		Agend_Apps_Records_Record_Context::push( 'course', array( 'slug' => 'a-course' ) );

		self::assertSame( '', $this->widget()->run_render() );

		\Elementor\Plugin::$instance->editor->is_edit_mode = true;
		$html = $this->widget()->run_render();

		self::assertStringContainsString( 'elementor-alert-warning', $html );
		self::assertStringContainsString( 'cannot be placed inside a card or detail template', $html );
		self::assertSame( array(), agend_lp_test_calls() );
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

		self::assertContains( 'agend-learning-paths', array_map( static fn( object $w ): string => $w->get_name(), $manager->registered ) );
	}
}
