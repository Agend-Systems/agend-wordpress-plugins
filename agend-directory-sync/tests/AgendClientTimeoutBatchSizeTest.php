<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\DirectorySync;

use Agend_Directory_Sync;
use Agend_Directory_Sync_Agend_Client;
use Agend_Test_Directory_Bulk_Upsert;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use WP_Error;

require_once AGEND_TESTS_ROOT . '/agend-directory-sync/agend-directory-sync.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-agend-client.php';

/**
 * The batch-size and timeout settings a hosted "Send to Agend" run reads, and
 * effective_timeout()'s pure arithmetic for fitting a batch's own gateway
 * request inside whatever execution-time budget the current request has.
 */
#[CoversClass( Agend_Directory_Sync_Agend_Client::class )]
final class AgendClientTimeoutBatchSizeTest extends TestCase {

	/**
	 * The default setting (45) under an unlimited max_execution_time (WP-CLI's
	 * own case, and how a browser step reports a host with no cap): the fixed
	 * STEP_REQUEST_BUDGET_SECONDS (50) minus the 5-second margin is the only
	 * ceiling left, and 45 sits exactly at it.
	 */
	#[Test]
	public function effective_timeout_web_at_the_default_setting_and_unlimited_execution_time_is_forty_five(): void {
		$this->assertSame( 45, Agend_Directory_Sync_Agend_Client::effective_timeout( 45, 0, 'web' ) );
	}

	/**
	 * A generous max_execution_time (120) does not let a web step exceed the
	 * fixed step budget: even though 60 - 5 = 55 would fit inside
	 * max_execution_time's own margin, the 50-second STEP_REQUEST_BUDGET_SECONDS
	 * (minus its own 5-second margin, 45) is the tighter of the two ceilings.
	 */
	#[Test]
	public function effective_timeout_web_is_capped_by_the_step_budget_even_when_execution_time_is_generous(): void {
		$this->assertSame( 45, Agend_Directory_Sync_Agend_Client::effective_timeout( 60, 120, 'web' ) );
	}

	/**
	 * A tight max_execution_time (30) is the tighter of the two ceilings this
	 * time: 30 - 5 = 25 is less than the step budget's own 45.
	 */
	#[Test]
	public function effective_timeout_web_is_capped_by_execution_time_when_it_is_the_tighter_ceiling(): void {
		$this->assertSame( 25, Agend_Directory_Sync_Agend_Client::effective_timeout( 60, 30, 'web' ) );
	}

	#[Test]
	public function effective_timeout_web_never_drops_below_ten_seconds(): void {
		// max_execution_time 10, minus 5, is 5 -- below the 10-second floor.
		$this->assertSame( 10, Agend_Directory_Sync_Agend_Client::effective_timeout( 60, 10, 'web' ) );
	}

	/**
	 * CLI context applies the setting in full: no request to protect, so
	 * neither the fixed step budget nor max_execution_time caps it, up to the
	 * setting's own 300-second maximum.
	 */
	#[Test]
	public function effective_timeout_cli_uses_the_full_setting(): void {
		$this->assertSame( 300, Agend_Directory_Sync_Agend_Client::effective_timeout( 300, 0, 'cli' ) );
	}

	#[Test]
	public function batch_size_defaults_when_unset(): void {
		delete_option( Agend_Directory_Sync::OPTION_BATCH_SIZE );

		$this->assertSame( Agend_Directory_Sync_Agend_Client::DEFAULT_BATCH_SIZE, Agend_Directory_Sync_Agend_Client::batch_size() );
	}

	#[Test]
	public function batch_size_is_clamped_to_the_gateway_cap(): void {
		update_option( Agend_Directory_Sync::OPTION_BATCH_SIZE, 500 );

		$this->assertSame( Agend_Directory_Sync_Agend_Client::MAX_BATCH_SIZE, Agend_Directory_Sync_Agend_Client::batch_size() );
	}

	#[Test]
	public function batch_size_is_clamped_to_at_least_one(): void {
		update_option( Agend_Directory_Sync::OPTION_BATCH_SIZE, 0 );

		$this->assertSame( Agend_Directory_Sync_Agend_Client::MIN_BATCH_SIZE, Agend_Directory_Sync_Agend_Client::batch_size() );
	}

	#[Test]
	public function timeout_seconds_is_clamped_between_fifteen_and_three_hundred(): void {
		update_option( Agend_Directory_Sync::OPTION_TIMEOUT_SECONDS, 5 );
		$this->assertSame( Agend_Directory_Sync_Agend_Client::MIN_TIMEOUT_SECONDS, Agend_Directory_Sync_Agend_Client::timeout_seconds() );

		update_option( Agend_Directory_Sync::OPTION_TIMEOUT_SECONDS, 999 );
		$this->assertSame( Agend_Directory_Sync_Agend_Client::MAX_TIMEOUT_SECONDS, Agend_Directory_Sync_Agend_Client::timeout_seconds() );
	}

	/**
	 * send_batch()'s timeout injection reaches the request args exactly the
	 * way agend-apps-core's export-reports authoring listing scopes its own
	 * one-off arg: add_filter() before the call, remove_filter() in a
	 * finally. A second, untimed call must not see a leftover timeout.
	 */
	#[Test]
	public function it_injects_the_timeout_for_one_call_and_removes_it_afterwards(): void {
		$client = new Agend_Directory_Sync_Agend_Client();

		$client->send_listings(
			array( array( 'external_id' => 'ext-1' ) ),
			'test-source',
			false,
			10,
			45
		);

		$this->assertSame( 45, Agend_Test_Directory_Bulk_Upsert::$calls[0]['args']['timeout'] );

		$client->send_listings(
			array( array( 'external_id' => 'ext-2' ) ),
			'test-source',
			false,
			10,
			null
		);

		$this->assertArrayNotHasKey( 'timeout', Agend_Test_Directory_Bulk_Upsert::$calls[1]['args'] );
	}

	/**
	 * Every bulk-upsert call carries `unattended => true`, whether or not a
	 * timeout is also given: this is a server-to-server upload, never a
	 * member's own action, so it must never carry a member bearer.
	 */
	#[Test]
	public function it_marks_every_call_unattended(): void {
		$client = new Agend_Directory_Sync_Agend_Client();

		$client->send_listings( array( array( 'external_id' => 'ext-1' ) ), 'test-source' );

		$this->assertTrue( Agend_Test_Directory_Bulk_Upsert::$calls[0]['args']['unattended'] );
	}

	/**
	 * The `http_api_debug` capture reaches is_retryable_failure() through the
	 * whole send_listings() -> send_batch() path: an agend_apps_invalid_response
	 * with a captured 504 ends up flagged retryable on the stored http_errors
	 * entry, not just on a directly-called is_retryable_failure().
	 */
	#[Test]
	public function send_listings_captures_the_real_status_for_an_invalid_response_via_http_api_debug(): void {
		Agend_Test_Directory_Bulk_Upsert::$response             = new WP_Error(
			'agend_apps_invalid_response',
			'Invalid JSON response from Agend API.',
			array( 'body' => '<html>504</html>' )
		);
		Agend_Test_Directory_Bulk_Upsert::$http_api_debug_status = 504;

		$client  = new Agend_Directory_Sync_Agend_Client();
		$summary = $client->send_listings( array( array( 'external_id' => 'ext-1' ) ), 'test-source' );

		$this->assertTrue( $summary['http_errors'][0]['retryable'] );
	}

	#[Test]
	public function send_listings_does_not_retry_an_invalid_response_with_an_unretryable_captured_status(): void {
		Agend_Test_Directory_Bulk_Upsert::$response             = new WP_Error(
			'agend_apps_invalid_response',
			'Invalid JSON response from Agend API.',
			array( 'body' => '<html>403</html>' )
		);
		Agend_Test_Directory_Bulk_Upsert::$http_api_debug_status = 403;

		$client  = new Agend_Directory_Sync_Agend_Client();
		$summary = $client->send_listings( array( array( 'external_id' => 'ext-1' ) ), 'test-source' );

		$this->assertFalse( $summary['http_errors'][0]['retryable'] );
	}

	#[Test]
	public function send_listings_chunks_by_the_given_batch_size_rather_than_the_gateway_cap(): void {
		$client   = new Agend_Directory_Sync_Agend_Client();
		$listings = array_fill( 0, 5, array( 'external_id' => 'x' ) );

		$client->send_listings( $listings, 'test-source', false, 2 );

		$this->assertCount( 3, Agend_Test_Directory_Bulk_Upsert::$calls );
		$this->assertCount( 2, Agend_Test_Directory_Bulk_Upsert::$calls[0]['listings'] );
		$this->assertCount( 2, Agend_Test_Directory_Bulk_Upsert::$calls[1]['listings'] );
		$this->assertCount( 1, Agend_Test_Directory_Bulk_Upsert::$calls[2]['listings'] );
	}

	/**
	 * @return array<string, array{0: WP_Error, 1: int|null, 2: bool}>
	 */
	public static function retryable_failure_cases(): array {
		return array(
			'http_request_failed (WP core transport failure, e.g. cURL 7/28/52/56)' => array(
				new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ),
				null,
				true,
			),
			'agend_apps_invalid_response, captured status 504' => array(
				new WP_Error( 'agend_apps_invalid_response', 'Invalid JSON response from Agend API.', array( 'body' => '<html>504</html>' ) ),
				504,
				true,
			),
			'agend_apps_invalid_response, captured status 413' => array(
				new WP_Error( 'agend_apps_invalid_response', 'Invalid JSON response from Agend API.', array( 'body' => '<html>413</html>' ) ),
				413,
				false,
			),
			'agend_apps_invalid_response, captured status 403' => array(
				new WP_Error( 'agend_apps_invalid_response', 'Invalid JSON response from Agend API.', array( 'body' => '<html>403</html>' ) ),
				403,
				false,
			),
			'agend_apps_invalid_response, no captured status'  => array(
				new WP_Error( 'agend_apps_invalid_response', 'Invalid JSON response from Agend API.', array( 'body' => '<html>error</html>' ) ),
				null,
				false,
			),
			'502 Bad Gateway'                                  => array(
				new WP_Error( 'agend_api_error', 'Bad Gateway', array( 'status_code' => 502 ) ),
				null,
				true,
			),
			'503 Service Unavailable'                          => array(
				new WP_Error( 'agend_api_error', 'Service Unavailable', array( 'status_code' => 503 ) ),
				null,
				true,
			),
			'504 Gateway Timeout'                              => array(
				new WP_Error( 'agend_api_error', 'Gateway Timeout', array( 'status_code' => 504 ) ),
				null,
				true,
			),
			'500 Internal Server Error (a real answer, not retried)' => array(
				new WP_Error( 'agend_api_error', 'Internal Server Error', array( 'status_code' => 500 ) ),
				null,
				false,
			),
			'400 validation error'                             => array(
				new WP_Error( 'agend_api_error', 'Invalid request parameters', array( 'status_code' => 400 ) ),
				null,
				false,
			),
			'422 Unprocessable Entity'                         => array(
				new WP_Error( 'agend_api_error', 'Unprocessable Entity', array( 'status_code' => 422 ) ),
				null,
				false,
			),
		);
	}

	#[Test]
	public function is_retryable_failure_matrix(): void {
		foreach ( self::retryable_failure_cases() as $label => $case ) {
			[ $error, $captured_status, $expected ] = $case;
			$this->assertSame( $expected, Agend_Directory_Sync_Agend_Client::is_retryable_failure( $error, $captured_status ), $label );
		}
	}

	#[Test]
	public function stamp_issue_position_computes_a_run_wide_position_and_attaches_external_id(): void {
		$batch = array(
			array( 'external_id' => 'ext-0' ),
			array( 'external_id' => 'ext-1' ),
		);

		$issue = Agend_Directory_Sync_Agend_Client::stamp_issue_position(
			array( 'record' => 1, 'field' => 'name', 'reason' => 'Required' ),
			2,
			$batch,
			100
		);

		// batch_index (2) * batch_size (100) + record (1) + 1 = 202.
		$this->assertSame( 202, $issue['listing_position'] );
		$this->assertSame( 'ext-1', $issue['external_id'] );
	}

	#[Test]
	public function stamp_issue_position_leaves_a_recordless_issue_unstamped(): void {
		$issue = Agend_Directory_Sync_Agend_Client::stamp_issue_position(
			array( 'record' => null, 'field' => 'external_source', 'reason' => 'Required' ),
			0,
			array(),
			25
		);

		$this->assertNull( $issue['listing_position'] );
		$this->assertArrayNotHasKey( 'external_id', $issue );
	}

	#[Test]
	public function format_issue_line_covers_all_three_shapes(): void {
		$this->assertSame(
			'Listing #202 (external id ext-1), field name: Required',
			Agend_Directory_Sync_Agend_Client::format_issue_line(
				array( 'listing_position' => 202, 'external_id' => 'ext-1', 'field' => 'name', 'reason' => 'Required' )
			)
		);

		$this->assertSame(
			'Listing #5, field name: Required',
			Agend_Directory_Sync_Agend_Client::format_issue_line(
				array( 'listing_position' => 5, 'external_id' => '', 'field' => 'name', 'reason' => 'Required' )
			)
		);

		$this->assertSame(
			'Field external_source: Required',
			Agend_Directory_Sync_Agend_Client::format_issue_line(
				array( 'listing_position' => null, 'field' => 'external_source', 'reason' => 'Required' )
			)
		);
	}

	/**
	 * The batch-level message no longer names a record index (that detail now
	 * lives only in the per-issue lines, once a run-wide position is
	 * available): it names the issue count and the affected field names
	 * instead.
	 */
	#[Test]
	public function send_listings_summarizes_a_validation_failure_without_an_in_batch_record_index(): void {
		Agend_Test_Directory_Bulk_Upsert::$response = new WP_Error(
			'agend_api_error',
			'Invalid request parameters',
			array(
				'status_code' => 400,
				'body'        => array(
					'error' => array(
						'code'    => 'VALIDATION_ERROR',
						'details' => array(
							'issues' => array(
								array( 'path' => array( 'listings', 0, 'name' ), 'message' => 'Required' ),
								array( 'path' => array( 'listings', 1, 'name' ), 'message' => 'Required' ),
								array( 'path' => array( 'external_source' ), 'message' => 'Required' ),
							),
						),
					),
				),
			)
		);

		$client  = new Agend_Directory_Sync_Agend_Client();
		$summary = $client->send_listings(
			array(
				array( 'external_id' => 'ext-0' ),
				array( 'external_id' => 'ext-1' ),
			),
			'test-source'
		);

		$message = $summary['http_errors'][0]['message'];

		$this->assertStringContainsString( 'Invalid request parameters', $message );
		$this->assertStringContainsString( '3 issues', $message );
		$this->assertStringContainsString( 'name', $message );
		$this->assertStringContainsString( 'external_source', $message );
		$this->assertStringNotContainsString( 'record', $message );
		$this->assertStringNotContainsString( '0-based', $message );
	}

	/**
	 * The privacy scrub redacts an email address anywhere in a message, not
	 * only inside a "received" tail.
	 */
	#[Test]
	public function it_redacts_an_email_address_in_the_batch_message(): void {
		Agend_Test_Directory_Bulk_Upsert::$response = new WP_Error(
			'agend_api_error',
			'Duplicate contact for jane.smith@example.com',
			array( 'status_code' => 400 )
		);

		$client  = new Agend_Directory_Sync_Agend_Client();
		$summary = $client->send_listings( array( array( 'external_id' => 'ext-1' ) ), 'test-source' );

		$message = $summary['http_errors'][0]['message'];

		$this->assertStringContainsString( '[email]', $message );
		$this->assertStringNotContainsString( 'jane.smith@example.com', $message );
	}

	/**
	 * The privacy scrub blanks a quoted value in a plain (non-validation)
	 * batch message the same way it does in a per-issue reason.
	 */
	#[Test]
	public function it_blanks_a_quoted_value_in_the_batch_message(): void {
		Agend_Test_Directory_Bulk_Upsert::$response = new WP_Error(
			'agend_api_error',
			'Rejected listing "Jane Smith Consulting LLC"',
			array( 'status_code' => 400 )
		);

		$client  = new Agend_Directory_Sync_Agend_Client();
		$summary = $client->send_listings( array( array( 'external_id' => 'ext-1' ) ), 'test-source' );

		$message = $summary['http_errors'][0]['message'];

		$this->assertStringNotContainsString( 'Jane Smith Consulting LLC', $message );
		$this->assertStringContainsString( "'\u{2026}'", $message );
	}

	/**
	 * "Every error type" includes a non-validation, transport-level failure:
	 * the scrub is not conditional on the error carrying validation details.
	 */
	#[Test]
	public function it_sanitizes_a_non_validation_transport_failure_message(): void {
		Agend_Test_Directory_Bulk_Upsert::$response = new WP_Error(
			'http_request_failed',
			'Could not resolve host for operator@example.com relay'
		);

		$client  = new Agend_Directory_Sync_Agend_Client();
		$summary = $client->send_listings( array( array( 'external_id' => 'ext-1' ) ), 'test-source' );

		$message = $summary['http_errors'][0]['message'];

		$this->assertStringContainsString( '[email]', $message );
		$this->assertStringNotContainsString( 'operator@example.com', $message );
	}

	#[Test]
	public function it_caps_a_message_at_three_hundred_characters(): void {
		Agend_Test_Directory_Bulk_Upsert::$response = new WP_Error(
			'agend_api_error',
			str_repeat( 'x', 500 ),
			array( 'status_code' => 400 )
		);

		$client  = new Agend_Directory_Sync_Agend_Client();
		$summary = $client->send_listings( array( array( 'external_id' => 'ext-1' ) ), 'test-source' );

		$this->assertLessThanOrEqual( 300, strlen( $summary['http_errors'][0]['message'] ) );
	}
}
