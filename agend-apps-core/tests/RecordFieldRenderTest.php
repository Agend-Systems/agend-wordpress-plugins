<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend_Apps_Records_Record_Context;
use Agend_Elementor_Record_Field;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The Agend Field markup moved out of the Elementor widget into core
 * (SPEC-INFRA-20260907-gutenberg-block-colours-and-surfaces US-4.1). The
 * widget's render() used to compute its "renders nothing" conditions and the
 * field markup itself; both now live in
 * agend-apps-core/includes/records/render/record-field.php, reachable by any
 * caller, not only that widget.
 */
final class RecordFieldRenderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/format.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/fields.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/record-context.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/preview-records.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/record-field.php';
		require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor-field-widget-trait.php';
		require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/widgets/class-agend-elementor-record-field.php';
		Agend_Apps_Records_Record_Context::reset();
	}

	// -------------------------------------------------------------------
	// Live rendering against a pushed record context
	// -------------------------------------------------------------------

	#[Test]
	public function should_render_the_field_against_a_pushed_record_context(): void {
		Agend_Apps_Records_Record_Context::push( 'event', array( 'name' => 'Sample Gala' ) );

		$settings = array( 'field' => 'event:name' );

		self::assertSame(
			'<div class="agend-field agend-field--text agend-field--event-name">Sample Gala</div>',
			agend_apps_records_render_record_field( $settings )
		);
	}

	#[Test]
	public function should_resolve_a_custom_field_by_its_free_text_key(): void {
		Agend_Apps_Records_Record_Context::push(
			'listing',
			array(
				'custom_fields' => array(
					array( 'key' => 'abn', 'label' => 'ABN', 'type' => 'text', 'value' => '12 345 678 901' ),
				),
			)
		);

		$settings = array( 'field' => 'common:custom_field', 'custom_field_key' => 'abn' );

		self::assertSame(
			'<div class="agend-field agend-field--text agend-field--common-custom_field">12 345 678 901</div>',
			agend_apps_records_render_record_field( $settings )
		);
	}

	#[Test]
	public function should_prefer_the_custom_field_picker_over_the_free_text_key(): void {
		Agend_Apps_Records_Record_Context::push(
			'listing',
			array(
				'custom_fields' => array(
					array( 'key' => 'abn', 'label' => 'ABN', 'type' => 'text', 'value' => 'from-picker' ),
					array( 'key' => 'established', 'label' => 'Established', 'type' => 'text', 'value' => 'from-text' ),
				),
			)
		);

		$settings = array(
			'field'                   => 'common:custom_field',
			'custom_field_key_choice' => 'abn',
			'custom_field_key'        => 'established',
		);

		self::assertStringContainsString( 'from-picker', agend_apps_records_render_record_field( $settings ) );
	}

	// -------------------------------------------------------------------
	// The editor preview path
	// -------------------------------------------------------------------

	#[Test]
	public function should_preview_the_event_placeholder_record_with_no_pushed_context(): void {
		$html = agend_apps_records_render_record_field(
			array( 'field' => 'event:name' ),
			array( 'preview' => true, 'preview_type' => 'event' )
		);

		self::assertSame(
			'<div class="agend-field agend-field--text agend-field--event-name agend-field--preview">Sample Event</div>',
			$html
		);
	}

	#[Test]
	public function should_not_mark_a_live_pushed_context_as_preview(): void {
		Agend_Apps_Records_Record_Context::push( 'event', array( 'name' => 'Sample Gala' ) );

		// A preview is still requested here (as the editor always does), but a
		// pushed context wins: is_preview reflects the RESOLVED context, never
		// the caller's own opts.
		$html = agend_apps_records_render_record_field(
			array( 'field' => 'event:name' ),
			array( 'preview' => true, 'preview_type' => 'course' )
		);

		self::assertStringNotContainsString( 'agend-field--preview', $html );
	}

	// -------------------------------------------------------------------
	// "Renders nothing" guards and their reasons
	// -------------------------------------------------------------------

	#[Test]
	public function should_render_nothing_silently_when_no_record_is_in_scope(): void {
		$settings = array( 'field' => 'event:name' );

		self::assertSame( '', agend_apps_records_record_field_render_reason( $settings ) );
		self::assertSame( '', agend_apps_records_render_record_field( $settings ) );
	}

	#[Test]
	public function should_render_nothing_silently_for_a_field_key_this_site_does_not_know(): void {
		Agend_Apps_Records_Record_Context::push( 'event', array( 'name' => 'Sample Gala' ) );

		$settings = array( 'field' => 'not-a-real-field' );

		self::assertSame( '', agend_apps_records_record_field_render_reason( $settings ), 'nothing more specific to say than "renders nothing"' );
		self::assertSame( '', agend_apps_records_render_record_field( $settings ) );
	}

	#[Test]
	public function should_give_the_field_not_applicable_reason_for_a_field_of_the_wrong_record_type(): void {
		Agend_Apps_Records_Record_Context::push( 'event', array( 'name' => 'Sample Gala' ) );

		$settings = array( 'field' => 'course:title' );

		self::assertSame( 'field_not_applicable', agend_apps_records_record_field_render_reason( $settings ) );
		self::assertSame( '', agend_apps_records_render_record_field( $settings ) );
	}

	// -------------------------------------------------------------------
	// The fallback-text path
	// -------------------------------------------------------------------

	#[Test]
	public function should_use_the_fallback_text_when_the_record_has_no_value(): void {
		Agend_Apps_Records_Record_Context::push( 'event', array() );

		$settings = array( 'field' => 'event:name', 'fallback_text' => 'No name yet' );

		self::assertSame(
			'<div class="agend-field agend-field--text agend-field--event-name">No name yet</div>',
			agend_apps_records_render_record_field( $settings )
		);
	}

	#[Test]
	public function should_render_nothing_when_the_record_has_no_value_and_no_fallback_is_set(): void {
		Agend_Apps_Records_Record_Context::push( 'event', array() );

		$settings = array( 'field' => 'event:name' );

		self::assertSame( '', agend_apps_records_render_record_field( $settings ) );
	}

	// -------------------------------------------------------------------
	// The label on/off paths
	// -------------------------------------------------------------------

	#[Test]
	public function should_render_no_label_by_default(): void {
		Agend_Apps_Records_Record_Context::push( 'event', array( 'name' => 'Sample Gala' ) );

		$html = agend_apps_records_render_record_field( array( 'field' => 'event:name' ) );

		self::assertStringNotContainsString( 'agend-field__label', $html );
		self::assertStringNotContainsString( 'agend-field--labelled', $html );
	}

	#[Test]
	public function should_render_the_fields_own_name_as_the_label_when_enabled(): void {
		Agend_Apps_Records_Record_Context::push( 'event', array( 'name' => 'Sample Gala' ) );

		$settings = array( 'field' => 'event:name', 'show_label' => 'yes', 'label_separator' => ': ' );

		self::assertSame(
			'<div class="agend-field agend-field--text agend-field--event-name agend-field--labelled">'
				. '<span class="agend-field__label">Name: </span>Sample Gala</div>',
			agend_apps_records_render_record_field( $settings )
		);
	}

	#[Test]
	public function should_prefer_the_configured_label_text_over_the_fields_own_name(): void {
		Agend_Apps_Records_Record_Context::push( 'event', array( 'name' => 'Sample Gala' ) );

		$settings = array( 'field' => 'event:name', 'show_label' => 'yes', 'label_text' => 'Event', 'label_separator' => ' - ' );

		self::assertStringContainsString( '<span class="agend-field__label">Event - </span>', agend_apps_records_render_record_field( $settings ) );
	}

	// -------------------------------------------------------------------
	// The detail link and its in_card_link suppression
	// -------------------------------------------------------------------

	#[Test]
	public function should_link_to_the_detail_page_when_requested(): void {
		Agend_Apps_Records_Record_Context::push(
			'event',
			array( 'name' => 'Sample Gala' ),
			array( 'detail_url' => 'https://example.test/events/event/sample-gala/' )
		);

		$settings = array( 'field' => 'event:name', 'link_to_detail' => 'yes' );

		self::assertSame(
			'<div class="agend-field agend-field--text agend-field--event-name">'
				. '<a class="agend-field__link" href="https://example.test/events/event/sample-gala/">Sample Gala</a></div>',
			agend_apps_records_render_record_field( $settings )
		);
	}

	#[Test]
	public function should_suppress_the_detail_link_when_already_inside_a_card_link(): void {
		Agend_Apps_Records_Record_Context::push(
			'event',
			array( 'name' => 'Sample Gala' ),
			array( 'detail_url' => 'https://example.test/events/event/sample-gala/', 'in_card_link' => true )
		);

		$settings = array( 'field' => 'event:name', 'link_to_detail' => 'yes' );

		self::assertSame(
			'<div class="agend-field agend-field--text agend-field--event-name">Sample Gala</div>',
			agend_apps_records_render_record_field( $settings )
		);
	}

	// -------------------------------------------------------------------
	// The Elementor widget delegating to the core renderer
	// -------------------------------------------------------------------

	private function render_widget( array $settings ): string {
		$widget = new Agend_Elementor_Record_Field( array(), null, $settings );

		ob_start();
		( function () {
			$this->render();
		} )->call( $widget );

		return (string) ob_get_clean();
	}

	#[Test]
	public function should_match_the_core_renderer_live_when_the_editor_is_closed(): void {
		Agend_Apps_Records_Record_Context::push( 'event', array( 'name' => 'Sample Gala' ) );
		\Elementor\Plugin::$instance->editor->is_edit_mode = false;

		$settings = array( 'field' => 'event:name' );

		self::assertSame(
			agend_apps_records_render_record_field( $settings, array( 'preview' => false, 'preview_type' => '' ) ),
			$this->render_widget( $settings )
		);
	}

	#[Test]
	public function should_match_the_core_renderer_preview_when_the_editor_is_open(): void {
		\Elementor\Plugin::$instance->editor->is_edit_mode = true;

		$settings = array( 'field' => 'event:name' );

		self::assertSame(
			agend_apps_records_render_record_field( $settings, array( 'preview' => true, 'preview_type' => 'event' ) ),
			$this->render_widget( $settings )
		);
	}

	#[Test]
	public function should_show_the_editor_notice_for_the_field_not_applicable_reason_only_when_the_editor_is_open(): void {
		Agend_Apps_Records_Record_Context::push( 'event', array( 'name' => 'Sample Gala' ) );
		$settings = array( 'field' => 'course:title' );

		\Elementor\Plugin::$instance->editor->is_edit_mode = false;
		self::assertSame( '', $this->render_widget( $settings ), 'no notice on the live front end' );

		\Elementor\Plugin::$instance->editor->is_edit_mode = true;
		self::assertSame(
			'<div class="elementor-alert elementor-alert-warning">This field does not exist on the record type this template renders.</div>',
			$this->render_widget( $settings )
		);
	}
}
