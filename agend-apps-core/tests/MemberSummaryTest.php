<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend_Apps_API;
use Agend_Apps_Cache;
use Agend_Apps_Endpoint_Scopes;
use Agend_Apps_Key_Scopes;
use Agend_Apps_Logger;
use Agend_Test_WP;
use Agend\Tests\AppsCore\MemoryLogStore;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use WP_Error;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/logging/class-agend-apps-log-redactor.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/logging/class-agend-apps-log-store.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/logging/class-agend-apps-logger.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/health.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-http-batch.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-key-scopes.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-endpoint-scopes.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/member-access-state.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/member-summary.php';
require_once __DIR__ . '/fixtures/memory-log-store.php';

/**
 * The member summary service: which reads a summary makes, how each part is
 * reduced from what Agend sends, and what the reads cost.
 *
 * The data cases run on a pinned copy of the gateway's shapes
 * (fixtures/member-summary-gateway.json). The fetch cases run the real API
 * client over its queued responses, so what is asserted about requests, member
 * tokens and stored copies is what leaves the plugin.
 */
final class MemberSummaryTest extends TestCase {

	private const NOW = 1794960000; // 2026-11-18 00:00:00 UTC.

	protected function setUp(): void {
		parent::setUp();
		Agend_Apps_Logger::set_instance( new Agend_Apps_Logger( new MemoryLogStore() ) );
		Agend_Apps_Endpoint_Scopes::set_map( null );
		Agend_Test_WP::set_filter( 'agend_apps_api_parallel', false );
		agend_apps_member_access_forget();
		$GLOBALS['agend_test_current_user_id'] = 42;

		// The client holds a member's reads for the rest of the request. A test
		// is a request of its own.
		( new \ReflectionProperty( Agend_Apps_API::class, 'identity_memo' ) )->setValue( agend_apps_api(), array() );
	}

	protected function tearDown(): void {
		Agend_Apps_Endpoint_Scopes::set_map( null );
		parent::tearDown();
	}

	// -----------------------------------------------------------------
	// Scene setting
	// -----------------------------------------------------------------

	/**
	 * The pinned gateway answers.
	 *
	 * @return array<string, mixed>
	 */
	private function gateway(): array {
		static $decoded = null;

		if ( null === $decoded ) {
			$decoded = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/member-summary-gateway.json' ), true );
		}

		return $decoded;
	}

	/**
	 * The profile's `data`, with changes laid over it.
	 *
	 * @param array $changes Top-level blocks to replace, `null` allowed.
	 * @return array
	 */
	private function profile( array $changes = array() ): array {
		return array_replace( $this->gateway()['profile']['data'], $changes );
	}

	private function withBearer( string $token = 'member-token' ): void {
		Agend_Test_WP::$filters['agend_apps_bearer_token'] = static fn(): string => $token;
	}

	private function know( array $scopes ): void {
		Agend_Apps_Key_Scopes::store_from_response( array( 'data' => array( 'scopes' => $scopes, 'app_ids' => array() ) ) );
	}

	private function ready(): array {
		return array( 'state' => 'ready', 'reason' => '', 'mode' => 'credentials', 'signed_in' => true, 'linked' => true, 'action' => 'none', 'retry_after' => 0, 'sign_in_url' => 'https://example.test/wp-login.php', 'initiate_url' => '', 'detail' => '' );
	}

	/**
	 * Queues the four answers of a full fetch, in the order they are sent.
	 */
	private function queueFull(): void {
		foreach ( array( 'profile', 'entitlements', 'memberships', 'tiers' ) as $name ) {
			Agend_Test_WP::queue_response( 200, $this->gateway()[ $name ] );
		}
	}

	/**
	 * The five parts the profile and the membership reads serve: what every full
	 * view had before the courses and reading lists were added.
	 */
	private function everything(): array {
		return array( 'sections' => array( 'membership', 'cpd', 'events', 'credentials', 'directory' ), 'greeting' => true );
	}

	/**
	 * Every part there is, the reading lists included.
	 */
	private function everyPart(): array {
		return array( 'sections' => agend_apps_member_summary_sections(), 'greeting' => true );
	}

	private function error( int $status, string $code = 'NOT_FOUND' ): array {
		return array( 'error' => array( 'code' => $code, 'message' => 'The gateway said no.' ) );
	}

	/**
	 * The path of each request that left the plugin, in order.
	 *
	 * @return string[]
	 */
	private function paths(): array {
		return array_map(
			static fn( array $request ): string => (string) wp_parse_url( $request['url'], PHP_URL_PATH ),
			Agend_Test_WP::$requests
		);
	}

	private function authorisationOf( int $index ): ?string {
		return Agend_Test_WP::$requests[ $index ]['headers']['Authorization'] ?? null;
	}

	// -----------------------------------------------------------------
	// Plan: which reads, and only those
	// -----------------------------------------------------------------

	#[Test]
	public function should_plan_three_reads_for_the_profile_parts_and_the_membership_and_a_fourth_for_the_tier_names(): void {
		$plan = agend_apps_member_summary_plan( $this->everything() );

		self::assertSame( array( 'profile', 'entitlements', 'memberships', 'tiers' ), $plan['reads'] );
		self::assertSame( array( 'membership', 'cpd', 'events', 'credentials', 'directory' ), $plan['sections'] );
		self::assertSame( array(), $plan['no_access'] );
	}

	#[Test]
	public function should_plan_one_more_read_for_each_of_the_courses_and_the_reading_lists(): void {
		$plan = agend_apps_member_summary_plan( $this->everyPart() );

		self::assertSame( array( 'profile', 'entitlements', 'memberships', 'tiers', 'enrollments', 'collections' ), $plan['reads'], 'six reads is the most a view can cost' );
		self::assertSame( agend_apps_member_summary_sections(), $plan['sections'] );

		self::assertSame( array( 'enrollments' ), agend_apps_member_summary_plan( array( 'sections' => array( 'courses' ), 'greeting' => false ) )['reads'] );
		self::assertSame( array( 'collections' ), agend_apps_member_summary_plan( array( 'sections' => array( 'reading' ), 'greeting' => false ) )['reads'] );
	}

	#[Test]
	public function should_rule_out_courses_and_reading_lists_for_a_key_without_their_permissions(): void {
		$this->know( array( 'crm.contacts.browse', 'crm.memberships.browse', 'crm.tiers.browse' ) );
		Agend_Apps_Key_Scopes::store_from_response( array( 'data' => array( 'scopes' => array( 'crm.contacts.browse', 'crm.memberships.browse', 'crm.tiers.browse' ), 'app_ids' => array( 'crm' ) ) ) );

		$plan = agend_apps_member_summary_plan( $this->everyPart() );

		self::assertSame( array( 'courses', 'reading' ), $plan['no_access'] );
		self::assertSame( array( 'profile', 'entitlements', 'memberships', 'tiers' ), $plan['reads'], 'no read is made for a part the key lacks' );
	}

	#[Test]
	public function should_plan_one_read_when_only_the_profile_parts_are_on(): void {
		$plan = agend_apps_member_summary_plan( array( 'sections' => array( 'cpd', 'events', 'credentials', 'directory' ), 'greeting' => true ) );

		self::assertSame( array( 'profile' ), $plan['reads'], 'four parts and the greeting, one read' );
	}

	#[Test]
	public function should_plan_three_reads_for_the_membership_alone(): void {
		$plan = agend_apps_member_summary_plan( array( 'sections' => array( 'membership' ), 'greeting' => false ) );

		self::assertSame( array( 'entitlements', 'memberships', 'tiers' ), $plan['reads'] );
	}

	#[Test]
	public function should_add_the_profile_for_a_greeting_even_when_it_is_the_only_profile_part(): void {
		$plan = agend_apps_member_summary_plan( array( 'sections' => array( 'membership' ), 'greeting' => true ) );

		self::assertSame( array( 'profile', 'entitlements', 'memberships', 'tiers' ), $plan['reads'] );
	}

	#[Test]
	public function should_plan_nothing_for_parts_that_are_switched_off(): void {
		self::assertSame( array(), agend_apps_member_summary_plan( array( 'sections' => array(), 'greeting' => false ) )['reads'] );
		self::assertSame( array(), agend_apps_member_summary_plan( array() )['reads'] );
	}

	#[Test]
	public function should_never_plan_a_part_it_does_not_know(): void {
		$plan = agend_apps_member_summary_plan( array( 'sections' => array( 'community', 'forum', '../x', 'cpd' ), 'greeting' => false ) );

		self::assertSame( array( 'cpd' ), $plan['sections'] );
		self::assertSame( array( 'profile' ), $plan['reads'] );
	}

	#[Test]
	public function should_rule_out_a_part_whose_permission_the_key_is_known_to_lack(): void {
		$this->know( array( 'crm.contacts.browse', 'crm.tiers.browse' ) );

		$plan = agend_apps_member_summary_plan( $this->everything() );

		self::assertSame( array( 'membership' ), $plan['no_access'] );
		self::assertSame( array( 'profile' ), $plan['reads'], 'no read is spent on a part that would be refused' );
	}

	#[Test]
	public function should_still_show_the_membership_without_tier_names_when_only_that_permission_is_missing(): void {
		$this->know( array( 'crm.contacts.browse', 'crm.memberships.browse' ) );

		$plan = agend_apps_member_summary_plan( $this->everything() );

		self::assertSame( array(), $plan['no_access'] );
		self::assertSame( array( 'profile', 'entitlements', 'memberships' ), $plan['reads'] );
	}

	#[Test]
	public function should_assume_nothing_is_missing_while_the_keys_permissions_are_not_known(): void {
		self::assertSame( array(), agend_apps_member_summary_plan( $this->everything() )['no_access'] );
	}

	#[Test]
	public function should_let_a_site_that_switched_the_guard_off_read_anyway(): void {
		$this->know( array( 'crm.tiers.browse' ) );
		Agend_Test_WP::set_filter( 'agend_apps_api_block_unauthorised', false );

		self::assertSame( array(), agend_apps_member_summary_plan( $this->everything() )['no_access'] );
	}

	#[Test]
	public function should_drop_the_greeting_for_a_key_without_the_profile_permission(): void {
		$this->know( array( 'crm.memberships.browse' ) );

		$plan = agend_apps_member_summary_plan( array( 'sections' => array( 'membership' ), 'greeting' => true ) );

		self::assertFalse( $plan['greeting'] );
		self::assertSame( array( 'entitlements', 'memberships' ), $plan['reads'] );
	}

	// -----------------------------------------------------------------
	// CPD
	// -----------------------------------------------------------------

	#[Test]
	public function should_show_cpd_against_the_target_agend_resolved(): void {
		$cpd = agend_apps_member_summary_cpd( $this->profile()['lms'] );

		self::assertSame( 'ready', $cpd['status'] );
		self::assertSame( 'target', $cpd['form'] );
		self::assertSame( 14.5, $cpd['earned'] );
		self::assertSame( 16.0, $cpd['target'], 'the effective target, not the standard one' );
		self::assertSame( 'on_track', $cpd['pace'] );
		self::assertSame( 224, $cpd['days_left'] );
		self::assertSame( 'Joined part way through the year', $cpd['adjustment'] );
	}

	#[Test]
	public function should_show_only_what_was_earned_when_no_target_is_set_up(): void {
		$lms = $this->profile()['lms'];
		$lms['cpd_resolution'] = 'not_configured';
		$lms['cpd']            = null;

		$cpd = agend_apps_member_summary_cpd( $lms );

		self::assertSame( array( 'status' => 'ready', 'form' => 'total', 'earned' => 14.5, 'why' => 'not_set', 'completed' => 3 ), $cpd );
		self::assertArrayNotHasKey( 'target', $cpd, 'no target is ever invented' );
	}

	#[Test]
	public function should_say_no_target_applies_when_agend_says_so(): void {
		$lms = $this->profile()['lms'];
		$lms['cpd_resolution'] = 'not_applicable';
		$lms['cpd']            = null;

		self::assertSame( 'not_applicable', agend_apps_member_summary_cpd( $lms )['why'] );
	}

	#[Test]
	public function should_call_a_total_of_nothing_empty_not_unavailable(): void {
		$lms = array( 'cpd_resolution' => 'not_configured', 'cpd' => null, 'total_cpd_points' => 0 );

		self::assertSame( 'empty', agend_apps_member_summary_cpd( $lms )['status'] );
	}

	#[Test]
	public function should_show_zero_of_the_target_for_a_member_who_has_earned_nothing_yet(): void {
		$lms = array(
			'cpd_resolution'   => 'resolved',
			'total_cpd_points' => 0,
			'cpd'              => array( 'effective_target' => 20, 'earned' => 0, 'status' => 'behind', 'days_remaining' => 300 ),
		);

		$cpd = agend_apps_member_summary_cpd( $lms );

		self::assertSame( 'ready', $cpd['status'] );
		self::assertSame( 0.0, $cpd['earned'] );
		self::assertSame( 20.0, $cpd['target'] );
	}

	#[Test]
	public function should_call_a_failed_resolution_or_a_failed_block_unavailable(): void {
		$lms = $this->profile()['lms'];
		$lms['cpd_resolution'] = 'failed';

		self::assertSame( 'unavailable', agend_apps_member_summary_cpd( $lms )['status'] );
		self::assertSame( 'unavailable', agend_apps_member_summary_cpd( null )['status'], 'a null block is a failure, never "no points"' );
	}

	#[Test]
	public function should_not_guess_at_a_resolution_it_does_not_know(): void {
		$lms = $this->profile()['lms'];
		$lms['cpd_resolution'] = 'estimated';

		self::assertSame( 'unavailable', agend_apps_member_summary_cpd( $lms )['status'] );
	}

	#[Test]
	public function should_not_invent_a_target_when_a_resolved_block_has_none(): void {
		foreach ( array( null, 0, -3, 'soon' ) as $target ) {
			$lms = array( 'cpd_resolution' => 'resolved', 'total_cpd_points' => 7, 'cpd' => array( 'effective_target' => $target, 'earned' => 7 ) );

			$cpd = agend_apps_member_summary_cpd( $lms );

			self::assertSame( 'total', $cpd['form'], var_export( $target, true ) );
			self::assertSame( 7.0, $cpd['earned'] );
			self::assertArrayNotHasKey( 'target', $cpd );
		}
	}

	#[Test]
	public function should_cope_with_a_future_pace_verdict_by_drawing_the_bar_without_it(): void {
		$lms = $this->profile()['lms'];
		$lms['cpd']['status'] = 'ahead_of_pace';

		$cpd = agend_apps_member_summary_cpd( $lms );

		self::assertSame( 'ready', $cpd['status'] );
		self::assertSame( '', $cpd['pace'] );
		self::assertSame( 16.0, $cpd['target'] );
	}

	#[Test]
	public function should_cope_with_a_block_that_is_missing_most_of_its_fields(): void {
		self::assertSame( 'empty', agend_apps_member_summary_cpd( array() )['status'] );

		$cpd = agend_apps_member_summary_cpd( array( 'cpd' => array( 'effective_target' => 10 ), 'total_cpd_points' => 3 ) );

		self::assertSame( 'target', $cpd['form'], 'no resolution field: the block is read for what it holds' );
		self::assertSame( 3.0, $cpd['earned'], 'earned falls back to the total' );
		self::assertNull( $cpd['days_left'] );
		self::assertSame( '', $cpd['pace'] );
	}

	#[Test]
	public function should_read_numbers_that_arrive_as_text_and_ignore_ones_that_are_not_numbers(): void {
		$lms = array( 'cpd_resolution' => 'resolved', 'cpd' => array( 'effective_target' => '20', 'earned' => '12.5', 'days_remaining' => '30' ) );

		$cpd = agend_apps_member_summary_cpd( $lms );

		self::assertSame( 12.5, $cpd['earned'] );
		self::assertSame( 20.0, $cpd['target'] );
		self::assertSame( 30, $cpd['days_left'] );
		self::assertSame( 0.0, agend_apps_member_summary_cpd( array( 'total_cpd_points' => 'lots' ) )['earned'] );
	}

	#[Test]
	public function should_not_report_time_left_once_the_period_is_over(): void {
		$lms = $this->profile()['lms'];
		$lms['cpd']['days_remaining'] = -4;

		self::assertNull( agend_apps_member_summary_cpd( $lms )['days_left'] );
	}

	// -----------------------------------------------------------------
	// Events
	// -----------------------------------------------------------------

	#[Test]
	public function should_list_upcoming_events_soonest_first_and_cap_them(): void {
		$events = agend_apps_member_summary_events( $this->profile()['events'], 3 );

		self::assertSame( 'ready', $events['status'] );
		self::assertSame( array( 'Pricing your work, a webinar', 'Summer mixer', 'Annual conference' ), array_column( $events['items'], 'title' ) );
		self::assertSame( 4, $events['total'], 'the count before the cap, so the card can say there are more' );
		self::assertSame( 'pricing-your-work', $events['items'][0]['slug'] );
		self::assertSame( 'registered', $events['items'][0]['status'] );
	}

	#[Test]
	public function should_say_none_when_agend_answered_and_there_are_no_upcoming_events(): void {
		$events = agend_apps_member_summary_events( array( 'attended' => array(), 'upcoming' => array(), 'total_attended' => 0, 'total_registered' => 0 ), 3 );

		self::assertSame( 'empty', $events['status'] );
	}

	#[Test]
	public function should_call_a_failed_events_block_unavailable_never_none(): void {
		self::assertSame( 'unavailable', agend_apps_member_summary_events( null, 3 )['status'] );
	}

	#[Test]
	public function should_drop_an_event_with_no_title_and_put_one_with_no_date_last(): void {
		$events = agend_apps_member_summary_events(
			array(
				'upcoming' => array(
					array( 'event_title' => '', 'event_slug' => 'x', 'date' => '2026-12-01T00:00:00Z' ),
					array( 'event_title' => 'Undated', 'event_slug' => 'undated', 'status' => 'registered' ),
					array( 'event_title' => 'Dated', 'event_slug' => 'dated', 'date' => '2026-12-01T00:00:00Z', 'status' => 'registered' ),
					'junk',
				),
			),
			5
		);

		self::assertSame( array( 'Dated', 'Undated' ), array_column( $events['items'], 'title' ) );
		self::assertSame( '', $events['items'][1]['date'] );
	}

	#[Test]
	public function should_strip_markup_from_an_event_title(): void {
		$events = agend_apps_member_summary_events( array( 'upcoming' => array( array( 'event_title' => '<img src=x onerror=alert(1)>Gala <b>night</b>', 'event_slug' => 'gala', 'date' => '2026-12-01T00:00:00Z' ) ) ), 3 );

		self::assertSame( 'Gala night', $events['items'][0]['title'] );
	}

	#[Test]
	public function should_never_return_fewer_than_one_event_when_asked_for_none(): void {
		self::assertCount( 1, agend_apps_member_summary_events( $this->profile()['events'], 0 )['items'] );
	}

	// -----------------------------------------------------------------
	// Certificates and badges
	// -----------------------------------------------------------------

	#[Test]
	public function should_list_certificates_newest_first_with_a_code_only_where_it_looks_like_one(): void {
		$lms                         = $this->profile()['lms'];
		$lms['certificates'][2]['verification_code'] = '../../etc/passwd';

		$credentials = agend_apps_member_summary_credentials( $lms, 3 );

		self::assertSame( 'ready', $credentials['status'] );
		self::assertSame( array( 'Briefing a client well', 'Accessible design foundations', 'Contracts for studios' ), array_column( $credentials['certificates'], 'title' ) );
		self::assertSame( 'VER-3fa91c0b7d2e4a65b8c1', $credentials['certificates'][0]['code'] );
		self::assertSame( '', $credentials['certificates'][2]['code'], 'a value that is not a code never reaches a link' );
		self::assertSame( 6.5, $credentials['certificates'][1]['cpd'] );
		self::assertSame( 3, $credentials['certificates_total'] );
	}

	#[Test]
	public function should_accept_the_older_certificate_code_shape(): void {
		$credentials = agend_apps_member_summary_credentials( $this->profile()['lms'], 3 );

		self::assertSame( '4D2A9C7E', $credentials['certificates'][2]['code'] );
	}

	#[Test]
	public function should_cap_certificates_and_keep_the_count(): void {
		$credentials = agend_apps_member_summary_credentials( $this->profile()['lms'], 2 );

		self::assertCount( 2, $credentials['certificates'] );
		self::assertSame( 3, $credentials['certificates_total'] );
	}

	#[Test]
	public function should_list_badges_newest_first_and_keep_a_colour_only_if_it_is_one(): void {
		$lms                           = $this->profile()['lms'];
		$lms['badges'][0]['badge_color'] = 'red; background:url(//evil.example)';

		$credentials = agend_apps_member_summary_credentials( $lms, 3 );

		self::assertSame( array( 'Client briefing', 'Accessible design' ), array_column( $credentials['badges'], 'name' ) );
		self::assertSame( '#8A4FD6', $credentials['badges'][0]['colour'] );
		self::assertSame( '', $credentials['badges'][1]['colour'] );
	}

	#[Test]
	public function should_say_none_when_there_are_no_certificates_or_badges(): void {
		self::assertSame( 'empty', agend_apps_member_summary_credentials( array( 'certificates' => array(), 'badges' => array() ), 3 )['status'] );
		self::assertSame( 'empty', agend_apps_member_summary_credentials( array(), 3 )['status'] );
	}

	#[Test]
	public function should_call_a_failed_learning_block_unavailable_for_certificates_too(): void {
		self::assertSame( 'unavailable', agend_apps_member_summary_credentials( null, 3 )['status'] );
	}

	#[Test]
	public function should_drop_a_certificate_with_no_course_title(): void {
		$credentials = agend_apps_member_summary_credentials( array( 'certificates' => array( array( 'course_title' => '  ', 'verification_code' => 'VER-3fa91c0b7d2e4a65b8c1' ) ) ), 3 );

		self::assertSame( 'empty', $credentials['status'] );
	}

	#[Test]
	public function should_never_return_the_certificate_number_or_id_for_display_that_the_surface_does_not_need(): void {
		$certificate = agend_apps_member_summary_credentials( $this->profile()['lms'], 3 )['certificates'][0];

		self::assertSame( array( 'title', 'code', 'issued', 'cpd' ), array_keys( $certificate ) );
	}

	// -----------------------------------------------------------------
	// Directory
	// -----------------------------------------------------------------

	#[Test]
	public function should_show_the_listing_the_profile_carries(): void {
		$directory = agend_apps_member_summary_directory( $this->profile()['directory'] );

		self::assertSame( 'ready', $directory['status'] );
		self::assertSame( 'Raman Studio', $directory['name'] );
		self::assertSame( 'raman-studio', $directory['slug'] );
		self::assertTrue( $directory['verified'] );
		self::assertSame( 'https://cdn.example.test/logos/raman-studio.png', $directory['logo'] );
	}

	#[Test]
	public function should_hide_the_tile_rather_than_say_not_listed_when_the_block_is_null(): void {
		self::assertSame( array( 'status' => 'hidden' ), agend_apps_member_summary_directory( null ), 'null is a failed read as often as it is no listing' );
	}

	#[Test]
	public function should_load_a_listing_logo_over_https_only(): void {
		$directory = $this->profile()['directory'];

		foreach ( array( 'http://cdn.example.test/a.png', 'javascript:alert(1)', 'data:image/png;base64,AAAA', '//cdn.example.test/a.png', '' ) as $logo ) {
			$directory['logo_url'] = $logo;

			self::assertSame( '', agend_apps_member_summary_directory( $directory )['logo'], $logo );
		}
	}

	#[Test]
	public function should_name_a_listing_by_its_slug_when_it_has_no_name_and_hide_one_with_neither(): void {
		self::assertSame( 'raman-studio', agend_apps_member_summary_directory( array( 'slug' => 'raman-studio' ) )['name'] );
		self::assertSame( 'hidden', agend_apps_member_summary_directory( array( 'is_verified' => true ) )['status'] );
	}

	// -----------------------------------------------------------------
	// Membership
	// -----------------------------------------------------------------

	private function membership( $entitlements = null, $memberships = null, $tiers = null ): array {
		return agend_apps_member_summary_membership(
			$entitlements ?? $this->gateway()['entitlements'],
			$memberships ?? $this->gateway()['memberships'],
			$tiers ?? $this->gateway()['tiers'],
			self::NOW
		);
	}

	/**
	 * A membership list with one row, changed.
	 *
	 * @param array $changes Fields to set on the fixture's row.
	 * @return array
	 */
	private function row( array $changes ): array {
		return array( 'success' => true, 'data' => array( array_replace( $this->gateway()['memberships']['data'][0], $changes ) ) );
	}

	#[Test]
	public function should_show_standing_tier_and_renewal_from_the_three_reads(): void {
		$membership = $this->membership();

		self::assertSame(
			array( 'status' => 'ready', 'label' => 'active', 'tier' => 'Professional', 'via_seat' => false, 'date' => '2027-03-01T00:00:00.000Z', 'kind' => 'renews', 'days' => 103 ),
			$membership
		);
	}

	#[Test]
	public function should_say_so_when_the_membership_is_held_through_an_organisation_seat(): void {
		self::assertTrue( $this->membership( null, $this->row( array( 'via_seat' => true ) ) )['via_seat'] );
	}

	#[Test]
	public function should_take_standing_from_the_entitlement_not_from_the_rows(): void {
		$not_a_member = array( 'success' => true, 'data' => array( 'is_member' => false, 'tier_ids' => array(), 'capabilities' => array() ) );

		self::assertSame( 'ended', $this->membership( $not_a_member, $this->row( array( 'status' => 'expired' ) ) )['label'] );
		self::assertSame( 'active', $this->membership( null, $this->row( array( 'status' => 'expired' ) ) )['label'], 'a member in their grace period is still a member' );
	}

	#[Test]
	public function should_say_none_with_a_way_to_join_when_there_is_no_membership(): void {
		$not_a_member = array( 'success' => true, 'data' => array( 'is_member' => false, 'tier_ids' => array(), 'capabilities' => array() ) );

		self::assertSame( array( 'status' => 'empty' ), $this->membership( $not_a_member, array( 'success' => true, 'data' => array() ) ) );
	}

	#[Test]
	public function should_show_a_member_who_holds_access_without_a_membership_row(): void {
		$membership = $this->membership( null, array( 'success' => true, 'data' => array() ) );

		self::assertSame( 'ready', $membership['status'] );
		self::assertSame( 'active', $membership['label'] );
		self::assertSame( '', $membership['tier'] );
		self::assertSame( '', $membership['date'] );
		self::assertNull( $membership['days'] );
	}

	#[Test]
	public function should_never_use_the_word_member_as_a_tier_name(): void {
		$tiers = array( 'success' => true, 'data' => array() );

		self::assertSame( '', $this->membership( null, null, $tiers )['tier'] );
		self::assertSame( '', $this->membership( null, null, new WP_Error( 'agend_api_error', 'down', array( 'status_code' => 500 ) ) )['tier'], 'tier names failing does not fail the membership' );
	}

	#[Test]
	public function should_show_pending_and_ended_memberships_as_what_they_are(): void {
		$not_a_member = array( 'success' => true, 'data' => array( 'is_member' => false ) );

		self::assertSame( 'pending', $this->membership( $not_a_member, $this->row( array( 'status' => 'pending' ) ) )['label'] );

		$ended = $this->membership( $not_a_member, $this->row( array( 'status' => 'cancelled', 'renewal_date' => null ) ) );

		self::assertSame( 'ended', $ended['label'] );
		self::assertSame( 'ended', $ended['kind'] );
	}

	#[Test]
	public function should_prefer_the_active_row_then_the_latest_end(): void {
		$rows = array(
			'success' => true,
			'data'    => array(
				array_replace( $this->gateway()['memberships']['data'][0], array( 'id' => 'old', 'status' => 'expired', 'tier_id' => '11111111-1111-4111-8111-111111111112', 'expiry_date' => '2026-02-01T00:00:00.000Z', 'renewal_date' => null ) ),
				array_replace( $this->gateway()['memberships']['data'][0], array( 'id' => 'now' ) ),
			),
		);

		self::assertSame( 'Professional', $this->membership( null, $rows )['tier'] );
	}

	#[Test]
	public function should_show_an_expiry_when_there_is_no_renewal_date(): void {
		$membership = $this->membership( null, $this->row( array( 'renewal_date' => null ) ) );

		self::assertSame( 'expires', $membership['kind'] );
		self::assertSame( '2027-03-01T00:00:00.000Z', $membership['date'] );
	}

	#[Test]
	public function should_work_out_the_days_itself_when_the_gateway_leaves_them_out(): void {
		$membership = $this->membership( null, $this->row( array( 'days_until_renewal' => null ) ) );

		self::assertSame( 103, $membership['days'] );
	}

	#[Test]
	public function should_report_a_renewal_that_has_passed_with_a_negative_count(): void {
		$membership = $this->membership( null, $this->row( array( 'days_until_renewal' => -9 ) ) );

		self::assertSame( -9, $membership['days'] );
	}

	#[Test]
	public function should_read_standing_from_the_rows_when_the_entitlement_read_failed(): void {
		$failed = new WP_Error( 'agend_api_error', 'down', array( 'status_code' => 500 ) );

		$membership = $this->membership( $failed );

		self::assertSame( 'ready', $membership['status'] );
		self::assertSame( 'active', $membership['label'] );
		self::assertSame( 'Professional', $membership['tier'] );
	}

	#[Test]
	public function should_show_standing_alone_when_the_membership_rows_failed(): void {
		$failed = new WP_Error( 'agend_api_error', 'down', array( 'status_code' => 500 ) );

		$membership = $this->membership( null, $failed );

		self::assertSame( array( 'status' => 'ready', 'label' => 'active', 'tier' => '', 'via_seat' => false, 'date' => '', 'kind' => '', 'days' => null ), $membership );
	}

	#[Test]
	public function should_call_the_membership_unavailable_when_both_reads_failed(): void {
		$failed = new WP_Error( 'agend_api_error', 'down', array( 'status_code' => 500 ) );

		self::assertSame( array( 'status' => 'unavailable' ), $this->membership( $failed, $failed ) );
	}

	#[Test]
	public function should_call_the_membership_no_access_only_when_every_failure_was_a_missing_permission(): void {
		$refused = new WP_Error( 'agend_apps_scope_missing', 'x', array( 'status_code' => 403 ) );
		$down    = new WP_Error( 'agend_api_error', 'down', array( 'status_code' => 500 ) );

		self::assertSame( 'no_access', $this->membership( $refused, $refused )['status'] );
		self::assertSame( 'unavailable', $this->membership( $refused, $down )['status'] );
	}

	// -----------------------------------------------------------------
	// Defensive reading
	// -----------------------------------------------------------------

	#[Test]
	public function should_ignore_fields_it_does_not_know_and_survive_ones_of_the_wrong_type(): void {
		$profile = $this->profile( array( 'lms' => array( 'cpd_resolution' => array( 'x' ), 'badges' => 'none', 'certificates' => 7, 'future_block' => array( 1, 2 ) ), 'events' => array( 'upcoming' => 'soon' ), 'directory' => 'listed', 'contact' => 'Priya' ) );

		self::assertSame( 'empty', agend_apps_member_summary_cpd( $profile['lms'] )['status'] );
		self::assertSame( 'empty', agend_apps_member_summary_credentials( $profile['lms'], 3 )['status'] );
		self::assertSame( 'empty', agend_apps_member_summary_events( $profile['events'], 3 )['status'] );
		self::assertSame( '', agend_apps_member_summary_first_name( $profile ) );
	}

	#[Test]
	public function should_read_the_first_name_and_drop_markup_from_it(): void {
		self::assertSame( 'Priya', agend_apps_member_summary_first_name( $this->profile() ) );
		self::assertSame( 'Priya', agend_apps_member_summary_first_name( array( 'contact' => array( 'first_name' => ' <b>Priya</b> ' ) ) ) );
		self::assertSame( '', agend_apps_member_summary_first_name( null ) );
	}

	// -----------------------------------------------------------------
	// The member's name and role, for the welcome band
	// -----------------------------------------------------------------

	#[Test]
	public function should_take_initials_and_the_role_line_from_the_contact(): void {
		$contact = agend_apps_member_summary_contact( $this->profile() );

		self::assertSame( 'Priya', $contact['first_name'] );
		self::assertSame( 'PR', $contact['initials'] );
		self::assertSame( 'Practice lead', $contact['title'] );
		self::assertSame( 'Raman Studio', $contact['company'], 'the free-text company when no company record is linked' );
	}

	#[Test]
	public function should_prefer_the_linked_company_and_take_one_initial_when_there_is_no_surname(): void {
		$profile                                   = $this->profile();
		$profile['contact']['primary_company_name'] = 'Raman &amp; Co';
		$profile['contact']['last_name']            = '';

		$contact = agend_apps_member_summary_contact( $profile );

		self::assertSame( 'P', $contact['initials'] );
		self::assertSame( 'Raman &amp; Co', $contact['company'] );
	}

	#[Test]
	public function should_take_initials_from_a_name_that_is_not_ascii_and_none_without_a_first_name(): void {
		$profile                                = $this->profile();
		$profile['contact']['first_name']       = 'élodie';
		$profile['contact']['last_name']        = 'østergaard';

		self::assertSame( 'ÉØ', agend_apps_member_summary_contact( $profile )['initials'] );
		self::assertSame( '', agend_apps_member_summary_contact( null )['initials'] );
		self::assertSame( array( 'first_name' => '', 'initials' => '', 'title' => '', 'company' => '' ), agend_apps_member_summary_contact( array( 'contact' => array( 'last_name' => 'Raman' ) ) ), 'a surname alone is not enough to draw initials from' );
	}

	#[Test]
	public function should_strip_markup_from_the_role_line(): void {
		$profile                           = $this->profile();
		$profile['contact']['job_title']   = '<img src=x onerror=alert(1)>Lead';

		self::assertSame( 'Lead', agend_apps_member_summary_contact( $profile )['title'] );
	}

	// -----------------------------------------------------------------
	// Courses in progress
	// -----------------------------------------------------------------

	#[Test]
	public function should_list_courses_in_progress_most_recently_opened_first_with_their_percentage(): void {
		$part = agend_apps_member_summary_courses( $this->gateway()['enrollments'], 3 );

		self::assertSame( 'ready', $part['status'] );
		self::assertSame( array( 'Contracts for studios', 'Pricing your work', 'Accessible forms' ), array_column( $part['items'], 'title' ) );
		self::assertSame( 4, $part['total'], 'the rest are counted, so the tile can say "and 1 more"' );
		self::assertSame( 35.0, $part['items'][0]['percent'] );
		self::assertSame( 72.4, $part['items'][1]['percent'] );
		self::assertSame( 3, $part['items'][0]['done'] );
		self::assertSame( 9, $part['items'][0]['lessons'] );
		self::assertSame( 'contracts-for-studios', $part['items'][0]['slug'] );
	}

	#[Test]
	public function should_leave_a_finished_course_out_of_courses_in_progress(): void {
		$response                         = $this->gateway()['enrollments'];
		$response['data'][0]['completed'] = true;

		$titles = array_column( agend_apps_member_summary_courses( $response, 6 )['items'], 'title' );

		self::assertNotContains( 'Pricing your work', $titles );
		self::assertCount( 3, $titles );
	}

	#[Test]
	public function should_keep_a_course_with_no_percentage_and_never_turn_it_into_zero(): void {
		$response                                    = $this->gateway()['enrollments'];
		$response['data'][0]['progress_percentage'] = null;
		$response['data'][1]['progress_percentage'] = 'soon';

		$items = agend_apps_member_summary_courses( $response, 6 )['items'];
		$by    = array_column( $items, 'percent', 'title' );

		self::assertNull( $by['Pricing your work'] );
		self::assertNull( $by['Contracts for studios'] );
		self::assertSame( 0.0, $by['Accessible forms'], 'a real zero is still zero' );
	}

	#[Test]
	public function should_hold_a_percentage_between_zero_and_a_hundred(): void {
		$response                                    = $this->gateway()['enrollments'];
		$response['data'][0]['progress_percentage'] = 140;
		$response['data'][1]['progress_percentage'] = -5;

		$by = array_column( agend_apps_member_summary_courses( $response, 6 )['items'], 'percent', 'title' );

		self::assertSame( 100.0, $by['Pricing your work'] );
		self::assertSame( 0.0, $by['Contracts for studios'] );
	}

	#[Test]
	public function should_say_none_when_agend_answered_and_the_member_has_no_course_in_progress(): void {
		self::assertSame( 'empty', agend_apps_member_summary_courses( array( 'success' => true, 'data' => array() ), 3 )['status'] );
	}

	#[Test]
	public function should_call_a_failed_enrolments_read_unavailable_never_none(): void {
		self::assertSame( 'unavailable', agend_apps_member_summary_courses( new WP_Error( 'agend_apps_api_error', 'Down.' ), 3 )['status'] );
		self::assertSame( 'unavailable', agend_apps_member_summary_courses( null, 3 )['status'] );
		self::assertSame( 'no_access', agend_apps_member_summary_courses( new WP_Error( 'agend_apps_scope_missing', 'No.' ), 3 )['status'] );
	}

	#[Test]
	public function should_drop_a_course_with_no_title_and_strip_markup_from_one_with(): void {
		$response                                  = $this->gateway()['enrollments'];
		$response['data'][0]['course']['title']    = '';
		$response['data'][1]['course']['title']    = '<script>alert(1)</script>Contracts';
		$response['data'][2]['course']             = null;

		$titles = array_column( agend_apps_member_summary_courses( $response, 6 )['items'], 'title' );

		self::assertSame( array( 'Contracts', 'Running a studio' ), $titles );
	}

	#[Test]
	public function should_never_return_fewer_than_one_course_when_asked_for_none(): void {
		self::assertCount( 1, agend_apps_member_summary_courses( $this->gateway()['enrollments'], 0 )['items'] );
	}

	#[Test]
	public function should_not_draw_lesson_counts_that_do_not_add_up(): void {
		$response                                       = $this->gateway()['enrollments'];
		$response['data'][0]['course']['lessons_count'] = 0;

		$by = array_column( agend_apps_member_summary_courses( $response, 6 )['items'], 'lessons', 'title' );

		self::assertNull( $by['Pricing your work'], 'a course with no lesson count has none to show' );
	}

	// -----------------------------------------------------------------
	// Reading lists
	// -----------------------------------------------------------------

	#[Test]
	public function should_list_reading_lists_with_their_page_counts_and_cap_them(): void {
		$part = agend_apps_member_summary_reading( $this->gateway()['collections'], 4 );

		self::assertSame( 'ready', $part['status'] );
		self::assertSame( array( 'New member starter pack', 'Running a studio', 'Pricing and proposals', 'Accessibility basics' ), array_column( $part['items'], 'title' ) );
		self::assertSame( array( 5, 6, 3, 4 ), array_column( $part['items'], 'pages' ) );
		self::assertSame( 5, $part['total'] );
	}

	#[Test]
	public function should_say_none_when_agend_answered_and_there_are_no_reading_lists(): void {
		self::assertSame( 'empty', agend_apps_member_summary_reading( array( 'success' => true, 'data' => array() ), 4 )['status'] );
	}

	#[Test]
	public function should_call_a_failed_reading_lists_read_unavailable_never_none(): void {
		self::assertSame( 'unavailable', agend_apps_member_summary_reading( new WP_Error( 'agend_apps_api_error', 'Down.' ), 4 )['status'] );
		self::assertSame( 'no_access', agend_apps_member_summary_reading( new WP_Error( 'agend_apps_app_not_allowed', 'No.' ), 4 )['status'] );
	}

	#[Test]
	public function should_count_zero_pages_for_a_list_whose_pages_are_missing_and_drop_one_with_no_title(): void {
		$response                         = $this->gateway()['collections'];
		$response['data'][0]['items']     = null;
		$response['data'][1]['title']     = '';

		$part = agend_apps_member_summary_reading( $response, 6 );

		self::assertSame( 0, $part['items'][0]['pages'] );
		self::assertNotContains( '', array_column( $part['items'], 'title' ) );
		self::assertSame( 4, $part['total'] );
	}

	#[Test]
	public function should_read_the_pinned_profile_shape_the_gateway_has_today(): void {
		$profile = $this->profile();

		foreach ( array( 'contact', 'directory', 'lms', 'events', 'activity' ) as $block ) {
			self::assertArrayHasKey( $block, $profile, 'the shape this was written against' );
		}

		foreach ( array( 'cpd_resolution', 'total_cpd_points', 'cpd', 'certificates', 'badges' ) as $field ) {
			self::assertArrayHasKey( $field, $profile['lms'], $field );
		}

		foreach ( array( 'effective_target', 'earned', 'status', 'days_remaining', 'adjustment', 'period_end', 'standard_target' ) as $field ) {
			self::assertArrayHasKey( $field, $profile['lms']['cpd'], $field );
		}
	}

	// -----------------------------------------------------------------
	// Fetching, end to end over the real client
	// -----------------------------------------------------------------

	#[Test]
	public function should_fetch_every_part_of_a_full_member_in_one_pass(): void {
		$this->withBearer();
		$this->queueFull();

		$result = agend_apps_member_summary_fetch( $this->everything(), $this->ready(), array( 'events' => 3, 'certificates' => 3 ) );

		self::assertSame( 'ready', $result['access']['state'] );
		self::assertSame( 'Priya', $result['contact']['first_name'] );
		self::assertSame( array( 'membership', 'cpd', 'events', 'credentials', 'directory' ), array_keys( $result['sections'] ) );

		foreach ( $result['sections'] as $name => $section ) {
			self::assertSame( 'ready', $section['status'], $name );
		}

		self::assertSame( 'Professional', $result['sections']['membership']['tier'] );
		self::assertSame( 16.0, $result['sections']['cpd']['target'] );
		self::assertCount( 3, $result['sections']['events']['items'] );
		self::assertCount( 3, $result['sections']['credentials']['certificates'] );
		self::assertSame( 'Raman Studio', $result['sections']['directory']['name'] );
	}

	#[Test]
	public function should_make_exactly_the_planned_gateway_calls(): void {
		$this->withBearer();
		$this->queueFull();

		agend_apps_member_summary_fetch( $this->everything(), $this->ready() );

		self::assertSame(
			array( '/v1/crm/me/connected-profile', '/v1/crm/me/entitlements', '/v1/crm/me/memberships', '/v1/crm/tiers' ),
			$this->paths(),
			'three member reads and one catalogue read, once each'
		);
	}

	#[Test]
	public function should_make_six_calls_at_most_for_a_view_with_every_part_on(): void {
		$this->withBearer();
		$this->queueFull();
		Agend_Test_WP::queue_response( 200, $this->gateway()['enrollments'] );
		Agend_Test_WP::queue_response( 200, $this->gateway()['collections'] );

		$result = agend_apps_member_summary_fetch( $this->everyPart(), $this->ready(), array( 'events' => 3, 'certificates' => 3, 'courses' => 3, 'reading' => 4 ) );

		self::assertSame(
			array( '/v1/crm/me/connected-profile', '/v1/crm/me/entitlements', '/v1/crm/me/memberships', '/v1/crm/tiers', '/v1/lms/me/enrollments', '/v1/atlas/me/collections' ),
			$this->paths(),
			'one call per read, whatever the number of parts, courses or lists'
		);
		self::assertSame( 'ready', $result['access']['state'] );
		self::assertSame( 'ready', $result['sections']['courses']['status'] );
		self::assertCount( 3, $result['sections']['courses']['items'] );
		self::assertSame( 'ready', $result['sections']['reading']['status'] );
		self::assertCount( 4, $result['sections']['reading']['items'] );
		self::assertSame( 'PR', $result['contact']['initials'] );
	}

	#[Test]
	public function should_ask_only_for_courses_in_progress_in_one_page_and_never_call_for_a_part_that_is_off(): void {
		$this->withBearer();
		Agend_Test_WP::queue_response( 200, $this->gateway()['enrollments'] );

		agend_apps_member_summary_fetch( array( 'sections' => array( 'courses' ), 'greeting' => false ), $this->ready() );

		self::assertSame( array( '/v1/lms/me/enrollments' ), $this->paths() );
		parse_str( (string) wp_parse_url( Agend_Test_WP::$requests[0]['url'], PHP_URL_QUERY ), $query );
		self::assertSame( 'active', $query['status'] );
		self::assertSame( '12', $query['limit'], 'a fixed page: more courses never mean more calls' );
		self::assertSame( 'Bearer member-token', $this->authorisationOf( 0 ) );
	}

	#[Test]
	public function should_mark_only_the_courses_unavailable_when_the_enrolments_read_failed(): void {
		$this->withBearer();
		Agend_Test_WP::queue_response( 200, $this->gateway()['profile'] );
		Agend_Test_WP::queue_response( 200, $this->gateway()['entitlements'] );
		Agend_Test_WP::queue_response( 200, $this->gateway()['memberships'] );
		Agend_Test_WP::queue_response( 200, $this->gateway()['tiers'] );
		Agend_Test_WP::queue_response( 500, $this->error( 500, 'SERVER_ERROR' ) );

		$result = agend_apps_member_summary_fetch( array( 'sections' => array( 'membership', 'cpd', 'courses' ), 'greeting' => true ), $this->ready() );

		self::assertSame( 'ready', $result['access']['state'] );
		self::assertSame( 'unavailable', $result['sections']['courses']['status'], 'a failed read is never drawn as no courses' );
		self::assertSame( 'ready', $result['sections']['cpd']['status'] );
		self::assertSame( 'ready', $result['sections']['membership']['status'] );
	}

	#[Test]
	public function should_read_a_not_found_enrolments_read_as_no_member_record(): void {
		$this->withBearer();
		Agend_Test_WP::queue_response( 404, $this->error( 404, 'CONTACT_NOT_FOUND' ) );

		$result = agend_apps_member_summary_fetch( array( 'sections' => array( 'courses' ), 'greeting' => false ), $this->ready() );

		self::assertSame( 'no_member_record', $result['access']['state'], 'the same answer as every other me read' );
	}

	#[Test]
	public function should_keep_the_summary_ready_when_only_the_reading_lists_read_failed(): void {
		$this->withBearer();
		Agend_Test_WP::queue_response( 404, $this->error( 404, 'NOT_FOUND' ) );

		$result = agend_apps_member_summary_fetch( array( 'sections' => array( 'reading' ), 'greeting' => false ), $this->ready() );

		self::assertSame( 'ready', $result['access']['state'], 'a refusal from the library says nothing about the member record' );
		self::assertSame( 'unavailable', $result['sections']['reading']['status'] );
	}

	#[Test]
	public function should_skip_the_courses_and_reading_calls_for_a_key_that_lacks_their_permissions(): void {
		Agend_Apps_Key_Scopes::store_from_response( array( 'data' => array( 'scopes' => array( 'crm.contacts.browse' ), 'app_ids' => array( 'crm' ) ) ) );
		$this->withBearer();
		Agend_Test_WP::queue_response( 200, $this->gateway()['profile'] );

		$result = agend_apps_member_summary_fetch( array( 'sections' => array( 'cpd', 'courses', 'reading' ), 'greeting' => false ), $this->ready() );

		self::assertSame( array( '/v1/crm/me/connected-profile' ), $this->paths() );
		self::assertSame( 'no_access', $result['sections']['courses']['status'] );
		self::assertSame( 'no_access', $result['sections']['reading']['status'] );
		self::assertSame( 'ready', $result['access']['state'] );
	}

	#[Test]
	public function should_stay_inside_the_per_member_cap_however_many_parts_are_on(): void {
		$this->withBearer();

		for ( $i = 0; $i < 6; $i++ ) {
			self::assertNull( agend_apps_member_summary_guard( 42, self::NOW ), 'fetch ' . ( $i + 1 ) );
		}

		self::assertSame( 'member', agend_apps_member_summary_guard( 42, self::NOW )['why'], 'the seventh fetch in a minute waits, so at most 36 calls a minute per member' );
	}

	#[Test]
	public function should_make_one_call_when_only_the_profile_parts_are_on_and_none_when_all_are_off(): void {
		$this->withBearer();
		Agend_Test_WP::queue_response( 200, $this->gateway()['profile'] );

		agend_apps_member_summary_fetch( array( 'sections' => array( 'cpd', 'events' ), 'greeting' => true ), $this->ready() );

		self::assertSame( array( '/v1/crm/me/connected-profile' ), $this->paths() );

		Agend_Test_WP::$requests = array();
		$result                  = agend_apps_member_summary_fetch( array( 'sections' => array(), 'greeting' => false ), $this->ready() );

		self::assertSame( array(), $this->paths() );
		self::assertSame( array(), $result['sections'] );
		self::assertSame( 'ready', $result['access']['state'] );
	}

	#[Test]
	public function should_send_the_member_token_on_the_member_reads_and_none_on_the_tiers(): void {
		$this->withBearer( 'member-token' );
		$this->queueFull();

		agend_apps_member_summary_fetch( $this->everything(), $this->ready() );

		self::assertSame( 'Bearer member-token', $this->authorisationOf( 0 ) );
		self::assertSame( 'Bearer member-token', $this->authorisationOf( 1 ) );
		self::assertSame( 'Bearer member-token', $this->authorisationOf( 2 ) );
		self::assertNull( $this->authorisationOf( 3 ), 'tier names are public, so the read carries no member token and can be shared' );
	}

	#[Test]
	public function should_store_nothing_about_the_member_and_share_only_the_tier_names(): void {
		$this->withBearer();
		$this->queueFull();

		agend_apps_member_summary_fetch( $this->everything(), $this->ready() );

		$keys = $this->cachedContentKeys();

		self::assertSame( array( 'agend_apps_' . Agend_Apps_Cache::build_key( 'crm_tiers' ) ), $keys, 'the only stored copy is the public tier list' );
		self::assertStringNotContainsString( 'Priya', (string) wp_json_encode( Agend_Test_WP::$transients ) );
		self::assertStringNotContainsString( 'VER-3fa91c0b7d2e4a65b8c1', (string) wp_json_encode( Agend_Test_WP::$transients ) );
		self::assertStringNotContainsString( 'member-token', (string) wp_json_encode( Agend_Test_WP::$transients ) );
	}

	#[Test]
	public function should_read_the_tiers_from_the_shared_copy_once_one_exists(): void {
		$this->withBearer();
		Agend_Test_WP::$transients[ 'agend_apps_' . Agend_Apps_Cache::build_key( 'crm_tiers' ) ] = $this->gateway()['tiers'];
		foreach ( array( 'profile', 'entitlements', 'memberships' ) as $name ) {
			Agend_Test_WP::queue_response( 200, $this->gateway()[ $name ] );
		}

		$result = agend_apps_member_summary_fetch( $this->everything(), $this->ready() );

		self::assertCount( 3, Agend_Test_WP::$requests, 'a warm page costs three calls' );
		self::assertSame( 'Professional', $result['sections']['membership']['tier'] );
	}

	#[Test]
	public function should_hold_the_member_reads_for_the_rest_of_the_request_only(): void {
		$this->withBearer();
		$this->queueFull();

		agend_apps_member_summary_fetch( $this->everything(), $this->ready() );
		agend_apps_member_summary_fetch( $this->everything(), $this->ready() );

		self::assertCount( 4, Agend_Test_WP::$requests, 'a second fetch in the same request is served from memory' );
		self::assertSame( array(), preg_grep( '/member_summary/', array_keys( Agend_Test_WP::$transients ) ) );
	}

	#[Test]
	public function should_skip_the_calls_for_a_part_the_key_lacks_the_permission_for(): void {
		$this->know( array( 'crm.contacts.browse', 'crm.tiers.browse' ) );
		$this->withBearer();
		Agend_Test_WP::queue_response( 200, $this->gateway()['profile'] );

		$result = agend_apps_member_summary_fetch( $this->everything(), $this->ready() );

		self::assertSame( array( '/v1/crm/me/connected-profile' ), $this->paths() );
		self::assertSame( 'no_access', $result['sections']['membership']['status'] );
		self::assertSame( 'ready', $result['sections']['cpd']['status'] );
		self::assertSame( 'ready', $result['access']['state'], 'one part being off limits does not make the rest unavailable' );
	}

	#[Test]
	public function should_say_not_configured_when_every_part_wanted_something_the_key_lacks(): void {
		$this->know( array( 'crm.tiers.browse' ) );
		$this->withBearer();

		$result = agend_apps_member_summary_fetch( array( 'sections' => array( 'membership', 'cpd' ), 'greeting' => true ), $this->ready() );

		self::assertSame( array(), $this->paths() );
		self::assertSame( 'not_configured', $result['access']['state'] );
		self::assertSame( 'missing_scope', $result['access']['reason'] );
		self::assertStringContainsString( 'crm.contacts.browse', $result['access']['detail'] );
		self::assertStringContainsString( 'crm.memberships.browse', $result['access']['detail'] );
	}

	#[Test]
	public function should_read_a_404_on_any_member_read_as_no_member_record_for_the_whole_summary(): void {
		$this->withBearer();
		Agend_Test_WP::queue_response( 404, $this->error( 404, 'CONTACT_NOT_FOUND' ) );
		Agend_Test_WP::queue_response( 200, array( 'success' => true, 'data' => array( 'is_member' => false, 'tier_ids' => array(), 'capabilities' => array() ) ), array() );
		Agend_Test_WP::queue_response( 404, $this->error( 404, 'CONTACT_NOT_FOUND' ) );
		Agend_Test_WP::queue_response( 200, $this->gateway()['tiers'] );

		$result = agend_apps_member_summary_fetch( $this->everything(), $this->ready() );

		self::assertSame( 'no_member_record', $result['access']['state'], 'the entitlement answers "not a member" rather than 404, and does not outvote the other two' );
		self::assertSame( 'contact_not_found', $result['access']['reason'] );
	}

	#[Test]
	public function should_read_a_total_outage_as_unavailable(): void {
		$this->withBearer();
		foreach ( array( 'profile', 'entitlements', 'memberships' ) as $unused ) {
			Agend_Test_WP::queue_response( 500, $this->error( 500, 'SERVER_ERROR' ) );
		}
		Agend_Test_WP::queue_response( 200, $this->gateway()['tiers'] );

		$result = agend_apps_member_summary_fetch( $this->everything(), $this->ready() );

		self::assertSame( array( 'state' => 'unavailable', 'reason' => 'gateway_error' ), array( 'state' => $result['access']['state'], 'reason' => $result['access']['reason'] ) );

		foreach ( $result['sections'] as $name => $section ) {
			self::assertSame( 'unavailable', $section['status'], $name . ' says it could not load, never that there is nothing' );
		}
	}

	#[Test]
	public function should_keep_the_summary_ready_and_mark_only_the_part_that_failed(): void {
		$this->withBearer();
		Agend_Test_WP::queue_response( 200, $this->gateway()['profile'] );
		Agend_Test_WP::queue_response( 500, $this->error( 500, 'SERVER_ERROR' ) );
		Agend_Test_WP::queue_response( 200, $this->gateway()['memberships'] );
		Agend_Test_WP::queue_response( 200, $this->gateway()['tiers'] );

		$result = agend_apps_member_summary_fetch( $this->everything(), $this->ready() );

		self::assertSame( 'ready', $result['access']['state'] );
		self::assertSame( 'ready', $result['sections']['membership']['status'], 'read from the rows when the entitlement failed' );
		self::assertSame( 'ready', $result['sections']['cpd']['status'] );
	}

	#[Test]
	public function should_mark_the_profile_parts_unavailable_when_only_the_profile_failed(): void {
		$this->withBearer();
		Agend_Test_WP::queue_response( 500, $this->error( 500, 'SERVER_ERROR' ) );
		Agend_Test_WP::queue_response( 200, $this->gateway()['entitlements'] );
		Agend_Test_WP::queue_response( 200, $this->gateway()['memberships'] );
		Agend_Test_WP::queue_response( 200, $this->gateway()['tiers'] );

		$result = agend_apps_member_summary_fetch( $this->everything(), $this->ready() );

		self::assertSame( 'ready', $result['access']['state'] );
		self::assertSame( 'ready', $result['sections']['membership']['status'] );

		foreach ( array( 'cpd', 'events', 'credentials', 'directory' ) as $name ) {
			self::assertSame( 'unavailable', $result['sections'][ $name ]['status'], $name );
		}

		self::assertSame( '', $result['contact']['first_name'] );
	}

	#[Test]
	public function should_read_a_rejected_sign_in_as_sign_in_again_in_credentials_mode(): void {
		$this->withBearer();
		Agend_Test_WP::queue_response( 401, $this->error( 401, 'UNAUTHORIZED' ) );
		Agend_Test_WP::queue_response( 401, $this->error( 401, 'UNAUTHORIZED' ) );

		$result = agend_apps_member_summary_fetch( array( 'sections' => array( 'cpd' ), 'greeting' => false ), $this->ready() );

		self::assertSame( array( 'state' => 'not_linked', 'reason' => 'session_expired', 'action' => 'sign_in_again' ), array( 'state' => $result['access']['state'], 'reason' => $result['access']['reason'], 'action' => $result['access']['action'] ) );
		self::assertSame( 'unavailable', $result['sections']['cpd']['status'] );
	}

	#[Test]
	public function should_read_a_gateway_429_as_busy_with_a_wait(): void {
		$this->withBearer();
		Agend_Test_WP::queue_response( 429, $this->error( 429, 'RATE_LIMITED' ) );

		$result = agend_apps_member_summary_fetch( array( 'sections' => array( 'cpd' ), 'greeting' => false ), $this->ready() );

		self::assertSame( 'busy', $result['access']['reason'] );
		self::assertGreaterThan( 0, $result['access']['retry_after'] );
	}

	#[Test]
	public function should_read_the_clients_local_limit_as_busy_without_calling_the_gateway(): void {
		$this->withBearer();
		set_transient( 'agend_apps_rate_limit_remaining', 0, 30 );
		set_transient( 'agend_apps_rate_limit_reset', (string) ( time() + 25 ), 30 );

		$result = agend_apps_member_summary_fetch( array( 'sections' => array( 'cpd' ), 'greeting' => false ), $this->ready() );

		self::assertSame( array(), $this->paths(), 'the client refused the call itself' );
		self::assertSame( 'busy', $result['access']['reason'] );
		self::assertGreaterThanOrEqual( 20, $result['access']['retry_after'] );
	}

	#[Test]
	public function should_run_the_pinned_profile_through_every_part_without_a_notice_or_warning(): void {
		// failOnNotice and failOnWarning make this a check that nothing in the
		// reduction reads a key the gateway shape does not have.
		$this->withBearer();
		$this->queueFull();

		$result = agend_apps_member_summary_fetch( $this->everything(), $this->ready() );

		self::assertSame( 'ready', $result['sections']['events']['status'] );
	}

	// -----------------------------------------------------------------
	// Guarding the site key
	// -----------------------------------------------------------------

	#[Test]
	public function should_let_a_member_start_six_fetches_in_a_minute_and_refuse_the_seventh(): void {
		$now = self::NOW;

		for ( $i = 1; $i <= 6; $i++ ) {
			self::assertNull( agend_apps_member_summary_guard( 42, $now + $i ), 'fetch ' . $i );
		}

		$refused = agend_apps_member_summary_guard( 42, $now + 7 );

		self::assertSame( 'member', $refused['why'] );
		self::assertGreaterThanOrEqual( 1, $refused['retry_after'] );
		self::assertLessThanOrEqual( 60, $refused['retry_after'] );
	}

	#[Test]
	public function should_tell_the_member_when_the_minute_ends(): void {
		for ( $i = 0; $i < 6; $i++ ) {
			agend_apps_member_summary_guard( 42, self::NOW );
		}

		self::assertSame( 45, agend_apps_member_summary_guard( 42, self::NOW + 15 )['retry_after'] );
	}

	#[Test]
	public function should_start_a_new_minute_after_sixty_seconds(): void {
		for ( $i = 0; $i < 7; $i++ ) {
			agend_apps_member_summary_guard( 42, self::NOW );
		}

		self::assertNull( agend_apps_member_summary_guard( 42, self::NOW + 60 ) );
	}

	#[Test]
	public function should_count_each_member_on_their_own(): void {
		for ( $i = 0; $i < 6; $i++ ) {
			agend_apps_member_summary_guard( 42, self::NOW );
		}

		self::assertNotNull( agend_apps_member_summary_guard( 42, self::NOW ) );
		self::assertNull( agend_apps_member_summary_guard( 43, self::NOW ) );
	}

	#[Test]
	public function should_store_only_a_count_and_a_time_for_the_cap(): void {
		agend_apps_member_summary_guard( 42, self::NOW );

		self::assertSame( array( 'agend_apps_ms_fetches_42' ), array_keys( Agend_Test_WP::$transients ) );
		self::assertSame( array( 'count' => 1, 'started' => self::NOW ), Agend_Test_WP::$transients['agend_apps_ms_fetches_42'] );
		self::assertSame( 60, Agend_Test_WP::$transient_ttls['agend_apps_ms_fetches_42'] );
	}

	#[Test]
	public function should_ask_for_a_wait_when_the_site_keys_minute_is_nearly_spent(): void {
		set_transient( 'agend_apps_rate_limit_remaining', 14, 60 );
		set_transient( 'agend_apps_rate_limit_reset', (string) ( self::NOW + 33 ), 60 );

		$refused = agend_apps_member_summary_guard( 42, self::NOW );

		self::assertSame( 'site', $refused['why'] );
		self::assertSame( 33, $refused['retry_after'] );
	}

	#[Test]
	public function should_go_ahead_at_the_floor_and_when_what_is_left_is_not_known(): void {
		self::assertNull( agend_apps_member_summary_guard( 42, self::NOW ), 'no figure recorded yet' );

		set_transient( 'agend_apps_rate_limit_remaining', 15, 60 );
		self::assertNull( agend_apps_member_summary_guard( 43, self::NOW ), 'exactly the floor is enough' );

		set_transient( 'agend_apps_rate_limit_remaining', 90, 60 );
		self::assertNull( agend_apps_member_summary_guard( 44, self::NOW ) );
	}

	#[Test]
	public function should_not_count_a_refused_fetch_against_the_member(): void {
		set_transient( 'agend_apps_rate_limit_remaining', 3, 60 );

		for ( $i = 0; $i < 20; $i++ ) {
			self::assertSame( 'site', agend_apps_member_summary_guard( 42, self::NOW )['why'] );
		}

		set_transient( 'agend_apps_rate_limit_remaining', 80, 60 );

		self::assertNull( agend_apps_member_summary_guard( 42, self::NOW ), 'the refusals above spent none of the six' );
	}

	#[Test]
	public function should_wait_a_default_half_minute_and_never_more_than_five_when_the_reset_time_is_odd(): void {
		set_transient( 'agend_apps_rate_limit_remaining', 0, 60 );

		self::assertSame( 30, agend_apps_member_summary_guard( 42, self::NOW )['retry_after'] );

		set_transient( 'agend_apps_rate_limit_reset', (string) ( self::NOW + 9000 ), 60 );

		self::assertSame( 300, agend_apps_member_summary_guard( 43, self::NOW )['retry_after'] );
	}

	#[Test]
	public function should_let_a_site_change_the_cap_and_the_floor(): void {
		Agend_Test_WP::set_filter( 'agend_apps_member_summary_fetches_per_minute', 2 );
		agend_apps_member_summary_guard( 42, self::NOW );
		agend_apps_member_summary_guard( 42, self::NOW );

		self::assertSame( 'member', agend_apps_member_summary_guard( 42, self::NOW )['why'] );

		Agend_Test_WP::$filters = array();
		Agend_Test_WP::set_filter( 'agend_apps_member_summary_min_headroom', 50 );
		set_transient( 'agend_apps_rate_limit_remaining', 40, 60 );

		self::assertSame( 'site', agend_apps_member_summary_guard( 99, self::NOW )['why'] );
	}
}
