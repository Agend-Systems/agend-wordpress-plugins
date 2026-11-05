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
use WP_REST_Request;
use WP_REST_Response;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/directory-map.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/rest/class-agend-apps-rest-controller.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/rest/directory-routes.php';

/**
 * The geocode route's per-address limit: the route is open to anyone and
 * spends the site's key, so one address is refused after ten place searches a
 * minute, before the gateway is called.
 */
final class DirectoryGeocodeProxyLimitTest extends TestCase {

	private const VISITOR = '203.0.113.7';

	protected function setUp(): void {
		parent::setUp();
		$_SERVER['REMOTE_ADDR'] = self::VISITOR;
	}

	protected function tearDown(): void {
		unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR'] );
		parent::tearDown();
	}

	private function geocode( string $place ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/agend-apps/v1/directory/geocode' );
		$request->set_param( 'q', $place );

		return ( new Agend_Apps_Directory_REST_Controller() )->geocode( $request );
	}

	private function queue_found_places( int $count ): void {
		for ( $i = 0; $i < $count; $i++ ) {
			Agend_Test_WP::queue_response( 200, array( 'data' => array( 'latitude' => -33.8, 'longitude' => 151 ) ) );
		}
	}

	private function use_up_the_limit(): void {
		$this->queue_found_places( 10 );
		for ( $i = 1; $i <= 10; $i++ ) {
			$this->assertSame( 200, $this->geocode( 'Place ' . $i )->get_status(), 'search ' . $i . ' is within the limit' );
		}
	}

	#[Test]
	public function should_refuse_with_429_and_no_gateway_call_when_an_address_has_searched_ten_times_in_a_minute(): void {
		$this->use_up_the_limit();
		$this->assertCount( 10, Agend_Test_WP::$requests );

		$response = $this->geocode( 'Place 11' );

		$this->assertSame( 429, $response->get_status() );
		$this->assertCount( 10, Agend_Test_WP::$requests, 'a refused search never reaches the gateway' );
	}

	#[Test]
	public function should_answer_in_the_envelope_the_location_filter_reads_when_a_search_is_refused(): void {
		$this->use_up_the_limit();

		$response = $this->geocode( 'Place 11' );
		$body     = $response->get_data();

		// filters.js lookup() reads body.data.status_code and shows 429 as busy.
		$this->assertSame( 429, $body['data']['status_code'] );
		$this->assertArrayNotHasKey( 'latitude', $body['data'] );
		$this->assertSame( 'agend_apps_geocode_rate_limited', $body['code'] );
		$this->assertSame( 'Place search is busy. Please try again in a moment.', $body['message'] );
	}

	#[Test]
	public function should_say_when_to_retry_and_forbid_caching_when_a_search_is_refused(): void {
		$this->use_up_the_limit();

		$headers = $this->geocode( 'Place 11' )->get_headers();

		$this->assertGreaterThanOrEqual( 1, (int) $headers['Retry-After'] );
		$this->assertLessThanOrEqual( 60, (int) $headers['Retry-After'] );
		$this->assertSame( 'no-store', $headers['Cache-Control'] );
	}

	#[Test]
	public function should_proxy_a_search_within_the_limit_and_serve_a_repeat_from_the_wordpress_cache(): void {
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'latitude' => -33.8, 'longitude' => 151, 'display_name' => 'Parramatta' ) ) );

		$first  = $this->geocode( 'Parramatta' );
		$second = $this->geocode( 'parramatta' );

		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( -33.8, $first->get_data()['data']['latitude'] );
		$this->assertSame( $first->get_data(), $second->get_data() );
		$this->assertCount( 1, Agend_Test_WP::$requests, 'the repeat is served from the cache' );
		$this->assertStringContainsString( '/directory/geocode?q=Parramatta', Agend_Test_WP::$requests[0]['url'] );
	}

	#[Test]
	public function should_count_a_cached_place_when_an_address_repeats_one_search(): void {
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'latitude' => -33.8, 'longitude' => 151 ) ) );

		for ( $i = 1; $i <= 10; $i++ ) {
			$this->assertSame( 200, $this->geocode( 'Parramatta' )->get_status() );
		}

		$this->assertSame( 429, $this->geocode( 'Parramatta' )->get_status() );
		$this->assertCount( 1, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_keep_other_addresses_searching_when_one_address_is_refused(): void {
		$this->use_up_the_limit();
		$this->assertSame( 429, $this->geocode( 'Place 11' )->get_status() );

		$_SERVER['REMOTE_ADDR'] = '198.51.100.20';
		$this->queue_found_places( 1 );

		$this->assertSame( 200, $this->geocode( 'Place 11' )->get_status() );
	}

	#[Test]
	public function should_ignore_a_forwarded_header_when_the_limit_is_keyed(): void {
		$this->use_up_the_limit();

		// A bot varying a header it controls stays in its own bucket.
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.99';
		add_filter( 'agend_apps_visitor_ip', static fn() => '198.51.100.99' );

		$this->assertSame( 429, $this->geocode( 'Place 11' )->get_status() );
	}

	#[Test]
	public function should_store_only_the_salted_hash_of_the_address_when_counting(): void {
		$this->queue_found_places( 1 );
		$this->geocode( 'Parramatta' );

		$key = 'agend_apps_geocode_ip_' . wp_hash( self::VISITOR );
		$this->assertArrayHasKey( $key, Agend_Test_WP::$transients );

		$counters = array_filter(
			Agend_Test_WP::$transients,
			static fn( $name ) => str_starts_with( (string) $name, 'agend_apps_geocode_ip_' ),
			ARRAY_FILTER_USE_KEY
		);
		$this->assertCount( 1, $counters );
		$this->assertStringNotContainsString( self::VISITOR, (string) json_encode( $counters ) );
		$this->assertNotSame( 'agend_apps_geocode_ip_' . md5( self::VISITOR ), $key, 'an unsalted hash could be reversed by trying every address' );
	}

	#[Test]
	public function should_allow_searches_again_when_the_window_from_the_first_search_closes(): void {
		$now = 1_700_000_000;
		for ( $i = 0; $i < 10; $i++ ) {
			$this->assertSame( 0, agend_apps_directory_geocode_throttle( self::VISITOR, $now ) );
		}

		$this->assertSame( 30, agend_apps_directory_geocode_throttle( self::VISITOR, $now + 30 ) );
		$this->assertSame( 1, agend_apps_directory_geocode_throttle( self::VISITOR, $now + 59 ) );
		$this->assertSame( 0, agend_apps_directory_geocode_throttle( self::VISITOR, $now + 60 ) );
	}

	#[Test]
	public function should_never_refuse_an_address_when_it_searches_less_than_ten_times_a_minute(): void {
		$now = 1_700_000_000;
		for ( $i = 0; $i < 30; $i++ ) {
			$this->assertSame( 0, agend_apps_directory_geocode_throttle( self::VISITOR, $now + $i * 7 ), 'search ' . ( $i + 1 ) );
		}
	}

	#[Test]
	public function should_expire_the_counter_with_its_window_when_it_is_written(): void {
		$now = 1_700_000_000;
		agend_apps_directory_geocode_throttle( self::VISITOR, $now );
		agend_apps_directory_geocode_throttle( self::VISITOR, $now + 45 );

		$key = 'agend_apps_geocode_ip_' . wp_hash( self::VISITOR );
		$this->assertSame( 15, Agend_Test_WP::$transient_ttls[ $key ], 'a later search does not stretch the window' );
	}
}
