<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend_Apps_Records_Filter_Context;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/filters.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema.php';

/**
 * The Range brackets presentation of a custom field filter: the rules for an
 * author's bracket list, the resolution between Agend's brackets and the
 * author's, the config handed to the script, and the schema that exposes it.
 * The script's own copy of the rules is exercised by hand (the repo has no JS
 * test harness), so the pure functions here are the executable statement of
 * them.
 */
final class FilterRangeBracketsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Agend_Apps_Records_Filter_Context::reset();
	}

	// -------------------------------------------------------------------
	// Parsing the author's list
	// -------------------------------------------------------------------

	#[Test]
	public function should_parse_one_bracket_per_line_with_blank_bounds_open(): void {
		$parsed = agend_apps_records_filter_range_brackets_parse(
			"Under 10,000 | | 9999\n10,000 to 30,000 | 10000 | 30000\nOver 30,000 | 30001 |"
		);

		self::assertSame(
			array(
				array( 'label' => 'Under 10,000', 'min' => null, 'max' => 9999 ),
				array( 'label' => '10,000 to 30,000', 'min' => 10000, 'max' => 30000 ),
				array( 'label' => 'Over 30,000', 'min' => 30001, 'max' => null ),
			),
			$parsed['brackets']
		);
		self::assertSame( array(), $parsed['issues'] );
	}

	#[Test]
	public function should_keep_negative_and_decimal_bounds_and_whole_numbers_as_integers(): void {
		$parsed = agend_apps_records_filter_range_brackets_parse( "Below zero | | -0.5\nBand | 5.0 | 7.25\nOne value | 4 | 4" );

		self::assertSame( -0.5, $parsed['brackets'][0]['max'] );
		self::assertSame( 5, $parsed['brackets'][1]['min'], 'a whole number is an integer so the JSON reads 5, not 5.0' );
		self::assertSame( 7.25, $parsed['brackets'][1]['max'] );
		self::assertSame( 4, $parsed['brackets'][2]['min'], 'a minimum equal to the maximum is one value, and bounds are inclusive' );
		self::assertSame( 4, $parsed['brackets'][2]['max'] );
		self::assertSame( array(), $parsed['issues'] );
	}

	#[Test]
	public function should_ignore_blank_lines_and_read_any_line_ending_but_number_lines_as_written(): void {
		$parsed = agend_apps_records_filter_range_brackets_parse( "Low | | 5\r\n\r\nbroken\rHigh | 6 |\n" );

		self::assertSame( array( 'Low', 'High' ), array_column( $parsed['brackets'], 'label' ) );
		self::assertSame( array( array( 'line' => 3, 'code' => 'format' ) ), $parsed['issues'], 'the blank line still counts, so the number matches what the author sees' );
	}

	/** @return array<string, array{string, string}> */
	public static function malformedLines(): array {
		return array(
			'two parts, which would silently read as a minimum' => array( 'Under 10,000 | 9999', 'format' ),
			'four parts'                                       => array( 'Band | 1 | 2 | 3', 'format' ),
			'no separator'                                     => array( 'Under 10,000', 'format' ),
			'empty label'                                      => array( ' | 1 | 2', 'label' ),
			'label of only markup'                             => array( '<b></b> | 1 | 2', 'label' ),
			'word for a bound'                                 => array( 'Band | abc | 2', 'number' ),
			'thousands separator'                              => array( 'Band | 10,000 | 30000', 'number' ),
			'exponent'                                         => array( 'Band | 1e3 | ', 'number' ),
			'hex'                                              => array( 'Band | 0x10 | ', 'number' ),
			'a sign with no digits'                            => array( 'Band | - | 5', 'number' ),
			'a bound beyond the limit'                         => array( 'Band | 99999999999999999 | ', 'number' ),
			'both ends open'                                   => array( 'Everything | | ', 'open' ),
			'minimum above maximum'                            => array( 'Backwards | 9 | 1', 'order' ),
			'minimum above a negative maximum'                 => array( 'Backwards | 0 | -1', 'order' ),
		);
	}

	#[Test]
	#[DataProvider( 'malformedLines' )]
	public function should_skip_a_malformed_line_and_name_why( string $line, string $code ): void {
		$parsed = agend_apps_records_filter_range_brackets_parse( "Before | | 1\n" . $line . "\nAfter | 2 | " );

		self::assertSame( array( 'Before', 'After' ), array_column( $parsed['brackets'], 'label' ), 'the lines around it are unaffected' );
		self::assertSame( array( array( 'line' => 2, 'code' => $code ) ), $parsed['issues'] );
	}

	#[Test]
	public function should_use_at_most_twelve_brackets_and_report_the_first_line_dropped(): void {
		$lines = array();
		for ( $i = 1; $i <= 14; $i++ ) {
			$lines[] = 'Band ' . $i . ' | ' . $i . ' | ' . ( $i + 0.5 );
		}
		$parsed = agend_apps_records_filter_range_brackets_parse( implode( "\n", $lines ) );

		self::assertSame( 12, AGEND_APPS_RECORDS_RANGE_BRACKETS_MAX );
		self::assertCount( 12, $parsed['brackets'] );
		self::assertSame( 'Band 12', $parsed['brackets'][11]['label'] );
		self::assertSame( array( array( 'line' => 13, 'code' => 'limit' ) ), $parsed['issues'], 'one issue for the cut, not one per dropped line' );
	}

	#[Test]
	public function should_not_count_skipped_lines_towards_the_cap(): void {
		$lines = array( 'bad', 'worse | x | y', 'ugly | 5 | 1' );
		for ( $i = 1; $i <= 12; $i++ ) {
			$lines[] = 'Band ' . $i . ' | ' . $i . ' |';
		}
		$parsed = agend_apps_records_filter_range_brackets_parse( implode( "\n", $lines ) );

		self::assertCount( 12, $parsed['brackets'], 'twelve valid lines fit however many bad ones came first' );
		self::assertSame( array( 1, 2, 3 ), array_column( $parsed['issues'], 'line' ) );
	}

	#[Test]
	public function should_accept_exactly_twelve_without_a_notice(): void {
		$lines = array();
		for ( $i = 1; $i <= 12; $i++ ) {
			$lines[] = 'Band ' . $i . ' | ' . $i . ' |';
		}
		$parsed = agend_apps_records_filter_range_brackets_parse( implode( "\n", $lines ) );

		self::assertCount( 12, $parsed['brackets'] );
		self::assertSame( array(), $parsed['issues'] );
	}

	#[Test]
	public function should_read_nothing_from_a_value_that_is_not_text(): void {
		foreach ( array( '', '   ', "\n\n", null, array( 'Low | | 5' ), 12 ) as $raw ) {
			$parsed = agend_apps_records_filter_range_brackets_parse( $raw );
			self::assertSame( array(), $parsed['brackets'] );
			self::assertSame( array(), $parsed['issues'] );
		}
	}

	#[Test]
	public function should_keep_a_label_as_plain_text_that_a_less_than_sign_can_start(): void {
		$parsed = agend_apps_records_filter_range_brackets_parse( "<10,000 | | 9999\n<b>Over</b>   30,000\t| 30001 |" );

		self::assertSame( '<10,000', $parsed['brackets'][0]['label'], 'sanitize_text_field() would have turned this into &lt;10,000' );
		self::assertSame( 'Over 30,000', $parsed['brackets'][1]['label'], 'real tags go and runs of whitespace collapse' );
	}

	// -------------------------------------------------------------------
	// Bounds and Agend's presets
	// -------------------------------------------------------------------

	/** @return array<string, array{mixed, mixed}> */
	public static function bounds(): array {
		return array(
			'null is open'              => array( null, null ),
			'blank is open'             => array( '', null ),
			'spaces are open'           => array( '  ', null ),
			'integer'                   => array( 5, 5 ),
			'zero is a bound'           => array( 0, 0 ),
			'zero as text'              => array( '0', 0 ),
			'float'                     => array( 2.5, 2.5 ),
			'whole float'               => array( 3.0, 3 ),
			'numeric text'              => array( ' 42 ', 42 ),
			'negative text'             => array( '-7.5', -7.5 ),
			'a boolean is not a number' => array( true, false ),
			'an array is not a number'  => array( array( 1 ), false ),
			'NaN is not a number'       => array( NAN, false ),
			'infinity is not a number'  => array( INF, false ),
			'text'                      => array( 'ten', false ),
			'too large'                 => array( 1.0e16, false ),
		);
	}

	#[Test]
	#[DataProvider( 'bounds' )]
	public function should_read_a_bound_as_a_number_an_open_end_or_unusable( $raw, $expected ): void {
		self::assertSame( $expected, agend_apps_records_filter_range_brackets_bound( $raw ) );
	}

	#[Test]
	public function should_treat_a_null_or_missing_bound_in_a_facet_preset_as_open(): void {
		$presets = agend_apps_records_filter_range_brackets_presets(
			array(
				array( 'label' => 'Under 10,000', 'max' => 9999 ),
				array( 'label' => 'Middle', 'min' => 10000, 'max' => 30000 ),
				array( 'label' => 'Over 30,000', 'min' => 30001, 'max' => null ),
				array( 'label' => 'Text bounds', 'min' => '5', 'max' => '9' ),
			)
		);

		self::assertSame(
			array(
				array( 'label' => 'Under 10,000', 'min' => null, 'max' => 9999 ),
				array( 'label' => 'Middle', 'min' => 10000, 'max' => 30000 ),
				array( 'label' => 'Over 30,000', 'min' => 30001, 'max' => null ),
				array( 'label' => 'Text bounds', 'min' => 5, 'max' => 9 ),
			),
			$presets
		);
	}

	#[Test]
	public function should_drop_an_unusable_facet_preset_without_dropping_the_rest(): void {
		$presets = agend_apps_records_filter_range_brackets_presets(
			array(
				'not an object',
				null,
				array( 'min' => 1 ),
				array( 'label' => '', 'min' => 1 ),
				array( 'label' => 12, 'min' => 1 ),
				array( 'label' => 'Both open', 'min' => null, 'max' => null ),
				array( 'label' => 'Word', 'min' => 'low' ),
				array( 'label' => 'Backwards', 'min' => 9, 'max' => 1 ),
				array( 'label' => 'Kept', 'min' => 1 ),
			)
		);

		self::assertSame( array( array( 'label' => 'Kept', 'min' => 1, 'max' => null ) ), $presets );
	}

	#[Test]
	public function should_read_no_presets_from_a_facet_that_has_none(): void {
		foreach ( array( null, array(), 'x', 5, false ) as $value ) {
			self::assertSame( array(), agend_apps_records_filter_range_brackets_presets( $value ) );
		}
	}

	// -------------------------------------------------------------------
	// Which brackets a filter offers
	// -------------------------------------------------------------------

	/** @return array<string, array<int, mixed>> */
	public static function resolutions(): array {
		$agend  = array( array( 'label' => 'Agend low', 'min' => null, 'max' => 5 ) );
		$custom = array( array( 'label' => 'Custom low', 'min' => null, 'max' => 7 ) );

		return array(
			'agend, with presets'                            => array( 'agend', $agend, $custom, 'agend', $agend ),
			'agend, no presets, custom rows: the fallback'   => array( 'agend', array(), $custom, 'custom', $custom ),
			'agend, no presets, no custom rows: nothing'     => array( 'agend', array(), array(), 'none', array() ),
			'agend, presets, no custom rows'                 => array( 'agend', $agend, array(), 'agend', $agend ),
			'custom, with rows, ignoring Agend presets'      => array( 'custom', $agend, $custom, 'custom', $custom ),
			'custom, with rows, no presets'                  => array( 'custom', array(), $custom, 'custom', $custom ),
			'custom, no rows: no fallback to Agend'          => array( 'custom', $agend, array(), 'none', array() ),
			'custom, nothing at all'                         => array( 'custom', array(), array(), 'none', array() ),
			'an unknown source behaves as Agend'             => array( 'other', $agend, $custom, 'agend', $agend ),
		);
	}

	#[Test]
	#[DataProvider( 'resolutions' )]
	public function should_resolve_the_brackets_from_the_source_with_the_documented_fallback( string $source, array $presets, array $custom, string $origin, array $brackets ): void {
		self::assertSame(
			array( 'origin' => $origin, 'brackets' => $brackets ),
			agend_apps_records_filter_range_brackets_resolve( $source, $presets, $custom )
		);
	}

	// -------------------------------------------------------------------
	// The config the script reads
	// -------------------------------------------------------------------

	#[Test]
	public function should_hand_the_script_the_validated_rows_and_the_facet_to_read(): void {
		$config = agend_apps_records_filter_config(
			'listing',
			'custom_field',
			array(
				'custom_field_key'          => 'income',
				'control'                   => 'range_brackets',
				'range_brackets_source'     => 'custom',
				'range_brackets_display'    => 'radio',
				'range_brackets_any_label'  => 'Any income',
				'range_brackets_custom'     => "Under 10,000 | | 9999\nbroken line\nOver 30,000 | 30001 |",
			)
		);

		self::assertSame( 'range_brackets', $config['control'] );
		self::assertSame( 'custom_fields', $config['state'] );
		self::assertSame( 'map', $config['mode'] );
		self::assertSame( 'income', $config['fieldKey'] );
		self::assertSame( array( 'facet' => 'custom.income' ), $config['source'], 'the script reads Agend\'s presets from the same facet request as every other filter' );
		self::assertSame(
			array(
				'source'   => 'custom',
				'display'  => 'radio',
				'anyLabel' => 'Any income',
				'custom'   => array(
					array( 'label' => 'Under 10,000', 'min' => null, 'max' => 9999 ),
					array( 'label' => 'Over 30,000', 'min' => 30001, 'max' => null ),
				),
			),
			$config['rangeBrackets'],
			'the malformed line never reaches the page'
		);
		self::assertStringContainsString( '"min":null,"max":9999', (string) wp_json_encode( $config ), 'an open end is a JSON null' );
	}

	#[Test]
	public function should_default_to_agends_brackets_in_a_dropdown_labelled_any(): void {
		$config = agend_apps_records_filter_config( 'listing', 'custom_field', array( 'custom_field_key' => 'income', 'control' => 'range_brackets' ) );

		self::assertSame(
			array( 'source' => 'agend', 'display' => 'select', 'anyLabel' => 'Any', 'custom' => array() ),
			$config['rangeBrackets']
		);
	}

	#[Test]
	public function should_fall_back_to_the_defaults_for_a_blank_or_unknown_setting(): void {
		$config = agend_apps_records_filter_config(
			'listing',
			'custom_field',
			array(
				'custom_field_key'         => 'income',
				'control'                  => 'range_brackets',
				'range_brackets_source'    => 'surprise',
				'range_brackets_display'   => 'carousel',
				'range_brackets_any_label' => '   ',
			)
		);

		self::assertSame( 'agend', $config['rangeBrackets']['source'] );
		self::assertSame( 'select', $config['rangeBrackets']['display'] );
		self::assertSame( 'Any', $config['rangeBrackets']['anyLabel'], 'an unset Elementor text control is an empty string, not its default' );
	}

	#[Test]
	public function should_ignore_a_values_choice_list_left_over_from_another_presentation(): void {
		$config = agend_apps_records_filter_config(
			'listing',
			'custom_field',
			array(
				'custom_field_key' => 'income',
				'control'          => 'range_brackets',
				'values_mode'      => 'choices',
				'choices'          => array( array( 'choice_label' => 'Left over', 'choice_value' => 'x' ) ),
			)
		);

		self::assertSame( array(), $config['values'] );
		self::assertSame( array( 'facet' => 'custom.income' ), $config['source'], 'without it the script would never be told which facet carries the presets' );
	}

	#[Test]
	public function should_offer_the_presentation_to_custom_fields_only_and_leave_other_configs_unchanged(): void {
		$descriptor = agend_apps_records_filter_descriptor( 'listing', 'custom_field' );
		self::assertContains( 'range_brackets', $descriptor['controls'] );
		self::assertContains( 'range', $descriptor['controls'], 'the free-text range stays' );

		foreach ( array( array( 'listing', 'category' ), array( 'listing', 'city' ), array( 'event', 'category' ), array( 'course', 'difficulty' ) ) as $pair ) {
			self::assertNotContains( 'range_brackets', agend_apps_records_filter_descriptor( $pair[0], $pair[1] )['controls'] );
		}

		$unsupported = agend_apps_records_filter_config( 'event', 'category', array( 'control' => 'range_brackets' ) );
		self::assertSame( 'checkboxes', $unsupported['control'], 'a filter that does not support it falls back to its default' );
		self::assertArrayNotHasKey( 'rangeBrackets', $unsupported );

		$range = agend_apps_records_filter_config( 'listing', 'custom_field', array( 'custom_field_key' => 'income', 'control' => 'range' ) );
		self::assertArrayNotHasKey( 'rangeBrackets', $range, 'the Number range config is untouched' );
	}

	// -------------------------------------------------------------------
	// The schema both adapters build their controls from
	// -------------------------------------------------------------------

	/**
	 * @param string $name Field name.
	 * @return array<string, mixed>
	 */
	private function filter_field( string $name ): array {
		foreach ( agend_apps_records_surface_schema( 'filter' )['sections'] as $section ) {
			foreach ( $section['fields'] as $field ) {
				if ( ( $field['name'] ?? '' ) === $name ) {
					return $field;
				}
			}
		}
		self::fail( 'No filter schema field named ' . $name );
	}

	#[Test]
	public function should_add_range_brackets_beside_number_range_in_the_presentation_options(): void {
		$options = $this->filter_field( 'control' )['options'];

		self::assertSame( 'Range brackets', $options['range_brackets'] );
		self::assertSame( 'Number range', $options['range'] );

		$keys = array_keys( $options );
		self::assertSame( array_search( 'range', $keys, true ) + 1, array_search( 'range_brackets', $keys, true ), 'listed straight after Number range' );
	}

	#[Test]
	public function should_declare_the_bracket_settings_conditional_on_the_custom_field_range_brackets_presentation(): void {
		$expected = array( 'filter' => 'listing:custom_field', 'control' => 'range_brackets' );

		foreach ( array( 'range_brackets_heading', 'range_brackets_source', 'range_brackets_custom', 'range_brackets_display', 'range_brackets_any_label' ) as $name ) {
			self::assertSame( $expected, $this->filter_field( $name )['condition'], $name );
		}
	}

	#[Test]
	public function should_declare_the_source_display_custom_list_and_any_label_with_their_defaults(): void {
		$source = $this->filter_field( 'range_brackets_source' );
		self::assertSame( 'select', $source['type'] );
		self::assertSame( 'agend', $source['default'] );
		self::assertSame(
			array( 'agend' => 'Use Agend\'s brackets', 'custom' => 'Custom brackets' ),
			$source['options']
		);
		self::assertStringContainsString( 'inclusive', $source['description'], 'the short help text says bounds are inclusive' );

		$custom = $this->filter_field( 'range_brackets_custom' );
		self::assertSame( 'textarea', $custom['type'] );
		self::assertSame( '', $custom['default'] );
		self::assertStringContainsString( 'Label | min | max', $custom['description'] );
		self::assertStringContainsString( '12', $custom['description'] );
		self::assertStringContainsString( 'Under 10,000 | | 9999', $custom['placeholder'] );

		$display = $this->filter_field( 'range_brackets_display' );
		self::assertSame( 'select', $display['type'] );
		self::assertSame( array( 'select', 'buttons', 'radio' ), array_keys( $display['options'] ) );
		self::assertSame( 'select', $display['default'] );

		$any = $this->filter_field( 'range_brackets_any_label' );
		self::assertSame( 'text', $any['type'] );
		self::assertSame( 'Any', $any['default'] );
	}

	#[Test]
	public function should_not_offer_a_second_any_label_or_a_values_list_for_range_brackets(): void {
		self::assertContains( 'range_brackets', $this->filter_field( 'any_label' )['condition']['control!'], 'the bracket "Any" label has its own setting' );

		foreach ( agend_apps_records_surface_schema( 'filter' )['sections'] as $section ) {
			if ( 'section_values' === $section['id'] ) {
				self::assertContains( 'range_brackets', $section['condition']['control!'], 'a values list has no meaning for brackets' );
				return;
			}
		}
		self::fail( 'The Values section is missing.' );
	}

	#[Test]
	public function should_keep_every_bracket_setting_name_unique_and_clear_of_the_existing_ones(): void {
		$names = array();
		foreach ( agend_apps_records_surface_schema( 'filter' )['sections'] as $section ) {
			foreach ( $section['fields'] as $field ) {
				$names[] = $field['name'];
			}
		}

		self::assertSame( array_values( array_unique( $names ) ), $names, 'a repeated name would persist as one setting' );
	}
}
