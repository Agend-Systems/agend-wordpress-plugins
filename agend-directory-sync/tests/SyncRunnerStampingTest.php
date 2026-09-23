<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\DirectorySync;

use Agend_Directory_Sync_Runner;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-directory-sync/agend-directory-sync.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-agend-client.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-sync-runner.php';

/**
 * The CLI/synchronous run's own use of
 * Agend_Directory_Sync_Agend_Client::stamp_issue_position() -- the same
 * shared static the resumable job uses -- so a run-wide listing_position and
 * external_id mean the same thing and are computed the same way whichever
 * path produced an issue.
 */
#[CoversClass( Agend_Directory_Sync_Runner::class )]
final class SyncRunnerStampingTest extends TestCase {

	#[Test]
	public function it_stamps_an_issue_with_its_run_wide_position_and_external_id(): void {
		$listings = array(
			array( 'external_id' => 'ext-0' ),
			array( 'external_id' => 'ext-1' ),
			array( 'external_id' => 'ext-2' ),
			array( 'external_id' => 'ext-3' ),
			array( 'external_id' => 'ext-4' ),
		);

		// batch_size 2: [ext-0,ext-1], [ext-2,ext-3], [ext-4]. batch_index 1,
		// record 1 -> the second listing of the second batch -> ext-3.
		$http_errors = array(
			array(
				'batch_index' => 1,
				'message'     => 'Invalid request parameters',
				'issues'      => array(
					array( 'record' => 1, 'field' => 'name', 'reason' => 'Required' ),
				),
			),
		);

		$stamped = Agend_Directory_Sync_Runner::stamp_http_error_positions( $http_errors, $listings, 2 );

		$issue = $stamped[0]['issues'][0];

		// batch_index (1) * batch_size (2) + record (1) + 1 = 4.
		$this->assertSame( 4, $issue['listing_position'] );
		$this->assertSame( 'ext-3', $issue['external_id'] );
	}

	/**
	 * send_listings() makes exactly one call for the whole listings set, so
	 * its own batch_index is already run-wide -- unlike the job, which sends
	 * one batch per call and restamps an always-0 index. No restamping of
	 * batch_index happens here, only the position/external_id.
	 */
	#[Test]
	public function it_does_not_touch_batch_index(): void {
		$http_errors = array(
			array(
				'batch_index' => 3,
				'issues'      => array(
					array( 'record' => 0, 'field' => 'name', 'reason' => 'Required' ),
				),
			),
		);

		$stamped = Agend_Directory_Sync_Runner::stamp_http_error_positions( $http_errors, array_fill( 0, 20, array( 'external_id' => 'x' ) ), 5 );

		$this->assertSame( 3, $stamped[0]['batch_index'] );
	}

	#[Test]
	public function it_leaves_a_recordless_issue_and_a_batch_with_no_issues_alone(): void {
		$http_errors = array(
			array(
				'batch_index' => 0,
				'message'     => 'Invalid request parameters',
				'issues'      => array(
					array( 'record' => null, 'field' => 'external_source', 'reason' => 'Required' ),
				),
			),
			array(
				'batch_index' => 1,
				'message'     => 'cURL error 28: Operation timed out',
			),
		);

		$stamped = Agend_Directory_Sync_Runner::stamp_http_error_positions( $http_errors, array( array( 'external_id' => 'ext-0' ) ), 1 );

		$this->assertNull( $stamped[0]['issues'][0]['listing_position'] );
		$this->assertArrayNotHasKey( 'external_id', $stamped[0]['issues'][0] );
		$this->assertArrayNotHasKey( 'issues', $stamped[1] );
	}
}
