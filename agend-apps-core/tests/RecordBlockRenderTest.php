<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend_Apps_Records_Record_Context;
use Agend_Elementor_Record_Block;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/format.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/ssr-detail.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/fragments.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/record-context.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/preview-records.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/fields.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema/record-block.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/record-block.php';
require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor-field-widget-trait.php';
require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/widgets/class-agend-elementor-record-block.php';

/**
 * The Agend Panel (record block) markup moved out of the Elementor widget
 * into core (SPEC-INFRA-20260907-gutenberg-block-colours-and-surfaces US-4.1).
 * The widget's render() used to compute its "renders nothing" conditions,
 * fetch tickets, and build the panel wrapper itself; all of that now lives in
 * agend_apps_records_render_record_block() and
 * agend_apps_records_record_block_render_reason()
 * (render/record-block.php), reachable by a caller other than that widget.
 */
final class RecordBlockRenderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Agend_Apps_Records_Record_Context::reset();
	}

	// -------------------------------------------------------------------
	// Fixtures
	// -------------------------------------------------------------------

	/** @return array<string, mixed> */
	private static function eventRecord(): array {
		return array(
			'slug'       => 'sample-event',
			'name'       => 'Sample Event',
			'start_date' => '2026-06-01T09:00:00+10:00',
			'end_date'   => '2026-06-01T17:00:00+10:00',
			'timezone'   => 'Australia/Sydney',
			'venue_type' => 'physical',
			'venue_name' => 'Sample Venue',
			'venue_city' => 'Sydney',
			'sold_out'   => false,
			'sponsors'   => array( array( 'name' => 'Sample Sponsor' ) ),
		);
	}

	/** @return array<string, mixed> */
	private static function courseRecord(): array {
		return array(
			'slug'                   => 'sample-course',
			'title'                  => 'Sample Course',
			'difficulty'             => 'beginner',
			'delivery_mode'          => 'self_paced',
			'total_duration_minutes' => 90,
			'lessons_count'          => 6,
			'instructor_name'        => 'Sample Instructor',
			'base_price'             => 0,
			'is_free'                => true,
			'learning_outcomes'      => array( 'Understand the fundamentals' ),
		);
	}

	/** @return array<string, mixed> */
	private static function listingRecord(): array {
		return array(
			'slug'           => 'sample-listing',
			'name'           => 'Sample Listing',
			'description'    => '<p>About text.</p>',
			'categories'     => array( array( 'name' => 'Consulting' ) ),
			'tags'           => array( array( 'name' => 'Accredited' ) ),
			'gallery_images' => array( 'https://example.test/photo.jpg' ),
			'locations'      => array( array( 'name' => 'Sydney office', 'city' => 'Sydney' ) ),
			'business_hours' => array( 'monday' => array( 'open' => '9:00am', 'close' => '5:00pm' ) ),
			'custom_fields'  => array( array( 'key' => 'abn', 'label' => 'ABN', 'type' => 'text', 'value' => '00 000 000 000' ) ),
			'achievements'   => array( array( 'name' => 'Sample Credential', 'type' => 'certificate', 'earned_at' => '2026-01-01T00:00:00+00:00' ) ),
			'phone'          => '+61 2 9000 0000',
			'website'        => 'https://example.test',
		);
	}

	/**
	 * Pushes a populated record of the given type, with any extra context
	 * (e.g. a preloaded ticket list) merged in alongside the slug.
	 *
	 * @param string $type  'event', 'course' or 'listing'.
	 * @param array  $extra Extra render context to merge in.
	 */
	private function pushRecordFor( string $type, array $extra = array() ): void {
		$records = array(
			'event'   => self::eventRecord(),
			'course'  => self::courseRecord(),
			'listing' => self::listingRecord(),
		);

		Agend_Apps_Records_Record_Context::push(
			$type,
			$records[ $type ],
			array( 'slug' => $records[ $type ]['slug'] ) + $extra
		);
	}

	private function renderWidget( array $settings ): string {
		$widget = new Agend_Elementor_Record_Block( array(), null, $settings );

		ob_start();
		( function () {
			$this->render();
		} )->call( $widget );

		return (string) ob_get_clean();
	}

	// -------------------------------------------------------------------
	// Every supported content-block key, against a pushed record context
	// -------------------------------------------------------------------

	/** @return array<string, array{string}> */
	public static function blockKeys(): array {
		$keys = array_keys( \agend_apps_records_record_block_blocks() );

		return array_combine( $keys, array_map( static fn( string $key ): array => array( $key ), $keys ) );
	}

	#[Test]
	#[DataProvider( 'blockKeys' )]
	public function should_render_a_populated_panel_for_every_block_key( string $key ): void {
		list( , $type, ) = \agend_apps_records_record_block_blocks()[ $key ];

		$roots = array(
			'event'   => 'agend-events-catalogue',
			'course'  => 'agend-courses-catalogue',
			'listing' => 'agend-directory-catalogue',
		);

		// The tickets panel needs a preloaded ticket list; every other key
		// renders from the pushed record alone.
		$extra = 'event_tickets' === $key
			? array( 'tickets' => array( array( 'ticket' => array( 'name' => 'General Admission', 'price' => 25 ) ) ) )
			: array();

		$this->pushRecordFor( $type, $extra );

		$settings = array( 'block' => $key );
		$html     = \agend_apps_records_render_record_block( $settings );

		$this->assertNotSame( '', $html, $key . ' rendered nothing' );
		$this->assertStringContainsString( 'agend-record-block--' . $key, $html );
		$this->assertStringContainsString( $roots[ $type ], $html );
		$this->assertContains(
			\agend_apps_records_record_block_render_reason( $settings ),
			array( '', 'retired' ),
			$key . ' should not report a blocking reason while it renders'
		);
	}

	// -------------------------------------------------------------------
	// Preview path
	// -------------------------------------------------------------------

	#[Test]
	public function should_render_against_the_preview_record_when_no_context_is_pushed(): void {
		$settings = array( 'block' => 'event_facts' );
		$opts     = array( 'preview' => true, 'preview_type' => 'event' );

		$this->assertSame( '', \agend_apps_records_record_block_render_reason( $settings, $opts ) );
		$this->assertNotSame( '', \agend_apps_records_render_record_block( $settings, $opts ) );
	}

	#[Test]
	public function should_give_the_tickets_live_only_reason_in_preview(): void {
		$settings = array( 'block' => 'event_tickets' );
		$opts     = array( 'preview' => true, 'preview_type' => 'event' );

		$this->assertSame( 'tickets_live_only', \agend_apps_records_record_block_render_reason( $settings, $opts ) );
		$this->assertSame( '', \agend_apps_records_render_record_block( $settings, $opts ) );
	}

	// -------------------------------------------------------------------
	// "Renders nothing" guards and their reasons
	// -------------------------------------------------------------------

	#[Test]
	public function should_render_nothing_and_give_no_reason_when_no_record_is_in_context(): void {
		$settings = array( 'block' => 'event_facts' );

		$this->assertSame( '', \agend_apps_records_record_block_render_reason( $settings ) );
		$this->assertSame( '', \agend_apps_records_render_record_block( $settings ) );
	}

	#[Test]
	public function should_render_nothing_and_give_no_reason_for_an_unknown_block_key(): void {
		$this->pushRecordFor( 'event' );
		$settings = array( 'block' => 'not-a-real-key' );

		$this->assertSame( '', \agend_apps_records_record_block_render_reason( $settings ) );
		$this->assertSame( '', \agend_apps_records_render_record_block( $settings ) );
	}

	#[Test]
	public function should_give_the_wrong_type_reason_when_the_panel_does_not_match_the_context(): void {
		$this->pushRecordFor( 'listing' );
		$settings = array( 'block' => 'event_facts' );

		$this->assertSame( 'wrong_type', \agend_apps_records_record_block_render_reason( $settings ) );
		$this->assertSame( '', \agend_apps_records_render_record_block( $settings ) );
	}

	#[Test]
	public function should_give_the_empty_fragment_reason_when_the_panel_has_nothing_to_show(): void {
		// A non-retired key, so this scenario cannot also be reported as 'retired'.
		Agend_Apps_Records_Record_Context::push( 'event', array( 'slug' => 'no-sponsors-event' ), array( 'slug' => 'no-sponsors-event' ) );
		$settings = array( 'block' => 'event_sponsors' );

		$this->assertSame( 'empty_fragment', \agend_apps_records_record_block_render_reason( $settings ) );
		$this->assertSame( '', \agend_apps_records_render_record_block( $settings ) );
	}

	#[Test]
	public function should_give_the_retired_reason_for_a_retired_key_that_still_renders(): void {
		$this->pushRecordFor( 'listing' );
		$settings = array( 'block' => 'listing_about' );

		$this->assertSame( 'retired', \agend_apps_records_record_block_render_reason( $settings ) );
		$this->assertNotSame( '', \agend_apps_records_render_record_block( $settings ) );
	}

	// -------------------------------------------------------------------
	// Ticket fetch: no double fetch across the reason call and the render
	// -------------------------------------------------------------------

	#[Test]
	public function should_fetch_tickets_at_most_once_even_though_the_reason_and_the_renderer_each_resolve(): void {
		Agend_Apps_Records_Record_Context::push( 'event', array( 'slug' => 'ticket-memo-event' ), array( 'slug' => 'ticket-memo-event' ) );
		$settings = array( 'block' => 'event_tickets' );

		// Exactly the order the widget uses: the reason first, then the
		// renderer, each independently resolving the panel.
		$this->assertSame( '', \agend_apps_records_record_block_render_reason( $settings ) );
		$this->assertNotSame( '', \agend_apps_records_render_record_block( $settings ) );

		$ticket_requests = array_values(
			array_filter(
				Agend_Test_WP::$requests,
				static fn( array $request ): bool => str_contains( $request['url'], '/tickets' )
			)
		);

		$this->assertCount(
			1,
			$ticket_requests,
			"agend_apps_records_record_block_tickets_for()'s per-slug memo should absorb the second resolve, not the gateway"
		);
	}

	// -------------------------------------------------------------------
	// The Elementor widget delegating to the core renderer
	// -------------------------------------------------------------------

	#[Test]
	public function should_match_the_core_renderer_live_when_the_editor_is_closed(): void {
		\Elementor\Plugin::$instance->editor->is_edit_mode = false;
		$this->pushRecordFor( 'event' );
		$settings = array( 'block' => 'event_facts' );

		$this->assertSame(
			\agend_apps_records_render_record_block( $settings, array( 'preview' => false, 'preview_type' => '' ) ),
			$this->renderWidget( $settings )
		);
	}

	#[Test]
	public function should_show_the_wrong_type_notice_only_when_the_editor_is_open(): void {
		$this->pushRecordFor( 'listing' );
		$settings = array( 'block' => 'event_facts' );

		\Elementor\Plugin::$instance->editor->is_edit_mode = false;
		$this->assertSame( '', $this->renderWidget( $settings ), 'no notice on the live front end' );

		\Elementor\Plugin::$instance->editor->is_edit_mode = true;
		$this->assertSame(
			'<div class="elementor-alert elementor-alert-warning">This panel is a event panel, but this template renders a listing. Nothing will show here on the live site.</div>',
			$this->renderWidget( $settings )
		);
	}

	#[Test]
	public function should_show_the_retired_notice_before_the_rendered_panel_in_the_editor(): void {
		\Elementor\Plugin::$instance->editor->is_edit_mode = true;
		$this->pushRecordFor( 'listing' );
		$settings = array( 'block' => 'listing_about' );

		$html = $this->renderWidget( $settings );

		$this->assertStringContainsString( 'This is now a field.', $html );
		$this->assertStringContainsString( 'agend-record-block--listing_about', $html );
		$this->assertTrue(
			strpos( $html, 'This is now a field.' ) < strpos( $html, 'agend-record-block--listing_about' ),
			'the retired notice should appear before the rendered panel'
		);
	}

	#[Test]
	public function should_show_both_the_retired_notice_and_the_nothing_to_show_notice_when_a_retired_panel_is_empty(): void {
		// Both are true at once here: 'listing_about' is retired, and this
		// listing has no description, so its fragment renders ''. A single
		// reason code cannot carry both, so the widget shows the retired
		// notice unconditionally (independent of the reason) and separately
		// shows this notice when the panel it goes on to render is empty --
		// exactly as it did before this moved into core.
		\Elementor\Plugin::$instance->editor->is_edit_mode = true;
		Agend_Apps_Records_Record_Context::push( 'listing', array( 'slug' => 'empty-listing' ), array( 'slug' => 'empty-listing' ) );
		$settings = array( 'block' => 'listing_about' );

		$html = $this->renderWidget( $settings );

		$this->assertStringContainsString( 'This is now a field.', $html );
		$this->assertStringContainsString( 'This record has nothing to show for this block.', $html );
	}
}
