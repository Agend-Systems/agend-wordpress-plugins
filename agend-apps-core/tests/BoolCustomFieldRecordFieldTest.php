<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend_Apps_Records_Record_Context;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/format.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/fields.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/record-context.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/preview-records.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/record-field.php';

/**
 * The Agend Field surface, pointed at a yes/no custom field, renders True or
 * False instead of "1" and a blank that fell through to the fallback text. A
 * `boolean` entry is detected on its own; a custom field stored as text opts in
 * with "Show as true or false". The built-in bool fields keep their own wording.
 */
#[CoversFunction( 'agend_apps_records_render_record_field' )]
#[CoversFunction( 'agend_apps_records_record_field_format_options' )]
#[CoversFunction( 'agend_apps_records_field_kind_for_record' )]
#[CoversFunction( 'agend_apps_records_record_custom_field_bool' )]
#[CoversFunction( 'agend_apps_records_custom_field_bool_value' )]
final class BoolCustomFieldRecordFieldTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Agend_Apps_Records_Record_Context::reset();
	}

	protected function tearDown(): void {
		Agend_Apps_Records_Record_Context::reset();
		parent::tearDown();
	}

	/**
	 * Pushes a listing carrying the given custom fields.
	 *
	 * @param array<int, array<string, mixed>> $custom_fields The record's `custom_fields`.
	 */
	private function pushListing( array $custom_fields ): void {
		Agend_Apps_Records_Record_Context::push( 'listing', array( 'custom_fields' => $custom_fields ) );
	}

	/**
	 * A custom field entry in the gateway's shape.
	 *
	 * @param mixed  $value The entry's value.
	 * @param string $type  The entry's type.
	 * @return array<string, mixed>
	 */
	private function entry( $value, string $type = 'boolean' ): array {
		return array(
			'key'   => 'parking',
			'label' => 'Parking',
			'type'  => $type,
			'value' => $value,
		);
	}

	/**
	 * Agend Field settings pointing at the parking custom field.
	 *
	 * @param array<string, mixed> $extra Further settings.
	 * @return array<string, mixed>
	 */
	private function settings( array $extra = array() ): array {
		return $extra + array( 'field' => 'common:custom_field', 'custom_field_key' => 'parking' );
	}

	// -------------------------------------------------------------------
	// A boolean entry is detected without any setting
	// -------------------------------------------------------------------

	#[Test]
	public function should_render_true_for_a_boolean_entry_as_the_bool_kind(): void {
		$this->pushListing( array( $this->entry( true ) ) );

		self::assertSame(
			'<div class="agend-field agend-field--bool agend-field--common-custom_field">True</div>',
			agend_apps_records_render_record_field( $this->settings() )
		);
	}

	#[Test]
	public function should_render_false_for_a_boolean_entry_rather_than_the_fallback_text(): void {
		$this->pushListing( array( $this->entry( false ) ) );

		self::assertSame(
			'<div class="agend-field agend-field--bool agend-field--common-custom_field">False</div>',
			agend_apps_records_render_record_field( $this->settings( array( 'fallback_text' => 'Not recorded' ) ) )
		);
	}

	#[Test]
	public function should_use_the_wording_from_the_custom_field_controls(): void {
		$settings = $this->settings( array( 'custom_field_bool_true' => 'Available', 'custom_field_bool_false' => 'None' ) );

		$this->pushListing( array( $this->entry( true ) ) );
		self::assertStringContainsString( '>Available</div>', agend_apps_records_render_record_field( $settings ) );

		Agend_Apps_Records_Record_Context::reset();
		$this->pushListing( array( $this->entry( false ) ) );
		self::assertStringContainsString( '>None</div>', agend_apps_records_render_record_field( $settings ) );
	}

	#[Test]
	public function should_escape_the_custom_wording(): void {
		$this->pushListing( array( $this->entry( true ) ) );

		$html = agend_apps_records_render_record_field( $this->settings( array( 'custom_field_bool_true' => '<b>Yes</b>' ) ) );

		self::assertStringNotContainsString( '<b>', $html );
		self::assertStringContainsString( '&lt;b&gt;Yes&lt;/b&gt;', $html );
	}

	#[Test]
	public function should_show_the_fallback_text_when_the_false_wording_is_emptied(): void {
		$this->pushListing( array( $this->entry( false ) ) );

		$html = agend_apps_records_render_record_field(
			$this->settings( array( 'custom_field_bool_false' => '', 'fallback_text' => 'Not recorded' ) )
		);

		self::assertSame( '<div class="agend-field agend-field--bool agend-field--common-custom_field">Not recorded</div>', $html );
	}

	#[Test]
	public function should_render_nothing_when_the_false_wording_is_emptied_without_fallback_text(): void {
		$this->pushListing( array( $this->entry( false ) ) );

		self::assertSame( '', agend_apps_records_render_record_field( $this->settings( array( 'custom_field_bool_false' => '' ) ) ) );
	}

	#[Test]
	public function should_keep_an_empty_true_wording_empty(): void {
		$this->pushListing( array( $this->entry( true ) ) );

		$html = agend_apps_records_render_record_field( $this->settings( array( 'custom_field_bool_true' => '', 'fallback_text' => 'Not recorded' ) ) );

		self::assertStringContainsString( '>Not recorded</div>', $html );
		self::assertStringNotContainsString( 'True', $html );
	}

	#[Test]
	public function should_read_the_string_values_of_a_boolean_entry(): void {
		$this->pushListing( array( $this->entry( 'true' ) ) );
		self::assertStringContainsString( '>True</div>', agend_apps_records_render_record_field( $this->settings() ) );

		Agend_Apps_Records_Record_Context::reset();
		$this->pushListing( array( $this->entry( '0' ) ) );
		self::assertStringContainsString( '>False</div>', agend_apps_records_render_record_field( $this->settings( array( 'fallback_text' => 'Not recorded' ) ) ) );
	}

	#[Test]
	public function should_read_the_numeric_values_of_a_boolean_entry(): void {
		$this->pushListing( array( $this->entry( 1 ) ) );
		self::assertStringContainsString( '>True</div>', agend_apps_records_render_record_field( $this->settings() ) );

		Agend_Apps_Records_Record_Context::reset();
		$this->pushListing( array( $this->entry( 0 ) ) );
		self::assertStringContainsString( '>False</div>', agend_apps_records_render_record_field( $this->settings() ) );
	}

	#[Test]
	public function should_wrap_the_bool_value_in_the_text_before_and_after(): void {
		$this->pushListing( array( $this->entry( true ) ) );

		$html = agend_apps_records_render_record_field( $this->settings( array( 'before_text' => 'Parking: ', 'after_text' => ' onsite' ) ) );

		self::assertSame(
			'<div class="agend-field agend-field--bool agend-field--common-custom_field">Parking: True onsite</div>',
			$html
		);
	}

	#[Test]
	public function should_label_the_field_with_the_custom_field_label(): void {
		$this->pushListing( array( $this->entry( true ) ) );

		$html = agend_apps_records_render_record_field( $this->settings( array( 'show_label' => 'yes', 'label_separator' => ': ' ) ) );

		self::assertStringContainsString( 'agend-field--labelled', $html );
		self::assertStringContainsString( '<span class="agend-field__label">Parking: </span>', $html );
	}

	// -------------------------------------------------------------------
	// A custom field stored as text opts in with the toggle
	// -------------------------------------------------------------------

	#[Test]
	public function should_leave_a_text_entry_as_text_without_the_toggle(): void {
		$this->pushListing( array( $this->entry( '1', 'text' ) ) );

		self::assertSame(
			'<div class="agend-field agend-field--text agend-field--common-custom_field">1</div>',
			agend_apps_records_render_record_field( $this->settings() )
		);
	}

	#[Test]
	public function should_read_a_text_entry_as_true_or_false_with_the_toggle(): void {
		$settings = $this->settings( array( 'custom_field_bool' => 'yes' ) );

		$this->pushListing( array( $this->entry( '1', 'text' ) ) );
		self::assertSame(
			'<div class="agend-field agend-field--bool agend-field--common-custom_field">True</div>',
			agend_apps_records_render_record_field( $settings )
		);

		Agend_Apps_Records_Record_Context::reset();
		$this->pushListing( array( $this->entry( 'no', 'text' ) ) );
		self::assertSame(
			'<div class="agend-field agend-field--bool agend-field--common-custom_field">False</div>',
			agend_apps_records_render_record_field( $settings )
		);
	}

	#[Test]
	public function should_keep_a_text_value_that_does_not_read_as_true_or_false_even_with_the_toggle(): void {
		$this->pushListing( array( $this->entry( 'Approved', 'text' ) ) );

		self::assertSame(
			'<div class="agend-field agend-field--text agend-field--common-custom_field">Approved</div>',
			agend_apps_records_render_record_field( $this->settings( array( 'custom_field_bool' => 'yes' ) ) )
		);
	}

	#[Test]
	public function should_keep_an_unparseable_boolean_entry_as_text(): void {
		$this->pushListing( array( $this->entry( 'Approved' ) ) );

		self::assertSame(
			'<div class="agend-field agend-field--text agend-field--common-custom_field">Approved</div>',
			agend_apps_records_render_record_field( $this->settings() )
		);
	}

	#[Test]
	public function should_show_the_fallback_text_not_false_for_a_missing_key_with_the_toggle(): void {
		$this->pushListing( array() );

		self::assertSame(
			'<div class="agend-field agend-field--text agend-field--common-custom_field">Not recorded</div>',
			agend_apps_records_render_record_field( $this->settings( array( 'custom_field_bool' => 'yes', 'fallback_text' => 'Not recorded' ) ) )
		);
	}

	#[Test]
	public function should_show_the_fallback_text_for_a_boolean_entry_with_no_value(): void {
		$this->pushListing( array( $this->entry( '' ) ) );

		self::assertSame(
			'<div class="agend-field agend-field--text agend-field--common-custom_field">Not recorded</div>',
			agend_apps_records_render_record_field( $this->settings( array( 'fallback_text' => 'Not recorded' ) ) )
		);
	}

	#[Test]
	public function should_ignore_the_toggle_for_a_field_that_is_not_a_custom_field(): void {
		Agend_Apps_Records_Record_Context::push( 'event', array( 'name' => 'Sample Gala' ) );

		self::assertSame(
			'<div class="agend-field agend-field--text agend-field--event-name">Sample Gala</div>',
			agend_apps_records_render_record_field( array( 'field' => 'event:name', 'custom_field_bool' => 'yes' ) )
		);
	}

	// -------------------------------------------------------------------
	// Restricted, and the built-in bool fields
	// -------------------------------------------------------------------

	#[Test]
	public function should_keep_the_restricted_treatment_for_a_gated_boolean_entry_and_send_no_value(): void {
		$this->pushListing(
			array(
				array(
					'key'   => 'parking',
					'label' => 'Parking',
					'type'  => 'boolean',
					'gated' => true,
					'state' => 'plan_required',
					'mode'  => 'selected_entitlements',
					'value' => true,
				),
			)
		);

		$html = agend_apps_records_render_record_field( $this->settings( array( 'custom_field_bool' => 'yes', 'fallback_text' => 'Not recorded' ) ) );

		self::assertStringContainsString( 'agend-field--gated', $html );
		self::assertStringContainsString( 'Not included in your subscription', $html );
		self::assertStringNotContainsString( 'True', $html );
		self::assertStringNotContainsString( 'Not recorded', $html );
	}

	#[Test]
	public function should_keep_the_built_in_bool_wording_and_ignore_the_custom_wording(): void {
		Agend_Apps_Records_Record_Context::push( 'event', array( 'sold_out' => true ) );

		$html = agend_apps_records_render_record_field(
			array(
				'field'                   => 'event:sold_out',
				'bool_true'               => 'Yes',
				'bool_false'              => '',
				'custom_field_bool_true'  => 'Available',
				'custom_field_bool_false' => 'None',
			)
		);

		self::assertSame( '<div class="agend-field agend-field--bool agend-field--event-sold_out">Yes</div>', $html );
	}

	#[Test]
	public function should_use_bool_true_and_bool_false_for_a_built_in_bool_field(): void {
		$extra = array( 'custom_field_bool_true' => 'Available', 'custom_field_bool_false' => 'None' );

		Agend_Apps_Records_Record_Context::push( 'event', array( 'sold_out' => true ) );
		self::assertStringContainsString(
			'>Sold<',
			agend_apps_records_render_record_field( $extra + array( 'field' => 'event:sold_out', 'bool_true' => 'Sold', 'bool_false' => 'On sale' ) )
		);

		Agend_Apps_Records_Record_Context::reset();
		Agend_Apps_Records_Record_Context::push( 'event', array( 'sold_out' => false ) );
		self::assertStringContainsString(
			'>On sale<',
			agend_apps_records_render_record_field( $extra + array( 'field' => 'event:sold_out', 'bool_true' => 'Sold', 'bool_false' => 'On sale' ) )
		);
	}

	#[Test]
	public function should_render_nothing_for_a_false_built_in_bool_field_by_default(): void {
		Agend_Apps_Records_Record_Context::push( 'event', array( 'sold_out' => false ) );

		self::assertSame( '', agend_apps_records_render_record_field( array( 'field' => 'event:sold_out' ) ) );
	}

	#[Test]
	public function should_take_the_bool_wording_from_the_setting_that_matches_the_field(): void {
		$s = array(
			'bool_true'               => 'Yes',
			'bool_false'              => '',
			'custom_field_bool_true'  => 'True',
			'custom_field_bool_false' => 'False',
		);

		$built_in = agend_apps_records_record_field_format_options( $s + array( 'field' => 'event:sold_out' ) );
		self::assertSame( 'Yes', $built_in['bool_true'] );
		self::assertSame( '', $built_in['bool_false'] );

		$custom = agend_apps_records_record_field_format_options( $s + array( 'field' => 'common:custom_field' ) );
		self::assertSame( 'True', $custom['bool_true'] );
		self::assertSame( 'False', $custom['bool_false'] );
	}

	#[Test]
	public function should_default_the_custom_wording_only_when_the_setting_is_absent(): void {
		$absent = agend_apps_records_record_field_format_options( array( 'field' => 'common:custom_field' ) );
		self::assertSame( 'True', $absent['bool_true'] );
		self::assertSame( 'False', $absent['bool_false'] );

		$null = agend_apps_records_record_field_format_options(
			array( 'field' => 'common:custom_field', 'custom_field_bool_true' => null, 'custom_field_bool_false' => null )
		);
		self::assertSame( 'True', $null['bool_true'] );
		self::assertSame( 'False', $null['bool_false'] );

		$empty = agend_apps_records_record_field_format_options(
			array( 'field' => 'common:custom_field', 'custom_field_bool_true' => '', 'custom_field_bool_false' => '' )
		);
		self::assertSame( '', $empty['bool_true'] );
		self::assertSame( '', $empty['bool_false'] );
	}

	// -------------------------------------------------------------------
	// The parser and the kind
	// -------------------------------------------------------------------

	/**
	 * @return array<string, array{0: mixed, 1: bool|null}>
	 */
	public static function boolValueCases(): array {
		return array(
			'true'              => array( true, true ),
			'false'             => array( false, false ),
			'int one'           => array( 1, true ),
			'int zero'          => array( 0, false ),
			'int negative'      => array( -3, true ),
			'float'             => array( 0.5, true ),
			'float zero'        => array( 0.0, false ),
			'string 1'          => array( '1', true ),
			'string 0'          => array( '0', false ),
			'string true'       => array( 'true', true ),
			'string TRUE'       => array( 'TRUE', true ),
			'string padded yes' => array( '  Yes ', true ),
			'string y'          => array( 'y', true ),
			'string on'         => array( 'ON', true ),
			'string false'      => array( 'false', false ),
			'string no'         => array( 'No', false ),
			'string n'          => array( 'n', false ),
			'string off'        => array( ' off ', false ),
			'empty string'      => array( '', null ),
			'blank string'      => array( '   ', null ),
			'other text'        => array( 'Approved', null ),
			'number as text'    => array( '2', null ),
			'null'              => array( null, null ),
			'array'             => array( array( 'yes' ), null ),
			'empty array'       => array( array(), null ),
		);
	}

	/**
	 * @param mixed     $value    The raw value.
	 * @param bool|null $expected What it reads as.
	 */
	#[Test]
	#[DataProvider( 'boolValueCases' )]
	public function should_parse_a_custom_field_value_as_true_false_or_neither( $value, ?bool $expected ): void {
		self::assertSame( $expected, agend_apps_records_custom_field_bool_value( $value ) );
	}

	#[Test]
	public function should_find_a_yes_no_entry_by_key_only_when_it_is_readable(): void {
		$boolean = array( 'custom_fields' => array( $this->entry( false ) ) );
		$text    = array( 'custom_fields' => array( $this->entry( 'yes', 'text' ) ) );
		$gated   = array( 'custom_fields' => array( $this->entry( true ) + array( 'gated' => true ) ) );

		self::assertFalse( agend_apps_records_record_custom_field_bool( $boolean, 'parking' ) );
		self::assertNull( agend_apps_records_record_custom_field_bool( $boolean, 'other' ) );
		self::assertNull( agend_apps_records_record_custom_field_bool( $boolean, '' ) );
		self::assertNull( agend_apps_records_record_custom_field_bool( array(), 'parking' ) );
		self::assertNull( agend_apps_records_record_custom_field_bool( $text, 'parking' ) );
		self::assertTrue( agend_apps_records_record_custom_field_bool( $text, 'parking', true ) );
		self::assertNull( agend_apps_records_record_custom_field_bool( $gated, 'parking' ) );
		self::assertNull( agend_apps_records_record_custom_field_bool( $gated, 'parking', true ) );
	}

	#[Test]
	public function should_settle_the_kind_as_bool_from_the_record(): void {
		$boolean = array( 'custom_fields' => array( $this->entry( false ) ) );
		$text    = array( 'custom_fields' => array( $this->entry( '1', 'text' ) ) );
		$extra   = array( 'custom_field_key' => 'parking' );

		self::assertSame( 'bool', agend_apps_records_field_kind_for_record( 'common:custom_field', $boolean, $extra ) );
		self::assertSame( 'text', agend_apps_records_field_kind_for_record( 'common:custom_field', $text, $extra ) );
		self::assertSame( 'bool', agend_apps_records_field_kind_for_record( 'common:custom_field', $text, $extra + array( 'custom_field_bool' => true ) ) );
		self::assertSame( 'text', agend_apps_records_field_kind_for_record( 'common:custom_field', $boolean, array( 'custom_field_key' => 'other' ) ) );
		self::assertSame( 'text', agend_apps_records_field_kind_for_record( 'common:title', $boolean, $extra ) );
	}

	#[Test]
	public function should_still_read_a_boolean_entry_as_text_for_other_readers_of_the_text_getter(): void {
		self::assertSame( '1', agend_apps_records_record_custom_field( array( 'custom_fields' => array( $this->entry( true ) ) ), 'parking' ) );
	}
}
