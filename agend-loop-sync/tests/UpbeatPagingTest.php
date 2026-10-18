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
 * Upbeat pages its committee list and each member's activities. A page that is
 * missed must never read as the whole answer: a truncated committee list makes
 * rosters look unlisted and hides committees from archiving decisions, and a
 * truncated activity list makes a member look as if they left a committee.
 * Committee pages are small because each one embeds its roster; member
 * activities keep the membership plugin's unpaged first call and cache entry.
 */
final class UpbeatPagingTest extends LoopSyncTestCase {

	private const ABORTED = 'Could not read committees from Upbeat; run aborted before any change';

	private function committee_name( int $number ): string {
		return sprintf( 'Committee %02d', $number );
	}

	private function committee_id( int $number ): string {
		return sprintf( 'uid-%02d', $number );
	}

	/**
	 * @return Loop_Sync_Test_Committee[] Committees 1 to $count, in name order.
	 */
	private function committees( int $count ): array {
		$committees = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$committees[] = new Loop_Sync_Test_Committee( $this->committee_name( $i ), $this->committee_id( $i ) );
		}

		return $committees;
	}

	private function loop_committee( string $unique_id ): array {
		return array(
			'unique_id'   => $unique_id,
			'name'        => $unique_id,
			'channel_id'  => 'c-' . $unique_id,
			'slug'        => $unique_id,
			'is_archived' => false,
		);
	}

	private function page_size( int $size ): void {
		add_filter( 'agend_loop_sync_committee_page_size', static fn() => $size );
	}

	/**
	 * @return array<int, string> Unique id of every committee sync call, in order.
	 */
	private function synced_ids(): array {
		return array_column( array_column( Loop_Sync_Test_State::calls_to( 'agend_apps_loop_sync_committee' ), 0 ), 'unique_id' );
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

	private function assert_aborted_before_any_change( array $summary ): void {
		$this->assertContains( self::ABORTED, $summary['errors'] );
		$this->assertSame( array(), Loop_Sync_Test_State::$calls );
		$this->assertSame( array(), Loop_Sync_Test_State::upbeat_calls_to( 'get_member_committee_activities' ) );
		$this->assertSame( 0, $summary['archived'] );
	}

	#[Test]
	public function a_committee_list_over_several_pages_is_fully_indexed_and_a_later_page_committee_is_synced_not_archived(): void {
		loop_sync_test_user( 1, '100' );
		Loop_Sync_Test_State::$upbeat_committees        = $this->committees( 12 );
		Loop_Sync_Test_State::$upbeat_activities['100'] = array(
			new Loop_Sync_Test_Activity( $this->committee_name( 1 ), 'Chair' ),
			new Loop_Sync_Test_Activity( $this->committee_name( 12 ), 'Member' ),
		);
		Loop_Sync_Test_State::$loop_committees          = array(
			$this->loop_committee( $this->committee_id( 1 ) ),
			$this->loop_committee( $this->committee_id( 12 ) ),
			$this->loop_committee( 'gone' ),
		);

		$summary = $this->committee_sync()->run();

		$this->assertCount( 3, Loop_Sync_Test_State::upbeat_calls_to( 'get_committees' ) );
		$this->assertSame( array( 'uid-01', 'uid-12' ), $this->synced_ids() );
		$this->assertSame( 0, $summary['skipped_unlisted_committees'] );
		$this->assertSame( 'replace', $summary['mode'] );
		$this->assertSame( array( array( 'gone', 'hidden' ) ), Loop_Sync_Test_State::calls_to( 'agend_apps_loop_archive_committee' ) );
		$this->assertSame( 1, $summary['archived'] );
	}

	#[Test]
	public function the_committee_call_passes_the_page_size_the_page_number_and_the_name_sort(): void {
		Loop_Sync_Test_State::$upbeat_committees = $this->committees( 7 );

		$this->committee_sync()->run();

		$this->assertSame(
			array(
				array( '5', '1', 'name', 'asc' ),
				array( '5', '2', 'name', 'asc' ),
			),
			Loop_Sync_Test_State::upbeat_calls_to( 'get_committees' )
		);
	}

	#[Test]
	public function the_committee_page_size_filter_is_honoured(): void {
		$this->page_size( 2 );
		loop_sync_test_user( 1, '100' );
		Loop_Sync_Test_State::$upbeat_committees        = $this->committees( 7 );
		Loop_Sync_Test_State::$upbeat_activities['100'] = array( new Loop_Sync_Test_Activity( $this->committee_name( 7 ), 'Member' ) );

		$summary = $this->committee_sync()->run();

		$calls = Loop_Sync_Test_State::upbeat_calls_to( 'get_committees' );
		$this->assertSame( array( '2', '2', '2', '2' ), array_column( $calls, 0 ) );
		$this->assertSame( array( '1', '2', '3', '4' ), array_column( $calls, 1 ) );
		$this->assertSame( array( 'uid-07' ), $this->synced_ids() );
		$this->assertSame( 'replace', $summary['mode'] );
	}

	#[Test]
	public function a_committee_page_size_below_one_is_raised_to_one(): void {
		$this->page_size( -3 );
		Loop_Sync_Test_State::$upbeat_committees = $this->committees( 2 );

		$this->committee_sync()->run();

		$this->assertSame(
			array(
				array( '1', '1', 'name', 'asc' ),
				array( '1', '2', 'name', 'asc' ),
			),
			Loop_Sync_Test_State::upbeat_calls_to( 'get_committees' )
		);
	}

	#[Test]
	public function a_failed_later_committee_page_aborts_the_run_before_any_gateway_call(): void {
		loop_sync_test_user( 1, '100' );
		Loop_Sync_Test_State::$upbeat_committees        = $this->committees( 12 );
		Loop_Sync_Test_State::$upbeat_activities['100'] = array( new Loop_Sync_Test_Activity( $this->committee_name( 1 ), 'Chair' ) );
		Loop_Sync_Test_State::$loop_committees          = array( $this->loop_committee( $this->committee_id( 1 ) ), $this->loop_committee( 'gone' ) );
		// The kiosk returns an empty collection, cached for 10 minutes, for a failed call.
		Loop_Sync_Test_State::$upbeat_committee_pages   = array( 2 => array( 'rows' => array() ) );

		$summary = $this->committee_sync()->run();

		$this->assert_aborted_before_any_change( $summary );
		$this->assertCount( 2, Loop_Sync_Test_State::upbeat_calls_to( 'get_committees' ) );
	}

	#[Test]
	public function a_later_committee_page_that_is_not_a_collection_aborts_the_run(): void {
		loop_sync_test_user( 1, '100' );
		Loop_Sync_Test_State::$upbeat_committees        = $this->committees( 12 );
		Loop_Sync_Test_State::$upbeat_activities['100'] = array( new Loop_Sync_Test_Activity( $this->committee_name( 1 ), 'Chair' ) );
		Loop_Sync_Test_State::$upbeat_committee_pages   = array( 3 => false );

		$summary = $this->committee_sync()->run();

		$this->assert_aborted_before_any_change( $summary );
	}

	#[Test]
	public function a_total_that_differs_from_the_committees_collected_aborts_the_run(): void {
		loop_sync_test_user( 1, '100' );
		Loop_Sync_Test_State::$upbeat_committees        = $this->committees( 7 );
		Loop_Sync_Test_State::$upbeat_committee_total   = 99;
		Loop_Sync_Test_State::$upbeat_activities['100'] = array( new Loop_Sync_Test_Activity( $this->committee_name( 1 ), 'Chair' ) );
		Loop_Sync_Test_State::$loop_committees          = array( $this->loop_committee( $this->committee_id( 1 ) ), $this->loop_committee( 'gone' ) );

		$summary = $this->committee_sync()->run();

		$this->assert_aborted_before_any_change( $summary );
	}

	#[Test]
	public function a_committee_skipped_across_a_page_boundary_aborts_the_run(): void {
		$this->page_size( 2 );
		loop_sync_test_user( 1, '100' );
		$committees                                     = $this->committees( 4 );
		Loop_Sync_Test_State::$upbeat_committees        = $committees;
		Loop_Sync_Test_State::$upbeat_activities['100'] = array( new Loop_Sync_Test_Activity( $this->committee_name( 1 ), 'Chair' ) );
		// The list shifted between the two pages: committee 2 comes round again and
		// committee 4 was never seen, yet the rows still add up to the reported 4.
		Loop_Sync_Test_State::$upbeat_committee_pages   = array(
			2 => array(
				'rows'  => array( $committees[1], $committees[2] ),
				'more'  => false,
				'total' => 4,
			),
		);

		$summary = $this->committee_sync()->run();

		$this->assert_aborted_before_any_change( $summary );
	}

	#[Test]
	public function a_total_reported_as_zero_is_not_checked(): void {
		loop_sync_test_user( 1, '100' );
		Loop_Sync_Test_State::$upbeat_committees        = $this->committees( 7 );
		Loop_Sync_Test_State::$upbeat_committee_total   = 0;
		Loop_Sync_Test_State::$upbeat_activities['100'] = array( new Loop_Sync_Test_Activity( $this->committee_name( 7 ), 'Member' ) );

		$summary = $this->committee_sync()->run();

		$this->assertNotContains( self::ABORTED, $summary['errors'] );
		$this->assertSame( array( 'uid-07' ), $this->synced_ids() );
	}

	#[Test]
	public function the_committee_page_cap_aborts_the_run_when_more_records_are_still_reported(): void {
		loop_sync_test_user( 1, '100' );
		Loop_Sync_Test_State::$upbeat_activities['100'] = array( new Loop_Sync_Test_Activity( $this->committee_name( 1 ), 'Chair' ) );
		for ( $page = 1; $page <= 200; $page++ ) {
			Loop_Sync_Test_State::$upbeat_committee_pages[ $page ] = array(
				'rows' => array( new Loop_Sync_Test_Committee( $this->committee_name( $page ), $this->committee_id( $page ) ) ),
				'more' => true,
			);
		}

		$summary = $this->committee_sync()->run();

		$this->assert_aborted_before_any_change( $summary );
		$this->assertCount( 200, Loop_Sync_Test_State::upbeat_calls_to( 'get_committees' ) );
	}

	#[Test]
	public function a_list_that_ends_on_the_last_page_the_cap_allows_is_read_in_full(): void {
		for ( $page = 1; $page <= 200; $page++ ) {
			Loop_Sync_Test_State::$upbeat_committee_pages[ $page ] = array(
				'rows'  => array( new Loop_Sync_Test_Committee( $this->committee_name( $page ), $this->committee_id( $page ) ) ),
				'more'  => $page < 200,
				'total' => 200,
			);
		}

		$preview = $this->committee_sync()->inspect();

		$this->assertSame( '', $preview['error'] );
		$this->assertSame( 200, $preview['total_committees'] );
	}

	#[Test]
	public function a_unique_id_repeated_across_pages_is_not_treated_as_ambiguous(): void {
		$this->page_size( 2 );
		loop_sync_test_user( 1, '100' );
		$committees                                     = $this->committees( 3 );
		Loop_Sync_Test_State::$upbeat_committees        = $committees;
		Loop_Sync_Test_State::$upbeat_activities['100'] = array(
			new Loop_Sync_Test_Activity( $this->committee_name( 2 ), 'Chair' ),
			new Loop_Sync_Test_Activity( $this->committee_name( 3 ), 'Member' ),
		);
		// Committee 2 closes page 1 and opens page 2 again.
		Loop_Sync_Test_State::$upbeat_committee_pages   = array(
			2 => array(
				'rows'  => array( $committees[1], $committees[2] ),
				'more'  => false,
				'total' => 3,
			),
		);

		$summary = $this->committee_sync()->run();

		$this->assertSame( array( 'uid-02', 'uid-03' ), $this->synced_ids() );
		$this->assertSame( 0, $summary['skipped_no_id_committees'] );
		$this->assertSame( 'replace', $summary['mode'] );
		$this->assertSame( array(), $this->logged( 'warning', 'sharing a name' ) );
	}

	#[Test]
	public function committees_sharing_a_name_on_different_pages_are_still_ambiguous(): void {
		$this->page_size( 1 );
		loop_sync_test_user( 1, '100' );
		Loop_Sync_Test_State::$upbeat_committees        = array(
			new Loop_Sync_Test_Committee( 'Board', 'uid-board-a' ),
			new Loop_Sync_Test_Committee( 'Board', 'uid-board-b' ),
		);
		Loop_Sync_Test_State::$upbeat_activities['100'] = array( new Loop_Sync_Test_Activity( 'Board', 'Chair' ) );

		$summary = $this->committee_sync()->run();

		$this->assertSame( array(), $this->synced_ids() );
		$this->assertSame( 1, $summary['skipped_no_id_committees'] );
		$this->assertCount( 1, $this->logged( 'warning', 'sharing a name' ) );
	}

	#[Test]
	public function an_empty_first_committee_page_is_an_empty_list_and_the_run_only_merges(): void {
		loop_sync_test_user( 1, '100' );
		Loop_Sync_Test_State::$upbeat_committees        = array();
		Loop_Sync_Test_State::$upbeat_activities['100'] = array( new Loop_Sync_Test_Activity( 'Board', 'Chair' ) );
		Loop_Sync_Test_State::$loop_committees          = array( $this->loop_committee( 'uid-board' ) );

		$summary = $this->committee_sync()->run();

		$this->assertNotContains( self::ABORTED, $summary['errors'] );
		$this->assertCount( 1, Loop_Sync_Test_State::upbeat_calls_to( 'get_committees' ) );
		$this->assertSame( 'merge', $summary['mode'] );
		$this->assertSame( array(), Loop_Sync_Test_State::calls_to( 'agend_apps_loop_archive_committee' ) );
	}

	#[Test]
	public function member_activities_over_two_pages_are_both_used(): void {
		Loop_Sync_Test_State::$upbeat_activities_page_size = 2;
		loop_sync_test_user( 1, '100' );
		Loop_Sync_Test_State::$upbeat_committees        = array(
			new Loop_Sync_Test_Committee( 'Audit', 'uid-audit' ),
			new Loop_Sync_Test_Committee( 'Board', 'uid-board' ),
			new Loop_Sync_Test_Committee( 'Finance', 'uid-finance' ),
		);
		Loop_Sync_Test_State::$upbeat_activities['100'] = array(
			new Loop_Sync_Test_Activity( 'Audit', 'Member' ),
			new Loop_Sync_Test_Activity( 'Board', 'Chair' ),
			new Loop_Sync_Test_Activity( 'Finance', 'Member' ),
		);

		$summary = $this->committee_sync()->run();

		$this->assertSame( array( 'uid-audit', 'uid-board', 'uid-finance' ), $this->synced_ids() );
		$this->assertSame( 0, $summary['failed_members'] );
		$this->assertSame( 'replace', $summary['mode'] );
		// The first call is the unpaged one the kiosk's own features make; later
		// pages pass the page number alone so Upbeat's page size stays the same.
		$this->assertSame(
			array(
				array( '100' ),
				array( '100', '', '2' ),
			),
			Loop_Sync_Test_State::upbeat_calls_to( 'get_member_committee_activities' )
		);
	}

	#[Test]
	public function a_failed_second_activities_page_counts_as_a_failed_member_and_the_run_is_in_merge_mode(): void {
		Loop_Sync_Test_State::$upbeat_activities_page_size = 2;
		loop_sync_test_user( 1, '100' );
		loop_sync_test_user( 2, '200' );
		Loop_Sync_Test_State::$upbeat_committees          = array(
			new Loop_Sync_Test_Committee( 'Audit', 'uid-audit' ),
			new Loop_Sync_Test_Committee( 'Board', 'uid-board' ),
			new Loop_Sync_Test_Committee( 'Finance', 'uid-finance' ),
		);
		Loop_Sync_Test_State::$upbeat_activities['100']   = array(
			new Loop_Sync_Test_Activity( 'Audit', 'Member' ),
			new Loop_Sync_Test_Activity( 'Board', 'Chair' ),
			new Loop_Sync_Test_Activity( 'Finance', 'Member' ),
		);
		Loop_Sync_Test_State::$upbeat_activity_pages['100'] = array( 2 => false );
		Loop_Sync_Test_State::$upbeat_activities['200']   = array( new Loop_Sync_Test_Activity( 'Board', 'Member' ) );
		Loop_Sync_Test_State::$loop_committees            = array(
			$this->loop_committee( 'uid-audit' ),
			$this->loop_committee( 'uid-board' ),
			$this->loop_committee( 'uid-finance' ),
			$this->loop_committee( 'gone' ),
		);

		$summary = $this->committee_sync()->run();

		$this->assertSame( 1, $summary['failed_members'] );
		$this->assertSame( 2, $summary['members_scanned'] );
		$this->assertSame( 'merge', $summary['mode'] );
		$payloads = array_column( Loop_Sync_Test_State::calls_to( 'agend_apps_loop_sync_committee' ), 0 );
		$this->assertSame( array( 'merge' ), array_values( array_unique( array_column( $payloads, 'mode' ) ) ) );
		$this->assertSame( array(), Loop_Sync_Test_State::calls_to( 'agend_apps_loop_archive_committee' ) );
		$this->assertSame( 0, $summary['archived'] );
	}

	#[Test]
	public function member_activities_past_the_page_cap_count_as_a_failed_member(): void {
		Loop_Sync_Test_State::$upbeat_activities_page_size = 1;
		loop_sync_test_user( 1, '100' );
		Loop_Sync_Test_State::$upbeat_committees        = $this->committees( 1 );
		$activities                                     = array();
		for ( $i = 1; $i <= 25; $i++ ) {
			$activities[] = new Loop_Sync_Test_Activity( $this->committee_name( 1 ), 'Member' );
		}
		Loop_Sync_Test_State::$upbeat_activities['100'] = $activities;

		$summary = $this->committee_sync()->run();

		$calls = Loop_Sync_Test_State::upbeat_calls_to( 'get_member_committee_activities' );
		$this->assertCount( 20, $calls );
		$this->assertSame( array( '100', '', '20' ), $calls[19] );
		$this->assertSame( 1, $summary['failed_members'] );
		$this->assertSame( 'merge', $summary['mode'] );
	}

	#[Test]
	public function member_activities_that_end_on_the_last_page_the_cap_allows_are_read_in_full(): void {
		Loop_Sync_Test_State::$upbeat_activities_page_size = 1;
		loop_sync_test_user( 1, '100' );
		Loop_Sync_Test_State::$upbeat_committees        = $this->committees( 1 );
		$activities                                     = array();
		for ( $i = 1; $i <= 20; $i++ ) {
			$activities[] = new Loop_Sync_Test_Activity( $this->committee_name( 1 ), 'Member' );
		}
		Loop_Sync_Test_State::$upbeat_activities['100'] = $activities;

		$summary = $this->committee_sync()->run();

		$this->assertCount( 20, Loop_Sync_Test_State::upbeat_calls_to( 'get_member_committee_activities' ) );
		$this->assertSame( 0, $summary['failed_members'] );
		$this->assertSame( 'replace', $summary['mode'] );
	}

	#[Test]
	public function an_unpaged_single_page_member_response_makes_exactly_one_call_with_no_paging_arguments(): void {
		loop_sync_test_user( 1, '100' );
		Loop_Sync_Test_State::$upbeat_committees        = array( new Loop_Sync_Test_Committee( 'Board', 'uid-board' ) );
		Loop_Sync_Test_State::$upbeat_activities['100'] = array( new Loop_Sync_Test_Activity( 'Board', 'Chair' ) );

		$summary = $this->committee_sync()->run();

		$this->assertSame( array( array( '100' ) ), Loop_Sync_Test_State::upbeat_calls_to( 'get_member_committee_activities' ) );
		$this->assertSame( 0, $summary['failed_members'] );
		$this->assertSame( array( 'uid-board' ), $this->synced_ids() );
	}

	#[Test]
	public function the_member_scan_reads_every_page_of_a_members_activities(): void {
		Loop_Sync_Test_State::$upbeat_activities_page_size = 2;
		loop_sync_test_user( 1, '100' );
		Loop_Sync_Test_State::$upbeat_activities['100'] = array(
			new Loop_Sync_Test_Activity( 'Audit', 'Member' ),
			new Loop_Sync_Test_Activity( 'Board', 'Chair' ),
			new Loop_Sync_Test_Activity( 'Finance', 'Member' ),
		);

		$report = $this->committee_sync()->scan_members();

		$this->assertSame( 1, $report['members_in_committee'] );
		$this->assertSame( array( 'Audit', 'Board', 'Finance' ), array_column( $report['members'][0]['committees'], 'committee' ) );
		$this->assertSame( array( 'Chair' => 1, 'Member' => 2 ), $report['roles'] );
	}

	#[Test]
	public function the_member_scan_lists_no_committees_for_a_member_whose_second_page_failed(): void {
		Loop_Sync_Test_State::$upbeat_activities_page_size = 2;
		loop_sync_test_user( 1, '100' );
		Loop_Sync_Test_State::$upbeat_activities['100']     = array(
			new Loop_Sync_Test_Activity( 'Audit', 'Member' ),
			new Loop_Sync_Test_Activity( 'Board', 'Chair' ),
			new Loop_Sync_Test_Activity( 'Finance', 'Member' ),
		);
		Loop_Sync_Test_State::$upbeat_activity_pages['100'] = array( 2 => false );

		$report = $this->committee_sync()->scan_members();

		$this->assertSame( 0, $report['members_in_committee'] );
		$this->assertSame( array(), $report['members'] );
	}

	#[Test]
	public function the_committee_catalogue_walks_every_page(): void {
		Loop_Sync_Test_State::$upbeat_committees = $this->committees( 7 );

		$preview = $this->committee_sync()->inspect();

		$this->assertSame( '', $preview['error'] );
		$this->assertSame( 7, $preview['total_committees'] );
		$this->assertSame( $this->committee_id( 7 ), $preview['committees'][6]['unique_id'] );
		$this->assertCount( 2, Loop_Sync_Test_State::upbeat_calls_to( 'get_committees' ) );
	}

	#[Test]
	public function the_committee_catalogue_reports_an_error_instead_of_a_partial_list(): void {
		Loop_Sync_Test_State::$upbeat_committees      = $this->committees( 12 );
		Loop_Sync_Test_State::$upbeat_committee_pages = array( 2 => array( 'rows' => array() ) );

		$preview = $this->committee_sync()->inspect();

		$this->assertSame( 'Could not read the full committee list from Upbeat', $preview['error'] );
		$this->assertSame( array(), $preview['committees'] );
		$this->assertSame( 0, $preview['total_committees'] );
	}
}
