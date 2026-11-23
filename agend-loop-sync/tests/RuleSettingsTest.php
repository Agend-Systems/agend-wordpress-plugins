<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\LoopSync;

use Agend_Loop_Sync_Rule_Sync;
use Agend_Loop_Sync_Settings;
use Agend_Test_WP;
use Loop_Sync_Test_State;
use PHPUnit\Framework\Attributes\Test;

require_once __DIR__ . '/RuleTestCase.php';

if ( ! defined( 'AGEND_LOOP_SYNC_DIR' ) ) {
	define( 'AGEND_LOOP_SYNC_DIR', AGEND_TESTS_ROOT . '/agend-loop-sync/' );
}

/**
 * Rule storage and sanitising, the crons and triggers, and the settings screen.
 */
final class RuleSettingsTest extends RuleTestCase {

	protected function tearDown(): void {
		unset( $_GET['edit_rule'], $_GET['rules'], $_GET['rules_code'] );
		parent::tearDown();
	}

	private function error_code( $result ): string {
		$this->assertInstanceOf( \WP_Error::class, $result );

		return (string) $result->get_error_code();
	}

	private function events_for( string $hook ): array {
		return array_values( array_filter( Agend_Test_WP::$scheduled_events, static fn( array $event ): bool => $event['hook'] === $hook ) );
	}

	/* ------------------------------ Sanitising ------------------------------ */

	#[Test]
	public function a_rule_is_cleaned_to_the_shape_the_runner_reads(): void {
		$rule = Agend_Loop_Sync_Settings::sanitize_rule(
			array(
				'slug'              => '  All Fellows!! ',
				'name'              => '  <b>All</b> Fellows ',
				'enabled'           => '1',
				'match'             => 'sometimes',
				'membership_types'  => "Fellow\nfellow\n\n Affiliate ",
				'membership_levels' => array( 'Gold', 'GOLD' ),
				'event_ids'         => "EV1\nEV1",
			)
		);

		$this->assertSame( 'all-fellows', $rule['slug'] );
		$this->assertSame( 'All Fellows', $rule['name'] );
		$this->assertTrue( $rule['enabled'] );
		$this->assertSame( 'all', $rule['match'], 'an unknown match mode falls back to all' );
		$this->assertSame( array( 'Fellow', 'Affiliate' ), $rule['membership_types'], 'case-only duplicates collapse' );
		$this->assertSame( array( 'Gold' ), $rule['membership_levels'] );
		$this->assertSame( array( 'EV1' ), $rule['event_ids'] );
		$this->assertTrue( $rule['apply_access_statuses'], 'the lapse rule applies unless turned off' );
		$this->assertTrue( $rule['member_can_leave'] );
		$this->assertTrue( $rule['allow_manual_members'] );
	}

	#[Test]
	public function a_slug_keeps_only_lower_case_letters_numbers_and_single_hyphens(): void {
		$this->assertSame( 'a-b-c-1', Agend_Loop_Sync_Settings::sanitize_rule_slug( '  A_b  c--1 ' ) );
		$this->assertSame( '', Agend_Loop_Sync_Settings::sanitize_rule_slug( '!!!' ) );
		$this->assertSame( 64, strlen( Agend_Loop_Sync_Settings::sanitize_rule_slug( str_repeat( 'a', 100 ) ) ) );
	}

	#[Test]
	public function always_include_keeps_valid_numbers_and_the_highest_role(): void {
		$list = Agend_Loop_Sync_Settings::sanitize_always_include( "100 = moderator\n100 = owner\nABC = owner\n200\n300 = admin\n" );

		$this->assertSame(
			array(
				array( 'membership_number' => '100', 'role' => 'owner' ),
				array( 'membership_number' => '200', 'role' => 'member' ),
				array( 'membership_number' => '300', 'role' => 'member' ),
			),
			$list,
			'a bad number is dropped, an unknown role (admin) becomes member'
		);
	}

	#[Test]
	public function the_saved_rules_are_cleaned_on_every_read(): void {
		update_option(
			Agend_Loop_Sync_Settings::OPT_RULES,
			array(
				array( 'slug' => 'a', 'name' => 'Alpha' ),
				array( 'slug' => 'a', 'name' => 'Again' ),
				array( 'slug' => 'b', 'name' => 'alpha' ),
				array( 'slug' => '', 'name' => 'No slug' ),
				array( 'slug' => 'c', 'name' => '' ),
				'junk',
				array( 'slug' => 'd', 'name' => 'Delta' ),
			)
		);

		$this->assertSame( array( 'a', 'd' ), array_column( Agend_Loop_Sync_Settings::get_rules(), 'slug' ) );

		update_option( Agend_Loop_Sync_Settings::OPT_RULES, 'garbage' );
		$this->assertSame( array(), Agend_Loop_Sync_Settings::get_rules() );
	}

	#[Test]
	public function access_statuses_are_known_ones_and_always_include_financial(): void {
		$this->assertSame( array( 'Financial', 'Renewal Due', 'Overdue' ), Agend_Loop_Sync_Settings::get_access_statuses(), 'the default' );
		$this->assertSame( array( 'Financial' ), Agend_Loop_Sync_Settings::sanitize_access_statuses( null ) );
		$this->assertSame( array( 'Financial', 'Renewal Due' ), Agend_Loop_Sync_Settings::sanitize_access_statuses( array( 'renewal due', 'Bogus', '<b>Pending</b>' ) ) );
		$this->assertSame( array( 'Financial', 'Reinstatable' ), Agend_Loop_Sync_Settings::sanitize_access_statuses( array( 'Reinstatable' ) ) );

		update_option( Agend_Loop_Sync_Settings::OPT_ACCESS_STATUSES, 'not an array' );
		$this->assertSame( array( 'Financial', 'Renewal Due', 'Overdue' ), Agend_Loop_Sync_Settings::get_access_statuses() );
	}

	#[Test]
	public function the_rule_schedule_is_one_of_the_allowed_ones(): void {
		$this->assertSame( 'twicedaily', Agend_Loop_Sync_Settings::sanitize_rule_schedule( 'twicedaily' ) );
		$this->assertSame( 'hourly', Agend_Loop_Sync_Settings::sanitize_rule_schedule( 'weekly' ) );
		$this->assertSame( 'hourly', Agend_Loop_Sync_Settings::get_rule_schedule() );
	}

	/* --------------------------- Saving and deleting --------------------------- */

	#[Test]
	public function a_new_rule_takes_its_slug_from_its_name_when_none_is_given(): void {
		$saved = Agend_Loop_Sync_Settings::save_rule( array( 'name' => 'All Fellows' ) );

		$this->assertSame( 'all-fellows', $saved['slug'] );
		$this->assertSame( array( 'all-fellows' ), array_column( Agend_Loop_Sync_Settings::get_rules(), 'slug' ) );
	}

	#[Test]
	public function a_saved_rules_slug_cannot_change(): void {
		$this->add_rule( 'All Fellows' );

		$saved = Agend_Loop_Sync_Settings::save_rule( array( 'slug' => 'renamed', 'name' => 'Fellows renamed', 'membership_types' => 'Fellow' ), 'all-fellows' );

		$this->assertSame( 'all-fellows', $saved['slug'] );
		$rules = Agend_Loop_Sync_Settings::get_rules();
		$this->assertCount( 1, $rules );
		$this->assertSame( 'all-fellows', $rules[0]['slug'] );
		$this->assertSame( 'Fellows renamed', $rules[0]['name'] );
		$this->assertSame( array( 'Fellow' ), $rules[0]['membership_types'] );
	}

	#[Test]
	public function slugs_must_be_unique(): void {
		$this->add_rule( 'Fellows' );

		$this->assertSame( 'slug_taken', $this->error_code( Agend_Loop_Sync_Settings::save_rule( array( 'slug' => 'FELLOWS', 'name' => 'Other' ) ) ) );
		$this->assertCount( 1, Agend_Loop_Sync_Settings::get_rules() );
	}

	#[Test]
	public function names_must_be_unique_ignoring_case_and_a_rule_may_keep_its_own(): void {
		$this->add_rule( 'Fellows' );
		$this->add_rule( 'Specialists' );

		$this->assertSame( 'name_taken', $this->error_code( Agend_Loop_Sync_Settings::save_rule( array( 'name' => 'fellows', 'slug' => 'other' ) ) ) );
		$this->assertSame( 'name_taken', $this->error_code( Agend_Loop_Sync_Settings::save_rule( array( 'name' => 'FELLOWS' ), 'specialists' ) ) );
		$this->assertIsArray( Agend_Loop_Sync_Settings::save_rule( array( 'name' => 'FELLOWS' ), 'fellows' ), 'saving a rule under its own name is fine' );
	}

	#[Test]
	public function a_rule_needs_a_name_and_a_usable_slug(): void {
		$this->assertSame( 'invalid_name', $this->error_code( Agend_Loop_Sync_Settings::save_rule( array( 'name' => '  ', 'slug' => 'x' ) ) ) );
		$this->assertSame( 'invalid_slug', $this->error_code( Agend_Loop_Sync_Settings::save_rule( array( 'name' => '!!!' ) ) ) );
		$this->assertSame( 'rule_not_found', $this->error_code( Agend_Loop_Sync_Settings::save_rule( array( 'name' => 'X' ), 'missing' ) ) );
		$this->assertSame( array(), Agend_Loop_Sync_Settings::get_rules() );
	}

	#[Test]
	public function the_number_of_rules_is_capped(): void {
		for ( $i = 1; $i <= Agend_Loop_Sync_Settings::RULES_MAX; $i++ ) {
			$this->add_rule( 'Rule ' . $i );
		}

		$this->assertSame( 'too_many_rules', $this->error_code( Agend_Loop_Sync_Settings::save_rule( array( 'name' => 'One too many' ) ) ) );
	}

	#[Test]
	public function a_rule_can_be_deleted(): void {
		$this->add_rule( 'Fellows' );
		$this->add_rule( 'Specialists' );

		$this->assertTrue( Agend_Loop_Sync_Settings::delete_rule( 'fellows' ) );
		$this->assertFalse( Agend_Loop_Sync_Settings::delete_rule( 'fellows' ) );
		$this->assertSame( array( 'specialists' ), array_column( Agend_Loop_Sync_Settings::get_rules(), 'slug' ) );
	}

	/* ------------------------------ Cron and triggers ------------------------------ */

	#[Test]
	public function the_rule_cron_is_scheduled_only_while_the_plugin_and_rule_sync_are_on(): void {
		Agend_Loop_Sync_Settings::sync_rules_cron();
		$events = $this->events_for( AGEND_LOOP_SYNC_RULES_CRON_HOOK );
		$this->assertCount( 1, $events );
		$this->assertSame( 'hourly', $events[0]['recurrence'] );

		Agend_Loop_Sync_Settings::sync_rules_cron();
		$this->assertCount( 1, $this->events_for( AGEND_LOOP_SYNC_RULES_CRON_HOOK ), 'must not double-schedule' );

		update_option( 'agend_loop_sync_rule_sync_enabled', '0' );
		Agend_Loop_Sync_Settings::sync_rules_cron();
		$this->assertSame( array(), $this->events_for( AGEND_LOOP_SYNC_RULES_CRON_HOOK ) );

		update_option( 'agend_loop_sync_rule_sync_enabled', '1' );
		Agend_Loop_Sync_Settings::sync_rules_cron();
		update_option( 'agend_loop_sync_enabled', '0' );
		Agend_Loop_Sync_Settings::sync_rules_cron();
		$this->assertSame( array(), $this->events_for( AGEND_LOOP_SYNC_RULES_CRON_HOOK ), 'the master switch also clears it' );
	}

	#[Test]
	public function the_rule_cron_does_not_touch_the_committee_cron(): void {
		Agend_Loop_Sync_Settings::ensure_committee_cron();
		Agend_Loop_Sync_Settings::sync_rules_cron();
		update_option( 'agend_loop_sync_rule_sync_enabled', '0' );
		Agend_Loop_Sync_Settings::sync_rules_cron();

		$this->assertCount( 1, $this->events_for( AGEND_LOOP_SYNC_CRON_HOOK ) );
	}

	#[Test]
	public function changing_the_schedule_reschedules_the_rule_cron(): void {
		Agend_Loop_Sync_Settings::sync_rules_cron();
		update_option( Agend_Loop_Sync_Settings::OPT_RULE_SCHEDULE, 'daily' );

		Agend_Loop_Sync_Settings::reschedule_rules_cron();

		$events = $this->events_for( AGEND_LOOP_SYNC_RULES_CRON_HOOK );
		$this->assertCount( 1, $events );
		$this->assertSame( 'daily', $events[0]['recurrence'] );
	}

	#[Test]
	public function turning_rule_sync_off_also_clears_a_pending_debounced_run(): void {
		$this->add_rule( 'Everyone' );
		Agend_Loop_Sync_Settings::schedule_debounced_rule_run();
		$this->assertCount( 1, $this->events_for( AGEND_LOOP_SYNC_RULES_DEBOUNCE_HOOK ) );

		update_option( 'agend_loop_sync_rule_sync_enabled', '0' );
		Agend_Loop_Sync_Settings::sync_rules_cron();

		$this->assertSame( array(), $this->events_for( AGEND_LOOP_SYNC_RULES_DEBOUNCE_HOOK ) );
	}

	#[Test]
	public function a_change_in_upbeat_schedules_one_debounced_run_about_five_minutes_out(): void {
		$this->add_rule( 'Everyone' );
		( new Agend_Loop_Sync_Rule_Sync() )->register();

		$before = time();
		do_action( 'iugo_membership_kiosk_invalidate_member_caches_complete', '100', 'member_updated' );
		do_action( 'iugo_membership_kiosk_invalidate_member_caches_complete', '200', 'member_updated' );
		do_action( 'iugo_membership_kiosk_invalidate_member_caches_complete', '100', 'member_updated' );

		$events = $this->events_for( AGEND_LOOP_SYNC_RULES_DEBOUNCE_HOOK );
		$this->assertCount( 1, $events, 'a burst of changes shares one run' );
		$this->assertGreaterThanOrEqual( $before + 299, $events[0]['timestamp'] );
		$this->assertLessThanOrEqual( time() + 301, $events[0]['timestamp'] );
		$this->assertSame( array(), Loop_Sync_Test_State::$calls, 'nothing runs in the triggering request' );
		$this->assertSame( array(), Loop_Sync_Test_State::$upbeat_calls );
	}

	#[Test]
	public function a_member_signing_in_schedules_one_debounced_run(): void {
		$this->add_rule( 'Everyone' );
		( new Agend_Loop_Sync_Rule_Sync() )->register();
		$member = loop_sync_test_user( 1, '100' );
		$staff  = loop_sync_test_user( 2, null );

		do_action( 'wp_login', 'staff', $staff );
		$this->assertSame( array(), $this->events_for( AGEND_LOOP_SYNC_RULES_DEBOUNCE_HOOK ), 'a user with no membership number changes nothing' );

		do_action( 'wp_login', 'member1', $member );
		do_action( 'wp_login', 'member1', $member );
		$this->assertCount( 1, $this->events_for( AGEND_LOOP_SYNC_RULES_DEBOUNCE_HOOK ) );
		$this->assertSame( array(), Loop_Sync_Test_State::$calls );
	}

	#[Test]
	public function a_debounced_run_is_not_scheduled_when_it_could_not_do_anything(): void {
		( new Agend_Loop_Sync_Rule_Sync() )->register();

		do_action( 'iugo_membership_kiosk_invalidate_member_caches_complete', '100', 'x' );
		$this->assertSame( array(), $this->events_for( AGEND_LOOP_SYNC_RULES_DEBOUNCE_HOOK ), 'no enabled rule' );

		$this->add_rule( 'Everyone' );
		update_option( 'agend_loop_sync_rule_sync_enabled', '0' );
		do_action( 'iugo_membership_kiosk_invalidate_member_caches_complete', '100', 'x' );
		$this->assertSame( array(), $this->events_for( AGEND_LOOP_SYNC_RULES_DEBOUNCE_HOOK ), 'rule sync off' );

		update_option( 'agend_loop_sync_rule_sync_enabled', '1' );
		update_option( 'agend_loop_sync_enabled', '0' );
		do_action( 'iugo_membership_kiosk_invalidate_member_caches_complete', '100', 'x' );
		$this->assertSame( array(), $this->events_for( AGEND_LOOP_SYNC_RULES_DEBOUNCE_HOOK ), 'plugin off' );
	}

	#[Test]
	public function both_cron_hooks_run_the_rule_sync(): void {
		$this->add_rule( 'Everyone' );
		$this->add_member( 1, '100' );
		( new Agend_Loop_Sync_Rule_Sync( new \Loop_Sync_Test_Upbeat() ) )->register();

		do_action( AGEND_LOOP_SYNC_RULES_DEBOUNCE_HOOK );
		do_action( AGEND_LOOP_SYNC_RULES_CRON_HOOK );

		$this->assertCount( 2, Loop_Sync_Test_State::calls_to( 'agend_apps_loop_sync_committee' ) );
	}

	/* ------------------------------ Settings screen ------------------------------ */

	#[Test]
	public function the_admin_screen_registers_the_rule_actions(): void {
		require_once AGEND_LOOP_SYNC_DIR . 'admin/class-agend-loop-sync-admin.php';

		( new \Agend_Loop_Sync_Admin( $this->committee_sync(), $this->rule_sync() ) )->register();

		foreach ( array( 'run_rules', 'save_rule', 'delete_rule' ) as $action ) {
			$this->assertNotEmpty( Agend_Test_WP::$actions[ 'admin_post_agend_loop_sync_' . $action ] ?? array(), $action );
		}
	}

	private function render_page(): string {
		ob_start();
		require AGEND_LOOP_SYNC_DIR . 'templates/admin-settings.php';

		return (string) ob_get_clean();
	}

	#[Test]
	public function the_rules_section_is_hidden_while_rule_sync_is_off(): void {
		update_option( 'agend_loop_sync_rule_sync_enabled', '0' );
		$this->add_rule( 'Fellows' );

		$html = $this->render_page();

		$this->assertStringContainsString( 'Community rules are off', $html );
		$this->assertStringNotContainsString( 'agend_loop_sync_save_rule', $html );
		$this->assertStringNotContainsString( 'agend_loop_sync_run_rules', $html );
	}

	#[Test]
	public function the_rules_section_warns_when_the_master_switch_is_off(): void {
		update_option( 'agend_loop_sync_enabled', '0' );
		$this->add_rule( 'Fellows' );

		$this->assertStringContainsString( 'rules will not run until it is turned on', $this->render_page() );

		update_option( 'agend_loop_sync_enabled', '1' );
		$this->assertStringNotContainsString( 'rules will not run until it is turned on', $this->render_page() );
	}

	#[Test]
	public function a_not_run_result_shows_its_reason_instead_of_the_success_notice(): void {
		$_GET['rules']      = 'not_run';
		$_GET['rules_code'] = 'disabled';
		$html               = $this->render_page();
		unset( $_GET['rules'], $_GET['rules_code'] );

		$this->assertStringContainsString( 'Loop Sync is turned off (Enable plugin, above), so no rules ran.', $html );
		$this->assertStringNotContainsString( 'Community rules run.', $html );
	}

	#[Test]
	public function the_rules_section_lists_rules_escaped_with_run_delete_and_save_forms(): void {
		$this->add_rule( 'Fellows & "Friends"', array( 'slug' => 'fellows', 'membership_types' => 'Fellow' ) );

		$html = $this->render_page();

		$this->assertStringContainsString( 'agend_loop_sync_run_rules', $html );
		$this->assertStringContainsString( 'agend_loop_sync_delete_rule', $html );
		$this->assertStringContainsString( 'agend_loop_sync_save_rule', $html );
		$this->assertStringContainsString( 'rule:fellows', $html );
		$this->assertStringContainsString( 'Fellows &amp; &quot;Friends&quot;', $html );
		$this->assertStringNotContainsString( 'Fellows & "Friends"', $html );
		$this->assertStringContainsString( 'Type: Fellow', $html );
		$this->assertStringContainsString( 'Add a rule', $html );
	}

	#[Test]
	public function editing_a_rule_locks_its_slug(): void {
		$this->add_rule( 'Fellows' );
		$_GET['edit_rule'] = 'fellows';

		$html = $this->render_page();

		$this->assertStringContainsString( 'Edit rule', $html );
		$this->assertMatchesRegularExpression( '/name="rule\[slug\]"[^>]*readonly/', $html );
		$this->assertStringContainsString( 'name="original_slug" value="fellows"', $html );
	}

	#[Test]
	public function a_new_rule_form_leaves_the_slug_open(): void {
		$html = $this->render_page();

		$this->assertDoesNotMatchRegularExpression( '/name="rule\[slug\]"[^>]*readonly/', $html );
		$this->assertStringContainsString( 'name="original_slug" value=""', $html );
	}

	#[Test]
	public function the_last_run_shows_each_rules_counts(): void {
		$this->add_rule( 'Everyone' );
		$this->add_member( 1, '100' );
		Loop_Sync_Test_State::$responses['agend_apps_loop_sync_committee'] = array(
			'success' => true,
			'data'    => array( 'applied' => 1, 'skipped' => 2, 'removed' => 3, 'held' => array( array( 'external_id' => 100, 'kind' => 'removal' ) ) ),
		);
		$this->rule_sync()->run();

		$html = $this->render_page();

		$this->assertStringContainsString( '1 matched, 1 applied, 3 removed, 2 skipped (no Loop account yet), 1 held', $html );
		$this->assertStringContainsString( 'Rule synced', $html, 'the rule activity log lists the run' );
	}

	#[Test]
	public function the_access_statuses_are_offered_as_checkboxes_with_financial_fixed(): void {
		$html = $this->render_page();

		foreach ( Agend_Loop_Sync_Settings::ACCESS_STATUS_CHOICES as $status ) {
			$this->assertStringContainsString( 'value="' . $status . '"', $html );
		}
		$this->assertMatchesRegularExpression( '/value="Financial"[^>]*checked[^>]*disabled/', $html );
		$this->assertMatchesRegularExpression( '/value="Overdue"[^>]*checked/', $html );
		$this->assertDoesNotMatchRegularExpression( '/value="Reinstatable"[^>]*checked/', $html );
	}
}
