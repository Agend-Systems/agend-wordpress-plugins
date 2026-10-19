<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\LoopSync;

use Agend_Loop_Sync_Committee_Sync;
use Agend_Loop_Sync_Settings;
use Agend_Test_WP;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\Test;

require_once __DIR__ . '/LoopSyncTestCase.php';

if ( ! defined( 'AGEND_LOOP_SYNC_DIR' ) ) {
	define( 'AGEND_LOOP_SYNC_DIR', AGEND_TESTS_ROOT . '/agend-loop-sync/' );
}

/**
 * The hand-over section of the settings page: a committee select, a member
 * table whose checkboxes follow what the picker allows, the queue with a Clear
 * action per committee, and the outcomes of the last run.
 */
final class AdoptionSettingsPageTest extends LoopSyncTestCase {

	protected function tearDown(): void {
		unset( $_GET['adopt'], $_GET['selected'], $_GET['queued'] );
		Agend_Test_WP::reset();
		parent::tearDown();
	}

	private function render_page(): string {
		ob_start();
		require AGEND_LOOP_SYNC_DIR . 'templates/admin-settings.php';

		return (string) ob_get_clean();
	}

	private function xpath( string $html ): DOMXPath {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
		libxml_clear_errors();

		return new DOMXPath( $dom );
	}

	private function load_committees(): void {
		set_transient(
			'agend_loop_sync_adopt_committees',
			array(
				'generated_at' => time(),
				'committees'   => array(
					array( 'unique_id' => 'uid-audit', 'name' => 'Audit' ),
					array( 'unique_id' => 'uid-board', 'name' => 'Board <b>' ),
				),
				'error'        => '',
			),
			900
		);
	}

	/**
	 * Stores a picker for uid-board built from realistic rows.
	 *
	 * @param array<int, array<string, mixed>> $local Local members.
	 * @param array<int, array<string, mixed>> $loop  Loop members.
	 */
	private function load_picker( array $local, array $loop, string $error = '' ): void {
		set_transient(
			'agend_loop_sync_adopt_picker',
			array(
				'generated_at'   => time(),
				'unique_id'      => 'uid-board',
				'committee'      => 'Board <b>',
				'unlinked_count' => 2,
				'members'        => Agend_Loop_Sync_Committee_Sync::join_adoption_members( $local, $loop ),
				'error'          => $error,
			),
			300
		);
	}

	private function local_member( int $wp_id, string $name, string $number, string $committee_role, string $channel_role ): array {
		return array(
			'wp_id'             => $wp_id,
			'name'              => $name,
			'membership_number' => $number,
			'committee_role'    => $committee_role,
			'channel_role'      => $channel_role,
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
	 * @return array<string, array<string, mixed>> Checkbox attributes by membership number value.
	 */
	private function checkboxes( string $html ): array {
		$boxes = array();
		foreach ( $this->xpath( $html )->query( '//input[@name="external_ids[]"]' ) as $input ) {
			$boxes[ $input->getAttribute( 'value' ) ] = array(
				'disabled' => $input->hasAttribute( 'disabled' ),
				'checked'  => $input->hasAttribute( 'checked' ),
				'row'      => trim( preg_replace( '/\s+/', ' ', $input->parentNode->parentNode->textContent ) ),
			);
		}

		return $boxes;
	}

	#[Test]
	public function before_the_committees_are_loaded_the_page_offers_to_load_them(): void {
		$html = $this->render_page();

		$this->assertStringContainsString( 'Hand members to the sync', $html );
		$this->assertStringContainsString( 'agend_loop_sync_adopt_load_committees', $html );
		$this->assertStringNotContainsString( 'name="unique_id"', $html );
		$this->assertStringContainsString( 'Nothing is queued.', $html );
	}

	#[Test]
	public function loaded_committees_fill_a_select_with_name_and_unique_id_escaped(): void {
		$this->load_committees();

		$options = $this->xpath( $this->render_page() )->query( '//select[@id="als-adopt-committee"]/option' );

		$this->assertSame( 2, $options->length );
		$this->assertSame( 'uid-audit', $options->item( 0 )->getAttribute( 'value' ) );
		$this->assertSame( 'Audit (uid-audit)', trim( $options->item( 0 )->textContent ) );
		$this->assertSame( 'Board <b> (uid-board)', trim( $options->item( 1 )->textContent ), 'Markup in a name is shown as text.' );
	}

	#[Test]
	public function a_committee_list_that_failed_to_load_shows_the_error_and_offers_to_load_again(): void {
		set_transient( 'agend_loop_sync_adopt_committees', array( 'committees' => array(), 'error' => 'Could not read the full committee list from Upbeat.' ), 900 );

		$html = $this->render_page();

		$this->assertStringContainsString( 'Could not read the full committee list from Upbeat.', $html );
		$this->assertStringContainsString( 'agend_loop_sync_adopt_load_committees', $html );
	}

	#[Test]
	public function only_selectable_members_get_an_enabled_checkbox_and_the_others_say_why(): void {
		$this->load_committees();
		$this->load_picker(
			array(
				$this->local_member( 1, 'Ada', '100', 'Member', 'member' ),
				$this->local_member( 2, 'Bea', '200', 'Chair', 'owner' ),
				$this->local_member( 3, 'Cy', '300', 'Member', 'member' ),
				$this->local_member( 4, 'Di', '400', 'Member', 'member' ),
				$this->local_member( 5, 'Eve', '500', 'Member', 'member' ),
				$this->local_member( 6, 'Flo', '600', 'Member', 'member' ),
			),
			array(
				$this->loop_member( 100, 'member', 'none' ),
				$this->loop_member( 200, 'owner', 'wordpress_sync' ),
				$this->loop_member( 300, 'member', 'group' ),
				$this->loop_member( 400, 'member', 'upbeat' ),
				$this->loop_member( 500, 'member', 'brand_new_source' ),
			)
		);

		$boxes = $this->checkboxes( $this->render_page() );

		$this->assertFalse( $boxes['100']['disabled'] );
		$this->assertTrue( $boxes['200']['disabled'] );
		$this->assertStringContainsString( 'Already managed by this sync.', $boxes['200']['row'] );
		$this->assertTrue( $boxes['300']['disabled'] );
		$this->assertStringContainsString( 'Managed by a CRM group.', $boxes['300']['row'] );
		$this->assertTrue( $boxes['400']['disabled'] );
		$this->assertStringContainsString( 'Managed by the Upbeat committee sync.', $boxes['400']['row'] );
		$this->assertTrue( $boxes['500']['disabled'] );
		$this->assertStringContainsString( 'Managed by another source.', $boxes['500']['row'] );
		$this->assertTrue( $boxes['600']['disabled'] );
		$this->assertStringContainsString( 'Not in the Loop channel.', $boxes['600']['row'] );
	}

	#[Test]
	public function a_member_with_a_number_the_gateway_cannot_use_is_shown_disabled_with_the_reason(): void {
		$this->load_committees();
		$this->load_picker( array( $this->local_member( 1, 'Ada', 'ABC123', 'Member', 'member' ) ), array() );

		$html = $this->render_page();

		$this->assertStringContainsString( 'ABC123', $html );
		$this->assertStringContainsString( 'not a plain number', $html );
		$this->assertSame( array( '' => true ), array_map( static fn( $box ) => $box['disabled'], $this->checkboxes( $html ) ) );
	}

	#[Test]
	public function a_demotion_is_flagged_with_the_role_change(): void {
		$this->load_committees();
		$this->load_picker(
			array(
				$this->local_member( 1, 'Ada', '100', 'Member', 'member' ),
				$this->local_member( 2, 'Bea', '200', 'Member', 'member' ),
			),
			array(
				$this->loop_member( 100, 'owner', 'none' ),
				$this->loop_member( 200, 'member', 'none' ),
			)
		);

		$boxes = $this->checkboxes( $this->render_page() );

		$this->assertStringContainsString( 'Demotion: owner to member.', $boxes['100']['row'] );
		$this->assertStringNotContainsString( 'Demotion', $boxes['200']['row'] );
	}

	#[Test]
	public function a_member_already_queued_is_ticked_disabled_and_marked(): void {
		$this->load_committees();
		$this->load_picker( array( $this->local_member( 1, 'Ada', '100', 'Member', 'member' ) ), array( $this->loop_member( 100, 'member', 'none' ) ) );
		Agend_Loop_Sync_Settings::queue_adoptions( 'uid-board', array( 100 ) );

		$boxes = $this->checkboxes( $this->render_page() );

		$this->assertTrue( $boxes['100']['checked'] );
		$this->assertTrue( $boxes['100']['disabled'] );
		$this->assertStringContainsString( 'Queued for the next sync.', $boxes['100']['row'] );
	}

	#[Test]
	public function the_queue_hand_over_form_posts_to_its_handler_with_a_nonce_and_the_committee(): void {
		$this->load_committees();
		$this->load_picker( array( $this->local_member( 1, 'Ada', '100', 'Member', 'member' ) ), array( $this->loop_member( 100, 'member', 'none' ) ) );

		$xpath = $this->xpath( $this->render_page() );

		$this->assertSame( 1, $xpath->query( '//form[input[@name="action"][@value="agend_loop_sync_adopt_queue"]][input[@name="unique_id"][@value="uid-board"]][input[@name="_wpnonce"]]' )->length );
	}

	#[Test]
	public function member_names_are_escaped(): void {
		$this->load_committees();
		$this->load_picker( array( $this->local_member( 1, '<script>alert(1)</script>', '100', 'Member', 'member' ) ), array( $this->loop_member( 100, 'member', 'none' ) ) );

		$html = $this->render_page();

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html );
	}

	#[Test]
	public function a_picker_error_shows_the_message_and_no_member_table(): void {
		$this->load_committees();
		$this->load_picker( array(), array(), 'Could not read the committee channel from Loop: Down' );

		$html = $this->render_page();

		$this->assertStringContainsString( 'Could not read the committee channel from Loop: Down', $html );
		$this->assertStringNotContainsString( 'agend_loop_sync_adopt_queue', $html );
	}

	#[Test]
	public function the_queue_lists_each_committee_with_a_clear_action(): void {
		$this->load_committees();
		Agend_Loop_Sync_Settings::queue_adoptions( 'uid-board', array( 100, 200 ) );
		Agend_Loop_Sync_Settings::queue_adoptions( 'uid-audit', array( 300 ) );

		$html  = $this->render_page();
		$xpath = $this->xpath( $html );

		$this->assertStringContainsString( '100, 200', $html );
		$this->assertSame( 1, $xpath->query( '//form[input[@name="action"][@value="agend_loop_sync_adopt_clear"]][input[@name="unique_id"][@value="uid-board"]][input[@name="_wpnonce"]]' )->length );
		$this->assertSame( 1, $xpath->query( '//form[input[@name="action"][@value="agend_loop_sync_adopt_clear"]][input[@name="unique_id"][@value="uid-audit"]]' )->length );
		$this->assertStringNotContainsString( 'Nothing is queued.', $html );
	}

	#[Test]
	public function a_queue_longer_than_the_list_shows_how_many_are_left_out(): void {
		Agend_Loop_Sync_Settings::queue_adoptions( 'uid-board', range( 1, 60 ) );

		$html = $this->render_page();

		$this->assertStringContainsString( ' and 10 more', $html );
		$this->assertStringNotContainsString( ', 51,', $html );
	}

	#[Test]
	public function a_queue_in_dry_run_says_it_will_be_kept(): void {
		Agend_Loop_Sync_Settings::queue_adoptions( 'uid-board', array( 100 ) );
		update_option( 'agend_loop_sync_dry_run', '1' );

		$this->assertStringContainsString( 'Dry run is on, so the next committee sync only logs what it would hand over', $this->render_page() );

		update_option( 'agend_loop_sync_dry_run', '0' );

		$this->assertStringNotContainsString( 'Dry run is on, so the next committee sync only logs', $this->render_page() );
	}

	#[Test]
	public function the_last_run_summary_shows_the_adoption_outcomes_and_any_hand_over_error(): void {
		update_option(
			Agend_Loop_Sync_Settings::OPT_LAST_SUMMARY,
			array(
				'synced'                 => 2,
				'archived'               => 0,
				'member_counts_reported' => true,
				'adopted'                => 2,
				'adoption_outcomes'      => array(
					'adopted'       => 2,
					'not_in_roster' => 1,
				),
				'adoption_results'       => array(
					array( 'committee' => 'Board', 'external_id' => 100, 'outcome' => 'adopted' ),
					array( 'committee' => 'Board', 'external_id' => 200, 'outcome' => 'not_in_roster' ),
				),
				'adoption_results_total' => 150,
				'adoption_errors'        => array( 'Audit: the gateway does not support hand-over (its response has no adoption_results); the queue was kept' ),
			)
		);

		$html = $this->render_page();

		$this->assertStringContainsString( 'Hand-over in the last run: 2 adopted. Outcomes: 2 adopted, 1 not on the committee roster.', $html );
		$this->assertStringContainsString( 'Board: membership number 200, not on the committee roster', $html );
		$this->assertStringContainsString( 'Showing the first 2 of 150 members.', $html );
		$this->assertStringContainsString( 'the gateway does not support hand-over', $html );
	}

	#[Test]
	public function a_held_outcome_is_labelled_in_the_summary_and_the_member_list(): void {
		update_option(
			Agend_Loop_Sync_Settings::OPT_LAST_SUMMARY,
			array(
				'synced'                 => 1,
				'archived'               => 0,
				'member_counts_reported' => true,
				'adopted'                => 1,
				'adoption_outcomes'      => array(
					'adopted' => 1,
					'held'    => 1,
				),
				'adoption_results'       => array(
					array( 'committee' => 'Board', 'external_id' => 100, 'outcome' => 'adopted' ),
					array( 'committee' => 'Board', 'external_id' => 200, 'outcome' => 'held' ),
				),
			)
		);

		$html = $this->render_page();

		$this->assertStringContainsString( 'Outcomes: 1 adopted, 1 held by a Loop administrator, not adopted.', $html );
		$this->assertStringContainsString( 'Board: membership number 200, held by a Loop administrator, not adopted', $html );
		$this->assertStringNotContainsString( 'unknown outcome', $html );
	}

	#[Test]
	public function a_last_run_without_a_hand_over_adds_nothing_to_the_summary(): void {
		update_option( Agend_Loop_Sync_Settings::OPT_LAST_SUMMARY, array( 'synced' => 1, 'archived' => 0, 'member_counts_reported' => true ) );

		$this->assertStringNotContainsString( 'Hand-over in the last run', $this->render_page() );
	}

	#[Test]
	public function the_page_has_no_em_dash(): void {
		$this->load_committees();
		$this->load_picker( array( $this->local_member( 1, 'Ada', '100', 'Member', 'member' ) ), array( $this->loop_member( 100, 'owner', 'none' ) ) );
		Agend_Loop_Sync_Settings::queue_adoptions( 'uid-board', array( 100 ) );

		$this->assertStringNotContainsString( "\u{2014}", $this->render_page() );
	}

	#[Test]
	public function a_queued_notice_reports_what_was_queued(): void {
		$_GET['adopt']    = 'queued';
		$_GET['selected'] = '3';
		$_GET['queued']   = '2';

		$this->assertStringContainsString( 'Hand-over queued. Members chosen: 3. Newly queued: 2.', $this->render_page() );
	}
}
