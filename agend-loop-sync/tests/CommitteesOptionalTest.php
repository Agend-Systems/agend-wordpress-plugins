<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\LoopSync;

use Agend_Loop_Sync_Settings;
use Agend_Test_WP;
use PHPUnit\Framework\Attributes\Test;

require_once __DIR__ . '/LoopSyncTestCase.php';

if ( ! defined( 'AGEND_LOOP_SYNC_DIR' ) ) {
	define( 'AGEND_LOOP_SYNC_DIR', AGEND_TESTS_ROOT . '/agend-loop-sync/' );
}

/**
 * Committee channels are optional: a site can run member sync alone, with no
 * committee cron, no committee runs and no committee tools on the settings
 * screen, while a site that already uses committee sync keeps it.
 */
final class CommitteesOptionalTest extends LoopSyncTestCase {

	private function render_page(): string {
		ob_start();
		require AGEND_LOOP_SYNC_DIR . 'templates/admin-settings.php';

		return (string) ob_get_clean();
	}

	#[Test]
	public function a_new_install_seeds_committee_sync_off_and_keeps_an_existing_setting(): void {
		delete_option( Agend_Loop_Sync_Settings::OPT_COMMITTEE_SYNC );
		Agend_Loop_Sync_Settings::seed_defaults();
		$this->assertFalse( Agend_Loop_Sync_Settings::is_committee_sync_enabled() );

		update_option( Agend_Loop_Sync_Settings::OPT_COMMITTEE_SYNC, '1' );
		Agend_Loop_Sync_Settings::seed_defaults();
		$this->assertTrue( Agend_Loop_Sync_Settings::is_committee_sync_enabled() );
	}

	#[Test]
	public function the_committee_cron_is_scheduled_only_while_the_plugin_and_committee_sync_are_on(): void {
		update_option( Agend_Loop_Sync_Settings::OPT_COMMITTEE_SYNC, '1' );
		Agend_Loop_Sync_Settings::sync_committee_cron();
		$this->assertNotFalse( wp_next_scheduled( AGEND_LOOP_SYNC_CRON_HOOK ) );

		Agend_Loop_Sync_Settings::sync_committee_cron();
		$this->assertCount( 1, Agend_Test_WP::$scheduled_events, 'must not double-schedule' );

		update_option( Agend_Loop_Sync_Settings::OPT_COMMITTEE_SYNC, '0' );
		Agend_Loop_Sync_Settings::sync_committee_cron();
		$this->assertFalse( wp_next_scheduled( AGEND_LOOP_SYNC_CRON_HOOK ) );

		update_option( Agend_Loop_Sync_Settings::OPT_COMMITTEE_SYNC, '1' );
		update_option( Agend_Loop_Sync_Settings::OPT_ENABLED, '0' );
		Agend_Loop_Sync_Settings::sync_committee_cron();
		$this->assertFalse( wp_next_scheduled( AGEND_LOOP_SYNC_CRON_HOOK ) );
	}

	#[Test]
	public function changing_the_schedule_with_committee_sync_off_schedules_nothing(): void {
		update_option( Agend_Loop_Sync_Settings::OPT_COMMITTEE_SYNC, '0' );

		Agend_Loop_Sync_Settings::reschedule_committee_cron();

		$this->assertFalse( wp_next_scheduled( AGEND_LOOP_SYNC_CRON_HOOK ) );
	}

	#[Test]
	public function changing_the_schedule_with_committee_sync_on_schedules_one_event_at_the_new_interval(): void {
		update_option( Agend_Loop_Sync_Settings::OPT_COMMITTEE_SYNC, '1' );
		Agend_Loop_Sync_Settings::sync_committee_cron();
		update_option( Agend_Loop_Sync_Settings::OPT_SCHEDULE, 'hourly' );

		Agend_Loop_Sync_Settings::reschedule_committee_cron();

		$this->assertCount( 1, Agend_Test_WP::$scheduled_events );
		$this->assertSame( 'hourly', Agend_Test_WP::$scheduled_events[0]['recurrence'] );
	}

	#[Test]
	public function a_committee_run_with_committee_sync_off_makes_no_calls_and_keeps_the_last_run(): void {
		update_option( Agend_Loop_Sync_Settings::OPT_LAST_SYNC, 1700000000 );
		update_option( Agend_Loop_Sync_Settings::OPT_LAST_SUMMARY, array( 'synced' => 4 ) );
		update_option( Agend_Loop_Sync_Settings::OPT_COMMITTEE_SYNC, '0' );

		$summary = $this->committee_sync()->run();

		$this->assertContains( 'Committee sync is disabled in settings', $summary['errors'] );
		$this->assertCount( 0, Agend_Test_WP::$requests );
		$this->assertSame( 1700000000, get_option( Agend_Loop_Sync_Settings::OPT_LAST_SYNC ) );
		$this->assertSame( array( 'synced' => 4 ), get_option( Agend_Loop_Sync_Settings::OPT_LAST_SUMMARY ) );
	}

	#[Test]
	public function a_site_that_never_stored_the_defaults_is_seeded_with_committee_sync_off(): void {
		delete_option( Agend_Loop_Sync_Settings::OPT_ENABLED );
		delete_option( Agend_Loop_Sync_Settings::OPT_COMMITTEE_SYNC );

		Agend_Loop_Sync_Settings::maybe_seed_defaults();

		$this->assertFalse( Agend_Loop_Sync_Settings::is_enabled() );
		$this->assertFalse( Agend_Loop_Sync_Settings::is_committee_sync_enabled() );
	}

	#[Test]
	public function an_existing_site_is_not_reseeded(): void {
		// Enabled and saved before committee sync was a separate default: the
		// missing committee option still reads as on, as it did before.
		delete_option( Agend_Loop_Sync_Settings::OPT_COMMITTEE_SYNC );

		Agend_Loop_Sync_Settings::maybe_seed_defaults();

		$this->assertTrue( Agend_Loop_Sync_Settings::is_enabled() );
		$this->assertTrue( Agend_Loop_Sync_Settings::is_committee_sync_enabled() );
		$this->assertFalse( get_option( Agend_Loop_Sync_Settings::OPT_COMMITTEE_SYNC, false ) );
	}

	#[Test]
	public function saving_either_switch_schedules_or_clears_the_cron(): void {
		Agend_Loop_Sync_Settings::register();

		update_option( Agend_Loop_Sync_Settings::OPT_COMMITTEE_SYNC, '1' );
		do_action( 'update_option_' . Agend_Loop_Sync_Settings::OPT_COMMITTEE_SYNC );
		$this->assertNotFalse( wp_next_scheduled( AGEND_LOOP_SYNC_CRON_HOOK ) );

		update_option( Agend_Loop_Sync_Settings::OPT_ENABLED, '0' );
		do_action( 'update_option_' . Agend_Loop_Sync_Settings::OPT_ENABLED );
		$this->assertFalse( wp_next_scheduled( AGEND_LOOP_SYNC_CRON_HOOK ) );

		// The first save on a site that never stored the option adds it.
		update_option( Agend_Loop_Sync_Settings::OPT_ENABLED, '1' );
		do_action( 'add_option_' . Agend_Loop_Sync_Settings::OPT_ENABLED );
		$this->assertNotFalse( wp_next_scheduled( AGEND_LOOP_SYNC_CRON_HOOK ) );

		update_option( Agend_Loop_Sync_Settings::OPT_COMMITTEE_SYNC, '0' );
		do_action( 'add_option_' . Agend_Loop_Sync_Settings::OPT_COMMITTEE_SYNC );
		$this->assertFalse( wp_next_scheduled( AGEND_LOOP_SYNC_CRON_HOOK ) );
	}

	#[Test]
	public function activating_a_fresh_install_schedules_nothing(): void {
		delete_option( Agend_Loop_Sync_Settings::OPT_ENABLED );
		delete_option( Agend_Loop_Sync_Settings::OPT_COMMITTEE_SYNC );

		Agend_Loop_Sync_Settings::activate();

		$this->assertFalse( wp_next_scheduled( AGEND_LOOP_SYNC_CRON_HOOK ) );
		$this->assertFalse( Agend_Loop_Sync_Settings::is_committee_sync_enabled() );
	}

	#[Test]
	public function reactivating_with_committee_sync_on_schedules_one_event(): void {
		update_option( Agend_Loop_Sync_Settings::OPT_COMMITTEE_SYNC, '1' );

		Agend_Loop_Sync_Settings::activate();
		Agend_Loop_Sync_Settings::activate();

		$this->assertCount( 1, Agend_Test_WP::$scheduled_events );
	}

	#[Test]
	public function a_queued_hand_over_stays_visible_and_clearable_while_committee_sync_is_off(): void {
		update_option( Agend_Loop_Sync_Settings::OPT_COMMITTEE_SYNC, '0' );
		Agend_Loop_Sync_Settings::queue_adoptions( 'COMM-1', array( 1001, 1002 ) );

		$html = $this->render_page();

		$this->assertStringContainsString( 'Queued for the next sync', $html );
		$this->assertStringContainsString( 'These hand-overs are applied when it is turned back on', $html );
		$this->assertStringContainsString( 'agend_loop_sync_adopt_clear', $html );
		$this->assertStringContainsString( '1001, 1002', $html );
		$this->assertStringNotContainsString( 'agend_loop_sync_adopt_queue"', $html );
	}

	#[Test]
	public function with_committee_sync_off_and_nothing_queued_the_queue_is_not_shown(): void {
		update_option( Agend_Loop_Sync_Settings::OPT_COMMITTEE_SYNC, '0' );

		$this->assertStringNotContainsString( 'Queued for the next sync', $this->render_page() );
	}

	#[Test]
	public function a_refused_committee_action_explains_why(): void {
		$_GET['committee_sync'] = 'off';

		$html = $this->render_page();
		unset( $_GET['committee_sync'] );

		$this->assertStringContainsString( 'Committee sync is off, so that committee action was not run.', $html );
	}

	#[Test]
	public function with_committee_sync_off_the_page_offers_member_sync_and_hides_the_committee_tools(): void {
		update_option( Agend_Loop_Sync_Settings::OPT_COMMITTEE_SYNC, '0' );

		$html = $this->render_page();

		$this->assertStringContainsString( 'Member sync', $html );
		$this->assertStringContainsString( 'agend_loop_sync_backfill_users', $html );
		$this->assertStringContainsString( 'Committee sync is off.', $html );
		$this->assertStringNotContainsString( 'agend_loop_sync_run_committees', $html );
		$this->assertStringNotContainsString( 'agend_loop_sync_scan_members', $html );
		$this->assertStringNotContainsString( 'Hand members to the sync', $html );

		// The committee settings stay in the form, hidden, so saving the form
		// does not wipe the role mapping or filters.
		$this->assertMatchesRegularExpression( '/<tbody id="als-committee-settings" hidden>/', $html );
		$this->assertStringContainsString( 'name="' . Agend_Loop_Sync_Settings::OPT_ROLE_MAP . '"', $html );
		$this->assertStringContainsString( 'name="' . Agend_Loop_Sync_Settings::OPT_FILTER_ALLOWLIST . '"', $html );
	}

	#[Test]
	public function with_committee_sync_on_the_page_shows_the_committee_tools(): void {
		update_option( Agend_Loop_Sync_Settings::OPT_COMMITTEE_SYNC, '1' );

		$html = $this->render_page();

		$this->assertStringContainsString( 'agend_loop_sync_backfill_users', $html );
		$this->assertStringContainsString( 'agend_loop_sync_run_committees', $html );
		$this->assertStringContainsString( 'Hand members to the sync', $html );
		$this->assertStringContainsString( '<tbody id="als-committee-settings">', $html );
		$this->assertStringNotContainsString( 'Committee sync is off.', $html );
	}
}
