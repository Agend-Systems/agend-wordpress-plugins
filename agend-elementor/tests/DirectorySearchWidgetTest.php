<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Elementor;

use Agend_Elementor_Directory_Search;
use Agend_Elementor_Schema_Controls;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/palette.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/settings.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/directory-search.php';
require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor-schema-controls.php';
require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor-field-widget-trait.php';
require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/widgets/class-agend-elementor-directory-search.php';
require_once __DIR__ . '/Content_Tab_Recording_Trait.php';

/**
 * Exposes the protected methods a test drives directly.
 */
final class Directory_Search_Test_Harness extends Agend_Elementor_Directory_Search {
	public function run(): void {
		$this->register_controls();
	}

	public function run_render(): string {
		ob_start();
		$this->render();

		return (string) ob_get_clean();
	}
}

/**
 * The Agend Directory Search widget: Content controls come from the core
 * schema (pinned by the fixture), the Style tab is declared here, and the
 * markup is the core renderer's.
 */
#[CoversClass( Agend_Elementor_Schema_Controls::class )]
final class DirectorySearchWidgetTest extends TestCase {

	use Content_Tab_Recording_Trait;

	private function controls(): Directory_Search_Test_Harness {
		$widget = new Directory_Search_Test_Harness();
		$widget->run();

		return $widget;
	}

	#[Test]
	public function should_register_the_content_tab_controls_the_surface_schema_declares(): void {
		$fixture = json_decode( file_get_contents( __DIR__ . '/fixtures/directory-search-content-controls.json' ), true );

		$this->assertEquals( $fixture, $this->contentTabRecordings( $this->controls()->recordings ) );
	}

	#[Test]
	public function should_be_named_for_the_operator_to_place(): void {
		$widget = new Agend_Elementor_Directory_Search();

		$this->assertSame( 'agend-directory-search', $widget->get_name() );
		$this->assertSame( 'Agend Directory Search', $widget->get_title() );
	}

	/** @return array<string, array{string, string}> Control id => the selector it writes under. */
	public static function styled_parts(): array {
		return array(
			'panel background' => array( 'panel_background', '.agend-ds__form' ),
			'panel padding'    => array( 'panel_padding', '.agend-ds__form' ),
			'panel radius'     => array( 'panel_radius', '.agend-ds__form' ),
			'field background' => array( 'field_background', '.agend-ds__field' ),
			'field text'       => array( 'field_text_colour', '.agend-ds__input' ),
			'placeholder'      => array( 'field_placeholder_colour', '.agend-ds__input::placeholder' ),
			'field icon'       => array( 'field_icon_colour', '.agend-ds__field .agend-ds__icon' ),
			'field height'     => array( 'field_height', '.agend-ds__field' ),
			'field radius'     => array( 'field_radius', '.agend-ds__field' ),
			'button radius'    => array( 'button_radius', '.agend-ds__button' ),
			'button padding'   => array( 'button_padding', '.agend-ds__button' ),
		);
	}

	#[DataProvider( 'styled_parts' )]
	#[Test]
	public function should_style_each_part_through_a_selector_under_the_widget_wrapper( string $control, string $selector ): void {
		$args = $this->recordedArgs( $this->controls()->recordings, $control );

		$this->assertArrayHasKey( '{{WRAPPER}} ' . $selector, $args['selectors'] );
	}

	#[Test]
	public function should_offer_typography_for_the_fields_the_button_and_the_locate_link(): void {
		$recordings = $this->controls()->recordings;

		foreach ( array( 'field_typography' => '.agend-ds__input', 'button_typography' => '.agend-ds__button', 'locate_typography' => '.agend-ds__locate' ) as $name => $selector ) {
			$this->assertSame( '{{WRAPPER}} ' . $selector, $this->recordedArgs( $recordings, $name )['selector'] );
		}
	}

	#[Test]
	public function should_write_the_colours_a_theme_fights_to_custom_properties_the_stylesheet_reads(): void {
		$recordings = $this->controls()->recordings;

		foreach ( array(
			'button_text_colour'       => '--agend-ds-btn-text',
			'button_background'        => '--agend-ds-btn-bg',
			'button_text_colour_hover' => '--agend-ds-btn-text-hover',
			'button_background_hover'  => '--agend-ds-btn-bg-hover',
			'locate_colour'            => '--agend-ds-locate',
			'locate_colour_hover'      => '--agend-ds-locate-hover',
		) as $control => $property ) {
			$this->assertSame(
				array( '{{WRAPPER}} .agend-ds' => $property . ': {{VALUE}};' ),
				$this->recordedArgs( $recordings, $control )['selectors']
			);
		}
	}

	#[Test]
	public function should_let_the_layout_go_side_by_side_or_stacked_per_device(): void {
		$args = $this->recordedArgs( $this->controls()->recordings, 'layout_direction' );

		$this->assertSame( array( 'row', 'column' ), array_keys( $args['options'] ) );
		$this->assertStringContainsString( 'flex-direction: column', $args['selectors_dictionary']['column'] );
		$this->assertSame( array( '{{WRAPPER}} .agend-ds__form' => '{{VALUE}}' ), $args['selectors'] );
	}

	#[Test]
	public function should_render_what_the_core_renderer_renders(): void {
		$settings = array( 'button_text' => 'Find', 'show_locate' => '' );
		$widget   = new Directory_Search_Test_Harness( array(), null, $settings );

		$this->assertSame( agend_apps_records_render_directory_search( $settings ), $widget->run_render() );
	}
}
