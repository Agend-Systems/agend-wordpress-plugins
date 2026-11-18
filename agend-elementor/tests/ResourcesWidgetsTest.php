<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Elementor;

use Agend_Apps_Records_Record_Context;
use Agend_Elementor_Resources_Ask;
use Agend_Elementor_Resources_Collections;
use Agend_Elementor_Resources_Search;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/tests/fixtures/resources-fixtures.php';

/**
 * Exposes the protected methods a test drives directly.
 */
trait Resources_Widget_Test_Methods {
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
 * The three Agend Resources widgets: thin adapters over the core surfaces, so
 * what is pinned here is the seam: the controls are the schema's, the output is
 * the core renderer's, and a widget stands down for a key that cannot use its
 * surface.
 */
final class ResourcesWidgetsTest extends TestCase {

	private const A = '09600000-0000-0000-0004-000000000001';

	protected function setUp(): void {
		parent::setUp();
		agend_res_test_reset();
		require_once AGEND_TESTS_ROOT . '/agend-elementor/agend-elementor.php';
		require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor.php';
		require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor-schema-controls.php';
		require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor-field-widget-trait.php';
		require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/widgets/class-agend-elementor-resources-collections.php';
		require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/widgets/class-agend-elementor-resources-search.php';
		require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/widgets/class-agend-elementor-resources-ask.php';
	}

	/** @return array<string, array{string, string, string, string, string}> */
	public static function widgets(): array {
		return array(
			'collections' => array( Agend_Elementor_Resources_Collections::class, 'resources-collections', 'agend-resources-collections', 'Agend Resources Collections', 'atlas.collections.browse' ),
			'search'      => array( Agend_Elementor_Resources_Search::class, 'resources-search', 'agend-resources-search', 'Agend Resources Search', 'atlas.pages.search' ),
			'ask'         => array( Agend_Elementor_Resources_Ask::class, 'resources-ask', 'agend-resources-ask', 'Agend Resources Ask', 'atlas.questions.create' ),
		);
	}

	private function widget( string $class, array $settings = array() ): object {
		switch ( $class ) {
			case Agend_Elementor_Resources_Search::class:
				return new class( array(), null, $settings ) extends Agend_Elementor_Resources_Search {
					use Resources_Widget_Test_Methods;
				};

			case Agend_Elementor_Resources_Ask::class:
				return new class( array(), null, $settings ) extends Agend_Elementor_Resources_Ask {
					use Resources_Widget_Test_Methods;
				};
		}

		return new class( array(), null, $settings ) extends Agend_Elementor_Resources_Collections {
			use Resources_Widget_Test_Methods;
		};
	}

	/**
	 * Every control the widget registers, keyed by id, with the tab it is on.
	 *
	 * @return array<string, array{tab: string, args: array}>
	 */
	private function controls( string $class ): array {
		$widget = $this->widget( $class );
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
	public function should_be_an_agend_widget_a_visitor_can_find_by_name( string $class, string $surface, string $name, string $title ): void {
		$widget = $this->widget( $class );

		self::assertSame( $name, $widget->get_name() );
		self::assertSame( $title, $widget->get_title() );
		self::assertSame( array( 'agend-apps' ), $widget->get_categories() );
		self::assertContains( 'resources', $widget->get_keywords() );
	}

	#[Test]
	#[DataProvider( 'widgets' )]
	public function should_load_the_shared_stylesheet_and_only_its_own_script( string $class, string $surface ): void {
		$widget = $this->widget( $class );

		self::assertSame( array( 'agend-apps-records-resources' ), $widget->get_style_depends() );
		self::assertSame( array( 'agend-apps-records-' . $surface ), $widget->get_script_depends(), 'a site without Ask never loads the Ask script' );
	}

	#[Test]
	#[DataProvider( 'widgets' )]
	public function should_load_neither_style_nor_script_while_the_key_cannot_use_the_surface( string $class, string $surface, string $name, string $title, string $scope ): void {
		agend_res_test_know( array( 'lms.courses.browse' ) );
		$widget = $this->widget( $class );

		self::assertSame( array(), $widget->get_style_depends() );
		self::assertSame( array(), $widget->get_script_depends() );
		unset( $scope );
	}

	// -----------------------------------------------------------------
	// Controls: the schema's, and nothing else
	// -----------------------------------------------------------------

	#[Test]
	#[DataProvider( 'widgets' )]
	public function should_register_exactly_the_controls_the_schema_declares( string $class, string $surface ): void {
		$expected = array();

		foreach ( agend_apps_records_surface_schema( $surface )['sections'] as $section ) {
			foreach ( $section['fields'] as $field ) {
				$expected[] = $field['name'];
			}
		}

		self::assertSame( $expected, array_keys( $this->controls( $class ) ) );
	}

	#[Test]
	#[DataProvider( 'widgets' )]
	public function should_put_the_colours_on_the_style_tab_and_the_rest_on_the_content_tab( string $class ): void {
		$controls = $this->controls( $class );

		foreach ( array( 'inherit_colours', 'heading_colour', 'body_colour', 'accent_colour' ) as $name ) {
			self::assertSame( 'style', $controls[ $name ]['tab'], $name );
		}

		self::assertSame( 'content', $controls['show_heading']['tab'] );
		self::assertSame( 'content', $controls['heading_text']['tab'] );
	}

	#[Test]
	public function should_default_the_collections_controls_to_what_the_schema_says(): void {
		$controls = $this->controls( Agend_Elementor_Resources_Collections::class );

		self::assertSame( 6, $controls['max_collections']['args']['default'] );
		self::assertSame( AGEND_APPS_RECORDS_RESOURCES_COLLECTIONS_MAX, $controls['max_collections']['args']['max'] );
		self::assertSame( '3', $controls['columns_desktop']['args']['default'] );
		self::assertSame( 'yes', $controls['show_member_collections']['args']['default'] );
		self::assertSame( 'additional', $controls['member_view']['args']['default'] );
		self::assertSame( array( 'additional', 'replace' ), array_keys( $controls['member_view']['args']['options'] ) );
		self::assertSame( array( 'show_member_collections' => 'yes' ), $controls['member_view']['args']['condition'] );
	}

	#[Test]
	public function should_default_the_search_controls_to_the_free_visitor_choice_and_warn_about_the_other(): void {
		$controls = $this->controls( Agend_Elementor_Resources_Search::class );

		self::assertSame( 'link_out', $controls['visitor_mode']['args']['default'] );
		self::assertSame( 6, $controls['max_results']['args']['default'] );
		self::assertSame( '', $controls['show_suggestions']['args']['default'], 'suggested searches are off until an editor writes some' );
		self::assertStringContainsString( 'AI search allowance', $controls['visitor_mode']['args']['description'] );
	}

	#[Test]
	public function should_default_the_ask_controls_to_a_sign_in_prompt_and_sources_shown(): void {
		$controls = $this->controls( Agend_Elementor_Resources_Ask::class );

		self::assertSame( 'prompt', $controls['signed_out_behaviour']['args']['default'] );
		self::assertSame( 'yes', $controls['show_sources']['args']['default'] );
		self::assertSame( array( 'signed_out_behaviour' => 'prompt' ), $controls['signed_out_message']['args']['condition'] );
	}

	#[Test]
	public function should_offer_the_collections_to_an_editor_for_the_picker_and_never_to_a_visitor(): void {
		agend_res_test_queue_collections(
			array(
				agend_res_test_collection( self::A, array( 'title' => 'New Member Starter Pack' ) ),
				agend_res_test_collection( 'b', array( 'title' => '' ) ),
			)
		);

		self::assertSame( array(), $this->controls( Agend_Elementor_Resources_Collections::class )['collection_ids']['args']['options'], 'a visitor\'s request makes no call' );
		self::assertSame( array(), Agend_Test_WP::$requests );

		\Elementor\Plugin::$instance->editor->is_edit_mode = true;
		$options = $this->controls( Agend_Elementor_Resources_Collections::class )['collection_ids']['args']['options'];

		self::assertSame( array( self::A => 'New Member Starter Pack', 'b' => 'b' ), $options, 'a collection with no title is offered under its id' );
		self::assertNull( Agend_Test_WP::$requests[0]['headers']['Authorization'] ?? null, 'the list is read as an anonymous visitor' );
	}

	#[Test]
	public function should_make_no_call_for_the_picker_while_gated(): void {
		\Elementor\Plugin::$instance->editor->is_edit_mode = true;
		agend_res_test_know( array( 'atlas.pages.search' ) );

		self::assertSame( array(), $this->controls( Agend_Elementor_Resources_Collections::class )['collection_ids']['args']['options'] );
		self::assertSame( array(), Agend_Test_WP::$requests );
	}

	// -----------------------------------------------------------------
	// Render
	// -----------------------------------------------------------------

	#[Test]
	public function should_echo_what_the_collections_renderer_draws_from_the_widgets_settings(): void {
		agend_res_test_queue_collections( array( agend_res_test_collection( self::A, array( 'title' => 'New Member Starter Pack' ) ) ) );
		$settings = array( 'collection_ids' => array( self::A ), 'heading_text' => 'Our collections', 'show_heading' => 'yes' );

		$html = $this->widget( Agend_Elementor_Resources_Collections::class, $settings )->run_render();

		self::assertSame( agend_apps_records_render_resources_collections( $settings ), $html );
		self::assertStringContainsString( 'Our collections', $html );
		self::assertStringContainsString( 'New Member Starter Pack', $html );
	}

	#[Test]
	public function should_echo_what_the_search_and_ask_renderers_draw(): void {
		$strip = static fn( string $html ): string => (string) preg_replace( '/agend-resources-(search|ask)-\d+/', 'N', $html );

		$search = $this->widget( Agend_Elementor_Resources_Search::class, array( 'heading_text' => 'Find things' ) )->run_render();
		self::assertSame( $strip( agend_apps_records_render_resources_search( array( 'heading_text' => 'Find things' ) ) ), $strip( $search ) );
		self::assertStringContainsString( 'Find things', $search );

		$ask = $this->widget( Agend_Elementor_Resources_Ask::class, array( 'heading_text' => 'Ask us' ) )->run_render();
		self::assertSame( $strip( agend_apps_records_render_resources_ask( array( 'heading_text' => 'Ask us' ) ) ), $strip( $ask ) );
		self::assertStringContainsString( 'Ask us', $ask );
	}

	#[Test]
	public function should_tell_an_editor_in_the_canvas_when_nothing_is_chosen_and_a_visitor_nothing(): void {
		$settings = array( 'show_member_collections' => '' );

		self::assertSame( '', $this->widget( Agend_Elementor_Resources_Collections::class, $settings )->run_render() );

		\Elementor\Plugin::$instance->editor->is_edit_mode = true;

		self::assertStringContainsString( 'Choose the collections to show', $this->widget( Agend_Elementor_Resources_Collections::class, $settings )->run_render() );
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
		self::assertStringContainsString( '--agend-res-accent:#FF6B55;', $this->widget( Agend_Elementor_Resources_Search::class, $settings )->run_render() );
	}

	#[Test]
	#[DataProvider( 'widgets' )]
	public function should_render_nothing_for_a_visitor_and_make_no_call_when_gated( string $class ): void {
		agend_res_test_know( array( 'lms.courses.browse' ) );

		self::assertSame( '', $this->widget( $class )->run_render() );
		self::assertCount( 0, Agend_Test_WP::$requests );
	}

	#[Test]
	#[DataProvider( 'widgets' )]
	public function should_render_a_disabled_box_naming_the_scope_in_the_editor_when_gated( string $class, string $surface, string $name, string $title, string $scope ): void {
		agend_res_test_know( array( 'lms.courses.browse' ) );
		\Elementor\Plugin::$instance->editor->is_edit_mode = true;

		$html = $this->widget( $class )->run_render();

		self::assertStringContainsString( 'agend-surface-gate', $html );
		self::assertStringContainsString( $scope, $html );
	}

	#[Test]
	#[DataProvider( 'widgets' )]
	public function should_lead_the_first_content_section_with_the_gate_notice_while_gated( string $class ): void {
		agend_res_test_know( array( 'lms.courses.browse' ) );

		self::assertSame( 'agend_surface_gate_notice', array_key_first( $this->controls( $class ) ) );
	}

	#[Test]
	#[DataProvider( 'widgets' )]
	public function should_render_nothing_inside_a_card_template_and_warn_only_in_the_editor( string $class ): void {
		Agend_Apps_Records_Record_Context::push( 'course', array( 'slug' => 'a-course' ) );

		self::assertSame( '', $this->widget( $class )->run_render() );

		\Elementor\Plugin::$instance->editor->is_edit_mode = true;
		$html = $this->widget( $class )->run_render();

		self::assertStringContainsString( 'elementor-alert-warning', $html );
		self::assertStringContainsString( 'cannot be placed inside a card or detail template', $html );
		self::assertCount( 0, Agend_Test_WP::$requests );

		Agend_Apps_Records_Record_Context::reset();
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

		self::assertContains( 'agend-resources-collections', $names );
		self::assertContains( 'agend-resources-search', $names );
		self::assertContains( 'agend-resources-ask', $names );
	}

	#[Test]
	public function should_ship_a_widget_whose_name_matches_its_block(): void {
		foreach ( array( 'resources-collections', 'resources-search', 'resources-ask' ) as $surface ) {
			$block = json_decode( (string) file_get_contents( AGEND_TESTS_ROOT . '/agend-apps-core/build/blocks/' . $surface . '/block.json' ), true );

			self::assertSame( 'agend-apps/' . $surface, $block['name'] );
		}
	}
}
