<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Elementor;

use Agend_Elementor_Schema_Controls;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor-schema-controls.php';

/**
 * Unit tests for Agend_Elementor_Schema_Controls that are not already pinned
 * by a widget's Content-tab controls fixture: the `colour` field type
 * (US-1.1), the unknown-type fallthrough, and the tab-to-section mapping.
 */
#[CoversClass( Agend_Elementor_Schema_Controls::class )]
final class SchemaControlsTest extends TestCase {

	#[Test]
	public function should_map_a_colour_field_to_the_elementor_color_control_when_given_a_colour_type(): void {
		$args = Agend_Elementor_Schema_Controls::control_args(
			array(
				'name'        => 'accent_colour',
				'label'       => 'Highlight / accent colour',
				'type'        => 'colour',
				'default'     => '#FF6B55',
				'description' => 'Drives active filter state.',
				'condition'   => array( 'inherit_colours!' => 'yes' ),
			)
		);

		self::assertSame(
			array(
				'label'       => 'Highlight / accent colour',
				'type'        => \Elementor\Controls_Manager::COLOR,
				'default'     => '#FF6B55',
				'description' => 'Drives active filter state.',
				'condition'   => array( 'inherit_colours!' => 'yes' ),
			),
			$args
		);
	}

	#[Test]
	public function should_return_an_empty_array_when_given_an_unknown_field_type(): void {
		$args = Agend_Elementor_Schema_Controls::control_args(
			array(
				'name'    => 'future_field',
				'label'   => 'A field type this adapter does not know yet',
				'type'    => 'font-family-picker',
				'default' => 'sans-serif',
			)
		);

		self::assertSame( array(), $args );
	}

	#[Test]
	public function should_start_a_style_tab_section_when_the_schema_section_declares_tab_style(): void {
		$widget = new \Elementor\Widget_Base();

		Agend_Elementor_Schema_Controls::register(
			$widget,
			array(
				'sections' => array(
					array(
						'id'     => 'section_style_colours',
						'label'  => 'Colours',
						'tab'    => 'style',
						'fields' => array(),
					),
				),
			)
		);

		self::assertSame( \Elementor\Controls_Manager::TAB_STYLE, $widget->recordings[0]['args']['tab'] );
	}

	#[Test]
	public function should_start_a_content_tab_section_when_the_schema_section_omits_tab(): void {
		$widget = new \Elementor\Widget_Base();

		Agend_Elementor_Schema_Controls::register(
			$widget,
			array(
				'sections' => array(
					array(
						'id'     => 'section_heading',
						'label'  => 'Heading',
						'fields' => array(),
					),
				),
			)
		);

		self::assertSame( \Elementor\Controls_Manager::TAB_CONTENT, $widget->recordings[0]['args']['tab'] );
	}

	#[Test]
	public function should_skip_a_field_whose_control_args_is_empty_when_registering_a_section(): void {
		$widget = new \Elementor\Widget_Base();

		Agend_Elementor_Schema_Controls::register(
			$widget,
			array(
				'sections' => array(
					array(
						'id'     => 'section_mixed',
						'label'  => 'Mixed',
						'fields' => array(
							array(
								'name'    => 'future_field',
								'type'    => 'font-family-picker',
								'default' => 'sans-serif',
							),
							array(
								'name'    => 'show_heading',
								'type'    => 'toggle',
								'default' => true,
							),
						),
					),
				),
			)
		);

		$control_ids = array_column(
			array_filter( $widget->recordings, static fn( array $entry ): bool => 'add_control' === $entry['method'] ),
			'id'
		);

		self::assertSame( array( 'show_heading' ), $control_ids, 'the unknown-type field registers no control' );
	}
}
