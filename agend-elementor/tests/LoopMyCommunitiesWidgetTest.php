<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Elementor;

use Agend_Apps_Key_Scopes;
use Agend_Apps_Records_Record_Context;
use Agend_Elementor_My_Communities;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;

/**
 * Exposes the protected methods a test drives directly.
 */
trait My_Communities_Widget_Test_Methods {
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
 * The Agend My Communities widget: a thin adapter over the core surface, so what
 * is pinned here is the seam: its controls are the schema's, its output is the
 * core renderer's, it is never served from Elementor's element cache, and it
 * stands down for a key that cannot use the surface.
 *
 * Each case runs in its own process: the render path loads the core renderer
 * and the access state, whose state is held for the request.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass( Agend_Elementor_My_Communities::class )]
final class LoopMyCommunitiesWidgetTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/tests/render-doubles.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/health.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-api.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/identity.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/loop-memberships.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-key-scopes.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/features.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/palette.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/loop-my-communities.php';
		require_once AGEND_TESTS_ROOT . '/agend-elementor/agend-elementor.php';
		require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor.php';
		require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor-schema-controls.php';
		require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor-field-widget-trait.php';
		require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/widgets/class-agend-elementor-loop-my-communities.php';
		require_once __DIR__ . '/Content_Tab_Recording_Trait.php';
		agend_render_test_reset();
		Agend_Apps_Records_Record_Context::reset();
	}

	protected function tearDown(): void {
		Agend_Apps_Records_Record_Context::reset();
		parent::tearDown();
	}

	private function widget( array $settings = array() ): object {
		return new class( array(), null, $settings ) extends Agend_Elementor_My_Communities {
			use My_Communities_Widget_Test_Methods;
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

		self::assertSame( 'agend-loop-my-communities', $widget->get_name() );
		self::assertSame( 'Agend My Communities', $widget->get_title() );
		self::assertSame( array( 'agend-apps' ), $widget->get_categories() );
		self::assertContains( 'communities', $widget->get_keywords() );
		self::assertContains( 'loop', $widget->get_keywords() );
	}

	#[Test]
	public function should_load_its_stylesheet_and_its_script(): void {
		$widget = $this->widget();

		self::assertSame( array( 'agend-apps-records-loop-my-communities' ), $widget->get_style_depends() );
		self::assertSame( array( 'agend-apps-records-loop-my-communities' ), $widget->get_script_depends() );
	}

	#[Test]
	public function should_load_nothing_while_the_key_cannot_use_the_surface(): void {
		$this->know( array( 'crm.tiers.browse' ) );

		self::assertSame( array(), $this->widget()->get_style_depends() );
		self::assertSame( array(), $this->widget()->get_script_depends() );
	}

	#[Test]
	public function should_never_be_served_from_elementors_element_cache(): void {
		self::assertTrue( $this->widget()->run_is_dynamic_content() );
	}

	// -----------------------------------------------------------------
	// Controls: the schema's, and nothing else
	// -----------------------------------------------------------------

	#[Test]
	public function should_register_exactly_the_controls_the_schema_declares(): void {
		$expected = array();

		foreach ( agend_apps_records_surface_schema( 'loop-my-communities' )['sections'] as $section ) {
			foreach ( $section['fields'] as $field ) {
				$expected[] = $field['name'];
			}
		}

		self::assertSame( $expected, array_keys( $this->controls() ) );
	}

	#[Test]
	public function should_offer_the_headings_and_labels_as_editable_text_with_the_agreed_defaults(): void {
		$controls = $this->controls();

		self::assertSame( 'My communities', $controls['heading_text']['args']['default'] );
		self::assertSame( 'Access community', $controls['button_label']['args']['default'] );
		self::assertSame( 'You are not in any communities yet.', $controls['empty_message']['args']['default'] );
		self::assertSame( 'content', $controls['button_label']['tab'] );
		self::assertSame( 'style', $controls['heading_colour']['tab'] );
	}

	// -----------------------------------------------------------------
	// Render
	// -----------------------------------------------------------------

	#[Test]
	public function should_echo_what_the_core_renderer_draws_from_the_widgets_settings(): void {
		$settings = array(
			'heading_text'       => 'Your communities',
			'show_heading'       => 'yes',
			'signed_out_message' => 'Log in to see them.',
		);

		$html = $this->widget( $settings )->run_render();

		self::assertSame( agend_apps_records_render_loop_my_communities( $settings ), $html );
		self::assertStringContainsString( 'Your communities</h2>', $html );
		self::assertStringContainsString( 'Log in to see them.', $html );
	}

	#[Test]
	public function should_give_a_signed_in_member_the_shell_and_make_no_call(): void {
		$GLOBALS['agend_test_current_user_id'] = 42;

		$html = $this->widget()->run_render();

		self::assertStringContainsString( 'data-agend-my-communities-config', $html );
		self::assertCount( 0, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_show_the_labelled_example_in_the_editor_and_call_nothing(): void {
		\Elementor\Plugin::$instance->editor->is_edit_mode = true;
		$GLOBALS['agend_test_current_user_id']             = 42;

		$html = $this->widget()->run_render();

		self::assertStringContainsString( 'Example for editing only. Members see the communities they belong to here.', $html );
		self::assertStringNotContainsString( 'data-agend-my-communities-config', $html );
		self::assertCount( 0, Agend_Test_WP::$requests );
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
		self::assertStringContainsString( 'loop.memberships.read', $html );
	}

	#[Test]
	public function should_render_nothing_inside_a_card_template_and_warn_only_in_the_editor(): void {
		Agend_Apps_Records_Record_Context::push( 'course', array( 'slug' => 'a-course' ) );

		self::assertSame( '', $this->widget()->run_render() );

		\Elementor\Plugin::$instance->editor->is_edit_mode = true;

		self::assertStringContainsString( 'cannot be placed inside a card or detail template', $this->widget()->run_render() );
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

		self::assertContains( 'agend-loop-my-communities', array_map( static fn( object $w ): string => $w->get_name(), $manager->registered ) );
	}
}
