<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend_Apps_Records_Record_Context;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;

/**
 * The three templated record surfaces shipped as blocks in this change: Agend
 * Field, Agend Pills and Agend Link / Button. Each is a thin adapter over the
 * same schema and core-renderer seam every other block uses (see
 * BlockSurfaceTest), so these tests pin the same three things that file pins
 * for the surfaces it covers: the schema-to-attribute derivation, the
 * per-instance render-nothing reason codes a block's own editor view
 * surfaces, and defaults parity against the matching Elementor widget's
 * recorded control defaults.
 *
 * A new file rather than an addition to BlockSurfaceTest, per this task's own
 * instructions, so as not to collide with concurrent work on that file.
 */
#[RunTestsInSeparateProcesses]
final class RecordSurfaceBlockTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/format.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/fields.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/record-context.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/preview-records.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/settings.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/pages.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/record-field.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/record-pills.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/record-link.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/blocks.php';
		Agend_Apps_Records_Record_Context::reset();
	}

	protected function tearDown(): void {
		Agend_Apps_Records_Record_Context::reset();
		parent::tearDown();
	}

	// -------------------------------------------------------------------
	// Schema -> block attribute derivation
	// -------------------------------------------------------------------

	#[Test]
	public function should_derive_the_expected_attributes_for_record_field(): void {
		$attributes = agend_apps_records_block_attributes( agend_apps_records_surface_schema( 'record-field' ) );

		self::assertSame( array( 'type' => 'string', 'default' => 'common:title' ), $attributes['field'] );
		self::assertSame( array( 'type' => 'boolean', 'default' => false ), $attributes['link_to_detail'] );
		self::assertSame( array( 'type' => 'boolean', 'default' => false ), $attributes['show_label'] );
		self::assertSame( array( 'type' => 'string', 'default' => 'div' ), $attributes['html_tag'] );
		self::assertSame( array( 'type' => 'number', 'default' => 0 ), $attributes['list_max'] );
		self::assertArrayNotHasKey( 'label_heading', $attributes, 'headings store no value' );
	}

	#[Test]
	public function should_derive_the_expected_attributes_for_record_pills(): void {
		$attributes = agend_apps_records_block_attributes( agend_apps_records_surface_schema( 'record-pills' ) );

		self::assertSame( array( 'type' => 'string', 'default' => 'common:category' ), $attributes['field'] );
		self::assertSame( array( 'type' => 'number', 'default' => 0 ), $attributes['max_items'] );
		self::assertSame( array( 'type' => 'boolean', 'default' => false ), $attributes['link_to_detail'] );
		self::assertArrayNotHasKey( 'label_heading', $attributes, 'headings store no value' );
	}

	#[Test]
	public function should_derive_the_expected_attributes_for_record_link(): void {
		$attributes = agend_apps_records_block_attributes( agend_apps_records_surface_schema( 'record-link' ) );

		self::assertSame( array( 'type' => 'string', 'default' => 'detail' ), $attributes['action'] );
		self::assertSame( array( 'type' => 'string', 'default' => 'button' ), $attributes['style_as'] );
		self::assertSame( array( 'type' => 'boolean', 'default' => false ), $attributes['full_width'] );
		self::assertSame( array( 'type' => 'boolean', 'default' => false ), $attributes['hide_when_registered'] );
		// Both of these carry a literal boolean `true` default in the schema
		// (rather than the string 'yes'), which is the branch
		// agend_apps_records_toggle_is_on() exists to also recognise.
		self::assertSame( array( 'type' => 'boolean', 'default' => true ), $attributes['disabled_when_sold_out'] );
		self::assertSame( array( 'type' => 'boolean', 'default' => true ), $attributes['hide_when_enrolled'] );
	}

	// -------------------------------------------------------------------
	// Render-nothing reason codes, read through the shared
	// agend_apps_records_surface_render_reason() seam every surface's block
	// editor view calls.
	// -------------------------------------------------------------------

	#[Test]
	public function should_give_the_field_not_applicable_reason_for_record_field(): void {
		Agend_Apps_Records_Record_Context::push( 'event', array( 'name' => 'Sample Gala' ) );

		self::assertSame(
			'field_not_applicable',
			agend_apps_records_surface_render_reason( 'record-field', array( 'field' => 'course:title' ) )
		);
	}

	#[Test]
	public function should_give_no_reason_for_record_field_at_a_matching_record_type(): void {
		Agend_Apps_Records_Record_Context::push( 'event', array( 'name' => 'Sample Gala' ) );

		self::assertSame(
			'',
			agend_apps_records_surface_render_reason( 'record-field', array( 'field' => 'event:name' ) )
		);
	}

	#[Test]
	public function should_give_the_field_not_applicable_reason_for_record_pills(): void {
		Agend_Apps_Records_Record_Context::push( 'event', array() );

		self::assertSame(
			'field_not_applicable',
			agend_apps_records_surface_render_reason( 'record-pills', array( 'field' => 'listing:category' ) )
		);
	}

	#[Test]
	public function should_give_the_no_terms_reason_for_record_pills(): void {
		Agend_Apps_Records_Record_Context::push( 'event', array() );

		self::assertSame(
			'no_terms',
			agend_apps_records_surface_render_reason( 'record-pills', array( 'field' => 'event:tags' ) )
		);
	}

	#[Test]
	public function should_give_the_ical_wrong_type_reason_for_record_link(): void {
		Agend_Apps_Records_Record_Context::push( 'course', array() );

		self::assertSame(
			'ical_wrong_type',
			agend_apps_records_surface_render_reason( 'record-link', array( 'action' => 'ical' ) )
		);
	}

	#[Test]
	public function should_give_the_enrol_wrong_type_reason_for_record_link(): void {
		Agend_Apps_Records_Record_Context::push( 'event', array() );

		self::assertSame(
			'enrol_wrong_type',
			agend_apps_records_surface_render_reason( 'record-link', array( 'action' => 'enrol' ) )
		);
	}

	#[Test]
	public function should_give_the_register_wrong_type_reason_for_record_link(): void {
		Agend_Apps_Records_Record_Context::push( 'course', array() );

		self::assertSame(
			'register_wrong_type',
			agend_apps_records_surface_render_reason( 'record-link', array( 'action' => 'register' ) )
		);
	}

	// -------------------------------------------------------------------
	// Defaults parity against the Elementor widget's recorded control
	// defaults. Each of these three surfaces renders nothing without a
	// record in scope, so both sides are compared with the same 'preview'
	// opt, mirroring the filter parity test in BlockSurfaceTest: it is the
	// stand-in preview markup, drawn identically from the same shared
	// settings, that this pins.
	// -------------------------------------------------------------------

	/**
	 * Reads a `-content-controls.json` fixture's recorded Content-tab
	 * defaults as an Elementor settings array, the same shape every parity
	 * test in BlockSurfaceTest builds.
	 *
	 * @param string $surface Surface id.
	 * @return array<string, mixed>
	 */
	private function elementorDefaults( string $surface ): array {
		$controls = json_decode(
			(string) file_get_contents( AGEND_TESTS_ROOT . '/agend-elementor/tests/fixtures/' . $surface . '-content-controls.json' ),
			true
		);

		$settings = array();
		foreach ( $controls['sections'] as $section ) {
			foreach ( $section['controls'] as $control ) {
				if ( array_key_exists( 'default', $control['args'] ) ) {
					$settings[ $control['id'] ] = $control['args']['default'];
				}
			}
		}

		return $settings;
	}

	#[Test]
	public function should_render_what_the_elementor_record_field_widget_renders_at_its_control_defaults_when_the_block_is_left_at_its_defaults(): void {
		$schema   = agend_apps_records_surface_schema( 'record-field' );
		$defaults = array_map( static fn( array $a ) => $a['default'], agend_apps_records_block_attributes( $schema ) );

		self::assertSame(
			agend_apps_records_render_record_field( $this->elementorDefaults( 'record-field' ), array( 'preview' => true ) ),
			agend_apps_records_render_record_field(
				agend_apps_records_settings_from_attributes( $schema, $defaults ),
				array( 'preview' => true )
			)
		);
	}

	#[Test]
	public function should_render_what_the_elementor_record_pills_widget_renders_at_its_control_defaults_when_the_block_is_left_at_its_defaults(): void {
		$schema   = agend_apps_records_surface_schema( 'record-pills' );
		$defaults = array_map( static fn( array $a ) => $a['default'], agend_apps_records_block_attributes( $schema ) );

		self::assertSame(
			agend_apps_records_render_record_pills( $this->elementorDefaults( 'record-pills' ), array( 'preview' => true ) ),
			agend_apps_records_render_record_pills(
				agend_apps_records_settings_from_attributes( $schema, $defaults ),
				array( 'preview' => true )
			)
		);
	}

	#[Test]
	public function should_render_what_the_elementor_record_link_widget_renders_at_its_control_defaults_when_the_block_is_left_at_its_defaults(): void {
		$schema   = agend_apps_records_surface_schema( 'record-link' );
		$defaults = array_map( static fn( array $a ) => $a['default'], agend_apps_records_block_attributes( $schema ) );

		self::assertSame(
			agend_apps_records_render_record_link( $this->elementorDefaults( 'record-link' ), array( 'preview' => true ) ),
			agend_apps_records_render_record_link(
				agend_apps_records_settings_from_attributes( $schema, $defaults ),
				array( 'preview' => true )
			)
		);
	}
}
