<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend_Apps_Directory_REST_Controller;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use WP_REST_Request;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/directory-map.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/rest/class-agend-apps-rest-controller.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/rest/directory-routes.php';

/**
 * The Organisation filter through the plugin's REST proxy
 * (SPEC-DIR-20261012-directory-organisation-filter US-1.1, AC30): the search
 * and markers routes share search_args() and search_filters(), so a gap in
 * either drops `locationName` before it reaches the API.
 */
final class DirectoryLocationNameProxyTest extends TestCase {

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function route_args( string $method ): array {
		$reflection = new ReflectionMethod( Agend_Apps_Directory_REST_Controller::class, $method );
		$reflection->setAccessible( true );

		return $reflection->invoke( new Agend_Apps_Directory_REST_Controller() );
	}

	/**
	 * @param array<string, mixed> $params Request parameters.
	 */
	private function request( array $params ): WP_REST_Request {
		$request = new WP_REST_Request( 'GET', '/agend-apps/v1/directory/search' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return $request;
	}

	#[Test]
	public function should_declare_location_name_as_sanitised_text_when_search_and_markers_register_their_args(): void {
		foreach ( array( 'search_args', 'markers_args' ) as $method ) {
			$args = $this->route_args( $method );

			$this->assertArrayHasKey( 'locationName', $args, $method );
			$this->assertSame( 'string', $args['locationName']['type'], $method );
			$this->assertSame( 'sanitize_text_field', $args['locationName']['sanitize_callback'], $method );
		}
	}

	#[Test]
	public function should_forward_location_name_when_a_search_request_carries_it(): void {
		$reflection = new ReflectionMethod( Agend_Apps_Directory_REST_Controller::class, 'search_filters' );
		$reflection->setAccessible( true );

		$filters = $reflection->invoke(
			new Agend_Apps_Directory_REST_Controller(),
			$this->request( array( 'locationName' => "St Vincent's", 'city' => 'Sydney', 'notAParam' => 'x' ) )
		);

		$this->assertSame( "St Vincent's", $filters['locationName'] );
		$this->assertSame( 'Sydney', $filters['city'] );
		$this->assertArrayNotHasKey( 'notAParam', $filters );
	}

	#[Test]
	public function should_ask_the_api_markers_without_an_added_backslash_when_the_name_has_an_apostrophe(): void {
		Agend_Test_WP::queue_response( 200, array( 'data' => array(), 'meta' => array( 'truncated' => false ) ) );

		( new Agend_Apps_Directory_REST_Controller() )->get_markers( $this->request( array( 'locationName' => "St Vincent's", 'limit' => 500 ) ) );

		$url = Agend_Test_WP::$requests[0]['url'];
		$this->assertStringContainsString( '/directory/markers?', $url );
		$this->assertStringContainsString( 'locationName=St%20Vincent%27s', $url );
		$this->assertStringNotContainsString( '%5C', $url, 'an apostrophe must not gain a backslash on the way to the API' );
	}

	#[Test]
	public function should_send_no_location_name_to_the_api_when_the_request_has_none(): void {
		Agend_Test_WP::queue_response( 200, array( 'data' => array(), 'meta' => array( 'truncated' => false ) ) );

		( new Agend_Apps_Directory_REST_Controller() )->get_markers( $this->request( array( 'city' => 'Sydney' ) ) );

		$this->assertStringNotContainsString( 'locationName', Agend_Test_WP::$requests[0]['url'] );
	}
}
