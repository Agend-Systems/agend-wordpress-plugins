<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend_Apps_Records_Record_Context;
use Agend_Elementor_Record_Image;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The Agend Image widget's render logic moved out of the Elementor widget
 * into core (SPEC-INFRA-20260907-gutenberg-block-colours-and-surfaces US-4.1
 * follow-up). agend_apps_records_render_record_image() and its companion
 * agend_apps_records_record_image_render_reason() now live in
 * agend-apps-core/includes/records/render/record-image.php, reachable by any
 * caller, not only the Elementor widget.
 */
final class RecordImageRenderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/format.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/fields.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/record-context.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/preview-records.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/record-image.php';
		require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor-field-widget-trait.php';
		require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/widgets/class-agend-elementor-record-image.php';
		Agend_Apps_Records_Record_Context::reset();
	}

	protected function tearDown(): void {
		Agend_Apps_Records_Record_Context::reset();
		parent::tearDown();
	}

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

	// -------------------------------------------------------------------
	// Rendering against a pushed record context
	// -------------------------------------------------------------------

	#[Test]
	public function should_render_an_img_element_against_the_pushed_record(): void {
		$this->pushEvent();

		$expected = '<img class="agend-record-image agend-record-image--img" src="https://cdn.test/hero.jpg" alt="Sample Event" loading="lazy" />';

		self::assertSame( $expected, agend_apps_records_render_record_image( array() ) );
		self::assertSame( '', agend_apps_records_record_image_render_reason( array() ) );
	}

	#[Test]
	public function should_link_the_img_to_the_detail_page_when_link_to_detail_is_enabled(): void {
		$this->pushEvent();

		$expected = '<a class="agend-record-image__link" href="https://example.test/events/sample-event/">'
			. '<img class="agend-record-image agend-record-image--img" src="https://cdn.test/hero.jpg" alt="Sample Event" loading="lazy" /></a>';

		self::assertSame( $expected, agend_apps_records_render_record_image( array( 'link_to_detail' => 'yes' ) ) );
	}

	#[Test]
	public function should_not_link_the_img_when_the_card_is_already_one_big_link(): void {
		$this->pushEvent( array(), array( 'in_card_link' => true ) );

		$expected = '<img class="agend-record-image agend-record-image--img" src="https://cdn.test/hero.jpg" alt="Sample Event" loading="lazy" />';

		self::assertSame( $expected, agend_apps_records_render_record_image( array( 'link_to_detail' => 'yes' ) ) );
	}

	// -------------------------------------------------------------------
	// The preview path (no pushed context; an editor previews a record)
	// -------------------------------------------------------------------

	#[Test]
	public function should_render_against_the_preview_record_when_no_context_is_pushed(): void {
		// The placeholder event record has no hero_image_url, so the
		// fallback proves the preview record really was resolved and read.
		$settings = array( 'fallback_image' => array( 'url' => 'https://cdn.test/fallback.jpg', 'id' => 7 ) );

		$expected = '<img class="agend-record-image agend-record-image--img" src="https://cdn.test/fallback.jpg" alt="Sample Event" loading="lazy" />';

		self::assertSame(
			$expected,
			agend_apps_records_render_record_image( $settings, array( 'preview' => true, 'preview_type' => 'event' ) )
		);
	}

	// -------------------------------------------------------------------
	// "Renders nothing" and its one reason code
	// -------------------------------------------------------------------

	#[Test]
	public function should_give_the_no_image_reason_when_the_record_has_no_image_and_no_fallback(): void {
		$this->pushEvent( array( 'hero_image_url' => '' ) );

		self::assertSame( 'no_image', agend_apps_records_record_image_render_reason( array() ) );
		self::assertSame( '', agend_apps_records_render_record_image( array() ) );
	}

	#[Test]
	public function should_render_nothing_with_no_reason_when_no_record_context_is_in_scope(): void {
		// No push, and not a preview: nothing more specific to say than
		// "renders nothing", matching agend_apps_records_filter_render_reason()'s
		// own "not a reason either" case.
		self::assertSame( '', agend_apps_records_record_image_render_reason( array() ) );
		self::assertSame( '', agend_apps_records_render_record_image( array() ) );
	}

	// -------------------------------------------------------------------
	// The fallback image, in both value shapes
	// -------------------------------------------------------------------

	#[Test]
	public function should_read_the_fallback_image_from_the_media_control_array_shape(): void {
		$this->pushEvent( array( 'hero_image_url' => '' ) );

		$settings = array( 'fallback_image' => array( 'url' => 'https://cdn.test/fallback.jpg', 'id' => 7 ) );

		self::assertSame(
			'<img class="agend-record-image agend-record-image--img" src="https://cdn.test/fallback.jpg" alt="Sample Event" loading="lazy" />',
			agend_apps_records_render_record_image( $settings )
		);
	}

	#[Test]
	public function should_read_the_fallback_image_from_a_plain_url_string(): void {
		$this->pushEvent( array( 'hero_image_url' => '' ) );

		$settings = array( 'fallback_image' => 'https://cdn.test/plain-fallback.jpg' );

		self::assertSame(
			'<img class="agend-record-image agend-record-image--img" src="https://cdn.test/plain-fallback.jpg" alt="Sample Event" loading="lazy" />',
			agend_apps_records_render_record_image( $settings )
		);
	}

	// -------------------------------------------------------------------
	// Background mode: placements
	// -------------------------------------------------------------------

	/** @return array<string, array{string, string}> placement setting => expected class/target */
	public static function placements(): array {
		return array(
			'fill default'    => array( '', 'fill' ),
			'fill explicit'   => array( 'fill', 'fill' ),
			'parent'          => array( 'parent', 'parent' ),
			'block'           => array( 'block', 'block' ),
			'invalid'         => array( 'not-a-real-placement', 'fill' ),
		);
	}

	#[Test]
	#[DataProvider( 'placements' )]
	public function should_render_the_background_element_for_each_placement( string $setting, string $expected_placement ): void {
		$this->pushEvent();

		$settings = array( 'mode' => 'background' );
		if ( '' !== $setting ) {
			$settings['placement'] = $setting;
		}

		$style = "background-image:url('https://cdn.test/hero.jpg');background-size:cover;background-position:center center;";

		$expected = '<div class="agend-record-image agend-record-image--bg agend-record-image--' . $expected_placement . '"'
			. ' style="' . esc_attr( $style ) . '"'
			. ' role="img" aria-label="Sample Event"'
			. ' data-agend-bg-url="' . esc_url( 'https://cdn.test/hero.jpg' ) . '"'
			. ' data-agend-bg-style="' . esc_attr( $style ) . '"'
			. ( 'parent' === $expected_placement ? ' data-agend-bg-target="parent"' : '' )
			. '></div>';

		self::assertSame( $expected, agend_apps_records_render_record_image( $settings ) );
	}

	#[Test]
	public function should_apply_a_custom_background_size_and_position(): void {
		$this->pushEvent();

		$settings = array( 'mode' => 'background', 'background_size' => 'contain', 'background_position' => 'left center' );
		$style    = "background-image:url('https://cdn.test/hero.jpg');background-size:contain;background-position:left center;";

		$html = agend_apps_records_render_record_image( $settings );

		self::assertStringContainsString( 'style="' . esc_attr( $style ) . '"', $html );
	}

	#[Test]
	public function should_darken_towards_the_bottom_when_the_overlay_gradient_is_enabled(): void {
		$this->pushEvent();

		$settings = array( 'mode' => 'background', 'overlay_gradient' => 'yes' );
		$style    = "background-image:linear-gradient(180deg, rgba(30,42,74,0.35), rgba(30,42,74,0.85)), url('https://cdn.test/hero.jpg');background-size:cover;background-position:center center;";

		$html = agend_apps_records_render_record_image( $settings );

		self::assertStringContainsString( 'style="' . esc_attr( $style ) . '"', $html );
	}

	#[Test]
	public function should_layer_the_overlay_colour_the_same_way_whether_or_not_inline_style_is_on(): void {
		$this->pushEvent();

		$settings = array( 'mode' => 'background', 'overlay_colour' => 'rgba(0,0,0,.4)' );
		$style    = "background-image:linear-gradient(rgba(0,0,0,.4), rgba(0,0,0,.4)), url('https://cdn.test/hero.jpg');background-size:cover;background-position:center center;";

		$without_inline_style = agend_apps_records_render_record_image( $settings );
		$with_inline_style    = agend_apps_records_render_record_image( $settings, array( 'inline_style' => true ) );

		// overlay_colour is not covered by the inline_style opt: it has
		// always been baked into the background-image value directly, so
		// turning the opt on must not add or duplicate anything for it.
		self::assertStringContainsString( 'style="' . esc_attr( $style ) . '"', $without_inline_style );
		self::assertSame( $without_inline_style, $with_inline_style );
	}

	// -------------------------------------------------------------------
	// The `inline_style` opt
	// -------------------------------------------------------------------

	#[Test]
	public function should_not_add_a_style_attribute_to_the_img_when_inline_style_is_off(): void {
		$this->pushEvent();

		$settings = array( 'aspect_ratio' => '1 / 1', 'object_fit' => 'contain' );

		$expected = '<img class="agend-record-image agend-record-image--img" src="https://cdn.test/hero.jpg" alt="Sample Event" loading="lazy" />';

		self::assertSame( $expected, agend_apps_records_render_record_image( $settings ) );
		self::assertSame( $expected, agend_apps_records_render_record_image( $settings, array( 'inline_style' => false ) ) );
	}

	#[Test]
	public function should_apply_aspect_ratio_and_object_fit_as_inline_styles_on_the_img_when_asked(): void {
		$this->pushEvent();

		$settings = array( 'aspect_ratio' => '1 / 1', 'object_fit' => 'contain' );

		$expected = '<img class="agend-record-image agend-record-image--img" style="aspect-ratio:1 / 1;object-fit:contain;" src="https://cdn.test/hero.jpg" alt="Sample Event" loading="lazy" />';

		self::assertSame( $expected, agend_apps_records_render_record_image( $settings, array( 'inline_style' => true ) ) );
	}

	#[Test]
	public function should_use_the_elementor_control_defaults_for_inline_styles_when_unset(): void {
		$this->pushEvent();

		$expected = '<img class="agend-record-image agend-record-image--img" style="aspect-ratio:16 / 9;object-fit:cover;" src="https://cdn.test/hero.jpg" alt="Sample Event" loading="lazy" />';

		self::assertSame( $expected, agend_apps_records_render_record_image( array(), array( 'inline_style' => true ) ) );
	}

	#[Test]
	public function should_not_add_min_height_to_the_background_style_when_inline_style_is_off(): void {
		$this->pushEvent();

		$settings = array( 'mode' => 'background', 'placement' => 'block', 'min_height' => array( 'size' => 300, 'unit' => 'vh' ) );

		$html = agend_apps_records_render_record_image( $settings );

		self::assertStringNotContainsString( 'min-height', $html );
	}

	#[Test]
	public function should_apply_a_custom_min_height_to_the_sized_background_block_when_inline_style_is_on(): void {
		$this->pushEvent();

		$settings = array( 'mode' => 'background', 'placement' => 'block', 'min_height' => array( 'size' => 300, 'unit' => 'vh' ) );
		$style    = "background-image:url('https://cdn.test/hero.jpg');background-size:cover;background-position:center center;min-height:300vh;";

		$html = agend_apps_records_render_record_image( $settings, array( 'inline_style' => true ) );

		self::assertStringContainsString( 'style="' . esc_attr( $style ) . '"', $html );
	}

	#[Test]
	public function should_use_the_default_min_height_when_unset_and_inline_style_is_on(): void {
		$this->pushEvent();

		$settings = array( 'mode' => 'background', 'placement' => 'block' );
		$style    = "background-image:url('https://cdn.test/hero.jpg');background-size:cover;background-position:center center;min-height:240px;";

		$html = agend_apps_records_render_record_image( $settings, array( 'inline_style' => true ) );

		self::assertStringContainsString( 'style="' . esc_attr( $style ) . '"', $html );
	}

	#[Test]
	public function should_not_apply_min_height_when_the_placement_is_not_block_even_with_inline_style_on(): void {
		$this->pushEvent();

		$settings = array( 'mode' => 'background', 'placement' => 'fill', 'min_height' => array( 'size' => 300, 'unit' => 'vh' ) );

		$html = agend_apps_records_render_record_image( $settings, array( 'inline_style' => true ) );

		self::assertStringNotContainsString( 'min-height', $html );
	}

	// -------------------------------------------------------------------
	// The Elementor widget delegating to the core renderer
	// -------------------------------------------------------------------

	private function render_widget( array $settings ): string {
		$widget = new Agend_Elementor_Record_Image( array(), null, $settings );

		ob_start();
		( function () {
			$this->render();
		} )->call( $widget );

		return (string) ob_get_clean();
	}

	#[Test]
	public function should_match_the_core_renderer_when_the_widget_delegates_to_it(): void {
		$this->pushEvent();

		self::assertSame(
			agend_apps_records_render_record_image( array() ),
			$this->render_widget( array() )
		);
	}

	#[Test]
	public function should_show_the_editor_notice_for_no_image_only_when_the_editor_is_open(): void {
		$this->pushEvent( array( 'hero_image_url' => '' ) );

		\Elementor\Plugin::$instance->editor->is_edit_mode = false;
		self::assertSame( '', $this->render_widget( array() ), 'no notice on the live front end' );

		\Elementor\Plugin::$instance->editor->is_edit_mode = true;
		self::assertSame(
			'<div class="elementor-alert elementor-alert-warning">This record has no image and no fallback image is set.</div>',
			$this->render_widget( array() )
		);
	}
}
