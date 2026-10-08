<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend_Apps_Records_Record_Context;
use Agend_Elementor_Record_Field;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
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
	// Restricted custom fields: gated is not the same as empty
	// -------------------------------------------------------------------

	/**
	 * The entry the gateway sends for a custom field the viewer may not see:
	 * no value, a `state` naming why, and `gated`.
	 *
	 * @param string $state The gateway's gate state.
	 * @return array
	 */
	private function gated_entry( string $state = 'access_restricted' ): array {
		return array(
			'key'   => 'sco_subregion',
			'label' => 'SCD-Sub Region',
			'mode'  => 'selected_entitlements',
			'state' => $state,
			'gated' => true,
		);
	}

	/**
	 * Settings for an Agend Field reading the `sco_subregion` custom field.
	 *
	 * @param array $overrides Settings to add or replace.
	 * @return array
	 */
	private function custom_field_settings( array $overrides = array() ): array {
		return array_merge( array( 'field' => 'common:custom_field', 'custom_field_key' => 'sco_subregion' ), $overrides );
	}

	/**
	 * What the widget prints for a restricted custom field with this text.
	 *
	 * @param string $text Already-escaped restricted text.
	 * @return string
	 */
	private function restricted_html( string $text ): string {
		return '<div class="agend-field agend-field--text agend-field--common-custom_field agend-field--gated">'
			. '<span class="agend-field__value agend-field__value--gated">' . $text . '</span></div>';
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function gate_state_labels(): array {
		return array(
			'sign in'               => array( 'authentication_required', 'Sign in to view' ),
			'members only'          => array( 'membership_required', 'Members only' ),
			'plan'                  => array( 'plan_required', 'Not included in your subscription' ),
			'selected entitlements' => array( 'access_restricted', 'Restricted' ),
			'unknown state'         => array( 'something_new', 'Restricted' ),
			'no state'              => array( '', 'Restricted' ),
		);
	}

	#[Test]
	#[DataProvider( 'gate_state_labels' )]
	public function should_name_the_gate_reason_for_a_restricted_custom_field( string $state, string $expected ): void {
		Agend_Apps_Records_Record_Context::push( 'listing', array( 'custom_fields' => array( $this->gated_entry( $state ) ) ) );

		self::assertSame( $this->restricted_html( $expected ), agend_apps_records_render_record_field( $this->custom_field_settings() ) );
	}

	#[Test]
	public function should_read_a_gated_entry_with_no_state_key_at_all_as_restricted(): void {
		$entry = $this->gated_entry();
		unset( $entry['state'] );
		Agend_Apps_Records_Record_Context::push( 'listing', array( 'custom_fields' => array( $entry ) ) );

		self::assertSame( $this->restricted_html( 'Restricted' ), agend_apps_records_render_record_field( $this->custom_field_settings() ) );
	}

	#[Test]
	public function should_let_the_gated_label_filter_reword_a_restricted_custom_field(): void {
		$seen = array();
		add_filter(
			'agend_apps_records_gated_field_label',
			static function ( string $label, string $state ) use ( &$seen ): string {
				$seen = array( $label, $state );
				return 'Locked';
			},
			10,
			2
		);
		Agend_Apps_Records_Record_Context::push( 'listing', array( 'custom_fields' => array( $this->gated_entry( 'membership_required' ) ) ) );

		self::assertSame( $this->restricted_html( 'Locked' ), agend_apps_records_render_record_field( $this->custom_field_settings() ) );
		self::assertSame( array( 'Members only', 'membership_required' ), $seen );
	}

	#[Test]
	public function should_render_nothing_when_the_gated_label_filter_blanks_the_label(): void {
		add_filter( 'agend_apps_records_gated_field_label', static fn(): string => '', 10, 2 );
		Agend_Apps_Records_Record_Context::push( 'listing', array( 'custom_fields' => array( $this->gated_entry() ) ) );

		self::assertSame( '', agend_apps_records_render_record_field( $this->custom_field_settings( array( 'fallback_text' => 'n/a' ) ) ) );
	}

	#[Test]
	public function should_prefer_the_restricted_text_setting_over_the_default_label_and_the_filter(): void {
		add_filter( 'agend_apps_records_gated_field_label', static fn(): string => 'Locked', 10, 2 );
		Agend_Apps_Records_Record_Context::push( 'listing', array( 'custom_fields' => array( $this->gated_entry( 'authentication_required' ) ) ) );

		$settings = $this->custom_field_settings( array( 'restricted_text' => '  Members of the PCA only  ' ) );

		self::assertSame( $this->restricted_html( 'Members of the PCA only' ), agend_apps_records_render_record_field( $settings ) );
	}

	#[Test]
	public function should_escape_the_restricted_text(): void {
		Agend_Apps_Records_Record_Context::push( 'listing', array( 'custom_fields' => array( $this->gated_entry() ) ) );

		$html = agend_apps_records_render_record_field( $this->custom_field_settings( array( 'restricted_text' => '<b>Hidden</b>' ) ) );

		self::assertStringNotContainsString( '<b>', $html );
		self::assertStringContainsString( '&lt;b&gt;Hidden&lt;/b&gt;', $html );
	}

	#[Test]
	public function should_show_the_restricted_text_rather_than_the_fallback_for_a_gated_entry(): void {
		Agend_Apps_Records_Record_Context::push( 'listing', array( 'custom_fields' => array( $this->gated_entry() ) ) );

		$html = agend_apps_records_render_record_field( $this->custom_field_settings( array( 'fallback_text' => 'Not recorded' ) ) );

		self::assertSame( $this->restricted_html( 'Restricted' ), $html );
		self::assertStringNotContainsString( 'Not recorded', $html );
	}

	#[Test]
	public function should_keep_the_before_and_after_text_around_a_restricted_value(): void {
		Agend_Apps_Records_Record_Context::push( 'listing', array( 'custom_fields' => array( $this->gated_entry( 'plan_required' ) ) ) );

		$html = agend_apps_records_render_record_field( $this->custom_field_settings( array( 'before_text' => 'Region: ', 'after_text' => '.' ) ) );

		self::assertStringContainsString(
			'Region: <span class="agend-field__value agend-field__value--gated">Not included in your subscription</span>.',
			$html
		);
	}

	#[Test]
	public function should_use_the_fallback_text_when_the_custom_field_is_absent_from_the_record(): void {
		Agend_Apps_Records_Record_Context::push(
			'listing',
			array( 'custom_fields' => array( array( 'key' => 'abn', 'label' => 'ABN', 'type' => 'text', 'value' => '12 345 678 901' ) ) )
		);

		$html = agend_apps_records_render_record_field( $this->custom_field_settings( array( 'fallback_text' => 'Not recorded', 'restricted_text' => 'Restricted' ) ) );

		self::assertSame(
			'<div class="agend-field agend-field--text agend-field--common-custom_field">Not recorded</div>',
			$html
		);
	}

	#[Test]
	public function should_use_the_fallback_text_for_a_listing_with_no_custom_fields_at_all(): void {
		Agend_Apps_Records_Record_Context::push( 'listing', array( 'name' => 'Sample Centre' ) );

		$settings = $this->custom_field_settings( array( 'fallback_text' => 'Not recorded' ) );

		self::assertSame(
			'<div class="agend-field agend-field--text agend-field--common-custom_field">Not recorded</div>',
			agend_apps_records_render_record_field( $settings )
		);
		self::assertSame( '', agend_apps_records_render_record_field( $this->custom_field_settings() ) );
	}

	#[Test]
	public function should_render_a_visible_custom_field_unchanged_whatever_the_restricted_text(): void {
		Agend_Apps_Records_Record_Context::push(
			'listing',
			array(
				'custom_fields' => array(
					array( 'key' => 'sco_subregion', 'label' => 'SCD-Sub Region', 'type' => 'text', 'value' => 'North' ),
				),
			)
		);

		$html = agend_apps_records_render_record_field( $this->custom_field_settings( array( 'restricted_text' => 'Members only', 'fallback_text' => 'n/a' ) ) );

		self::assertSame(
			'<div class="agend-field agend-field--text agend-field--common-custom_field">North</div>',
			$html
		);
	}

	#[Test]
	public function should_not_treat_an_entry_flagged_gated_false_as_restricted(): void {
		Agend_Apps_Records_Record_Context::push(
			'listing',
			array( 'custom_fields' => array( array( 'key' => 'sco_subregion', 'label' => 'SCD-Sub Region', 'type' => 'text', 'value' => 'North', 'gated' => false ) ) )
		);

		self::assertStringNotContainsString( 'gated', agend_apps_records_render_record_field( $this->custom_field_settings() ) );
	}

	#[Test]
	public function should_still_show_the_label_of_a_restricted_custom_field(): void {
		Agend_Apps_Records_Record_Context::push( 'listing', array( 'custom_fields' => array( $this->gated_entry() ) ) );

		$html = agend_apps_records_render_record_field( $this->custom_field_settings( array( 'show_label' => 'yes', 'label_separator' => ': ' ) ) );

		self::assertSame(
			'<div class="agend-field agend-field--text agend-field--common-custom_field agend-field--gated agend-field--labelled">'
				. '<span class="agend-field__label">SCD-Sub Region: </span>'
				. '<span class="agend-field__value agend-field__value--gated">Restricted</span></div>',
			$html
		);
	}

	#[Test]
	public function should_leave_the_label_only_field_alone_for_a_restricted_custom_field(): void {
		Agend_Apps_Records_Record_Context::push( 'listing', array( 'custom_fields' => array( $this->gated_entry() ) ) );

		$settings = $this->custom_field_settings( array( 'field' => 'common:custom_field_label', 'restricted_text' => 'Members only' ) );

		self::assertSame(
			'<div class="agend-field agend-field--text agend-field--common-custom_field_label">SCD-Sub Region</div>',
			agend_apps_records_render_record_field( $settings )
		);
	}

	#[Test]
	public function should_not_apply_the_restricted_text_to_a_field_that_is_not_a_custom_field(): void {
		Agend_Apps_Records_Record_Context::push( 'event', array() );

		$settings = array( 'field' => 'event:name', 'fallback_text' => 'No name yet', 'restricted_text' => 'Members only' );

		self::assertSame(
			'<div class="agend-field agend-field--text agend-field--event-name">No name yet</div>',
			agend_apps_records_render_record_field( $settings )
		);
	}

	#[Test]
	public function should_tell_a_gated_entry_from_a_missing_one_and_a_visible_one(): void {
		$record = array(
			'custom_fields' => array(
				$this->gated_entry( 'plan_required' ),
				array( 'key' => 'abn', 'label' => 'ABN', 'type' => 'text', 'value' => '12 345 678 901' ),
			),
		);

		self::assertSame( 'plan_required', agend_apps_records_record_custom_field_gate( $record, 'sco_subregion' ) );
		self::assertNull( agend_apps_records_record_custom_field_gate( $record, 'abn' ), 'visible entry' );
		self::assertNull( agend_apps_records_record_custom_field_gate( $record, 'missing' ), 'no entry' );
		self::assertNull( agend_apps_records_record_custom_field_gate( $record, '' ), 'no key' );
		self::assertNull( agend_apps_records_record_custom_field_gate( array(), 'sco_subregion' ), 'no custom_fields' );
		self::assertSame( '', agend_apps_records_record_custom_field( $record, 'sco_subregion' ), 'the value reader keeps returning an empty string' );
	}

	#[Test]
	public function should_carry_the_gated_state_through_the_elementor_widget(): void {
		Agend_Apps_Records_Record_Context::push( 'listing', array( 'custom_fields' => array( $this->gated_entry( 'membership_required' ) ) ) );
		\Elementor\Plugin::$instance->editor->is_edit_mode = false;

		$settings = $this->custom_field_settings( array( 'fallback_text' => 'Not recorded' ) );

		self::assertSame( $this->restricted_html( 'Members only' ), $this->render_widget( $settings ) );
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
