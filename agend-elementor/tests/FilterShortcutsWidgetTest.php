<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Elementor;

use Agend_Elementor_Filter_Shortcuts;
use Agend_Elementor_Schema_Controls;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/palette.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/settings.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/filter-shortcuts.php';
require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor-schema-controls.php';
require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor-field-widget-trait.php';
require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/widgets/class-agend-elementor-filter-shortcuts.php';
require_once __DIR__ . '/Content_Tab_Recording_Trait.php';

/**
 * Exposes the protected methods a test drives directly.
 */
final class Filter_Shortcuts_Test_Harness extends Agend_Elementor_Filter_Shortcuts {
	public function run(): void {
		$this->register_controls();
	}

	// Elementor's icon library is not loaded in the unit suite, so an icon
	// font element named by the picker value stands in for what it draws.
	protected function icon_html( $icon ): string {
		return is_array( $icon ) && ! empty( $icon['value'] ) ? '<i class="' . $icon['value'] . '" aria-hidden="true"></i>' : '';
	}

	public function run_render(): string {
		ob_start();
		$this->render();

		return (string) ob_get_clean();
	}
}

/**
 * The Agend Filter Shortcuts widget: Content controls come from the core
 * schema plus the two adapter controls (pinned by the fixture), the Style tab
 * is declared here, and the markup is the core renderer's with the icon picker
 * values turned into markup.
 */
#[CoversClass( Agend_Elementor_Schema_Controls::class )]
final class FilterShortcutsWidgetTest extends TestCase {

	use Content_Tab_Recording_Trait;

	private function controls(): Filter_Shortcuts_Test_Harness {
		$widget = new Filter_Shortcuts_Test_Harness();
		$widget->run();

		return $widget;
	}

	#[Test]
	public function should_register_the_content_tab_controls_the_surface_schema_declares(): void {
		$fixture = json_decode( file_get_contents( __DIR__ . '/fixtures/filter-shortcuts-content-controls.json' ), true );

		$this->assertEquals( $fixture, $this->contentTabRecordings( $this->controls()->recordings ) );
	}

	#[Test]
	public function should_be_named_for_the_operator_to_place(): void {
		$widget = new Agend_Elementor_Filter_Shortcuts();

		$this->assertSame( 'agend-filter-shortcuts', $widget->get_name() );
		$this->assertSame( 'Agend Filter Shortcuts', $widget->get_title() );
	}

	#[Test]
	public function should_offer_an_icon_for_each_manual_tile_and_one_shared_icon(): void {
		$recordings = $this->controls()->recordings;
		$items      = $this->recordedArgs( $recordings, 'items' );

		$this->assertSame( array( 'label', 'value', 'icon' ), array_column( $items['fields'], 'name' ) );
		$this->assertSame( \Elementor\Controls_Manager::ICONS, $items['fields'][2]['type'] );
		$this->assertSame( array( 'source' => 'manual' ), $items['condition'] );
		$this->assertSame( \Elementor\Controls_Manager::ICONS, $this->recordedArgs( $recordings, 'all_icon' )['type'] );
		$this->assertSame( array( 'source' => 'all' ), $this->recordedArgs( $recordings, 'all_icon' )['condition'] );
	}

	/** @return array<string, array{string, string}> Control id => the selector it writes under. */
	public static function styled_parts(): array {
		return array(
			'gap'           => array( 'grid_gap', '.agend-fs__grid' ),
			'tile padding'  => array( 'tile_padding', '.agend-fs .agend-fs__tile' ),
			'tile radius'   => array( 'tile_radius', '.agend-fs .agend-fs__tile' ),
			'tile align'    => array( 'tile_align', '.agend-fs .agend-fs__tile' ),
			'icon size'     => array( 'icon_size', '.agend-fs__icon' ),
			'icon radius'   => array( 'icon_radius', '.agend-fs__icon' ),
		);
	}

	#[Test]
	#[DataProvider( 'styled_parts' )]
	public function should_style_each_part_through_a_selector_under_the_widget_wrapper( string $control, string $selector ): void {
		$args = $this->recordedArgs( $this->controls()->recordings, $control );

		$this->assertArrayHasKey( '{{WRAPPER}} ' . $selector, $args['selectors'] );
	}

	/** @return array<string, array{string, string}> Control id => the custom property it sets. */
	public static function state_colours(): array {
		return array(
			'background'        => array( 'tile_bg_normal', '--agend-fs-bg' ),
			'border'            => array( 'tile_border_normal', '--agend-fs-border' ),
			'text'              => array( 'tile_text_normal', '--agend-fs-text' ),
			'hover background'  => array( 'tile_bg_hover', '--agend-fs-bg-hover' ),
			'hover border'      => array( 'tile_border_hover', '--agend-fs-border-hover' ),
			'hover text'        => array( 'tile_text_hover', '--agend-fs-text-hover' ),
			'active background' => array( 'tile_bg_active', '--agend-fs-bg-active' ),
			'active border'     => array( 'tile_border_active', '--agend-fs-border-active' ),
			'active text'       => array( 'tile_text_active', '--agend-fs-text-active' ),
			'focus ring'        => array( 'tile_focus_ring', '--agend-fs-focus' ),
			'icon colour'       => array( 'icon_colour', '--agend-fs-icon' ),
			'icon background'   => array( 'icon_background', '--agend-fs-icon-bg' ),
		);
	}

	#[Test]
	#[DataProvider( 'state_colours' )]
	public function should_write_tile_colours_to_custom_properties_so_a_themes_link_colours_cannot_override_them( string $control, string $property ): void {
		$args = $this->recordedArgs( $this->controls()->recordings, $control );

		$this->assertSame( array( '{{WRAPPER}} .agend-fs' => $property . ': {{VALUE}};' ), $args['selectors'] );
	}

	#[Test]
	public function should_offer_typography_for_the_label_and_a_shadow_for_a_tile_and_its_hover(): void {
		$recordings = $this->controls()->recordings;

		$this->assertSame( '{{WRAPPER}} .agend-fs .agend-fs__label', $this->recordedArgs( $recordings, 'label_typography' )['selector'] );
		$this->assertSame( '{{WRAPPER}} .agend-fs .agend-fs__tile', $this->recordedArgs( $recordings, 'tile_shadow' )['selector'] );
		$this->assertStringContainsString( ':hover', $this->recordedArgs( $recordings, 'tile_shadow_hover' )['selector'] );
	}

	#[Test]
	public function should_render_the_core_markup_with_each_icon_picker_value_drawn(): void {
		$settings = array(
			'filter'   => 'state',
			'all_icon' => array( 'value' => 'fas fa-globe' ),
			'items'    => array(
				array( 'label' => 'NSW', 'value' => 'NSW', 'icon' => array( 'value' => 'fas fa-map' ) ),
				array( 'label' => 'VIC', 'value' => 'VIC', 'icon' => array( 'value' => '' ) ),
			),
		);
		$widget   = new Filter_Shortcuts_Test_Harness( array(), null, $settings );

		$html = $widget->run_render();

		$this->assertStringContainsString( '<span class="agend-fs__icon" aria-hidden="true"><i class="fas fa-map" aria-hidden="true"></i></span><span class="agend-fs__label">NSW</span>', $html );
		$this->assertStringContainsString( '<span class="agend-fs__label">VIC</span>', $html );
		$this->assertSame( 1, substr_count( $html, 'agend-fs__icon' ) );
	}
}
