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
 * The server half of the Range brackets presentation: the first-paint stand-in
 * a visitor gets (the same disabled, aria-busy shape as every other control),
 * the config the script reads off it, what an editor preview draws, and the
 * notes an editor sees when brackets are missing or lines were skipped.
 *
 * Runs in separate processes for the reason FilterRenderTest does, and so the
 * facets stub function below never reaches another test.
 */
#[RunTestsInSeparateProcesses]
final class FilterRangeBracketsRenderTest extends TestCase {

	private const LIST = "Under 10,000 | | 9999\n10,000 to 30,000 | 10000 | 30000\nOver 30,000 | 30001 |";

	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/palette.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/filters.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/filter.php';
		require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor-field-widget-trait.php';
		require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/widgets/class-agend-elementor-filter.php';
		Agend_Apps_Records_Filter_Context::reset();
		Agend_Apps_Records_Filter_Context::set( 'listing' );
		$GLOBALS['agend_test_facets_requests'] = array();
	}

	/**
	 * @param array<string, mixed> $extra Settings added to a Range brackets custom field filter.
	 * @return array<string, mixed>
	 */
	private function settings( array $extra = array() ): array {
		return $extra + array(
			'filter'           => 'listing:custom_field',
			'custom_field_key' => 'income',
			'control'          => 'range_brackets',
		);
	}

	/** Reads the config off the shell's data attribute, as the script does. */
	private function config_from( string $html ): array {
		self::assertSame( 1, preg_match( '/data-agend-filter="([^"]*)"/', $html, $match ) );
		return (array) json_decode( html_entity_decode( $match[1], ENT_QUOTES ), true );
	}

	private function use_facets_stub( $response ): void {
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/tests/fixtures/directory-facets-stub.php';
		$GLOBALS['agend_test_facets_response'] = $response;
	}

	// -------------------------------------------------------------------
	// First paint (live)
	// -------------------------------------------------------------------

	/** @return array<string, array{string, string}> */
	public static function placeholders(): array {
		return array(
			'dropdown' => array(
				'select',
				'<select class="agend-filter__brackets agend-filter__placeholder" aria-label="Custom field" aria-busy="true" disabled><option>Any</option></select>',
			),
			'buttons'  => array(
				'buttons',
				'<div class="agend-filter__options agend-filter__brackets agend-filter__brackets--buttons agend-filter__placeholder" role="group" aria-label="Custom field" aria-busy="true"><button type="button" class="agend-filter__button is-active" aria-pressed="true" disabled>Any</button></div>',
			),
			'radio'    => array(
				'radio',
				'<div class="agend-filter__options agend-filter__brackets agend-filter__brackets--radio agend-filter__placeholder" role="radiogroup" aria-label="Custom field" aria-busy="true"><label class="agend-filter__option"><input type="radio" name="agend-filter-brackets-income" checked disabled /><span>Any</span></label></div>',
			),
		);
	}

	#[Test]
	#[DataProvider( 'placeholders' )]
	public function should_render_a_disabled_busy_stand_in_with_only_any_in_the_chosen_display( string $display, string $control ): void {
		$settings = $this->settings( array( 'range_brackets_display' => $display, 'range_brackets_custom' => self::LIST ) );
		$config   = agend_apps_records_filter_config( 'listing', 'custom_field', $settings );

		$expected = '<div class="agend-filter agend-filter--range_brackets agend-filter--custom_field" data-agend-filter="' . esc_attr( (string) wp_json_encode( $config ) ) . '">'
			. '<span class="agend-filter__label">Custom field</span>'
			. '<div class="agend-filter__control">' . $control . '</div>'
			. '</div>';

		self::assertSame( $expected, agend_apps_records_render_filter( $settings ) );
	}

	#[Test]
	public function should_use_the_any_label_setting_in_the_stand_in(): void {
		$html = agend_apps_records_render_filter( $this->settings( array( 'range_brackets_any_label' => 'Any income' ) ) );

		self::assertStringContainsString( '<option>Any income</option>', $html );
	}

	#[Test]
	public function should_pass_the_script_its_brackets_and_settings_in_the_shell_config(): void {
		$html   = agend_apps_records_render_filter(
			$this->settings(
				array(
					'range_brackets_source'    => 'custom',
					'range_brackets_display'   => 'buttons',
					'range_brackets_any_label' => 'Everything',
					'range_brackets_custom'    => self::LIST . "\nnot a bracket",
				)
			)
		);
		$config = $this->config_from( $html );

		self::assertSame( 'range_brackets', $config['control'] );
		self::assertSame( 'custom_fields', $config['state'] );
		self::assertSame( 'map', $config['mode'] );
		self::assertSame( 'income', $config['fieldKey'] );
		self::assertSame( array( 'facet' => 'custom.income' ), $config['source'] );
		self::assertSame( 'custom', $config['rangeBrackets']['source'] );
		self::assertSame( 'buttons', $config['rangeBrackets']['display'] );
		self::assertSame( 'Everything', $config['rangeBrackets']['anyLabel'] );
		self::assertSame(
			array(
				array( 'label' => 'Under 10,000', 'min' => null, 'max' => 9999 ),
				array( 'label' => '10,000 to 30,000', 'min' => 10000, 'max' => 30000 ),
				array( 'label' => 'Over 30,000', 'min' => 30001, 'max' => null ),
			),
			$config['rangeBrackets']['custom'],
			'validated rows only, with open ends as null'
		);
	}

	#[Test]
	public function should_still_draw_the_stand_in_for_agends_brackets_because_only_the_page_can_ask_the_facet(): void {
		$this->use_facets_stub( array( 'data' => array() ) );

		$html = agend_apps_records_render_filter( $this->settings() );

		self::assertStringContainsString( 'aria-busy="true"', $html );
		self::assertStringContainsString( '<option>Any</option>', $html );
		self::assertSame( array(), $GLOBALS['agend_test_facets_requests'], 'a live render never costs a gateway call' );
	}

	#[Test]
	public function should_render_nothing_live_for_custom_brackets_with_no_usable_line(): void {
		foreach ( array( '', "\n\n", "only a label\nanother | x | y\nbackwards | 9 | 1" ) as $list ) {
			self::assertSame(
				'',
				agend_apps_records_render_filter( $this->settings( array( 'range_brackets_source' => 'custom', 'range_brackets_custom' => $list ) ) ),
				'nothing to offer a visitor'
			);
		}
	}

	#[Test]
	public function should_not_leak_editor_notes_into_the_live_markup(): void {
		$html = agend_apps_records_render_filter( $this->settings( array( 'range_brackets_custom' => self::LIST . "\nnot a bracket" ) ) );

		self::assertStringNotContainsString( 'agend-filter__notice', $html );
		self::assertStringNotContainsString( 'skipped', $html );
	}

	#[Test]
	public function should_render_nothing_outside_a_catalogue_as_every_other_filter_does(): void {
		Agend_Apps_Records_Filter_Context::reset();

		self::assertSame( '', agend_apps_records_render_filter( $this->settings( array( 'range_brackets_custom' => self::LIST ) ) ) );
	}

	#[Test]
	public function should_give_no_render_reason_for_range_brackets(): void {
		// The empty cases are handled in the render itself (see the docblock
		// of agend_apps_records_filter_render_reason()), so neither adapter
		// needs a message for a code it would not otherwise know.
		self::assertSame( '', agend_apps_records_filter_render_reason( $this->settings( array( 'range_brackets_source' => 'custom' ) ), 'listing' ) );
		self::assertSame( 'missing_field_key', agend_apps_records_filter_render_reason( $this->settings( array( 'custom_field_key' => '' ) ), 'listing' ) );
	}

	// -------------------------------------------------------------------
	// Editor preview
	// -------------------------------------------------------------------

	/** @return array<string, array{string, string}> */
	public static function previews(): array {
		$labels = array( 'Any', 'Under 10,000', '10,000 to 30,000', 'Over 30,000' );

		$select  = '<select class="agend-filter__brackets" aria-label="Custom field">';
		$buttons = '<div class="agend-filter__options agend-filter__brackets agend-filter__brackets--buttons" role="group" aria-label="Custom field">';
		$radio   = '<div class="agend-filter__options agend-filter__brackets agend-filter__brackets--radio" role="radiogroup" aria-label="Custom field">';
		foreach ( $labels as $index => $label ) {
			$select  .= '<option>' . $label . '</option>';
			$buttons .= '<button type="button" class="agend-filter__button' . ( 0 === $index ? ' is-active' : '' ) . '" aria-pressed="' . ( 0 === $index ? 'true' : 'false' ) . '">' . $label . '</button>';
			$radio   .= '<label class="agend-filter__option"><input type="radio" name="agend-filter-brackets-income"' . ( 0 === $index ? ' checked' : '' ) . ' /><span>' . $label . '</span></label>';
		}

		return array(
			'dropdown' => array( 'select', $select . '</select>' ),
			'buttons'  => array( 'buttons', $buttons . '</div>' ),
			'radio'    => array( 'radio', $radio . '</div>' ),
		);
	}

	#[Test]
	#[DataProvider( 'previews' )]
	public function should_draw_the_custom_brackets_after_any_in_a_preview( string $display, string $control ): void {
		$settings = $this->settings( array( 'range_brackets_source' => 'custom', 'range_brackets_display' => $display, 'range_brackets_custom' => self::LIST ) );
		$config   = agend_apps_records_filter_config( 'listing', 'custom_field', $settings );

		$expected = '<div class="agend-filter agend-filter--range_brackets agend-filter--custom_field agend-filter--preview" data-agend-filter="' . esc_attr( (string) wp_json_encode( $config ) ) . '">'
			. '<span class="agend-filter__label">Custom field</span>'
			. '<div class="agend-filter__control">' . $control . '</div>'
			. '</div>';

		self::assertSame( $expected, agend_apps_records_render_filter( $settings, array( 'preview' => true ) ) );
	}

	#[Test]
	public function should_preview_agends_brackets_read_from_the_facet(): void {
		$this->use_facets_stub(
			array(
				'data' => array(
					'custom.income' => array(
						'min'     => 0,
						'max'     => 90000,
						'presets' => array(
							array( 'label' => 'Low', 'max' => 9999 ),
							array( 'label' => 'High', 'min' => 10000, 'max' => null ),
						),
					),
				),
			)
		);

		$html = agend_apps_records_render_filter( $this->settings( array( 'range_brackets_custom' => self::LIST ) ), array( 'preview' => true ) );

		self::assertStringContainsString( '<option>Any</option><option>Low</option><option>High</option></select>', $html, 'Agend\'s own brackets win over the widget\'s list' );
		self::assertStringNotContainsString( 'Under 10,000</option>', $html );
		self::assertStringNotContainsString( 'agend-filter__notice', $html );
		self::assertSame( array( array( 'custom.income' ) ), $GLOBALS['agend_test_facets_requests'], 'one request, for this field only' );
	}

	#[Test]
	public function should_preview_the_custom_brackets_with_a_note_when_agend_has_none(): void {
		$this->use_facets_stub( array( 'data' => array( 'custom.income' => array( 'min' => 0, 'max' => 5 ) ) ) );

		$html = agend_apps_records_render_filter( $this->settings( array( 'range_brackets_custom' => self::LIST ) ), array( 'preview' => true ) );

		self::assertStringContainsString( '<option>Under 10,000</option>', $html );
		self::assertStringContainsString( '<li>Agend has no brackets for this field, so the custom brackets are used.</li>', $html );
	}

	#[Test]
	public function should_not_ask_the_facet_for_a_preview_of_custom_brackets(): void {
		$this->use_facets_stub( array( 'data' => array() ) );

		agend_apps_records_render_filter( $this->settings( array( 'range_brackets_source' => 'custom', 'range_brackets_custom' => self::LIST ) ), array( 'preview' => true ) );

		self::assertSame( array(), $GLOBALS['agend_test_facets_requests'] );
	}

	#[Test]
	public function should_say_no_brackets_are_configured_in_place_of_the_control_when_agend_and_the_widget_have_none(): void {
		$this->use_facets_stub( array( 'data' => array( 'custom.income' => array( 'presets' => array() ) ) ) );

		$html = agend_apps_records_render_filter( $this->settings(), array( 'preview' => true ) );

		self::assertStringContainsString( '<div class="agend-filter__control"><ul class="agend-filter__notice" role="note"><li>No brackets are configured. Agend has none for this field', $html );
		self::assertStringNotContainsString( '<select', $html );
		self::assertStringNotContainsString( '<button', $html );
	}

	#[Test]
	public function should_say_no_brackets_are_configured_when_the_gateway_call_fails(): void {
		$this->use_facets_stub( new \WP_Error( 'agend_apps_unavailable', 'down' ) );

		$html = agend_apps_records_render_filter( $this->settings(), array( 'preview' => true ) );

		self::assertStringContainsString( 'No brackets are configured.', $html );
	}

	#[Test]
	public function should_say_no_brackets_are_configured_when_the_api_client_is_not_loaded(): void {
		// The facets stub is deliberately not required: function_exists() is false.
		$html = agend_apps_records_render_filter( $this->settings(), array( 'preview' => true ) );

		self::assertStringContainsString( 'No brackets are configured.', $html );
	}

	#[Test]
	public function should_tell_an_author_with_an_empty_custom_list_what_to_add(): void {
		$html = agend_apps_records_render_filter( $this->settings( array( 'range_brackets_source' => 'custom' ) ), array( 'preview' => true ) );

		self::assertStringContainsString( '<li>No brackets are configured. Add at least one line under Custom brackets, for example Under 10,000 | | 9999. Visitors see nothing until you do.</li>', $html );
		self::assertStringNotContainsString( '<select', $html );
	}

	#[Test]
	public function should_list_each_skipped_line_by_its_number_in_a_preview(): void {
		$list = "Under 10,000 | | 9999\n"
			. "Under 10,000 | 9999\n"
			. " | 1 | 2\n"
			. "Band | abc | 2\n"
			. "Everything | |\n"
			. "Backwards | 9 | 1";
		$html = agend_apps_records_render_filter( $this->settings( array( 'range_brackets_source' => 'custom', 'range_brackets_custom' => $list ) ), array( 'preview' => true ) );

		self::assertStringContainsString( '<option>Under 10,000</option></select>', $html, 'the one good line still previews' );
		self::assertStringContainsString( '<li>Line 2 was skipped: write it as Label | min | max.</li>', $html );
		self::assertStringContainsString( '<li>Line 3 was skipped: it has no label.</li>', $html );
		self::assertStringContainsString( '<li>Line 4 was skipped: a bound must be a plain number such as 9999 or 12.5.</li>', $html );
		self::assertStringContainsString( '<li>Line 5 was skipped: give a minimum, a maximum, or both.</li>', $html );
		self::assertStringContainsString( '<li>Line 6 was skipped: the minimum is above the maximum.</li>', $html );
	}

	#[Test]
	public function should_explain_the_cap_and_cut_a_long_list_of_notes_short(): void {
		$lines = array();
		for ( $i = 1; $i <= 14; $i++ ) {
			$lines[] = 'Band ' . $i . ' | ' . $i . ' |';
		}
		$html = agend_apps_records_render_filter( $this->settings( array( 'range_brackets_source' => 'custom', 'range_brackets_custom' => implode( "\n", $lines ) ) ), array( 'preview' => true ) );

		self::assertStringContainsString( '<li>Line 13 and any after it were skipped: at most 12 brackets are used.</li>', $html );
		self::assertStringContainsString( '<option>Band 12</option>', $html );
		self::assertStringNotContainsString( 'Band 13', $html );

		$many = agend_apps_records_render_filter( $this->settings( array( 'range_brackets_source' => 'custom', 'range_brackets_custom' => "ok | 1 |\n" . str_repeat( "bad\n", 8 ) ) ), array( 'preview' => true ) );
		self::assertSame( 5, substr_count( $many, 'was skipped' ) );
		self::assertStringContainsString( '<li>3 more lines were skipped.</li>', $many );
	}

	#[Test]
	public function should_escape_every_label_it_draws(): void {
		$list = '<script>x</script>Hostile "label" & more | | 5';
		$html = agend_apps_records_render_filter( $this->settings( array( 'range_brackets_source' => 'custom', 'range_brackets_custom' => $list ) ), array( 'preview' => true ) );

		self::assertStringNotContainsString( '<script>', $html );
		self::assertStringContainsString( '<option>xHostile &quot;label&quot; &amp; more</option>', $html );
	}

	// -------------------------------------------------------------------
	// Through the Elementor widget and the block
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
	public function should_match_the_core_renderer_through_the_elementor_widget_live_and_in_the_editor(): void {
		$settings = $this->settings( array( 'range_brackets_source' => 'custom', 'range_brackets_custom' => self::LIST . "\nbroken" ) );

		\Elementor\Plugin::$instance->editor->is_edit_mode = false;
		self::assertSame( agend_apps_records_render_filter( $settings ), $this->render_widget( $settings ) );

		\Elementor\Plugin::$instance->editor->is_edit_mode = true;
		$preview = agend_apps_records_render_filter( $settings, array( 'preview' => true ) );
		self::assertSame( $preview, $this->render_widget( $settings ), 'the editor shows the skipped-line note without any widget code of its own' );
		self::assertStringContainsString( 'Line 4 was skipped', $preview );
	}

	#[Test]
	public function should_let_the_block_attributes_carry_the_settings_with_the_schema_defaults(): void {
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/blocks.php';

		$attributes = agend_apps_records_block_attributes( agend_apps_records_surface_schema( 'filter' ) );

		self::assertSame( array( 'type' => 'string', 'default' => 'agend' ), $attributes['range_brackets_source'] );
		self::assertSame( array( 'type' => 'string', 'default' => '' ), $attributes['range_brackets_custom'] );
		self::assertSame( array( 'type' => 'string', 'default' => 'select' ), $attributes['range_brackets_display'] );
		self::assertSame( array( 'type' => 'string', 'default' => 'Any' ), $attributes['range_brackets_any_label'] );
		self::assertArrayNotHasKey( 'range_brackets_heading', $attributes, 'headings store no value' );

		$settings = agend_apps_records_settings_from_attributes(
			agend_apps_records_surface_schema( 'filter' ),
			array( 'filter' => 'listing:custom_field', 'custom_field_key' => 'income', 'control' => 'range_brackets', 'range_brackets_custom' => self::LIST, 'range_brackets_display' => 'radio' )
		);
		self::assertStringContainsString( 'agend-filter__brackets--radio', agend_apps_records_render_filter( $settings ), 'the block renders through the same core function with the same settings' );
	}
}
