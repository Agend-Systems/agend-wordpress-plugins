<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend_Apps_Records_Filter_Context;
use Agend_Elementor_Filter;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;

/**
 * The Agend Filter markup moved out of the Elementor widget into core
 * (SPEC-INFRA-20260907-gutenberg-block-colours-and-surfaces US-4.1). The
 * widget's render() used to compute its "renders nothing" conditions and the
 * preview control markup itself; both now live in
 * agend-apps-core/includes/records/render/filter.php, reachable by any
 * caller, not only that widget.
 *
 * Runs in separate processes: agend_apps_records_filter_registry() memoises
 * its result for the life of the process, and the "no choices defined"
 * scenario below injects a synthetic registry entry via the
 * `agend_apps_records_filter_registry` filter, which only takes effect before
 * that memoisation happens.
 */
#[RunTestsInSeparateProcesses]
final class FilterRenderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/filters.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/filter.php';
		require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor-field-widget-trait.php';
		require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/widgets/class-agend-elementor-filter.php';
		Agend_Apps_Records_Filter_Context::reset();
	}

	// -------------------------------------------------------------------
	// Live rendering
	// -------------------------------------------------------------------

	#[Test]
	public function should_render_the_labelled_shell_live_with_no_preview_markup(): void {
		Agend_Apps_Records_Filter_Context::set( 'listing' );

		$settings = array( 'filter' => 'listing:rating' );
		$config   = agend_apps_records_filter_config( 'listing', 'rating', $settings );

		$expected = '<div class="agend-filter agend-filter--select agend-filter--rating" data-agend-filter="' . esc_attr( (string) wp_json_encode( $config ) ) . '">'
			. '<span class="agend-filter__label">Minimum rating</span>'
			. '<div class="agend-filter__control"></div>'
			. '</div>';

		self::assertSame( $expected, agend_apps_records_render_filter( $settings ) );
	}

	#[Test]
	public function should_render_nothing_live_outside_a_catalogue_context(): void {
		// No Filter_Context set: on the live site this filter is not inside
		// any catalogue's filter template.
		$settings = array( 'filter' => 'event:category' );

		self::assertSame( '', agend_apps_records_render_filter( $settings ) );
	}

	#[Test]
	public function should_still_render_outside_a_catalogue_context_when_previewing(): void {
		// A preview draws itself using its own declared type, so a designer
		// can see and style the real control even with no catalogue in scope.
		$settings = array( 'filter' => 'event:category', 'control' => 'select' );

		self::assertNotSame( '', agend_apps_records_render_filter( $settings, array( 'preview' => true ) ) );
	}

	#[Test]
	public function should_render_nothing_silently_when_the_filter_setting_has_no_colon(): void {
		$settings = array( 'filter' => 'not-a-valid-selection' );

		self::assertSame( '', agend_apps_records_render_filter( $settings ) );
		self::assertSame( '', agend_apps_records_render_filter( $settings, array( 'preview' => true ) ) );
		self::assertSame( '', agend_apps_records_filter_render_reason( $settings, '' ), 'nothing more specific to say than "renders nothing"' );
	}

	// -------------------------------------------------------------------
	// Preview stand-in control markup, per presentation
	// -------------------------------------------------------------------

	/** @return array<string, array{string, string, array<string, mixed>, string}> */
	public static function previewControls(): array {
		return array(
			'search'     => array(
				'event',
				'search',
				array(),
				'<input type="search" placeholder="Search" />',
			),
			'date'       => array(
				'event',
				'date_from',
				array(),
				'<input type="date" />',
			),
			'reset'      => array(
				'event',
				'reset',
				array(),
				'<button type="button" class="agend-filter__button agend-filter__reset">Clear filters</button>',
			),
			'range'      => array(
				'listing',
				'custom_field',
				array( 'custom_field_key' => 'education_level', 'control' => 'range' ),
				'<div class="agend-filter__range"><input type="number" placeholder="Min" /><input type="number" placeholder="Max" /></div>',
			),
			'select'     => array(
				'event',
				'category',
				array( 'control' => 'select' ),
				'<select><option>Any Category</option><option>Example category one</option><option>Example category two</option><option>Example category three</option></select>',
			),
			'checkboxes' => array(
				'event',
				'category',
				array( 'control' => 'checkboxes' ),
				'<div class="agend-filter__options">'
					. '<label class="agend-filter__option"><input type="checkbox" /><span>Example category one</span></label>'
					. '<label class="agend-filter__option"><input type="checkbox" /><span>Example category two</span></label>'
					. '<label class="agend-filter__option"><input type="checkbox" /><span>Example category three</span></label>'
					. '</div>',
			),
			'buttons'    => array(
				'event',
				'venue_type',
				array( 'control' => 'buttons' ),
				'<div class="agend-filter__options">'
					. '<button type="button" class="agend-filter__button is-active" aria-pressed="true">Any Format</button>'
					. '<button type="button" class="agend-filter__button" aria-pressed="false">In-Person</button>'
					. '<button type="button" class="agend-filter__button" aria-pressed="false">Online</button>'
					. '<button type="button" class="agend-filter__button" aria-pressed="false">Hybrid</button>'
					. '</div>',
			),
		);
	}

	#[Test]
	#[DataProvider( 'previewControls' )]
	public function should_draw_the_stand_in_control_markup_for_a_preview( string $type, string $key, array $extra, string $expected_control_markup ): void {
		$settings = array( 'filter' => "$type:$key" ) + $extra;
		$config   = agend_apps_records_filter_config( $type, $key, $settings );
		self::assertNotNull( $config, 'the scenario must resolve to a real filter config' );

		$classes  = 'agend-filter agend-filter--' . sanitize_html_class( $config['control'] ) . ' agend-filter--' . sanitize_html_class( $key ) . ' agend-filter--preview';
		$expected = '<div class="' . $classes . '" data-agend-filter="' . esc_attr( (string) wp_json_encode( $config ) ) . '">';
		if ( $config['showLabel'] && '' !== $config['label'] ) {
			$expected .= '<span class="agend-filter__label">' . esc_html( $config['label'] ) . '</span>';
		}
		$expected .= '<div class="agend-filter__control">' . $expected_control_markup . '</div></div>';

		self::assertSame( $expected, agend_apps_records_render_filter( $settings, array( 'preview' => true ) ) );
	}

	// -------------------------------------------------------------------
	// "Renders nothing" guards and their reasons
	// -------------------------------------------------------------------

	#[Test]
	public function should_give_the_wrong_catalogue_reason_and_render_nothing(): void {
		Agend_Apps_Records_Filter_Context::set( 'event' );
		$settings = array( 'filter' => 'listing:category' );

		self::assertSame( 'wrong_catalogue', agend_apps_records_filter_render_reason( $settings, 'event' ) );
		self::assertSame( '', agend_apps_records_render_filter( $settings ) );
		self::assertSame( '', agend_apps_records_render_filter( $settings, array( 'preview' => true ) ), 'a mismatched catalogue renders nothing even in a preview' );
	}

	#[Test]
	public function should_give_the_unconfigured_reason_for_a_filter_the_type_does_not_offer(): void {
		$settings = array( 'filter' => 'event:not-a-real-filter' );

		self::assertSame( 'unconfigured', agend_apps_records_filter_render_reason( $settings, '' ) );
		self::assertSame( '', agend_apps_records_render_filter( $settings, array( 'preview' => true ) ) );
	}

	#[Test]
	public function should_give_the_missing_field_key_reason_for_a_custom_field_filter_with_no_key(): void {
		// A custom field filter's needs_key check now runs before
		// agend_apps_records_filter_config() is called, so this specific
		// case wins over the more generic 'unconfigured' reason -- an author
		// who picked a custom field filter and left the key blank should see
		// "enter the custom field key", not "not configured yet".
		$settings = array( 'filter' => 'listing:custom_field', 'custom_field_key' => '  ' );

		self::assertSame( 'missing_field_key', agend_apps_records_filter_render_reason( $settings, '' ) );
		self::assertSame( '', agend_apps_records_render_filter( $settings, array( 'preview' => true ) ), 'the front end is unchanged: both reasons already render nothing' );
	}

	#[Test]
	public function should_give_the_no_choices_reason_when_a_choices_only_filter_defines_none(): void {
		// Unlike missing_field_key, this one genuinely cannot be reached
		// through the public API as it stands: no descriptor in the live
		// registry sets choices_only, so the condition is unreachable with
		// real data. Reaching it still needs the registry filter -- the same
		// hook a site would use to add a filter of its own -- left as-is
		// rather than "fixed", since there is no ordering bug to fix here.
		add_filter(
			'agend_apps_records_filter_registry',
			static function ( array $registry ): array {
				$registry['event']['choices_only_test'] = array(
					'label'        => 'Choices only test',
					'state'        => 'choicesOnlyTest',
					'mode'         => 'scalar',
					'controls'     => array( 'select' ),
					'source'       => null,
					'choices_only' => true,
				);
				return $registry;
			}
		);

		$settings = array( 'filter' => 'event:choices_only_test' );

		self::assertSame( 'no_choices', agend_apps_records_filter_render_reason( $settings, '' ) );
		self::assertSame( '', agend_apps_records_render_filter( $settings, array( 'preview' => true ) ) );
	}

	// -------------------------------------------------------------------
	// The Elementor widget delegating to the core renderer
	// -------------------------------------------------------------------

	private function render_widget( array $settings ): string {
		$widget = new Agend_Elementor_Filter( array(), null, $settings );

		ob_start();
		( function () {
			$this->render();
		} )->call( $widget );

		return (string) ob_get_clean();
	}

	#[Test]
	public function should_match_the_core_renderer_live_when_the_editor_is_closed(): void {
		Agend_Apps_Records_Filter_Context::set( 'listing' );
		\Elementor\Plugin::$instance->editor->is_edit_mode = false;

		$settings = array( 'filter' => 'listing:rating' );

		self::assertSame(
			agend_apps_records_render_filter( $settings, array( 'preview' => false ) ),
			$this->render_widget( $settings )
		);
	}

	#[Test]
	public function should_match_the_core_renderer_preview_when_the_editor_is_open(): void {
		\Elementor\Plugin::$instance->editor->is_edit_mode = true;

		$settings = array( 'filter' => 'event:category', 'control' => 'buttons' );

		self::assertSame(
			agend_apps_records_render_filter( $settings, array( 'preview' => true ) ),
			$this->render_widget( $settings )
		);
	}

	#[Test]
	public function should_show_the_editor_notice_for_each_reason_only_when_the_editor_is_open(): void {
		Agend_Apps_Records_Filter_Context::set( 'event' );
		$settings = array( 'filter' => 'listing:category' );

		\Elementor\Plugin::$instance->editor->is_edit_mode = false;
		self::assertSame( '', $this->render_widget( $settings ), 'no notice on the live front end' );

		\Elementor\Plugin::$instance->editor->is_edit_mode = true;
		self::assertSame(
			'<div class="elementor-alert elementor-alert-warning">This filter belongs to a different catalogue, so it renders nothing here.</div>',
			$this->render_widget( $settings )
		);
	}
}
