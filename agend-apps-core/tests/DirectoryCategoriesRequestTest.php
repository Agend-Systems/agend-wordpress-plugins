<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend_Apps_Cache;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;

/**
 * The categories request behind the Category filter, the legacy filter bar and
 * the catalogue's category exclusion options.
 *
 * Runs in separate processes: loading agend_apps_directory_get_categories()
 * into the shared process would make every other test that resolves the
 * catalogue's category exclusion options call the gateway.
 */
#[RunTestsInSeparateProcesses]
final class DirectoryCategoriesRequestTest extends TestCase {

	private const DEFAULT_ARGS = array(
		'query' => array(
			'limit'       => 500,
			'active_only' => true,
			'counts'      => false,
		),
	);

	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/directory-categories.php';
	}

	/**
	 * @return array{data: array<int, array<string, mixed>>}
	 */
	private function categories( int $count ): array {
		$data = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$data[] = array( 'id' => 'c' . $i, 'name' => 'Category ' . $i, 'parent_id' => null, 'is_active' => true );
		}

		return array( 'data' => $data );
	}

	#[Test]
	public function should_ask_for_up_to_500_active_categories_without_listing_counts(): void {
		Agend_Test_WP::queue_response( 200, $this->categories( 160 ) );

		$response = agend_apps_directory_get_categories();

		$this->assertCount( 1, Agend_Test_WP::$requests );
		$this->assertStringEndsWith( '/directory/categories?limit=500&active_only=true&counts=false', Agend_Test_WP::$requests[0]['url'] );
		$this->assertCount( 160, $response['data'], 'more than 100 categories reach the filter' );
	}

	#[Test]
	public function should_cache_the_response_under_a_key_that_carries_the_request_arguments(): void {
		Agend_Test_WP::queue_response( 200, $this->categories( 2 ) );

		$first  = agend_apps_directory_get_categories();
		$second = agend_apps_directory_get_categories();

		$this->assertCount( 1, Agend_Test_WP::$requests, 'the second read should be served from the cache' );
		$this->assertSame( $first, $second );
		$this->assertArrayHasKey( 'agend_apps_' . Agend_Apps_Cache::build_key( 'directory_categories', self::DEFAULT_ARGS ), Agend_Test_WP::$transients );
	}

	#[Test]
	public function should_not_serve_a_list_cached_without_request_arguments_by_an_earlier_release(): void {
		Agend_Test_WP::$transients[ 'agend_apps_' . Agend_Apps_Cache::build_key( 'directory_categories' ) ] = $this->categories( 50 );
		Agend_Test_WP::queue_response( 200, $this->categories( 160 ) );

		$response = agend_apps_directory_get_categories();

		$this->assertCount( 1, Agend_Test_WP::$requests, 'the argument-free entry must not satisfy the new request' );
		$this->assertCount( 160, $response['data'] );
	}

	#[Test]
	public function should_send_and_cache_under_the_arguments_a_site_filter_returns(): void {
		$filtered = array( 'query' => array( 'limit' => 200, 'active_only' => true, 'counts' => true ) );
		add_filter(
			'agend_apps_directory_get_categories_args',
			static function ( array $args ) use ( $filtered ): array {
				TestCase::assertSame( self::DEFAULT_ARGS, $args, 'the filter receives the default arguments' );
				return $filtered;
			}
		);
		Agend_Test_WP::queue_response( 200, $this->categories( 2 ) );

		agend_apps_directory_get_categories();

		$this->assertStringEndsWith( '/directory/categories?limit=200&active_only=true&counts=true', Agend_Test_WP::$requests[0]['url'] );
		$this->assertArrayHasKey( 'agend_apps_' . Agend_Apps_Cache::build_key( 'directory_categories', $filtered ), Agend_Test_WP::$transients );
		$this->assertArrayNotHasKey( 'agend_apps_' . Agend_Apps_Cache::build_key( 'directory_categories', self::DEFAULT_ARGS ), Agend_Test_WP::$transients );
	}
	#[Test]
	public function should_retry_once_at_100_under_its_own_cache_entry_when_the_gateway_rejects_a_limit_of_500(): void {
		Agend_Test_WP::queue_response( 400, array( 'error' => array( 'message' => 'Invalid query parameters' ) ) );
		Agend_Test_WP::queue_response( 200, $this->categories( 100 ) );

		$response = agend_apps_directory_get_categories();

		$capped = array( 'query' => array( 'limit' => 100, 'active_only' => true, 'counts' => false ) );
		$this->assertCount( 2, Agend_Test_WP::$requests );
		$this->assertStringEndsWith( '/directory/categories?limit=500&active_only=true&counts=false', Agend_Test_WP::$requests[0]['url'] );
		$this->assertStringEndsWith( '/directory/categories?limit=100&active_only=true&counts=false', Agend_Test_WP::$requests[1]['url'] );
		$this->assertCount( 100, $response['data'] );
		$this->assertArrayHasKey( 'agend_apps_' . Agend_Apps_Cache::build_key( 'directory_categories', $capped ), Agend_Test_WP::$transients );
		$this->assertArrayNotHasKey( 'agend_apps_' . Agend_Apps_Cache::build_key( 'directory_categories', self::DEFAULT_ARGS ), Agend_Test_WP::$transients );
	}

	#[Test]
	public function should_not_retry_when_the_first_request_succeeds(): void {
		Agend_Test_WP::queue_response( 200, $this->categories( 160 ) );
		Agend_Test_WP::queue_response( 200, $this->categories( 100 ) );

		$response = agend_apps_directory_get_categories();

		$this->assertCount( 1, Agend_Test_WP::$requests );
		$this->assertCount( 160, $response['data'] );
	}

	#[Test]
	public function should_return_the_error_after_one_retry_when_the_capped_request_also_fails(): void {
		Agend_Test_WP::queue_response( 400, array( 'error' => array( 'message' => 'Invalid query parameters' ) ) );
		Agend_Test_WP::queue_response( 500, array( 'error' => array( 'message' => 'Internal server error' ) ) );
		Agend_Test_WP::queue_response( 200, $this->categories( 2 ) );

		$response = agend_apps_directory_get_categories();

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertCount( 2, Agend_Test_WP::$requests, 'one retry, never a loop' );
	}

	#[Test]
	public function should_not_retry_a_rejected_request_whose_limit_is_already_100_or_less(): void {
		add_filter(
			'agend_apps_directory_get_categories_args',
			static function (): array {
				return array( 'query' => array( 'limit' => 100, 'active_only' => true, 'counts' => false ) );
			}
		);
		Agend_Test_WP::queue_response( 400, array( 'error' => array( 'message' => 'Invalid query parameters' ) ) );
		Agend_Test_WP::queue_response( 200, $this->categories( 2 ) );

		$this->assertInstanceOf( \WP_Error::class, agend_apps_directory_get_categories() );
		$this->assertCount( 1, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_pass_the_capped_response_through_the_response_filter(): void {
		add_filter(
			'agend_apps_directory_get_categories_response',
			static function ( array $response ): array {
				$response['filtered'] = true;
				return $response;
			}
		);
		Agend_Test_WP::queue_response( 400, array( 'error' => array( 'message' => 'Invalid query parameters' ) ) );
		Agend_Test_WP::queue_response( 200, $this->categories( 3 ) );

		$this->assertTrue( agend_apps_directory_get_categories()['filtered'] );
	}
	#[Test]
	public function should_retry_at_100_when_the_gateway_answers_a_limit_of_500_with_a_422(): void {
		Agend_Test_WP::queue_response( 422, array( 'error' => array( 'message' => 'Unprocessable query' ) ) );
		Agend_Test_WP::queue_response( 200, $this->categories( 100 ) );

		$response = agend_apps_directory_get_categories();

		$this->assertCount( 2, Agend_Test_WP::$requests );
		$this->assertStringEndsWith( '/directory/categories?limit=100&active_only=true&counts=false', Agend_Test_WP::$requests[1]['url'] );
		$this->assertCount( 100, $response['data'] );
	}

	#[Test]
	public function should_make_one_request_and_return_the_error_when_the_gateway_fails_with_a_500(): void {
		Agend_Test_WP::queue_response( 500, array( 'error' => array( 'message' => 'Internal server error' ) ) );
		Agend_Test_WP::queue_response( 200, $this->categories( 100 ) );

		$response = agend_apps_directory_get_categories();

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 500, $response->get_error_data()['status_code'] );
		$this->assertCount( 1, Agend_Test_WP::$requests, 'a failing gateway is not asked twice' );
	}

	#[Test]
	public function should_make_one_request_and_return_the_error_when_the_request_fails_in_transport(): void {
		Agend_Test_WP::queue_transport_error( 'http_request_failed', 'Operation timed out' );
		Agend_Test_WP::queue_response( 200, $this->categories( 100 ) );

		$response = agend_apps_directory_get_categories();

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'http_request_failed', $response->get_error_code() );
		$this->assertCount( 1, Agend_Test_WP::$requests, 'an unreachable gateway is not asked twice' );
	}
}
