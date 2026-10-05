<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\LoopSync;

use Agend_Loop_Sync_Logger;
use Agend_Loop_Sync_Settings;
use Agend_Loop_Sync_User_Sync;
use Agend_Test_WP;
use Loop_Sync_Test_Activity;
use Loop_Sync_Test_Committee;
use Loop_Sync_Test_State;
use PHPUnit\Framework\Attributes\Test;

require_once __DIR__ . '/LoopSyncTestCase.php';

final class LoggingAndSchedulingTest extends LoopSyncTestCase {

	#[Test]
	public function the_log_never_stores_personal_data(): void {
		Agend_Loop_Sync_Logger::info(
			'x',
			array(
				'wp_id'  => 5,
				'email'  => 'a@example.com',
				'roster' => array( array( 'external_id' => 1, 'email' => 'b@example.com', 'display_name' => 'B' ) ),
			)
		);

		$stored = wp_json_encode( Agend_Loop_Sync_Logger::recent() );

		$this->assertStringNotContainsString( 'example.com', $stored );
		$this->assertStringContainsString( '"wp_id":5', $stored );
	}

	#[Test]
	public function an_oversized_context_is_truncated(): void {
		Agend_Loop_Sync_Logger::info( 'big', array( 'ids' => range( 1, 5000 ) ) );

		$context = Agend_Loop_Sync_Logger::recent( 1 )[0]['context'];

		$this->assertTrue( $context['truncated'] );
		$this->assertLessThanOrEqual( Agend_Loop_Sync_Logger::MAX_CONTEXT_BYTES, strlen( $context['preview'] ) );
	}

	#[Test]
	public function a_dry_run_logs_no_email_for_a_user_or_committee(): void {
		update_option( 'agend_loop_sync_dry_run', '1' );
		$user = loop_sync_test_user( 1, '100', 'secret@example.com' );
		Loop_Sync_Test_State::$upbeat_committees        = array( new Loop_Sync_Test_Committee( 'Board' ) );
		Loop_Sync_Test_State::$upbeat_activities['100'] = array( new Loop_Sync_Test_Activity( 'Board', 'Chair' ) );

		( new Agend_Loop_Sync_User_Sync() )->sync_user( $user );
		$this->committee_sync()->run();

		$this->assertStringNotContainsString( 'secret@example.com', wp_json_encode( Agend_Loop_Sync_Logger::recent() ) );
		$this->assertSame( array(), Loop_Sync_Test_State::calls_to( 'agend_apps_loop_sync_user' ) );
	}

	#[Test]
	public function the_stored_summary_caps_unresolved_ids(): void {
		loop_sync_test_user( 1, '100' );
		Loop_Sync_Test_State::$upbeat_committees        = array( new Loop_Sync_Test_Committee( 'Board' ) );
		Loop_Sync_Test_State::$upbeat_activities['100'] = array( new Loop_Sync_Test_Activity( 'Board', 'Chair' ) );
		Loop_Sync_Test_State::$responses['agend_apps_loop_sync_committee'] = array(
			'success' => true,
			'data'    => array( 'applied' => 0, 'skipped' => 500, 'removed' => 0, 'skipped_external_ids' => range( 1, 500 ) ),
		);

		$this->committee_sync()->run();

		$stored = get_option( Agend_Loop_Sync_Settings::OPT_LAST_SUMMARY );
		$this->assertCount( 100, $stored['unresolved_external_ids'] );
		$this->assertSame( 500, $stored['unresolved_external_ids_total'] );
	}

	#[Test]
	public function a_missing_cron_event_is_recreated_when_the_sync_is_on(): void {
		Agend_Loop_Sync_Settings::ensure_committee_cron();

		$this->assertNotFalse( wp_next_scheduled( AGEND_LOOP_SYNC_CRON_HOOK ) );
		$this->assertCount( 1, Agend_Test_WP::$scheduled_events );

		Agend_Loop_Sync_Settings::ensure_committee_cron();
		$this->assertCount( 1, Agend_Test_WP::$scheduled_events, 'must not double-schedule' );
	}

	#[Test]
	public function no_cron_event_is_created_while_the_plugin_is_disabled(): void {
		update_option( 'agend_loop_sync_enabled', '0' );

		Agend_Loop_Sync_Settings::ensure_committee_cron();

		$this->assertFalse( wp_next_scheduled( AGEND_LOOP_SYNC_CRON_HOOK ) );
	}

	#[Test]
	public function login_sync_is_deferred_to_shutdown(): void {
		$user = loop_sync_test_user( 1, '100' );
		$sync = new Agend_Loop_Sync_User_Sync();

		$sync->on_login( 'member1', $user );
		$this->assertSame( array(), Loop_Sync_Test_State::calls_to( 'agend_apps_loop_sync_user' ), 'login must not block on the gateway' );

		do_action( 'shutdown' );

		$this->assertCount( 1, Loop_Sync_Test_State::calls_to( 'agend_apps_loop_sync_user' ) );
	}
}
