<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend_Apps_Directory_REST_Controller;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use WP_REST_Request;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/directory-map.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/rest/class-agend-apps-rest-controller.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/rest/directory-routes.php';

/**
 * The page and page-size arguments of the directory REST routes hold the
 * bounds they declare. WordPress checks `minimum` and `maximum` only for an
 * argument with no sanitize callback, so these tests apply each declared
 * callback the way WordPress does rather than trusting the declaration.
 */
final class DirectoryRestArgBoundsTest extends TestCase {

	private const PAGING_ARGS_BY_METHOD = array(
		'search_args'   => array( 'page', 'limit', 'per_page' ),
		'markers_args'  => array( 'limit' ),
		'listings_args' => array( 'page', 'per_page' ),
		'reviews_args'  => array( 'page', 'limit' ),
	);

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function route_args( string $method ): array {
		$reflection = new ReflectionMethod( Agend_Apps_Directory_REST_Controller::class, $method );
		$reflection->setAccessible( true );

		return $reflection->invoke( new Agend_Apps_Directory_REST_Controller() );
	}

	/**
	 * The paging arguments a route method declares, pinned to the expected
	 * names so a renamed or dropped argument fails rather than skipping.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function paging_args( string $method ): array {
		$paging = array_intersect_key( $this->route_args( $method ), array_flip( array( 'page', 'limit', 'per_page' ) ) );
		$this->assertSame( self::PAGING_ARGS_BY_METHOD[ $method ], array_keys( $paging ), $method );

		return $paging;
	}

	/**
	 * Applies a declared argument the way WordPress sanitises a request
	 * parameter: the callback receives the value, the request and the name.
	 *
	 * @param mixed $value Raw query value.
	 * @return mixed
	 */
	private function sanitise( string $method, string $name, $value ) {
		$arg = $this->route_args( $method )[ $name ];

		return call_user_func( $arg['sanitize_callback'], $value, new WP_REST_Request(), $name );
	}

	/**
	 * Builds a request the way WordPress hands it to a route callback: every
	 * parameter already passed through its declared sanitize callback.
	 *
	 * @param array<string, mixed> $params Raw query parameters.
	 */
	private function sanitised_request( string $method, array $params ): WP_REST_Request {
		$request = new WP_REST_Request( 'GET', '/agend-apps/v1/directory/search' );
		foreach ( $params as $name => $value ) {
			$request->set_param( $name, $this->sanitise( $method, $name, $value ) );
		}

		return $request;
	}

	/**
	 * @return array<string, array{string, string, int}>
	 */
	public static function bounded_page_sizes(): array {
		return array(
			'search limit'      => array( 'search_args', 'limit', 100 ),
			'search per_page'   => array( 'search_args', 'per_page', 100 ),
			'markers limit'     => array( 'markers_args', 'limit', 2000 ),
			'listings per_page' => array( 'listings_args', 'per_page', 100 ),
			'reviews limit'     => array( 'reviews_args', 'limit', 100 ),
		);
	}

	#[Test]
	#[DataProvider( 'bounded_page_sizes' )]
	public function should_reduce_a_page_size_to_the_declared_maximum_when_a_larger_one_is_requested( string $method, string $name, int $maximum ): void {
		$this->assertSame( $maximum, $this->sanitise( $method, $name, (string) ( $maximum + 1 ) ) );
		$this->assertSame( $maximum, $this->sanitise( $method, $name, '10000000' ) );
	}

	#[Test]
	#[DataProvider( 'bounded_page_sizes' )]
	public function should_keep_a_page_size_unchanged_when_it_is_within_the_declared_bounds( string $method, string $name, int $maximum ): void {
		$this->assertSame( 1, $this->sanitise( $method, $name, '1' ) );
		$this->assertSame( 5, $this->sanitise( $method, $name, '5' ) );
		$this->assertSame( $maximum, $this->sanitise( $method, $name, (string) $maximum ) );
	}

	#[Test]
	public function should_keep_every_marker_limit_the_map_widget_offers_when_a_map_requests_it(): void {
		foreach ( array( 250, 500, 1000, 2000 ) as $limit ) {
			$this->assertSame( $limit, $this->sanitise( 'markers_args', 'limit', (string) $limit ) );
		}
	}

	#[Test]
	public function should_raise_a_paging_value_to_the_minimum_when_it_is_zero_or_negative(): void {
		foreach ( array_keys( self::PAGING_ARGS_BY_METHOD ) as $method ) {
			foreach ( $this->paging_args( $method ) as $name => $arg ) {
				$this->assertSame( 1, $this->sanitise( $method, $name, '0' ), "{$method} {$name}" );
				$this->assertSame( 1, $this->sanitise( $method, $name, '-5' ), "{$method} {$name}" );
			}
		}
	}

	/**
	 * @return array<string, array{mixed, int}>
	 */
	public static function irregular_values(): array {
		return array(
			'empty'          => array( '', 1 ),
			'non-numeric'    => array( 'abc', 1 ),
			'decimal'        => array( '1.5', 1 ),
			'exponent'       => array( '1e3', 100 ),
			'leading digits' => array( '50abc', 50 ),
			'array'          => array( array( '5' ), 1 ),
		);
	}

	/**
	 * @param mixed $value Raw query value.
	 */
	#[Test]
	#[DataProvider( 'irregular_values' )]
	public function should_clamp_a_page_size_into_the_declared_bounds_when_the_value_is_not_a_plain_integer( $value, int $expected ): void {
		$this->assertSame( $expected, $this->sanitise( 'search_args', 'limit', $value ) );
	}

	#[Test]
	public function should_keep_a_large_page_number_when_page_declares_no_maximum(): void {
		foreach ( array( 'search_args', 'listings_args', 'reviews_args' ) as $method ) {
			$this->assertArrayNotHasKey( 'maximum', $this->route_args( $method )['page'], $method );
			$this->assertSame( 5000, $this->sanitise( $method, 'page', '5000' ), $method );
		}
	}

	#[Test]
	public function should_enforce_the_declared_bounds_when_any_paging_argument_declares_them(): void {
		foreach ( array_keys( self::PAGING_ARGS_BY_METHOD ) as $method ) {
			foreach ( $this->paging_args( $method ) as $name => $arg ) {
				$this->assertNotSame( 'absint', $arg['sanitize_callback'], "{$method} {$name}" );
				$this->assertSame( $arg['minimum'], $this->sanitise( $method, $name, (string) ( $arg['minimum'] - 1 ) ), "{$method} {$name}" );
				if ( isset( $arg['maximum'] ) ) {
					$this->assertSame( $arg['maximum'], $this->sanitise( $method, $name, (string) ( $arg['maximum'] + 1 ) ), "{$method} {$name}" );
				}
			}
		}
	}

	#[Test]
	public function should_forward_a_page_size_of_one_hundred_when_a_search_requests_more(): void {
		$reflection = new ReflectionMethod( Agend_Apps_Directory_REST_Controller::class, 'search_filters' );
		$reflection->setAccessible( true );

		$filters = $reflection->invoke(
			new Agend_Apps_Directory_REST_Controller(),
			$this->sanitised_request( 'search_args', array( 'limit' => '500', 'per_page' => '250', 'page' => '2' ) )
		);

		$this->assertSame( 100, $filters['limit'] );
		$this->assertSame( 100, $filters['per_page'] );
		$this->assertSame( 2, $filters['page'] );
	}

	#[Test]
	public function should_ask_the_api_for_two_thousand_markers_when_a_map_requests_more(): void {
		Agend_Test_WP::queue_response( 200, array( 'data' => array(), 'meta' => array( 'truncated' => false ) ) );

		( new Agend_Apps_Directory_REST_Controller() )->get_markers(
			$this->sanitised_request( 'markers_args', array( 'limit' => '5000' ) )
		);

		$url = Agend_Test_WP::$requests[0]['url'];
		$this->assertStringContainsString( '/directory/markers?', $url );
		$this->assertStringContainsString( 'limit=2000', $url );
	}
}
