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
		parse_str( $this->last_query_string(), $query );

		return $query;
	}

	/**
	 * The raw query string of the last gateway request, where a repeated key
	 * is still repeated (parse_str keeps only the last one).
	 *
	 * @return string
	 */
	private function last_query_string(): string {
		return (string) parse_url( Agend_Test_WP::$requests[0]['url'], PHP_URL_QUERY );
	}

	/**
	 * Each `name=value` pair of the last gateway request, decoded, in order.
	 *
	 * @return array<int, array{0: string, 1: string}>
	 */
	private function last_query_pairs(): array {
		$pairs = array();
		foreach ( explode( '&', $this->last_query_string() ) as $pair ) {
			if ( '' === $pair ) {
				continue;
			}
			[ $name, $value ] = array_pad( explode( '=', $pair, 2 ), 2, '' );
			$pairs[]          = array( rawurldecode( $name ), rawurldecode( $value ) );
		}
		return $pairs;
	}

	/**
	 * The values sent for one repeated key.
	 *
	 * @param string $name Parameter name.
	 * @return string[]
	 */
	private function sent( string $name ): array {
		$values = array();
		foreach ( $this->last_query_pairs() as [ $key, $value ] ) {
			if ( $key === $name ) {
				$values[] = $value;
			}
		}
		return $values;
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

		foreach ( array( 'sortBy', 'sortOrder', 'category', 'tag', 'location', 'state', 'type', 'locationType', 'experienceLevel', 'postedWithin' ) as $name ) {
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
		self::assertSame( array(), call_user_func( $callback, '', new WP_REST_Request(), 'category' ) );
	}

	#[Test]
	public function should_not_forward_a_parameter_the_route_does_not_declare(): void {
		$query = $this->gateway_query_for( array( 'account_id' => 'someone-else' ) );

		self::assertArrayNotHasKey( 'account_id', $query );
	}

	#[Test]
	public function should_send_each_value_of_a_list_filter_as_a_repeated_key(): void {
		$this->gateway_query_for(
			array(
				'category'        => array( 'audit', 'accounting' ),
				'tag'             => 'salary-120k-plus,accreditation-required',
				'type'            => 'full-time,part-time',
				'locationType'    => array( 'hybrid', 'remote' ),
				'experienceLevel' => array( 'mid', 'senior' ),
			)
		);

		self::assertSame( array( 'audit', 'accounting' ), $this->sent( 'category' ) );
		self::assertSame( array( 'salary-120k-plus', 'accreditation-required' ), $this->sent( 'tag' ) );
		self::assertSame( array( 'full-time', 'part-time' ), $this->sent( 'type' ) );
		self::assertSame( array( 'hybrid', 'remote' ), $this->sent( 'locationType' ) );
		self::assertSame( array( 'mid', 'senior' ), $this->sent( 'experienceLevel' ) );
		// Never PHP's bracketed form, which the gateway does not read.
		self::assertStringNotContainsString( '[', rawurldecode( $this->last_query_string() ) );
	}

	#[Test]
	public function should_drop_a_value_outside_a_fixed_list_and_a_repeated_value(): void {
		$this->gateway_query_for(
			array(
				'type' => array( 'full-time', 'volunteer', 'full-time' ),
			)
		);

		self::assertSame( array( 'full-time' ), $this->sent( 'type' ) );
	}

	#[Test]
	public function should_cap_each_list_filter_at_what_the_gateway_accepts(): void {
		$args = $this->list_args();

		foreach ( array( 'category', 'tag', 'type', 'locationType', 'experienceLevel' ) as $name ) {
			self::assertSame( 'array', $args[ $name ]['type'], $name );
			self::assertSame( Agend_Apps_Jobs_REST_Controller::LIST_FILTER_MAX_VALUES, $args[ $name ]['maxItems'], $name );
		}
		self::assertSame( 20, Agend_Apps_Jobs_REST_Controller::LIST_FILTER_MAX_VALUES );
	}

	#[Test]
	public function should_forward_the_state_and_featured_first(): void {
		$query = $this->gateway_query_for(
			array(
				'state'         => 'NSW',
				'featuredFirst' => 'true',
			)
		);

		self::assertSame( 'NSW', $query['state'] ?? null );
		self::assertSame( 'true', $query['featuredFirst'] ?? null );
	}

	#[Test]
	public function should_leave_featured_first_out_when_it_is_false(): void {
		$query = $this->gateway_query_for( array( 'featuredFirst' => 'false' ) );

		self::assertArrayNotHasKey( 'featuredFirst', $query );
	}

	#[Test]
	public function should_turn_posted_within_into_the_gateway_posted_after_date_at_midnight(): void {
		$query = $this->gateway_query_for( array( 'postedWithin' => '7' ) );

		self::assertArrayNotHasKey( 'postedWithin', $query );
		self::assertSame( gmdate( 'Y-m-d\T00:00:00\Z', time() - 7 * DAY_IN_SECONDS ), $query['postedAfter'] ?? null );
	}

	#[Test]
	public function should_ignore_a_posted_within_the_board_does_not_offer(): void {
		$query = $this->gateway_query_for( array( 'postedWithin' => '5' ) );

		self::assertArrayNotHasKey( 'postedAfter', $query );
	}
	#[Test]
	public function should_declare_the_point_and_radius_with_the_directory_routes_limits(): void {
		$args = $this->list_args();

		foreach ( array( 'lat' => array( -90, 90 ), 'lng' => array( -180, 180 ), 'radius' => array( 1, 1000 ) ) as $name => $range ) {
			self::assertSame( 'number', $args[ $name ]['type'], $name );
			self::assertSame( $range[0], $args[ $name ]['minimum'], $name );
			self::assertSame( $range[1], $args[ $name ]['maximum'], $name );
			self::assertSame( 'rest_sanitize_request_arg', $args[ $name ]['sanitize_callback'], $name );
			self::assertSame( 'rest_validate_request_arg', $args[ $name ]['validate_callback'], $name );
		}
		self::assertContains( 'distance', $args['sortBy']['enum'] );
	}

	#[Test]
	public function should_forward_a_point_and_radius_together_rounded_for_the_cache(): void {
		$query = $this->gateway_query_for(
			array(
				'lat'    => '-34.9284989',
				'lng'    => '138.6007456',
				'radius' => '25.04',
			)
		);

		self::assertSame( '-34.928', $query['lat'] ?? null );
		self::assertSame( '138.601', $query['lng'] ?? null );
		self::assertSame( '25', $query['radius'] ?? null );
	}

	#[Test]
	public function should_forward_include_remote_as_true_only_with_a_point(): void {
		$point = array( 'lat' => '-34.9', 'lng' => '138.6', 'radius' => '25' );

		$query = $this->gateway_query_for( $point + array( 'includeRemote' => 'true', 'search' => 'with point' ) );
		self::assertSame( 'true', $query['includeRemote'] ?? null );

		Agend_Test_WP::$requests = array();
		$query = $this->gateway_query_for( $point + array( 'includeRemote' => 'false', 'search' => 'false flag' ) );
		self::assertArrayNotHasKey( 'includeRemote', $query );

		// Without a point there is no radius for remote jobs to be added to.
		Agend_Test_WP::$requests = array();
		$query = $this->gateway_query_for( array( 'includeRemote' => 'true', 'search' => 'no point' ) );
		self::assertArrayNotHasKey( 'includeRemote', $query );
	}

	#[Test]
	public function should_forward_none_of_the_point_unless_all_three_arrive(): void {
		$sets = array(
			'no radius' => array( 'lat' => '-34.9', 'lng' => '138.6' ),
			'no lng'    => array( 'lat' => '-34.9', 'radius' => '25' ),
			'only lat'  => array( 'lat' => '-34.9' ),
			'radius'    => array( 'radius' => '25' ),
		);

		foreach ( $sets as $label => $params ) {
			Agend_Test_WP::$requests = array();
			// A distinct search keeps each set clear of the cache.
			$query = $this->gateway_query_for( $params + array( 'search' => $label ) );

			foreach ( array( 'lat', 'lng', 'radius' ) as $name ) {
				self::assertArrayNotHasKey( $name, $query, $label );
			}
		}
	}

	#[Test]
	public function should_forward_the_orders_the_board_and_the_visitor_can_pick(): void {
		foreach ( Agend_Apps_Jobs_REST_Controller::SORT_ORDERS as $pair ) {
			[ $by, $order ] = explode( ':', $pair );
			Agend_Test_WP::$requests = array();
			$params                  = array( 'sortBy' => $by, 'sortOrder' => $order );
			if ( 'distance' === $by ) {
				$params += array( 'lat' => '-34.9', 'lng' => '138.6', 'radius' => '25' );
			}
			$query = $this->gateway_query_for( $params );

			self::assertSame( $by, $query['sortBy'] ?? null, $pair );
			self::assertSame( $order, $query['sortOrder'] ?? null, $pair );
		}
		self::assertSame( array( 'published_at:desc', 'expires_at:asc', 'title:asc', 'salary_max:desc', 'distance:asc' ), Agend_Apps_Jobs_REST_Controller::SORT_ORDERS );
	}

	#[Test]
	public function should_drop_an_order_that_is_not_on_the_list_and_leave_the_gateway_default(): void {
		$query = $this->gateway_query_for( array( 'sortBy' => 'title', 'sortOrder' => 'desc' ) );

		self::assertArrayNotHasKey( 'sortBy', $query );
		self::assertArrayNotHasKey( 'sortOrder', $query );
	}

	#[Test]
	public function should_drop_a_distance_sort_that_has_no_location_rather_than_let_the_gateway_refuse_it(): void {
		$query = $this->gateway_query_for( array( 'sortBy' => 'distance', 'sortOrder' => 'asc' ) );

		self::assertArrayNotHasKey( 'sortBy', $query );
		self::assertArrayNotHasKey( 'sortOrder', $query );
	}

	#[Test]
	public function should_drop_a_distance_sort_when_the_location_is_only_partly_given(): void {
		$query = $this->gateway_query_for( array( 'sortBy' => 'distance', 'sortOrder' => 'asc', 'lat' => '-34.9', 'lng' => '138.6' ) );

		self::assertArrayNotHasKey( 'sortBy', $query );
		self::assertArrayNotHasKey( 'lat', $query );
	}

	#[Test]
	public function should_always_send_the_distance_sort_nearest_first(): void {
		$query = $this->gateway_query_for(
			array(
				'sortBy'    => 'distance',
				'sortOrder' => 'desc',
				'lat'       => '-34.9',
				'lng'       => '138.6',
				'radius'    => '25',
			)
		);

		self::assertSame( 'distance', $query['sortBy'] ?? null );
		self::assertSame( 'asc', $query['sortOrder'] ?? null );
	}

	#[Test]
	public function should_cache_a_search_by_its_point_radius_and_sort(): void {
		$base = array(
			'lat'    => -34.928,
			'lng'    => 138.6,
			'radius' => 25.0,
		);
		$body = array( 'success' => true, 'data' => array() );

		agend_apps_jobs_get_jobs( $base );
		Agend_Test_WP::queue_response( 200, $body );
		agend_apps_jobs_get_jobs( $base );
		self::assertCount( 1, Agend_Test_WP::$requests, 'The same search is answered from the cache.' );

		$variants = array(
			'another point'  => array( 'lat' => -33.868 ) + $base,
			'another radius' => array( 'radius' => 50.0 ) + $base,
			'nearest first'  => array( 'sortBy' => 'distance', 'sortOrder' => 'asc' ) + $base,
			'no point'       => array(),
		);
		$expected = 1;
		foreach ( $variants as $label => $query ) {
			Agend_Test_WP::queue_response( 200, $body );
			agend_apps_jobs_get_jobs( $query );
			++$expected;
			self::assertCount( $expected, Agend_Test_WP::$requests, $label . ' is its own cache entry.' );
		}
	}

	#[Test]
	public function should_keep_the_distance_and_postcode_in_the_list_a_visitor_without_details_sees(): void {
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/jobs-visibility.php';

		$teaser = agend_apps_records_jobs_teaser(
			array(
				'title'             => 'Auditor',
				'location_postcode' => '5000',
				'distance_km'       => 12.3,
				'contact_name'      => 'Sam',
				'contact_phone'     => '0400 000 000',
				'reference_number'  => 'REF-1',
				'description'       => 'Full details.',
			)
		);

		self::assertSame( '5000', $teaser['location_postcode'] );
		self::assertSame( 12.3, $teaser['distance_km'] );
		foreach ( array( 'contact_name', 'contact_phone', 'reference_number', 'description' ) as $detail ) {
			self::assertArrayNotHasKey( $detail, $teaser );
		}
	}
}
