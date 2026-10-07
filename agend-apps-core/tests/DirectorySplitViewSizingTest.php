<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;

/**
 * The two settings that size the directory's split view: the width of a side
 * filter column, and a height cap that makes the results list scroll inside
 * itself. Both write nothing until they are set, so the recorded catalogue
 * markup in CatalogueRenderTest stays the proof that a catalogue that leaves
 * them alone is unchanged.
 *
 * Each case runs in its own process: the render doubles define the gateway
 * list functions, and other tests in this suite assert those do NOT exist.
 */
#[RunTestsInSeparateProcesses]
#[CoversFunction( 'agend_apps_records_directory_catalogue_filter_column' )]
#[CoversFunction( 'agend_apps_records_directory_catalogue_choice' )]
#[CoversFunction( 'agend_apps_records_directory_catalogue_inline_style' )]
#[CoversFunction( 'agend_apps_records_directory_catalogue_scroll_class' )]
final class DirectorySplitViewSizingTest extends TestCase {

	private const DEFAULT_STYLE = '--agend-dir-heading:#1E2A4A;--agend-dir-body:#26304D;--agend-dir-accent:#FF6B55;--agend-dir-button:#FF6B55;--agend-dir-button-text:#FFFFFF;--agend-dir-card-radius:10px;';

	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/tests/render-doubles.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/palette.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/directory-catalogue.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/blocks.php';
		agend_render_test_reset();
	}

	/**
	 * The choices the editor offers, pinned here so a change to either list is
	 * a deliberate one.
	 *
	 * @return array<string, array{string}>
	 */
	public static function filter_columns(): array {
		$cases = array();
		foreach ( array( '25%', '33%', '40%', '50%', '60%', '67%', '70%', '75%' ) as $value ) {
			$cases[ $value ] = array( $value );
		}
		return $cases;
	}

	/** @return array<string, array{string}> */
	public static function results_heights(): array {
		$cases = array();
		foreach ( array( '480px', '560px', '640px', '720px', '800px', '880px', '960px', '1040px' ) as $value ) {
			$cases[ $value ] = array( $value );
		}
		return $cases;
	}

	/** @return array<string, array{mixed}> */
	public static function values_outside_the_lists(): array {
		return array(
			'a bare number'          => array( '33' ),
			'an integer'             => array( 33 ),
			'a share not offered'    => array( '45%' ),
			'a full width'           => array( '100%' ),
			'a pixel width'          => array( '300px' ),
			'a height not offered'   => array( '500px' ),
			'a declaration smuggled' => array( '33%;background:red' ),
			'a function'             => array( 'calc(100% - 1px)' ),
			'an array'               => array( array( '33%' ) ),
			'null'                   => array( null ),
			'whitespace'             => array( ' ' ),
		);
	}

	#[Test]
	public function should_offer_an_unset_default_first_in_both_lists(): void {
		self::assertSame( '', array_key_first( agend_apps_records_directory_catalogue_filter_column_options() ) );
		self::assertSame( '', array_key_first( agend_apps_records_directory_catalogue_results_height_options() ) );
	}

	#[Test]
	public function should_write_nothing_new_to_the_config_or_the_markup_when_neither_setting_is_set(): void {
		$config = agend_apps_records_directory_catalogue_build_config( array() );

		self::assertSame( array( 'style', 'desktop', 'tablet', 'mobile', 'cardRadius' ), array_keys( $config['layout'] ) );
		self::assertSame( self::DEFAULT_STYLE, agend_apps_records_directory_catalogue_inline_style( $config ) );
		self::assertSame( '', agend_apps_records_directory_catalogue_scroll_class( $config ) );

		foreach ( array( array(), array( 'card_template' => '7', 'filter_template' => '8', 'filter_position' => 'right' ) ) as $settings ) {
			$html = agend_apps_records_render_directory_catalogue( $settings );

			self::assertStringNotContainsString( '--agend-filter-column', $html );
			self::assertStringNotContainsString( '--agend-dir-results-max-height', $html );
			self::assertStringNotContainsString( 'scroll-results', $html );
		}
	}

	#[Test]
	#[DataProvider( 'filter_columns' )]
	public function should_set_the_filter_column_on_the_root_when_a_side_filter_template_has_a_width( string $width ): void {
		foreach ( array( 'left', 'right' ) as $position ) {
			$settings = array( 'filter_template' => '8', 'filter_position' => $position, 'filter_column_width' => $width );
			$config   = agend_apps_records_directory_catalogue_build_config( $settings );

			self::assertSame( $width, $config['layout']['filterColumn'] );
			self::assertSame( self::DEFAULT_STYLE . '--agend-filter-column:' . $width . ';', agend_apps_records_directory_catalogue_inline_style( $config ) );
		}
	}

	#[Test]
	public function should_put_the_filter_column_on_the_root_of_both_render_paths(): void {
		$legacy    = agend_apps_records_render_directory_catalogue( array( 'filter_template' => '8', 'filter_position' => 'left', 'filter_column_width' => '33%' ) );
		$templated = agend_apps_records_render_directory_catalogue( array( 'card_template' => '7', 'filter_template' => '8', 'filter_position' => 'right', 'filter_column_width' => '50%' ) );

		self::assertStringContainsString( 'class="agend-directory-catalogue" style="' . self::DEFAULT_STYLE . '--agend-filter-column:33%;"', $legacy );
		self::assertStringContainsString( 'agend-filters-right" style="' . self::DEFAULT_STYLE . '--agend-filter-column:50%;"', $templated );
	}

	#[Test]
	public function should_ignore_the_filter_column_without_a_side_filter_template(): void {
		$cases = array(
			'filters across the top'    => array( 'filter_template' => '8', 'filter_position' => 'top' ),
			'no position set'           => array( 'filter_template' => '8' ),
			'an unknown position'       => array( 'filter_template' => '8', 'filter_position' => 'bottom' ),
			'an empty filter template'  => array( 'filter_template' => '', 'filter_position' => 'left' ),
			'no filter template at all' => array( 'filter_position' => 'right' ),
		);

		foreach ( $cases as $label => $settings ) {
			$config = agend_apps_records_directory_catalogue_build_config( $settings + array( 'filter_column_width' => '33%' ) );

			self::assertArrayNotHasKey( 'filterColumn', $config['layout'], $label );
			self::assertSame( self::DEFAULT_STYLE, agend_apps_records_directory_catalogue_inline_style( $config ), $label );
		}
	}

	#[Test]
	#[DataProvider( 'values_outside_the_lists' )]
	public function should_ignore_a_filter_column_outside_the_option_list( $value ): void {
		$config = agend_apps_records_directory_catalogue_build_config( array( 'filter_template' => '8', 'filter_position' => 'left', 'filter_column_width' => $value ) );

		self::assertArrayNotHasKey( 'filterColumn', $config['layout'] );
		self::assertSame( self::DEFAULT_STYLE, agend_apps_records_directory_catalogue_inline_style( $config ) );
	}

	#[Test]
	#[DataProvider( 'results_heights' )]
	public function should_cap_the_results_list_through_a_class_and_a_custom_property_when_a_height_is_set( string $height ): void {
		$config = agend_apps_records_directory_catalogue_build_config( array( 'results_max_height' => $height ) );

		self::assertSame( $height, $config['layout']['resultsMaxHeight'] );
		self::assertSame( self::DEFAULT_STYLE . '--agend-dir-results-max-height:' . $height . ';', agend_apps_records_directory_catalogue_inline_style( $config ) );
		self::assertSame( ' agend-directory-catalogue--scroll-results', agend_apps_records_directory_catalogue_scroll_class( $config ) );
	}

	#[Test]
	public function should_put_the_height_cap_on_the_root_of_both_render_paths_without_an_inline_max_height(): void {
		$legacy    = agend_apps_records_render_directory_catalogue( array( 'results_max_height' => '640px' ) );
		$templated = agend_apps_records_render_directory_catalogue( array( 'card_template' => '7', 'filter_template' => '8', 'filter_position' => 'left', 'results_max_height' => '640px' ) );

		self::assertStringContainsString( 'class="agend-directory-catalogue agend-directory-catalogue--scroll-results" style="' . self::DEFAULT_STYLE . '--agend-dir-results-max-height:640px;"', $legacy );
		self::assertStringContainsString( 'class="agend-directory-catalogue agend-directory-catalogue--templated agend-filters-left agend-directory-catalogue--scroll-results" style="' . self::DEFAULT_STYLE . '--agend-dir-results-max-height:640px;"', $templated );

		// The cap is only ever the custom property: a real max-height or overflow
		// declaration inline would apply on a phone too, and to nothing the
		// stylesheet can scope.
		foreach ( array( $legacy, $templated ) as $html ) {
			self::assertDoesNotMatchRegularExpression( '/(?<![-\w])max-height\s*:/', $html );
			self::assertStringNotContainsString( 'overflow', $html );
		}
	}

	#[Test]
	public function should_cap_the_results_list_whatever_the_filter_placement(): void {
		$config = agend_apps_records_directory_catalogue_build_config( array( 'filter_position' => 'top', 'results_max_height' => '480px' ) );

		self::assertSame( '480px', $config['layout']['resultsMaxHeight'] );
	}

	#[Test]
	#[DataProvider( 'values_outside_the_lists' )]
	public function should_ignore_a_results_height_outside_the_option_list( $value ): void {
		$config = agend_apps_records_directory_catalogue_build_config( array( 'results_max_height' => $value ) );

		self::assertArrayNotHasKey( 'resultsMaxHeight', $config['layout'] );
		self::assertSame( self::DEFAULT_STYLE, agend_apps_records_directory_catalogue_inline_style( $config ) );
		self::assertSame( '', agend_apps_records_directory_catalogue_scroll_class( $config ) );
	}

	#[Test]
	public function should_set_both_together_for_a_list_beside_filters_and_a_map(): void {
		$config = agend_apps_records_directory_catalogue_build_config(
			array( 'filter_template' => '8', 'filter_position' => 'right', 'filter_column_width' => '33%', 'results_max_height' => '720px' )
		);

		self::assertSame( self::DEFAULT_STYLE . '--agend-filter-column:33%;--agend-dir-results-max-height:720px;', agend_apps_records_directory_catalogue_inline_style( $config ) );
	}

	#[Test]
	public function should_declare_both_settings_in_the_schema_with_unset_defaults(): void {
		$fields = array();
		foreach ( agend_apps_records_surface_schema( 'directory-catalogue' )['sections'] as $section ) {
			foreach ( $section['fields'] as $field ) {
				$fields[ $field['name'] ?? '' ] = $field;
			}
		}

		foreach ( array( 'filter_column_width', 'results_max_height' ) as $name ) {
			self::assertSame( 'select', $fields[ $name ]['type'], $name );
			self::assertSame( '', $fields[ $name ]['default'], $name );
			self::assertIsString( $fields[ $name ]['options'], $name . ' names its option list so the render can sanitise against the same one' );
		}

		// A list condition and a not-empty condition are the two shapes both
		// editor adapters evaluate (Elementor natively, the block inspector in
		// conditionMet()).
		self::assertSame(
			array(
				'filter_template!' => '',
				'filter_position'  => array( 'left', 'right' ),
			),
			$fields['filter_column_width']['condition']
		);
		self::assertStringNotContainsString( 'on the filter template itself', $fields['filter_position']['description'] );
		self::assertStringContainsString( 'Filter column width', $fields['filter_position']['description'] );
	}

	#[Test]
	public function should_give_the_block_both_settings_as_string_attributes_and_render_them(): void {
		$attributes = agend_apps_records_block_attributes( agend_apps_records_surface_schema( 'directory-catalogue' ) );

		self::assertSame( array( 'type' => 'string', 'default' => '' ), $attributes['filter_column_width'] );
		self::assertSame( array( 'type' => 'string', 'default' => '' ), $attributes['results_max_height'] );

		$html = agend_apps_records_render_block( 'directory-catalogue', array( 'results_max_height' => '800px' ) );

		self::assertStringContainsString( '--agend-dir-results-max-height:800px;', $html );
		self::assertStringContainsString( 'agend-directory-catalogue--scroll-results', $html );
	}
}
