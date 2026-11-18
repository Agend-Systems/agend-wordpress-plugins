<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Elementor;

use Agend_Apps_Endpoint_Scopes;
use Agend_Apps_Key_Scopes;
use Agend_Apps_Logger;
use Agend_Apps_Records_Record_Context;
use Agend_Test_WP;
use Agend\Tests\AppsCore\MemoryLogStore;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/logging/class-agend-apps-log-redactor.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/logging/class-agend-apps-log-store.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/logging/class-agend-apps-logger.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/tests/fixtures/memory-log-store.php';

/**
 * Exposes the protected methods a test drives directly.
 */
trait Community_Widget_Test_Methods {
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
 * The three Agend Community widgets: thin adapters over the core surfaces, so
 * what is pinned here is the seam. Their controls are the schema's, their
 * output is the core renderer's, and they stand down for a key that cannot use
 * the surface.
 *
 * Each case runs in its own process: the real read wrappers are declared here,
 * and other tests declare doubles for some of them.
 */
#[RunTestsInSeparateProcesses]
final class CommunityWidgetsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/tests/render-doubles.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/health.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-http-batch.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-key-scopes.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-endpoint-scopes.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/features.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/palette.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/loop-public.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/community-announcements.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/community-channels.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/community-feed.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/tests/fixtures/community-stubs.php';
		require_once AGEND_TESTS_ROOT . '/agend-elementor/agend-elementor.php';
		require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor.php';
		require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor-schema-controls.php';
		require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor-field-widget-trait.php';
		require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/widgets/class-agend-elementor-community-announcements.php';
		require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/widgets/class-agend-elementor-community-channels.php';
		require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/widgets/class-agend-elementor-community-feed.php';
		require_once __DIR__ . '/Content_Tab_Recording_Trait.php';
		agend_render_test_reset();
		Agend_Test_WP::set_filter( 'agend_apps_api_parallel', false );
		Agend_Apps_Logger::set_instance( new Agend_Apps_Logger( new MemoryLogStore() ) );
		Agend_Apps_Endpoint_Scopes::set_map( null );
		Agend_Apps_Records_Record_Context::reset();
		Agend_Test_WP::$options['agend_apps_account_slug']       = 'wdaa';
		Agend_Test_WP::$options['agend_apps_member_auth_mode']   = 'wordpress';
		Agend_Test_WP::$options['agend_apps_root_url_for_tests'] = 'https://api.agend.com.au';
	}

	protected function tearDown(): void {
		Agend_Apps_Records_Record_Context::reset();
		Agend_Apps_Endpoint_Scopes::set_map( null );
		parent::tearDown();
	}

	/** @return array<string, array{string, string, string, string, string}> */
	public static function widgets(): array {
		return array(
			'announcements' => array( 'community-announcements', 'agend-community-announcements', 'Agend Community Announcements', 'announcements', 'Agend_Elementor_Community_Announcements' ),
			'channels'      => array( 'community-channels', 'agend-community-channels', 'Agend Community Channels', 'channels', 'Agend_Elementor_Community_Channels' ),
			'popular posts' => array( 'community-feed', 'agend-community-feed', 'Agend Community Popular Posts', 'popular', 'Agend_Elementor_Community_Feed' ),
		);
	}

	private function widget( string $class, array $settings = array() ): object {
		return match ( $class ) {
			'Agend_Elementor_Community_Announcements' => new class( array(), null, $settings ) extends \Agend_Elementor_Community_Announcements {
				use Community_Widget_Test_Methods;
			},
			'Agend_Elementor_Community_Channels' => new class( array(), null, $settings ) extends \Agend_Elementor_Community_Channels {
				use Community_Widget_Test_Methods;
			},
			default => new class( array(), null, $settings ) extends \Agend_Elementor_Community_Feed {
				use Community_Widget_Test_Methods;
			},
		};
	}

	private function know( array $scopes ): void {
		Agend_Apps_Key_Scopes::store_from_response( array( 'data' => array( 'scopes' => $scopes, 'app_ids' => array() ) ) );
	}

	/**
	 * Every control the widget registers, keyed by id, with the tab it is on.
	 *
	 * @param object $widget A widget from {@see self::widget()}.
	 * @return array<string, array{tab: string, args: array}>
	 */
	private function controls( object $widget ): array {
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
	#[DataProvider( 'widgets' )]
	public function should_be_an_agend_widget_a_visitor_can_find_by_name( string $surface, string $name, string $title, string $keyword, string $class ): void {
		$widget = $this->widget( $class );

		self::assertSame( $name, $widget->get_name() );
		self::assertSame( $title, $widget->get_title() );
		self::assertSame( array( 'agend-apps' ), $widget->get_categories() );
		self::assertContains( $keyword, $widget->get_keywords() );
		self::assertContains( 'community', $widget->get_keywords() );
	}

	#[Test]
	#[DataProvider( 'widgets' )]
	public function should_load_the_shared_stylesheet_and_no_script( string $surface, string $name, string $title, string $keyword, string $class ): void {
		$widget = $this->widget( $class );

		self::assertSame( array( 'agend-apps-records-community' ), $widget->get_style_depends() );

		$declared = array_map(
			static fn( \ReflectionMethod $method ): string => $method->getName(),
			( new \ReflectionClass( $class ) )->getMethods( \ReflectionMethod::IS_PUBLIC )
		);

		self::assertNotContains( 'get_script_depends', $declared );
	}

	#[Test]
	#[DataProvider( 'widgets' )]
	public function should_load_no_style_while_the_key_cannot_use_the_surface( string $surface, string $name, string $title, string $keyword, string $class ): void {
		$this->know( array( 'crm.contacts.browse' ) );

		self::assertSame( array(), $this->widget( $class )->get_style_depends() );
	}

	// -----------------------------------------------------------------
	// Controls: the schema's, and nothing else
	// -----------------------------------------------------------------

	#[Test]
	#[DataProvider( 'widgets' )]
	public function should_register_exactly_the_controls_the_schema_declares( string $surface, string $name, string $title, string $keyword, string $class ): void {
		$expected = array();

		foreach ( agend_apps_records_surface_schema( $surface )['sections'] as $section ) {
			foreach ( $section['fields'] as $field ) {
				$expected[] = $field['name'];
			}
		}

		self::assertSame( $expected, array_keys( $this->controls( $this->widget( $class ) ) ) );
	}

	#[Test]
	#[DataProvider( 'widgets' )]
	public function should_put_the_colours_on_the_style_tab_and_the_rest_on_the_content_tab( string $surface, string $name, string $title, string $keyword, string $class ): void {
		$controls = $this->controls( $this->widget( $class ) );

		foreach ( array( 'inherit_colours', 'heading_colour', 'body_colour', 'accent_colour' ) as $control ) {
			self::assertSame( 'style', $controls[ $control ]['tab'], $control );
		}

		foreach ( array( 'channels', 'link_mode', 'empty_message', 'heading_text' ) as $control ) {
			self::assertSame( 'content', $controls[ $control ]['tab'], $control );
		}
	}

	#[Test]
	#[DataProvider( 'widgets' )]
	public function should_default_every_privacy_control_to_the_cautious_choice( string $surface, string $name, string $title, string $keyword, string $class ): void {
		$controls = $this->controls( $this->widget( $class ) );

		self::assertSame( 'auto', $controls['link_mode']['args']['default'] );
		if ( isset( $controls['author_display'] ) ) {
			self::assertSame( 'none', $controls['author_display']['args']['default'] );
			self::assertSame( '', $controls['show_avatar']['args']['default'] );
			self::assertSame( array( 'author_display!' => 'none' ), $controls['show_avatar']['args']['condition'], 'a photo only means something once an author is shown' );
		}

		if ( isset( $controls['include_untitled'] ) ) {
			self::assertSame( '', $controls['include_untitled']['args']['default'] );
		}
	}

	#[Test]
	#[DataProvider( 'widgets' )]
	public function should_offer_an_editor_only_the_channels_the_site_allows( string $surface, string $name, string $title, string $keyword, string $class ): void {
		$general = agend_cm_test_channel( 'general' );
		$jobs    = agend_cm_test_channel( 'jobs' );
		agend_cm_test_allow( array( $general ) );
		agend_cm_test_queue_channels( array( $general, $jobs ) );
		\Elementor\Plugin::$instance->editor->is_edit_mode = true;

		self::assertSame( array( 'chan-general' => 'General' ), $this->controls( $this->widget( $class ) )['channels']['args']['options'] );
	}

	#[Test]
	#[DataProvider( 'widgets' )]
	public function should_make_no_gateway_call_for_the_picker_on_a_visitors_request_or_while_gated( string $surface, string $name, string $title, string $keyword, string $class ): void {
		agend_cm_test_allow( array( agend_cm_test_channel( 'general' ) ) );

		self::assertSame( array(), $this->controls( $this->widget( $class ) )['channels']['args']['options'] );
		self::assertCount( 0, Agend_Test_WP::$requests );

		\Elementor\Plugin::$instance->editor->is_edit_mode = true;
		$this->know( array( 'crm.contacts.browse' ) );

		self::assertSame( array(), $this->controls( $this->widget( $class ) )['channels']['args']['options'] );
		self::assertCount( 0, Agend_Test_WP::$requests );
	}

	// -----------------------------------------------------------------
	// Render
	// -----------------------------------------------------------------

	#[Test]
	public function should_echo_what_the_core_renderer_draws_from_the_widgets_settings(): void {
		$news = agend_cm_test_channel( 'news', array( 'channel_type' => 'feed', 'name' => 'Member News' ) );
		agend_cm_test_allow( array( $news ) );
		agend_cm_test_queue_channels( array( $news ) );
		agend_cm_test_queue_announcements( array( agend_cm_test_post( 'x', $news, "## Open day\n\nCome along." ) ) );
		$settings = array(
			'heading_text' => 'Our notices',
			'show_heading' => 'yes',
			'count'        => 3,
		);

		$html = $this->widget( 'Agend_Elementor_Community_Announcements', $settings )->run_render();

		self::assertStringContainsString( 'Our notices', $html );
		self::assertStringContainsString( 'Open day', $html );
		self::assertStringContainsString( 'loop-channel/news', $html );
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
		self::assertStringContainsString( '--agend-cm-accent:#FF6B55;', $this->widget( 'Agend_Elementor_Community_Channels', $settings )->run_render() );
	}

	#[Test]
	public function should_tell_an_editor_in_the_canvas_where_to_allow_channels_and_never_a_visitor(): void {
		$visitor = $this->widget( 'Agend_Elementor_Community_Channels' )->run_render();

		self::assertStringNotContainsString( 'Settings', $visitor );
		self::assertStringContainsString( 'There are no community channels to show yet.', $visitor );

		\Elementor\Plugin::$instance->editor->is_edit_mode = true;
		$editor = $this->widget( 'Agend_Elementor_Community_Channels' )->run_render();

		self::assertStringContainsString( 'No channels are allowed on the website yet.', $editor );
	}

	#[Test]
	#[DataProvider( 'widgets' )]
	public function should_render_nothing_for_a_visitor_and_make_no_call_when_gated( string $surface, string $name, string $title, string $keyword, string $class ): void {
		agend_cm_test_allow( array( agend_cm_test_channel( 'general' ) ) );
		$this->know( array( 'crm.contacts.browse' ) );

		self::assertSame( '', $this->widget( $class )->run_render() );
		self::assertCount( 0, Agend_Test_WP::$requests );
	}

	#[Test]
	#[DataProvider( 'widgets' )]
	public function should_render_a_disabled_box_naming_the_scope_in_the_editor_when_gated( string $surface, string $name, string $title, string $keyword, string $class ): void {
		$this->know( array( 'crm.contacts.browse' ) );
		\Elementor\Plugin::$instance->editor->is_edit_mode = true;

		$html = $this->widget( $class )->run_render();

		self::assertStringContainsString( 'agend-surface-gate', $html );
		self::assertStringContainsString( 'loop.channels.browse', $html );
		self::assertCount( 0, Agend_Test_WP::$requests );
	}

	#[Test]
	#[DataProvider( 'widgets' )]
	public function should_lead_the_first_content_section_with_the_gate_notice_while_gated( string $surface, string $name, string $title, string $keyword, string $class ): void {
		$this->know( array( 'crm.contacts.browse' ) );

		self::assertSame( 'agend_surface_gate_notice', array_key_first( $this->controls( $this->widget( $class ) ) ) );
	}

	#[Test]
	#[DataProvider( 'widgets' )]
	public function should_render_nothing_inside_a_card_template_and_warn_only_in_the_editor( string $surface, string $name, string $title, string $keyword, string $class ): void {
		Agend_Apps_Records_Record_Context::push( 'course', array( 'slug' => 'a-course' ) );

		self::assertSame( '', $this->widget( $class )->run_render() );

		\Elementor\Plugin::$instance->editor->is_edit_mode = true;
		$html = $this->widget( $class )->run_render();

		self::assertStringContainsString( 'elementor-alert-warning', $html );
		self::assertStringContainsString( 'cannot be placed inside a card or detail template', $html );
		self::assertCount( 0, Agend_Test_WP::$requests );
	}

	// -----------------------------------------------------------------
	// Registration
	// -----------------------------------------------------------------

	#[Test]
	public function should_be_registered_once_core_ships_the_surfaces(): void {
		$manager = new class() {
			/** @var object[] */
			public array $registered = array();

			public function register( object $widget ): void {
				$this->registered[] = $widget;
			}
		};

		( new \Agend_Elementor() )->register_widgets( $manager );

		$names = array_map( static fn( object $w ): string => $w->get_name(), $manager->registered );

		foreach ( array( 'agend-community-announcements', 'agend-community-channels', 'agend-community-feed' ) as $name ) {
			self::assertContains( $name, $names );
		}
	}
}
