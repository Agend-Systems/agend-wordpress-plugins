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
 * Community rule channels (`rule:` keys) belong to the rule sync. A committee
 * run must never read, update or archive one.
 */
final class CommitteeIgnoresRuleChannelsTest extends LoopSyncTestCase {

	/**
	 * @return array<string, mixed>
	 */
	private function channel( string $unique_id, bool $archived = false ): array {
		return array(
			'unique_id'   => $unique_id,
			'is_archived' => $archived,
		);
	}

	private function board(): void {
		loop_sync_test_user( 1, '100' );
		Loop_Sync_Test_State::$upbeat_committees        = array(
			new Loop_Sync_Test_Committee( 'Board', 'uid-board' ),
			new Loop_Sync_Test_Committee( 'Audit', 'uid-audit' ),
		);
		Loop_Sync_Test_State::$upbeat_activities['100'] = array( new Loop_Sync_Test_Activity( 'Board', 'Chair' ) );
	}

	/**
	 * @return string[]
	 */
	private function archived(): array {
		return array_column( Loop_Sync_Test_State::calls_to( 'agend_apps_loop_archive_committee' ), 0 );
	}

	#[Test]
	public function a_complete_committee_run_never_archives_a_rule_channel(): void {
		$this->board();
		Loop_Sync_Test_State::$loop_committees = array(
			$this->channel( 'uid-board' ),
			$this->channel( 'uid-audit' ),
			$this->channel( 'rule:all-fellows' ),
			$this->channel( 'rule:specialists' ),
			$this->channel( 'removed-upstream' ),
		);

		$summary = $this->committee_sync()->run();

		$this->assertSame( 'replace', $summary['mode'] );
		$this->assertSame( array( 'uid-audit', 'removed-upstream' ), $this->archived(), 'only committee channels are archived' );
	}

	#[Test]
	public function a_removed_upstream_sweep_leaves_rule_channels_alone(): void {
		$this->board();
		Loop_Sync_Test_State::$loop_committees = array( $this->channel( 'uid-board' ), $this->channel( 'uid-audit' ), $this->channel( 'rule:all-fellows' ) );
		Loop_Sync_Test_State::$upbeat_activities['100'] = array(
			new Loop_Sync_Test_Activity( 'Board', 'Chair' ),
			new Loop_Sync_Test_Activity( 'Audit', 'Member' ),
		);

		$this->committee_sync()->run();

		$this->assertSame( array(), $this->archived() );
	}

	#[Test]
	public function an_empty_committee_sweep_leaves_rule_channels_alone(): void {
		$this->board();
		Loop_Sync_Test_State::$loop_committees = array( $this->channel( 'uid-board' ), $this->channel( 'rule:uid-audit' ) );

		$this->committee_sync()->run();

		$this->assertSame( array(), $this->archived(), 'Audit has no member, but the live channel with the rule prefix is not its channel' );
	}

	#[Test]
	public function an_upbeat_committee_whose_id_uses_the_rule_prefix_is_not_synced(): void {
		loop_sync_test_user( 1, '100' );
		Loop_Sync_Test_State::$upbeat_committees        = array( new Loop_Sync_Test_Committee( 'Board', 'rule:board' ) );
		Loop_Sync_Test_State::$upbeat_activities['100'] = array( new Loop_Sync_Test_Activity( 'Board', 'Chair' ) );

		$summary = $this->committee_sync()->run();

		$this->assertSame( array(), Loop_Sync_Test_State::calls_to( 'agend_apps_loop_sync_committee' ) );
		$this->assertSame( 1, $summary['skipped_no_id_committees'] );
	}

	#[Test]
	public function the_committee_sync_still_works_alongside_rule_channels(): void {
		$this->board();
		Loop_Sync_Test_State::$loop_committees = array( $this->channel( 'rule:all-fellows' ) );

		$this->committee_sync()->run();

		$payloads = array_column( array_column( Loop_Sync_Test_State::calls_to( 'agend_apps_loop_sync_committee' ), 0 ), null, 'unique_id' );
		$this->assertSame( array( 'uid-board' ), array_keys( $payloads ) );
		$this->assertSame( 'replace', $payloads['uid-board']['mode'] );
	}
}
