<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend_Apps_Records_Record_Context;
use Agend_Elementor_Record_Pills;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The Agend Pills markup moved out of the Elementor widget into core
 * (SPEC-INFRA-20260907-gutenberg-block-colours-and-surfaces US-4.1). The
 * widget's render() used to compute its "renders nothing" conditions and the
 * pill markup itself; both now live in
 * agend-apps-core/includes/records/render/record-pills.php, reachable by any
 * caller, not only that widget.
 */
final class RecordPillsRenderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/format.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/fields.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/record-context.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/preview-records.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/record-pills.php';
		require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor-field-widget-trait.php';
		require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/widgets/class-agend-elementor-record-pills.php';
		Agend_Apps_Records_Record_Context::reset();
	}

	// -------------------------------------------------------------------
	// Live rendering against a pushed record context
	// -------------------------------------------------------------------

	#[Test]
	public function should_render_one_pill_per_term_against_a_pushed_record_context(): void {
		Agend_Apps_Records_Record_Context::push(
			'event',
			array( 'tags' => array( array( 'name' => 'Sydney' ), array( 'name' => 'Networking' ) ) )
		);

		$settings = array( 'field' => 'event:tags' );

		self::assertSame(
			'<div class="agend-pills"><span class="agend-pill">Sydney</span><span class="agend-pill">Networking</span></div>',
			agend_apps_records_render_record_pills( $settings )
		);
	}

	#[Test]
	public function should_render_a_single_value_field_as_one_pill(): void {
		Agend_Apps_Records_Record_Context::push( 'event', array( 'category' => 'Conference' ) );

		$settings = array( 'field' => 'event:category' );

		self::assertSame(
			'<div class="agend-pills"><span class="agend-pill">Conference</span></div>',
			agend_apps_records_render_record_pills( $settings )
		);
	}

	#[Test]
	public function should_truncate_to_max_items(): void {
		Agend_Apps_Records_Record_Context::push(
			'event',
			array( 'tags' => array( array( 'name' => 'A' ), array( 'name' => 'B' ), array( 'name' => 'C' ) ) )
		);

		$settings = array( 'field' => 'event:tags', 'max_items' => 2 );

		self::assertSame(
			'<div class="agend-pills"><span class="agend-pill">A</span><span class="agend-pill">B</span></div>',
			agend_apps_records_render_record_pills( $settings )
		);
	}

	// -------------------------------------------------------------------
	// The editor preview path
	// -------------------------------------------------------------------

	#[Test]
	public function should_preview_the_event_placeholder_records_category(): void {
		$html = agend_apps_records_render_record_pills(
			array( 'field' => 'event:category' ),
			array( 'preview' => true, 'preview_type' => 'event' )
		);

		self::assertSame( '<div class="agend-pills"><span class="agend-pill">Conference</span></div>', $html );
	}

	// -------------------------------------------------------------------
	// "Renders nothing" guards and their reasons
	// -------------------------------------------------------------------

	#[Test]
	public function should_render_nothing_silently_when_no_record_is_in_scope(): void {
		$settings = array( 'field' => 'event:category' );

		self::assertSame( '', agend_apps_records_record_pills_render_reason( $settings ) );
		self::assertSame( '', agend_apps_records_render_record_pills( $settings ) );
	}

	#[Test]
	public function should_give_the_field_not_applicable_reason_for_terms_of_the_wrong_record_type(): void {
		Agend_Apps_Records_Record_Context::push( 'event', array() );

		$settings = array( 'field' => 'listing:category' );

		self::assertSame( 'field_not_applicable', agend_apps_records_record_pills_render_reason( $settings ) );
		self::assertSame( '', agend_apps_records_render_record_pills( $settings ) );
	}

	#[Test]
	public function should_give_the_no_terms_reason_when_the_record_has_none(): void {
		Agend_Apps_Records_Record_Context::push( 'event', array() );

		$settings = array( 'field' => 'event:tags' );

		self::assertSame( 'no_terms', agend_apps_records_record_pills_render_reason( $settings ) );
		self::assertSame( '', agend_apps_records_render_record_pills( $settings ) );
	}

	#[Test]
	public function should_give_the_no_terms_reason_when_max_items_truncates_to_nothing(): void {
		Agend_Apps_Records_Record_Context::push( 'event', array( 'tags' => array( array( 'name' => 'A' ) ) ) );

		$settings = array( 'field' => 'event:tags', 'max_items' => 0 );

		// max_items of 0 means "show every term" (see the schema), not zero,
		// so this is the boundary check that 0 is not mistaken for a truncation.
		self::assertSame( '', agend_apps_records_record_pills_render_reason( $settings ) );
	}

	// -------------------------------------------------------------------
	// The label on/off paths
	// -------------------------------------------------------------------

	#[Test]
	public function should_render_no_label_by_default(): void {
		Agend_Apps_Records_Record_Context::push( 'event', array( 'category' => 'Conference' ) );

		$html = agend_apps_records_render_record_pills( array( 'field' => 'event:category' ) );

		self::assertStringNotContainsString( 'agend-pills__label', $html );
		self::assertStringNotContainsString( 'agend-pills--labelled', $html );
	}

	#[Test]
	public function should_render_the_fields_own_name_as_the_label_when_enabled(): void {
		Agend_Apps_Records_Record_Context::push( 'event', array( 'category' => 'Conference' ) );

		$settings = array( 'field' => 'event:category', 'show_label' => 'yes' );

		self::assertSame(
			'<div class="agend-pills agend-pills--labelled"><span class="agend-pills__label">Category</span><span class="agend-pill">Conference</span></div>',
			agend_apps_records_render_record_pills( $settings )
		);
	}

	#[Test]
	public function should_prefer_the_configured_label_text_over_the_fields_own_name(): void {
		Agend_Apps_Records_Record_Context::push( 'event', array( 'category' => 'Conference' ) );

		$settings = array( 'field' => 'event:category', 'show_label' => 'yes', 'label_text' => 'Type' );

		self::assertStringContainsString( '<span class="agend-pills__label">Type</span>', agend_apps_records_render_record_pills( $settings ) );
	}

	// -------------------------------------------------------------------
	// The detail link and its in_card_link suppression
	// -------------------------------------------------------------------

	#[Test]
	public function should_link_each_pill_to_the_detail_page_when_requested(): void {
		Agend_Apps_Records_Record_Context::push(
			'event',
			array( 'category' => 'Conference' ),
			array( 'detail_url' => 'https://example.test/events/event/sample-gala/' )
		);

		$settings = array( 'field' => 'event:category', 'link_to_detail' => 'yes' );

		self::assertSame(
			'<div class="agend-pills"><a class="agend-pill" href="https://example.test/events/event/sample-gala/">Conference</a></div>',
			agend_apps_records_render_record_pills( $settings )
		);
	}

	#[Test]
	public function should_suppress_the_detail_link_when_already_inside_a_card_link(): void {
		Agend_Apps_Records_Record_Context::push(
			'event',
			array( 'category' => 'Conference' ),
			array( 'detail_url' => 'https://example.test/events/event/sample-gala/', 'in_card_link' => true )
		);

		$settings = array( 'field' => 'event:category', 'link_to_detail' => 'yes' );

		self::assertSame(
			'<div class="agend-pills"><span class="agend-pill">Conference</span></div>',
			agend_apps_records_render_record_pills( $settings )
		);
	}

	// -------------------------------------------------------------------
	// The Elementor widget delegating to the core renderer
	// -------------------------------------------------------------------

	private function render_widget( array $settings ): string {
		$widget = new Agend_Elementor_Record_Pills( array(), null, $settings );

		ob_start();
		( function () {
			$this->render();
		} )->call( $widget );

		return (string) ob_get_clean();
	}

	#[Test]
	public function should_match_the_core_renderer_live_when_the_editor_is_closed(): void {
		Agend_Apps_Records_Record_Context::push( 'event', array( 'category' => 'Conference' ) );
		\Elementor\Plugin::$instance->editor->is_edit_mode = false;

		$settings = array( 'field' => 'event:category' );

		self::assertSame(
			agend_apps_records_render_record_pills( $settings, array( 'preview' => false, 'preview_type' => '' ) ),
			$this->render_widget( $settings )
		);
	}

	#[Test]
	public function should_match_the_core_renderer_preview_when_the_editor_is_open(): void {
		\Elementor\Plugin::$instance->editor->is_edit_mode = true;

		$settings = array( 'field' => 'event:category' );

		self::assertSame(
			agend_apps_records_render_record_pills( $settings, array( 'preview' => true, 'preview_type' => 'event' ) ),
			$this->render_widget( $settings )
		);
	}

	#[Test]
	public function should_show_the_editor_notice_for_the_field_not_applicable_reason_only_when_the_editor_is_open(): void {
		Agend_Apps_Records_Record_Context::push( 'event', array() );
		$settings = array( 'field' => 'listing:category' );

		\Elementor\Plugin::$instance->editor->is_edit_mode = false;
		self::assertSame( '', $this->render_widget( $settings ), 'no notice on the live front end' );

		\Elementor\Plugin::$instance->editor->is_edit_mode = true;
		self::assertSame(
			'<div class="elementor-alert elementor-alert-warning">These terms do not exist on the record type this template renders.</div>',
			$this->render_widget( $settings )
		);
	}

	#[Test]
	public function should_show_the_no_terms_notice_only_when_the_editor_is_open(): void {
		Agend_Apps_Records_Record_Context::push( 'event', array() );
		$settings = array( 'field' => 'event:tags' );

		\Elementor\Plugin::$instance->editor->is_edit_mode = false;
		self::assertSame( '', $this->render_widget( $settings ), 'no notice on the live front end' );

		\Elementor\Plugin::$instance->editor->is_edit_mode = true;
		self::assertSame(
			'<div class="elementor-alert elementor-alert-warning">This record has no terms for this field, so nothing renders here on the live site.</div>',
			$this->render_widget( $settings )
		);
	}
}
