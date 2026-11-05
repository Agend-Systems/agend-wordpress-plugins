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
}
