<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\LoopSync;

use Agend_Loop_Sync_Logger;
use Loop_Sync_Test_Activity;
use Loop_Sync_Test_Committee;
use Loop_Sync_Test_State;
use PHPUnit\Framework\Attributes\Test;

require_once __DIR__ . '/LoopSyncTestCase.php';

/**
 * A committee that still exists in Upbeat but has no local member left is
 * archived in Loop. The gateway ignores an empty roster, so pushing one would
 * leave the last members in the channel. Archiving is reversible: the next
 * roster sync upserts the committee as active once a local member returns.
 */
final class EmptyCommitteeArchivingTest extends LoopSyncTestCase {

	private function loop_committee( string $unique_id, bool $archived = false ): array {
		return array(
			'unique_id'   => $unique_id,
			'name'        => $unique_id,
			'channel_id'  => 'c-' . $unique_id,
			'slug'        => $unique_id,
			'is_archived' => $archived,
		);
	}

	/**
	 * @return array<int, array<string, mixed>> Log entries at $level whose message contains $fragment.
	 */
	private function logged( string $level, string $fragment ): array {
		return array_values(
			array_filter(
				Agend_Loop_Sync_Logger::recent(),
				static fn( $entry ) => $level === $entry['level'] && false !== strpos( $entry['message'], $fragment )
			)
		);
	}

	/**
	 * One local member on Board, and Audit listed in Upbeat with nobody on it.
	 */
	private function board_with_an_empty_audit(): void {
		loop_sync_test_user( 1, '100' );
		Loop_Sync_Test_State::$upbeat_committees        = array(
			new Loop_Sync_Test_Committee( 'Board', 'uid-board' ),
			new Loop_Sync_Test_Committee( 'Audit', 'uid-audit' ),
		);
		Loop_Sync_Test_State::$upbeat_activities['100'] = array( new Loop_Sync_Test_Activity( 'Board', 'Chair' ) );
	}

	#[Test]
	public function a_dry_run_logs_and_counts_the_empty_committee_but_does_not_archive_it(): void {
		update_option( 'agend_loop_sync_dry_run', '1' );
		$this->board_with_an_empty_audit();
		Loop_Sync_Test_State::$loop_committees = array( $this->loop_committee( 'uid-board' ), $this->loop_committee( 'uid-audit' ) );

		$summary = $this->committee_sync()->run();

		$this->assertSame( array(), Loop_Sync_Test_State::calls_to( 'agend_apps_loop_archive_committee' ) );
		$this->assertSame( array(), Loop_Sync_Test_State::calls_to( 'agend_apps_loop_sync_committee' ) );
		$this->assertSame( 1, $summary['archived'] );
		$this->assertSame( 1, $summary['archived_empty'] );

		$entries = $this->logged( 'info', 'Dry run: would archive committee' );
		$this->assertCount( 1, $entries );
		$this->assertSame( 'uid-audit', $entries[0]['context']['unique_id'] );
		$this->assertSame( 'no local member left', $entries[0]['context']['reason'] );
	}

	#[Test]
	public function a_failed_archive_is_recorded_and_not_counted_while_the_rest_of_the_run_continues(): void {
		$this->board_with_an_empty_audit();
		Loop_Sync_Test_State::$loop_committees                                = array( $this->loop_committee( 'uid-board' ), $this->loop_committee( 'uid-audit' ) );
		Loop_Sync_Test_State::$responses['agend_apps_loop_archive_committee'] = new \WP_Error( 'boom', 'gateway down' );

		$summary = $this->committee_sync()->run();

		$this->assertSame( array( array( 'uid-audit', 'hidden' ) ), Loop_Sync_Test_State::calls_to( 'agend_apps_loop_archive_committee' ) );
		$this->assertSame( 0, $summary['archived'] );
		$this->assertSame( 0, $summary['archived_empty'] );
		$this->assertSame( 1, $summary['synced'] );
		$this->assertContains( 'Archive uid-audit: gateway down', $summary['errors'] );

		$entries = $this->logged( 'error', 'Committee archive failed' );
		$this->assertCount( 1, $entries );
		$this->assertSame( 'uid-audit', $entries[0]['context']['unique_id'] );
		$this->assertSame( 'gateway down', $entries[0]['context']['error'] );
	}

	#[Test]
	public function an_empty_committee_the_filter_excludes_is_not_archived(): void {
		loop_sync_test_user( 1, '100' );
		Loop_Sync_Test_State::$upbeat_committees        = array(
			new Loop_Sync_Test_Committee( 'Board', 'uid-board' ),
			new Loop_Sync_Test_Committee( 'Audit', 'uid-audit', false ),
		);
		Loop_Sync_Test_State::$upbeat_activities['100'] = array( new Loop_Sync_Test_Activity( 'Board', 'Chair' ) );
		Loop_Sync_Test_State::$loop_committees          = array( $this->loop_committee( 'uid-board' ), $this->loop_committee( 'uid-audit' ) );

		$summary = $this->committee_sync()->run();

		$this->assertSame( array(), Loop_Sync_Test_State::calls_to( 'agend_apps_loop_archive_committee' ) );
		$this->assertSame( 0, $summary['archived'] );
		$this->assertSame( 0, $summary['archived_empty'] );
	}

	#[Test]
	public function an_empty_committee_already_archived_in_loop_is_not_archived_again(): void {
		$this->board_with_an_empty_audit();
		Loop_Sync_Test_State::$loop_committees = array( $this->loop_committee( 'uid-board' ), $this->loop_committee( 'uid-audit', true ) );

		$summary = $this->committee_sync()->run();

		$this->assertSame( array(), Loop_Sync_Test_State::calls_to( 'agend_apps_loop_archive_committee' ) );
		$this->assertSame( 0, $summary['archived'] );
		$this->assertSame( 0, $summary['archived_empty'] );
	}

	#[Test]
	public function a_merge_mode_run_archives_no_empty_committee(): void {
		$this->board_with_an_empty_audit();
		loop_sync_test_user( 2, '200' );
		Loop_Sync_Test_State::$upbeat_activities['200'] = false;
		Loop_Sync_Test_State::$loop_committees          = array( $this->loop_committee( 'uid-board' ), $this->loop_committee( 'uid-audit' ) );

		$summary = $this->committee_sync()->run();

		$this->assertSame( 'merge', $summary['mode'] );
		$this->assertSame( array(), Loop_Sync_Test_State::calls_to( 'agend_apps_loop_archive_committee' ) );
		$this->assertSame( 0, $summary['archived'] );
		$this->assertSame( 0, $summary['archived_empty'] );
	}

	#[Test]
	public function a_committee_archived_for_having_no_members_is_upserted_active_when_a_local_member_returns(): void {
		$this->board_with_an_empty_audit();
		Loop_Sync_Test_State::$loop_committees = array( $this->loop_committee( 'uid-board' ), $this->loop_committee( 'uid-audit' ) );

		$this->committee_sync()->run();

		$this->assertSame( array( array( 'uid-audit', 'hidden' ) ), Loop_Sync_Test_State::calls_to( 'agend_apps_loop_archive_committee' ) );

		// Next run: Loop now holds Audit archived, and a local member is back on it.
		Loop_Sync_Test_State::$calls                    = array();
		Loop_Sync_Test_State::$loop_committees          = array( $this->loop_committee( 'uid-board' ), $this->loop_committee( 'uid-audit', true ) );
		Loop_Sync_Test_State::$upbeat_activities['100'] = array(
			new Loop_Sync_Test_Activity( 'Board', 'Chair' ),
			new Loop_Sync_Test_Activity( 'Audit', 'Member' ),
		);

		$summary = $this->committee_sync()->run();

		$payloads = array_column( Loop_Sync_Test_State::calls_to( 'agend_apps_loop_sync_committee' ), 0 );
		$by_id    = array_column( $payloads, null, 'unique_id' );
		$this->assertSame( array( 'uid-board', 'uid-audit' ), array_keys( $by_id ) );
		$this->assertTrue( $by_id['uid-audit']['is_active'] );
		$this->assertCount( 1, $by_id['uid-audit']['roster'] );
		$this->assertSame( array(), Loop_Sync_Test_State::calls_to( 'agend_apps_loop_archive_committee' ) );
		$this->assertSame( 0, $summary['archived'] );
		$this->assertSame( 0, $summary['archived_empty'] );
	}
}
