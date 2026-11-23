<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\LoopSync;

use Agend_Loop_Sync_Logger;
use Agend_Loop_Sync_Rule_Sync;
use Agend_Loop_Sync_Settings;
use Agend_Test_WP;
use Loop_Sync_Test_Registration;
use Loop_Sync_Test_State;
use PHPUnit\Framework\Attributes\Test;

require_once __DIR__ . '/RuleTestCase.php';

/**
 * The community rule runner: rosters, modes, archiving, dry run and the summary.
 */
final class RuleSyncTest extends RuleTestCase {

	private function logged( string $needle ): bool {
		return false !== strpos( wp_json_encode( Agend_Loop_Sync_Logger::recent( 200 ) ), $needle );
	}

	#[Test]
	public function a_complete_run_sends_a_replace_roster_under_the_rule_key(): void {
		$this->add_rule( 'All Fellows', array( 'membership_types' => 'Fellow' ) );
		$this->add_member( 1, '300', 'Financial', 'Fellow' );
		$this->add_member( 2, '100', 'Renewal Due', 'Fellow' );
		$this->add_member( 3, '200', 'Financial', 'Affiliate' );

		$summary = $this->rule_sync()->run();

		$payloads = $this->payloads();
		$this->assertSame( array( 'rule:all-fellows' ), array_keys( $payloads ) );
		$this->assertSame(
			array(
				'idp_entity_id' => 'https://idp.example.com/saml/metadata',
				'unique_id'     => 'rule:all-fellows',
				'name'          => 'All Fellows',
				'is_active'     => true,
				'roster'        => array(
					array( 'external_id' => 100, 'channel_role' => 'member' ),
					array( 'external_id' => 300, 'channel_role' => 'member' ),
				),
				'mode'          => 'replace',
			),
			$payloads['rule:all-fellows']
		);
		$this->assertSame( 'replace', $summary['mode'] );
		$this->assertSame( 1, $summary['rules_synced'] );
		$this->assertSame( 3, $summary['members_scanned'] );
		$this->assertSame( 2, $summary['rules']['all-fellows']['matched'] );
	}

	#[Test]
	public function members_who_lapsed_are_left_out_and_return_when_financial_again(): void {
		$this->add_rule( 'Everyone' );
		$this->add_member( 1, '100', 'Financial' );
		$this->add_member( 2, '200', 'Reinstatable' );
		$this->add_member( 3, '300', 'Overdue' );

		$this->rule_sync()->run();
		$this->assertSame( array( 100, 300 ), $this->roster_ids( $this->payloads()['rule:everyone'] ) );

		Loop_Sync_Test_State::$calls = array();
		$this->add_member( 2, '200', 'Financial' );
		Loop_Sync_Test_State::$users = array_slice( Loop_Sync_Test_State::$users, 0, 3 );

		$this->rule_sync()->run();
		$this->assertSame( array( 100, 200, 300 ), $this->roster_ids( $this->payloads()['rule:everyone'] ) );
	}

	#[Test]
	public function always_include_members_are_added_whatever_the_criteria_and_status_say(): void {
		$this->add_rule( 'Fellows', array( 'membership_types' => 'Fellow', 'always_include' => "900 = moderator\n100" ) );
		$this->add_member( 1, '100', 'Financial', 'Affiliate' );
		$this->add_member( 2, '200', 'Financial', 'Fellow' );

		$this->rule_sync()->run();

		$this->assertSame(
			array(
				array( 'external_id' => 100, 'channel_role' => 'member' ),
				array( 'external_id' => 200, 'channel_role' => 'member' ),
				array( 'external_id' => 900, 'channel_role' => 'moderator' ),
			),
			$this->payloads()['rule:fellows']['roster']
		);
	}

	#[Test]
	public function the_highest_role_wins_when_a_member_also_meets_the_criteria(): void {
		$this->add_rule( 'Fellows', array( 'membership_types' => 'Fellow', 'always_include' => "200 = owner\n300 = member" ) );
		$this->add_member( 2, '200', 'Financial', 'Fellow' );
		$this->add_member( 3, '300', 'Financial', 'Fellow' );

		$this->rule_sync()->run();

		$roles = array_column( $this->payloads()['rule:fellows']['roster'], 'channel_role', 'external_id' );
		$this->assertSame( array( 200 => 'owner', 300 => 'member' ), $roles );
	}

	#[Test]
	public function a_member_is_fetched_once_however_many_rules_there_are(): void {
		$this->add_rule( 'One' );
		$this->add_rule( 'Two', array( 'membership_types' => 'Fellow' ) );
		$this->add_member( 1, '100', 'Financial', 'Fellow' );

		$this->rule_sync()->run();

		$this->assertCount( 1, Loop_Sync_Test_State::upbeat_calls_to( 'get_member_details_by_id' ) );
		$this->assertSame( array(), Loop_Sync_Test_State::upbeat_calls_to( 'get_member_registrations' ), 'no rule lists events, so registrations are not read' );
		$this->assertSame( array( 'rule:one', 'rule:two' ), array_keys( $this->payloads() ) );
	}

	#[Test]
	public function registrations_are_read_only_when_a_rule_lists_events(): void {
		$this->add_rule( 'Event', array( 'event_ids' => 'EV100' ) );
		$this->add_member( 1, '100', 'Financial', '', '', array( new Loop_Sync_Test_Registration( 'EV100', 'Paid' ) ) );
		$this->add_member( 2, '200', 'Financial', '', '', array( new Loop_Sync_Test_Registration( 'EV100', 'Cancelled' ) ) );
		$this->add_member( 3, '300', 'Financial', '', '', array() );

		$this->rule_sync()->run();

		$this->assertCount( 3, Loop_Sync_Test_State::upbeat_calls_to( 'get_member_registrations' ) );
		$this->assertSame( array( 100 ), $this->roster_ids( $this->payloads()['rule:event'] ) );
	}

	#[Test]
	public function a_failed_member_lookup_runs_every_rule_in_merge_mode_and_says_so(): void {
		$this->add_rule( 'One' );
		$this->add_rule( 'Two', array( 'membership_types' => 'Fellow' ) );
		$this->add_member( 1, '100', 'Financial', 'Fellow' );
		loop_sync_test_user( 2, '200' ); // No Upbeat record: the lookup fails.
		Loop_Sync_Test_State::$loop_committees = array( $this->loop_channel( 'rule:one' ), $this->loop_channel( 'rule:gone' ) );

		$summary = $this->rule_sync()->run();

		$this->assertSame( 'merge', $summary['mode'] );
		$this->assertSame( 1, $summary['failed_members'] );
		$payloads = $this->payloads();
		$this->assertSame( 'merge', $payloads['rule:one']['mode'] );
		$this->assertSame( 'merge', $payloads['rule:two']['mode'] );
		$this->assertContains( 'Upbeat data was incomplete; ran in merge mode with no removals or archiving', $summary['errors'] );
		$this->assertSame( array(), $this->archived_ids(), 'nothing is archived on incomplete data' );
		$this->assertSame( array(), Loop_Sync_Test_State::calls_to( 'agend_apps_loop_list_committees' ) );
	}

	#[Test]
	public function a_failed_registrations_lookup_also_forces_merge_mode(): void {
		$this->add_rule( 'Event', array( 'event_ids' => 'EV100' ) );
		$this->add_member( 1, '100', 'Financial', '', '', array( new Loop_Sync_Test_Registration( 'EV100' ) ) );
		$this->add_member( 2, '200' ); // Registrations unset: the lookup fails.

		$summary = $this->rule_sync()->run();

		$this->assertSame( 'merge', $summary['mode'] );
		$this->assertSame( 'merge', $this->payloads()['rule:event']['mode'] );
	}

	#[Test]
	public function a_run_that_read_no_member_runs_in_merge_mode(): void {
		$this->add_rule( 'Everyone', array( 'always_include' => '900' ) );

		$summary = $this->rule_sync()->run();

		$this->assertSame( 'merge', $summary['mode'] );
		$this->assertSame( 'merge', $this->payloads()['rule:everyone']['mode'] );
	}

	#[Test]
	public function the_gateway_counts_land_in_the_summary_per_rule(): void {
		$this->add_rule( 'Everyone' );
		$this->add_member( 1, '100' );
		$this->add_member( 2, '200' );
		Loop_Sync_Test_State::$responses['agend_apps_loop_sync_committee'] = array(
			'success' => true,
			'data'    => array(
				'applied'              => 1,
				'skipped'              => 1,
				'removed'              => 4,
				'skipped_external_ids' => array( 200 ),
				'held'                 => array( array( 'external_id' => 100, 'kind' => 'removal', 'reason' => 'Emergency' ) ),
			),
		);

		$summary = $this->rule_sync()->run();

		$stats = $summary['rules']['everyone'];
		$this->assertSame( 1, $stats['applied'] );
		$this->assertSame( 1, $stats['skipped'] );
		$this->assertSame( 4, $stats['removed'] );
		$this->assertSame( 1, $stats['held'] );
		$this->assertSame( 'synced', $stats['outcome'] );
		$this->assertSame( array( 200 ), $summary['unresolved_external_ids'] );
		$this->assertSame( 1, $summary['held_members'] );
		$this->assertSame( 'Everyone', $summary['held_results'][0]['rule'] );
		$this->assertTrue( $summary['member_counts_reported'] );
		$this->assertSame( $summary, get_option( Agend_Loop_Sync_Settings::OPT_LAST_RULE_SUMMARY ) );
		$this->assertGreaterThan( 0, (int) get_option( Agend_Loop_Sync_Settings::OPT_LAST_RULE_SYNC ) );
	}

	#[Test]
	public function a_gateway_failure_for_one_rule_does_not_stop_the_others(): void {
		$this->add_rule( 'One' );
		$this->add_rule( 'Two' );
		$this->add_member( 1, '100' );
		Loop_Sync_Test_State::$responders['agend_apps_loop_sync_committee'] = static function ( array $args ) {
			return 'rule:one' === $args[0]['unique_id'] ? new \WP_Error( 'boom', 'gateway down' ) : array( 'success' => true, 'data' => array( 'applied' => 1, 'removed' => 0 ) );
		};

		$summary = $this->rule_sync()->run();

		$this->assertSame( 'failed', $summary['rules']['one']['outcome'] );
		$this->assertSame( 'synced', $summary['rules']['two']['outcome'] );
		$this->assertContains( 'One: gateway down', $summary['errors'] );
	}

	#[Test]
	public function a_removed_or_disabled_rules_channel_is_archived_on_a_complete_run(): void {
		$this->add_rule( 'Kept' );
		$this->add_rule( 'Switched off', array( 'enabled' => false ) );
		$this->add_member( 1, '100' );
		Loop_Sync_Test_State::$loop_committees = array(
			$this->loop_channel( 'rule:kept' ),
			$this->loop_channel( 'rule:switched-off' ),
			$this->loop_channel( 'rule:deleted' ),
			$this->loop_channel( 'rule:long-gone', true ),
			$this->loop_channel( 'uid-board' ),
		);

		$summary = $this->rule_sync()->run();

		$this->assertSame( array( 'rule:switched-off', 'rule:deleted' ), $this->archived_ids() );
		$this->assertSame( 2, $summary['archived'] );
	}

	#[Test]
	public function a_dry_run_sends_nothing_and_logs_what_would_happen(): void {
		update_option( 'agend_loop_sync_dry_run', '1' );
		$this->add_rule( 'Everyone' );
		$this->add_member( 1, '100', 'Financial' );
		Loop_Sync_Test_State::$loop_committees = array( $this->loop_channel( 'rule:deleted' ) );

		$summary = $this->rule_sync()->run();

		$this->assertSame( array(), Loop_Sync_Test_State::calls_to( 'agend_apps_loop_sync_committee' ) );
		$this->assertSame( array(), Loop_Sync_Test_State::calls_to( 'agend_apps_loop_archive_committee' ) );
		$this->assertTrue( $this->logged( 'Dry run: would sync rule' ) );
		$this->assertTrue( $this->logged( 'Dry run: would archive rule channel' ) );
		$this->assertTrue( $summary['dry_run'] );
		$this->assertSame( 'dry_run', $summary['rules']['everyone']['outcome'] );
	}

	#[Test]
	public function a_rule_nobody_matches_sends_an_empty_replace_roster_and_archives_nothing(): void {
		$this->add_rule( 'Specialists', array( 'membership_types' => 'Specialist' ) );
		$this->add_member( 1, '100', 'Financial', 'Fellow' );
		Loop_Sync_Test_State::$loop_committees = array( $this->loop_channel( 'rule:specialists' ) );

		$summary = $this->rule_sync()->run();

		$payload = $this->payloads()['rule:specialists'];
		$this->assertSame( array(), $payload['roster'] );
		$this->assertSame( 'replace', $payload['mode'] );
		$this->assertTrue( $payload['is_active'] );
		$this->assertSame( array(), $this->archived_ids() );
		$this->assertSame( 'emptied', $summary['rules']['specialists']['outcome'] );
		$this->assertArrayNotHasKey( 'archived_empty', $summary );
	}

	#[Test]
	public function a_rule_nobody_matches_sends_nothing_and_archives_nothing_in_merge_mode(): void {
		$this->add_rule( 'Specialists', array( 'membership_types' => 'Specialist' ) );
		$this->add_member( 1, '100', 'Financial', 'Fellow' );
		loop_sync_test_user( 2, '200' ); // No Upbeat record: forces merge mode.
		Loop_Sync_Test_State::$loop_committees = array( $this->loop_channel( 'rule:specialists' ) );

		$summary = $this->rule_sync()->run();

		$this->assertSame( 'merge', $summary['mode'] );
		$this->assertSame( array(), Loop_Sync_Test_State::calls_to( 'agend_apps_loop_sync_committee' ) );
		$this->assertSame( array(), $this->archived_ids() );
		$this->assertSame( 'empty_skipped', $summary['rules']['specialists']['outcome'] );
	}

	#[Test]
	public function a_rule_that_matches_someone_again_is_sent_active_so_its_channel_returns(): void {
		$this->add_rule( 'Specialists', array( 'membership_types' => 'Specialist' ) );
		$this->add_member( 1, '100', 'Financial', 'Specialist' );
		Loop_Sync_Test_State::$loop_committees = array( $this->loop_channel( 'rule:specialists', true ) );

		$this->rule_sync()->run();

		$this->assertTrue( $this->payloads()['rule:specialists']['is_active'] );
		$this->assertSame( array(), $this->archived_ids() );
	}

	#[Test]
	public function the_optional_channel_flags_are_off_unless_the_filter_allows_them(): void {
		$this->add_rule( 'Locked', array( 'member_can_leave' => '0', 'allow_manual_members' => '0' ) );
		$this->add_rule( 'Open' );
		$this->add_member( 1, '100' );

		$this->rule_sync()->run();

		$payloads = $this->payloads();
		$this->assertArrayNotHasKey( 'member_can_leave', $payloads['rule:locked'] );
		$this->assertArrayNotHasKey( 'allow_manual_members', $payloads['rule:locked'] );

		Loop_Sync_Test_State::$calls = array();
		Agend_Test_WP::set_filter( 'agend_loop_sync_send_rule_channel_flags', true );

		$this->rule_sync()->run();

		$payloads = $this->payloads();
		$this->assertFalse( $payloads['rule:locked']['member_can_leave'] );
		$this->assertFalse( $payloads['rule:locked']['allow_manual_members'] );
		$this->assertTrue( $payloads['rule:open']['member_can_leave'] );
		$this->assertTrue( $payloads['rule:open']['allow_manual_members'] );
	}

	#[Test]
	public function a_roster_over_the_gateway_limit_is_not_sent(): void {
		$this->add_rule( 'Huge' );
		for ( $i = 1; $i <= 2001; $i++ ) {
			$this->add_member( $i, (string) ( 1000 + $i ) );
		}

		$summary = $this->rule_sync()->run();

		$this->assertSame( array(), Loop_Sync_Test_State::calls_to( 'agend_apps_loop_sync_committee' ) );
		$this->assertSame( 'too_large', $summary['rules']['huge']['outcome'] );
	}

	#[Test]
	public function nothing_runs_while_rule_sync_is_off(): void {
		update_option( 'agend_loop_sync_rule_sync_enabled', '0' );
		$this->add_rule( 'Everyone' );
		$this->add_member( 1, '100' );

		$summary = $this->rule_sync()->run();

		$this->assertSame( array(), Loop_Sync_Test_State::$calls );
		$this->assertSame( array(), Loop_Sync_Test_State::$upbeat_calls );
		$this->assertContains( 'Community rule sync is disabled in settings', $summary['errors'] );
		$this->assertFalse( get_option( Agend_Loop_Sync_Settings::OPT_LAST_RULE_SUMMARY, false ), 'a run that did not happen leaves the last real summary alone' );
	}

	#[Test]
	public function a_run_is_refused_while_another_holds_the_lock_and_releases_it_after(): void {
		$this->add_rule( 'Everyone' );
		$this->add_member( 1, '100' );

		set_transient( Agend_Loop_Sync_Rule_Sync::LOCK, '1', 600 );
		$summary = $this->rule_sync()->run();
		$this->assertContains( 'A community rule run is already in progress', $summary['errors'] );
		$this->assertSame( array(), Loop_Sync_Test_State::$calls );

		delete_transient( Agend_Loop_Sync_Rule_Sync::LOCK );
		$this->rule_sync()->run();
		$this->assertFalse( get_transient( Agend_Loop_Sync_Rule_Sync::LOCK ), 'the lock is released after a run' );
		$this->assertNotEmpty( $this->payloads() );
	}

	#[Test]
	public function hand_picked_channels_are_never_touched(): void {
		$this->add_rule( 'Everyone' );
		$this->add_member( 1, '100' );
		Loop_Sync_Test_State::$loop_committees = array( $this->loop_channel( 'staff-room' ), $this->loop_channel( '12345' ) );

		$this->rule_sync()->run();

		$this->assertSame( array(), $this->archived_ids() );
		$this->assertSame( array( 'rule:everyone' ), array_keys( $this->payloads() ) );
	}
}
