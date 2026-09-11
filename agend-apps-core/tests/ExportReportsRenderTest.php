<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend_Elementor_Export_Reports;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The Agend Export Report markup moved out of the Elementor widget into core
 * (SPEC-INFRA-20260907-gutenberg-block-colours-and-surfaces US-4.1). The
 * widget's parameter_map() and offered_reports() helpers, and its render(),
 * moved unchanged other than tolerating a repeater row that is not itself an
 * array -- a block is expected to store `reports` and `parameters` the same
 * shape Elementor does (a list of associative arrays keyed by row control
 * name), so both read identically here.
 */
final class ExportReportsRenderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/export-reports.php';
		require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor-field-widget-trait.php';
		require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/widgets/class-agend-elementor-export-reports.php';
	}

	// -------------------------------------------------------------------
	// Live rendering
	// -------------------------------------------------------------------

	#[Test]
	public function should_render_a_single_button_for_button_mode(): void {
		$settings = array(
			'mode'        => 'button',
			'report'      => 'report-1',
			'format'      => 'csv',
			'button_text' => 'Download now',
		);

		$config = array(
			'restBase'   => 'https://example.test/wp-json/agend-apps/v1',
			'mode'       => 'button',
			'reports'    => array( array( 'id' => 'report-1', 'label' => '' ) ),
			'format'     => 'csv',
			'parameters' => array(),
			'labels'     => array(
				'working' => 'Preparing…',
				'failed'  => 'That report could not be produced. Try again shortly.',
			),
		);

		$expected = '<div class="agend-export-report agend-export-report--button" data-agend-export-config="' . esc_attr( (string) wp_json_encode( $config ) ) . '">'
			. '<button type="button" class="agend-export__submit" data-agend-export-submit data-report-id="report-1">Download now</button></div>';

		self::assertSame( $expected, agend_apps_records_render_export_reports( $settings ) );
	}

	#[Test]
	public function should_render_a_menu_for_dropdown_mode(): void {
		$settings = array(
			'mode'        => 'dropdown',
			'reports'     => array(
				array( 'report_id' => 'report-1', 'report_label' => 'Members CSV' ),
				array( 'report_id' => 'report-2', 'report_label' => '' ),
			),
			'format'      => 'xlsx',
			'button_text' => 'Export data',
			'full_width'  => 'yes',
			'parameters'  => array(
				array( 'param_field' => 'keyword', 'param_source' => 'manual', 'param_value' => 'gold' ),
				array( 'param_field' => 'category', 'param_source' => 'catalogue', 'param_catalogue_filter' => 'custom_field', 'param_custom_key' => 'education_level' ),
			),
		);

		$config = array(
			'restBase'   => 'https://example.test/wp-json/agend-apps/v1',
			'mode'       => 'dropdown',
			'reports'    => array(
				array( 'id' => 'report-1', 'label' => 'Members CSV' ),
				array( 'id' => 'report-2', 'label' => '' ),
			),
			'format'     => 'xlsx',
			'parameters' => array(
				array( 'field' => 'keyword', 'source' => 'manual', 'value' => 'gold', 'filter' => '', 'customKey' => '' ),
				array( 'field' => 'category', 'source' => 'catalogue', 'value' => '', 'filter' => 'custom_field', 'customKey' => 'education_level' ),
			),
			'labels'     => array(
				'working' => 'Preparing…',
				'failed'  => 'That report could not be produced. Try again shortly.',
			),
		);

		$menu_id = 'agend-export-menu-widget-42';

		$expected = '<div class="agend-export-report agend-export-report--dropdown agend-export-report--full" data-agend-export-config="' . esc_attr( (string) wp_json_encode( $config ) ) . '">'
			. '<button type="button" class="agend-export__submit agend-export__trigger" data-agend-export-trigger aria-haspopup="true" aria-expanded="false" aria-controls="' . $menu_id . '">'
			. 'Export data<span class="agend-export__caret" aria-hidden="true"></span></button>'
			. '<ul class="agend-export__menu" id="' . $menu_id . '" data-agend-export-menu hidden>'
			. '<li class="agend-export__menu-item"><button type="button" class="agend-export__item" data-agend-export-submit data-report-id="report-1">Members CSV</button></li>'
			. '<li class="agend-export__menu-item"><button type="button" class="agend-export__item" data-agend-export-submit data-report-id="report-2">report-2</button></li>'
			. '</ul></div>';

		self::assertSame( $expected, agend_apps_records_render_export_reports( $settings, array( 'id' => 'widget-42' ) ) );
	}

	// -------------------------------------------------------------------
	// "Renders nothing" guards
	// -------------------------------------------------------------------

	#[Test]
	public function should_render_nothing_for_button_mode_with_no_report_chosen(): void {
		self::assertSame( '', agend_apps_records_render_export_reports( array( 'mode' => 'button', 'report' => '' ) ) );
	}

	#[Test]
	public function should_render_nothing_for_dropdown_mode_with_no_reports_offered(): void {
		self::assertSame( '', agend_apps_records_render_export_reports( array( 'mode' => 'dropdown', 'reports' => array() ) ) );
	}

	// -------------------------------------------------------------------
	// Both settings shapes: Elementor's repeater rows, and a block's plainer
	// list of associative arrays
	// -------------------------------------------------------------------

	#[Test]
	public function should_read_reports_the_same_whether_elementor_shaped_or_plain(): void {
		// Elementor's saved repeater rows carry its own internal `_id` key
		// alongside the row's named controls; a block is expected to store
		// only the named keys.
		$elementor_shaped = array( array( '_id' => 'abc123', 'report_id' => 'report-1', 'report_label' => 'Label A' ) );
		$plain             = array( array( 'report_id' => 'report-1', 'report_label' => 'Label A' ) );

		$expected = array( array( 'id' => 'report-1', 'label' => 'Label A' ) );

		self::assertSame( $expected, agend_apps_records_export_reports_offered_reports( array( 'mode' => 'dropdown', 'reports' => $elementor_shaped ) ) );
		self::assertSame( $expected, agend_apps_records_export_reports_offered_reports( array( 'mode' => 'dropdown', 'reports' => $plain ) ) );
	}

	#[Test]
	public function should_read_parameters_the_same_whether_elementor_shaped_or_plain(): void {
		$elementor_shaped = array( array( '_id' => 'xyz789', 'param_field' => 'keyword', 'param_source' => 'manual', 'param_value' => 'gold' ) );
		$plain             = array( array( 'param_field' => 'keyword', 'param_source' => 'manual', 'param_value' => 'gold' ) );

		$expected = array( array( 'field' => 'keyword', 'source' => 'manual', 'value' => 'gold', 'filter' => '', 'customKey' => '' ) );

		self::assertSame( $expected, agend_apps_records_export_reports_parameter_map( array( 'parameters' => $elementor_shaped ) ) );
		self::assertSame( $expected, agend_apps_records_export_reports_parameter_map( array( 'parameters' => $plain ) ) );
	}

	#[Test]
	public function should_ignore_a_repeater_row_that_is_not_itself_an_array(): void {
		self::assertSame( array(), agend_apps_records_export_reports_offered_reports( array( 'mode' => 'dropdown', 'reports' => array( 'not-a-row' ) ) ) );
		self::assertSame( array(), agend_apps_records_export_reports_parameter_map( array( 'parameters' => array( 'not-a-row' ) ) ) );
	}

	// -------------------------------------------------------------------
	// The Elementor widget delegating to the core renderer
	// -------------------------------------------------------------------

	private function render_widget( array $settings ): string {
		$widget = new Agend_Elementor_Export_Reports( array(), null, $settings );

		ob_start();
		( function () {
			$this->render();
		} )->call( $widget );

		return (string) ob_get_clean();
	}

	#[Test]
	public function should_match_the_core_renderer_for_button_mode(): void {
		$settings = array( 'mode' => 'button', 'report' => 'report-1', 'button_text' => 'Download now' );

		self::assertSame(
			agend_apps_records_render_export_reports( $settings, array( 'id' => 'test-widget-id' ) ),
			$this->render_widget( $settings )
		);
	}

	#[Test]
	public function should_match_the_core_renderer_for_dropdown_mode(): void {
		$settings = array(
			'mode'    => 'dropdown',
			'reports' => array( array( 'report_id' => 'report-1', 'report_label' => 'Members CSV' ) ),
		);

		self::assertSame(
			agend_apps_records_render_export_reports( $settings, array( 'id' => 'test-widget-id' ) ),
			$this->render_widget( $settings )
		);
	}

	#[Test]
	public function should_show_the_editor_notice_only_when_the_editor_is_open(): void {
		$settings = array( 'mode' => 'button', 'report' => '' );

		\Elementor\Plugin::$instance->editor->is_edit_mode = false;
		self::assertSame( '', $this->render_widget( $settings ), 'no notice on the live front end' );

		\Elementor\Plugin::$instance->editor->is_edit_mode = true;
		self::assertSame(
			'<div class="elementor-alert elementor-alert-warning">Choose the report this button downloads.</div>',
			$this->render_widget( $settings )
		);
	}

	#[Test]
	public function should_show_the_dropdown_specific_editor_notice(): void {
		\Elementor\Plugin::$instance->editor->is_edit_mode = true;

		self::assertSame(
			'<div class="elementor-alert elementor-alert-warning">Add the reports this menu should offer.</div>',
			$this->render_widget( array( 'mode' => 'dropdown', 'reports' => array() ) )
		);
	}
}
