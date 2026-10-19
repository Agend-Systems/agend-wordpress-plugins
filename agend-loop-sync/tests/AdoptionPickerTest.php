<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\LoopSync;

use Agend_Loop_Sync_Committee_Sync;
use Agend_Loop_Sync_Settings;
use Loop_Sync_Test_Activity;
use Loop_Sync_Test_Committee;
use Loop_Sync_Test_State;
use PHPUnit\Framework\Attributes\Test;
use WP_Error;

require_once __DIR__ . '/LoopSyncTestCase.php';

/**
 * The member picker joins a committee's local roster members (from Upbeat) to
 * what Loop says about the committee channel, by membership number, and decides
 * who can be handed to the sync and what that would do to their role.
 */
final class AdoptionPickerTest extends LoopSyncTestCase {

	protected function setUp(): void {
		parent::setUp();

		loop_sync_test_user( 1, '100' );
		loop_sync_test_user( 2, '200' );
		loop_sync_test_user( 3, '300' );
		loop_sync_test_user( 4, 'ABC123' );
		loop_sync_test_user( 5, '500' );
		loop_sync_test_user( 6, null );

		Loop_Sync_Test_State::$upbeat_committees = array(
			new Loop_Sync_Test_Committee( 'Board', 'uid-board' ),
			new Loop_Sync_Test_Committee( 'Audit', 'uid-audit' ),
		);

		// 100 holds two roles on the Board; the higher one must win.
		Loop_Sync_Test_State::$upbeat_activities['100']    = array(
			new Loop_Sync_Test_Activity( 'Board', 'Member' ),
			new Loop_Sync_Test_Activity( 'Board', 'Chair' ),
		);
		Loop_Sync_Test_State::$upbeat_activities['200']    = array( new Loop_Sync_Test_Activity( 'Board', 'Member' ) );
		Loop_Sync_Test_State::$upbeat_activities['300']    = array( new Loop_Sync_Test_Activity( 'Audit', 'Member' ) );
		Loop_Sync_Test_State::$upbeat_activities['ABC123'] = array( new Loop_Sync_Test_Activity( 'Board', 'Member' ) );
		Loop_Sync_Test_State::$upbeat_activities['500']    = array( new Loop_Sync_Test_Activity( 'Board', 'Secretary' ) );
	}

	/**
	 * @param array<int, array<string, mixed>> $members Members as the gateway reports them.
	 */
	private function loop_members( array $members, int $unlinked = 0 ): array {
		return array(
			'success' => true,
			'data'    => array(
				'unique_id'      => 'uid-board',
				'members'        => $members,
				'unlinked_count' => $unlinked,
			),
		);
	}

	private function loop_member( int $external_id, string $role, string $managed_by ): array {
		return array(
			'external_id' => $external_id,
			'role'        => $role,
			'managed_by'  => $managed_by,
		);
	}

	/**
	 * Joins one local member (given a channel role and membership number) to the
	 * Loop member list, and returns the single row.
	 *
	 * @param array<int, array<string, mixed>> $loop_members Members as the gateway reports them.
	 */
	private function join_one( string $membership_number, string $channel_role, array $loop_members ): array {
		$rows = Agend_Loop_Sync_Committee_Sync::join_adoption_members(
			array(
				array(
					'wp_id'             => 1,
					'name'              => 'A Member',
					'membership_number' => $membership_number,
					'committee_role'    => 'Role',
					'channel_role'      => $channel_role,
				),
			),
			$loop_members
		);

		$this->assertCount( 1, $rows );

		return $rows[0];
	}

	#[Test]
	public function the_picker_lists_the_local_members_of_that_committee_with_their_roles_joined_to_loop(): void {
		Loop_Sync_Test_State::$responses['agend_apps_loop_get_committee_members'] = $this->loop_members(
			array(
				$this->loop_member( 100, 'member', 'none' ),
				$this->loop_member( 200, 'owner', 'none' ),
				$this->loop_member( 500, 'moderator', 'wordpress_sync' ),
				$this->loop_member( 999, 'owner', 'none' ),
			),
			3
		);

		$picker = $this->committee_sync()->adoption_picker( 'uid-board' );

		$this->assertSame( '', $picker['error'] );
		$this->assertSame( 'uid-board', $picker['unique_id'] );
		$this->assertSame( 'Board', $picker['committee'] );
		$this->assertSame( 3, $picker['unlinked_count'] );

		// 300 is only on Audit and user 6 has no membership number: neither is listed.
		$by_number = array_column( $picker['members'], null, 'membership_number' );
		$this->assertEqualsCanonicalizing( array( '100', '200', '500', 'ABC123' ), array_keys( $by_number ) );

		$this->assertSame( 'Member 1', $by_number['100']['name'] );
		$this->assertSame( 'Chair', $by_number['100']['committee_role'], 'The higher of two roles wins.' );
		$this->assertSame( 'owner', $by_number['100']['channel_role'] );
		$this->assertSame( 'member', $by_number['100']['loop_role'] );
		$this->assertSame( 'none', $by_number['100']['managed_by'] );
		$this->assertTrue( $by_number['100']['selectable'] );
		$this->assertSame( 100, $by_number['100']['external_id'] );

		$this->assertSame( 'Secretary', $by_number['500']['committee_role'] );
		$this->assertSame( 'moderator', $by_number['500']['channel_role'] );
		$this->assertFalse( $by_number['500']['selectable'] );
		$this->assertSame( 'already_managed', $by_number['500']['reason'] );
	}

	#[Test]
	public function the_picker_asks_loop_about_this_committee_and_this_sites_idp(): void {
		$this->committee_sync()->adoption_picker( 'uid-board' );

		$this->assertSame(
			array( array( 'uid-board', 'https://idp.example.com/saml/metadata' ) ),
			Loop_Sync_Test_State::calls_to( 'agend_apps_loop_get_committee_members' )
		);
	}

	#[Test]
	public function the_picker_reads_an_unwrapped_gateway_response_too(): void {
		Loop_Sync_Test_State::$responses['agend_apps_loop_get_committee_members'] = array(
			'unique_id'      => 'uid-board',
			'members'        => array( $this->loop_member( 200, 'member', 'none' ) ),
			'unlinked_count' => 1,
		);

		$picker = $this->committee_sync()->adoption_picker( 'uid-board' );

		$this->assertSame( '', $picker['error'] );
		$this->assertSame( 1, $picker['unlinked_count'] );
		$this->assertSame( array( '200' ), array_column( array_filter( $picker['members'], static fn( $row ) => $row['selectable'] ), 'membership_number' ) );
	}

	#[Test]
	public function a_member_nothing_manages_who_has_a_loop_membership_is_selectable(): void {
		$row = $this->join_one( '100', 'member', array( $this->loop_member( 100, 'member', 'none' ) ) );

		$this->assertTrue( $row['selectable'] );
		$this->assertSame( '', $row['reason'] );
		$this->assertSame( 'member', $row['loop_role'] );
		$this->assertSame( 'none', $row['managed_by'] );
		$this->assertSame( 100, $row['external_id'] );
	}

	#[Test]
	public function a_membership_the_sync_already_manages_is_not_selectable(): void {
		$row = $this->join_one( '100', 'member', array( $this->loop_member( 100, 'member', 'wordpress_sync' ) ) );

		$this->assertFalse( $row['selectable'] );
		$this->assertSame( 'already_managed', $row['reason'] );
		$this->assertSame( 'wordpress_sync', $row['managed_by'] );
	}

	#[Test]
	public function a_membership_managed_by_a_group_is_not_selectable(): void {
		$row = $this->join_one( '100', 'member', array( $this->loop_member( 100, 'member', 'group' ) ) );

		$this->assertFalse( $row['selectable'] );
		$this->assertSame( 'managed_by_group', $row['reason'] );
	}

	#[Test]
	public function a_membership_managed_by_upbeat_is_not_selectable(): void {
		$row = $this->join_one( '100', 'member', array( $this->loop_member( 100, 'member', 'upbeat' ) ) );

		$this->assertFalse( $row['selectable'] );
		$this->assertSame( 'managed_by_upbeat', $row['reason'] );
	}

	#[Test]
	public function a_manager_the_plugin_does_not_know_is_treated_as_managed_elsewhere(): void {
		$row = $this->join_one( '100', 'member', array( $this->loop_member( 100, 'member', 'some_future_source' ) ) );

		$this->assertFalse( $row['selectable'] );
		$this->assertSame( 'managed_elsewhere', $row['reason'] );
	}

	#[Test]
	public function a_member_loop_does_not_list_has_no_loop_membership_and_cannot_be_selected(): void {
		$row = $this->join_one( '100', 'owner', array( $this->loop_member( 999, 'member', 'none' ) ) );

		$this->assertFalse( $row['selectable'] );
		$this->assertSame( 'no_loop_membership', $row['reason'] );
		$this->assertSame( '', $row['loop_role'] );
		$this->assertSame( '', $row['managed_by'] );
		$this->assertFalse( $row['demotion'] );
	}

	#[Test]
	public function a_membership_number_the_gateway_cannot_use_is_shown_and_cannot_be_selected(): void {
		$row = $this->join_one( 'ABC123', 'member', array( $this->loop_member( 123, 'member', 'none' ) ) );

		$this->assertFalse( $row['selectable'] );
		$this->assertSame( 'number_unusable', $row['reason'] );
		$this->assertNull( $row['external_id'] );
		$this->assertSame( 'ABC123', $row['membership_number'] );
	}

	#[Test]
	public function a_roster_role_below_the_current_loop_role_is_flagged_as_a_demotion(): void {
		$cases = array(
			'owner to member'     => array( 'member', 'owner' ),
			'owner to moderator'  => array( 'moderator', 'owner' ),
			'admin to moderator'  => array( 'moderator', 'admin' ),
			'admin to member'     => array( 'member', 'admin' ),
			'moderator to member' => array( 'member', 'moderator' ),
		);

		foreach ( $cases as $label => list( $channel_role, $loop_role ) ) {
			$row = $this->join_one( '100', $channel_role, array( $this->loop_member( 100, $loop_role, 'none' ) ) );

			$this->assertTrue( $row['demotion'], $label );
			$this->assertTrue( $row['selectable'], $label );
		}
	}

	#[Test]
	public function an_equal_or_higher_roster_role_is_not_a_demotion(): void {
		$cases = array(
			'member to owner'        => array( 'owner', 'member' ),
			'moderator to owner'     => array( 'owner', 'moderator' ),
			'admin to owner'         => array( 'owner', 'admin' ),
			'moderator to moderator' => array( 'moderator', 'moderator' ),
			'member to member'       => array( 'member', 'member' ),
			'owner to owner'         => array( 'owner', 'owner' ),
		);

		foreach ( $cases as $label => list( $channel_role, $loop_role ) ) {
			$row = $this->join_one( '100', $channel_role, array( $this->loop_member( 100, $loop_role, 'none' ) ) );

			$this->assertFalse( $row['demotion'], $label );
		}
	}

	#[Test]
	public function a_row_that_cannot_be_selected_is_never_flagged_as_a_demotion(): void {
		$row = $this->join_one( '100', 'member', array( $this->loop_member( 100, 'owner', 'group' ) ) );

		$this->assertFalse( $row['selectable'] );
		$this->assertFalse( $row['demotion'] );
	}

	#[Test]
	public function rows_are_sorted_by_name_then_membership_number(): void {
		$local = array(
			array( 'wp_id' => 1, 'name' => 'beth', 'membership_number' => '2', 'committee_role' => 'Member', 'channel_role' => 'member' ),
			array( 'wp_id' => 2, 'name' => 'Adam', 'membership_number' => '9', 'committee_role' => 'Member', 'channel_role' => 'member' ),
			array( 'wp_id' => 3, 'name' => 'Adam', 'membership_number' => '3', 'committee_role' => 'Member', 'channel_role' => 'member' ),
		);

		$rows = Agend_Loop_Sync_Committee_Sync::join_adoption_members( $local, array() );

		$this->assertSame( array( '3', '9', '2' ), array_column( $rows, 'membership_number' ) );
	}

	#[Test]
	public function a_gateway_error_gives_a_message_and_no_table(): void {
		Loop_Sync_Test_State::$responses['agend_apps_loop_get_committee_members'] = new WP_Error( 'agend_api_error', 'Gateway exploded', array( 'status_code' => 500 ) );

		$picker = $this->committee_sync()->adoption_picker( 'uid-board' );

		$this->assertStringContainsString( 'Gateway exploded', $picker['error'] );
		$this->assertSame( array(), $picker['members'] );
	}

	#[Test]
	public function a_missing_channel_says_to_run_a_committee_sync_first(): void {
		Loop_Sync_Test_State::$responses['agend_apps_loop_get_committee_members'] = new WP_Error( 'agend_api_error', 'Not found', array( 'status_code' => 404 ) );

		$picker = $this->committee_sync()->adoption_picker( 'uid-board' );

		$this->assertStringContainsString( 'no channel for this committee yet', $picker['error'] );
		$this->assertSame( array(), $picker['members'] );
	}

	#[Test]
	public function a_refused_read_names_the_update_scope(): void {
		Loop_Sync_Test_State::$responses['agend_apps_loop_get_committee_members'] = new WP_Error( 'agend_api_error', 'Forbidden', array( 'status_code' => 403 ) );

		$picker = $this->committee_sync()->adoption_picker( 'uid-board' );

		$this->assertStringContainsString( 'loop.committees.update', $picker['error'] );
		$this->assertSame( array(), $picker['members'] );
	}

	#[Test]
	public function a_response_without_a_member_list_gives_an_error_and_no_table(): void {
		Loop_Sync_Test_State::$responses['agend_apps_loop_get_committee_members'] = array( 'success' => true, 'data' => array( 'unique_id' => 'uid-board' ) );

		$picker = $this->committee_sync()->adoption_picker( 'uid-board' );

		$this->assertNotSame( '', $picker['error'] );
		$this->assertSame( array(), $picker['members'] );
	}

	#[Test]
	public function a_failed_member_lookup_in_upbeat_gives_an_error_and_never_a_partial_table(): void {
		Loop_Sync_Test_State::$upbeat_activities['200'] = false;

		$picker = $this->committee_sync()->adoption_picker( 'uid-board' );

		$this->assertNotSame( '', $picker['error'] );
		$this->assertSame( array(), $picker['members'] );
		$this->assertSame( array(), Loop_Sync_Test_State::calls_to( 'agend_apps_loop_get_committee_members' ) );
	}

	#[Test]
	public function an_unreadable_committee_list_gives_an_error_without_asking_loop(): void {
		Loop_Sync_Test_State::$upbeat_committees = false;

		$picker = $this->committee_sync()->adoption_picker( 'uid-board' );

		$this->assertNotSame( '', $picker['error'] );
		$this->assertSame( array(), Loop_Sync_Test_State::calls_to( 'agend_apps_loop_get_committee_members' ) );
	}

	#[Test]
	public function an_unknown_unique_id_gives_an_error_without_asking_loop(): void {
		$picker = $this->committee_sync()->adoption_picker( 'uid-nope' );

		$this->assertNotSame( '', $picker['error'] );
		$this->assertSame( array(), $picker['members'] );
		$this->assertSame( array(), Loop_Sync_Test_State::calls_to( 'agend_apps_loop_get_committee_members' ) );
	}

	#[Test]
	public function a_committee_name_shared_by_two_committees_cannot_be_picked(): void {
		Loop_Sync_Test_State::$upbeat_committees[] = new Loop_Sync_Test_Committee( 'Board', 'uid-board-2' );

		$picker = $this->committee_sync()->adoption_picker( 'uid-board' );

		$this->assertNotSame( '', $picker['error'] );
		$this->assertSame( array(), Loop_Sync_Test_State::calls_to( 'agend_apps_loop_get_committee_members' ) );
	}

	#[Test]
	public function the_picker_makes_no_write_call(): void {
		$this->committee_sync()->adoption_picker( 'uid-board' );

		$this->assertSame( array(), Loop_Sync_Test_State::calls_to( 'agend_apps_loop_sync_committee' ) );
		$this->assertSame( array(), Loop_Sync_Test_State::calls_to( 'agend_apps_loop_archive_committee' ) );
		$this->assertSame( array(), Agend_Loop_Sync_Settings::get_adopt_queue() );
	}

	#[Test]
	public function only_numbers_the_picker_allowed_can_be_queued(): void {
		Loop_Sync_Test_State::$responses['agend_apps_loop_get_committee_members'] = $this->loop_members(
			array(
				$this->loop_member( 100, 'member', 'none' ),
				$this->loop_member( 200, 'member', 'group' ),
				$this->loop_member( 500, 'member', 'none' ),
			)
		);
		$picker = $this->committee_sync()->adoption_picker( 'uid-board' );

		$ids = Agend_Loop_Sync_Committee_Sync::selectable_external_ids( $picker, 'uid-board', array( '100', '200', '500', '300', '999', 'junk', '100' ) );

		$this->assertSame( array( 100, 500 ), $ids );
	}

	#[Test]
	public function a_selection_posted_for_another_committee_than_the_picker_was_loaded_for_is_refused(): void {
		Loop_Sync_Test_State::$responses['agend_apps_loop_get_committee_members'] = $this->loop_members( array( $this->loop_member( 100, 'member', 'none' ) ) );
		$picker = $this->committee_sync()->adoption_picker( 'uid-board' );

		$this->assertSame( array(), Agend_Loop_Sync_Committee_Sync::selectable_external_ids( $picker, 'uid-audit', array( '100' ) ) );
	}

	#[Test]
	public function nothing_can_be_queued_from_a_picker_that_failed(): void {
		Loop_Sync_Test_State::$responses['agend_apps_loop_get_committee_members'] = new WP_Error( 'agend_api_error', 'Down', array( 'status_code' => 500 ) );
		$picker = $this->committee_sync()->adoption_picker( 'uid-board' );

		$this->assertSame( array(), Agend_Loop_Sync_Committee_Sync::selectable_external_ids( $picker, 'uid-board', array( '100' ) ) );
	}

	#[Test]
	public function the_committee_list_holds_the_committees_the_sync_covers_by_name(): void {
		Loop_Sync_Test_State::$upbeat_committees = array(
			new Loop_Sync_Test_Committee( 'Zeta', 'uid-zeta' ),
			new Loop_Sync_Test_Committee( 'alpha', 'uid-alpha' ),
			new Loop_Sync_Test_Committee( 'No Id', '' ),
			new Loop_Sync_Test_Committee( 'Twin', 'uid-twin-1' ),
			new Loop_Sync_Test_Committee( 'Twin', 'uid-twin-2' ),
			new Loop_Sync_Test_Committee( 'Hidden', 'uid-hidden', false ),
		);

		$result = $this->committee_sync()->adoption_committees();

		$this->assertSame( '', $result['error'] );
		$this->assertSame(
			array(
				array( 'unique_id' => 'uid-alpha', 'name' => 'alpha' ),
				array( 'unique_id' => 'uid-zeta', 'name' => 'Zeta' ),
			),
			$result['committees']
		);
	}

	#[Test]
	public function the_committee_list_follows_the_committee_filter(): void {
		update_option( Agend_Loop_Sync_Settings::OPT_FILTER_MODE, 'allowlist' );
		update_option( Agend_Loop_Sync_Settings::OPT_FILTER_ALLOWLIST, array( 'audit' ) );

		$result = $this->committee_sync()->adoption_committees();

		$this->assertSame( array( 'uid-audit' ), array_column( $result['committees'], 'unique_id' ) );
	}

	#[Test]
	public function an_unreadable_committee_list_gives_an_error_and_no_committees(): void {
		Loop_Sync_Test_State::$upbeat_committees = false;

		$result = $this->committee_sync()->adoption_committees();

		$this->assertNotSame( '', $result['error'] );
		$this->assertSame( array(), $result['committees'] );
	}
}
