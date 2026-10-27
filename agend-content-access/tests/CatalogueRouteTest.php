<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Catalogue as Catalogue;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-policy.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-catalogue.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-viewer-facts.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-runtime.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/rest/catalogue-routes.php';

/**
 * The editor catalogue route.
 *
 * `/plans` is read by things that already exist, so it must not change. The new
 * `/catalogue` route has to be as closed as it is, because it serves tenant
 * configuration to anybody the permission callback lets through, and has to
 * report each list's own state so one list failing hides none of the others.
 *
 * Separate processes, because the segment and entitlement wrappers are Core
 * functions that either exist or do not, and cannot be undefined once declared.
 */
#[CoversNothing]
#[RunTestsInSeparateProcesses]
final class CatalogueRouteTest extends TestCase {

	private const SEG_A = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1';
	private const TIER  = '11111111-1111-1111-1111-111111111111';

	private function seed(): void {
		require_once __DIR__ . '/fixtures/core-segments-double.php';
		require_once __DIR__ . '/fixtures/core-entitlement-types-double.php';

		Agend_Test_WP::$tiers_response = array(
			'data' => array(
				array( 'id' => self::TIER, 'name' => 'Full Member', 'slug' => 'full', 'tier_type' => 'individual', 'is_active' => true, 'price_cents' => 45000 ),
			),
		);

		Agend_Test_WP::set_filter(
			'agend_apps_crm_get_segments_response',
			array( 'success' => true, 'data' => array( array( 'id' => self::SEG_A, 'slug' => 'overdue', 'name' => 'Overdue renewals' ) ) )
		);

		Agend_Test_WP::set_filter(
			'agend_apps_crm_get_entitlement_types_response',
			array( 'success' => true, 'data' => array( array( 'gate_key' => 'directory.access', 'name' => 'Directory access', 'description' => 'Browse', 'kind' => 'platform-gate' ) ) )
		);
	}

	private function get( array $params = array() ): array {
		$request = new WP_REST_Request( 'GET', '/agend-content-access/v1/catalogue' );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		$response = agend_content_access_get_catalogue( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );

		return $response->get_data();
	}

	/** @return array<string, array> Registered route args keyed by route. */
	private function routes(): array {
		agend_content_access_register_catalogue_routes();

		$routes = array();

		foreach ( Agend_Test_WP::$rest_routes as $route ) {
			$routes[ $route['route'] ] = $route;
		}

		return $routes;
	}

	// -----------------------------------------------------------------
	// Registration
	// -----------------------------------------------------------------

	#[Test]
	public function both_routes_are_registered_under_the_plugin_namespace(): void {
		$routes = $this->routes();

		$this->assertSame( array( '/plans', '/catalogue' ), array_keys( $routes ) );
		$this->assertSame( 'agend-content-access/v1', $routes['/catalogue']['namespace'] );
	}

	/** One permission callback and one refresh argument, so the routes cannot come to disagree. */
	#[Test]
	public function the_catalogue_route_has_the_same_permission_callback_and_refresh_arg_as_plans(): void {
		$routes = $this->routes();

		$plans     = $routes['/plans']['args'][0];
		$catalogue = $routes['/catalogue']['args'][0];

		$this->assertSame( 'GET', $catalogue['methods'] );
		$this->assertSame( $plans['permission_callback'], $catalogue['permission_callback'] );
		$this->assertSame( $plans['args'], $catalogue['args'] );
		$this->assertSame( 'agend_content_access_get_catalogue', $catalogue['callback'] );
	}

	/** Kept exactly as it was. */
	#[Test]
	public function the_plans_route_is_unchanged(): void {
		$plans = $this->routes()['/plans']['args'][0];

		$this->assertSame( 'agend_content_access_get_plans', $plans['callback'] );
		$this->assertSame( 'agend_content_access_can_read_plans', $plans['permission_callback'] );

		$this->seed();

		$data = agend_content_access_get_plans( new WP_REST_Request( 'GET', '/agend-content-access/v1/plans' ) )->get_data();

		$this->assertSame( array( 'plans', 'all_plans', 'stale', 'can_select', 'error' ), array_keys( $data ) );
	}

	// -----------------------------------------------------------------
	// Permission
	// -----------------------------------------------------------------

	#[Test]
	public function the_route_is_refused_without_edit_posts(): void {
		$GLOBALS['agend_test_current_user_can']['edit_posts'] = false;

		$result = agend_content_access_can_read_plans();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 401, $result->get_error_data()['status'], 'a signed-out visitor is asked to authenticate' );

		$GLOBALS['agend_test_current_user_id'] = 7;

		$this->assertSame( 403, agend_content_access_can_read_plans()->get_error_data()['status'] );
	}

	#[Test]
	public function the_route_is_open_to_somebody_who_can_edit_posts(): void {
		$GLOBALS['agend_test_current_user_can']['edit_posts'] = true;

		$this->assertTrue( agend_content_access_can_read_plans() );
	}

	// -----------------------------------------------------------------
	// Shape
	// -----------------------------------------------------------------

	#[Test]
	public function the_response_has_three_sections_in_one_shape(): void {
		$this->seed();

		$data = $this->get();

		$this->assertSame( array( 'plans', 'segments', 'entitlements' ), array_keys( $data ) );

		foreach ( $data as $section ) {
			$this->assertSame( array( 'items', 'stale', 'error' ), array_keys( $section ) );
			$this->assertFalse( $section['stale'] );
			$this->assertSame( '', $section['error'] );
		}

		$this->assertSame( array( self::TIER ), array_column( $data['plans']['items'], 'id' ) );
		$this->assertSame( array( self::SEG_A ), array_column( $data['segments']['items'], 'id' ) );
		$this->assertSame( array( 'directory.access' ), array_column( $data['entitlements']['items'], 'gate_key' ) );
	}

	/** The route is for an editor control: no pricing, no key, no raw gateway payload. */
	#[Test]
	public function the_response_carries_no_pricing_or_internals(): void {
		$this->seed();

		$serialised = (string) json_encode( $this->get() );

		foreach ( array( '45000', 'price_cents', 'test-api-key', 'api.example.test' ) as $forbidden ) {
			$this->assertStringNotContainsString( $forbidden, $serialised );
		}
	}

	/** A key without the entitlement scope still gets its plans and segments, and a message naming the scope. */
	#[Test]
	public function a_section_that_failed_says_why_and_leaves_the_others_untouched(): void {
		$this->seed();
		Agend_Test_WP::set_filter(
			'agend_apps_crm_get_entitlement_types_response',
			new WP_Error( 'agend_api_error', 'Forbidden', array( 'status_code' => 403 ) )
		);

		$data = $this->get();

		$this->assertCount( 1, $data['plans']['items'] );
		$this->assertCount( 1, $data['segments']['items'] );
		$this->assertSame( array(), $data['entitlements']['items'] );
		$this->assertTrue( $data['entitlements']['stale'] );
		$this->assertStringContainsString( 'crm.entitlements.browse', $data['entitlements']['error'] );
		$this->assertSame( '', $data['segments']['error'] );
	}

	// -----------------------------------------------------------------
	// Refresh
	// -----------------------------------------------------------------

	#[Test]
	public function the_cache_is_used_unless_a_refresh_is_asked_for(): void {
		$this->seed();
		$this->get();

		// Upstream now says something else everywhere.
		Agend_Test_WP::$tiers_response = array( 'data' => array() );
		Agend_Test_WP::set_filter( 'agend_apps_crm_get_segments_response', array( 'success' => true, 'data' => array() ) );
		Agend_Test_WP::set_filter( 'agend_apps_crm_get_entitlement_types_response', array( 'success' => true, 'data' => array() ) );

		$cached = $this->get();

		$this->assertCount( 1, $cached['plans']['items'] );
		$this->assertCount( 1, $cached['segments']['items'] );
		$this->assertCount( 1, $cached['entitlements']['items'] );

		$refreshed = $this->get( array( 'refresh' => true ) );

		$this->assertSame( array(), $refreshed['plans']['items'] );
		$this->assertSame( array(), $refreshed['segments']['items'] );
		$this->assertSame( array(), $refreshed['entitlements']['items'] );
		$this->assertSame( array(), Catalogue::segments()['items'], 'and the refresh replaced the cached copy' );
	}

	/**
	 * The request memo must not make the route's `refresh` flag toothless: an
	 * editor who has just fixed the API key asks again and must hear Agend, not
	 * the failure the same request has already remembered.
	 */
	#[Test]
	public function a_refresh_asks_again_after_a_failure_this_request_already_remembered(): void {
		$this->seed();
		Agend_Test_WP::set_filter( 'agend_apps_crm_get_entitlement_types_response', new WP_Error( 'agend_api_error', 'Forbidden', array( 'status_code' => 403 ) ) );
		Agend_Test_WP::$tiers_response = new WP_Error( 'http_request_failed', 'down' );

		$this->assertSame( array(), $this->get()['entitlements']['items'] );
		$this->assertSame( array(), $this->get()['plans']['items'] );

		$this->seed();

		$this->assertSame( array(), $this->get()['entitlements']['items'], 'without refresh the remembered failure stands' );

		$refreshed = $this->get( array( 'refresh' => true ) );

		$this->assertCount( 1, $refreshed['entitlements']['items'] );
		$this->assertCount( 1, $refreshed['plans']['items'] );
		$this->assertCount( 1, $refreshed['segments']['items'] );
		$this->assertFalse( $refreshed['entitlements']['stale'] );
	}

	#[Test]
	public function the_plans_route_refresh_bypasses_the_memo_too(): void {
		$this->seed();
		Agend_Test_WP::$tiers_response = new WP_Error( 'http_request_failed', 'down' );

		$request = new WP_REST_Request( 'GET', '/agend-content-access/v1/plans' );

		$this->assertTrue( agend_content_access_get_plans( $request )->get_data()['stale'] );

		$this->seed();

		$this->assertTrue( agend_content_access_get_plans( $request )->get_data()['stale'], 'the remembered failure stands without refresh' );

		$request->set_param( 'refresh', true );
		$data = agend_content_access_get_plans( $request )->get_data();

		$this->assertFalse( $data['stale'] );
		$this->assertCount( 1, $data['all_plans'] );
	}
}
