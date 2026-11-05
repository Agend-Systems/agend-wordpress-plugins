<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\AppsCore;

use Agend_Apps_Logger;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/logging/class-agend-apps-log-redactor.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/logging/class-agend-apps-log-store.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/logging/class-agend-apps-logger.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-api.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/upbeat-record-refresh.php';
require_once __DIR__ . '/fixtures/memory-log-store.php';

/**
 * `agend_apps_request_upbeat_record_refresh()`: one fire-and-forget call to
 * the gateway that never throws and logs every failure.
 */
final class UpbeatRecordRefreshTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Agend_Apps_Logger::set_instance( new Agend_Apps_Logger( new MemoryLogStore() ) );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function refresh_requests(): array {
		return array_values(
			array_filter(
				Agend_Test_WP::$requests,
				static fn( array $r ): bool => false !== strpos( $r['url'], '/workflows/upbeat/record-refreshes' )
			)
		);
	}

	#[Test]
	public function should_post_the_resource_path_and_lookup_value_to_the_gateway_when_called(): void {
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'refreshes' => array() ) ) );

		agend_apps_request_upbeat_record_refresh( 'members', 'M12345' );

		$requests = $this->refresh_requests();
		$this->assertCount( 1, $requests );
		$this->assertSame( 'POST', $requests[0]['method'] );
		$this->assertStringEndsWith( '/v1/workflows/upbeat/record-refreshes', $requests[0]['url'] );
		$this->assertSame(
			array(
				'resource_path' => 'members',
				'lookup_value'  => 'M12345',
			),
			json_decode( (string) $requests[0]['body'], true )
		);
	}

	#[Test]
	public function should_cap_the_wait_at_three_seconds_when_called(): void {
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'refreshes' => array() ) ) );

		agend_apps_request_upbeat_record_refresh( 'members', 'M12345' );

		$this->assertSame( 3, AGEND_APPS_RECORD_REFRESH_TIMEOUT_SECONDS );
		$this->assertSame( 3, $this->refresh_requests()[0]['timeout'] );
	}

	#[Test]
	public function should_log_and_swallow_the_failure_when_the_request_times_out(): void {
		Agend_Test_WP::queue_transport_error( 'http_request_failed', 'cURL error 28: Operation timed out' );

		agend_apps_request_upbeat_record_refresh( 'members', 'M12345' );

		$rows = agend_apps_logger()->pending();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'transport_error', $rows[0]['outcome'] );
		$this->assertSame( '/v1/workflows/upbeat/record-refreshes', $rows[0]['path'] );
	}

	#[Test]
	public function should_log_and_swallow_the_failure_when_the_gateway_answers_non_2xx(): void {
		Agend_Test_WP::queue_response( 403, array( 'error' => array( 'code' => 'forbidden', 'message' => 'Missing scope' ) ) );

		agend_apps_request_upbeat_record_refresh( 'members', 'M12345' );

		$rows = agend_apps_logger()->pending();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'http_error', $rows[0]['outcome'] );
		$this->assertSame( 403, $rows[0]['status'] );
	}

	#[Test]
	public function should_swallow_the_exception_when_a_request_filter_throws(): void {
		add_filter(
			'agend_apps_upbeat_record_refresh_args',
			static function (): array {
				throw new \RuntimeException( 'boom' );
			}
		);

		agend_apps_request_upbeat_record_refresh( 'members', 'M12345' );

		$this->assertCount( 0, $this->refresh_requests() );
	}

	#[Test]
	public function should_send_nothing_when_an_argument_is_empty(): void {
		agend_apps_request_upbeat_record_refresh( '', 'M12345' );
		agend_apps_request_upbeat_record_refresh( 'members', '' );
		agend_apps_request_upbeat_record_refresh( ' ', '  ' );

		$this->assertCount( 0, Agend_Test_WP::$requests );
	}
}
