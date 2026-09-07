<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;

/**
 * The block is an adapter over the schema and the core renderer. These tests
 * pin the seam between block-shaped attributes and Elementor-shaped settings,
 * and prove a block left at its defaults renders exactly what the Elementor
 * widget renders at its defaults.
 */
#[RunTestsInSeparateProcesses]
final class BlockSurfaceTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/tests/render-doubles.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/palette.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/events-catalogue.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/blocks.php';
		agend_render_test_reset();
	}

	#[Test]
	public function should_derive_typed_attributes_with_defaults_when_given_the_events_schema(): void {
		$attributes = agend_apps_records_block_attributes( agend_apps_records_surface_schema( 'events-catalogue' ) );

		self::assertSame( array( 'type' => 'boolean', 'default' => true ), $attributes['show_heading'] );
		self::assertSame( array( 'type' => 'boolean', 'default' => false ), $attributes['multi_category_filter'], 'a literal "no" default is an off toggle' );
		self::assertSame( array( 'type' => 'string', 'default' => '3' ), $attributes['columns_desktop'] );
		self::assertSame( array( 'type' => 'number', 'default' => 9 ), $attributes['per_page'] );
		self::assertSame( 'array', $attributes['exclude_categories']['type'] );
		self::assertSame( array(), $attributes['exclude_categories']['default'] );
		self::assertArrayNotHasKey( 'exclusions_note', $attributes, 'notes store no value' );
		self::assertArrayNotHasKey( 'heading_search', $attributes, 'headings store no value' );
	}

	#[Test]
	public function should_convert_toggles_to_the_elementor_convention_when_building_settings_from_attributes(): void {
		$schema   = agend_apps_records_surface_schema( 'events-catalogue' );
		$settings = agend_apps_records_settings_from_attributes( $schema, array( 'show_heading' => false, 'show_image' => true, 'per_page' => 12, 'unknown' => 'kept' ) );

		self::assertSame( '', $settings['show_heading'] );
		self::assertSame( 'yes', $settings['show_image'] );
		self::assertSame( 12, $settings['per_page'] );
		self::assertSame( 'kept', $settings['unknown'] );
	}

	#[Test]
	public function should_render_what_the_elementor_widget_renders_at_its_control_defaults_when_the_block_is_left_at_its_defaults(): void {
		$schema   = agend_apps_records_surface_schema( 'events-catalogue' );
		$defaults = array_map( static fn( array $a ) => $a['default'], agend_apps_records_block_attributes( $schema ) );

		// The Elementor control defaults, as recorded from the widget before
		// the schema existed: what get_settings_for_display() hands the widget
		// on a page where nothing was changed.
		$controls = json_decode( (string) file_get_contents( AGEND_TESTS_ROOT . '/agend-elementor/tests/fixtures/events-catalogue-content-controls.json' ), true );
		$elementor_settings = array();
		foreach ( $controls['sections'] as $section ) {
			foreach ( $section['controls'] as $control ) {
				if ( array_key_exists( 'default', $control['args'] ) ) {
					$elementor_settings[ $control['id'] ] = $control['args']['default'];
				}
			}
		}

		self::assertSame( agend_apps_records_render_events_catalogue( $elementor_settings ), agend_apps_records_render_block( 'events-catalogue', $defaults ) );
		self::assertStringContainsString( 'Upcoming Events', agend_apps_records_render_block( 'events-catalogue', $defaults ) );
	}

	#[Test]
	public function should_resolve_option_callables_and_boolean_toggle_defaults_when_serving_the_editor_schema(): void {
		$schema = agend_apps_records_block_editor_schema( 'events-catalogue' );
		$fields = array();
		foreach ( $schema['sections'] as $section ) {
			foreach ( $section['fields'] as $field ) {
				$fields[ $field['name'] ] = $field;
			}
		}

		self::assertIsArray( $fields['exclude_categories']['options'], 'the callable option source is resolved to a list' );
		self::assertTrue( $fields['show_heading']['default'] );
		self::assertFalse( $fields['multi_category_filter']['default'] );
		self::assertSame( array( '' => 'Built-in card', '7' => 'Card', '8' => 'Filters' ), $fields['card_template']['options'] );
	}

	#[Test]
	public function should_derive_a_string_attribute_with_the_field_default_when_given_a_colour_field(): void {
		$schema = array(
			'sections' => array(
				array(
					'id'     => 'section_style_colours',
					'label'  => 'Colours',
					'tab'    => 'style',
					'fields' => array(
						array(
							'name'    => 'accent_colour',
							'label'   => 'Accent colour',
							'type'    => 'colour',
							'default' => '#FF6B55',
						),
					),
				),
			),
		);

		$attributes = agend_apps_records_block_attributes( $schema );

		self::assertSame( array( 'type' => 'string', 'default' => '#FF6B55' ), $attributes['accent_colour'] );
	}

	#[Test]
	public function should_return_an_empty_schema_when_the_surface_is_unknown(): void {
		self::assertSame( array(), agend_apps_records_block_editor_schema( 'no-such-surface' ) );
	}

	#[Test]
	public function should_return_an_empty_string_when_rendering_a_surface_with_no_renderer(): void {
		self::assertSame( '', agend_apps_records_render_block( 'no-such-surface', array() ) );
	}

	/**
	 * Iterates every committed build/blocks/* directory rather than naming
	 * events-catalogue alone, so a later block (courses-catalogue) is covered
	 * by this assertion without a second test being written for it.
	 */
	#[Test]
	public function should_allow_several_instances_on_every_catalogue_block(): void {
		$build_dir = AGEND_TESTS_ROOT . '/agend-apps-core/build/blocks';
		$block_dirs = glob( $build_dir . '/*', GLOB_ONLYDIR );

		self::assertNotEmpty( $block_dirs, 'expected at least one committed block build' );

		foreach ( $block_dirs as $block_dir ) {
			$block = json_decode( (string) file_get_contents( $block_dir . '/block.json' ), true );
			$multiple = $block['supports']['multiple'] ?? true;

			self::assertNotFalse( $multiple, basename( $block_dir ) . ' should allow several instances on one page' );
		}
	}

	#[Test]
	public function should_insert_the_agend_category_before_widgets(): void {
		$categories = array(
			array( 'slug' => 'text', 'title' => 'Text' ),
			array( 'slug' => 'widgets', 'title' => 'Widgets' ),
			array( 'slug' => 'theme', 'title' => 'Theme' ),
		);

		$result = agend_apps_records_block_categories( $categories );
		$slugs  = array_column( $result, 'slug' );

		self::assertSame( array( 'text', 'agend', 'widgets', 'theme' ), $slugs );
	}

	#[Test]
	public function should_not_duplicate_the_agend_category_when_called_more_than_once_per_request(): void {
		$categories = array( array( 'slug' => 'widgets', 'title' => 'Widgets' ) );

		$once  = agend_apps_records_block_categories( $categories );
		$twice = agend_apps_records_block_categories( $once );

		self::assertCount( 1, array_filter( $twice, static fn( array $c ): bool => 'agend' === $c['slug'] ) );
	}

	#[Test]
	public function should_append_the_agend_category_when_widgets_is_absent(): void {
		$categories = array( array( 'slug' => 'text', 'title' => 'Text' ) );

		$result = agend_apps_records_block_categories( $categories );

		self::assertSame( 'agend', $result[ count( $result ) - 1 ]['slug'] );
	}
}
