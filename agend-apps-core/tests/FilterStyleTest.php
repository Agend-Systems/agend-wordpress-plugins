<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\Test;

#[CoversFunction( 'agend_apps_records_filter_style' )]
final class FilterStyleTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/palette.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/filters.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/filter.php';
	}

	#[Test]
	public function should_add_nothing_when_no_style_setting_is_set(): void {
		$this->assertSame( array( 'classes' => array(), 'style' => '' ), agend_apps_records_filter_style( array( 'field_border_sides' => 'bottom' ) ) );
	}

	#[Test]
	public function should_expose_each_set_value_as_a_property_and_a_class(): void {
		$style = agend_apps_records_filter_style(
			array(
				'field_background'   => '#FFFFFF',
				'field_border_width' => '2',
				'field_border_sides' => 'bottom',
				'field_border_colour' => '#3366AA',
				'field_height'       => '40',
				'field_radius'       => '0',
			)
		);

		$this->assertSame(
			array(
				'agend-filter--field-bg',
				'agend-filter--field-border-colour',
				'agend-filter--field-border-width',
				'agend-filter--field-radius',
				'agend-filter--field-height',
				'agend-filter--field-border-bottom',
			),
			$style['classes']
		);
		$this->assertSame( '--agend-filter-bg:#FFFFFF;--agend-filter-border-colour:#3366AA;--agend-filter-border-width:2px;--agend-filter-radius:0px;--agend-filter-height:40px', $style['style'] );
	}

	#[Test]
	public function should_drop_an_unsafe_colour_and_a_size_the_schema_does_not_offer(): void {
		$style = agend_apps_records_filter_style(
			array(
				'field_background' => 'red;background:url(x)',
				'field_height'     => '41',
				'button_radius'    => '999',
			)
		);

		$this->assertSame( array( 'agend-filter--button-radius' ), $style['classes'] );
		$this->assertSame( '--agend-filter-button-radius:999px', $style['style'] );
	}

	#[Test]
	public function should_expose_horizontal_padding(): void {
		$style = agend_apps_records_filter_style( array( 'field_padding' => '12' ) );

		$this->assertSame( array( 'agend-filter--field-padding' ), $style['classes'] );
		$this->assertSame( '--agend-filter-padding-x:12px', $style['style'] );
		$this->assertSame( array(), agend_apps_records_filter_style( array( 'field_padding' => '13' ) )['classes'] );
	}

	#[Test]
	public function should_ignore_border_sides_without_a_border_width(): void {
		$style = agend_apps_records_filter_style( array( 'field_border_colour' => '#000', 'field_border_sides' => 'bottom' ) );

		$this->assertNotContains( 'agend-filter--field-border-bottom', $style['classes'] );
	}
}
