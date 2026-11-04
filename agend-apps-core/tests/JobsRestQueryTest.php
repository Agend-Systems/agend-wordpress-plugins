<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend_Apps_Endpoint_Scopes;
use Agend_Apps_Jobs_REST_Controller;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use WP_REST_Request;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-api.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-key-scopes.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-endpoint-scopes.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/jobs.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/rest/class-agend-apps-rest-controller.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/rest/jobs-routes.php';
require_once __DIR__ . '/fixtures/real-cache.php';

/**
 * The `/agend-apps/v1/jobs` proxy forwards the jobs board's filters to the
 * gateway. It used to forward only paging, search and sort, so a category,
 * location, employment type or featured-only board silently showed every job.
 */
final class JobsRestQueryTest extends TestCase {

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		agend_test_load_real_cache();
	}

	protected function setUp(): void {
		parent::setUp();
		Agend_Apps_Endpoint_Scopes::set_map( null );
	}

	protected function tearDown(): void {
		Agend_Apps_Endpoint_Scopes::set_map( null );
		parent::tearDown();
	}

	/**
	 * Sends a list request through the controller, the way WordPress would
	 * after sanitising each declared argument, and returns the gateway query.
	 *
	 * @param array<string, mixed> $params Raw query parameters.
	 * @return array<string, string> The query string the gateway received.
	 */
	private function gateway_query_for( array $params ): array {
		Agend_Test_WP::queue_response( 200, array( 'success' => true, 'data' => array() ) );

		$controller = new Agend_Apps_Jobs_REST_Controller();
		$request    = new WP_REST_Request( 'GET', '/agend-apps/v1/jobs' );
		$args       = $this->list_args();
		foreach ( $params as $name => $value ) {
			$callback = $args[ $name ]['sanitize_callback'] ?? null;
			$request->set_param( $name, $callback ? call_user_func( $callback, $value, $request, $name ) : $value );
		}

		$controller->get_jobs( $request );

		$this->assertCount( 1, Agend_Test_WP::$requests );
		$query = array();
		parse_str( (string) parse_url( Agend_Test_WP::$requests[0]['url'], PHP_URL_QUERY ), $query );

		return $query;
	}

	/**
	 * The list route's declared arguments.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function list_args(): array {
		Agend_Test_WP::$rest_routes = array();
		( new Agend_Apps_Jobs_REST_Controller() )->register_routes();

		foreach ( Agend_Test_WP::$rest_routes as $route ) {
			if ( '/jobs' === $route['route'] ) {
				return $route['args'][0]['args'];
			}
		}

		$this->fail( 'The jobs list route was not registered.' );
	}

	#[Test]
	public function should_forward_the_board_filters_to_the_gateway(): void {
		$query = $this->gateway_query_for(
			array(
				'category'     => 'financial-planning',
				'location'     => 'Sydney',
				'type'         => 'full-time',
				'experienceLevel' => 'senior',
				'featuredOnly' => 'true',
				'search'       => 'auditor',
			)
		);

		self::assertSame( 'financial-planning', $query['category'] ?? null );
		self::assertSame( 'Sydney', $query['location'] ?? null );
		self::assertSame( 'full-time', $query['type'] ?? null );
		self::assertSame( 'senior', $query['experienceLevel'] ?? null );
		self::assertSame( 'true', $query['featuredOnly'] ?? null );
		self::assertSame( 'auditor', $query['search'] ?? null );
	}

	#[Test]
	public function should_leave_featured_only_out_when_it_is_false(): void {
		$query = $this->gateway_query_for( array( 'featuredOnly' => 'false' ) );

		self::assertArrayNotHasKey( 'featuredOnly', $query );
	}

	#[Test]
	public function should_not_send_an_empty_filter_the_gateway_would_reject(): void {
		$query = $this->gateway_query_for(
			array(
				'category' => '',
				'location' => '',
			)
		);

		self::assertArrayNotHasKey( 'category', $query );
		self::assertArrayNotHasKey( 'location', $query );
	}

	#[Test]
	public function should_validate_each_filter_since_a_sanitize_callback_switches_off_the_default_check(): void {
		$args = $this->list_args();

		foreach ( array( 'sortBy', 'sortOrder', 'category', 'location', 'type', 'experienceLevel' ) as $name ) {
			self::assertSame( 'rest_validate_request_arg', $args[ $name ]['validate_callback'] ?? null, $name );
		}
	}

	#[Test]
	public function should_not_hand_the_request_to_sanitize_title_as_its_fallback_title(): void {
		// WordPress calls a sanitize callback as ( $value, $request, $name ),
		// and sanitize_title() reads a second argument as the fallback for an
		// empty value: an empty category became the request object and fataled.
		$callback = $this->list_args()['category']['sanitize_callback'];

		self::assertNotSame( 'sanitize_title', $callback );
		self::assertSame( '', call_user_func( $callback, '', new WP_REST_Request(), 'category' ) );
	}

	#[Test]
	public function should_not_forward_a_parameter_the_route_does_not_declare(): void {
		$query = $this->gateway_query_for( array( 'account_id' => 'someone-else' ) );

		self::assertArrayNotHasKey( 'account_id', $query );
	}
}
