<?php
/**
 * Shared base for the Agend Loop Sync suite.
 *
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\LoopSync;

use Agend\Tests\TestCase;
use Agend_Loop_Sync_Contact_Resolver;
use Agend_Test_WP;
use Loop_Sync_Test_State;

if ( ! defined( 'AGEND_LOOP_SYNC_CRON_HOOK' ) ) {
	define( 'AGEND_LOOP_SYNC_CRON_HOOK', 'agend_loop_sync_committee_event' );
}

require_once AGEND_TESTS_ROOT . '/agend-loop-sync/tests/doubles.php';

foreach ( array( 'logger', 'settings', 'role-mapper', 'contact-resolver', 'user-sync', 'committee-sync' ) as $loop_sync_file ) {
	require_once AGEND_TESTS_ROOT . "/agend-loop-sync/includes/class-agend-loop-sync-{$loop_sync_file}.php";
}

/**
 * Resets the plugin and double state, and turns the plugin on for real
 * (non-dry-run) calls so tests can assert on what reaches the gateway.
 */
abstract class LoopSyncTestCase extends TestCase {

	protected function committee_sync(): \Agend_Loop_Sync_Committee_Sync {
		return new \Agend_Loop_Sync_Committee_Sync( new \Loop_Sync_Test_Upbeat() );
	}

	protected function setUp(): void {
		parent::setUp();
		Loop_Sync_Test_State::reset();

		// The shared `get_users()` stub reads from this suite's user registry
		// while one of its tests runs. The base TestCase clears the hook again.
		$GLOBALS['agend_test_get_users'] = 'loop_sync_test_get_users';
		Agend_Loop_Sync_Contact_Resolver::flush_cache();
		\Agend_Loop_Sync_User_Sync::reset_request_state();

		update_option( 'agend_loop_sync_enabled', '1' );
		update_option( 'agend_loop_sync_dry_run', '0' );
		update_option( 'wp_saml_idp_settings', array( 'entity_id' => 'https://idp.example.com/saml/metadata' ) );
	}

	protected function tearDown(): void {
		Agend_Test_WP::reset();
		parent::tearDown();
	}
}
