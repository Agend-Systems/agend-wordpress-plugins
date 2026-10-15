<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend_Apps_Records_Record_Context;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * A capped list's "Show the rest" control on Agend Field and Agend Pills, and
 * multi-select and checkbox-group custom fields read as lists so the cap and
 * the control apply to them.
 */
final class RecordListOverflowTest extends TestCase {

	private const CATEGORIES = array( 'Ageing', 'Hand therapy', 'Mental health', 'Paediatrics' );

	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/palette.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/format.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/fields.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/record-context.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/preview-records.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema/record-field.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema/record-pills.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/record-field.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/record-pills.php';
		Agend_Apps_Records_Record_Context::reset();
	}

	private function listingWithCategories(): array {
		return array( 'categories' => array_map( static fn ( $name ) => array( 'name' => $name ), self::CATEGORIES ) );
	}

	private function listingWithFundingSchemes( string $type ): array {
		return array(
			'custom_fields' => array(
				array(
					'key'         => 'funding_schemes',
					'label'       => 'Funding schemes',
					'type'        => $type,
					'value'       => array( 'ndis', 'dva', 'medicare' ),
					'value_label' => array( 'NDIS', 'DVA', 'Medicare' ),
				),
			),
		);
	}

	private function formatList( array $opts ): string {
		return agend_apps_records_format_field( self::CATEGORIES, 'list', $opts );
	}

	private function schemaDefault( array $schema, string $name ) {
		foreach ( $schema['sections'] as $section ) {
			foreach ( $section['fields'] as $field ) {
				if ( $name === $field['name'] ) {
					return $field['default'];
				}
			}
		}
		$this->fail( "{$name} is not in the schema" );
	}

	// -- Controls ----------------------------------------------------------------

	#[Test]
	public function should_offer_a_show_the_rest_toggle_off_by_default_with_overflow_and_collapse_text_on_both_widgets(): void {
		foreach ( array( agend_apps_records_schema_record_field(), agend_apps_records_schema_record_pills() ) as $schema ) {
			$this->assertFalse( $this->schemaDefault( $schema, 'show_rest' ) );
			$this->assertSame( '+{n}', $this->schemaDefault( $schema, 'overflow_text' ) );
			$this->assertSame( 'Show less', $this->schemaDefault( $schema, 'collapse_text' ) );
		}
	}

	// -- Agend Field ---------------------------------------------------------------

	#[Test]
	public function should_render_a_capped_list_exactly_as_before_when_the_toggle_is_off(): void {
		$this->assertSame( 'Ageing, Hand therapy', $this->formatList( array( 'list_max' => 2 ) ) );
	}

	#[Test]
	public function should_render_the_first_items_then_a_control_counting_the_rest_when_the_toggle_is_on_and_the_list_is_longer(): void {
		$html = $this->formatList( array( 'list_max' => 2, 'list_show_rest' => true ) );

		$this->assertSame(
			'<span class="agend-list" data-agend-rest-group>Ageing, Hand therapy'
			. '<span class="agend-list__rest" data-agend-rest hidden>, Mental health, Paediatrics</span>'
			. '<button type="button" class="agend-rest-toggle" aria-expanded="false" data-agend-rest-toggle data-agend-more="+2" data-agend-less="Show less">+2</button>'
			. '</span>',
			$html
		);
	}

	#[Test]
	public function should_replace_n_in_the_configured_overflow_text_and_use_the_configured_collapse_text(): void {
		$html = $this->formatList(
			array( 'list_max' => 1, 'list_show_rest' => true, 'list_overflow_text' => 'and {n} more', 'list_collapse_text' => 'Fewer' )
		);

		$this->assertStringContainsString( '>and 3 more</button>', $html );
		$this->assertStringContainsString( 'data-agend-less="Fewer"', $html );
	}

	#[Test]
	public function should_render_every_item_and_no_control_when_the_list_is_no_longer_than_the_limit(): void {
		$html = $this->formatList( array( 'list_max' => 4, 'list_show_rest' => true ) );

		$this->assertSame( 'Ageing, Hand therapy, Mental health, Paediatrics', $html );
	}

	#[Test]
	public function should_carry_the_toggle_and_its_texts_from_the_widget_settings(): void {
		$opts = agend_apps_records_record_field_format_options(
			array( 'list_max' => 2, 'show_rest' => 'yes', 'overflow_text' => '+{n} more', 'collapse_text' => 'Less' )
		);

		$this->assertTrue( $opts['list_show_rest'] );
		$this->assertSame( '+{n} more', $opts['list_overflow_text'] );
		$this->assertSame( 'Less', $opts['list_collapse_text'] );
		$this->assertFalse( agend_apps_records_record_field_format_options( array() )['list_show_rest'] );
	}

	#[Test]
	public function should_render_a_multi_select_or_checkbox_group_custom_field_as_a_list_capped_with_the_control(): void {
		foreach ( array( 'multi_select', 'checkbox_group' ) as $type ) {
			$record = $this->listingWithFundingSchemes( $type );
			$extra  = array( 'custom_field_key' => 'funding_schemes' );

			$this->assertSame( 'list', agend_apps_records_field_kind_for_record( 'common:custom_field', $record, $extra ), $type );
			$html = agend_apps_records_render_field(
				'common:custom_field',
				'listing',
				$record,
				$extra,
				array( 'list_max' => 1, 'list_separator' => ' | ', 'list_show_rest' => true )
			);
			$this->assertStringStartsWith( '<span class="agend-list" data-agend-rest-group>NDIS<span class="agend-list__rest" data-agend-rest hidden> | DVA | Medicare</span>', $html, $type );
		}
	}

	#[Test]
	public function should_render_a_multi_select_custom_field_as_the_same_text_as_before_with_no_limit_and_the_default_separator(): void {
		$record = $this->listingWithFundingSchemes( 'multi_select' );

		$this->assertSame(
			'NDIS, DVA, Medicare',
			agend_apps_records_render_field( 'common:custom_field', 'listing', $record, array( 'custom_field_key' => 'funding_schemes' ), array( 'list_separator' => ', ' ) )
		);
		$this->assertSame( 'NDIS, DVA, Medicare', agend_apps_records_record_custom_field( $record, 'funding_schemes' ) );
	}

	#[Test]
	public function should_keep_a_single_select_custom_field_as_text(): void {
		$record = array( 'custom_fields' => array( array( 'key' => 'level', 'type' => 'select', 'value' => 'senior', 'value_label' => 'Senior' ) ) );

		$this->assertSame( 'text', agend_apps_records_field_kind_for_record( 'common:custom_field', $record, array( 'custom_field_key' => 'level' ) ) );
	}

	// -- Agend Pills ---------------------------------------------------------------

	#[Test]
	public function should_render_capped_pills_exactly_as_before_when_the_toggle_is_off(): void {
		Agend_Apps_Records_Record_Context::push( 'listing', $this->listingWithCategories() );

		$this->assertSame(
			'<div class="agend-pills"><span class="agend-pill">Ageing</span><span class="agend-pill">Hand therapy</span></div>',
			agend_apps_records_render_record_pills( array( 'field' => 'listing:categories', 'max_items' => 2 ) )
		);
	}

	#[Test]
	public function should_hold_back_the_pills_past_the_limit_behind_the_control_when_the_toggle_is_on(): void {
		Agend_Apps_Records_Record_Context::push( 'listing', $this->listingWithCategories() );

		$this->assertSame(
			'<div class="agend-pills" data-agend-rest-group>'
			. '<span class="agend-pill">Ageing</span><span class="agend-pill">Hand therapy</span>'
			. '<span class="agend-pill" data-agend-rest hidden>Mental health</span><span class="agend-pill" data-agend-rest hidden>Paediatrics</span>'
			. '<button type="button" class="agend-rest-toggle" aria-expanded="false" data-agend-rest-toggle data-agend-more="+2" data-agend-less="Show less">+2</button>'
			. '</div>',
			agend_apps_records_render_record_pills( array( 'field' => 'listing:categories', 'max_items' => 2, 'show_rest' => 'yes' ) )
		);
	}

	#[Test]
	public function should_render_every_pill_and_no_control_when_the_terms_fit_the_limit(): void {
		Agend_Apps_Records_Record_Context::push( 'listing', $this->listingWithCategories() );

		$html = agend_apps_records_render_record_pills( array( 'field' => 'listing:categories', 'max_items' => 4, 'show_rest' => 'yes' ) );

		$this->assertStringNotContainsString( 'agend-rest-toggle', $html );
		$this->assertSame( 4, substr_count( $html, 'class="agend-pill"' ) );
	}

	#[Test]
	public function should_offer_custom_fields_to_pills_and_render_one_pill_per_selected_option(): void {
		$this->assertTrue( agend_apps_records_field_registry()['common:custom_field']['pill'] );

		foreach ( array( 'multi_select', 'checkbox_group' ) as $type ) {
			Agend_Apps_Records_Record_Context::reset();
			Agend_Apps_Records_Record_Context::push( 'listing', $this->listingWithFundingSchemes( $type ) );

			$this->assertSame(
				'<div class="agend-pills"><span class="agend-pill">NDIS</span><span class="agend-pill">DVA</span><span class="agend-pill">Medicare</span></div>',
				agend_apps_records_render_record_pills( array( 'field' => 'common:custom_field', 'custom_field_key_choice' => 'funding_schemes' ) ),
				$type
			);
		}
	}
}
