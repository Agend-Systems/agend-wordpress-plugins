<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\AppsCore;

use Agend_Apps_API;
use Agend_Apps_Logger;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/logging/class-agend-apps-log-redactor.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/logging/class-agend-apps-log-store.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/logging/class-agend-apps-logger.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-api.php';
require_once __DIR__ . '/fixtures/memory-log-store.php';

/**
 * Every exit from Agend_Apps_API::request() records exactly one redacted row,
 * and nothing that authenticates the site or the member ever reaches it.
 */
#[CoversClass( Agend_Apps_API::class )]
#[CoversClass( Agend_Apps_Logger::class )]
final class ApiLoggingTest extends TestCase {

	private MemoryLogStore $store;

	protected function setUp(): void {
		parent::setUp();
		$this->store = new MemoryLogStore();
		Agend_Apps_Logger::set_instance( new Agend_Apps_Logger( $this->store ) );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function rows(): array {
		return agend_apps_logger()->pending();
	}

	#[Test]
	public function should_record_one_row_with_timing_and_gateway_id_when_a_call_succeeds(): void {
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'id' => 'abc' ) ), array( 'X-Request-Id' => 'req-123' ) );

		( new Agend_Apps_API() )->request( 'GET', '/events', array( 'query' => array( 'page' => 2 ) ) );

		$this->assertCount( 1, $this->rows() );
		$row = $this->rows()[0];
		$this->assertSame( 'outbound', $row['direction'] );
		$this->assertSame( 'GET', $row['method'] );
		$this->assertSame( 'api.example.test', $row['host'] );
		$this->assertSame( '/v1/events', $row['path'] );
		$this->assertSame( 'page=2', $row['query'] );
		$this->assertSame( 200, $row['status'] );
		$this->assertSame( 'success', $row['outcome'] );
		$this->assertSame( 'req-123', $row['gateway_request_id'] );
		$this->assertSame( 'api_key', $row['auth_mode'] );
		$this->assertSame( agend_apps_logger()->request_id(), $row['request_id'] );
		$this->assertSame( '{"data":{"id":"abc"}}', $row['response_body'] );
	}

	#[Test]
	public function should_store_the_full_redacted_body_when_a_call_fails(): void {
		$big = str_repeat( 'x', 9000 );
		Agend_Test_WP::queue_response( 422, array( 'error' => array( 'code' => 'validation_failed', 'message' => 'Email jane@example.com is taken' ), 'detail' => $big ) );

		$result = ( new Agend_Apps_API() )->request( 'POST', '/crm/contacts', array( 'body' => array( 'email' => 'jane@example.com', 'tier_id' => 't1' ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$row = $this->rows()[0];
		$this->assertSame( 'http_error', $row['outcome'] );
		$this->assertSame( 'validation_failed', $row['error_code'] );
		$this->assertStringStartsWith( 'Email [redacted:email#', $row['error_message'] );
		$this->assertSame( 0, $row['truncated'] );
		$this->assertStringContainsString( $big, $row['response_body'], 'a failed call keeps its whole body' );
		$this->assertStringNotContainsString( 'jane@example.com', $row['request_body'] . $row['response_body'] . $row['error_message'] );
	}

	#[Test]
	public function should_cap_bodies_at_five_thousand_characters_when_a_call_succeeds(): void {
		Agend_Test_WP::queue_response( 200, array( 'data' => str_repeat( 'y', 9000 ) ) );

		( new Agend_Apps_API() )->request( 'GET', '/cms/content' );

		$row = $this->rows()[0];
		$this->assertSame( Agend_Apps_Logger::SUCCESS_BODY_LIMIT, strlen( $row['response_body'] ) );
		$this->assertSame( 1, $row['truncated'] );
		$this->assertSame( 9011, $row['response_bytes'], 'the original size is still recorded' );
	}

	#[Test]
	public function should_record_a_transport_error_with_no_status_when_the_request_never_completes(): void {
		Agend_Test_WP::queue_transport_error( 'http_request_failed', 'cURL error 28: Operation timed out' );

		( new Agend_Apps_API() )->request( 'GET', '/crm/me' );

		$row = $this->rows()[0];
		$this->assertNull( $row['status'] );
		$this->assertSame( 'transport_error', $row['outcome'] );
		$this->assertSame( 'http_request_failed', $row['error_code'] );
	}

	#[Test]
	public function should_record_invalid_json_as_an_invalid_response(): void {
		Agend_Test_WP::queue_response( 502, '<html>Bad gateway for jane@example.com</html>' );

		( new Agend_Apps_API() )->request( 'GET', '/events' );

		$row = $this->rows()[0];
		$this->assertSame( 'invalid_response', $row['outcome'] );
		$this->assertSame( 502, $row['status'] );
		$this->assertMatchesRegularExpression( '/^\[non-JSON body: \d+ bytes/', $row['response_body'] );
	}

	#[Test]
	public function should_record_a_rate_limited_short_circuit_without_sending_anything(): void {
		set_transient( 'agend_apps_rate_limit_remaining', 0, 60 );

		( new Agend_Apps_API() )->request( 'GET', '/events' );

		$this->assertCount( 0, Agend_Test_WP::$requests );
		$row = $this->rows()[0];
		$this->assertSame( 'rate_limited', $row['outcome'] );
		$this->assertNull( $row['status'] );
	}

	#[Test]
	public function should_link_the_rejected_attempt_and_its_retry_when_the_member_bearer_is_refused(): void {
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'member-token' );
		Agend_Test_WP::queue_response( 401, array( 'error' => array( 'message' => 'unauthorized' ) ) );
		Agend_Test_WP::queue_response( 200, array( 'data' => array() ) );

		( new Agend_Apps_API() )->request( 'GET', '/events' );

		$rows = $this->rows();
		$this->assertCount( 2, $rows );
		$this->assertSame( array( 'retried', 1, 'bearer' ), array( $rows[0]['outcome'], $rows[0]['attempt'], $rows[0]['auth_mode'] ) );
		$this->assertSame( array( 'success', 2, 'unattended' ), array( $rows[1]['outcome'], $rows[1]['attempt'], $rows[1]['auth_mode'] ) );
		$this->assertSame( $rows[0]['request_id'], $rows[1]['request_id'] );
	}

	#[Test]
	public function should_never_store_the_api_key_or_member_bearer_anywhere_in_a_row(): void {
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'member-secret-token' );
		Agend_Test_WP::queue_response( 500, array( 'error' => array( 'message' => 'boom' ) ) );

		( new Agend_Apps_API() )->request( 'POST', '/cart/items', array( 'body' => array( 'product_id' => 'p1' ), 'cart_session' => 'cart-session-value' ) );

		$serialised = (string) json_encode( $this->rows() );
		$this->assertStringNotContainsString( 'test-api-key', $serialised );
		$this->assertStringNotContainsString( 'member-secret-token', $serialised );
		$this->assertStringNotContainsString( 'cart-session-value', $serialised );
	}

	#[Test]
	public function should_store_no_bodies_when_the_endpoint_carries_credentials(): void {
		Agend_Test_WP::queue_response( 200, array( 'access_token' => 'a', 'refresh_token' => 'r' ) );
		Agend_Test_WP::queue_response( 200, array( 'access_token' => 'impersonation' ) );

		$api = new Agend_Apps_API();
		$api->request( 'POST', '/auth/login', array( 'body' => array( 'email' => 'jane@example.com', 'password' => 'hunter2' ) ) );
		$api->request( 'POST', '/sso/tokens', array( 'body' => array( 'external_id' => 'ext-1' ) ) );

		foreach ( $this->rows() as $row ) {
			$this->assertSame( '[not stored for this endpoint]', $row['request_body'] );
			$this->assertSame( '[not stored for this endpoint]', $row['response_body'] );
		}
	}

	#[Test]
	public function should_record_nothing_when_logging_is_switched_off(): void {
		update_option( Agend_Apps_Logger::OPTION_ENABLED, '0' );
		Agend_Test_WP::queue_response( 200, array( 'data' => array() ) );

		( new Agend_Apps_API() )->request( 'GET', '/events' );

		$this->assertCount( 0, $this->rows() );
	}

	#[Test]
	public function should_drop_a_row_when_the_entry_filter_returns_false(): void {
		Agend_Test_WP::set_filter( 'agend_apps_log_entry', false );
		Agend_Test_WP::queue_response( 200, array( 'data' => array() ) );

		( new Agend_Apps_API() )->request( 'GET', '/events' );

		$this->assertCount( 0, $this->rows() );
	}

	#[Test]
	public function should_write_the_buffer_in_one_batch_on_shutdown(): void {
		Agend_Test_WP::queue_response( 200, array( 'data' => array() ) );
		Agend_Test_WP::queue_response( 200, array( 'data' => array() ) );

		$api = new Agend_Apps_API();
		$api->request( 'GET', '/events' );
		$api->request( 'GET', '/lms/courses' );

		$this->assertCount( 0, $this->store->rows, 'nothing is written mid-request' );

		do_action( 'shutdown' );

		$this->assertCount( 2, $this->store->rows );
		$this->assertCount( 0, $this->rows() );
	}

	#[Test]
	public function should_hash_the_user_and_truncate_the_ip_when_identifying_the_requester(): void {
		$GLOBALS['agend_test_current_user_id'] = 7;
		$_SERVER['REMOTE_ADDR']                = '203.0.113.77';
		$_SERVER['REQUEST_URI']                = '/my-account/?email=jane@example.com';

		try {
			Agend_Test_WP::queue_response( 200, array( 'data' => array() ) );
			( new Agend_Apps_API() )->request( 'GET', '/crm/me' );
		} finally {
			unset( $_SERVER['REMOTE_ADDR'], $_SERVER['REQUEST_URI'] );
		}

		$row = $this->rows()[0];
		$this->assertSame( 7, $row['user_id'] );
		$this->assertSame( '203.0.113.0/24', $row['ip_prefix'] );
		$this->assertSame( '/my-account/', $row['page_path'], 'the page query string is dropped' );
	}

	#[Test]
	public function should_scrub_the_signed_in_users_own_name_and_email_when_an_error_echoes_them(): void {
		$user               = new \WP_User( 12 );
		$user->user_email   = 'zelphine@example.com';
		$user->user_login   = 'zelphineq';
		$user->first_name   = 'Zelphine';
		$user->last_name    = 'Quorrimax';
		$GLOBALS['agend_test_users'][]         = $user;
		$GLOBALS['agend_test_current_user_id'] = 12;

		Agend_Test_WP::queue_response( 409, array( 'error' => array( 'message' => 'Zelphine Quorrimax already holds a seat' ) ) );

		( new Agend_Apps_API() )->request( 'POST', '/crm/seats' );

		$row = $this->rows()[0];
		$this->assertStringNotContainsString( 'zelphine', strtolower( (string) json_encode( $row ) ) );
		$this->assertStringNotContainsString( 'quorrimax', strtolower( (string) json_encode( $row ) ) );
		$this->assertSame( Agend_Apps_Logger::user_hash( 'zelphine@example.com' ), $row['user_hash'] );
		$this->assertStringEndsWith( 'already holds a seat', $row['error_message'] );
	}

	#[Test]
	public function should_record_a_sibling_plugin_call_through_the_public_function(): void {
		agend_apps_log_http(
			array(
				'source'          => 'agend-directory-sync',
				'method'          => 'post',
				'url'             => 'https://org.crm6.dynamics.com/api/data/v9.2/contacts?$filter=emailaddress1%20eq%20%27jane%40example.com%27',
				'status'          => null,
				'error'           => 'Timed out for jane@example.com',
				'request_headers' => array( 'Authorization' => 'Bearer secret' ),
			)
		);

		$row = $this->rows()[0];
		$this->assertSame( 'agend-directory-sync', $row['source'] );
		$this->assertSame( 'POST', $row['method'] );
		$this->assertSame( 'transport_error', $row['outcome'] );
		$serialised = (string) json_encode( $row );
		$this->assertStringNotContainsString( 'jane', $serialised );
		$this->assertStringNotContainsString( 'secret', $serialised );
	}
}
