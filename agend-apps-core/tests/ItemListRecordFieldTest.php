<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend_Apps_Records_Record_Context;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/format.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/fields.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/record-context.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/preview-records.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/record-field.php';

/**
 * The Agend Field surface, pointed at an item list custom field, renders the
 * same table or list as the built-in detail instead of a blank, and shows its
 * fallback text when the list is empty.
 */
#[CoversFunction( 'agend_apps_records_render_record_field' )]
#[CoversFunction( 'agend_apps_records_field_kind_for_record' )]
#[CoversFunction( 'agend_apps_records_record_custom_field_item_list' )]
#[CoversFunction( 'agend_apps_records_format_field' )]
final class ItemListRecordFieldTest extends TestCase {

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
	 * An item list entry in the gateway's shape.
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
				array( 'name' => 'Cafe', 'area_sqm' => 85 ),
				array( 'name' => 'Department Store', 'area_sqm' => 12500 ),
			),
			'item_fields' => array(
				array( 'key' => 'name', 'label' => 'Tenant', 'type' => 'text' ),
				array( 'key' => 'area_sqm', 'label' => 'Area (sqm)', 'type' => 'number' ),
			),
		);
	}

	/**
	 * Agend Field settings pointing at the tenants custom field.
	 *
	 * @param array<string, mixed> $extra Further settings.
	 * @return array<string, mixed>
	 */
	private function settings( array $extra = array() ): array {
		return $extra + array( 'field' => 'common:custom_field', 'custom_field_key' => 'tenants' );
	}

	#[Test]
	public function should_render_the_table_instead_of_a_blank_by_default(): void {
		$this->pushListing( array( $this->tenants() ) );

		$html = agend_apps_records_render_record_field( $this->settings() );

		self::assertStringStartsWith( '<div class="agend-field agend-field--item_list agend-field--common-custom_field">', $html );
		self::assertStringContainsString( '<th scope="col">Tenant</th>', $html );
		self::assertStringContainsString( '<th class="agend-dir-cf__item-list-cell--number" scope="col">Area (sqm)</th>', $html );
		self::assertStringContainsString( '<td>Department Store</td><td class="agend-dir-cf__item-list-cell--number">12,500</td>', $html );
	}

	#[Test]
	public function should_render_the_list_when_the_widget_asks_for_it(): void {
		$this->pushListing( array( $this->tenants() ) );

		$html = agend_apps_records_render_record_field( $this->settings( array( 'item_list_layout' => 'list' ) ) );

		self::assertStringContainsString( '<ul class="agend-dir-cf__item-list agend-dir-cf__item-list--list"><li>Cafe, 85</li><li>Department Store, 12,500</li></ul>', $html );
		self::assertStringNotContainsString( '<table', $html );
	}

	#[Test]
	public function should_limit_the_item_fields_from_the_widget_control(): void {
		$this->pushListing( array( $this->tenants() ) );

		$html = agend_apps_records_render_record_field( $this->settings( array( 'item_list_fields' => 'area_sqm, nonsense' ) ) );

		self::assertStringContainsString( 'Area (sqm)', $html );
		self::assertStringNotContainsString( '>Tenant</th>', $html );
	}

	#[Test]
	public function should_fall_back_to_a_table_for_an_unrecognised_layout(): void {
		$this->pushListing( array( $this->tenants() ) );

		self::assertStringContainsString( '<table', agend_apps_records_render_record_field( $this->settings( array( 'item_list_layout' => 'grid' ) ) ) );
	}

	#[Test]
	public function should_render_the_table_in_a_div_whatever_html_tag_is_chosen(): void {
		$this->pushListing( array( $this->tenants() ) );

		$html = agend_apps_records_render_record_field( $this->settings( array( 'html_tag' => 'span' ) ) );

		self::assertStringStartsWith( '<div class="agend-field', $html );
		self::assertStringEndsWith( '</div>', $html );
	}

	#[Test]
	public function should_not_wrap_the_table_in_the_text_before_and_after(): void {
		$this->pushListing( array( $this->tenants() ) );

		$html = agend_apps_records_render_record_field( $this->settings( array( 'before_text' => 'BEFORE', 'after_text' => 'AFTER' ) ) );

		self::assertStringNotContainsString( 'BEFORE', $html );
		self::assertStringNotContainsString( 'AFTER', $html );
	}

	#[Test]
	public function should_escape_a_hostile_cell(): void {
		$this->pushListing( array( $this->tenants( array( 'value' => array( array( 'name' => '<script>alert(1)</script>', 'area_sqm' => 1 ) ) ) ) ) );

		$html = agend_apps_records_render_record_field( $this->settings() );

		self::assertStringNotContainsString( '<script', $html );
		self::assertStringContainsString( '&lt;script&gt;', $html );
	}

	#[Test]
	public function should_show_the_fallback_text_for_an_empty_item_list(): void {
		$this->pushListing( array( $this->tenants( array( 'value' => array() ) ) ) );

		$html = agend_apps_records_render_record_field( $this->settings( array( 'fallback_text' => 'No tenants listed' ) ) );

		self::assertSame( '<div class="agend-field agend-field--item_list agend-field--common-custom_field">No tenants listed</div>', $html );
	}

	#[Test]
	public function should_render_nothing_for_an_empty_item_list_without_fallback_text(): void {
		$this->pushListing( array( $this->tenants( array( 'value' => array() ) ) ) );

		self::assertSame( '', agend_apps_records_render_record_field( $this->settings() ) );
	}

	#[Test]
	public function should_show_the_fallback_text_when_the_listing_has_no_such_item_list(): void {
		$this->pushListing( array() );

		$html = agend_apps_records_render_record_field( $this->settings( array( 'fallback_text' => 'None' ) ) );

		self::assertSame( '<div class="agend-field agend-field--text agend-field--common-custom_field">None</div>', $html );
	}

	#[Test]
	public function should_keep_the_restricted_treatment_for_a_gated_item_list_and_send_no_values(): void {
		$this->pushListing(
			array(
				array(
					'key'    => 'tenants',
					'label'  => 'Tenants',
					'type'   => 'item_list',
					'gated'  => true,
					'state'  => 'plan_required',
					'mode'   => 'selected_entitlements',
					'value'  => array( array( 'name' => 'Leaked Tenant' ) ),
				),
			)
		);

		$html = agend_apps_records_render_record_field( $this->settings( array( 'fallback_text' => 'Not recorded' ) ) );

		self::assertStringContainsString( 'agend-field--gated', $html );
		self::assertStringContainsString( 'Not included in your subscription', $html );
		self::assertStringNotContainsString( 'Leaked Tenant', $html );
		self::assertStringNotContainsString( '<table', $html );
		self::assertStringNotContainsString( 'Not recorded', $html );
	}

	#[Test]
	public function should_still_render_a_text_custom_field_as_text(): void {
		$this->pushListing( array( array( 'key' => 'abn', 'label' => 'ABN', 'type' => 'text', 'value' => '12 345' ) ) );

		self::assertSame(
			'<div class="agend-field agend-field--text agend-field--common-custom_field">12 345</div>',
			agend_apps_records_render_record_field( array( 'field' => 'common:custom_field', 'custom_field_key' => 'abn' ) )
		);
	}

	#[Test]
	public function should_settle_the_kind_from_the_record_not_only_the_registry(): void {
		$with    = array( 'custom_fields' => array( $this->tenants() ) );
		$without = array( 'custom_fields' => array( array( 'key' => 'tenants', 'type' => 'text', 'value' => 'x' ) ) );
		$extra   = array( 'custom_field_key' => 'tenants' );

		self::assertSame( 'item_list', agend_apps_records_field_kind_for_record( 'common:custom_field', $with, $extra ) );
		self::assertSame( 'text', agend_apps_records_field_kind_for_record( 'common:custom_field', $without, $extra ) );
		self::assertSame( 'text', agend_apps_records_field_kind_for_record( 'common:custom_field', $with, array( 'custom_field_key' => 'other' ) ) );
		self::assertSame( 'text', agend_apps_records_field_kind_for_record( 'common:title', $with, $extra ) );
		self::assertSame( '', agend_apps_records_field_kind_for_record( 'nope', $with, $extra ) );
		self::assertContains( 'item_list', AGEND_APPS_RECORDS_FIELD_KINDS );
	}

	#[Test]
	public function should_flatten_nothing_for_other_readers_of_the_text_getter(): void {
		self::assertSame( '', agend_apps_records_record_custom_field( array( 'custom_fields' => array( $this->tenants() ) ), 'tenants' ) );
		self::assertNull( agend_apps_records_record_custom_field_item_list( array( 'custom_fields' => array( $this->tenants( array( 'gated' => true ) ) ) ), 'tenants' ) );
		self::assertNull( agend_apps_records_record_custom_field_item_list( array(), 'tenants' ) );
	}

	#[Test]
	public function should_preview_an_item_list_on_the_placeholder_listing(): void {
		$record = agend_apps_records_preview_placeholder_record( 'listing' );
		$entry  = agend_apps_records_record_custom_field_item_list( $record, 'tenants' );

		self::assertNotNull( $entry );
		self::assertNotEmpty( $entry['value'] );
		self::assertNotEmpty( $entry['item_fields'] );
		self::assertContains( 'number', array_column( $entry['item_fields'], 'type' ) );

		$html = agend_apps_records_render_record_field( $this->settings(), array( 'preview' => true, 'preview_type' => 'listing' ) );

		self::assertStringContainsString( 'agend-field--preview', $html );
		self::assertStringContainsString( '<th scope="col">Tenant</th>', $html );
		self::assertStringContainsString( '12,500', $html );
	}

	#[Test]
	public function should_offer_the_item_list_in_the_editor_custom_field_picker(): void {
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema/record-field.php';

		\Elementor\Plugin::$instance->editor->is_edit_mode = true;

		self::assertSame( 'Tenants', agend_apps_records_custom_field_key_options()['tenants'] ?? null );
	}
}
