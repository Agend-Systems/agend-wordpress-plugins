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

	/**
	 * run()'s $timeout_context defaults to 'web', the safer of the two: a
	 * caller that forgets to say otherwise gets the capped browser-step
	 * timeout, not CLI's unbounded one. Checked by reflection rather than by
	 * exercising a real send, since run() with dry_run=false needs the full
	 * fetch/transform/source-registry pipeline this suite does not stand up
	 * elsewhere either; the three real callers (the job's step_fetch(), the
	 * admin preview action, and the CLI command) are each asserted to pass
	 * their own explicit context in the source directly.
	 */
	/**
	 * A row error's own field-level issues (an error_examples entry's
	 * `fields`, from the gateway's per-row `error.fields`) get the same
	 * run-wide listing_position/external_id stamping stamp_http_error_
	 * positions() gives a batch-level 400's `issues`, using the example's
	 * own batch_index (already run-wide here, one call for the whole
	 * listings set) and the field issue's record index within that batch.
	 */
	#[Test]
	public function it_stamps_a_row_errors_fields_with_their_run_wide_position_and_external_id(): void {
		$listings = array(
			array( 'external_id' => 'ext-0' ),
			array( 'external_id' => 'ext-1' ),
			array( 'external_id' => 'ext-2' ),
			array( 'external_id' => 'ext-3' ),
			array( 'external_id' => 'ext-4' ),
		);

		$error_examples = array(
			array(
				'batch_index' => 1,
				'external_id' => 'ext-3',
				'code'        => 'VALIDATION_ERROR',
				'message'     => 'Row failed validation',
				'fields'      => array(
					array( 'record' => 1, 'field' => 'custom_fields.state', 'reason' => 'Invalid enum value' ),
				),
			),
		);

		$stamped = Agend_Directory_Sync_Runner::stamp_error_example_positions( $error_examples, $listings, 2 );

		$issue = $stamped[0]['fields'][0];

		// batch_index (1) * batch_size (2) + record (1) + 1 = 4.
		$this->assertSame( 4, $issue['listing_position'] );
		$this->assertSame( 'ext-3', $issue['external_id'] );
	}

	/**
	 * An error_examples entry with no `fields` at all (an older gateway, or a
	 * row error that carries only a top-level code/message) is left alone.
	 */
	#[Test]
	public function it_leaves_an_error_example_with_no_fields_alone(): void {
		$error_examples = array(
			array(
				'batch_index' => 0,
				'external_id' => 'ext-0',
				'code'        => 'UNKNOWN',
				'message'     => 'Something went wrong',
			),
		);

		$stamped = Agend_Directory_Sync_Runner::stamp_error_example_positions( $error_examples, array( array( 'external_id' => 'ext-0' ) ), 1 );

		$this->assertArrayNotHasKey( 'fields', $stamped[0] );
	}

	#[Test]
	public function run_defaults_the_timeout_context_to_web(): void {
		$parameter = ( new \ReflectionMethod( Agend_Directory_Sync_Runner::class, 'run' ) )->getParameters()[2];

		$this->assertSame( 'timeout_context', $parameter->getName() );
		$this->assertTrue( $parameter->isDefaultValueAvailable() );
		$this->assertSame( 'web', $parameter->getDefaultValue() );
	}
}
