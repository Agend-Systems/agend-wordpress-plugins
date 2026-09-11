<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend_Apps_Records_Record_Context;
use Agend_Elementor_Record_Link;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The Agend Link / Button widget's render logic moved out of the Elementor
 * widget into core (SPEC-INFRA-20260907-gutenberg-block-colours-and-surfaces
 * US-4.1 follow-up). agend_apps_records_render_record_link() and its
 * companion agend_apps_records_record_link_render_reason() now live in
 * agend-apps-core/includes/records/render/record-link.php, reachable by any
 * caller, not only the Elementor widget.
 */
final class RecordLinkRenderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/format.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/fields.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/record-context.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/preview-records.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/settings.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/pages.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/record-link.php';
		require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor-field-widget-trait.php';
		require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/widgets/class-agend-elementor-record-link.php';
		Agend_Apps_Records_Record_Context::reset();
	}

	protected function tearDown(): void {
		Agend_Apps_Records_Record_Context::reset();
		parent::tearDown();
	}

	/** @var array<string, array{slug: string, name?: string, title?: string}> */
	private const DEFAULT_RECORDS = array(
		'event'   => array( 'slug' => 'sample-event', 'name' => 'Sample Event' ),
		'course'  => array( 'slug' => 'sample-course', 'title' => 'Sample Course' ),
		'listing' => array( 'slug' => 'sample-listing', 'name' => 'Sample Listing' ),
	);

	private function pushRecord( string $type, array $record = array(), array $extra = array() ): void {
		$defaults = self::DEFAULT_RECORDS[ $type ] ?? array();

		Agend_Apps_Records_Record_Context::push(
			$type,
			$record + $defaults,
			$extra + array( 'detail_url' => 'https://example.test/' . $type . '/' . ( $defaults['slug'] ?? 'sample' ) . '/' )
		);
	}

	private function seedPage( int $id ): void {
		$GLOBALS['agend_test_posts'][ $id ] = array(
			'ID'          => $id,
			'post_type'   => 'page',
			'post_status' => 'publish',
		);
	}

	// -------------------------------------------------------------------
	// The `detail` action
	// -------------------------------------------------------------------

	#[Test]
	public function should_render_the_detail_anchor_with_its_default_label(): void {
		$this->pushRecord( 'event' );

		self::assertSame(
			'<a class="agend-record-link agend-record-link--button agend-record-link--action-detail" href="https://example.test/event/sample-event/">View details</a>',
			agend_apps_records_render_record_link( array() )
		);
		self::assertSame( '', agend_apps_records_record_link_render_reason( array() ) );
	}

	#[Test]
	public function should_use_a_custom_label_when_text_is_set(): void {
		$this->pushRecord( 'event' );

		self::assertSame(
			'<a class="agend-record-link agend-record-link--button agend-record-link--action-detail" href="https://example.test/event/sample-event/">See more</a>',
			agend_apps_records_render_record_link( array( 'text' => 'See more' ) )
		);
	}

	#[Test]
	public function should_render_a_span_instead_of_an_anchor_inside_a_card_that_is_one_big_link(): void {
		$this->pushRecord( 'event', array(), array( 'in_card_link' => true ) );

		self::assertSame(
			'<span class="agend-record-link agend-record-link--button agend-record-link--action-detail">View details</span>',
			agend_apps_records_render_record_link( array() )
		);
	}

	#[Test]
	public function should_render_nothing_with_no_reason_when_the_detail_action_has_no_url_to_link_to(): void {
		$this->pushRecord( 'event', array(), array( 'detail_url' => '' ) );

		self::assertSame( '', agend_apps_records_record_link_render_reason( array() ) );
		self::assertSame( '', agend_apps_records_render_record_link( array() ) );
	}

	// -------------------------------------------------------------------
	// The `catalogue` action
	// -------------------------------------------------------------------

	/** @return array<string, array{string, string}> type => expected default label */
	public static function catalogueLabels(): array {
		return array(
			'event'   => array( 'event', 'Back to Events' ),
			'course'  => array( 'course', 'Back to Courses' ),
			'listing' => array( 'listing', 'Back to Directory' ),
		);
	}

	#[Test]
	#[DataProvider( 'catalogueLabels' )]
	public function should_render_the_catalogue_anchor_with_the_type_specific_default_label( string $type, string $expected_label ): void {
		$this->seedPage( 42 );
		$this->pushRecord( $type, array(), array( 'host_page_id' => 42 ) );

		self::assertSame(
			'<a class="agend-record-link agend-record-link--button agend-record-link--action-catalogue" href="https://example.test/page-42/">' . $expected_label . '</a>',
			agend_apps_records_render_record_link( array( 'action' => 'catalogue' ) )
		);
	}

	#[Test]
	public function should_render_nothing_when_the_catalogue_action_has_no_page_to_link_to(): void {
		$this->pushRecord( 'event' );

		self::assertSame( '', agend_apps_records_record_link_render_reason( array( 'action' => 'catalogue' ) ) );
		self::assertSame( '', agend_apps_records_render_record_link( array( 'action' => 'catalogue' ) ) );
	}

	// -------------------------------------------------------------------
	// The `ical` action
	// -------------------------------------------------------------------

	#[Test]
	public function should_render_the_ical_anchor_for_an_event(): void {
		$this->pushRecord( 'event' );

		$expected_href = rest_url( 'agend-apps/v1/events/sample-event/ical' );

		self::assertSame(
			'<a class="agend-record-link agend-record-link--button agend-record-link--action-ical" href="' . esc_url( $expected_href ) . '">Add to Calendar</a>',
			agend_apps_records_render_record_link( array( 'action' => 'ical' ) )
		);
	}

	#[Test]
	public function should_open_the_ical_anchor_in_a_new_tab_when_configured(): void {
		$this->pushRecord( 'event' );

		$html = agend_apps_records_render_record_link( array( 'action' => 'ical', 'new_tab' => 'yes' ) );

		self::assertStringContainsString( 'target="_blank" rel="noopener"', $html );
	}

	/** @return array<string, array{string, array}> */
	public static function icalWrongTypeScenarios(): array {
		return array(
			'wrong record type' => array( 'course', array() ),
			'no slug on event'  => array( 'event', array( 'slug' => '' ) ),
		);
	}

	#[Test]
	#[DataProvider( 'icalWrongTypeScenarios' )]
	public function should_give_the_ical_wrong_type_reason_and_render_nothing( string $type, array $record_overrides ): void {
		$this->pushRecord( $type, $record_overrides );

		self::assertSame( 'ical_wrong_type', agend_apps_records_record_link_render_reason( array( 'action' => 'ical' ) ) );
		self::assertSame( '', agend_apps_records_render_record_link( array( 'action' => 'ical' ) ) );
	}

	// -------------------------------------------------------------------
	// The `custom` action
	// -------------------------------------------------------------------

	#[Test]
	public function should_substitute_slug_and_title_placeholders_in_the_custom_url(): void {
		$this->pushRecord( 'event' );

		self::assertSame(
			'<a class="agend-record-link agend-record-link--button agend-record-link--action-custom" href="https://x.test/sample-event-Sample%20Event">Sample Event</a>',
			agend_apps_records_render_record_link( array( 'action' => 'custom', 'custom_url' => 'https://x.test/{slug}-{title}' ) )
		);
	}

	#[Test]
	public function should_render_nothing_with_no_reason_when_the_custom_url_is_blank(): void {
		$this->pushRecord( 'event' );

		self::assertSame( '', agend_apps_records_record_link_render_reason( array( 'action' => 'custom' ) ) );
		self::assertSame( '', agend_apps_records_render_record_link( array( 'action' => 'custom' ) ) );
	}

	// -------------------------------------------------------------------
	// The `enrol` action
	// -------------------------------------------------------------------

	#[Test]
	public function should_render_the_enrol_anchor_to_the_login_url_for_a_course(): void {
		$this->pushRecord( 'course' );

		$expected_href = wp_login_url( 'https://example.test/course/sample-course/' );

		self::assertSame(
			'<a class="agend-record-link agend-record-link--button agend-record-link--action-enrol" href="' . esc_url( $expected_href ) . '">Enrol Now</a>',
			agend_apps_records_render_record_link( array( 'action' => 'enrol' ) )
		);
	}

	#[Test]
	public function should_hide_the_enrol_anchor_by_default_once_already_enrolled(): void {
		$this->pushRecord( 'course', array( 'my_enrollment' => true ) );

		self::assertSame( '', agend_apps_records_record_link_render_reason( array( 'action' => 'enrol' ) ), 'not a reason: this has never shown an editor notice' );
		self::assertSame( '', agend_apps_records_render_record_link( array( 'action' => 'enrol' ) ) );
	}

	#[Test]
	public function should_still_render_the_enrol_anchor_when_hide_when_enrolled_is_turned_off(): void {
		$this->pushRecord( 'course', array( 'my_enrollment' => true ) );

		self::assertNotSame( '', agend_apps_records_render_record_link( array( 'action' => 'enrol', 'hide_when_enrolled' => '' ) ) );
	}

	#[Test]
	public function should_give_the_enrol_wrong_type_reason_for_a_non_course_record(): void {
		$this->pushRecord( 'event' );

		self::assertSame( 'enrol_wrong_type', agend_apps_records_record_link_render_reason( array( 'action' => 'enrol' ) ) );
		self::assertSame( '', agend_apps_records_render_record_link( array( 'action' => 'enrol' ) ) );
	}

	// -------------------------------------------------------------------
	// The `register` action (a <button>, not an anchor)
	// -------------------------------------------------------------------

	#[Test]
	public function should_render_the_register_button_with_its_default_label(): void {
		$this->pushRecord( 'event' );

		self::assertSame(
			'<button type="button" class="agend-record-link agend-record-link--button agend-record-link--action-register" data-agend-event-slug="sample-event">Register Now</button>',
			agend_apps_records_render_record_link( array( 'action' => 'register' ) )
		);
	}

	#[Test]
	public function should_disable_the_register_button_and_relabel_it_when_sold_out(): void {
		$this->pushRecord( 'event', array( 'sold_out' => true ) );

		self::assertSame(
			'<button type="button" class="agend-record-link agend-record-link--button agend-record-link--action-register" data-agend-event-slug="sample-event" disabled>Sold Out</button>',
			agend_apps_records_render_record_link( array( 'action' => 'register' ) )
		);
	}

	#[Test]
	public function should_relabel_the_register_button_once_already_registered(): void {
		$this->pushRecord( 'event', array( 'my_registration' => true ) );

		self::assertSame(
			'<button type="button" class="agend-record-link agend-record-link--button agend-record-link--action-register" data-agend-event-slug="sample-event">Register Another Attendee</button>',
			agend_apps_records_render_record_link( array( 'action' => 'register' ) )
		);
	}

	#[Test]
	public function should_hide_the_register_button_when_configured_to_hide_once_registered(): void {
		$this->pushRecord( 'event', array( 'my_registration' => true ) );

		self::assertSame( '', agend_apps_records_render_record_link( array( 'action' => 'register', 'hide_when_registered' => 'yes' ) ) );
	}

	#[Test]
	public function should_render_the_register_button_even_inside_a_card_that_is_one_big_link(): void {
		$this->pushRecord( 'event', array(), array( 'in_card_link' => true ) );

		// The button's own click must win over the card's; it is never
		// suppressed or replaced with a <span> the way an anchor is.
		self::assertStringContainsString( '<button', agend_apps_records_render_record_link( array( 'action' => 'register' ) ) );
	}

	/** @return array<string, array{string, array}> */
	public static function registerWrongTypeScenarios(): array {
		return array(
			'wrong record type' => array( 'course', array() ),
			'no slug on event'  => array( 'event', array( 'slug' => '' ) ),
		);
	}

	#[Test]
	#[DataProvider( 'registerWrongTypeScenarios' )]
	public function should_give_the_register_wrong_type_reason_and_render_nothing( string $type, array $record_overrides ): void {
		$this->pushRecord( $type, $record_overrides );

		self::assertSame( 'register_wrong_type', agend_apps_records_record_link_render_reason( array( 'action' => 'register' ) ) );
		self::assertSame( '', agend_apps_records_render_record_link( array( 'action' => 'register' ) ) );
	}

	// -------------------------------------------------------------------
	// No record context in scope
	// -------------------------------------------------------------------

	#[Test]
	public function should_render_nothing_with_no_reason_when_no_record_context_is_in_scope(): void {
		self::assertSame( '', agend_apps_records_record_link_render_reason( array() ) );
		self::assertSame( '', agend_apps_records_render_record_link( array() ) );
	}

	// -------------------------------------------------------------------
	// The Elementor widget delegating to the core renderer
	// -------------------------------------------------------------------

	private function render_widget( array $settings ): string {
		$widget = new Agend_Elementor_Record_Link( array(), null, $settings );

		ob_start();
		( function () {
			$this->render();
		} )->call( $widget );

		return (string) ob_get_clean();
	}

	#[Test]
	public function should_match_the_core_renderer_when_the_widget_delegates_to_it(): void {
		$this->pushRecord( 'event' );

		self::assertSame(
			agend_apps_records_render_record_link( array() ),
			$this->render_widget( array() )
		);
	}

	/** @return array<string, array{string, string, string}> action => [reason, notice] */
	public static function widgetNoticeScenarios(): array {
		return array(
			'ical'     => array( 'course', 'ical', 'Add to calendar is only available for events.' ),
			'enrol'    => array( 'event', 'enrol', 'Enrol is only available for courses.' ),
			'register' => array( 'course', 'register', 'Register is only available for events.' ),
		);
	}

	#[Test]
	#[DataProvider( 'widgetNoticeScenarios' )]
	public function should_show_the_editor_notice_for_each_wrong_type_reason_only_when_the_editor_is_open( string $type, string $action, string $notice ): void {
		$this->pushRecord( $type );

		\Elementor\Plugin::$instance->editor->is_edit_mode = false;
		self::assertSame( '', $this->render_widget( array( 'action' => $action ) ), 'no notice on the live front end' );

		\Elementor\Plugin::$instance->editor->is_edit_mode = true;
		self::assertSame(
			'<div class="elementor-alert elementor-alert-warning">' . $notice . '</div>',
			$this->render_widget( array( 'action' => $action ) )
		);
	}
}
