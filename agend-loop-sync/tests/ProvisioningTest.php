<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\LoopSync;

use Agend_Loop_Sync_Gateway;
use Agend_Loop_Sync_Logger;
use Agend_Loop_Sync_Provision_Retry;
use Agend_Loop_Sync_Settings;
use Agend_Loop_Sync_User_Sync;
use Agend_Test_WP;
use Loop_Sync_Test_State;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use WP_Error;

require_once __DIR__ . '/LoopSyncTestCase.php';

/**
 * With "Create Loop accounts" on, member syncs ask Agend to create a member's
 * Loop account before their first sign-in (`provision: true`), report what
 * happened without naming the member, and send members held up by something
 * that passes on its own again an hour later. Off, nothing changes.
 */
#[CoversClass( Agend_Loop_Sync_User_Sync::class )]
#[CoversClass( Agend_Loop_Sync_Provision_Retry::class )]
final class ProvisioningTest extends LoopSyncTestCase {

	protected function setUp(): void {
		parent::setUp();
		update_option( 'agend_loop_sync_user_sync_enabled', '1' );
	}

	private function turn_on(): void {
		update_option( Agend_Loop_Sync_Settings::OPT_PROVISION, '1' );
	}

	/**
	 * @return array<int, array<string, mixed>> Log entries at $level whose message contains $fragment.
	 */
	private function logged( string $level, string $fragment ): array {
		return array_values(
			array_filter(
				Agend_Loop_Sync_Logger::recent( 500 ),
				static fn( $entry ) => $level === $entry['level'] && false !== strpos( $entry['message'], $fragment )
			)
		);
	}

	/**
	 * A bulk answer giving each external id an outcome, as the contract does:
	 * `provisioning` on every item, failed ones included, and counts on top.
	 *
	 * @param array<int, string> $outcomes 'provisioned', 'linked', 'already_linked' or 'skipped:<reason>' by external id.
	 */
	private function bulk_answer( array $outcomes ): array {
		$results = array();
		$counts  = array(
			'provisioned'    => 0,
			'linked'         => 0,
			'already_linked' => 0,
			'skipped'        => 0,
		);
		foreach ( $outcomes as $external_id => $outcome ) {
			$parts = explode( ':', $outcome );
			$block = array( 'outcome' => $parts[0] );
			if ( 'skipped' === $parts[0] ) {
				$block['reason'] = $parts[1];
			}
			++$counts[ $parts[0] ];
			$results[] = array(
				'external_id'  => $external_id,
				'success'      => 'skipped' !== $parts[0],
				'provisioning' => $block,
			);
		}

		return array(
			'success' => true,
			'data'    => array(
				'total'        => count( $results ),
				'succeeded'    => count( array_filter( $results, static fn( $r ) => $r['success'] ) ),
				'failed'       => count( array_filter( $results, static fn( $r ) => ! $r['success'] ) ),
				'results'      => $results,
				'provisioning' => $counts,
			),
		);
	}

	private function gateway_error( int $status, string $code, string $message ): WP_Error {
		return new WP_Error(
			'agend_api_error',
			$message,
			array(
				'status_code' => $status,
				'body'        => array(
					'success' => false,
					'error'   => array(
						'code'    => $code,
						'message' => $message,
					),
				),
			)
		);
	}

	private function retry_scheduled(): bool {
		return false !== wp_next_scheduled( AGEND_LOOP_SYNC_PROVISION_RETRY_HOOK );
	}

	#[Test]
	public function provisioning_is_off_unless_turned_on(): void {
		Agend_Loop_Sync_Settings::seed_defaults();
		$user = loop_sync_test_user( 1, '1001' );

		( new Agend_Loop_Sync_User_Sync() )->sync_user( $user );
		Agend_Loop_Sync_User_Sync::reset_request_state();
		$summary = Agend_Loop_Sync_User_Sync::backfill_all();

		$this->assertFalse( Agend_Loop_Sync_Settings::is_provision_enabled() );
		$this->assertArrayNotHasKey( 'provision', Loop_Sync_Test_State::calls_to( 'agend_apps_loop_sync_user' )[0][0] );
		$this->assertFalse( Loop_Sync_Test_State::calls_to( 'agend_apps_loop_bulk_sync_users' )[0][2] );
		$this->assertArrayNotHasKey( 'provisioning', $summary );
	}

	#[Test]
	public function a_filter_can_turn_it_on_without_the_setting(): void {
		add_filter( 'agend_loop_sync_provision_members', static fn() => true );

		$this->assertTrue( Agend_Loop_Sync_Settings::should_provision() );
	}

	#[Test]
	public function a_key_known_to_lack_the_scope_holds_provisioning_back_so_syncs_are_not_refused(): void {
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-key-scopes.php';
		$this->turn_on();
		\Agend_Apps_Key_Scopes::store_from_response( array( 'data' => array( 'scopes' => array( 'loop.users.sync' ), 'app_ids' => array( 'loop' ) ) ) );

		( new Agend_Loop_Sync_User_Sync() )->sync_user( loop_sync_test_user( 1, '1001' ) );

		$this->assertSame( 'scope_missing', Agend_Loop_Sync_Settings::provision_blocker() );
		$this->assertArrayNotHasKey( 'provision', Loop_Sync_Test_State::calls_to( 'agend_apps_loop_sync_user' )[0][0] );
	}

	#[Test]
	public function a_key_known_to_hold_the_scope_provisions(): void {
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-key-scopes.php';
		$this->turn_on();
		\Agend_Apps_Key_Scopes::store_from_response( array( 'data' => array( 'scopes' => array( 'loop.users.sync', 'loop.users.provision' ), 'app_ids' => array( 'loop' ) ) ) );

		$this->assertSame( '', Agend_Loop_Sync_Settings::provision_blocker() );
		$this->assertTrue( Agend_Loop_Sync_Settings::should_provision() );
	}

	#[Test]
	public function a_member_sync_asks_for_the_account_to_be_created(): void {
		$this->turn_on();
		$user = loop_sync_test_user( 1, '1001' );

		( new Agend_Loop_Sync_User_Sync() )->sync_user( $user );

		$this->assertTrue( Loop_Sync_Test_State::calls_to( 'agend_apps_loop_sync_user' )[0][0]['provision'] );
	}

	#[Test]
	public function dry_run_sends_nothing_and_says_it_would_provision(): void {
		$this->turn_on();
		update_option( 'agend_loop_sync_dry_run', '1' );

		( new Agend_Loop_Sync_User_Sync() )->sync_user( loop_sync_test_user( 1, '1001' ) );

		$this->assertSame( array(), Loop_Sync_Test_State::calls_to( 'agend_apps_loop_sync_user' ) );
		$this->assertTrue( $this->logged( 'info', 'Dry run: would sync user' )[0]['context']['provision'] );
	}

	#[Test]
	public function a_member_over_the_hourly_limit_is_queued_for_a_retry_and_named_only_by_user_id(): void {
		$this->turn_on();
		Loop_Sync_Test_State::$responses['agend_apps_loop_sync_user'] = $this->gateway_error(
			404,
			'NOT_FOUND',
			'No Loop user for external id 1001 (private@example.com); provisioning was skipped: rate_limited.'
		);

		$synced = ( new Agend_Loop_Sync_User_Sync() )->sync_user( loop_sync_test_user( 7, '1001', 'private@example.com' ) );

		$this->assertFalse( $synced );
		$warning = $this->logged( 'warning', 'Loop account not created' );
		$this->assertSame(
			array(
				'wp_id'  => 7,
				'reason' => 'rate_limited',
			),
			$warning[0]['context']
		);
		$this->assertStringNotContainsString( 'private@example.com', wp_json_encode( Agend_Loop_Sync_Logger::recent( 500 ) ) );
		$this->assertSame( 'rate_limited', Agend_Loop_Sync_Provision_Retry::queued()[7]['reason'] );
		$this->assertTrue( $this->retry_scheduled() );
	}

	#[Test]
	public function a_member_skipped_for_a_lasting_reason_is_reported_and_not_queued(): void {
		$this->turn_on();
		Loop_Sync_Test_State::$responses['agend_apps_loop_sync_user'] = $this->gateway_error(
			404,
			'NOT_FOUND',
			'No Loop user for external id 1001 (a@example.com); provisioning was skipped: restricted_role.'
		);

		( new Agend_Loop_Sync_User_Sync() )->sync_user( loop_sync_test_user( 7, '1001' ) );

		$this->assertSame( 'restricted_role', $this->logged( 'warning', 'Loop account not created' )[0]['context']['reason'] );
		$this->assertSame( array(), Agend_Loop_Sync_Provision_Retry::queued() );
	}

	#[Test]
	public function a_key_without_the_scope_is_reported_once_and_not_retried(): void {
		$this->turn_on();
		Loop_Sync_Test_State::$responses['agend_apps_loop_sync_user'] = $this->gateway_error( 403, 'INSUFFICIENT_SCOPES', 'Missing required scopes: loop.users.provision' );

		( new Agend_Loop_Sync_User_Sync() )->sync_user( loop_sync_test_user( 7, '1001' ) );

		$this->assertCount( 1, $this->logged( 'error', 'lacks loop.users.provision' ) );
		$this->assertSame( array(), Agend_Loop_Sync_Provision_Retry::queued() );
	}

	#[Test]
	public function any_other_failure_logs_the_status_and_code_with_the_email_masked(): void {
		Loop_Sync_Test_State::$responses['agend_apps_loop_sync_user'] = $this->gateway_error(
			404,
			'NOT_FOUND',
			'No Loop user for external id 1001 (private@example.com); provision via SSO before syncing.'
		);

		( new Agend_Loop_Sync_User_Sync() )->sync_user( loop_sync_test_user( 7, '1001', 'private@example.com' ) );

		$context = $this->logged( 'error', 'User sync failed' )[0]['context'];
		$this->assertSame( 404, $context['status'] );
		$this->assertSame( 'NOT_FOUND', $context['code'] );
		$this->assertStringContainsString( '([email])', $context['error'] );
		$this->assertStringNotContainsString( 'private@example.com', wp_json_encode( Agend_Loop_Sync_Logger::recent( 500 ) ) );
	}

	#[Test]
	public function a_created_account_takes_the_member_off_the_retry_queue(): void {
		$this->turn_on();
		Agend_Loop_Sync_Provision_Retry::add( array( 7 => 'rate_limited' ) );
		Loop_Sync_Test_State::$responses['agend_apps_loop_sync_user'] = array(
			'success' => true,
			'data'    => array(
				'user_id'      => '00000000-0000-0000-0000-000000000007',
				'external_id'  => 1001,
				'provisioning' => array( 'outcome' => 'provisioned' ),
			),
		);

		( new Agend_Loop_Sync_User_Sync() )->sync_user( loop_sync_test_user( 7, '1001' ) );

		$this->assertSame( array(), Agend_Loop_Sync_Provision_Retry::queued() );
		$this->assertSame( 'provisioned', $this->logged( 'info', 'User synced' )[0]['context']['provisioning'] );
	}

	#[Test]
	public function the_backfill_asks_for_accounts_and_counts_each_outcome(): void {
		$this->turn_on();
		foreach ( array( 1 => '1001', 2 => '1002', 3 => '1003', 4 => '1004', 5 => '1005' ) as $id => $number ) {
			loop_sync_test_user( $id, $number );
		}
		Loop_Sync_Test_State::$responses['agend_apps_loop_bulk_sync_users'] = $this->bulk_answer(
			array(
				1001 => 'provisioned',
				1002 => 'linked',
				1003 => 'already_linked',
				1004 => 'skipped:conflict',
				1005 => 'skipped:provisioning_failed',
			)
		);

		$summary = Agend_Loop_Sync_User_Sync::backfill_all();

		$this->assertTrue( Loop_Sync_Test_State::calls_to( 'agend_apps_loop_bulk_sync_users' )[0][2] );
		$this->assertSame( 1, $summary['provisioning']['provisioned'] );
		$this->assertSame( 1, $summary['provisioning']['linked'] );
		$this->assertSame( 1, $summary['provisioning']['already_linked'] );
		$this->assertSame( 2, $summary['provisioning']['skipped'] );
		$this->assertSame(
			array(
				'conflict'            => 1,
				'provisioning_failed' => 1,
			),
			$summary['provisioning']['reasons']
		);
		// Only the passing fault is retried; a conflict needs someone to fix it.
		$this->assertSame( array( 5 ), array_keys( Agend_Loop_Sync_Provision_Retry::queued() ) );
		$this->assertSame( 1, $summary['provisioning']['queued'] );

		$skipped = $this->logged( 'warning', 'Loop account not created' );
		$this->assertCount( 2, $skipped );
		$this->assertStringNotContainsString( '@example.com', wp_json_encode( Agend_Loop_Sync_Logger::recent( 500 ) ) );
	}

	#[Test]
	public function once_the_hourly_limit_is_hit_the_rest_wait_for_the_retry_unsent(): void {
		$this->turn_on();
		for ( $id = 1; $id <= 150; $id++ ) {
			loop_sync_test_user( $id, (string) ( 1000 + $id ) );
		}
		$first = array();
		for ( $id = 1; $id <= 100; $id++ ) {
			$first[ 1000 + $id ] = 100 === $id ? 'skipped:rate_limited' : 'provisioned';
		}
		Loop_Sync_Test_State::$responses['agend_apps_loop_bulk_sync_users'] = $this->bulk_answer( $first );

		$summary = Agend_Loop_Sync_User_Sync::backfill_all();

		$this->assertCount( 1, Loop_Sync_Test_State::calls_to( 'agend_apps_loop_bulk_sync_users' ) );
		$this->assertSame( 50, $summary['provisioning']['deferred'] );
		$this->assertCount( 51, Agend_Loop_Sync_Provision_Retry::queued() );
		$this->assertTrue( $this->retry_scheduled() );
	}

	#[Test]
	public function a_scope_refusal_stops_the_backfill_with_a_clear_error(): void {
		$this->turn_on();
		for ( $id = 1; $id <= 150; $id++ ) {
			loop_sync_test_user( $id, (string) ( 1000 + $id ) );
		}
		Loop_Sync_Test_State::$responses['agend_apps_loop_bulk_sync_users'] = $this->gateway_error( 403, 'INSUFFICIENT_SCOPES', 'Missing required scopes: loop.users.provision' );

		$summary = Agend_Loop_Sync_User_Sync::backfill_all();

		$this->assertCount( 1, Loop_Sync_Test_State::calls_to( 'agend_apps_loop_bulk_sync_users' ) );
		$this->assertTrue( $summary['provisioning']['refused'] );
		$this->assertStringContainsString( 'loop.users.provision', $summary['errors'][0] );
		$this->assertSame( array(), Agend_Loop_Sync_Provision_Retry::queued() );
	}

	#[Test]
	public function a_batch_that_gets_no_answer_is_queued_for_the_retry(): void {
		$this->turn_on();
		loop_sync_test_user( 1, '1001' );
		loop_sync_test_user( 2, '1002' );
		Loop_Sync_Test_State::$responses['agend_apps_loop_bulk_sync_users'] = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );

		Agend_Loop_Sync_User_Sync::backfill_all();

		$this->assertSame(
			array( 'request_failed', 'request_failed' ),
			array_column( Agend_Loop_Sync_Provision_Retry::queued(), 'reason' )
		);
	}

	#[Test]
	public function the_retry_sends_queued_members_again_and_keeps_those_still_waiting(): void {
		$this->turn_on();
		loop_sync_test_user( 1, '1001' );
		loop_sync_test_user( 2, '1002' );
		loop_sync_test_user( 3, '1003' );
		Agend_Loop_Sync_Provision_Retry::add(
			array(
				1 => 'rate_limited',
				2 => 'rate_limited',
			)
		);
		Agend_Test_WP::$scheduled_events = array();
		Loop_Sync_Test_State::$responses['agend_apps_loop_bulk_sync_users'] = $this->bulk_answer(
			array(
				1001 => 'provisioned',
				1002 => 'skipped:rate_limited',
			)
		);

		Agend_Loop_Sync_Provision_Retry::run();

		$sent = Loop_Sync_Test_State::calls_to( 'agend_apps_loop_bulk_sync_users' )[0];
		$this->assertSame( array( 1001, 1002 ), array_column( $sent[0], 'external_id' ), 'only queued members are sent' );
		$this->assertTrue( $sent[2] );
		$queue = Agend_Loop_Sync_Provision_Retry::queued();
		$this->assertSame( array( 2 ), array_keys( $queue ) );
		$this->assertSame( 1, $queue[2]['attempts'] );
		$this->assertTrue( $this->retry_scheduled() );
	}

	#[Test]
	public function the_retry_gives_up_after_the_attempt_limit(): void {
		$this->turn_on();
		loop_sync_test_user( 1, '1001' );
		update_option(
			Agend_Loop_Sync_Settings::OPT_PROVISION_RETRY,
			array(
				1 => array(
					'reason'   => 'provisioning_failed',
					'attempts' => Agend_Loop_Sync_Provision_Retry::MAX_ATTEMPTS - 1,
					'since'    => 1,
				),
			)
		);
		Loop_Sync_Test_State::$responses['agend_apps_loop_bulk_sync_users'] = $this->bulk_answer( array( 1001 => 'skipped:provisioning_failed' ) );

		Agend_Loop_Sync_Provision_Retry::run();

		$this->assertSame( array(), Agend_Loop_Sync_Provision_Retry::queued() );
		$this->assertSame( 1, $this->logged( 'error', 'Gave up creating a Loop account' )[0]['context']['wp_id'] );
		$this->assertFalse( $this->retry_scheduled() );
	}

	#[Test]
	public function turning_provisioning_off_empties_the_queue_at_the_next_retry(): void {
		Agend_Loop_Sync_Provision_Retry::add( array( 1 => 'rate_limited' ) );

		Agend_Loop_Sync_Provision_Retry::run();

		$this->assertSame( array(), Agend_Loop_Sync_Provision_Retry::queued() );
		$this->assertSame( array(), Loop_Sync_Test_State::calls_to( 'agend_apps_loop_bulk_sync_users' ) );
		$this->assertFalse( $this->retry_scheduled() );
	}

	#[Test]
	public function with_member_sync_off_the_retry_waits_and_sends_nothing(): void {
		$this->turn_on();
		update_option( 'agend_loop_sync_user_sync_enabled', '0' );
		loop_sync_test_user( 1, '1001' );
		Agend_Loop_Sync_Provision_Retry::add( array( 1 => 'rate_limited' ) );
		Agend_Test_WP::$scheduled_events = array();

		Agend_Loop_Sync_Provision_Retry::run();

		$this->assertSame( array(), Loop_Sync_Test_State::calls_to( 'agend_apps_loop_bulk_sync_users' ) );
		$this->assertCount( 1, Agend_Loop_Sync_Provision_Retry::queued() );
		$this->assertTrue( $this->retry_scheduled() );
	}

	#[Test]
	public function a_member_who_left_a_channel_is_read_as_such(): void {
		$hold = Agend_Loop_Sync_Gateway::normalise_hold(
			array(
				'external_id' => 1001,
				'kind'        => 'member_left',
				'role_cap'    => null,
				'reason'      => null,
				'held_since'  => '2026-11-24T05:00:00.000Z',
			)
		);

		$this->assertSame( 'member_left', $hold['kind'] );

		$summary = array(
			'held_members' => 0,
			'held_results' => array(),
		);
		Agend_Loop_Sync_Gateway::record_holds( 'All Fellows', array( 'held' => array( $hold ) ), $summary, 'rule' );

		$this->assertCount( 1, $this->logged( 'info', 'Rule member left the channel in Loop' ) );
	}
}
