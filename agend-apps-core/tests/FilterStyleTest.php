<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend_Apps_Records_Filter_Context;
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
		Agend_Apps_Records_Filter_Context::reset();
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
	public function should_give_a_filter_with_a_height_the_hook_its_action_buttons_follow(): void {
		Agend_Apps_Records_Filter_Context::set( 'listing' );

		$html = agend_apps_records_render_filter( array( 'filter' => 'listing:location', 'field_height' => '48' ) );

		$this->assertMatchesRegularExpression( '/class="agend-filter [^"]*agend-filter--field-height"/', $html );
		$this->assertStringContainsString( 'style="--agend-filter-height:48px"', $html );
		$this->assertStringContainsString( 'agend-filter__location-search', $html );

		foreach ( array( '', '41', 'tall' ) as $value ) {
			$plain = agend_apps_records_render_filter( array( 'filter' => 'listing:location', 'field_height' => $value ) );
			$this->assertStringNotContainsString( 'agend-filter--field-height', $plain );
			$this->assertStringNotContainsString( '--agend-filter-height', $plain );
			$this->assertSame( agend_apps_records_render_filter( array( 'filter' => 'listing:location' ) ), $plain );
		}
	}

	#[Test]
	public function should_size_the_action_buttons_but_not_the_choice_buttons_in_the_stylesheet(): void {
		$css = (string) file_get_contents( AGEND_TESTS_ROOT . '/agend-apps-core/assets/css/filters.css' );

		preg_match_all( '/^(\.agend-filter\.agend-filter--field-height[^{]*)\{([^}]*)\}/m', $css, $rules, PREG_SET_ORDER );
		$buttons = array_filter(
			$rules,
			static fn( array $rule ): bool => false !== strpos( $rule[1], 'button' ) || false !== strpos( $rule[1], '__reset' )
		);

		$this->assertCount( 1, $buttons, 'one rule carries the height to the action buttons' );
		$rule = reset( $buttons );

		foreach ( array( '.agend-filter__location-search', '.agend-filter__location-locate', '.agend-filter__reset', '.agend-filter__share', '.agend-filter__view .agend-filter__button' ) as $selector ) {
			$this->assertStringContainsString( $selector, $rule[1] );
		}
		$this->assertStringContainsString( 'height: var(--agend-filter-height)', $rule[2] );

		// What is left once the view switch's buttons are accounted for must
		// not name a bare button: the Buttons presentation's choices stay as
		// they were.
		$this->assertStringNotContainsString( '.agend-filter__button', str_replace( '.agend-filter__view .agend-filter__button', '', $rule[1] ) );
	}

	#[Test]
	public function should_start_the_results_level_with_a_right_hand_filter_column(): void {
		$css = (string) file_get_contents( AGEND_TESTS_ROOT . '/agend-apps-core/assets/css/filters.css' );

		// The slot comes first in the markup but sits in column two, so only
		// dense placement lets the results back into the first row.
		$this->assertMatchesRegularExpression( '/^\.agend-filters-right \{[^}]*grid-auto-flow: row dense;/m', $css );
		$this->assertDoesNotMatchRegularExpression( '/^\.agend-filters-left \{[^}]*grid-auto-flow/m', $css );
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
