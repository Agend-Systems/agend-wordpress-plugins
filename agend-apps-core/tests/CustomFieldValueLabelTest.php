<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend_Apps_Records_Record_Context;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * An option-backed custom field (dropdown, multi-select, radio, checkbox group)
 * stores option KEYS in `value` and, from a gateway that supports it, carries
 * the labels to show in `value_label`. Display paths show the label; filter and
 * comparison paths keep the key.
 *
 * Without a usable `value_label` (an older gateway, or a field with no options)
 * every output is exactly what it was before the property existed.
 */
final class CustomFieldValueLabelTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/format.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/fields.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/record-context.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/preview-records.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/record-field.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/ssr-detail.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/query.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/filters.php';
		Agend_Apps_Records_Record_Context::reset();
	}

	// -------------------------------------------------------------------
	// Fixtures
	// -------------------------------------------------------------------

	/**
	 * A single-value option field, as the gateway sends it.
	 *
	 * @param array $overrides Entry properties to add or replace.
	 * @return array
	 */
	private function dropdown( array $overrides = array() ): array {
		return array_merge(
			array(
				'key'         => 'centre_type',
				'label'       => 'Centre Type',
				'type'        => 'dropdown',
				'value'       => 'enclosed_mall',
				'value_label' => 'Enclosed Mall',
			),
			$overrides
		);
	}

	/**
	 * A multi-value option field: `value_label` lists the labels in the same
	 * order as `value` lists the option keys.
	 *
	 * @param array $overrides Entry properties to add or replace.
	 * @return array
	 */
	private function multi_select( array $overrides = array() ): array {
		return array_merge(
			array(
				'key'         => 'facilities',
				'label'       => 'Facilities',
				'type'        => 'multi_select',
				'value'       => array( 'car_park', 'food_court', 'cinema' ),
				'value_label' => array( 'Car Park', 'Food Court', 'Cinema' ),
			),
			$overrides
		);
	}

	/**
	 * @param array ...$entries Custom-field entries.
	 * @return array A listing record.
	 */
	private function record( array ...$entries ): array {
		return array( 'custom_fields' => $entries );
	}

	/**
	 * Settings for an Agend Field reading a custom field by key.
	 *
	 * @param string $key       Custom-field key.
	 * @param array  $overrides Settings to add or replace.
	 * @return array
	 */
	private function field_settings( string $key, array $overrides = array() ): array {
		return array_merge( array( 'field' => 'common:custom_field', 'custom_field_key' => $key ), $overrides );
	}

	// -------------------------------------------------------------------
	// The value reader
	// -------------------------------------------------------------------

	#[Test]
	public function should_return_the_label_for_a_dropdown_with_a_value_label(): void {
		self::assertSame( 'Enclosed Mall', agend_apps_records_record_custom_field( $this->record( $this->dropdown() ), 'centre_type' ) );
	}

	#[Test]
	public function should_return_the_joined_labels_in_order_for_a_multi_select(): void {
		self::assertSame(
			'Car Park, Food Court, Cinema',
			agend_apps_records_record_custom_field( $this->record( $this->multi_select() ), 'facilities' )
		);
	}

	#[Test]
	public function should_return_the_stored_value_for_an_entry_with_no_value_label(): void {
		$dropdown = $this->dropdown();
		$multi    = $this->multi_select();
		unset( $dropdown['value_label'], $multi['value_label'] );
		$record = $this->record( $dropdown, $multi );

		self::assertSame( 'enclosed_mall', agend_apps_records_record_custom_field( $record, 'centre_type' ) );
		self::assertSame( 'car_park, food_court, cinema', agend_apps_records_record_custom_field( $record, 'facilities' ) );
	}

	#[Test]
	public function should_leave_a_non_option_type_unchanged(): void {
		$record = $this->record(
			array( 'key' => 'abn', 'label' => 'ABN', 'type' => 'text', 'value' => '  12 345 678 901  ' ),
			array( 'key' => 'established', 'label' => 'Established', 'type' => 'number', 'value' => 2014 ),
			array( 'key' => 'services', 'label' => 'Services', 'type' => 'array', 'value' => array( 'Advisory', 'Audit' ) )
		);

		self::assertSame( '12 345 678 901', agend_apps_records_record_custom_field( $record, 'abn' ) );
		self::assertSame( '2014', agend_apps_records_record_custom_field( $record, 'established' ) );
		self::assertSame( 'Advisory, Audit', agend_apps_records_record_custom_field( $record, 'services' ) );
	}

	/**
	 * Labels that are present but not worth showing.
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function unusable_labels(): array {
		return array(
			'null'                    => array( null ),
			'empty string'            => array( '' ),
			'whitespace only'         => array( "  \t " ),
			'empty list'              => array( array() ),
			'list of blanks'          => array( array( '', '  ' ) ),
			'list of non-scalars'     => array( array( array( 'nested' ), null ) ),
			'a number'                => array( 42 ),
			'a boolean'               => array( true ),
		);
	}

	/**
	 * @param mixed $label The unusable `value_label`.
	 */
	#[Test]
	#[DataProvider( 'unusable_labels' )]
	public function should_fall_back_to_the_value_when_the_label_is_not_usable( $label ): void {
		$record = $this->record( $this->dropdown( array( 'value_label' => $label ) ) );

		self::assertSame( 'enclosed_mall', agend_apps_records_record_custom_field( $record, 'centre_type' ) );
		self::assertSame( array(), agend_apps_records_custom_field_value_labels( $record['custom_fields'][0] ) );
	}

	#[Test]
	public function should_trim_a_label_as_a_value_is_trimmed(): void {
		$record = $this->record( $this->dropdown( array( 'value_label' => '  Enclosed Mall ' ) ) );

		self::assertSame( 'Enclosed Mall', agend_apps_records_record_custom_field( $record, 'centre_type' ) );
	}

	#[Test]
	public function should_drop_a_blank_label_from_a_list_but_keep_the_rest_in_order(): void {
		$record = $this->record( $this->multi_select( array( 'value_label' => array( 'Car Park', '', 'Cinema' ) ) ) );

		self::assertSame( 'Car Park, Cinema', agend_apps_records_record_custom_field( $record, 'facilities' ) );
	}

	#[Test]
	public function should_keep_returning_empty_for_a_missing_key(): void {
		self::assertSame( '', agend_apps_records_record_custom_field( $this->record( $this->dropdown() ), 'missing' ) );
		self::assertSame( '', agend_apps_records_record_custom_field( array(), 'centre_type' ) );
	}

	// -------------------------------------------------------------------
	// A gated entry carries no value and no label
	// -------------------------------------------------------------------

	/**
	 * @param array $overrides Entry properties to add or replace.
	 * @return array
	 */
	private function gated( array $overrides = array() ): array {
		return array_merge(
			array(
				'key'   => 'centre_type',
				'label' => 'Centre Type',
				'mode'  => 'selected_entitlements',
				'state' => 'membership_required',
				'gated' => true,
			),
			$overrides
		);
	}

	#[Test]
	public function should_not_read_a_label_off_a_gated_entry(): void {
		// The gateway sends neither; a stray label must still not get out.
		$record = $this->record( $this->gated( array( 'value_label' => 'Enclosed Mall' ) ) );

		self::assertSame( '', agend_apps_records_record_custom_field( $record, 'centre_type' ) );
		self::assertSame( array(), agend_apps_records_custom_field_value_labels( $record['custom_fields'][0] ) );
		self::assertSame( 'membership_required', agend_apps_records_record_custom_field_gate( $record, 'centre_type' ) );
	}

	#[Test]
	public function should_still_render_the_restricted_text_for_a_gated_entry_in_an_agend_field(): void {
		Agend_Apps_Records_Record_Context::push( 'listing', $this->record( $this->gated( array( 'value_label' => 'Enclosed Mall' ) ) ) );

		self::assertSame(
			'<div class="agend-field agend-field--text agend-field--common-custom_field agend-field--gated">'
				. '<span class="agend-field__value agend-field__value--gated">Members only</span></div>',
			agend_apps_records_render_record_field( $this->field_settings( 'centre_type' ) )
		);
	}

	#[Test]
	public function should_still_render_the_restricted_text_for_a_gated_entry_in_the_builtin_detail(): void {
		$html = agend_apps_records_ssr_cf_value( $this->gated( array( 'value_label' => 'Enclosed Mall' ) ) );

		self::assertSame( '<span class="agend-dir-cf__value agend-dir-cf__value--gated">Members only</span>', $html );
	}

	// -------------------------------------------------------------------
	// Agend Field, field registry, pills
	// -------------------------------------------------------------------

	#[Test]
	public function should_show_the_label_in_an_agend_field(): void {
		Agend_Apps_Records_Record_Context::push( 'listing', $this->record( $this->dropdown(), $this->multi_select() ) );

		self::assertSame(
			'<div class="agend-field agend-field--text agend-field--common-custom_field">Enclosed Mall</div>',
			agend_apps_records_render_record_field( $this->field_settings( 'centre_type' ) )
		);
		self::assertSame(
			'<div class="agend-field agend-field--list agend-field--common-custom_field">Car Park, Food Court, Cinema</div>',
			agend_apps_records_render_record_field( $this->field_settings( 'facilities' ) )
		);
	}

	#[Test]
	public function should_keep_the_fields_name_as_the_label_and_the_option_label_as_the_value(): void {
		Agend_Apps_Records_Record_Context::push( 'listing', $this->record( $this->dropdown() ) );

		$html = agend_apps_records_render_record_field( $this->field_settings( 'centre_type', array( 'show_label' => 'yes', 'label_separator' => ': ' ) ) );

		self::assertSame(
			'<div class="agend-field agend-field--text agend-field--common-custom_field agend-field--labelled">'
				. '<span class="agend-field__label">Centre Type: </span>Enclosed Mall</div>',
			$html
		);
		self::assertSame( 'Centre Type', agend_apps_records_record_custom_field_label( $this->record( $this->dropdown() ), 'centre_type' ) );
		self::assertSame( 'Centre Type', agend_apps_records_field_label( 'common:custom_field_label', $this->record( $this->dropdown() ), array( 'custom_field_key' => 'centre_type' ) ) );
	}

	#[Test]
	public function should_render_an_agend_field_with_no_value_label_exactly_as_before(): void {
		$dropdown = $this->dropdown();
		unset( $dropdown['value_label'] );
		Agend_Apps_Records_Record_Context::push( 'listing', $this->record( $dropdown ) );

		self::assertSame(
			'<div class="agend-field agend-field--text agend-field--common-custom_field">enclosed_mall</div>',
			agend_apps_records_render_record_field( $this->field_settings( 'centre_type' ) )
		);
	}

	#[Test]
	public function should_serve_the_label_through_the_field_registry_and_pills(): void {
		$record = $this->record( $this->dropdown(), $this->multi_select() );
		$extra  = array( 'custom_field_key' => 'centre_type' );

		self::assertSame( 'Enclosed Mall', agend_apps_records_field_value( 'common:custom_field', 'listing', $record, $extra ) );
		self::assertSame( array( 'Enclosed Mall' ), agend_apps_records_field_terms( 'common:custom_field', 'listing', $record, $extra ) );
	}

	// -------------------------------------------------------------------
	// Built-in server-rendered detail
	// -------------------------------------------------------------------

	#[Test]
	public function should_show_the_label_in_the_builtin_detail_for_a_dropdown(): void {
		$html = agend_apps_records_ssr_cf_value( $this->dropdown() );

		self::assertSame( '<span class="agend-dir-cf__value">Enclosed Mall</span>', $html );
		self::assertStringNotContainsString( 'enclosed_mall', $html );
	}

	#[Test]
	public function should_show_the_joined_labels_in_the_builtin_detail_for_a_multi_select(): void {
		$html = agend_apps_records_ssr_cf_value( $this->multi_select() );

		self::assertSame( '<span class="agend-dir-cf__value">Car Park, Food Court, Cinema</span>', $html );
		self::assertStringNotContainsString( 'car_park', $html );
	}

	#[Test]
	public function should_show_one_chip_per_label_for_an_array_typed_entry_with_labels(): void {
		$html = agend_apps_records_ssr_cf_value( $this->multi_select( array( 'type' => 'array' ) ) );

		self::assertSame(
			'<span class="agend-dir-cf__value"><span class="agend-dir-card__pills">'
				. '<span class="agend-dir-pill agend-dir-pill--tag">Car Park</span>'
				. '<span class="agend-dir-pill agend-dir-pill--tag">Food Court</span>'
				. '<span class="agend-dir-pill agend-dir-pill--tag">Cinema</span>'
				. '</span></span>',
			$html
		);
	}

	#[Test]
	public function should_escape_a_label_in_the_builtin_detail(): void {
		$html = agend_apps_records_ssr_cf_value( $this->dropdown( array( 'value_label' => '<b>Mall</b> & Co' ) ) );

		self::assertSame( '<span class="agend-dir-cf__value">&lt;b&gt;Mall&lt;/b&gt; &amp; Co</span>', $html );
	}

	#[Test]
	public function should_render_the_details_section_with_the_field_name_and_the_option_label(): void {
		$html = agend_apps_records_ssr_custom_fields( array( $this->dropdown(), $this->multi_select() ) );

		self::assertStringContainsString( '<span class="agend-dir-cf__label">Centre Type</span><span class="agend-dir-cf__value">Enclosed Mall</span>', $html );
		self::assertStringContainsString( '<span class="agend-dir-cf__label">Facilities</span><span class="agend-dir-cf__value">Car Park, Food Court, Cinema</span>', $html );
		self::assertStringNotContainsString( 'enclosed_mall', $html );
	}

	#[Test]
	public function should_render_the_builtin_detail_with_no_value_label_exactly_as_before(): void {
		$dropdown = $this->dropdown();
		$tags     = array( 'key' => 'services', 'label' => 'Services', 'type' => 'array', 'value' => array( 'Advisory', 'Audit' ) );
		unset( $dropdown['value_label'] );

		self::assertSame( '<span class="agend-dir-cf__value">enclosed_mall</span>', agend_apps_records_ssr_cf_value( $dropdown ) );
		self::assertSame( '<span class="agend-dir-cf__value">Open Mall</span>', agend_apps_records_ssr_cf_value( array( 'key' => 'centre_type', 'label' => 'Centre Type', 'type' => 'text', 'value' => 'Open Mall' ) ) );
		self::assertSame(
			'<span class="agend-dir-cf__value"><span class="agend-dir-card__pills">'
				. '<span class="agend-dir-pill agend-dir-pill--tag">Advisory</span>'
				. '<span class="agend-dir-pill agend-dir-pill--tag">Audit</span>'
				. '</span></span>',
			agend_apps_records_ssr_cf_value( $tags )
		);
	}

	#[Test]
	public function should_keep_typed_non_option_values_in_the_builtin_detail_even_with_a_stray_label(): void {
		$url   = array( 'key' => 'web', 'label' => 'Website', 'type' => 'url', 'value' => 'https://example.com/a', 'value_label' => 'Nope' );
		$bool  = array( 'key' => 'open', 'label' => 'Open', 'type' => 'boolean', 'value' => true, 'value_label' => 'Nope' );

		self::assertStringContainsString( '>https://example.com/a</a>', agend_apps_records_ssr_cf_value( $url ) );
		self::assertSame( '<span class="agend-dir-cf__value">Yes</span>', agend_apps_records_ssr_cf_value( $bool ) );
	}

	// -------------------------------------------------------------------
	// Filter and comparison paths keep the stored option key
	// -------------------------------------------------------------------

	#[Test]
	public function should_keep_the_option_key_in_the_custom_field_filter_query(): void {
		// Filter state is the option keys a visitor ticked, which the gateway
		// matches against the stored `value`. No record, and so no label, is
		// involved in building it.
		self::assertSame(
			array( 'centre_type' => 'enclosed_mall,regional', 'facilities' => 'car_park' ),
			agend_apps_records_custom_field_filters(
				array( 'centre_type' => array( 'enclosed_mall', 'regional' ), 'facilities' => 'car_park' )
			)
		);
		self::assertSame(
			array( 'custom_fields' => array( 'centre_type' => 'enclosed_mall' ) ),
			agend_apps_records_fragment_query_args( array( 'custom_fields' => array( 'centre_type' => array( 'enclosed_mall' ) ) ), 'listing' )
		);
	}

	#[Test]
	public function should_leave_the_stored_value_on_the_entry_for_anything_that_compares_it(): void {
		$entry = $this->dropdown();
		$multi = $this->multi_select();

		// Reading labels for display never rewrites the entry.
		agend_apps_records_custom_field_value_labels( $entry );
		agend_apps_records_ssr_cf_value( $entry );
		agend_apps_records_record_custom_field( $this->record( $entry, $multi ), 'facilities' );

		self::assertSame( 'enclosed_mall', $entry['value'] );
		self::assertSame( array( 'car_park', 'food_court', 'cinema' ), $multi['value'] );
	}

	#[Test]
	public function should_not_add_a_label_path_to_the_filter_registry(): void {
		// The custom-field filter addresses a field by key and reads its option
		// values from the gateway's facet endpoint, not from a listing's entry.
		$config = agend_apps_records_filter_config( 'listing', 'custom_field', array( 'custom_field_key' => 'centre_type' ) );

		self::assertSame( 'centre_type', $config['fieldKey'] );
		self::assertSame( array( 'facet' => 'custom' ), agend_apps_records_filter_descriptor( 'listing', 'custom_field' )['source'] );
	}
}
