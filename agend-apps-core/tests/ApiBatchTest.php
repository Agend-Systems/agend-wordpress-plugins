<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\AppsCore;

use Agend_Apps_API;
use Agend_Apps_Endpoint_Scopes;
use Agend_Apps_Key_Scopes;
use Agend_Apps_Logger;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use WP_Error;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/logging/class-agend-apps-log-redactor.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/logging/class-agend-apps-log-store.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/logging/class-agend-apps-logger.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/health.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-http-batch.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-api.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-key-scopes.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-endpoint-scopes.php';
require_once __DIR__ . '/fixtures/memory-log-store.php';

/**
 * get_cached_many() serves each read exactly as get_cached() would serve it
 * alone, sending the reads not already held as one batch. Run through the
 * sequential fallback, which sends through wp_remote_request() and its
 * queued responses; the Requests adapter has its own suite.
 */
#[CoversClass( Agend_Apps_API::class )]
final class ApiBatchTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Agend_Apps_Logger::set_instance( new Agend_Apps_Logger( new MemoryLogStore() ) );
		Agend_Apps_Endpoint_Scopes::set_map( null );
		Agend_Test_WP::set_filter( 'agend_apps_api_parallel', false );
	}

	protected function tearDown(): void {
		Agend_Apps_Endpoint_Scopes::set_map( null );
		parent::tearDown();
	}

	/**
	 * Makes each resolution of the bearer return the next token, repeating the
	 * last one, and counts the resolutions.
	 *
	 * @param string[] $tokens Tokens in resolution order.
	 * @return \ArrayObject<string, int>
	 */
	private function setBearerSequence( array $tokens ): \ArrayObject {
		$state = new \ArrayObject( array( 'calls' => 0 ) );

		Agend_Test_WP::$filters['agend_apps_bearer_token'] = static function () use ( $tokens, $state ): string {
			$token = $tokens[ min( $state['calls'], count( $tokens ) - 1 ) ];
			++$state['calls'];

			return $token;
		};

		return $state;
	}

	/**
	 * @return array<string, array{path: string, args: array, cache_key: string, ttl: int}>
	 */
	private function reads(): array {
		return array(
			'search' => array( 'path' => '/directory/search', 'args' => array( 'query' => array( 'q' => '' ) ), 'cache_key' => 'directory_search_x', 'ttl' => 120 ),
			'facets' => array( 'path' => '/directory/facets', 'args' => array( 'query' => array( 'fields' => 'custom.size' ) ), 'cache_key' => 'directory_facets_x', 'ttl' => 600 ),
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function rows(): array {
		return agend_apps_logger()->pending();
	}

	private function authorisationOf( int $request_index ): ?string {
		return Agend_Test_WP::$requests[ $request_index ]['headers']['Authorization'] ?? null;
	}

	private function unauthorised(): array {
		return array( 'error' => array( 'message' => 'unauthorized' ) );
	}

	#[Test]
	public function should_return_each_read_under_its_own_key_in_the_order_given(): void {
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'listing' ) ) );
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'custom.size' => array() ) ) );

		$results = ( new Agend_Apps_API() )->get_cached_many( $this->reads() );

		$this->assertSame( array( 'search', 'facets' ), array_keys( $results ) );
		$this->assertSame( array( 'listing' ), $results['search']['data'] );
		$this->assertSame( array( 'custom.size' => array() ), $results['facets']['data'] );
	}

	#[Test]
	public function should_answer_each_read_exactly_as_get_cached_would_alone(): void {
		$this->setBearerSequence( array( 'member-token' ) );
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'listing' ) ) );
		Agend_Test_WP::queue_response( 500, array( 'error' => array( 'message' => 'boom' ) ) );
		$batched = ( new Agend_Apps_API() )->get_cached_many( $this->reads() );
		$batched_requests = Agend_Test_WP::$requests;

		Agend_Test_WP::$requests = array();
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'listing' ) ) );
		Agend_Test_WP::queue_response( 500, array( 'error' => array( 'message' => 'boom' ) ) );
		$api    = new Agend_Apps_API();
		$single = array();
		foreach ( $this->reads() as $id => $read ) {
			$single[ $id ] = $api->get_cached( $read['path'], $read['args'], $read['cache_key'], $read['ttl'] );
		}

		$this->assertEquals( $single, $batched );
		$this->assertSame( Agend_Test_WP::$requests, $batched_requests );
	}

	#[Test]
	public function should_send_only_the_reads_not_already_held(): void {
		Agend_Test_WP::$transients['agend_apps_directory_search_x'] = array( 'data' => array( 'stored' ) );

		$results = ( new Agend_Apps_API() )->get_cached_many( $this->reads() );

		$this->assertSame( array( 'stored' ), $results['search']['data'] );
		$this->assertCount( 1, Agend_Test_WP::$requests );
		$this->assertStringContainsString( '/directory/facets', Agend_Test_WP::$requests[0]['url'] );
	}

	#[Test]
	public function should_send_a_held_read_again_when_it_is_asked_for_fresh(): void {
		Agend_Test_WP::$transients['agend_apps_directory_search_x'] = array( 'data' => array( 'stored' ) );
		$reads                    = $this->reads();
		$reads['search']['fresh'] = true;

		( new Agend_Apps_API() )->get_cached_many( $reads );

		$this->assertCount( 2, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_store_no_member_token_read_and_hold_it_for_the_rest_of_the_request(): void {
		$this->setBearerSequence( array( 'member-token' ) );
		$api = new Agend_Apps_API();

		$batched = $api->get_cached_many( $this->reads() );
		$again   = $api->get_cached( '/directory/facets', array( 'query' => array( 'fields' => 'custom.size' ) ), 'directory_facets_x', 600 );

		$this->assertSame( array(), preg_grep( '/^agend_apps_directory_/', array_keys( Agend_Test_WP::$transients ) ) );
		$this->assertSame( $batched['facets'], $again );
		$this->assertCount( 2, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_resolve_the_member_token_once_for_the_whole_batch(): void {
		$resolutions = $this->setBearerSequence( array( 'member-token' ) );

		( new Agend_Apps_API() )->get_cached_many( $this->reads() );

		$this->assertSame( 1, $resolutions['calls'] );
		$this->assertSame( 'Bearer member-token', $this->authorisationOf( 0 ) );
		$this->assertSame( 'Bearer member-token', $this->authorisationOf( 1 ) );
	}

	#[Test]
	public function should_resolve_once_and_send_no_token_when_the_visitor_has_none(): void {
		$resolutions = $this->setBearerSequence( array( '', 'late-token' ) );

		( new Agend_Apps_API() )->get_cached_many( $this->reads() );

		$this->assertSame( 1, $resolutions['calls'] );
		$this->assertNull( $this->authorisationOf( 0 ) );
		$this->assertNull( $this->authorisationOf( 1 ) );
		$this->assertSame( array( 'api_key', 'api_key' ), array_column( $this->rows(), 'auth_mode' ) );
	}

	#[Test]
	public function should_log_each_exchange_as_its_own_row_under_the_pages_request_id(): void {
		( new Agend_Apps_API() )->get_cached_many( $this->reads() );

		$rows = $this->rows();
		$this->assertCount( 2, $rows );
		$this->assertSame( array( '/v1/directory/search', '/v1/directory/facets' ), array_column( $rows, 'path' ) );
		$this->assertSame( array( agend_apps_logger()->request_id(), agend_apps_logger()->request_id() ), array_column( $rows, 'request_id' ) );
		$this->assertSame( array( 1, 1 ), array_column( $rows, 'attempt' ) );
	}

	#[Test]
	public function should_retry_rejected_reads_with_a_token_re_resolved_once_for_the_batch(): void {
		$resolutions = $this->setBearerSequence( array( 'revoked-token', 'fresh-token' ) );
		$rejected    = array();
		add_action(
			'agend_apps_bearer_rejected',
			static function ( $token, $status ) use ( &$rejected ): void {
				$rejected[] = array( $token, $status );
			},
			10,
			2
		);
		Agend_Test_WP::queue_response( 401, $this->unauthorised() );
		Agend_Test_WP::queue_response( 401, $this->unauthorised() );

		$results = ( new Agend_Apps_API() )->get_cached_many( $this->reads() );

		$this->assertSame( 2, $resolutions['calls'], 'once for the batch, once after the 401' );
		$this->assertSame( array( array( 'revoked-token', 401 ), array( 'revoked-token', 401 ) ), $rejected );
		$this->assertSame( array( 'Bearer revoked-token', 'Bearer revoked-token', 'Bearer fresh-token', 'Bearer fresh-token' ), array_map( array( $this, 'authorisationOf' ), array( 0, 1, 2, 3 ) ) );
		$this->assertSame( array( 1, 1, 2, 2 ), array_column( $this->rows(), 'attempt' ) );
		$this->assertIsArray( $results['search'] );
		$this->assertIsArray( $results['facets'] );
	}

	#[Test]
	public function should_retry_without_a_token_as_attempt_three_when_the_re_resolved_token_is_rejected_too(): void {
		$this->setBearerSequence( array( 'revoked-token', 'fresh-token' ) );
		$reads = array( 'facets' => $this->reads()['facets'] );
		Agend_Test_WP::queue_response( 401, $this->unauthorised() );
		Agend_Test_WP::queue_response( 403, $this->unauthorised() );

		( new Agend_Apps_API() )->get_cached_many( $reads );

		$this->assertSame( array( 'Bearer revoked-token', 'Bearer fresh-token', null ), array_map( array( $this, 'authorisationOf' ), array( 0, 1, 2 ) ) );
		$this->assertSame( array( 1, 2, 3 ), array_column( $this->rows(), 'attempt' ) );
		$this->assertSame( 'unattended', $this->rows()[2]['auth_mode'] );
	}

	#[Test]
	public function should_retry_without_a_token_as_attempt_two_when_the_re_resolved_token_is_the_same(): void {
		$this->setBearerSequence( array( 'member-token' ) );
		$reads = array( 'facets' => $this->reads()['facets'] );
		Agend_Test_WP::queue_response( 401, $this->unauthorised() );

		( new Agend_Apps_API() )->get_cached_many( $reads );

		$this->assertSame( array( 'Bearer member-token', null ), array_map( array( $this, 'authorisationOf' ), array( 0, 1 ) ) );
		$this->assertSame( array( 1, 2 ), array_column( $this->rows(), 'attempt' ) );
	}

	#[Test]
	public function should_retry_a_403_without_a_token_and_without_re_resolving(): void {
		$resolutions = $this->setBearerSequence( array( 'member-token', 'fresh-token' ) );
		$rejected    = 0;
		add_action(
			'agend_apps_bearer_rejected',
			static function () use ( &$rejected ): void {
				++$rejected;
			}
		);
		$reads = array( 'facets' => $this->reads()['facets'] );
		Agend_Test_WP::queue_response( 403, $this->unauthorised() );

		( new Agend_Apps_API() )->get_cached_many( $reads );

		$this->assertSame( 1, $resolutions['calls'] );
		$this->assertSame( 1, $rejected );
		$this->assertSame( array( 'Bearer member-token', null ), array_map( array( $this, 'authorisationOf' ), array( 0, 1 ) ) );
	}

	#[Test]
	public function should_return_the_rate_limit_refusal_without_sending_anything(): void {
		set_transient( 'agend_apps_rate_limit_remaining', 0, 60 );

		$results = ( new Agend_Apps_API() )->get_cached_many( $this->reads() );

		$this->assertSame( 'agend_apps_rate_limited', $results['search']->get_error_code() );
		$this->assertSame( 'agend_apps_rate_limited', $results['facets']->get_error_code() );
		$this->assertSame( 429, $results['facets']->get_error_data()['status_code'] );
		$this->assertCount( 0, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_return_the_scope_guard_refusal_without_sending_that_read(): void {
		Agend_Apps_Key_Scopes::store_from_response( array( 'data' => array( 'scopes' => array( 'directory.listings.browse' ), 'app_ids' => array() ) ) );
		Agend_Apps_Endpoint_Scopes::set_map(
			array(
				'GET /directory/search' => array( 'app' => 'directory', 'scopes' => array( array( 'directory.listings.browse' ) ) ),
				'GET /directory/facets' => array( 'app' => 'directory', 'scopes' => array( array( 'directory.facets.browse' ) ) ),
			)
		);

		$results = ( new Agend_Apps_API() )->get_cached_many( $this->reads() );

		$this->assertInstanceOf( WP_Error::class, $results['facets'] );
		$this->assertSame( 'agend_apps_scope_missing', $results['facets']->get_error_code() );
		$this->assertIsArray( $results['search'] );
		$this->assertCount( 1, Agend_Test_WP::$requests );
	}
}
