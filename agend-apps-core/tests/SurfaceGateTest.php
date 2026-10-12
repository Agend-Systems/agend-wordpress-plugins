<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend_Apps_Key_Scopes;
use Agend_Apps_Records_Record_Context;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use WP_Error;
use WP_REST_Request;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/health.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-key-scopes.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/features.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/surface-gate.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/palette.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/preview-records.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/directory-catalogue.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/events-catalogue.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/account-link.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/header-auth.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/record-block.php';

/**
 * The surface gate: which surfaces the stored API key can drive, what a gated
 * surface renders, and that a gated surface makes no gateway call.
 *
 * `render-doubles.php` answers the gateway wrappers from memory, so "no gateway
 * call" is proved two ways: the double's data never appears in the result, and
 * the recording HTTP double saw no request.
 */
final class SurfaceGateTest extends TestCase {

	/**
	 * Stores what a `GET /v1/health` response would have reported.
	 *
	 * @param string[] $scopes  Scopes held.
	 * @param string[] $app_ids Apps the key is restricted to; empty for none.
	 */
	private function know( array $scopes, array $app_ids = array() ): void {
		Agend_Apps_Key_Scopes::store_from_response( array( 'data' => array( 'scopes' => $scopes, 'app_ids' => $app_ids ) ) );
	}

	protected function setUp(): void {
		parent::setUp();
		// Loaded here rather than at the top of the file: the doubles declare the
		// gateway wrappers, which other suites rely on being absent until a test
		// that wants them asks.
		require_once AGEND_TESTS_ROOT . '/tests/render-doubles.php';
		Agend_Apps_Records_Record_Context::reset();
	}

	// -----------------------------------------------------------------
	// Open and closed
	// -----------------------------------------------------------------

	#[Test]
	public function should_stay_open_when_the_scopes_are_not_known(): void {
		self::assertTrue( agend_apps_records_surface_available( 'events-catalogue' ) );
		self::assertSame( array( 'scopes' => array(), 'app' => '' ), agend_apps_records_surface_missing( 'events-catalogue' ) );
		self::assertNull( agend_apps_records_surface_gate( 'events-catalogue', true ) );
		self::assertNull( agend_apps_records_surface_gate( 'events-catalogue', false ) );
		self::assertSame( '', agend_apps_records_surface_notice( 'events-catalogue' ) );
	}

	#[Test]
	public function should_never_refresh_the_scopes_to_answer(): void {
		// Stale by a day: a lazy refresh would fire a gateway call from the render.
		$this->know( array( 'crm.contacts.browse' ) );
		$stored               = get_option( Agend_Apps_Key_Scopes::OPTION );
		$stored['fetched_at'] = time() - DAY_IN_SECONDS;
		update_option( Agend_Apps_Key_Scopes::OPTION, $stored );

		self::assertTrue( agend_apps_records_surface_gated( 'events-catalogue' ) );
		self::assertSame( '', agend_apps_records_surface_gate( 'events-catalogue', false ) );
		self::assertCount( 0, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_stay_open_when_the_key_holds_the_scope(): void {
		$this->know( array( 'events.events.browse' ) );

		self::assertTrue( agend_apps_records_surface_available( 'events-catalogue' ) );
		self::assertNull( agend_apps_records_surface_gate( 'events-catalogue', true ) );
	}

	#[Test]
	public function should_close_and_name_the_missing_scope(): void {
		$this->know( array( 'directory.listings.search' ) );

		self::assertSame( array( 'scopes' => array( 'events.events.browse' ), 'app' => '' ), agend_apps_records_surface_missing( 'events-catalogue' ) );
		self::assertStringContainsString( 'events.events.browse', agend_apps_records_surface_notice( 'events-catalogue' ) );
		self::assertStringContainsString( 'Test connection', agend_apps_records_surface_notice( 'events-catalogue' ) );
	}

	/**
	 * @return array<string, array{string, string[]}>
	 */
	public static function requirements(): array {
		return array(
			'directory'   => array( 'directory-catalogue', array( 'directory.listings.search' ) ),
			'events'      => array( 'events-catalogue', array( 'events.events.browse' ) ),
			'courses'     => array( 'courses-catalogue', array( 'lms.courses.browse' ) ),
			'memberships' => array( 'memberships-catalogue', array( 'crm.tiers.browse' ) ),
			'login'       => array( 'member-login', array( 'auth.sessions.create' ) ),
			'header auth' => array( 'header-auth', array( 'auth.sessions.delete' ) ),
			'account'     => array( 'account-link', array( 'sso.identities.read', 'sso.tokens.create' ) ),
			'export'      => array( 'export-reports', array( 'directory.export_reports.browse', 'directory.export_reports.run' ) ),
			'add to cart' => array( 'add-to-cart', array( 'cart.items.manage' ) ),
			'cart header' => array( 'cart-header', array( 'cart.items.browse' ) ),
			'cart view'   => array( 'cart-view', array( 'cart.items.browse', 'cart.items.manage' ) ),
			'tickets'     => array( 'record-block-event-tickets', array( 'events.tickets.browse' ) ),
		);
	}

	/**
	 * @param string[] $scopes Scopes the surface needs.
	 */
	#[Test]
	#[DataProvider( 'requirements' )]
	public function should_hold_each_surface_to_exactly_its_scopes( string $surface, array $scopes ): void {
		self::assertSame( $scopes, agend_apps_records_surface_requirements( $surface )['scopes'] );

		$this->know( array( 'crm.contacts.browse' ) );
		self::assertSame( $scopes, agend_apps_records_surface_missing( $surface )['scopes'] );

		$this->know( $scopes );
		self::assertTrue( agend_apps_records_surface_available( $surface ) );
	}

	#[Test]
	public function should_report_only_the_scope_still_missing(): void {
		$this->know( array( 'cart.items.browse' ) );

		self::assertSame( array( 'cart.items.manage' ), agend_apps_records_surface_missing( 'cart-view' )['scopes'] );
		self::assertTrue( agend_apps_records_surface_available( 'cart-header' ) );
	}

	#[Test]
	public function should_honour_the_superadmin_and_app_wildcards(): void {
		$this->know( array( '*' ) );
		self::assertTrue( agend_apps_records_surface_available( 'events-catalogue' ) );
		self::assertTrue( agend_apps_records_surface_available( 'cart-view' ) );

		$this->know( array( 'events.*', 'cart.*' ) );
		self::assertTrue( agend_apps_records_surface_available( 'events-catalogue' ) );
		self::assertTrue( agend_apps_records_surface_available( 'cart-view' ) );
		self::assertFalse( agend_apps_records_surface_available( 'courses-catalogue' ) );
	}

	#[Test]
	public function should_close_a_surface_whose_app_the_key_is_restricted_away_from(): void {
		$this->know( array( 'events.events.browse' ), array( 'crm' ) );

		$missing = agend_apps_records_surface_missing( 'events-catalogue' );

		self::assertSame( array(), $missing['scopes'] );
		self::assertSame( 'events', $missing['app'] );
		self::assertFalse( agend_apps_records_surface_available( 'events-catalogue' ) );
		self::assertStringContainsString( '"events" app', agend_apps_records_surface_notice( 'events-catalogue' ) );
	}

	#[Test]
	public function should_treat_an_empty_app_list_as_unrestricted(): void {
		$this->know( array( 'events.events.browse' ), array() );

		self::assertTrue( agend_apps_records_surface_available( 'events-catalogue' ) );
	}

	#[Test]
	public function should_stand_down_when_the_request_guard_is_switched_off(): void {
		$this->know( array( 'crm.contacts.browse' ) );
		self::assertTrue( agend_apps_records_surface_gated( 'events-catalogue' ) );

		Agend_Test_WP::set_filter( 'agend_apps_api_block_unauthorised', false );

		self::assertTrue( agend_apps_records_surface_available( 'events-catalogue' ) );
		self::assertNull( agend_apps_records_surface_gate( 'events-catalogue', true ) );
	}

	#[Test]
	public function should_leave_a_surface_the_map_does_not_list_alone(): void {
		$this->know( array( 'crm.contacts.browse' ) );

		self::assertTrue( agend_apps_records_surface_available( 'record-field' ) );
		self::assertNull( agend_apps_records_surface_gate( 'record-field', true ) );
	}

	#[Test]
	public function should_let_a_site_amend_the_map(): void {
		add_filter(
			'agend_apps_records_surface_map',
			static function ( array $map ): array {
				$map['events-catalogue']['scopes'] = array( 'events.events.read' );
				return $map;
			}
		);
		$this->know( array( 'events.events.browse' ) );

		self::assertSame( array( 'events.events.read' ), agend_apps_records_surface_missing( 'events-catalogue' )['scopes'] );
	}

	// -----------------------------------------------------------------
	// The gate itself
	// -----------------------------------------------------------------

	#[Test]
	public function should_render_nothing_for_a_visitor_and_a_disabled_box_for_an_editor(): void {
		$this->know( array( 'crm.contacts.browse' ) );

		self::assertSame( '', agend_apps_records_surface_gate( 'events-catalogue', false ) );

		$box = (string) agend_apps_records_surface_gate( 'events-catalogue', true );

		self::assertStringContainsString( 'agend-surface-gate', $box );
		self::assertStringContainsString( 'aria-disabled="true"', $box );
		self::assertStringContainsString( 'Agend Events (disabled)', $box );
		self::assertStringContainsString( 'events.events.browse', $box );
		self::assertStringContainsString( 'Visitors see nothing', $box );
		self::assertStringContainsString( 'dashed', $box );
	}

	// -----------------------------------------------------------------
	// Renderers: nothing drawn, no call made
	// -----------------------------------------------------------------

	#[Test]
	public function should_render_the_catalogue_when_open_and_nothing_when_gated(): void {
		agend_render_test_reset();

		self::assertNotSame( '', agend_apps_records_render_events_catalogue( array() ), 'open: renders its shell' );

		$this->know( array( 'crm.contacts.browse' ) );
		Agend_Test_WP::$requests = array();

		self::assertSame( '', agend_apps_records_render_events_catalogue( array() ) );
		self::assertSame( '', agend_apps_records_render_directory_catalogue( array() ) );
		self::assertSame( '', agend_apps_records_render_account_link( array() ) );
		self::assertCount( 0, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_emit_no_config_attribute_for_a_gated_surface(): void {
		$this->know( array( 'crm.contacts.browse' ) );

		self::assertStringNotContainsString( 'data-agend', agend_apps_records_render_directory_catalogue( array() ) );
		self::assertStringNotContainsString( 'data-agend', agend_apps_records_render_header_auth( array() ) );
	}

	#[Test]
	public function should_render_a_surface_the_key_still_covers_when_another_is_gated(): void {
		agend_render_test_reset();
		$this->know( array( 'directory.listings.search' ) );

		self::assertSame( '', agend_apps_records_render_events_catalogue( array() ) );
		self::assertNotSame( '', agend_apps_records_render_directory_catalogue( array() ) );
	}

	// -----------------------------------------------------------------
	// Record surfaces inherit their catalogue
	// -----------------------------------------------------------------

	#[Test]
	public function should_gate_a_record_surface_by_the_type_it_previews(): void {
		$this->know( array( 'directory.listings.search' ) );

		self::assertNull( agend_apps_records_record_gate( array(), array( 'preview' => true, 'preview_type' => 'listing' ) ) );

		$box = (string) agend_apps_records_record_gate( array(), array( 'preview' => true, 'preview_type' => 'event' ) );
		self::assertStringContainsString( 'events.events.browse', $box );

		// Defaults to the event type, as the preview resolver does.
		self::assertStringContainsString( 'agend-surface-gate', (string) agend_apps_records_record_gate( array(), array( 'preview' => true ) ) );

		// A visitor sees nothing, and outside a record or preview there is nothing to gate.
		Agend_Apps_Records_Record_Context::push( 'event', array( 'slug' => 'gala' ) );
		self::assertSame( '', agend_apps_records_record_gate( array(), array() ) );
		Agend_Apps_Records_Record_Context::reset();
		self::assertNull( agend_apps_records_record_gate( array(), array() ) );
	}

	#[Test]
	public function should_hold_the_tickets_panel_to_the_tickets_scope_on_top_of_its_catalogue(): void {
		$this->know( array( 'events.events.browse' ) );

		self::assertNull( agend_apps_records_record_gate( array( 'block' => 'event_facts' ), array( 'preview' => true, 'preview_type' => 'event' ) ) );

		$box = (string) agend_apps_records_record_gate( array( 'block' => 'event_tickets' ), array( 'preview' => true, 'preview_type' => 'event' ) );
		self::assertStringContainsString( 'events.tickets.browse', $box );

		// A visitor gets nothing, from the renderer and from the gate alike.
		Agend_Apps_Records_Record_Context::push( 'event', array( 'slug' => 'gala' ) );
		self::assertSame( '', agend_apps_records_record_gate( array( 'block' => 'event_tickets' ), array() ) );
		self::assertSame( '', agend_apps_records_render_record_block( array( 'block' => 'event_tickets' ), array() ) );
	}

	#[Test]
	public function should_not_fetch_tickets_for_a_key_without_the_tickets_scope(): void {
		$this->know( array( 'events.events.browse' ) );

		self::assertSame( array(), agend_apps_records_record_block_tickets_for( 'gala', array() ) );
		self::assertCount( 0, Agend_Test_WP::$requests, 'the tickets wrapper records a request when it is called' );

		$this->know( array( 'events.events.browse', 'events.tickets.browse' ) );

		self::assertNotSame( array(), agend_apps_records_record_block_tickets_for( 'gala-open', array() ) );
		self::assertCount( 1, Agend_Test_WP::$requests );
	}

	// -----------------------------------------------------------------
	// No gateway-backed editor data while gated
	// -----------------------------------------------------------------

	#[Test]
	public function should_not_fetch_preview_records_for_a_gated_catalogue(): void {
		$this->know( array( 'crm.contacts.browse' ) );

		self::assertSame( array(), agend_apps_records_fetch_preview_event() );
		self::assertSame( array(), agend_apps_records_fetch_preview_course() );
		self::assertSame( array(), agend_apps_records_fetch_preview_listing() );

		$record = agend_apps_records_preview_record( 'event' );
		self::assertTrue( $record['_agend_preview_placeholder'], 'the placeholder stands in' );
		self::assertSame( array(), Agend_Test_WP::$transients, 'and is not cached, so a fixed key shows real data at once' );
		self::assertCount( 0, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_fetch_a_preview_record_when_the_catalogue_is_open(): void {
		$this->know( array( 'events.events.browse' ) );

		$record = agend_apps_records_fetch_preview_event();

		self::assertSame( 'event-one', $record['slug'] );
	}

	#[Test]
	public function should_offer_no_gateway_backed_options_for_a_gated_catalogue(): void {
		\Elementor\Plugin::$instance->editor->is_edit_mode = true;
		$this->know( array( 'crm.contacts.browse' ) );

		self::assertSame( array(), agend_apps_records_event_category_options() );
		self::assertSame( array(), agend_apps_records_event_city_options() );
		self::assertSame( array(), agend_apps_records_courses_catalogue_category_options() );
		self::assertSame( array(), agend_apps_records_directory_catalogue_category_options() );
		self::assertSame( array( 'data' => array() ), agend_apps_records_export_reports_authoring_listing() );

		$this->know( array( 'events.events.browse' ) );

		self::assertSame( array( '1' => 'Conferences', '2' => 'Workshops' ), agend_apps_records_event_category_options() );
	}

	// -----------------------------------------------------------------
	// REST proxy and fragments
	// -----------------------------------------------------------------

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function owned_routes(): array {
		return array(
			'directory search'     => array( '/agend-apps/v1/directory/search', 'directory-catalogue' ),
			'directory listing'    => array( '/agend-apps/v1/directory/listings/acme', 'directory-catalogue' ),
			'directory categories' => array( '/agend-apps/v1/directory/categories', 'directory-catalogue' ),
			'directory facets'     => array( '/agend-apps/v1/directory/facets', 'directory-catalogue' ),
			'events list'          => array( '/agend-apps/v1/events', 'events-catalogue' ),
			'events categories'    => array( '/agend-apps/v1/events/categories', 'events-catalogue' ),
			'courses list'         => array( '/agend-apps/v1/lms/courses', 'courses-catalogue' ),
			'tiers'                => array( '/agend-apps/v1/crm/tiers', 'memberships-catalogue' ),
			'export list'          => array( '/agend-apps/v1/directory/export-reports', 'export-reports' ),
			'export download'      => array( '/agend-apps/v1/directory/export-reports/abc-1', 'export-reports' ),
			'account link'         => array( '/agend-apps/v1/account-link/status', 'account-link' ),
			'event cards'          => array( '/agend-apps/v1/cards/events', 'events-catalogue' ),
			'legacy event cards'   => array( '/agend-elementor/v1/cards/events', 'events-catalogue' ),
			'course cards'         => array( '/agend-apps/v1/cards/courses', 'courses-catalogue' ),
			'listing cards'        => array( '/agend-apps/v1/cards/listings', 'directory-catalogue' ),
		);
	}

	#[Test]
	#[DataProvider( 'owned_routes' )]
	public function should_refuse_a_proxied_route_whose_surface_is_gated( string $route, string $surface ): void {
		$this->know( array( 'crm.contacts.browse' ) );

		$result = agend_apps_records_surface_gate_rest( null, null, new WP_REST_Request( 'GET', $route ) );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'agend_apps_feature_unavailable', $result->get_error_code() );
		self::assertSame( 403, $result->get_error_data()['status'] );
		self::assertSame( $surface, $result->get_error_data()['surface'] );
		self::assertCount( 0, Agend_Test_WP::$requests );
	}

	#[Test]
	#[DataProvider( 'owned_routes' )]
	public function should_pass_a_proxied_route_through_when_its_surface_is_open( string $route ): void {
		self::assertNull( agend_apps_records_surface_gate_rest( null, null, new WP_REST_Request( 'GET', $route ) ), 'scopes unknown: fail open' );

		$this->know( array( '*' ) );

		self::assertNull( agend_apps_records_surface_gate_rest( null, null, new WP_REST_Request( 'GET', $route ) ) );
	}

	#[Test]
	public function should_leave_shared_routes_alone_whatever_the_key_holds(): void {
		$this->know( array( 'crm.contacts.browse' ) );

		foreach ( array( '/agend-apps/v1/auth/session', '/agend-apps/v1/auth/login', '/agend-apps/v1/sites/config', '/agend-apps/v1/cart', '/agend-apps/v1/cart/items', '/agend-apps/v1/events/gala/tickets', '/agend-apps/v1/events/gala/attendee-fields', '/agend-apps/v1/directory/me/listing', '/wp/v2/posts' ) as $route ) {
			self::assertNull( agend_apps_records_surface_gate_rest( null, null, new WP_REST_Request( 'GET', $route ) ), $route );
		}
	}

	#[Test]
	public function should_not_override_a_response_another_filter_already_made(): void {
		$this->know( array( 'crm.contacts.browse' ) );
		$earlier = new WP_Error( 'earlier', 'Earlier' );

		self::assertSame( $earlier, agend_apps_records_surface_gate_rest( $earlier, null, new WP_REST_Request( 'GET', '/agend-apps/v1/events' ) ) );
	}

	#[Test]
	public function should_refuse_an_export_download_without_a_gateway_call(): void {
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/directory-export-reports.php';

		$this->know( array( 'directory.export_reports.browse' ) );

		$result = agend_apps_directory_run_export_report( 'abc-1' );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'agend_apps_feature_unavailable', $result->get_error_code() );
		self::assertStringContainsString( 'directory.export_reports.run', $result->get_error_message() );
		self::assertSame( 403, $result->get_error_data()['status_code'] );
		self::assertCount( 0, Agend_Test_WP::$requests );
	}
}
