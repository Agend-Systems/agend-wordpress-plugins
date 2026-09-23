<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\DirectorySync;

use Agend_Directory_Sync_Agend_Client;
use Agend_Directory_Sync_Job;
use Agend_Test_Directory_Bulk_Upsert;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use WP_Error;

require_once AGEND_TESTS_ROOT . '/agend-directory-sync/agend-directory-sync.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/interface-source.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-config.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-agend-client.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-sync-job.php';

/**
 * The job state a chunked upload reports, and the lifecycle rules the progress
 * panel and the step loop depend on.
 */
#[CoversClass( Agend_Directory_Sync_Job::class )]
final class SyncJobProgressTest extends TestCase {

	/**
	 * @param array<string, mixed> $overrides
	 *
	 * @return array<string, mixed>
	 */
	private function job( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'             => 'dsj_test',
				'user_id'        => 1,
				'stage'          => Agend_Directory_Sync_Job::STAGE_SENDING,
				'batch_size'     => 100,
				'batch_count'    => 10,
				'batch_cursor'   => 4,
				'batch_attempts' => 0,
				'retry_after'    => 0,
				'listing_count'  => 950,
				'source'         => 'dataverse',
				'message'        => '',
				'send'           => array(
					'created'         => 300,
					'updated'         => 100,
					'errored'         => 0,
					'error_examples'  => array(),
					'http_errors'     => array(),
					'retried_batches' => array(),
				),
			),
			$overrides
		);
	}

	#[Test]
	public function it_should_report_percent_complete_from_batches_done(): void {
		$progress = Agend_Directory_Sync_Job::progress( $this->job() );

		$this->assertSame( 40, $progress['percent'] );
		$this->assertSame( 4, $progress['batches_done'] );
		$this->assertSame( 10, $progress['batches_total'] );
	}

	/**
	 * Fetching has no batch count yet. Reporting 0% there reads as a stalled
	 * run, so the panel is told the figure is unknown instead.
	 */
	#[Test]
	public function it_should_report_an_unknown_percent_while_still_fetching(): void {
		$progress = Agend_Directory_Sync_Job::progress(
			$this->job(
				array(
					'stage'       => Agend_Directory_Sync_Job::STAGE_PENDING,
					'batch_count' => 0,
				)
			)
		);

		$this->assertNull( $progress['percent'] );
		$this->assertTrue( $progress['active'] );
	}

	#[Test]
	public function it_should_report_a_finished_job_as_complete_and_inactive(): void {
		$progress = Agend_Directory_Sync_Job::progress(
			$this->job(
				array(
					'stage'        => Agend_Directory_Sync_Job::STAGE_DONE,
					'batch_cursor' => 10,
				)
			)
		);

		$this->assertSame( 100, $progress['percent'] );
		$this->assertFalse( $progress['active'] );
	}

	/**
	 * The last batch is normally short, so multiplying batches by the cap
	 * overshoots. A panel claiming 1000 of 950 sent looks broken.
	 */
	#[Test]
	public function it_should_never_report_more_listings_sent_than_exist(): void {
		$progress = Agend_Directory_Sync_Job::progress(
			$this->job(
				array(
					'stage'        => Agend_Directory_Sync_Job::STAGE_DONE,
					'batch_cursor' => 10,
				)
			)
		);

		$this->assertSame( 950, $progress['listings_sent'] );
	}

	#[Test]
	public function it_should_treat_pending_and_sending_as_active_and_everything_else_as_not(): void {
		$this->assertTrue( Agend_Directory_Sync_Job::is_active( $this->job( array( 'stage' => Agend_Directory_Sync_Job::STAGE_PENDING ) ) ) );
		$this->assertTrue( Agend_Directory_Sync_Job::is_active( $this->job( array( 'stage' => Agend_Directory_Sync_Job::STAGE_SENDING ) ) ) );
		$this->assertFalse( Agend_Directory_Sync_Job::is_active( $this->job( array( 'stage' => Agend_Directory_Sync_Job::STAGE_DONE ) ) ) );
		$this->assertFalse( Agend_Directory_Sync_Job::is_active( $this->job( array( 'stage' => Agend_Directory_Sync_Job::STAGE_CANCELLED ) ) ) );
		$this->assertFalse( Agend_Directory_Sync_Job::is_active( $this->job( array( 'stage' => Agend_Directory_Sync_Job::STAGE_FAILED ) ) ) );
	}

	#[Test]
	public function it_should_surface_the_failure_message_on_a_failed_job(): void {
		$progress = Agend_Directory_Sync_Job::progress(
			$this->job(
				array(
					'stage'   => Agend_Directory_Sync_Job::STAGE_FAILED,
					'message' => 'Dataverse request failed with HTTP 400',
				)
			)
		);

		$this->assertFalse( $progress['active'] );
		$this->assertSame( 'Dataverse request failed with HTTP 400', $progress['message'] );
	}

	#[Test]
	public function it_should_report_no_job_when_none_has_been_started(): void {
		delete_option( Agend_Directory_Sync_Job::OPTION_JOB );

		$this->assertNull( Agend_Directory_Sync_Job::current() );
	}

	#[Test]
	public function it_should_read_back_a_stored_job(): void {
		update_option( Agend_Directory_Sync_Job::OPTION_JOB, $this->job(), false );

		$current = Agend_Directory_Sync_Job::current();

		$this->assertIsArray( $current );
		$this->assertSame( 'dsj_test', $current['id'] );
	}

	/**
	 * Cancelling leaves the uploaded batches uploaded: the bulk-upsert is
	 * idempotent on (external_source, external_id), so a cancelled run is a
	 * partial sync a later run finishes, not something to roll back.
	 */
	#[Test]
	public function it_should_mark_a_cancelled_job_inactive_without_clearing_its_counts(): void {
		update_option( Agend_Directory_Sync_Job::OPTION_JOB, $this->job(), false );

		Agend_Directory_Sync_Job::cancel();

		$current = Agend_Directory_Sync_Job::current();

		$this->assertSame( Agend_Directory_Sync_Job::STAGE_CANCELLED, $current['stage'] );
		$this->assertSame( 300, $current['send']['created'] );
		$this->assertFalse( Agend_Directory_Sync_Job::is_active( $current ) );
	}

	#[Test]
	public function it_should_forget_a_job_entirely_when_cleared(): void {
		update_option( Agend_Directory_Sync_Job::OPTION_JOB, $this->job(), false );

		Agend_Directory_Sync_Job::clear();

		$this->assertNull( Agend_Directory_Sync_Job::current() );
	}

	/**
	 * Stores a batch payload the way step_fetch() would have, at the option
	 * key step_send() reads it back from -- the same md5(job id) + index
	 * scheme Agend_Directory_Sync_Job::batch_option() uses internally.
	 *
	 * @param array<int, array<string, mixed>> $batch
	 */
	private function store_batch( string $job_id, int $index, array $batch ): void {
		update_option(
			Agend_Directory_Sync_Job::OPTION_BATCH_PREFIX . md5( $job_id ) . '_' . $index,
			$batch,
			false
		);
	}

	/**
	 * A gateway 400 for the record at index 1 of the batch at job-wide batch
	 * index 2 must come back with its record index converted to the position
	 * an operator counts across the whole run (batch_index * batch_size +
	 * record + 1), and with the failing listing's external_id attached from
	 * the batch that was actually sent.
	 */
	#[Test]
	public function it_converts_an_issue_record_into_a_job_wide_listing_position_and_attaches_external_id(): void {
		$job_id = 'dsj_position_test';

		update_option(
			Agend_Directory_Sync_Job::OPTION_JOB,
			$this->job(
				array(
					'id'              => $job_id,
					'stage'           => Agend_Directory_Sync_Job::STAGE_SENDING,
					'batch_count'     => 3,
					'batch_cursor'    => 2,
					'external_source' => 'test-source',
					'auto_publish'    => false,
					'transform'       => array(),
				)
			),
			false
		);

		$this->store_batch(
			$job_id,
			2,
			array(
				array( 'external_id' => 'ext-0', 'name' => 'Row 0' ),
				array( 'external_id' => 'ext-1', 'name' => 'Row 1' ),
			)
		);

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
								array(
									'path'    => array( 'listings', 1, 'name' ),
									'message' => 'Required',
								),
							),
						),
					),
				),
			)
		);

		$job = Agend_Directory_Sync_Job::step();

		$http_error = $job['send']['http_errors'][0];
		$this->assertSame( 2, $http_error['batch_index'] );

		$issue = $http_error['issues'][0];
		$this->assertSame( 1, $issue['record'] );
		// batch_index (2) * MAX_BATCH_SIZE (100) + record (1) + 1 = 202.
		$this->assertSame( 202, $issue['listing_position'] );
		$this->assertSame( 'ext-1', $issue['external_id'] );
	}

	/**
	 * A `fields`-shape issue carries no record index, so it must not be given
	 * a fabricated listing position or external_id.
	 */
	#[Test]
	public function it_leaves_an_issue_with_no_record_unstamped(): void {
		$job_id = 'dsj_no_record_test';

		update_option(
			Agend_Directory_Sync_Job::OPTION_JOB,
			$this->job(
				array(
					'id'              => $job_id,
					'stage'           => Agend_Directory_Sync_Job::STAGE_SENDING,
					'batch_count'     => 1,
					'batch_cursor'    => 0,
					'external_source' => 'test-source',
					'auto_publish'    => false,
					'transform'       => array(),
				)
			),
			false
		);

		$this->store_batch(
			$job_id,
			0,
			array( array( 'external_id' => 'ext-0', 'name' => 'Row 0' ) )
		);

		Agend_Test_Directory_Bulk_Upsert::$response = new WP_Error(
			'agend_api_error',
			'Invalid request parameters',
			array(
				'status_code' => 400,
				'body'        => array(
					'error' => array(
						'code'    => 'VALIDATION_ERROR',
						'details' => array(
							'fields' => array(
								'external_source' => array( 'Required' ),
							),
						),
					),
				),
			)
		);

		$job = Agend_Directory_Sync_Job::step();

		$issue = $job['send']['http_errors'][0]['issues'][0];

		$this->assertNull( $issue['record'] );
		$this->assertNull( $issue['listing_position'] );
		$this->assertArrayNotHasKey( 'external_id', $issue );
	}

	/**
	 * A transport timeout on the first attempt schedules a retry rather than
	 * failing the batch immediately: the cursor stays put, the stored batch
	 * option is kept, and nothing lands in http_errors yet.
	 */
	#[Test]
	public function a_timeout_schedules_a_retry_without_advancing_the_cursor_or_recording_a_failure(): void {
		$job_id = 'dsj_retry_test';
		$option = Agend_Directory_Sync_Job::OPTION_BATCH_PREFIX . md5( $job_id ) . '_0';

		update_option(
			Agend_Directory_Sync_Job::OPTION_JOB,
			$this->job(
				array(
					'id'              => $job_id,
					'stage'           => Agend_Directory_Sync_Job::STAGE_SENDING,
					'batch_count'     => 1,
					'batch_cursor'    => 0,
					'batch_attempts'  => 0,
					'retry_after'     => 0,
					'external_source' => 'test-source',
					'auto_publish'    => false,
					'transform'       => array(),
				)
			),
			false
		);

		$this->store_batch( $job_id, 0, array( array( 'external_id' => 'ext-0' ) ) );

		Agend_Test_Directory_Bulk_Upsert::$response = new WP_Error(
			'http_request_failed',
			'cURL error 28: Operation timed out after 15001 milliseconds with 0 bytes received'
		);

		$job = Agend_Directory_Sync_Job::step( 60 );

		$this->assertSame( 0, $job['batch_cursor'] );
		$this->assertSame( 1, $job['batch_attempts'] );
		$this->assertGreaterThan( time(), $job['retry_after'] );
		$this->assertSame( array(), $job['send']['http_errors'] );
		$this->assertNotFalse( get_option( $option ) );
	}

	/**
	 * The third attempt (the original send plus two retries) that still times
	 * out is finally recorded as a failure, with the attempt count in its
	 * message, and only then is the stored batch option released.
	 */
	#[Test]
	public function the_third_timeout_records_the_failure_with_the_attempt_count(): void {
		$job_id = 'dsj_retry_exhausted_test';
		$option = Agend_Directory_Sync_Job::OPTION_BATCH_PREFIX . md5( $job_id ) . '_0';

		update_option(
			Agend_Directory_Sync_Job::OPTION_JOB,
			$this->job(
				array(
					'id'              => $job_id,
					'stage'           => Agend_Directory_Sync_Job::STAGE_SENDING,
					'batch_count'     => 1,
					'batch_cursor'    => 0,
					'batch_attempts'  => 2,
					'retry_after'     => time() - 1,
					'external_source' => 'test-source',
					'auto_publish'    => false,
					'transform'       => array(),
				)
			),
			false
		);

		$this->store_batch( $job_id, 0, array( array( 'external_id' => 'ext-0' ) ) );

		Agend_Test_Directory_Bulk_Upsert::$response = new WP_Error(
			'http_request_failed',
			'cURL error 28: Operation timed out after 15001 milliseconds with 0 bytes received'
		);

		$job = Agend_Directory_Sync_Job::step( 60 );

		$this->assertSame( 1, $job['batch_cursor'] );
		$this->assertSame( 0, $job['batch_attempts'] );
		$this->assertCount( 1, $job['send']['http_errors'] );
		$this->assertStringContainsString( 'failed after 3 attempts', $job['send']['http_errors'][0]['message'] );
		$this->assertFalse( get_option( $option ) );
	}

	/**
	 * A gateway 400 is a real answer, not a transport failure, so it is never
	 * retried: the batch fails on the first attempt.
	 */
	#[Test]
	public function a_gateway_400_is_not_retried(): void {
		$job_id = 'dsj_no_retry_test';

		update_option(
			Agend_Directory_Sync_Job::OPTION_JOB,
			$this->job(
				array(
					'id'              => $job_id,
					'stage'           => Agend_Directory_Sync_Job::STAGE_SENDING,
					'batch_count'     => 1,
					'batch_cursor'    => 0,
					'batch_attempts'  => 0,
					'retry_after'     => 0,
					'external_source' => 'test-source',
					'auto_publish'    => false,
					'transform'       => array(),
				)
			),
			false
		);

		$this->store_batch( $job_id, 0, array( array( 'external_id' => 'ext-0' ) ) );

		Agend_Test_Directory_Bulk_Upsert::$response = new WP_Error(
			'agend_api_error',
			'Invalid request parameters',
			array(
				'status_code' => 400,
				'body'        => array( 'error' => array( 'code' => 'VALIDATION_ERROR' ) ),
			)
		);

		$job = Agend_Directory_Sync_Job::step( 60 );

		$this->assertSame( 1, $job['batch_cursor'] );
		$this->assertSame( 0, $job['batch_attempts'] );
		$this->assertCount( 1, $job['send']['http_errors'] );
		$this->assertStringNotContainsString( 'timed out', $job['send']['http_errors'][0]['message'] );
	}

	/**
	 * A step that arrives before retry_after is a no-op: no gateway call, no
	 * lock taken, the job unchanged. progress() reports how much longer the
	 * page should wait before its next step call.
	 */
	#[Test]
	public function a_step_before_retry_after_is_a_no_op_reporting_waiting_seconds(): void {
		$job_id = 'dsj_waiting_test';

		$stored = $this->job(
			array(
				'id'              => $job_id,
				'stage'           => Agend_Directory_Sync_Job::STAGE_SENDING,
				'batch_count'     => 1,
				'batch_cursor'    => 0,
				'batch_attempts'  => 1,
				'retry_after'     => time() + 50,
				'external_source' => 'test-source',
				'auto_publish'    => false,
				'transform'       => array(),
			)
		);

		update_option( Agend_Directory_Sync_Job::OPTION_JOB, $stored, false );

		$job = Agend_Directory_Sync_Job::step( 60 );

		$this->assertSame( 0, $job['batch_cursor'] );
		$this->assertSame( 1, $job['batch_attempts'] );
		$this->assertFalse( get_option( Agend_Directory_Sync_Job::OPTION_LOCK ) );

		$progress = Agend_Directory_Sync_Job::progress( $job );

		$this->assertNotNull( $progress['waiting_seconds'] );
		$this->assertGreaterThan( 0, $progress['waiting_seconds'] );
		$this->assertLessThanOrEqual( 50, $progress['waiting_seconds'] );
	}

	/**
	 * A job created before batch_size was stored on it (or one whose stored
	 * value is otherwise unusable) must fall back to the fixed 100 every
	 * pre-0.8.1 release actually chunked with, not today's batch_size()
	 * setting default (25) -- a different number that would desync the
	 * position arithmetic for every batch after the first.
	 */
	#[Test]
	public function job_batch_size_falls_back_to_max_batch_size_for_a_legacy_job(): void {
		$this->assertSame(
			Agend_Directory_Sync_Agend_Client::MAX_BATCH_SIZE,
			Agend_Directory_Sync_Job::job_batch_size( $this->job( array( 'batch_size' => null ) ) )
		);
		$this->assertSame(
			Agend_Directory_Sync_Agend_Client::MAX_BATCH_SIZE,
			Agend_Directory_Sync_Job::job_batch_size( array( 'id' => 'x' ) )
		);
		$this->assertSame(
			Agend_Directory_Sync_Agend_Client::MAX_BATCH_SIZE,
			Agend_Directory_Sync_Job::job_batch_size( $this->job( array( 'batch_size' => 0 ) ) )
		);
		$this->assertSame(
			Agend_Directory_Sync_Agend_Client::MAX_BATCH_SIZE,
			Agend_Directory_Sync_Job::job_batch_size( $this->job( array( 'batch_size' => -5 ) ) )
		);
		$this->assertSame(
			Agend_Directory_Sync_Agend_Client::MAX_BATCH_SIZE,
			Agend_Directory_Sync_Job::job_batch_size( $this->job( array( 'batch_size' => 'not-a-number' ) ) )
		);
	}

	#[Test]
	public function job_batch_size_returns_a_valid_stored_value_unchanged(): void {
		$this->assertSame( 40, Agend_Directory_Sync_Job::job_batch_size( $this->job( array( 'batch_size' => 40 ) ) ) );
	}

	/**
	 * A SENDING-stage legacy job (missing batch_size entirely, as one paused
	 * before 0.8.1 and resumed afterwards would be) must neither crash
	 * (array_chunk() throws a ValueError given a length of 0, which
	 * (int) null casts to) nor silently switch to the new batch_size()
	 * default: it falls back to 100 and computes a run-wide position against
	 * that, exactly like a job that has always had batch_size stored.
	 */
	#[Test]
	public function a_legacy_sending_job_with_no_batch_size_uses_one_hundred_and_positions_correctly(): void {
		$job_id = 'dsj_legacy_test';

		$job = $this->job(
			array(
				'id'              => $job_id,
				'stage'           => Agend_Directory_Sync_Job::STAGE_SENDING,
				'batch_count'     => 3,
				'batch_cursor'    => 2,
				'external_source' => 'test-source',
				'auto_publish'    => false,
				'transform'       => array(),
			)
		);
		unset( $job['batch_size'] );

		update_option( Agend_Directory_Sync_Job::OPTION_JOB, $job, false );

		$this->store_batch(
			$job_id,
			2,
			array(
				array( 'external_id' => 'ext-0', 'name' => 'Row 0' ),
				array( 'external_id' => 'ext-1', 'name' => 'Row 1' ),
			)
		);

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
								array( 'path' => array( 'listings', 1, 'name' ), 'message' => 'Required' ),
							),
						),
					),
				),
			)
		);

		$result = Agend_Directory_Sync_Job::step();

		$issue = $result['send']['http_errors'][0]['issues'][0];
		// batch_index (2) * 100 (the legacy fallback) + record (1) + 1 = 202.
		$this->assertSame( 202, $issue['listing_position'] );
		$this->assertSame( 'ext-1', $issue['external_id'] );
	}

	/**
	 * A job's own batch_size, fixed at start(), does not move when the live
	 * setting is changed mid-run: job_batch_size() reads only the value
	 * stored on the job it is given.
	 */
	#[Test]
	public function a_job_keeps_its_own_batch_size_when_the_setting_changes_mid_run(): void {
		$job = $this->job( array( 'batch_size' => 50 ) );

		update_option( \Agend_Directory_Sync::OPTION_BATCH_SIZE, 10 );

		$this->assertSame( 50, Agend_Directory_Sync_Job::job_batch_size( $job ) );

		$progress = Agend_Directory_Sync_Job::progress(
			array_merge( $job, array( 'batch_cursor' => 2, 'listing_count' => 1000 ) )
		);
		$this->assertSame( 100, $progress['listings_sent'] );
	}

	/**
	 * A batch that fails once with a retryable transport error and then
	 * succeeds on its second attempt resets the retry bookkeeping, records no
	 * http_errors entry, and is flagged in retried_batches so the result
	 * panel can note that some of what it counts as "updated" may actually be
	 * rows an earlier, timed-out attempt already delivered.
	 */
	#[Test]
	public function a_timeout_followed_by_success_resets_attempts_and_records_a_retried_batch(): void {
		$job_id = 'dsj_retry_then_success_test';

		update_option(
			Agend_Directory_Sync_Job::OPTION_JOB,
			$this->job(
				array(
					'id'              => $job_id,
					'stage'           => Agend_Directory_Sync_Job::STAGE_SENDING,
					'batch_count'     => 1,
					'batch_cursor'    => 0,
					'batch_attempts'  => 1,
					'retry_after'     => time() - 1,
					'external_source' => 'test-source',
					'auto_publish'    => false,
					'transform'       => array(),
				)
			),
			false
		);

		$this->store_batch( $job_id, 0, array( array( 'external_id' => 'ext-0' ) ) );

		Agend_Test_Directory_Bulk_Upsert::$response = array(
			'data' => array(
				'results' => array(
					array( 'status' => 'updated', 'external_id' => 'ext-0' ),
				),
			),
		);

		$job = Agend_Directory_Sync_Job::step( 60 );

		$this->assertSame( 1, $job['batch_cursor'] );
		$this->assertSame( 0, $job['batch_attempts'] );
		$this->assertSame( 0, $job['retry_after'] );
		$this->assertSame( array(), $job['send']['http_errors'] );
		$this->assertCount( 1, $job['send']['retried_batches'] );
		$this->assertSame( 1, $job['send']['retried_batches'][0]['batch'] );
		$this->assertSame( 2, $job['send']['retried_batches'][0]['attempts'] );
	}

	/**
	 * Acquiring the lock does not itself refresh the job read at the top of
	 * step(): another request can write a newer state into the gap between
	 * that read and the lock being granted. The `agend_directory_sync_job_
	 * before_lock` action is a test seam for exactly this window: hooking it
	 * to write a newer job simulates the race, and step() must act on that
	 * newer state (here: a job the hook cancels, which step() must return
	 * without touching the gateway) rather than the stale pre-lock copy.
	 */
	#[Test]
	public function step_re_reads_the_job_after_acquiring_the_lock(): void {
		$job_id = 'dsj_race_test';

		update_option(
			Agend_Directory_Sync_Job::OPTION_JOB,
			$this->job(
				array(
					'id'              => $job_id,
					'stage'           => Agend_Directory_Sync_Job::STAGE_SENDING,
					'batch_count'     => 1,
					'batch_cursor'    => 0,
					'external_source' => 'test-source',
					'auto_publish'    => false,
					'transform'       => array(),
				)
			),
			false
		);

		$this->store_batch( $job_id, 0, array( array( 'external_id' => 'ext-0' ) ) );

		add_action(
			'agend_directory_sync_job_before_lock',
			static function ( array $job ) use ( $job_id ): void {
				// Simulate a second request cancelling the job in the window
				// between step()'s pre-lock read and the lock being granted.
				$job['stage'] = Agend_Directory_Sync_Job::STAGE_CANCELLED;
				update_option( Agend_Directory_Sync_Job::OPTION_JOB, $job, false );
			}
		);

		$result = Agend_Directory_Sync_Job::step();

		$this->assertSame( Agend_Directory_Sync_Job::STAGE_CANCELLED, $result['stage'] );
		// The stale pre-lock copy was still STAGE_SENDING with a batch ready
		// to send; if step() had acted on it instead of re-reading, this
		// would be non-empty.
		$this->assertSame( array(), Agend_Test_Directory_Bulk_Upsert::$calls );
	}
}
