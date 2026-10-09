<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/format.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/ssr-detail.php';

/**
 * The shared PHP renderer for an `item_list` custom field, and the built-in
 * listing detail that uses it. assets/js/directory-catalogue.js draws the same
 * markup client-side; there is no JS test harness in this repository, so this
 * pins the markup both are written against.
 */
#[CoversFunction( 'agend_apps_records_item_list_options' )]
#[CoversFunction( 'agend_apps_records_item_list_columns' )]
#[CoversFunction( 'agend_apps_records_item_list_cell_text' )]
#[CoversFunction( 'agend_apps_records_render_item_list' )]
#[CoversFunction( 'agend_apps_records_ssr_cf_value' )]
#[CoversFunction( 'agend_apps_records_ssr_custom_fields' )]
final class ItemListRenderTest extends TestCase {

	/**
	 * An item list entry in the gateway's shape, with its field declaration.
	 *
	 * @param array<string, mixed> $overrides Entry keys to replace.
	 * @return array<string, mixed>
	 */
	private function tenants( array $overrides = array() ): array {
		return $overrides + array(
			'key'         => 'tenants',
			'label'       => 'Tenants',
			'type'        => 'item_list',
			'value'       => array(
				array( 'name' => 'Cafe', 'category' => 'Food', 'area_sqm' => 85 ),
				array( 'name' => 'Department Store', 'category' => 'Retail', 'area_sqm' => 12500 ),
				array( 'name' => 'Kiosk', 'category' => '', 'area_sqm' => '3.75' ),
			),
			'item_fields' => array(
				array( 'key' => 'name', 'label' => 'Tenant', 'type' => 'text' ),
				array( 'key' => 'category', 'label' => 'Category', 'type' => 'text' ),
				array( 'key' => 'area_sqm', 'label' => 'Area (sqm)', 'type' => 'number' ),
			),
		);
	}

	/**
	 * The cell text of every body row of a rendered table, in order.
	 *
	 * @param string $html Rendered table markup.
	 * @return array<int, array<int, string>>
	 */
	private function bodyRows( string $html ): array {
		preg_match( '#<tbody>(.*)</tbody>#s', $html, $body );
		preg_match_all( '#<tr>(.*?)</tr>#s', $body[1] ?? '', $rows );

		return array_map(
			static function ( string $row ): array {
				preg_match_all( '#<td[^>]*>(.*?)</td>#s', $row, $cells );
				return $cells[1];
			},
			$rows[1]
		);
	}

	/**
	 * The header cell text of a rendered table, in order.
	 *
	 * @param string $html Rendered table markup.
	 * @return array<int, string>
	 */
	private function headings( string $html ): array {
		preg_match_all( '#<th(?:\s[^>]*)?>(.*?)</th>#s', $html, $cells );

		return $cells[1];
	}

	// -- options ---------------------------------------------------------------

	#[Test]
	public function should_default_to_a_table_of_every_item_field(): void {
		self::assertSame( array( 'layout' => 'table', 'fields' => array() ), \agend_apps_records_item_list_options( array() ) );
	}

	#[Test]
	public function should_read_the_list_layout_and_split_the_item_field_keys(): void {
		$options = \agend_apps_records_item_list_options( array( 'item_list_layout' => 'list', 'item_list_fields' => ' name , area_sqm,, name ' ) );

		self::assertSame( array( 'layout' => 'list', 'fields' => array( 'name', 'area_sqm' ) ), $options );
	}

	#[Test]
	public function should_treat_an_unrecognised_layout_as_a_table(): void {
		self::assertSame( 'table', \agend_apps_records_item_list_options( array( 'item_list_layout' => 'grid' ) )['layout'] );
		self::assertSame( 'table', \agend_apps_records_item_list_options( array( 'item_list_layout' => array( 'list' ) ) )['layout'] );
	}

	// -- table -----------------------------------------------------------------

	#[Test]
	public function should_draw_a_header_row_of_item_field_labels_and_a_row_per_item_in_stored_order(): void {
		$html = \agend_apps_records_render_item_list( $this->tenants() );

		self::assertStringStartsWith( '<div class="agend-dir-cf__item-list-wrap" tabindex="0" role="region" aria-label="Tenants"><table class="agend-dir-cf__item-list agend-dir-cf__item-list--table">', $html );
		self::assertSame( array( 'Tenant', 'Category', 'Area (sqm)' ), $this->headings( $html ) );
		self::assertSame(
			array(
				array( 'Cafe', 'Food', '85' ),
				array( 'Department Store', 'Retail', '12,500' ),
				array( 'Kiosk', '', '3.75' ),
			),
			array_map(
				static function ( array $cells ): array {
					return array_map( 'strip_tags', $cells );
				},
				$this->bodyRows( $html )
			)
		);
	}

	#[Test]
	public function should_group_thousands_in_number_cells_and_keep_the_value_own_decimals(): void {
		self::assertSame( '12,500', \agend_apps_records_item_list_cell_text( 12500, 'number' ) );
		self::assertSame( '1,234,567.891', \agend_apps_records_item_list_cell_text( 1234567.891, 'number' ) );
		self::assertSame( '3.75', \agend_apps_records_item_list_cell_text( '3.75', 'number' ) );
		self::assertSame( '1,000', \agend_apps_records_item_list_cell_text( '1000.00', 'number' ) );
		self::assertSame( '-2,500', \agend_apps_records_item_list_cell_text( -2500, 'number' ) );
	}

	#[Test]
	public function should_leave_a_text_cell_and_a_non_numeric_number_cell_as_written(): void {
		self::assertSame( '12500', \agend_apps_records_item_list_cell_text( '12500', 'text' ) );
		self::assertSame( 'TBC', \agend_apps_records_item_list_cell_text( 'TBC', 'number' ) );
		self::assertSame( '', \agend_apps_records_item_list_cell_text( null, 'text' ) );
		self::assertSame( '', \agend_apps_records_item_list_cell_text( array( 'x' ), 'text' ) );
		self::assertSame( '', \agend_apps_records_item_list_cell_text( true, 'text' ) );
		self::assertSame( '', \agend_apps_records_item_list_cell_text( '   ', 'text' ) );
	}

	#[Test]
	public function should_mark_number_columns_so_they_can_align_right(): void {
		$html = \agend_apps_records_render_item_list( $this->tenants() );

		self::assertStringContainsString( '<th class="agend-dir-cf__item-list-cell--number" scope="col">Area (sqm)</th>', $html );
		self::assertStringContainsString( '<td class="agend-dir-cf__item-list-cell--number">12,500</td>', $html );
		self::assertStringContainsString( '<th scope="col">Tenant</th>', $html );
	}

	#[Test]
	public function should_escape_every_cell_and_heading(): void {
		$entry = $this->tenants(
			array(
				'value'       => array( array( 'name' => '<script>alert(1)</script>', 'category' => '"><img src=x onerror=1>', 'area_sqm' => 1 ) ),
				'item_fields' => array(
					array( 'key' => 'name', 'label' => '<b>Tenant</b>', 'type' => 'text' ),
					array( 'key' => 'category', 'label' => 'Category', 'type' => 'text' ),
					array( 'key' => 'area_sqm', 'label' => 'Area', 'type' => 'number' ),
				),
			)
		);

		foreach ( array( 'table', 'list' ) as $layout ) {
			$html = \agend_apps_records_render_item_list( $entry, array( 'layout' => $layout ) );

			self::assertStringNotContainsString( '<script', $html, $layout );
			self::assertStringNotContainsString( '<img', $html, $layout );
			self::assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html, $layout );
		}
		self::assertStringNotContainsString( '<b>', \agend_apps_records_render_item_list( $entry ) );
	}

	#[Test]
	public function should_leave_out_an_item_with_nothing_to_show_in_the_chosen_fields(): void {
		$entry = $this->tenants(
			array(
				'value' => array(
					array( 'name' => 'Cafe', 'category' => 'Food', 'area_sqm' => 85 ),
					array( 'name' => '', 'category' => 'Retail', 'area_sqm' => '' ),
					array( 'name' => '', 'category' => '', 'area_sqm' => '' ),
				),
			)
		);

		self::assertCount( 2, $this->bodyRows( \agend_apps_records_render_item_list( $entry ) ) );
		self::assertCount( 1, $this->bodyRows( \agend_apps_records_render_item_list( $entry, array( 'fields' => array( 'name' ) ) ) ) );
	}

	#[Test]
	public function should_leave_a_number_too_large_to_be_one_as_written(): void {
		self::assertSame( '1e999', \agend_apps_records_item_list_cell_text( '1e999', 'number' ) );
		self::assertSame( '-1e999', \agend_apps_records_item_list_cell_text( '-1e999', 'number' ) );
		self::assertSame( '1,000', \agend_apps_records_item_list_cell_text( '1e3', 'number' ) );
	}

	// -- the scrolling wrapper -------------------------------------------------

	#[Test]
	public function should_make_the_scrolling_wrapper_focusable_and_named_by_the_field_label(): void {
		$html = \agend_apps_records_render_item_list( $this->tenants( array( 'label' => 'Centre tenants' ) ) );

		self::assertStringStartsWith( '<div class="agend-dir-cf__item-list-wrap" tabindex="0" role="region" aria-label="Centre tenants">', $html );
	}

	#[Test]
	public function should_escape_the_field_label_in_the_wrapper_name(): void {
		$html = \agend_apps_records_render_item_list( $this->tenants( array( 'label' => 'A "B" <i>C</i>' ) ) );

		self::assertStringContainsString( 'aria-label="A &quot;B&quot; &lt;i&gt;C&lt;/i&gt;"', $html );
		self::assertStringNotContainsString( '<i>', $html );
	}

	#[Test]
	public function should_keep_the_wrapper_focusable_but_not_claim_a_region_when_the_field_has_no_label(): void {
		$entry = $this->tenants();
		unset( $entry['label'] );

		self::assertStringStartsWith( '<div class="agend-dir-cf__item-list-wrap" tabindex="0"><table', \agend_apps_records_render_item_list( $entry ) );
		self::assertStringNotContainsString( 'role=', \agend_apps_records_render_item_list( $entry ) );
	}

	#[Test]
	public function should_not_make_a_list_a_scrolling_region(): void {
		self::assertStringNotContainsString( 'tabindex', \agend_apps_records_render_item_list( $this->tenants(), array( 'layout' => 'list' ) ) );
	}

	// -- shared markup fixture -------------------------------------------------

	/**
	 * Every case of tests/fixtures/item-list-render.json: an entry, the options
	 * and the markup both renderers must draw. The catalogue script is held to
	 * the same file by tests/js/item-list-render.test.mjs.
	 *
	 * @return array<string, array{array<string, mixed>, array<string, mixed>, string}>
	 */
	public static function fixture_cases(): array {
		$fixture = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/item-list-render.json' ), true );
		$cases   = array();
		foreach ( $fixture['cases'] as $case ) {
			$cases[ $case['name'] ] = array( $case['entry'], $case['options'], $case['html'] );
		}

		return $cases;
	}

	/**
	 * @param array<string, mixed> $entry   The custom field entry.
	 * @param array<string, mixed> $options The renderer's options.
	 * @param string               $html    The markup the fixture expects.
	 */
	#[DataProvider( 'fixture_cases' )]
	#[Test]
	public function should_draw_the_shared_fixture_markup( array $entry, array $options, string $html ): void {
		self::assertSame( $html, \agend_apps_records_render_item_list( $entry, $options ) );
	}

	#[Test]
	public function should_cover_every_layout_and_edge_the_two_renderers_must_agree_on(): void {
		$names = array_keys( self::fixture_cases() );

		self::assertGreaterThanOrEqual( 15, count( $names ) );
		foreach ( array( 'table_default', 'list_layout', 'fields_filter_keeps_declared_order', 'fields_filter_ignores_unknown_keys', 'fields_filter_with_no_match_shows_every_field', 'without_item_fields_uses_first_seen_keys_as_columns', 'number_formatting_in_a_table', 'hostile_cells_and_labels_in_a_table', 'empty_list_renders_nothing' ) as $required ) {
			self::assertContains( $required, $names );
		}
	}

	// -- item fields filter ----------------------------------------------------

	#[Test]
	public function should_limit_the_columns_to_the_named_keys_in_declared_order(): void {
		$html = \agend_apps_records_render_item_list( $this->tenants(), array( 'fields' => array( 'area_sqm', 'name' ) ) );

		self::assertSame( array( 'Tenant', 'Area (sqm)' ), $this->headings( $html ) );
		self::assertSame( array( 'Cafe', '85' ), array_map( 'strip_tags', $this->bodyRows( $html )[0] ) );
	}

	#[Test]
	public function should_ignore_an_unknown_key_in_the_item_fields(): void {
		$columns = \agend_apps_records_item_list_columns( $this->tenants(), array( 'category', 'nonsense' ) );

		self::assertSame( array( 'category' ), array_column( $columns, 'key' ) );
	}

	#[Test]
	public function should_show_every_item_field_when_no_named_key_matches(): void {
		$columns = \agend_apps_records_item_list_columns( $this->tenants(), array( 'nonsense', 'also_nonsense' ) );

		self::assertSame( array( 'name', 'category', 'area_sqm' ), array_column( $columns, 'key' ) );
	}

	// -- gateway without item_fields -------------------------------------------

	#[Test]
	public function should_fall_back_to_the_item_keys_in_first_seen_order_as_order_and_headings(): void {
		$entry = $this->tenants(
			array(
				'value' => array(
					array( 'b_key' => 'B1', 'a_key' => 'A1' ),
					array( 'c_key' => 'C2', 'b_key' => 'B2' ),
				),
			)
		);
		unset( $entry['item_fields'] );

		$html = \agend_apps_records_render_item_list( $entry );

		self::assertSame( array( 'b_key', 'a_key', 'c_key' ), $this->headings( $html ) );
		self::assertSame( array( array( 'B1', 'A1', '' ), array( 'B2', '', 'C2' ) ), $this->bodyRows( $html ) );
	}

	#[Test]
	public function should_apply_the_item_fields_filter_to_the_fallback_columns_too(): void {
		$entry = $this->tenants( array( 'value' => array( array( 'x' => '1', 'y' => '2' ) ) ) );
		unset( $entry['item_fields'] );

		self::assertSame( array( 'y' ), $this->headings( \agend_apps_records_render_item_list( $entry, array( 'fields' => array( 'y' ) ) ) ) );
	}

	#[Test]
	public function should_fall_back_when_item_fields_declares_nothing_usable(): void {
		$entry = $this->tenants( array( 'item_fields' => array( 'junk', array( 'label' => 'No key' ), array( 'key' => '' ) ) ) );

		self::assertSame( array( 'name', 'category', 'area_sqm' ), $this->headings( \agend_apps_records_render_item_list( $entry ) ) );
	}

	#[Test]
	public function should_use_the_key_as_heading_when_a_declared_field_has_no_label(): void {
		$entry = $this->tenants( array( 'item_fields' => array( array( 'key' => 'name', 'type' => 'text' ) ) ) );

		self::assertSame( array( 'name' ), $this->headings( \agend_apps_records_render_item_list( $entry ) ) );
	}

	// -- list ------------------------------------------------------------------

	#[Test]
	public function should_draw_one_entry_per_item_joining_the_values_in_declared_order_and_skipping_empties(): void {
		$html = \agend_apps_records_render_item_list( $this->tenants(), array( 'layout' => 'list' ) );

		self::assertSame(
			'<ul class="agend-dir-cf__item-list agend-dir-cf__item-list--list">'
			. '<li>Cafe, Food, 85</li>'
			. '<li>Department Store, Retail, 12,500</li>'
			. '<li>Kiosk, 3.75</li>'
			. '</ul>',
			$html
		);
	}

	#[Test]
	public function should_limit_the_list_values_to_the_named_keys(): void {
		$html = \agend_apps_records_render_item_list( $this->tenants(), array( 'layout' => 'list', 'fields' => array( 'area_sqm', 'name' ) ) );

		self::assertStringContainsString( '<li>Cafe, 85</li>', $html );
		self::assertStringNotContainsString( 'Food', $html );
	}

	#[Test]
	public function should_leave_an_item_with_no_values_out_of_the_list(): void {
		$entry = $this->tenants( array( 'value' => array( array( 'name' => '' ), array( 'name' => 'Cafe' ) ) ) );

		self::assertSame( 1, substr_count( \agend_apps_records_render_item_list( $entry, array( 'layout' => 'list' ) ), '<li>' ) );
	}

	// -- nothing to show -------------------------------------------------------

	/** @return array<string, array{array<string, mixed>}> */
	public static function entries_with_nothing_to_show(): array {
		$declared = array( array( 'key' => 'name', 'label' => 'Tenant', 'type' => 'text' ) );

		return array(
			'empty list'          => array( array( 'type' => 'item_list', 'value' => array(), 'item_fields' => $declared ) ),
			'missing value'       => array( array( 'type' => 'item_list', 'item_fields' => $declared ) ),
			'value not a list'    => array( array( 'type' => 'item_list', 'value' => 'oops', 'item_fields' => $declared ) ),
			'items not objects'   => array( array( 'type' => 'item_list', 'value' => array( 'a', 'b' ), 'item_fields' => $declared ) ),
			'every item empty'    => array( array( 'type' => 'item_list', 'value' => array( array( 'name' => '' ), array( 'name' => '  ' ) ), 'item_fields' => $declared ) ),
			'no columns at all'   => array( array( 'type' => 'item_list', 'value' => array( array() ) ) ),
		);
	}

	/**
	 * @param array<string, mixed> $entry The entry.
	 */
	#[DataProvider( 'entries_with_nothing_to_show' )]
	#[Test]
	public function should_render_nothing_for_an_empty_or_missing_item_list( array $entry ): void {
		self::assertSame( '', \agend_apps_records_render_item_list( $entry ) );
		self::assertSame( '', \agend_apps_records_render_item_list( $entry, array( 'layout' => 'list' ) ) );
	}

	#[Test]
	public function should_render_nothing_for_a_gated_entry_even_if_it_carries_a_value(): void {
		self::assertSame( '', \agend_apps_records_render_item_list( $this->tenants( array( 'gated' => true, 'state' => 'plan_required' ) ) ) );
	}

	// -- the built-in detail ---------------------------------------------------

	#[Test]
	public function should_show_an_item_list_in_the_detail_as_a_table_by_default(): void {
		$html = \agend_apps_records_ssr_cf_value( $this->tenants() );

		self::assertStringStartsWith( '<div class="agend-dir-cf__value"><div class="agend-dir-cf__item-list-wrap" tabindex="0" role="region" aria-label="Tenants"><table', $html );
		self::assertStringContainsString( '<td>Cafe</td>', $html );
		self::assertStringNotContainsString( 'Array', $html );
	}

	#[Test]
	public function should_show_an_item_list_in_the_detail_as_a_list_when_asked(): void {
		$html = \agend_apps_records_ssr_cf_value( $this->tenants(), array( 'layout' => 'list', 'fields' => array() ) );

		self::assertStringContainsString( '<ul class="agend-dir-cf__item-list agend-dir-cf__item-list--list">', $html );
		self::assertStringNotContainsString( '<table', $html );
	}

	#[Test]
	public function should_give_an_item_list_row_the_full_width_modifier_in_the_details_section(): void {
		$html = \agend_apps_records_ssr_custom_fields(
			array(
				array( 'key' => 'abn', 'label' => 'ABN', 'type' => 'text', 'value' => '00 000' ),
				$this->tenants(),
			)
		);

		self::assertStringContainsString( '<div class="agend-dir-cf__row agend-dir-cf__row--item-list"><span class="agend-dir-cf__label">Tenants</span>', $html );
		self::assertStringContainsString( '<div class="agend-dir-cf__row"><span class="agend-dir-cf__label">ABN</span>', $html );
	}

	#[Test]
	public function should_drop_the_row_of_an_empty_item_list_in_the_details_section(): void {
		$html = \agend_apps_records_ssr_custom_fields(
			array(
				array( 'key' => 'abn', 'label' => 'ABN', 'type' => 'text', 'value' => '00 000' ),
				$this->tenants( array( 'value' => array() ) ),
			)
		);

		self::assertStringContainsString( 'ABN', $html );
		self::assertStringNotContainsString( 'Tenants', $html );
		self::assertSame( '', \agend_apps_records_ssr_custom_fields( array( $this->tenants( array( 'value' => array() ) ) ) ) );
	}

	#[Test]
	public function should_keep_the_restricted_treatment_for_a_gated_item_list_and_send_no_values(): void {
		$gated = array(
			'key'   => 'tenants',
			'label' => 'Tenants',
			'type'  => 'item_list',
			'gated' => true,
			'state' => 'membership_required',
			'value' => array( array( 'name' => 'Leaked Tenant' ) ),
		);

		$html = \agend_apps_records_ssr_custom_fields( array( $gated ) );

		self::assertStringContainsString( 'agend-dir-cf__value--gated', $html );
		self::assertStringContainsString( 'Members only', $html );
		self::assertStringNotContainsString( 'Leaked Tenant', $html );
		self::assertStringNotContainsString( '<table', $html );
		self::assertStringNotContainsString( 'item-list', $html );
	}

	#[Test]
	public function should_leave_the_other_custom_field_types_as_they_were(): void {
		self::assertSame( '<span class="agend-dir-cf__value">Open Mall</span>', \agend_apps_records_ssr_cf_value( array( 'key' => 'k', 'label' => 'K', 'type' => 'text', 'value' => 'Open Mall' ) ) );
	}
}
