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
 * Loop channels are keyed by Upbeat's stable committee unique id, not the
 * committee name, so a rename keeps its channel. Activities carry the name
 * only, and Upbeat caches the committee list longer than the activities, so a
 * name the list does not know yet must never be synced or archived around.
 */
final class StableCommitteeIdTest extends LoopSyncTestCase {

	private function loop_committee( string $unique_id, string $name = '', bool $archived = false ): array {
		return array(
			'unique_id'   => $unique_id,
			'name'        => '' === $name ? $unique_id : $name,
			'channel_id'  => 'c-' . $unique_id,
			'slug'        => $unique_id,
			'is_archived' => $archived,
		);
	}

	/**
	 * @return array<int, array<string, mixed>> Payload of every committee sync call, in order.
	 */
	private function synced_payloads(): array {
		return array_column( Loop_Sync_Test_State::calls_to( 'agend_apps_loop_sync_committee' ), 0 );
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

	#[Test]
	public function a_committee_syncs_with_its_upbeat_unique_id_and_its_name(): void {
		loop_sync_test_user( 1, '100' );
		Loop_Sync_Test_State::$upbeat_committees        = array( new Loop_Sync_Test_Committee( 'Executive Board', 'uid-9f3' ) );
		Loop_Sync_Test_State::$upbeat_activities['100'] = array( new Loop_Sync_Test_Activity( 'Executive Board', 'Chair' ) );

		$summary = $this->committee_sync()->run();

		$payloads = $this->synced_payloads();
		$this->assertCount( 1, $payloads );
		$this->assertSame( 'uid-9f3', $payloads[0]['unique_id'] );
		$this->assertSame( 'Executive Board', $payloads[0]['name'] );
		$this->assertSame( 1, $summary['synced'] );
		$this->assertSame( 0, $summary['skipped_unlisted_committees'] );
		$this->assertSame( 0, $summary['skipped_no_id_committees'] );
	}

	#[Test]
	public function the_unique_id_is_trimmed(): void {
		loop_sync_test_user( 1, '100' );
		Loop_Sync_Test_State::$upbeat_committees        = array( new Loop_Sync_Test_Committee( 'Board', '  uid-board ' ) );
		Loop_Sync_Test_State::$upbeat_activities['100'] = array( new Loop_Sync_Test_Activity( 'Board', 'Chair' ) );

		$this->committee_sync()->run();

		$this->assertSame( 'uid-board', $this->synced_payloads()[0]['unique_id'] );
	}

	#[Test]
	public function a_renamed_committee_keeps_its_channel_and_nothing_is_archived(): void {
		loop_sync_test_user( 1, '100' );
		Loop_Sync_Test_State::$upbeat_committees        = array( new Loop_Sync_Test_Committee( 'Executive Committee', 'uid-board' ) );
		Loop_Sync_Test_State::$upbeat_activities['100'] = array( new Loop_Sync_Test_Activity( 'Executive Committee', 'Chair' ) );
		Loop_Sync_Test_State::$loop_committees          = array( $this->loop_committee( 'uid-board', 'Board' ) );

		$summary = $this->committee_sync()->run();

		$payloads = $this->synced_payloads();
		$this->assertCount( 1, $payloads );
		$this->assertSame( 'uid-board', $payloads[0]['unique_id'] );
		$this->assertSame( 'Executive Committee', $payloads[0]['name'] );
		$this->assertSame( 'replace', $payloads[0]['mode'] );
		$this->assertSame( array(), Loop_Sync_Test_State::calls_to( 'agend_apps_loop_archive_committee' ) );
		$this->assertSame( 0, $summary['archived'] );
	}

	#[Test]
	public function a_committee_in_activities_but_not_in_the_list_is_not_synced_and_the_run_only_merges(): void {
		loop_sync_test_user( 1, '100', 'private@example.com' );
		Loop_Sync_Test_State::$upbeat_committees        = array(
			new Loop_Sync_Test_Committee( 'Board', 'uid-board' ),
			new Loop_Sync_Test_Committee( 'Audit', 'uid-audit' ),
		);
		Loop_Sync_Test_State::$upbeat_activities['100'] = array(
			new Loop_Sync_Test_Activity( 'Board', 'Chair' ),
			new Loop_Sync_Test_Activity( 'Brand New Committee', 'Member' ),
		);
		// Audit has no roster this run and gone is absent upstream: on complete
		// data both would be archived.
		Loop_Sync_Test_State::$loop_committees          = array(
			$this->loop_committee( 'uid-board' ),
			$this->loop_committee( 'uid-audit' ),
			$this->loop_committee( 'gone' ),
		);

		$summary = $this->committee_sync()->run();

		$payloads = $this->synced_payloads();
		$this->assertSame( array( 'uid-board' ), array_column( $payloads, 'unique_id' ) );
		$this->assertSame( 'merge', $payloads[0]['mode'] );
		$this->assertSame( 'merge', $summary['mode'] );
		$this->assertSame( 1, $summary['skipped_unlisted_committees'] );
		$this->assertSame( array(), Loop_Sync_Test_State::calls_to( 'agend_apps_loop_archive_committee' ) );
		$this->assertSame( array(), Loop_Sync_Test_State::calls_to( 'agend_apps_loop_list_committees' ) );
		$this->assertSame( 0, $summary['archived'] );
		$this->assertContains(
			'Upbeat committee list is out of date compared with member activities; ran in merge mode with no removals or archiving',
			$summary['errors']
		);

		$warnings = $this->logged( 'warning', 'missing from the committee list' );
		$this->assertCount( 1, $warnings );
		$this->assertSame( array( 'Brand New Committee' ), $warnings[0]['context']['committees'] );
		$this->assertStringNotContainsString( 'private@example.com', wp_json_encode( Agend_Loop_Sync_Logger::recent() ) );
	}

	#[Test]
	public function a_committee_list_still_holding_the_old_name_after_a_rename_is_treated_as_behind(): void {
		loop_sync_test_user( 1, '100' );
		Loop_Sync_Test_State::$upbeat_committees        = array( new Loop_Sync_Test_Committee( 'Board', 'uid-board' ) );
		Loop_Sync_Test_State::$upbeat_activities['100'] = array( new Loop_Sync_Test_Activity( 'Executive Committee', 'Chair' ) );
		Loop_Sync_Test_State::$loop_committees          = array( $this->loop_committee( 'uid-board' ), $this->loop_committee( 'gone' ) );

		$summary = $this->committee_sync()->run();

		$this->assertSame( array(), $this->synced_payloads() );
		$this->assertSame( 'merge', $summary['mode'] );
		$this->assertSame( 1, $summary['skipped_unlisted_committees'] );
		$this->assertSame( array(), Loop_Sync_Test_State::calls_to( 'agend_apps_loop_archive_committee' ) );
	}

	#[Test]
	public function a_committee_with_an_empty_unique_id_is_skipped_without_forcing_merge_mode(): void {
		loop_sync_test_user( 1, '100' );
		Loop_Sync_Test_State::$upbeat_committees        = array(
			new Loop_Sync_Test_Committee( 'Board', '' ),
			new Loop_Sync_Test_Committee( 'Audit', 'uid-audit' ),
		);
		Loop_Sync_Test_State::$upbeat_activities['100'] = array(
			new Loop_Sync_Test_Activity( 'Board', 'Chair' ),
			new Loop_Sync_Test_Activity( 'Audit', 'Member' ),
		);
		Loop_Sync_Test_State::$responses['agend_apps_loop_sync_committee'] = array(
			'success' => true,
			'data'    => array( 'applied' => 1, 'skipped' => 0, 'removed' => 0, 'skipped_external_ids' => array() ),
		);

		$summary = $this->committee_sync()->run();

		$payloads = $this->synced_payloads();
		$this->assertSame( array( 'uid-audit' ), array_column( $payloads, 'unique_id' ) );
		$this->assertSame( 'replace', $payloads[0]['mode'] );
		$this->assertSame( 'replace', $summary['mode'] );
		$this->assertSame( 1, $summary['skipped_no_id_committees'] );
		$this->assertSame( 0, $summary['skipped_unlisted_committees'] );
		$this->assertSame( array(), $summary['errors'] );

		$warnings = $this->logged( 'warning', 'no usable Upbeat unique id' );
		$this->assertCount( 1, $warnings );
		$this->assertSame( 'Board', $warnings[0]['context']['committee'] );
	}

	#[Test]
	public function a_committee_object_without_a_unique_id_method_counts_as_having_none(): void {
		loop_sync_test_user( 1, '100' );
		Loop_Sync_Test_State::$upbeat_committees        = array(
			new class() {
				public function get_name(): string {
					return 'Board';
				}
			},
		);
		Loop_Sync_Test_State::$upbeat_activities['100'] = array( new Loop_Sync_Test_Activity( 'Board', 'Chair' ) );

		$summary = $this->committee_sync()->run();

		$this->assertSame( array(), $this->synced_payloads() );
		$this->assertSame( 1, $summary['skipped_no_id_committees'] );
	}

	#[Test]
	public function committees_sharing_a_name_are_skipped_as_ambiguous_and_their_ids_are_not_archived(): void {
		loop_sync_test_user( 1, '100' );
		Loop_Sync_Test_State::$upbeat_committees        = array(
			new Loop_Sync_Test_Committee( 'Board', 'uid-board-a' ),
			new Loop_Sync_Test_Committee( 'Audit', 'uid-audit' ),
			new Loop_Sync_Test_Committee( 'Board', 'uid-board-b' ),
		);
		Loop_Sync_Test_State::$upbeat_activities['100'] = array(
			new Loop_Sync_Test_Activity( 'Board', 'Chair' ),
			new Loop_Sync_Test_Activity( 'Audit', 'Member' ),
		);
		Loop_Sync_Test_State::$loop_committees          = array(
			$this->loop_committee( 'uid-board-a' ),
			$this->loop_committee( 'uid-board-b' ),
			$this->loop_committee( 'uid-audit' ),
			$this->loop_committee( 'gone' ),
		);

		$summary = $this->committee_sync()->run();

		$payloads = $this->synced_payloads();
		$this->assertSame( array( 'uid-audit' ), array_column( $payloads, 'unique_id' ) );
		$this->assertSame( 'replace', $payloads[0]['mode'] );
		$this->assertSame( 1, $summary['skipped_no_id_committees'] );
		$this->assertSame( array( array( 'gone', 'hidden' ) ), Loop_Sync_Test_State::calls_to( 'agend_apps_loop_archive_committee' ) );

		$warnings = $this->logged( 'warning', 'sharing a name' );
		$this->assertCount( 1, $warnings );
		$this->assertSame( array( 'Board' ), $warnings[0]['context']['committees'] );
	}

	#[Test]
	public function an_ambiguous_committee_without_a_roster_is_not_archived(): void {
		loop_sync_test_user( 1, '100' );
		Loop_Sync_Test_State::$upbeat_committees        = array(
			new Loop_Sync_Test_Committee( 'Board', 'uid-board-a' ),
			new Loop_Sync_Test_Committee( 'Board', 'uid-board-b' ),
			new Loop_Sync_Test_Committee( 'Audit', 'uid-audit' ),
		);
		Loop_Sync_Test_State::$upbeat_activities['100'] = array( new Loop_Sync_Test_Activity( 'Audit', 'Member' ) );
		Loop_Sync_Test_State::$loop_committees          = array(
			$this->loop_committee( 'uid-board-a' ),
			$this->loop_committee( 'uid-board-b' ),
			$this->loop_committee( 'uid-audit' ),
		);

		$summary = $this->committee_sync()->run();

		$this->assertSame( array( 'uid-audit' ), array_column( $this->synced_payloads(), 'unique_id' ) );
		$this->assertSame( array(), Loop_Sync_Test_State::calls_to( 'agend_apps_loop_archive_committee' ) );
		$this->assertSame( 0, $summary['archived_empty'] );
	}

	#[Test]
	public function a_loop_committee_whose_unique_id_is_not_in_the_list_is_archived_on_a_complete_run(): void {
		loop_sync_test_user( 1, '100' );
		Loop_Sync_Test_State::$upbeat_committees        = array( new Loop_Sync_Test_Committee( 'Board', 'uid-board' ) );
		Loop_Sync_Test_State::$upbeat_activities['100'] = array( new Loop_Sync_Test_Activity( 'Board', 'Chair' ) );
		// "board" is what the old name-slug keying would have used: it is not an
		// Upbeat id, so it is no longer current.
		Loop_Sync_Test_State::$loop_committees          = array(
			$this->loop_committee( 'uid-board' ),
			$this->loop_committee( 'board' ),
			$this->loop_committee( 'uid-removed' ),
		);

		$summary = $this->committee_sync()->run();

		$this->assertSame( 'replace', $summary['mode'] );
		$this->assertSame(
			array( array( 'board', 'hidden' ), array( 'uid-removed', 'hidden' ) ),
			Loop_Sync_Test_State::calls_to( 'agend_apps_loop_archive_committee' )
		);
		$this->assertSame( 2, $summary['archived'] );
	}

	#[Test]
	public function a_committee_emptied_of_local_members_is_archived_by_its_unique_id(): void {
		loop_sync_test_user( 1, '100' );
		Loop_Sync_Test_State::$upbeat_committees        = array(
			new Loop_Sync_Test_Committee( 'Board', 'uid-board' ),
			new Loop_Sync_Test_Committee( 'Audit', 'uid-audit' ),
		);
		Loop_Sync_Test_State::$upbeat_activities['100'] = array( new Loop_Sync_Test_Activity( 'Board', 'Chair' ) );
		Loop_Sync_Test_State::$loop_committees          = array(
			$this->loop_committee( 'uid-board' ),
			$this->loop_committee( 'uid-audit' ),
			// The old slug-keyed channel for Audit is not matched as the emptied
			// committee: it is archived as removed upstream.
			$this->loop_committee( 'audit' ),
		);

		$summary = $this->committee_sync()->run();

		$this->assertSame( array( 'uid-board' ), array_column( $this->synced_payloads(), 'unique_id' ) );
		$this->assertSame(
			array( array( 'uid-audit', 'hidden' ), array( 'audit', 'hidden' ) ),
			Loop_Sync_Test_State::calls_to( 'agend_apps_loop_archive_committee' )
		);
		$this->assertSame( 2, $summary['archived'] );
		$this->assertSame( 1, $summary['archived_empty'] );
	}

	#[Test]
	public function a_dry_run_logs_the_unique_id_it_would_sync(): void {
		update_option( 'agend_loop_sync_dry_run', '1' );
		loop_sync_test_user( 1, '100' );
		Loop_Sync_Test_State::$upbeat_committees        = array( new Loop_Sync_Test_Committee( 'Board', 'uid-board' ) );
		Loop_Sync_Test_State::$upbeat_activities['100'] = array( new Loop_Sync_Test_Activity( 'Board', 'Chair' ) );

		$this->committee_sync()->run();

		$entries = $this->logged( 'info', 'Dry run: would sync committee' );
		$this->assertCount( 1, $entries );
		$this->assertSame( 'uid-board', $entries[0]['context']['unique_id'] );
		$this->assertSame( array(), Loop_Sync_Test_State::calls_to( 'agend_apps_loop_sync_committee' ) );
	}

	#[Test]
	public function the_committee_catalogue_includes_each_unique_id(): void {
		Loop_Sync_Test_State::$upbeat_committees = array(
			new Loop_Sync_Test_Committee( 'Board', ' uid-board ' ),
			new Loop_Sync_Test_Committee( 'Audit', '' ),
		);

		$preview = $this->committee_sync()->inspect();

		$this->assertSame( array( 'uid-board', '' ), array_column( $preview['committees'], 'unique_id' ) );
		$this->assertSame( array( 'Board', 'Audit' ), array_column( $preview['committees'], 'name' ) );
	}
}
