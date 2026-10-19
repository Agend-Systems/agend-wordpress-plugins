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
use WP_Error;

require_once __DIR__ . '/LoopSyncTestCase.php';

/**
 * Members an administrator handed over ride along on the next sync of their
 * committee as `adopt_external_ids`. The queue is settled only against outcomes
 * the gateway actually returned: a gateway that does not know about hand-over,
 * a key without the extra scope and a dry run all leave it untouched.
 */
final class AdoptionSyncTest extends LoopSyncTestCase {

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
	 * @return array<string, array<string, mixed>> Payload of every committee sync call, by unique id.
	 */
	private function synced_payloads(): array {
		$payloads = array();
		foreach ( Loop_Sync_Test_State::calls_to( 'agend_apps_loop_sync_committee' ) as $args ) {
			$payloads[ $args[0]['unique_id'] ] = $args[0];
		}

		return $payloads;
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
	 * A gateway answer that reports an outcome for each id, in the shape the
	 * contract gives.
	 *
	 * @param array<int, string> $outcomes Outcome by external id.
	 */
	private function adoption_response( array $outcomes, ?int $adopted = null ): array {
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
				'adopted'              => $adopted ?? count( array_keys( $outcomes, 'adopted', true ) ),
				'adoption_results'     => $results,
			),
		);
	}

	#[Test]
	public function the_payload_carries_the_queued_ids_for_that_committee_only(): void {
		Agend_Loop_Sync_Settings::queue_adoptions( 'uid-board', array( 200, 100 ) );
		Agend_Loop_Sync_Settings::queue_adoptions( 'uid-other', array( 999 ) );

		$this->committee_sync()->run();

		$payloads = $this->synced_payloads();
		$this->assertSame( array( 'uid-board', 'uid-audit' ), array_keys( $payloads ) );
		$this->assertSame( array( 200, 100 ), $payloads['uid-board']['adopt_external_ids'] );
		$this->assertArrayNotHasKey( 'adopt_external_ids', $payloads['uid-audit'] );
	}

	#[Test]
	public function a_committee_with_nothing_queued_sends_no_adopt_key(): void {
		$this->committee_sync()->run();

		foreach ( $this->synced_payloads() as $payload ) {
			$this->assertArrayNotHasKey( 'adopt_external_ids', $payload );
		}
	}

	#[Test]
	public function the_queued_ids_are_sent_as_integers(): void {
		update_option( Agend_Loop_Sync_Settings::OPT_ADOPT_QUEUE, array( 'uid-board' => array( '100', 200 ) ) );

		$this->committee_sync()->run();

		$this->assertSame( array( 100, 200 ), $this->synced_payloads()['uid-board']['adopt_external_ids'] );
	}

	#[Test]
	public function every_returned_id_leaves_the_queue_whatever_its_outcome(): void {
		Agend_Loop_Sync_Settings::queue_adoptions( 'uid-board', array( 100, 200, 300, 400 ) );
		Agend_Loop_Sync_Settings::queue_adoptions( 'uid-audit', array( 100 ) );
		Loop_Sync_Test_State::$responders['agend_apps_loop_sync_committee'] = fn( array $args ) => 'uid-board' === $args[0]['unique_id']
			? $this->adoption_response(
				array(
					100 => 'adopted',
					200 => 'managed_elsewhere',
					300 => 'not_in_roster',
				)
			)
			: array( 'success' => true, 'data' => array( 'applied' => 1, 'adopted' => 0, 'adoption_results' => array() ) );

		$this->committee_sync()->run();

		// 400 was sent but got no outcome, so it stays; the other committee's queue is untouched.
		$this->assertSame( array( 400 ), Agend_Loop_Sync_Settings::get_adopt_queue_for( 'uid-board' ) );
		$this->assertSame( array( 100 ), Agend_Loop_Sync_Settings::get_adopt_queue_for( 'uid-audit' ) );
	}

	#[Test]
	public function an_id_queued_while_the_run_was_in_progress_is_not_lost(): void {
		Agend_Loop_Sync_Settings::queue_adoptions( 'uid-board', array( 100 ) );
		Loop_Sync_Test_State::$responders['agend_apps_loop_sync_committee'] = function ( array $args ) {
			// An administrator queues another member while the gateway call is out.
			Agend_Loop_Sync_Settings::queue_adoptions( 'uid-board', array( 200 ) );

			return $this->adoption_response( array( 100 => 'adopted' ) );
		};

		$this->committee_sync()->run();

		$this->assertSame( array( 200 ), Agend_Loop_Sync_Settings::get_adopt_queue_for( 'uid-board' ) );
	}

	#[Test]
	public function the_summary_counts_each_outcome_and_totals_the_adopted(): void {
		Agend_Loop_Sync_Settings::queue_adoptions( 'uid-board', array( 100, 200, 300 ) );
		Agend_Loop_Sync_Settings::queue_adoptions( 'uid-audit', array( 100 ) );
		Loop_Sync_Test_State::$responders['agend_apps_loop_sync_committee'] = fn( array $args ) => 'uid-board' === $args[0]['unique_id']
			? $this->adoption_response(
				array(
					100 => 'adopted',
					200 => 'adopted',
					300 => 'already_managed',
				)
			)
			: $this->adoption_response( array( 100 => 'adopted' ) );

		$summary = $this->committee_sync()->run();

		$this->assertSame( 3, $summary['adopted'] );
		$this->assertSame(
			array(
				'adopted'         => 3,
				'already_managed' => 1,
			),
			$summary['adoption_outcomes']
		);
		$this->assertSame(
			array(
				array( 'committee' => 'Board', 'external_id' => 100, 'outcome' => 'adopted' ),
				array( 'committee' => 'Board', 'external_id' => 200, 'outcome' => 'adopted' ),
				array( 'committee' => 'Board', 'external_id' => 300, 'outcome' => 'already_managed' ),
				array( 'committee' => 'Audit', 'external_id' => 100, 'outcome' => 'adopted' ),
			),
			$summary['adoption_results']
		);
		$this->assertSame( array(), $summary['adoption_errors'] );
		$this->assertSame( 2, $summary['synced'] );
	}

	#[Test]
	public function the_adopted_total_falls_back_to_counting_adopted_outcomes(): void {
		Agend_Loop_Sync_Settings::queue_adoptions( 'uid-board', array( 100, 200 ) );
		$response = $this->adoption_response( array( 100 => 'adopted', 200 => 'inserted' ) );
		unset( $response['data']['adopted'] );
		Loop_Sync_Test_State::$responses['agend_apps_loop_sync_committee'] = $response;

		$summary = $this->committee_sync()->run();

		$this->assertSame( 1, $summary['adopted'] );
	}

	#[Test]
	public function an_outcome_the_plugin_does_not_know_is_recorded_as_unknown_and_still_settles_the_id(): void {
		Agend_Loop_Sync_Settings::queue_adoptions( 'uid-board', array( 100 ) );
		Loop_Sync_Test_State::$responses['agend_apps_loop_sync_committee'] = $this->adoption_response( array( 100 => 'quarantined' ) );

		$summary = $this->committee_sync()->run();

		$this->assertSame( array( 'unknown' => 1 ), $summary['adoption_outcomes'] );
		$this->assertSame( array(), Agend_Loop_Sync_Settings::get_adopt_queue_for( 'uid-board' ) );
	}

	#[Test]
	public function a_summary_without_a_hand_over_has_empty_adoption_fields(): void {
		$summary = $this->committee_sync()->run();

		$this->assertSame( 0, $summary['adopted'] );
		$this->assertSame( array(), $summary['adoption_outcomes'] );
		$this->assertSame( array(), $summary['adoption_results'] );
		$this->assertSame( array(), $summary['adoption_errors'] );
	}

	#[Test]
	public function the_queue_is_kept_and_the_summary_says_so_when_the_gateway_returns_no_adoption_results(): void {
		Agend_Loop_Sync_Settings::queue_adoptions( 'uid-board', array( 100, 200 ) );
		Loop_Sync_Test_State::$responses['agend_apps_loop_sync_committee'] = array(
			'success' => true,
			'data'    => array( 'applied' => 2, 'skipped' => 0, 'removed' => 0, 'skipped_external_ids' => array() ),
		);

		$summary = $this->committee_sync()->run();

		$this->assertSame( array( 100, 200 ), Agend_Loop_Sync_Settings::get_adopt_queue_for( 'uid-board' ) );
		$this->assertSame( 0, $summary['adopted'] );
		$this->assertCount( 1, $summary['adoption_errors'] );
		$this->assertStringContainsString( 'does not support hand-over', $summary['adoption_errors'][0] );
		$this->assertContains( $summary['adoption_errors'][0], $summary['errors'] );
		$this->assertSame( 2, $summary['synced'] );
		$this->assertCount( 1, $this->logged( 'warning', 'hand-over not confirmed' ) );
	}

	#[Test]
	public function the_queue_is_kept_and_the_scope_named_when_the_key_lacks_loop_committees_update(): void {
		Agend_Loop_Sync_Settings::queue_adoptions( 'uid-board', array( 100 ) );
		Loop_Sync_Test_State::$responders['agend_apps_loop_sync_committee'] = fn( array $args ) => isset( $args[0]['adopt_external_ids'] )
			? new WP_Error( 'agend_api_error', 'Forbidden', array( 'status_code' => 403 ) )
			: array( 'success' => true, 'data' => array( 'applied' => 1, 'skipped' => 0, 'removed' => 0, 'skipped_external_ids' => array() ) );

		$summary = $this->committee_sync()->run();

		$this->assertSame( array( 100 ), Agend_Loop_Sync_Settings::get_adopt_queue_for( 'uid-board' ) );
		$this->assertCount( 1, $summary['adoption_errors'] );
		$this->assertStringContainsString( 'loop.committees.update', $summary['adoption_errors'][0] );
		$this->assertStringContainsString( 'Board', $summary['adoption_errors'][0] );
		$this->assertContains( $summary['adoption_errors'][0], $summary['errors'] );
		$this->assertSame( 0, $summary['adopted'] );
		$this->assertCount( 1, $this->logged( 'error', 'loop.committees.update' ) );
	}

	#[Test]
	public function a_refused_hand_over_does_not_stop_the_roster_from_syncing(): void {
		Agend_Loop_Sync_Settings::queue_adoptions( 'uid-board', array( 100 ) );
		Loop_Sync_Test_State::$responders['agend_apps_loop_sync_committee'] = fn( array $args ) => isset( $args[0]['adopt_external_ids'] )
			? new WP_Error( 'agend_api_error', 'Forbidden', array( 'status_code' => 403 ) )
			: array( 'success' => true, 'data' => array( 'applied' => 2, 'skipped' => 0, 'removed' => 0, 'skipped_external_ids' => array() ) );

		$summary = $this->committee_sync()->run();

		$board_calls = array_values(
			array_filter(
				Loop_Sync_Test_State::calls_to( 'agend_apps_loop_sync_committee' ),
				static fn( $args ) => 'uid-board' === $args[0]['unique_id']
			)
		);
		$this->assertCount( 2, $board_calls );
		$this->assertSame( array( 100 ), $board_calls[0][0]['adopt_external_ids'] );
		$this->assertArrayNotHasKey( 'adopt_external_ids', $board_calls[1][0] );
		$this->assertSame( $board_calls[0][0]['roster'], $board_calls[1][0]['roster'] );
		$this->assertSame( 2, $summary['synced'] );
		$this->assertSame( 4, $summary['applied_members'] );
	}

	#[Test]
	public function a_403_that_persists_without_the_hand_over_fails_the_committee_and_keeps_the_queue(): void {
		Agend_Loop_Sync_Settings::queue_adoptions( 'uid-board', array( 100 ) );
		Loop_Sync_Test_State::$responses['agend_apps_loop_sync_committee'] = new WP_Error( 'agend_api_error', 'Forbidden', array( 'status_code' => 403 ) );

		$summary = $this->committee_sync()->run();

		$this->assertSame( array( 100 ), Agend_Loop_Sync_Settings::get_adopt_queue_for( 'uid-board' ) );
		$this->assertSame( 0, $summary['synced'] );
		$this->assertContains( 'Board: Forbidden', $summary['errors'] );
		$this->assertContains( 'Audit: Forbidden', $summary['errors'] );
	}

	#[Test]
	public function a_403_is_read_from_a_local_scope_refusal_too(): void {
		Agend_Loop_Sync_Settings::queue_adoptions( 'uid-board', array( 100 ) );
		Loop_Sync_Test_State::$responders['agend_apps_loop_sync_committee'] = fn( array $args ) => isset( $args[0]['adopt_external_ids'] )
			? new WP_Error( 'agend_apps_scope_missing', 'Blocked locally', array( 'status_code' => 403, 'missing' => array( 'loop.committees.update' ) ) )
			: array( 'success' => true, 'data' => array( 'applied' => 1 ) );

		$summary = $this->committee_sync()->run();

		$this->assertCount( 1, $summary['adoption_errors'] );
		$this->assertSame( array( 100 ), Agend_Loop_Sync_Settings::get_adopt_queue_for( 'uid-board' ) );
	}

	#[Test]
	public function a_failure_other_than_403_keeps_the_queue_without_blaming_the_scope(): void {
		Agend_Loop_Sync_Settings::queue_adoptions( 'uid-board', array( 100 ) );
		Loop_Sync_Test_State::$responders['agend_apps_loop_sync_committee'] = fn( array $args ) => new WP_Error( 'agend_api_error', 'Server error', array( 'status_code' => 500 ) );

		$summary = $this->committee_sync()->run();

		$this->assertSame( array( 100 ), Agend_Loop_Sync_Settings::get_adopt_queue_for( 'uid-board' ) );
		$this->assertSame( array(), $summary['adoption_errors'] );
		$this->assertContains( 'Board: Server error', $summary['errors'] );
		$this->assertCount( 2, Loop_Sync_Test_State::calls_to( 'agend_apps_loop_sync_committee' ), 'No retry: one call per committee.' );
	}

	#[Test]
	public function a_transport_error_with_no_status_keeps_the_queue(): void {
		Agend_Loop_Sync_Settings::queue_adoptions( 'uid-board', array( 100 ) );
		Loop_Sync_Test_State::$responses['agend_apps_loop_sync_committee'] = new WP_Error( 'http_request_failed', 'Timed out' );

		$summary = $this->committee_sync()->run();

		$this->assertSame( array( 100 ), Agend_Loop_Sync_Settings::get_adopt_queue_for( 'uid-board' ) );
		$this->assertSame( array(), $summary['adoption_errors'] );
	}

	#[Test]
	public function a_dry_run_sends_nothing_logs_what_would_be_sent_and_keeps_the_queue(): void {
		update_option( 'agend_loop_sync_dry_run', '1' );
		Agend_Loop_Sync_Settings::queue_adoptions( 'uid-board', array( 100, 200 ) );

		$summary = $this->committee_sync()->run();

		$this->assertSame( array(), Loop_Sync_Test_State::calls_to( 'agend_apps_loop_sync_committee' ) );
		$this->assertSame( array( 100, 200 ), Agend_Loop_Sync_Settings::get_adopt_queue_for( 'uid-board' ) );
		$this->assertSame( 0, $summary['adopted'] );
		$this->assertSame( array(), $summary['adoption_errors'] );

		$dry_runs = array_values(
			array_filter(
				$this->logged( 'info', 'Dry run: would sync committee' ),
				static fn( $entry ) => 'uid-board' === $entry['context']['unique_id']
			)
		);
		$this->assertCount( 1, $dry_runs );
		$this->assertSame( 2, $dry_runs[0]['context']['adopt_total'] );
		$this->assertSame( array( 100, 200 ), $dry_runs[0]['context']['adopt_external_ids'] );
	}

	#[Test]
	public function a_dry_run_log_for_a_committee_with_no_queue_has_no_adopt_context(): void {
		update_option( 'agend_loop_sync_dry_run', '1' );

		$this->committee_sync()->run();

		foreach ( $this->logged( 'info', 'Dry run: would sync committee' ) as $entry ) {
			$this->assertArrayNotHasKey( 'adopt_total', $entry['context'] );
		}
	}

	#[Test]
	public function an_empty_adoption_results_list_settles_nothing_and_is_not_an_unsupported_gateway(): void {
		Agend_Loop_Sync_Settings::queue_adoptions( 'uid-board', array( 100 ) );
		Loop_Sync_Test_State::$responses['agend_apps_loop_sync_committee'] = $this->adoption_response( array() );

		$summary = $this->committee_sync()->run();

		$this->assertSame( array( 100 ), Agend_Loop_Sync_Settings::get_adopt_queue_for( 'uid-board' ) );
		$this->assertSame( array(), $summary['adoption_errors'] );
	}

	#[Test]
	public function the_log_holds_membership_numbers_and_outcomes_and_no_personal_data(): void {
		Agend_Loop_Sync_Settings::queue_adoptions( 'uid-board', array( 100, 200 ) );
		Loop_Sync_Test_State::$responses['agend_apps_loop_sync_committee'] = $this->adoption_response(
			array(
				100 => 'adopted',
				200 => 'managed_elsewhere',
			)
		);

		$this->committee_sync()->run();

		$entries = $this->logged( 'info', 'hand-over processed' );
		$this->assertCount( 1, $entries );
		$this->assertSame(
			array(
				'adopted'           => 1,
				'managed_elsewhere' => 1,
			),
			$entries[0]['context']['outcomes']
		);
		$this->assertSame(
			array(
				100 => 'adopted',
				200 => 'managed_elsewhere',
			),
			$entries[0]['context']['results']
		);
		$this->assertStringNotContainsString( 'chair@example.com', wp_json_encode( Agend_Loop_Sync_Logger::recent() ) );
		$this->assertStringNotContainsString( 'Member 1', wp_json_encode( Agend_Loop_Sync_Logger::recent() ) );
	}

	#[Test]
	public function a_large_hand_over_keeps_only_a_capped_detail_list_in_the_stored_summary(): void {
		$ids = range( 1000, 1149 );
		Agend_Loop_Sync_Settings::queue_adoptions( 'uid-board', $ids );
		Loop_Sync_Test_State::$responses['agend_apps_loop_sync_committee'] = $this->adoption_response( array_fill_keys( $ids, 'adopted' ) );

		$summary = $this->committee_sync()->run();
		$stored  = get_option( Agend_Loop_Sync_Settings::OPT_LAST_SUMMARY );

		$this->assertSame( 150, $summary['adopted'] );
		$this->assertSame( array( 'adopted' => 150 ), $summary['adoption_outcomes'] );
		$this->assertCount( 100, $stored['adoption_results'] );
		$this->assertSame( 150, $stored['adoption_results_total'] );
		$this->assertSame( array(), Agend_Loop_Sync_Settings::get_adopt_queue_for( 'uid-board' ) );

		$logged = $this->logged( 'info', 'hand-over processed' );
		$this->assertSame( array( 'adopted' => 150 ), $logged[0]['context']['outcomes'] );
		$this->assertCount( 25, $logged[0]['context']['results'] );
	}
}
