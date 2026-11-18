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
	public function a_committee_run_with_committee_sync_off_makes_no_calls(): void {
		update_option( Agend_Loop_Sync_Settings::OPT_COMMITTEE_SYNC, '0' );

		$summary = $this->committee_sync()->run();

		$this->assertContains( 'Committee sync is disabled in settings', $summary['errors'] );
		$this->assertCount( 0, Agend_Test_WP::$requests );
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
