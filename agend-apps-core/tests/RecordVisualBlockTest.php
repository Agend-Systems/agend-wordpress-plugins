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
 * The record-image and record-block Gutenberg blocks (Agend Image and Agend
 * Panel), plus the `block`-on-`adapter` schema seam that lets a block render
 * a control for record-image's four Elementor-only settings
 * (aspect_ratio, object_fit, min_height, overlay_colour).
 *
 * BlockSurfaceTest.php and RecordSurfaceBlockTest.php belong to other work in
 * flight on this branch; this file is these two surfaces' own coverage,
 * following the same parity-test shape BlockSurfaceTest.php established.
 */
final class RecordVisualBlockTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/format.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/fields.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/record-context.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/preview-records.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/ssr-detail.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/fragments.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/record-image.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/record-block.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/blocks.php';
		Agend_Apps_Records_Record_Context::reset();
	}

	protected function tearDown(): void {
		Agend_Apps_Records_Record_Context::reset();
		parent::tearDown();
	}

	// -------------------------------------------------------------------
	// Fixtures
	// -------------------------------------------------------------------

	private function pushEvent( array $record = array(), array $extra = array() ): void {
		Agend_Apps_Records_Record_Context::push(
			'event',
			$record + array(
				'slug'           => 'sample-event',
				'name'           => 'Sample Event',
				'hero_image_url' => 'https://cdn.test/hero.jpg',
			),
			$extra + array( 'detail_url' => 'https://example.test/events/sample-event/' )
		);
	}

	private function pushListing( array $record = array(), array $extra = array() ): void {
		Agend_Apps_Records_Record_Context::push(
			'listing',
			$record + array(
				'slug'        => 'sample-listing',
				'name'        => 'Sample Listing',
				'description' => '<p>About text.</p>',
			),
			$extra + array( 'slug' => 'sample-listing' )
		);
	}

	/** @return array<string, mixed> Control defaults from a fixture, keyed by control id. */
	private function fixtureDefaults( string $surface ): array {
		$controls = json_decode(
			(string) file_get_contents( AGEND_TESTS_ROOT . '/agend-elementor/tests/fixtures/' . $surface . '-content-controls.json' ),
			true
		);

		$defaults = array();
		foreach ( $controls['sections'] as $section ) {
			foreach ( $section['controls'] as $control ) {
				if ( array_key_exists( 'default', $control['args'] ) ) {
					$defaults[ $control['id'] ] = $control['args']['default'];
				}
			}
		}

		return $defaults;
	}

	// -------------------------------------------------------------------
	// Task 1: the `block`-on-`adapter` attribute derivation
	// -------------------------------------------------------------------

	#[Test]
	public function should_derive_an_attribute_from_an_adapter_fields_block_declaration(): void {
		$schema = array(
			'sections' => array(
				array(
					'id'     => 'section_style',
					'label'  => 'Style',
					'fields' => array(
						array(
							'name'  => 'overlay_colour',
							'label' => 'Overlay colour',
							'type'  => 'adapter',
							'block' => array(
								'type'    => 'colour',
								'label'   => 'Overlay colour',
								'default' => '#000000',
							),
						),
					),
				),
			),
		);

		$attributes = agend_apps_records_block_attributes( $schema );

		self::assertSame( array( 'type' => 'string', 'default' => '#000000' ), $attributes['overlay_colour'] );
	}

	#[Test]
	public function should_derive_a_number_attribute_from_an_adapter_fields_block_declaration_ignoring_its_editor_only_keys(): void {
		$schema = array(
			'sections' => array(
				array(
					'id'     => 'section_style',
					'label'  => 'Style',
					'fields' => array(
						array(
							'name'  => 'min_height',
							'label' => 'Minimum height',
							'type'  => 'adapter',
							'block' => array(
								'type'    => 'number',
								'label'   => 'Minimum height (px)',
								'default' => 240,
								'min'     => 0,
								'max'     => 1000,
							),
						),
					),
				),
			),
		);

		$attributes = agend_apps_records_block_attributes( $schema );

		// 'min'/'max' are editor-only concerns schema-inspector.js reads
		// straight off the field declaration; the registered WP attribute
		// only ever needs a type and a default, the same as a plain `number`
		// field would derive.
		self::assertSame( array( 'type' => 'number', 'default' => 240 ), $attributes['min_height'] );
	}

	#[Test]
	public function should_derive_no_attribute_for_an_adapter_field_with_no_block_declaration(): void {
		$schema = array(
			'sections' => array(
				array(
					'id'     => 'section_style',
					'label'  => 'Style',
					'fields' => array(
						array(
							'name'  => 'selectors_only',
							'label' => 'Something builder-specific',
							'type'  => 'adapter',
						),
					),
				),
			),
		);

		$attributes = agend_apps_records_block_attributes( $schema );

		self::assertArrayNotHasKey( 'selectors_only', $attributes );
	}

	#[Test]
	public function should_derive_the_four_record_image_adapter_attributes_matching_their_schema_block_declarations(): void {
		$attributes = agend_apps_records_block_attributes( agend_apps_records_surface_schema( 'record-image' ) );

		self::assertSame( array( 'type' => 'string', 'default' => '16 / 9' ), $attributes['aspect_ratio'] );
		self::assertSame( array( 'type' => 'string', 'default' => 'cover' ), $attributes['object_fit'] );
		self::assertSame( array( 'type' => 'number', 'default' => 240 ), $attributes['min_height'] );
		self::assertSame( array( 'type' => 'string', 'default' => '' ), $attributes['overlay_colour'] );
	}

	// -------------------------------------------------------------------
	// Each surface's reason codes, through the shared resolver
	// agend_apps_records_surface_render_reason() (blocks.php), the same
	// entry point the block editor's preview route reads.
	// -------------------------------------------------------------------

	#[Test]
	public function should_report_no_reason_for_record_image_when_it_renders(): void {
		$this->pushEvent();

		self::assertSame( '', agend_apps_records_surface_render_reason( 'record-image', array() ) );
	}

	#[Test]
	public function should_report_the_no_image_reason_for_record_image(): void {
		$this->pushEvent( array( 'hero_image_url' => '' ) );

		self::assertSame( 'no_image', agend_apps_records_surface_render_reason( 'record-image', array() ) );
	}

	#[Test]
	public function should_report_no_reason_for_record_block_when_it_renders(): void {
		$this->pushEvent();

		self::assertSame( '', agend_apps_records_surface_render_reason( 'record-block', array( 'block' => 'event_facts' ) ) );
	}

	#[Test]
	public function should_report_the_wrong_type_reason_for_record_block(): void {
		$this->pushListing();

		self::assertSame( 'wrong_type', agend_apps_records_surface_render_reason( 'record-block', array( 'block' => 'event_facts' ) ) );
	}

	#[Test]
	public function should_report_the_tickets_live_only_reason_for_record_block_in_preview(): void {
		$reason = agend_apps_records_surface_render_reason(
			'record-block',
			array( 'block' => 'event_tickets' ),
			array( 'preview' => true, 'preview_type' => 'event' )
		);

		self::assertSame( 'tickets_live_only', $reason );
	}

	#[Test]
	public function should_report_the_empty_fragment_reason_for_record_block(): void {
		// No sponsors on the pushed record, so the fragment renders ''.
		Agend_Apps_Records_Record_Context::push( 'event', array( 'slug' => 'no-sponsors-event' ), array( 'slug' => 'no-sponsors-event' ) );

		self::assertSame( 'empty_fragment', agend_apps_records_surface_render_reason( 'record-block', array( 'block' => 'event_sponsors' ) ) );
	}

	#[Test]
	public function should_report_the_retired_reason_for_record_block_while_the_panel_still_renders(): void {
		$this->pushListing();
		$settings = array( 'block' => 'listing_about' );

		self::assertSame( 'retired', agend_apps_records_surface_render_reason( 'record-block', $settings ) );
		// 'retired' does not mean "renders nothing": the fragment still
		// renders, which is exactly why the block shows its notice ALONGSIDE
		// the preview markup rather than instead of it.
		self::assertNotSame( '', agend_apps_records_render_block( 'record-block', $settings ) );
	}

	// -------------------------------------------------------------------
	// record-image's `inline_style` opt: the four adapter settings, and
	// the one of them (`overlay_colour`) the opt was never meant to gate.
	// -------------------------------------------------------------------

	#[Test]
	public function should_emit_aspect_ratio_and_object_fit_as_inline_styles_only_when_inline_style_is_on(): void {
		$this->pushEvent();
		$settings = array( 'aspect_ratio' => '4 / 3', 'object_fit' => 'contain' );

		$without_inline_style = agend_apps_records_render_record_image( $settings );
		$with_inline_style    = agend_apps_records_render_record_image( $settings, array( 'inline_style' => true ) );

		self::assertStringNotContainsString( 'style=', $without_inline_style );
		self::assertStringContainsString( 'style="aspect-ratio:4 / 3;object-fit:contain;"', $with_inline_style );
	}

	#[Test]
	public function should_emit_min_height_as_an_inline_style_only_when_inline_style_is_on(): void {
		$this->pushEvent();
		// The array( 'size', 'unit' ) shape record-image/render.php builds
		// from the block's plain pixel number attribute (see the `block`
		// declaration on min_height in schema/record-image.php); the core
		// renderer is not touched for this change, so this is the shape it
		// still expects.
		$settings = array( 'mode' => 'background', 'placement' => 'block', 'min_height' => array( 'size' => 400, 'unit' => 'px' ) );

		$without_inline_style = agend_apps_records_render_record_image( $settings );
		$with_inline_style    = agend_apps_records_render_record_image( $settings, array( 'inline_style' => true ) );

		self::assertStringNotContainsString( 'min-height', $without_inline_style );
		self::assertStringContainsString( 'min-height:400px;', $with_inline_style );
	}

	#[Test]
	public function should_apply_the_overlay_colour_regardless_of_inline_style(): void {
		// Unlike the other three adapter fields, overlay_colour has always
		// been baked directly into the background-image value (see the
		// deviation note on agend_apps_records_record_image_background_value()),
		// which is why its `block` declaration needs no inline_style
		// handling of its own in record-image/render.php: it already takes
		// effect either way.
		$this->pushEvent();
		$settings = array( 'mode' => 'background', 'overlay_colour' => 'rgba(1,2,3,.5)' );

		$without_inline_style = agend_apps_records_render_record_image( $settings );
		$with_inline_style    = agend_apps_records_render_record_image( $settings, array( 'inline_style' => true ) );

		self::assertStringContainsString( 'linear-gradient(rgba(1,2,3,.5), rgba(1,2,3,.5))', $without_inline_style );
		self::assertSame( $without_inline_style, $with_inline_style );
	}

	// -------------------------------------------------------------------
	// Defaults parity: a block left at its defaults renders exactly what
	// the Elementor widget renders at its own control defaults, given the
	// same render opts on both sides (BlockSurfaceTest.php's pattern).
	// -------------------------------------------------------------------

	#[Test]
	public function should_render_what_the_elementor_image_widget_renders_at_its_control_defaults(): void {
		$this->pushEvent();

		$schema             = agend_apps_records_surface_schema( 'record-image' );
		$defaults           = array_map( static fn( array $a ) => $a['default'], agend_apps_records_block_attributes( $schema ) );
		$elementor_settings = $this->fixtureDefaults( 'record-image' );
		$block_settings     = agend_apps_records_settings_from_attributes( $schema, $defaults );

		// The same reshape record-image/render.php performs on the block's
		// plain pixel min_height attribute before handing settings to the
		// core renderer.
		if ( isset( $defaults['min_height'] ) && is_numeric( $defaults['min_height'] ) ) {
			$block_settings['min_height'] = array( 'size' => $defaults['min_height'], 'unit' => 'px' );
		}

		// Both sides given the same opt (the one a live block render always
		// uses) so the comparison exercises the same code path on both
		// sides, following should_render_what_the_elementor_filter_widget_renders...()
		// in BlockSurfaceTest.php.
		self::assertSame(
			agend_apps_records_render_record_image( $elementor_settings, array( 'inline_style' => true ) ),
			agend_apps_records_render_record_image( $block_settings, array( 'inline_style' => true ) )
		);
	}

	#[Test]
	public function should_render_what_the_elementor_panel_widget_renders_at_its_control_defaults(): void {
		$this->pushEvent();

		$schema             = agend_apps_records_surface_schema( 'record-block' );
		$defaults           = array_map( static fn( array $a ) => $a['default'], agend_apps_records_block_attributes( $schema ) );
		$elementor_settings = $this->fixtureDefaults( 'record-block' );

		self::assertSame(
			agend_apps_records_render_record_block( $elementor_settings ),
			agend_apps_records_render_block( 'record-block', $defaults )
		);
	}
}
