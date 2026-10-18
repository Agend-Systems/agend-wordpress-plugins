<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\LoopSync;

use Agend_Loop_Sync_Settings;
use Loop_Sync_Test_Activity;
use Loop_Sync_Test_Committee;
use Loop_Sync_Test_State;
use PHPUnit\Framework\Attributes\Test;

require_once __DIR__ . '/LoopSyncTestCase.php';

/**
 * The settings page prints member counts only when the gateway reported them.
 * A gateway that returns just { committee, created } leaves every count at 0,
 * which would otherwise read as real data, so the summary records whether the
 * counts came back.
 */
final class MemberCountReportingTest extends LoopSyncTestCase {

	protected function setUp(): void {
		parent::setUp();

		loop_sync_test_user( 1, '100' );
		Loop_Sync_Test_State::$upbeat_committees        = array( new Loop_Sync_Test_Committee( 'Board' ) );
		Loop_Sync_Test_State::$upbeat_activities['100'] = array( new Loop_Sync_Test_Activity( 'Board', 'Chair' ) );
	}

	#[Test]
	public function the_flag_is_false_when_the_gateway_response_lacks_applied(): void {
		Loop_Sync_Test_State::$responses['agend_apps_loop_sync_committee'] = array(
			'success' => true,
			'data'    => array( 'committee' => array(), 'created' => true ),
		);

		$summary = $this->committee_sync()->run();

		$this->assertSame( 1, $summary['synced'] );
		$this->assertFalse( $summary['member_counts_reported'] );
		$this->assertSame( 0, $summary['applied_members'] );
	}

	#[Test]
	public function the_flag_is_true_when_the_gateway_response_includes_applied(): void {
		Loop_Sync_Test_State::$responses['agend_apps_loop_sync_committee'] = array(
			'success' => true,
			'data'    => array( 'applied' => 1, 'skipped' => 0, 'removed' => 0, 'skipped_external_ids' => array() ),
		);

		$summary = $this->committee_sync()->run();

		$this->assertTrue( $summary['member_counts_reported'] );
		$this->assertSame( 1, $summary['applied_members'] );
	}

	#[Test]
	public function a_reported_count_of_zero_still_counts_as_reported(): void {
		Loop_Sync_Test_State::$responses['agend_apps_loop_sync_committee'] = array(
			'success' => true,
			'data'    => array( 'applied' => 0, 'skipped' => 0, 'removed' => 0 ),
		);

		$summary = $this->committee_sync()->run();

		$this->assertTrue( $summary['member_counts_reported'] );
	}

	#[Test]
	public function the_flag_is_true_when_the_client_returns_the_data_unwrapped(): void {
		Loop_Sync_Test_State::$responses['agend_apps_loop_sync_committee'] = array( 'applied' => 1, 'skipped' => 0, 'removed' => 0 );

		$summary = $this->committee_sync()->run();

		$this->assertTrue( $summary['member_counts_reported'] );
	}

	#[Test]
	public function the_flag_is_false_in_a_dry_run_because_nothing_reaches_the_gateway(): void {
		update_option( 'agend_loop_sync_dry_run', '1' );

		$summary = $this->committee_sync()->run();

		$this->assertSame( array(), Loop_Sync_Test_State::calls_to( 'agend_apps_loop_sync_committee' ) );
		$this->assertTrue( $summary['dry_run'] );
		$this->assertFalse( $summary['member_counts_reported'] );
	}

	#[Test]
	public function the_stored_summary_carries_the_flag_for_the_settings_page(): void {
		Loop_Sync_Test_State::$responses['agend_apps_loop_sync_committee'] = array(
			'success' => true,
			'data'    => array( 'applied' => 1, 'skipped' => 0, 'removed' => 0 ),
		);

		$this->committee_sync()->run();

		$stored = get_option( Agend_Loop_Sync_Settings::OPT_LAST_SUMMARY );
		$this->assertTrue( $stored['member_counts_reported'] );
	}
}
