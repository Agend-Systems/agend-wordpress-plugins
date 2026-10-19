<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\LoopSync;

use Agend_Loop_Sync_Logger;
use Agend_Loop_Sync_Settings;
use Loop_Sync_Test_Activity;
use Loop_Sync_Test_Committee;
use Loop_Sync_Test_State;
use PHPUnit\Framework\Attributes\Test;

require_once __DIR__ . '/LoopSyncTestCase.php';

/**
 * A Loop administrator can remove a member from a synced channel, or cap their
 * role, in an emergency. The gateway then skips or caps that member in the sync
 * and reports them as `held`. The plugin records who, why and since when in the
 * run summary and the log, and treats a hand-over of a held member as decided.
 */
final class HoldSyncTest extends LoopSyncTestCase {

	protected function setUp(): void {
		parent::setUp();

		loop_sync_test_user( 1, '100', 'chair@example.com' );
		loop_sync_test_user( 2, '200' );
		Loop_Sync_Test_State::$upbeat_committees        = array(
			new Loop_Sync_Test_Committee( 'Board', 'uid-board' ),
			new Loop_Sync_Test_Committee( 'Audit', 'uid-audit' ),
		);
		Loop_Sync_Test_State::$upbeat_activities['100'] = array(
			new Loop_Sync_Test_Activity( 'Board', 'Chair' ),
			new Loop_Sync_Test_Activity( 'Audit', 'Member' ),
		);
		Loop_Sync_Test_State::$upbeat_activities['200'] = array( new Loop_Sync_Test_Activity( 'Board', 'Member' ) );
	}

	/**
	 * @return array<int, array<string, mixed>> Log entries at $level whose message contains $fragment.
	 */
	private function logged( string $level, string $fragment ): array {
		return array_values(
			array_filter(
				Agend_Loop_Sync_Logger::recent( 200 ),
				static fn( $entry ) => $level === $entry['level'] && false !== strpos( $entry['message'], $fragment )
			)
		);
	}

	/**
	 * One entry of `held`, in the shape the contract gives.
	 */
	private function hold( int $external_id, string $kind = 'removal', ?string $role_cap = null, string $reason = 'Under review', string $held_since = '2026-10-19T01:30:00Z' ): array {
		return array(
			'external_id' => $external_id,
			'kind'        => $kind,
			'role_cap'    => $role_cap,
			'reason'      => $reason,
			'held_since'  => $held_since,
		);
	}

	/**
	 * A committee sync answer that reports the given holds.
	 *
	 * @param array<int, array<string, mixed>> $held Entries of `held`.
	 */
	private function held_response( array $held ): array {
		return array(
			'success' => true,
			'data'    => array(
				'applied'              => 1,
				'skipped'              => 0,
				'removed'              => 0,
				'skipped_external_ids' => array(),
				'held'                 => $held,
			),
		);
	}

	#[Test]
	public function held_members_are_read_into_the_summary_with_their_committee(): void {
		Loop_Sync_Test_State::$responders['agend_apps_loop_sync_committee'] = fn( array $args ) => 'uid-board' === $args[0]['unique_id']
			? $this->held_response(
				array(
					$this->hold( 200, 'removal', null, 'Conduct complaint', '2026-10-19T01:30:00Z' ),
					$this->hold( 100, 'role_cap', 'member', 'Pending review', '2026-10-18T22:00:00Z' ),
				)
			)
			: $this->held_response( array() );

		$summary = $this->committee_sync()->run();

		$this->assertSame( 2, $summary['held_members'] );
		$this->assertSame(
			array(
				array(
					'committee'   => 'Board',
					'external_id' => 200,
					'kind'        => 'removal',
					'role_cap'    => '',
					'reason'      => 'Conduct complaint',
					'held_since'  => '2026-10-19T01:30:00Z',
				),
				array(
					'committee'   => 'Board',
					'external_id' => 100,
					'kind'        => 'role_cap',
					'role_cap'    => 'member',
					'reason'      => 'Pending review',
					'held_since'  => '2026-10-18T22:00:00Z',
				),
			),
			$summary['held_results']
		);
		$this->assertSame( 2, $summary['synced'], 'A hold does not fail the committee.' );
		$this->assertSame( array(), $summary['errors'] );
	}

	#[Test]
	public function held_members_are_totalled_across_committees(): void {
		Loop_Sync_Test_State::$responders['agend_apps_loop_sync_committee'] = fn( array $args ) => $this->held_response(
			array( $this->hold( 'uid-board' === $args[0]['unique_id'] ? 200 : 100 ) )
		);

		$summary = $this->committee_sync()->run();

		$this->assertSame( 2, $summary['held_members'] );
		$this->assertSame( array( 'Board', 'Audit' ), array_column( $summary['held_results'], 'committee' ) );
		$this->assertSame( array( 200, 100 ), array_column( $summary['held_results'], 'external_id' ) );
	}

	#[Test]
	public function each_held_member_is_logged_with_the_hold_but_not_its_reason_or_personal_data(): void {
		Loop_Sync_Test_State::$responses['agend_apps_loop_sync_committee'] = $this->held_response(
			array(
				$this->hold( 100, 'role_cap', 'moderator', 'Pending review', '2026-10-18T22:00:00Z' ),
			)
		);

		$this->committee_sync()->run();

		$entries = array_values(
			array_filter(
				$this->logged( 'info', 'held by a Loop administrator' ),
				static fn( $entry ) => 'Board' === $entry['context']['committee']
			)
		);
		$this->assertCount( 1, $entries );
		$this->assertSame(
			array(
				'committee'   => 'Board',
				'external_id' => 100,
				'kind'        => 'role_cap',
				'role_cap'    => 'moderator',
				'held_since'  => '2026-10-18T22:00:00Z',
			),
			$entries[0]['context']
		);
		$this->assertStringNotContainsString( 'Pending review', wp_json_encode( Agend_Loop_Sync_Logger::recent() ) );
		$this->assertStringNotContainsString( 'chair@example.com', wp_json_encode( Agend_Loop_Sync_Logger::recent() ) );
		$this->assertStringNotContainsString( 'Member 1', wp_json_encode( Agend_Loop_Sync_Logger::recent() ) );
	}

	#[Test]
	public function a_response_without_held_is_read_as_no_holds(): void {
		// A gateway that does not know about holds sends no `held` at all.
		Loop_Sync_Test_State::$responses['agend_apps_loop_sync_committee'] = array(
			'success' => true,
			'data'    => array( 'applied' => 1, 'skipped' => 0, 'removed' => 0, 'skipped_external_ids' => array() ),
		);

		$summary = $this->committee_sync()->run();

		$this->assertSame( 0, $summary['held_members'] );
		$this->assertSame( array(), $summary['held_results'] );
		$this->assertSame( array(), $summary['errors'] );
		$this->assertSame( 2, $summary['synced'] );
		$this->assertSame( array(), $this->logged( 'info', 'held by a Loop administrator' ) );
	}

	#[Test]
	public function an_empty_held_list_adds_nothing(): void {
		Loop_Sync_Test_State::$responses['agend_apps_loop_sync_committee'] = $this->held_response( array() );

		$summary = $this->committee_sync()->run();

		$this->assertSame( 0, $summary['held_members'] );
		$this->assertSame( array(), $summary['held_results'] );
		$this->assertSame( array(), $this->logged( 'info', 'held by a Loop administrator' ) );
	}

	#[Test]
	public function a_held_value_that_is_not_a_list_is_read_as_no_holds(): void {
		Loop_Sync_Test_State::$responses['agend_apps_loop_sync_committee'] = array(
			'success' => true,
			'data'    => array( 'applied' => 1, 'held' => 'none' ),
		);

		$summary = $this->committee_sync()->run();

		$this->assertSame( 0, $summary['held_members'] );
		$this->assertSame( 2, $summary['synced'] );
	}

	#[Test]
	public function held_is_read_from_an_unwrapped_response_too(): void {
		Loop_Sync_Test_State::$responses['agend_apps_loop_sync_committee'] = $this->held_response( array( $this->hold( 200 ) ) )['data'];

		$summary = $this->committee_sync()->run();

		$this->assertSame( 2, $summary['held_members'] );
	}

	#[Test]
	public function an_entry_that_names_no_member_is_ignored_and_an_unknown_kind_is_kept_as_unknown(): void {
		Loop_Sync_Test_State::$responses['agend_apps_loop_sync_committee'] = $this->held_response(
			array(
				'not an entry',
				array( 'kind' => 'removal', 'reason' => 'No id' ),
				$this->hold( 0 ),
				$this->hold( 200, 'quarantine', 'superuser', 'Odd', '' ),
			)
		);

		$summary = $this->committee_sync()->run();

		// Two committees receive the same answer, each with the one usable entry.
		$this->assertSame( 2, $summary['held_members'] );
		$this->assertSame( 'unknown', $summary['held_results'][0]['kind'] );
		$this->assertSame( '', $summary['held_results'][0]['role_cap'], 'A role the plugin does not know is dropped.' );
		$this->assertSame( 200, $summary['held_results'][0]['external_id'] );
	}

	#[Test]
	public function a_hold_with_non_scalar_values_is_read_without_notices(): void {
		Loop_Sync_Test_State::$responses['agend_apps_loop_sync_committee'] = $this->held_response(
			array(
				array(
					'external_id' => array( 300 ),
					'kind'        => 'removal',
				),
				array(
					'external_id' => 301,
					'kind'        => array( 'removal' ),
					'role_cap'    => array( 'member' ),
					'reason'      => array( 'text' ),
					'held_since'  => array( 'when' ),
				),
			)
		);

		$summary = $this->committee_sync()->run();

		$this->assertSame( 301, $summary['held_results'][0]['external_id'], 'An array external id is ignored.' );
		$this->assertSame( 'unknown', $summary['held_results'][0]['kind'] );
		$this->assertSame( '', $summary['held_results'][0]['role_cap'] );
		$this->assertSame( '', $summary['held_results'][0]['reason'] );
		$this->assertSame( '', $summary['held_results'][0]['held_since'] );
	}

	#[Test]
	public function a_long_reason_is_cut_and_a_short_one_is_trimmed(): void {
		Loop_Sync_Test_State::$responders['agend_apps_loop_sync_committee'] = fn( array $args ) => 'uid-board' === $args[0]['unique_id']
			? $this->held_response(
				array(
					$this->hold( 100, 'removal', null, str_repeat( 'a', 600 ) ),
					$this->hold( 200, 'removal', null, '  Short reason  ' ),
				)
			)
			: $this->held_response( array() );

		$summary = $this->committee_sync()->run();

		$this->assertSame( str_repeat( 'a', 500 ) . '...', $summary['held_results'][0]['reason'] );
		$this->assertSame( 'Short reason', $summary['held_results'][1]['reason'] );
	}

	#[Test]
	public function a_summary_without_holds_has_empty_hold_fields(): void {
		$summary = $this->committee_sync()->run();

		$this->assertSame( 0, $summary['held_members'] );
		$this->assertSame( array(), $summary['held_results'] );
	}

	#[Test]
	public function a_large_number_of_holds_keeps_a_capped_detail_list_in_the_stored_summary_and_the_log(): void {
		$held = array();
		foreach ( range( 1000, 1149 ) as $external_id ) {
			$held[] = $this->hold( $external_id );
		}
		Loop_Sync_Test_State::$responders['agend_apps_loop_sync_committee'] = fn( array $args ) => 'uid-board' === $args[0]['unique_id']
			? $this->held_response( $held )
			: $this->held_response( array() );

		$summary = $this->committee_sync()->run();
		$stored  = get_option( Agend_Loop_Sync_Settings::OPT_LAST_SUMMARY );

		$this->assertSame( 150, $summary['held_members'] );
		$this->assertSame( 150, $stored['held_members'] );
		$this->assertCount( 100, $stored['held_results'] );

		$this->assertCount( 25, $this->logged( 'info', 'Committee member held by a Loop administrator' ) );
		$more = $this->logged( 'info', 'More held members than the log lists' );
		$this->assertCount( 1, $more );
		$this->assertSame( 150, $more[0]['context']['held'] );
		$this->assertSame( 25, $more[0]['context']['logged'] );
	}

	#[Test]
	public function the_run_complete_entry_leaves_the_hold_reasons_to_the_per_member_entries(): void {
		Loop_Sync_Test_State::$responses['agend_apps_loop_sync_committee'] = $this->held_response( array( $this->hold( 100, 'removal', null, 'Private detail' ) ) );

		$this->committee_sync()->run();

		$complete = $this->logged( 'info', 'Committee sync run complete' );
		$this->assertCount( 1, $complete );
		$this->assertSame( 2, $complete[0]['context']['held_members'] );
		$this->assertArrayNotHasKey( 'held_results', $complete[0]['context'] );
	}

	#[Test]
	public function a_dry_run_sends_nothing_so_it_reports_no_holds(): void {
		update_option( 'agend_loop_sync_dry_run', '1' );

		$summary = $this->committee_sync()->run();

		$this->assertSame( 0, $summary['held_members'] );
		$this->assertSame( array(), $summary['held_results'] );
	}

	#[Test]
	public function a_failed_committee_reports_no_holds(): void {
		Loop_Sync_Test_State::$responses['agend_apps_loop_sync_committee'] = new \WP_Error( 'agend_api_error', 'Server error', array( 'status_code' => 500 ) );

		$summary = $this->committee_sync()->run();

		$this->assertSame( 0, $summary['held_members'] );
		$this->assertSame( 0, $summary['synced'] );
	}

	/**
	 * @param array<int, string> $outcomes Outcome by external id.
	 */
	private function adoption_response( array $outcomes, array $held = array() ): array {
		$results = array();
		foreach ( $outcomes as $external_id => $outcome ) {
			$results[] = array(
				'external_id' => $external_id,
				'outcome'     => $outcome,
			);
		}

		return array(
			'success' => true,
			'data'    => array(
				'applied'              => 1,
				'skipped'              => 0,
				'removed'              => 0,
				'skipped_external_ids' => array(),
				'adopted'              => count( array_keys( $outcomes, 'adopted', true ) ),
				'adoption_results'     => $results,
				'held'                 => $held,
			),
		);
	}

	#[Test]
	public function a_held_hand_over_is_a_known_outcome_and_leaves_the_queue(): void {
		Agend_Loop_Sync_Settings::queue_adoptions( 'uid-board', array( 100, 200 ) );
		Loop_Sync_Test_State::$responders['agend_apps_loop_sync_committee'] = fn( array $args ) => 'uid-board' === $args[0]['unique_id']
			? $this->adoption_response(
				array(
					100 => 'adopted',
					200 => 'held',
				),
				array( $this->hold( 200 ) )
			)
			: $this->adoption_response( array() );

		$summary = $this->committee_sync()->run();

		$this->assertSame( array( 'adopted' => 1, 'held' => 1 ), $summary['adoption_outcomes'] );
		$this->assertSame( 1, $summary['adopted'] );
		$this->assertSame(
			array(
				array( 'committee' => 'Board', 'external_id' => 100, 'outcome' => 'adopted' ),
				array( 'committee' => 'Board', 'external_id' => 200, 'outcome' => 'held' ),
			),
			$summary['adoption_results']
		);
		$this->assertSame( array(), Agend_Loop_Sync_Settings::get_adopt_queue_for( 'uid-board' ), 'A held member is decided, so it leaves the queue like any other outcome.' );
		$this->assertSame( 1, $summary['held_members'] );
	}

	#[Test]
	public function a_held_outcome_is_logged_as_held_and_not_as_unknown(): void {
		Agend_Loop_Sync_Settings::queue_adoptions( 'uid-board', array( 200 ) );
		Loop_Sync_Test_State::$responses['agend_apps_loop_sync_committee'] = $this->adoption_response( array( 200 => 'held' ) );

		$this->committee_sync()->run();

		$entries = $this->logged( 'info', 'hand-over processed' );
		$this->assertCount( 1, $entries );
		$this->assertSame( array( 'held' => 1 ), $entries[0]['context']['outcomes'] );
		$this->assertSame( array( 200 => 'held' ), $entries[0]['context']['results'] );
	}
}
