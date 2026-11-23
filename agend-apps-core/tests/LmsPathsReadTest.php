<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\AppsCore;

use Agend_Apps_Endpoint_Scopes;
use Agend_Apps_Logger;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;
use WP_Error;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/logging/class-agend-apps-log-redactor.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/logging/class-agend-apps-log-store.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/logging/class-agend-apps-logger.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/health.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-http-batch.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-key-scopes.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-endpoint-scopes.php';
require_once __DIR__ . '/fixtures/memory-log-store.php';

/**
 * The learning path reads the public display makes: a single path, several at
 * once, and the list, each as an anonymous visitor would read it.
 *
 * Paths are the same for every viewer, so a display reads them without the
 * member's token, which keeps them in the shared cache. A read that carries a
 * token is held for one request only (see CachedRequestIdentityTest), which on a
 * site whose visitors are mostly signed in would be one live gateway call per
 * path per page view.
 *
 * Each case runs in its own process: the real LMS wrappers are declared here,
 * and other tests declare doubles for some of them.
 */
#[RunTestsInSeparateProcesses]
final class LmsPathsReadTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/lms.php';
		Agend_Apps_Logger::set_instance( new Agend_Apps_Logger( new MemoryLogStore() ) );
		Agend_Apps_Endpoint_Scopes::set_map( null );
		// Sent one after another through wp_remote_request(), which answers from
		// the queue; the parallel adapter has its own suite.
		Agend_Test_WP::set_filter( 'agend_apps_api_parallel', false );
	}

	protected function tearDown(): void {
		Agend_Apps_Endpoint_Scopes::set_map( null );
		parent::tearDown();
	}

	private function asMember(): void {
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'member-token' );
	}

	private function authorisationOf( int $request_index ): ?string {
		return Agend_Test_WP::$requests[ $request_index ]['headers']['Authorization'] ?? null;
	}

	/** @return string[] The shared transients the paths reads wrote. */
	private function storedPathKeys(): array {
		return array_values( preg_grep( '/^agend_apps_lms_path/', array_keys( Agend_Test_WP::$transients ) ) );
	}

	// -----------------------------------------------------------------
	// Several paths at once
	// -----------------------------------------------------------------

	#[Test]
	public function should_read_each_path_from_its_own_address_and_return_them_keyed_in_the_order_given(): void {
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'id' => 'b' ) ) );
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'id' => 'a' ) ) );

		$paths = agend_apps_lms_get_paths_by_id( array( 'b', 'a' ), true );

		self::assertSame( array( 'b', 'a' ), array_keys( $paths ) );
		self::assertSame( 'b', $paths['b']['data']['id'] );
		self::assertSame( 'a', $paths['a']['data']['id'] );
		self::assertCount( 2, Agend_Test_WP::$requests );
		self::assertStringContainsString( '/lms/paths/b', Agend_Test_WP::$requests[0]['url'] );
		self::assertStringContainsString( '/lms/paths/a', Agend_Test_WP::$requests[1]['url'] );
	}

	#[Test]
	public function should_drop_blank_and_repeated_ids(): void {
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'id' => 'a' ) ) );

		$paths = agend_apps_lms_get_paths_by_id( array( 'a', '', 'a' ), true );

		self::assertSame( array( 'a' ), array_keys( $paths ) );
		self::assertCount( 1, Agend_Test_WP::$requests );
		self::assertSame( array(), agend_apps_lms_get_paths_by_id( array(), true ) );
		self::assertSame( array(), agend_apps_lms_get_paths_by_id( array( '' ), true ) );
	}

	#[Test]
	public function should_return_an_error_for_the_path_that_failed_and_the_path_for_the_one_that_did_not(): void {
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'id' => 'a' ) ) );
		Agend_Test_WP::queue_response( 404, array( 'error' => array( 'message' => 'Learning path not found' ) ) );

		$paths = agend_apps_lms_get_paths_by_id( array( 'a', 'gone' ), true );

		self::assertSame( 'a', $paths['a']['data']['id'] );
		self::assertInstanceOf( WP_Error::class, $paths['gone'] );
	}

	#[Test]
	public function should_send_only_the_paths_not_already_held(): void {
		Agend_Test_WP::$transients['agend_apps_lms_path_single_' . md5( (string) wp_json_encode( array( 'id_or_slug' => 'a' ) ) )] = array( 'data' => array( 'id' => 'a', 'stored' => true ) );
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'id' => 'b' ) ) );

		$paths = agend_apps_lms_get_paths_by_id( array( 'a', 'b' ), true );

		self::assertTrue( $paths['a']['data']['stored'] );
		self::assertCount( 1, Agend_Test_WP::$requests );
		self::assertStringContainsString( '/lms/paths/b', Agend_Test_WP::$requests[0]['url'] );
	}

	#[Test]
	public function should_answer_a_path_exactly_as_the_single_read_does(): void {
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'id' => 'a' ) ) );
		$batched          = agend_apps_lms_get_paths_by_id( array( 'a' ), true );
		$batched_requests = Agend_Test_WP::$requests;

		Agend_Test_WP::$requests   = array();
		Agend_Test_WP::$transients = array();
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'id' => 'a' ) ) );
		$single = agend_apps_lms_get_path( 'a', true );

		self::assertSame( $single, $batched['a'] );
		self::assertSame( $batched_requests, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_run_the_response_filter_for_each_path(): void {
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'id' => 'a' ) ) );
		add_filter(
			'agend_apps_lms_get_path_response',
			static function ( array $response, string $id ): array {
				$response['filtered_for'] = $id;
				return $response;
			},
			10,
			2
		);

		self::assertSame( 'a', agend_apps_lms_get_paths_by_id( array( 'a' ), true )['a']['filtered_for'] );
	}

	// -----------------------------------------------------------------
	// Anonymous reads share the cache; member reads do not
	// -----------------------------------------------------------------

	#[Test]
	public function should_send_no_member_token_and_share_the_stored_answer_for_an_anonymous_read(): void {
		$this->asMember();
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'id' => 'a' ) ) );

		agend_apps_lms_get_paths_by_id( array( 'a' ), true );
		agend_apps_lms_get_paths_by_id( array( 'a' ), true );

		self::assertCount( 1, Agend_Test_WP::$requests, 'the second read is served from the shared entry' );
		self::assertNull( $this->authorisationOf( 0 ), 'a signed-in visitor is read as an anonymous one' );
		self::assertCount( 1, $this->storedPathKeys() );
	}

	#[Test]
	public function should_send_the_member_token_and_store_nothing_shared_for_a_plain_read(): void {
		$this->asMember();
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'id' => 'a' ) ) );

		agend_apps_lms_get_path( 'a' );

		self::assertSame( 'Bearer member-token', $this->authorisationOf( 0 ) );
		self::assertSame( array(), $this->storedPathKeys() );
	}

	#[Test]
	public function should_keep_an_anonymous_read_when_a_filter_rebuilds_the_request_args(): void {
		$this->asMember();
		add_filter(
			'agend_apps_lms_get_path_args',
			static function (): array {
				return array();
			}
		);
		add_filter(
			'agend_apps_lms_get_paths_args',
			static function ( array $args, array $query ): array {
				return array( 'query' => $query );
			},
			10,
			2
		);
		Agend_Test_WP::queue_response( 200, array( 'data' => array() ) );
		Agend_Test_WP::queue_response( 200, array( 'data' => array() ) );

		agend_apps_lms_get_path( 'a', true );
		agend_apps_lms_get_paths( array( 'limit' => 3 ), true );

		self::assertNull( $this->authorisationOf( 0 ) );
		self::assertNull( $this->authorisationOf( 1 ) );
	}

	// -----------------------------------------------------------------
	// The list
	// -----------------------------------------------------------------

	#[Test]
	public function should_read_the_list_anonymously_with_the_query_given(): void {
		$this->asMember();
		Agend_Test_WP::queue_response( 200, array( 'data' => array(), 'meta' => array( 'pagination' => array( 'total' => 0 ) ) ) );

		agend_apps_lms_get_paths( array( 'limit' => 4 ), true );
		agend_apps_lms_get_paths( array( 'limit' => 4 ), true );

		self::assertCount( 1, Agend_Test_WP::$requests );
		self::assertStringContainsString( '/lms/paths', Agend_Test_WP::$requests[0]['url'] );
		self::assertStringContainsString( 'limit=4', Agend_Test_WP::$requests[0]['url'] );
		self::assertNull( $this->authorisationOf( 0 ) );
		self::assertNotEmpty( preg_grep( '/^agend_apps_lms_paths/', array_keys( Agend_Test_WP::$transients ) ) );
	}

	#[Test]
	public function should_still_read_the_list_as_the_member_when_not_asked_to_be_anonymous(): void {
		$this->asMember();
		Agend_Test_WP::queue_response( 200, array( 'data' => array() ) );

		agend_apps_lms_get_paths( array( 'limit' => 4 ) );

		self::assertSame( 'Bearer member-token', $this->authorisationOf( 0 ) );
	}
}
