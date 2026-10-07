<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\LoopSync;

use Loop_Sync_Test_Activity;
use Loop_Sync_Test_Committee;
use Loop_Sync_Test_State;
use PHPUnit\Framework\Attributes\Test;

require_once __DIR__ . '/LoopSyncTestCase.php';

/**
 * Deprovisioning (replace mode, archiving, clearing an emptied committee) must
 * only act on complete data. A failed Upbeat read looks like "nobody is
 * there", and acting on it would strip real members and archive live channels.
 */
final class UpstreamFailureSafetyTest extends LoopSyncTestCase {

	private function loop_committee( string $unique_id, bool $archived = false ): array {
		return array(
			'unique_id'   => $unique_id,
			'name'        => $unique_id,
			'channel_id'  => 'c-' . $unique_id,
			'slug'        => $unique_id,
			'is_archived' => $archived,
		);
	}

	#[Test]
	public function an_unreadable_committee_list_aborts_before_any_gateway_call(): void {
		loop_sync_test_user( 1, '100' );
		Loop_Sync_Test_State::$upbeat_committees        = false;
		Loop_Sync_Test_State::$upbeat_activities['100'] = array( new Loop_Sync_Test_Activity( 'Board', 'Chair' ) );
		Loop_Sync_Test_State::$loop_committees          = array( $this->loop_committee( 'board' ) );

		$summary = $this->committee_sync()->run();

		$this->assertSame( array(), Loop_Sync_Test_State::$calls );
		$this->assertNotEmpty( $summary['errors'] );
		$this->assertSame( 0, $summary['archived'] );
	}

	#[Test]
	public function a_failed_member_lookup_downgrades_the_run_to_merge_and_skips_archiving(): void {
		loop_sync_test_user( 1, '100' );
		loop_sync_test_user( 2, '200' );
		Loop_Sync_Test_State::$upbeat_committees        = array( new Loop_Sync_Test_Committee( 'Board' ) );
		Loop_Sync_Test_State::$upbeat_activities['100'] = array( new Loop_Sync_Test_Activity( 'Board', 'Chair' ) );
		Loop_Sync_Test_State::$upbeat_activities['200'] = false;
		Loop_Sync_Test_State::$loop_committees          = array( $this->loop_committee( 'board' ), $this->loop_committee( 'gone' ) );

		$summary = $this->committee_sync()->run();

		$this->assertSame( 1, $summary['failed_members'] );
		$this->assertSame( 'merge', $summary['mode'] );
		$this->assertSame( 'merge', Loop_Sync_Test_State::calls_to( 'agend_apps_loop_sync_committee' )[0][0]['mode'] );
		$this->assertSame( array(), Loop_Sync_Test_State::calls_to( 'agend_apps_loop_archive_committee' ) );
	}

	#[Test]
	public function a_complete_run_uses_replace_mode_and_archives_removed_committees(): void {
		loop_sync_test_user( 1, '100' );
		Loop_Sync_Test_State::$upbeat_committees        = array( new Loop_Sync_Test_Committee( 'Board' ) );
		Loop_Sync_Test_State::$upbeat_activities['100'] = array( new Loop_Sync_Test_Activity( 'Board', 'Chair' ) );
		Loop_Sync_Test_State::$loop_committees          = array( $this->loop_committee( 'board' ), $this->loop_committee( 'gone' ) );

		$summary = $this->committee_sync()->run();

		$this->assertSame( 'replace', Loop_Sync_Test_State::calls_to( 'agend_apps_loop_sync_committee' )[0][0]['mode'] );
		$this->assertSame( array( array( 'gone', 'hidden' ) ), Loop_Sync_Test_State::calls_to( 'agend_apps_loop_archive_committee' ) );
		$this->assertSame( 1, $summary['archived'] );
	}

	#[Test]
	public function archiving_follows_pagination_past_the_first_page(): void {
		loop_sync_test_user( 1, '100' );
		Loop_Sync_Test_State::$upbeat_committees        = array( new Loop_Sync_Test_Committee( 'Board' ) );
		Loop_Sync_Test_State::$upbeat_activities['100'] = array( new Loop_Sync_Test_Activity( 'Board', 'Chair' ) );

		$loop = array( $this->loop_committee( 'board' ) );
		for ( $i = 1; $i <= 120; $i++ ) {
			$loop[] = $this->loop_committee( 'stale-' . $i );
		}
		Loop_Sync_Test_State::$loop_committees = $loop;

		$summary = $this->committee_sync()->run();

		$this->assertSame( 120, $summary['archived'] );
		$this->assertCount( 2, Loop_Sync_Test_State::calls_to( 'agend_apps_loop_list_committees' ) );
	}

	#[Test]
	public function a_failed_page_skips_archiving_rather_than_acting_on_a_partial_list(): void {
		loop_sync_test_user( 1, '100' );
		Loop_Sync_Test_State::$upbeat_committees        = array( new Loop_Sync_Test_Committee( 'Board' ) );
		Loop_Sync_Test_State::$upbeat_activities['100'] = array( new Loop_Sync_Test_Activity( 'Board', 'Chair' ) );
		Loop_Sync_Test_State::$responses['agend_apps_loop_list_committees'] = new \WP_Error( 'boom', 'gateway down' );

		$summary = $this->committee_sync()->run();

		$this->assertSame( array(), Loop_Sync_Test_State::calls_to( 'agend_apps_loop_archive_committee' ) );
		$this->assertNotEmpty( $summary['errors'] );
	}

	#[Test]
	public function a_committee_whose_last_local_member_left_is_cleared_with_an_empty_roster(): void {
		loop_sync_test_user( 1, '100' );
		Loop_Sync_Test_State::$upbeat_committees        = array(
			new Loop_Sync_Test_Committee( 'Board' ),
			new Loop_Sync_Test_Committee( 'Audit' ),
		);
		Loop_Sync_Test_State::$upbeat_activities['100'] = array( new Loop_Sync_Test_Activity( 'Board', 'Chair' ) );
		Loop_Sync_Test_State::$loop_committees          = array( $this->loop_committee( 'board' ), $this->loop_committee( 'audit' ) );

		$this->committee_sync()->run();

		$payloads = array_column( Loop_Sync_Test_State::calls_to( 'agend_apps_loop_sync_committee' ), 0 );
		$by_id    = array_column( $payloads, null, 'unique_id' );
		$this->assertSame( array(), $by_id['audit']['roster'] );
		$this->assertSame( 'replace', $by_id['audit']['mode'] );
		$this->assertSame( array(), Loop_Sync_Test_State::calls_to( 'agend_apps_loop_archive_committee' ) );
	}

	#[Test]
	public function an_empty_committee_not_yet_in_loop_is_not_created(): void {
		loop_sync_test_user( 1, '100' );
		Loop_Sync_Test_State::$upbeat_committees        = array(
			new Loop_Sync_Test_Committee( 'Board' ),
			new Loop_Sync_Test_Committee( 'Audit' ),
		);
		Loop_Sync_Test_State::$upbeat_activities['100'] = array( new Loop_Sync_Test_Activity( 'Board', 'Chair' ) );
		Loop_Sync_Test_State::$loop_committees          = array( $this->loop_committee( 'board' ) );

		$this->committee_sync()->run();

		$ids = array_column( array_column( Loop_Sync_Test_State::calls_to( 'agend_apps_loop_sync_committee' ), 0 ), 'unique_id' );
		$this->assertSame( array( 'board' ), $ids );
	}

	#[Test]
	public function a_gateway_that_ignores_replace_mode_is_reported(): void {
		loop_sync_test_user( 1, '100' );
		Loop_Sync_Test_State::$upbeat_committees        = array( new Loop_Sync_Test_Committee( 'Board' ) );
		Loop_Sync_Test_State::$upbeat_activities['100'] = array( new Loop_Sync_Test_Activity( 'Board', 'Chair' ) );
		Loop_Sync_Test_State::$loop_committees          = array( $this->loop_committee( 'board' ) );
		Loop_Sync_Test_State::$responses['agend_apps_loop_sync_committee'] = array(
			'success' => true,
			'data'    => array( 'committee' => array(), 'created' => false ),
		);

		$summary = $this->committee_sync()->run();

		$this->assertContains( 'Gateway did not confirm replace mode; departed members are not being removed', $summary['errors'] );
	}
}
