<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\DirectorySync;

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
				'id'            => 'dsj_test',
				'user_id'       => 1,
				'stage'         => Agend_Directory_Sync_Job::STAGE_SENDING,
				'batch_count'   => 10,
				'batch_cursor'  => 4,
				'listing_count' => 950,
				'source'        => 'dataverse',
				'message'       => '',
				'send'          => array(
					'created'        => 300,
					'updated'        => 100,
					'errored'        => 0,
					'error_examples' => array(),
					'http_errors'    => array(),
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
}
