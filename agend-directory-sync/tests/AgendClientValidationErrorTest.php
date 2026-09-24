<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\DirectorySync;

use Agend_Directory_Sync_Agend_Client;
use Agend_Test_Directory_Bulk_Upsert;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use WP_Error;

require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-agend-client.php';

/**
 * The validation-error mapper that turns a gateway 400 into readable, PII-free
 * issues, and send_batch()'s use of it to enrich a failed batch's http_errors
 * entry.
 */
#[CoversClass( Agend_Directory_Sync_Agend_Client::class )]
final class AgendClientValidationErrorTest extends TestCase {

	private function gateway_error( array $details, int $status_code = 400, string $code = 'VALIDATION_ERROR' ): WP_Error {
		return new WP_Error(
			'agend_api_error',
			'Invalid request parameters',
			array(
				'status_code' => $status_code,
				'path'        => '/directory/listings/bulk-upsert',
				'body'        => array(
					'success' => false,
					'error'   => array(
						'code'    => $code,
						'message' => 'Invalid request parameters',
						'details' => $details,
					),
				),
			)
		);
	}

	#[Test]
	public function it_maps_the_fields_shape_with_no_record_index(): void {
		$error = $this->gateway_error(
			array(
				'fields' => array(
					'external_source' => array( 'Required' ),
					'listings'        => array( 'Expected array, received null' ),
				),
			)
		);

		$described = Agend_Directory_Sync_Agend_Client::describe_validation_error( $error, 5 );

		$this->assertSame( 400, $described['status_code'] );
		$this->assertSame( 'VALIDATION_ERROR', $described['code'] );
		$this->assertCount( 2, $described['issues'] );

		$this->assertNull( $described['issues'][0]['record'] );
		$this->assertSame( 'external_source', $described['issues'][0]['field'] );
		$this->assertSame( 'Required', $described['issues'][0]['reason'] );

		$this->assertNull( $described['issues'][1]['record'] );
		$this->assertSame( 'listings', $described['issues'][1]['field'] );
		$this->assertSame( 'Expected array', $described['issues'][1]['reason'] );
	}

	#[Test]
	public function it_maps_the_issues_shape_with_a_record_index_and_nested_field(): void {
		$error = $this->gateway_error(
			array(
				'issues' => array(
					array(
						'path'    => array( 'listings', 12, 'custom_fields', 'state' ),
						'message' => 'Invalid enum value',
					),
				),
			)
		);

		$described = Agend_Directory_Sync_Agend_Client::describe_validation_error( $error, 100 );

		$this->assertCount( 1, $described['issues'] );
		$this->assertSame( 12, $described['issues'][0]['record'] );
		$this->assertSame( 'custom_fields.state', $described['issues'][0]['field'] );
		$this->assertSame( 'Invalid enum value', $described['issues'][0]['reason'] );
	}

	/**
	 * A path index the gateway should never send outside the batch it
	 * described (untrusted response data) is not reported as a record: the
	 * whole path becomes the field instead, with no record index.
	 */
	#[Test]
	public function it_treats_an_out_of_range_record_index_as_no_record(): void {
		$error = $this->gateway_error(
			array(
				'issues' => array(
					array(
						'path'    => array( 'listings', 50, 'name' ),
						'message' => 'Required',
					),
				),
			)
		);

		$described = Agend_Directory_Sync_Agend_Client::describe_validation_error( $error, 5 );

		$this->assertNull( $described['issues'][0]['record'] );
		$this->assertSame( 'listings.50.name', $described['issues'][0]['field'] );
	}

	/**
	 * A non-validation WP_Error -- a transport timeout, with no decoded body
	 * at all -- degrades to an empty issue list rather than throwing or
	 * fabricating a status code.
	 */
	#[Test]
	public function it_returns_no_issues_for_a_non_validation_error(): void {
		$error = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );

		$described = Agend_Directory_Sync_Agend_Client::describe_validation_error( $error, 10 );

		$this->assertNull( $described['status_code'] );
		$this->assertNull( $described['code'] );
		$this->assertSame( array(), $described['issues'] );
		$this->assertSame( 0, $described['issues_omitted'] );
	}

	/**
	 * A zod message that echoes the submitted value must not reach the
	 * operator: everything from "received" onward is stripped, including the
	 * quoted literal that follows it.
	 */
	#[Test]
	public function it_strips_the_received_clause_from_a_message(): void {
		$error = $this->gateway_error(
			array(
				'issues' => array(
					array(
						'path'    => array( 'listings', 0, 'status' ),
						'message' => "Invalid enum value. Expected 'approved' | 'pending', received 'Jane Smith'",
					),
				),
			)
		);

		$described = Agend_Directory_Sync_Agend_Client::describe_validation_error( $error, 1 );

		// The "received" tail is stripped entirely, and every quoted literal
		// left in the message -- including the allowed enum options, which
		// are not the submitted value -- is blanked too: there is no reliable
		// way to tell a submitted value apart from a literal that is simply
		// part of the message, so every quoted literal is treated as unsafe.
		$this->assertSame( "Invalid enum value. Expected '\u{2026}' | '\u{2026}'", $described['issues'][0]['reason'] );
		$this->assertStringNotContainsString( 'Jane Smith', $described['issues'][0]['reason'] );
	}

	#[Test]
	public function it_caps_issues_per_batch_and_reports_how_many_were_omitted(): void {
		$issues = array();
		for ( $i = 0; $i < 25; $i++ ) {
			$issues[] = array(
				'path'    => array( 'listings', $i, 'name' ),
				'message' => 'Required',
			);
		}

		$error = $this->gateway_error( array( 'issues' => $issues ) );

		$described = Agend_Directory_Sync_Agend_Client::describe_validation_error( $error, 25 );

		$this->assertCount( 20, $described['issues'] );
		$this->assertSame( 5, $described['issues_omitted'] );
	}

	#[Test]
	public function send_listings_enriches_a_failed_batch_with_status_code_code_and_issues(): void {
		Agend_Test_Directory_Bulk_Upsert::$response = $this->gateway_error(
			array(
				'issues' => array(
					array(
						'path'    => array( 'listings', 0, 'custom_fields', 'state' ),
						'message' => 'Expected string',
					),
				),
			)
		);

		$client  = new Agend_Directory_Sync_Agend_Client();
		$summary = $client->send_listings(
			array( array( 'external_id' => 'ext-1', 'name' => 'Test Listing' ) ),
			'test-source'
		);

		$this->assertCount( 1, $summary['http_errors'] );

		$http_error = $summary['http_errors'][0];

		$this->assertSame( 400, $http_error['status_code'] );
		$this->assertSame( 'VALIDATION_ERROR', $http_error['code'] );
		$this->assertCount( 1, $http_error['issues'] );
		$this->assertSame( 0, $http_error['issues'][0]['record'] );
		$this->assertSame( 'custom_fields.state', $http_error['issues'][0]['field'] );
		// The batch message names the issue count and field, not an in-batch
		// record index: that detail now lives only in the issue line, once a
		// run-wide position is available (see SyncJobProgressTest.php and
		// SyncRunnerStampingTest.php).
		$this->assertStringContainsString( '1 issue: custom_fields.state', $http_error['message'] );
	}

	#[Test]
	public function send_listings_leaves_the_plain_message_alone_when_there_are_no_issues(): void {
		Agend_Test_Directory_Bulk_Upsert::$response = new WP_Error(
			'http_request_failed',
			'cURL error 28: Operation timed out'
		);

		$client  = new Agend_Directory_Sync_Agend_Client();
		$summary = $client->send_listings(
			array( array( 'external_id' => 'ext-1', 'name' => 'Test Listing' ) ),
			'test-source'
		);

		$http_error = $summary['http_errors'][0];

		$this->assertSame( 'cURL error 28: Operation timed out', $http_error['message'] );
		$this->assertArrayNotHasKey( 'issues', $http_error );
		$this->assertArrayNotHasKey( 'status_code', $http_error );
	}

	/**
	 * A per-row 200-level error (the batch as a whole was accepted, but one
	 * row failed the gateway's independent per-row validation) carries its
	 * own `error.fields`, mapped into the error_examples entry's `fields`
	 * the same shape a batch-level 400's `issues` uses, with the row's index
	 * within the batch as `record` and the field message run through the
	 * same privacy scrub.
	 */
	#[Test]
	public function a_row_error_maps_its_fields_into_the_error_example(): void {
		Agend_Test_Directory_Bulk_Upsert::$response = array(
			'data' => array(
				'results' => array(
					array(
						'index'       => 0,
						'external_id' => 'ext-0',
						'status'      => 'error',
						'error'       => array(
							'code'    => 'VALIDATION_ERROR',
							'message' => 'Row failed validation',
							'fields'  => array(
								array( 'path' => 'custom_fields.state', 'message' => "Invalid enum value, received 'Jane Smith'" ),
								array( 'path' => 'email', 'message' => 'Invalid email' ),
							),
						),
					),
				),
			),
		);

		$client  = new Agend_Directory_Sync_Agend_Client();
		$summary = $client->send_listings(
			array( array( 'external_id' => 'ext-0' ) ),
			'test-source'
		);

		$this->assertCount( 1, $summary['error_examples'] );

		$fields = $summary['error_examples'][0]['fields'];
		$this->assertCount( 2, $fields );

		$this->assertSame( 0, $fields[0]['record'] );
		$this->assertSame( 'custom_fields.state', $fields[0]['field'] );
		$this->assertSame( 'Invalid enum value', $fields[0]['reason'] );
		$this->assertStringNotContainsString( 'Jane Smith', $fields[0]['reason'] );

		$this->assertSame( 0, $fields[1]['record'] );
		$this->assertSame( 'email', $fields[1]['field'] );
		$this->assertSame( 'Invalid email', $fields[1]['reason'] );
	}

	/**
	 * A row error with no `fields` at all (an older gateway, or a row error
	 * that carries only a top-level code/message) leaves the error_examples
	 * entry exactly as it was before this feature: no `fields` key at all,
	 * not an empty array.
	 */
	#[Test]
	public function a_row_error_without_fields_is_unchanged(): void {
		Agend_Test_Directory_Bulk_Upsert::$response = array(
			'data' => array(
				'results' => array(
					array(
						'index'       => 0,
						'external_id' => 'ext-0',
						'status'      => 'error',
						'error'       => array(
							'code'    => 'UNKNOWN',
							'message' => 'Something went wrong',
						),
					),
				),
			),
		);

		$client  = new Agend_Directory_Sync_Agend_Client();
		$summary = $client->send_listings(
			array( array( 'external_id' => 'ext-0' ) ),
			'test-source'
		);

		$this->assertArrayNotHasKey( 'fields', $summary['error_examples'][0] );
	}

	/**
	 * A row error's `fields` is capped at MAX_FIELD_ISSUES_PER_ROW, same
	 * reasoning as the batch-level issue cap: a row that fails every field it
	 * carries should not swamp the example.
	 */
	#[Test]
	public function a_row_error_caps_its_field_issues(): void {
		$fields = array();
		for ( $i = 0; $i < 15; $i++ ) {
			$fields[] = array( 'path' => "custom_fields.f$i", 'message' => 'Required' );
		}

		Agend_Test_Directory_Bulk_Upsert::$response = array(
			'data' => array(
				'results' => array(
					array(
						'index'       => 0,
						'external_id' => 'ext-0',
						'status'      => 'error',
						'error'       => array( 'code' => 'VALIDATION_ERROR', 'message' => 'Row failed', 'fields' => $fields ),
					),
				),
			),
		);

		$client  = new Agend_Directory_Sync_Agend_Client();
		$summary = $client->send_listings(
			array( array( 'external_id' => 'ext-0' ) ),
			'test-source'
		);

		$this->assertCount( Agend_Directory_Sync_Agend_Client::MAX_FIELD_ISSUES_PER_ROW, $summary['error_examples'][0]['fields'] );
	}

	/**
	 * An OPTION_CREATED notice on a successful row is aggregated per path
	 * into `options_created`, keeping a row count and distinct sanitised
	 * example values (capped at MAX_OPTION_VALUES_PER_PATH); any other
	 * notice code is only counted, per code, under `other_notices`.
	 */
	#[Test]
	public function notices_are_aggregated_by_path_and_code(): void {
		$rows = array();
		foreach ( array( 'VIC', 'WA', 'VIC' ) as $value ) {
			$rows[] = array(
				'status'      => 'created',
				'external_id' => 'ext-' . $value,
				'notices'     => array(
					array( 'code' => 'OPTION_CREATED', 'path' => 'custom_fields.state', 'value' => $value ),
				),
			);
		}
		$rows[] = array(
			'status'  => 'updated',
			'notices' => array( array( 'code' => 'SOMETHING_ELSE' ) ),
		);

		Agend_Test_Directory_Bulk_Upsert::$response = array( 'data' => array( 'results' => $rows ) );

		$client  = new Agend_Directory_Sync_Agend_Client();
		$summary = $client->send_listings( array_fill( 0, 4, array( 'external_id' => 'x' ) ), 'test-source' );

		$this->assertSame( 3, $summary['options_created']['custom_fields.state']['count'] );
		$this->assertSame( array( 'VIC', 'WA' ), $summary['options_created']['custom_fields.state']['values'] );
		$this->assertSame( array( 'SOMETHING_ELSE' => 1 ), $summary['other_notices'] );
	}

	/**
	 * `options_created` values are capped at MAX_OPTION_VALUES_PER_PATH
	 * distinct examples; the row count keeps counting past the cap.
	 */
	#[Test]
	public function options_created_values_are_capped_but_the_count_is_not(): void {
		$rows = array();
		for ( $i = 0; $i < 15; $i++ ) {
			$rows[] = array(
				'status'  => 'created',
				'notices' => array(
					array( 'code' => 'OPTION_CREATED', 'path' => 'custom_fields.state', 'value' => "v$i" ),
				),
			);
		}

		Agend_Test_Directory_Bulk_Upsert::$response = array( 'data' => array( 'results' => $rows ) );

		$client  = new Agend_Directory_Sync_Agend_Client();
		$summary = $client->send_listings( array_fill( 0, 15, array( 'external_id' => 'x' ) ), 'test-source' );

		$this->assertSame( 15, $summary['options_created']['custom_fields.state']['count'] );
		$this->assertCount( Agend_Directory_Sync_Agend_Client::MAX_OPTION_VALUES_PER_PATH, $summary['options_created']['custom_fields.state']['values'] );
	}

	/**
	 * A response with no `notices` at all on any row (an older gateway)
	 * leaves `options_created` and `other_notices` empty, exactly like
	 * before this feature existed.
	 */
	#[Test]
	public function absent_notices_leave_the_summary_empty(): void {
		Agend_Test_Directory_Bulk_Upsert::$response = array(
			'data' => array( 'results' => array( array( 'status' => 'created', 'external_id' => 'ext-0' ) ) ),
		);

		$client  = new Agend_Directory_Sync_Agend_Client();
		$summary = $client->send_listings( array( array( 'external_id' => 'ext-0' ) ), 'test-source' );

		$this->assertSame( array(), $summary['options_created'] );
		$this->assertSame( array(), $summary['other_notices'] );
		$this->assertSame( 0, $summary['warnings'] );
		$this->assertSame( array(), $summary['warning_examples'] );
	}

	/**
	 * A row's `warnings` (plain strings) are counted and kept as sanitised
	 * examples, capped at MAX_WARNING_EXAMPLES; the count keeps counting
	 * past the cap.
	 */
	#[Test]
	public function warnings_are_counted_and_capped_with_the_privacy_scrub_applied(): void {
		$rows = array();
		for ( $i = 0; $i < 12; $i++ ) {
			$rows[] = array(
				'status'   => 'updated',
				'warnings' => array( 'Row matched an existing listing by email jane@example.com' ),
			);
		}

		Agend_Test_Directory_Bulk_Upsert::$response = array( 'data' => array( 'results' => $rows ) );

		$client  = new Agend_Directory_Sync_Agend_Client();
		$summary = $client->send_listings( array_fill( 0, 12, array( 'external_id' => 'x' ) ), 'test-source' );

		$this->assertSame( 12, $summary['warnings'] );
		$this->assertCount( Agend_Directory_Sync_Agend_Client::MAX_WARNING_EXAMPLES, $summary['warning_examples'] );
		$this->assertStringNotContainsString( 'jane@example.com', $summary['warning_examples'][0] );
		$this->assertStringContainsString( '[email]', $summary['warning_examples'][0] );
	}
}
