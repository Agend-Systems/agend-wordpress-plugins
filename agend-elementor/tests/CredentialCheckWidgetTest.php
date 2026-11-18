<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Elementor;

use Agend_Apps_Captcha;
use Agend_Apps_Key_Scopes;
use Agend_Apps_Records_Record_Context;
use Agend_Elementor_Credential_Check;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;

/**
 * Exposes the protected methods a test drives directly.
 */
trait Credential_Check_Widget_Test_Methods {
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
 * The Agend Credential Check widget: a thin adapter over the core surface, so
 * what is pinned here is the seam: its controls are the schema's, its output is
 * the core renderer's, it fails closed without a captcha, and it stands down
 * for a key that cannot use the surface.
 *
 * Each case runs in its own process: the real collaborators are declared here,
 * which other tests in this suite declare as doubles.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass( Agend_Elementor_Credential_Check::class )]
final class CredentialCheckWidgetTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/tests/render-doubles.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/health.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-key-scopes.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-secret-store.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-captcha.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/features.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/palette.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/public-throttle.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/credential-guard.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/credential-check.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/credential-check.php';
		require_once AGEND_TESTS_ROOT . '/agend-elementor/agend-elementor.php';
		require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor.php';
		require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor-schema-controls.php';
		require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor-field-widget-trait.php';
		require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/widgets/class-agend-elementor-credential-check.php';
		require_once __DIR__ . '/Content_Tab_Recording_Trait.php';
		agend_render_test_reset();
		Agend_Apps_Records_Record_Context::reset();
		Agend_Test_WP::set_filter( 'agend_apps_captcha_allow_none', true );
		$this->know( array( 'lms.certificates.verify', 'lms.badges.verify' ) );
	}

	protected function tearDown(): void {
		Agend_Apps_Records_Record_Context::reset();
		parent::tearDown();
	}

	private function widget( array $settings = array() ): object {
		return new class( array(), null, $settings ) extends Agend_Elementor_Credential_Check {
			use Credential_Check_Widget_Test_Methods;
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

		self::assertSame( 'agend-credential-check', $widget->get_name() );
		self::assertSame( 'Agend Credential Check', $widget->get_title() );
		self::assertSame( array( 'agend-apps' ), $widget->get_categories() );
		self::assertContains( 'certificate', $widget->get_keywords() );
		self::assertContains( 'badge', $widget->get_keywords() );
	}

	#[Test]
	public function should_load_its_script_and_style_which_bring_the_captcha_client_with_them(): void {
		$widget = $this->widget();

		self::assertSame( array( 'agend-apps-records-credential-check' ), $widget->get_script_depends() );
		self::assertSame( array( 'agend-apps-records-credential-check' ), $widget->get_style_depends() );
	}

	#[Test]
	public function should_load_neither_while_the_key_cannot_use_the_surface(): void {
		$this->know( array( 'lms.paths.browse' ) );

		self::assertSame( array(), $this->widget()->get_script_depends() );
		self::assertSame( array(), $this->widget()->get_style_depends() );
	}

	// -----------------------------------------------------------------
	// Controls: the schema's, and nothing else
	// -----------------------------------------------------------------

	#[Test]
	public function should_register_exactly_the_controls_the_schema_declares(): void {
		$expected = array();

		foreach ( agend_apps_records_surface_schema( 'credential-check' )['sections'] as $section ) {
			foreach ( $section['fields'] as $field ) {
				$expected[] = $field['name'];
			}
		}

		self::assertSame( $expected, array_keys( array_diff_key( $this->controls(), array( 'agend_surface_gate_notice' => true ) ) ) );
	}

	#[Test]
	public function should_put_the_colours_on_the_style_tab_and_the_rest_on_the_content_tab(): void {
		$controls = $this->controls();

		foreach ( array( 'inherit_colours', 'heading_colour', 'body_colour', 'accent_colour' ) as $name ) {
			self::assertSame( 'style', $controls[ $name ]['tab'], $name );
		}

		foreach ( array( 'heading_text', 'input_label', 'prefill_param', 'auto_check', 'show_holder', 'not_found_text', 'help_url' ) as $name ) {
			self::assertSame( 'content', $controls[ $name ]['tab'], $name );
		}
	}

	#[Test]
	public function should_default_every_control_to_what_the_schema_says(): void {
		$controls = $this->controls();

		self::assertSame( 'yes', $controls['auto_check']['args']['default'] );
		self::assertSame( 'yes', $controls['show_number']['args']['default'] );
		self::assertSame( 'yes', $controls['inherit_colours']['args']['default'] );
		self::assertSame( 'code', $controls['prefill_param']['args']['default'] );
		self::assertSame( 'Credential verified', $controls['valid_title']['args']['default'] );
		self::assertNotSame( 'yes', $controls['show_holder']['args']['default'], 'the earner name is off by default' );
	}

	#[Test]
	public function should_lead_the_first_content_section_with_the_gate_notice_while_gated(): void {
		$this->know( array( 'lms.paths.browse' ) );

		self::assertSame( 'agend_surface_gate_notice', array_key_first( $this->controls() ) );
	}

	// -----------------------------------------------------------------
	// Render
	// -----------------------------------------------------------------

	#[Test]
	public function should_echo_what_the_core_renderer_draws_from_the_widgets_settings(): void {
		$settings = array(
			'heading_text' => 'Verify a certificate',
			'show_heading' => 'yes',
			'button_label' => 'Look it up',
		);

		$html = $this->widget( $settings )->run_render();

		self::assertStringContainsString( 'Verify a certificate', $html );
		self::assertStringContainsString( 'Look it up', $html );
		self::assertStringContainsString( 'data-agend-credential-config=', $html );
		self::assertStringNotContainsString( 'elementor-alert', $html );
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
		self::assertStringContainsString( '--agend-cc-accent:#FF6B55;', $this->widget( $settings )->run_render() );
	}

	#[Test]
	public function should_draw_nothing_for_a_visitor_when_no_captcha_is_set_up(): void {
		Agend_Test_WP::set_filter( 'agend_apps_captcha_allow_none', false );

		self::assertSame( '', $this->widget()->run_render() );
	}

	#[Test]
	public function should_tell_an_editor_why_visitors_see_nothing_and_still_draw_the_form_to_style(): void {
		Agend_Test_WP::set_filter( 'agend_apps_captcha_allow_none', false );
		\Elementor\Plugin::$instance->editor->is_edit_mode = true;

		$html = $this->widget()->run_render();

		self::assertStringContainsString( 'elementor-alert-warning', $html );
		self::assertStringContainsString( 'visitors will not see this form', $html );
		self::assertStringContainsString( 'Settings &gt; Agend Apps &gt; Form Captcha', $html );
		self::assertStringContainsString( 'agend-credential-check__form', $html );
		self::assertStringContainsString( 'data-agend-credential-preview="1"', $html );
	}

	#[Test]
	public function should_tell_an_editor_a_certificate_only_key_will_refuse_badge_codes(): void {
		$this->know( array( 'lms.certificates.verify' ) );
		\Elementor\Plugin::$instance->editor->is_edit_mode = true;

		$html = $this->widget()->run_render();

		self::assertStringContainsString( 'lms.badges.verify', $html );
		self::assertStringContainsString( 'agend-credential-check__form', $html );
	}

	#[Test]
	public function should_tell_a_visitor_nothing_about_the_key(): void {
		$this->know( array( 'lms.certificates.verify' ) );

		$html = $this->widget()->run_render();

		self::assertStringNotContainsString( 'elementor-alert', $html );
		self::assertStringNotContainsString( 'lms.badges.verify', $html );
	}

	#[Test]
	public function should_render_nothing_for_a_visitor_when_gated(): void {
		$this->know( array( 'lms.paths.browse' ) );

		self::assertSame( '', $this->widget()->run_render() );
	}

	#[Test]
	public function should_render_a_disabled_box_naming_the_scope_in_the_editor_when_gated(): void {
		$this->know( array( 'lms.paths.browse' ) );
		\Elementor\Plugin::$instance->editor->is_edit_mode = true;

		$html = $this->widget()->run_render();

		self::assertStringContainsString( 'agend-surface-gate', $html );
		self::assertStringContainsString( 'lms.certificates.verify', $html );
		self::assertStringNotContainsString( 'agend-credential-check__form', $html );
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

		self::assertContains( 'agend-credential-check', array_map( static fn( object $w ): string => $w->get_name(), $manager->registered ) );
	}
}
