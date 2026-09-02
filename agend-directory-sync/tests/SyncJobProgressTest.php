<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\DirectorySync;

use Agend_Directory_Sync_Job;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

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
}
