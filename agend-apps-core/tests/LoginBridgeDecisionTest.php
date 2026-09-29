<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use Agend_Apps_Member_Session;
use Agend_Test_WP;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use WP_Error;
use WP_User;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/auth.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/member-provisioning.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-member-session.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-token-worker.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/rest/class-agend-apps-rest-controller.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/rest/auth-routes.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/wp-login-bridge.php';

/**
 * The priority-30 refusal behind the login bridge. Once the bridge has
 * learned (from the gateway's 409) that an email already holds a dashboard
 * account, the WordPress-password login WordPress resolved at priority 20 is
 * replaced by the generic credentials error, for that email only. Once the
 * bridge has learned the gateway withheld the session for email verification
 * (a 202, a 503 `VERIFICATION_EMAIL_UNAVAILABLE`, or a register response
 * carrying `pending_email_confirmation`), the same replacement happens with
 * the verification-specific error instead
 * (SPEC-CORE-20260907-wordpress-email-verification-handling Decision change
 * A).
 */
final class LoginBridgeDecisionTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		agend_apps_wp_login_arm_refusal( '' );
	}

	#[Test]
	public function should_pass_the_resolved_user_through_when_no_refusal_is_armed(): void {
		$user = new WP_User( 4 );

		$this->assertSame( $user, agend_apps_wp_login_refuse_wordpress_password( $user, 'a@b.co', 'pw' ) );
	}

	#[Test]
	public function should_replace_the_resolved_user_with_the_generic_error_when_the_email_is_armed(): void {
		agend_apps_wp_login_arm_refusal( 'a@b.co' );

		$result = agend_apps_wp_login_refuse_wordpress_password( new WP_User( 4 ), 'A@B.co ', 'pw' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'agend_apps_invalid_credentials', $result->get_error_code() );
		$this->assertStringNotContainsStringIgnoringCase( 'agend', $result->get_error_message() );
		$this->assertStringNotContainsStringIgnoringCase( 'exist', $result->get_error_message() );
	}

	#[Test]
	public function should_leave_a_different_email_untouched_when_a_refusal_is_armed(): void {
		agend_apps_wp_login_arm_refusal( 'a@b.co' );
		$user = new WP_User( 9 );

		$this->assertSame( $user, agend_apps_wp_login_refuse_wordpress_password( $user, 'c@d.co', 'pw' ) );
	}

	/**
	 * Counts requests made to a given gateway path (SPEC-CORE-20260907
	 * US-1.1 AC4/AC5): a call to `agend_apps_auth_register()` shows up here as
	 * a POST to `/auth/register`, so this stands in for a mock's call count.
	 */
	private function requestCount( string $path ): int {
		return count(
			array_filter(
				Agend_Test_WP::$requests,
				static fn( array $request ): bool => str_contains( $request['url'], $path )
			)
		);
	}

	/** @return array<string, array{string}> */
	public static function localFallbackCases(): array {
		return array(
			'email throttle' => array( 'email_throttle' ),
			'IP throttle' => array( 'ip_throttle' ),
			'gateway 429' => array( 'gateway_429' ),
			'invalid API key' => array( 'invalid_key' ),
			'gateway 403' => array( 'gateway_403' ),
			'register rejection' => array( 'register_rejection' ),
			'malformed success' => array( 'malformed_success' ),
		);
	}

	private function queueLocalFallbackCase( string $case ): void {
		switch ( $case ) {
			case 'email_throttle':
				Agend_Test_WP::set_filter( 'agend_apps_auth_login_limits', array( 'per_ip' => 20, 'per_email' => 0 ) );
				break;
			case 'ip_throttle':
				Agend_Test_WP::set_filter( 'agend_apps_auth_login_limits', array( 'per_ip' => 0, 'per_email' => 5 ) );
				break;
			case 'gateway_429':
				Agend_Test_WP::queue_response( 429, array( 'error' => array( 'code' => 'RATE_LIMITED', 'message' => 'Slow down.' ) ) );
				break;
			case 'invalid_key':
				Agend_Test_WP::queue_response( 401, array( 'error' => array( 'code' => 'INVALID_CREDENTIALS', 'message' => 'Invalid.' ) ) );
				Agend_Test_WP::queue_response( 401, array( 'error' => array( 'code' => 'INVALID_API_KEY', 'message' => 'Invalid key.' ) ) );
				break;
			case 'gateway_403':
				Agend_Test_WP::queue_response( 403, array( 'error' => array( 'code' => 'FORBIDDEN', 'message' => 'Forbidden.' ) ) );
				break;
			case 'register_rejection':
				Agend_Test_WP::queue_response( 401, array( 'error' => array( 'code' => 'INVALID_CREDENTIALS', 'message' => 'Invalid.' ) ) );
				Agend_Test_WP::queue_response( 400, array( 'error' => array( 'code' => 'WEAK_PASSWORD', 'message' => 'Weak password.' ) ) );
				break;
			case 'malformed_success':
				Agend_Test_WP::queue_response( 200, '' );
				break;
		}
	}

	#[Test]
	#[DataProvider( 'localFallbackCases' )]
	public function should_allow_local_password_when_wordpress_only_admin_encounters_gateway_failure( string $case ): void {
		$owner = new WP_User( 94 );
		$owner->user_email = 'owner@site.test';
		$owner->user_login = 'site-owner';
		$owner->user_pass = 'local-password';
		$owner->roles = array( 'administrator' );
		$GLOBALS['agend_test_users'][] = $owner;
		$this->queueLocalFallbackCase( $case );
		$this->assertTrue( wp_check_password( 'local-password', $owner->user_pass, $owner->ID ) );
		$this->assertNull( agend_apps_wp_login_authenticate( null, $owner->user_email, 'local-password' ) );
		$this->assertSame( $owner, agend_apps_wp_login_refuse_wordpress_password( $owner, $owner->user_email, 'local-password' ) );
	}

	/** @return array<string, array{string, string}> */
	public static function protectedFallbackCases(): array {
		$cases = array();
		foreach ( self::localFallbackCases() as $label => $value ) {
			$cases[ $label . ' linked' ] = array( $value[0], 'linked' );
			$cases[ $label . ' marked' ] = array( $value[0], 'marked' );
		}
		return $cases;
	}

	#[Test]
	#[DataProvider( 'protectedFallbackCases' )]
	public function should_refuse_local_password_when_linked_or_marked_member_encounters_gateway_failure( string $case, string $state ): void {
		$member = new WP_User( 95 );
		$member->user_email = 'protected@site.test';
		$member->user_pass = 'local-password';
		$GLOBALS['agend_test_users'][] = $member;
		update_user_meta( 95, 'linked' === $state ? '_agend_apps_supabase_user_id' : 'agend_mfa_enrolled', '1' );
		$this->queueLocalFallbackCase( $case );
		$this->assertNull( agend_apps_wp_login_authenticate( null, $member->user_email, 'local-password' ) );
		$refused = agend_apps_wp_login_refuse_wordpress_password( $member, $member->user_email, 'local-password' );
		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 'agend_apps_invalid_credentials', $refused->get_error_code() );
	}

	#[Test]
	public function should_keep_local_password_when_bridge_is_disabled_for_wordpress_only_admin(): void {
		$owner = new WP_User( 100 );
		$owner->user_email = 'disabled-owner@site.test';
		$owner->user_pass = 'local-password';
		$owner->roles = array( 'administrator' );
		$GLOBALS['agend_test_users'][] = $owner;
		Agend_Test_WP::set_filter( 'agend_apps_wp_login_bridge_enabled', false );
		$this->assertNull( agend_apps_wp_login_authenticate( null, $owner->user_email, 'local-password' ) );
		$this->assertSame( $owner, agend_apps_wp_login_refuse_wordpress_password( $owner, $owner->user_email, 'local-password' ) );
	}

	#[Test]
	public function should_refuse_local_password_when_bridge_is_disabled_for_linked_member(): void {
		$member = new WP_User( 101 );
		$member->user_email = 'disabled-member@site.test';
		$GLOBALS['agend_test_users'][] = $member;
		update_user_meta( 101, '_agend_apps_supabase_user_id', 'agend-user' );
		Agend_Test_WP::set_filter( 'agend_apps_wp_login_bridge_enabled', false );
		$this->assertNull( agend_apps_wp_login_authenticate( null, $member->user_email, 'password' ) );
		$this->assertSame( 'agend_apps_invalid_credentials', agend_apps_wp_login_refuse_wordpress_password( $member, $member->user_email, 'password' )->get_error_code() );
	}

	#[Test]
	public function should_issue_no_cookie_or_member_session_when_gateway_requires_mfa(): void {
		$existing = new WP_User( 81 );
		$existing->user_email = 'mfa@example.test';
		$existing->user_pass = 'same-password';
		$GLOBALS['agend_test_users'][] = $existing;
		Agend_Test_WP::queue_response( 202, array( 'data' => array( 'status' => 'mfa_required', 'mfa_token' => 'secret-token', 'factors' => array( array( 'id' => 'factor-1', 'factor_type' => 'totp', 'friendly_name' => 'Phone' ) ) ) ) );
		$this->assertNull( agend_apps_wp_login_authenticate( null, 'mfa@example.test', 'same-password' ) );
		$this->assertFalse( Agend_Apps_Member_Session::has_session( 81 ) );
		$this->assertSame( '1', get_user_meta( 81, 'agend_mfa_enrolled', true ) );
		$this->assertSame( array(), Agend_Test_WP::$auth_cookie_users );
		$refused = agend_apps_wp_login_refuse_wordpress_password( $existing, 'mfa@example.test', 'same-password' );
		$this->assertSame( 'agend_apps_mfa_required', $refused->get_error_code() );
		$html = agend_apps_wp_login_mfa_message( '' );
		$this->assertStringContainsString( 'agend_mfa_nonce', $html );
		$this->assertStringContainsString( 'challenge_id', $html );
		$this->assertStringNotContainsString( 'secret-token', $html );
		$this->assertStringNotContainsString( 'same-password', $html );
	}

	#[Test]
	public function should_allow_local_password_fallback_when_gateway_is_unreachable_for_a_member_without_mfa(): void {
		$existing = new WP_User( 84 );
		$existing->user_email = 'plain@example.test';
		$existing->user_pass = 'local-password';
		$GLOBALS['agend_test_users'][] = $existing;
		Agend_Test_WP::queue_response( 503, array( 'error' => array( 'code' => 'UNAVAILABLE' ) ) );
		$this->assertNull( agend_apps_wp_login_authenticate( null, 'plain@example.test', 'local-password' ) );
		$this->assertSame( $existing, agend_apps_wp_login_refuse_wordpress_password( $existing, 'plain@example.test', 'local-password' ) );
	}

	#[Test]
	public function should_allow_local_password_fallback_when_gateway_is_unreachable_for_an_unmarked_linked_member(): void {
		$existing = new WP_User( 91 );
		$existing->user_email = 'linked-outage@example.test';
		$GLOBALS['agend_test_users'][] = $existing;
		update_user_meta( 91, '_agend_apps_supabase_user_id', 'agend-user' );
		Agend_Test_WP::queue_response( 503, array( 'error' => array( 'code' => 'UNAVAILABLE' ) ) );
		$this->assertNull( agend_apps_wp_login_authenticate( null, 'linked-outage@example.test', 'password' ) );
		$this->assertSame( $existing, agend_apps_wp_login_refuse_wordpress_password( $existing, 'linked-outage@example.test', 'password' ) );
	}

	#[Test]
	public function should_refuse_local_password_when_known_mfa_member_submits_wordpress_username(): void {
		$existing = new WP_User( 85 );
		$existing->user_email = 'named@example.test';
		$existing->user_login = 'named-member';
		$existing->user_pass = 'local-password';
		$GLOBALS['agend_test_users'][] = $existing;
		update_user_meta( 85, 'agend_mfa_enrolled', '1' );
		$this->assertNull( agend_apps_wp_login_authenticate( null, 'named-member', 'local-password' ) );
		$this->assertSame( 'agend_apps_invalid_credentials', agend_apps_wp_login_refuse_wordpress_password( $existing, 'named-member', 'local-password' )->get_error_code() );
		$this->assertSame( 0, $this->requestCount( '/auth/login' ) );
	}

	#[Test]
	public function should_require_code_when_unmarked_linked_member_submits_wordpress_username(): void {
		$existing = new WP_User( 87 );
		$existing->user_login = 'linked-member';
		$existing->user_email = 'linked@example.test';
		$existing->user_pass = 'same-password';
		$GLOBALS['agend_test_users'][] = $existing;
		update_user_meta( 87, '_agend_apps_supabase_user_id', 'agend-user' );
		Agend_Test_WP::queue_response( 202, array( 'data' => array( 'status' => 'mfa_required', 'mfa_token' => 'private-token', 'factors' => array( array( 'id' => 'factor-1', 'factor_type' => 'totp' ) ) ) ) );
		$this->assertNull( agend_apps_wp_login_authenticate( null, 'linked-member', 'same-password' ) );
		$this->assertSame( 'agend_apps_mfa_required', agend_apps_wp_login_refuse_wordpress_password( $existing, 'linked-member', 'same-password' )->get_error_code() );
		$this->assertSame( '1', get_user_meta( 87, 'agend_mfa_enrolled', true ) );
		$this->assertSame( 1, $this->requestCount( '/auth/login' ) );
		$this->assertSame( array(), Agend_Test_WP::$auth_cookie_users );
	}

	#[Test]
	public function should_use_linked_email_when_wordpress_username_looks_like_an_email(): void {
		$existing = new WP_User( 92 );
		$existing->user_login = 'old@example.test';
		$existing->user_email = 'current@example.test';
		$GLOBALS['agend_test_users'][] = $existing;
		update_user_meta( 92, '_agend_apps_supabase_user_id', 'agend-user' );
		Agend_Test_WP::queue_response( 202, array( 'data' => array( 'status' => 'mfa_required', 'mfa_token' => 'private-token', 'factors' => array( array( 'id' => 'factor-1', 'factor_type' => 'totp' ) ) ) ) );
		$this->assertNull( agend_apps_wp_login_authenticate( null, 'old@example.test', 'password' ) );
		$this->assertSame( 'agend_apps_mfa_required', agend_apps_wp_login_refuse_wordpress_password( $existing, 'old@example.test', 'password' )->get_error_code() );
		$sent = json_decode( Agend_Test_WP::$requests[0]['body'], true );
		$this->assertSame( 'current@example.test', $sent['email'] );
	}

	#[Test]
	public function should_leave_unrelated_wordpress_user_unmarked_when_login_name_matches_gateway_email(): void {
		$other = new WP_User( 96 );
		$other->user_login = 'gateway@example.test';
		$other->user_email = 'other@example.test';
		$GLOBALS['agend_test_users'][] = $other;
		Agend_Test_WP::queue_response( 202, array( 'data' => array( 'status' => 'mfa_required', 'mfa_token' => 'private-token', 'factors' => array( array( 'id' => 'factor-1', 'factor_type' => 'totp' ) ) ) ) );
		$this->assertNull( agend_apps_wp_login_authenticate( null, 'gateway@example.test', 'password' ) );
		$this->assertSame( '', get_user_meta( 96, 'agend_mfa_enrolled', true ) );
		$this->assertSame( 'agend_apps_mfa_required', agend_apps_wp_login_refuse_wordpress_password( $other, 'gateway@example.test', 'password' )->get_error_code() );
	}

	#[Test]
	public function should_route_username_to_gateway_when_user_has_identity_conflict_flag(): void {
		$existing = new WP_User( 97 );
		$existing->user_login = 'conflicted-member';
		$existing->user_email = 'conflicted@example.test';
		$GLOBALS['agend_test_users'][] = $existing;
		update_user_meta( 97, AGEND_APPS_IDENTITY_CONFLICT_META, '1' );
		Agend_Test_WP::queue_response( 202, array( 'data' => array( 'status' => 'mfa_required', 'mfa_token' => 'private-token', 'factors' => array( array( 'id' => 'factor-1', 'factor_type' => 'totp' ) ) ) ) );
		$this->assertNull( agend_apps_wp_login_authenticate( null, 'conflicted-member', 'password' ) );
		$this->assertSame( 1, $this->requestCount( '/auth/login' ) );
		$this->assertSame( 'agend_apps_mfa_required', agend_apps_wp_login_refuse_wordpress_password( $existing, 'conflicted-member', 'password' )->get_error_code() );
	}

	#[Test]
	public function should_sign_in_email_owner_when_another_users_login_equals_that_email(): void {
		$other = new WP_User( 98 );
		$other->user_login = 'owner@example.test';
		$other->user_email = 'other@example.test';
		$owner = new WP_User( 99 );
		$owner->user_login = 'owner-login';
		$owner->user_email = 'owner@example.test';
		$GLOBALS['agend_test_users'][] = $other;
		$GLOBALS['agend_test_users'][] = $owner;
		update_user_meta( 98, '_agend_apps_supabase_user_id', 'other-agend-user' );
		Agend_Test_WP::set_filter( 'agend_apps_member_login_user_id', 99 );
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'session' => array( 'access_token' => 'access', 'refresh_token' => 'refresh', 'expires_at' => time() + 3600 ) ) ) );
		$this->assertSame( $owner, agend_apps_wp_login_authenticate( null, 'owner@example.test', 'password' ) );
		$this->assertSame( '', agend_apps_wp_login_arm_refusal()['email'] );
		$this->assertSame( 'owner@example.test', json_decode( Agend_Test_WP::$requests[0]['body'], true )['email'] );
	}

	#[Test]
	public function should_keep_local_login_when_unlinked_wordpress_user_submits_username(): void {
		$existing = new WP_User( 88 );
		$existing->user_login = 'wordpress-only';
		$existing->user_email = 'local@example.test';
		$GLOBALS['agend_test_users'][] = $existing;
		$this->assertNull( agend_apps_wp_login_authenticate( null, 'wordpress-only', 'password' ) );
		$this->assertSame( $existing, agend_apps_wp_login_refuse_wordpress_password( $existing, 'wordpress-only', 'password' ) );
		$this->assertSame( 0, $this->requestCount( '/auth/login' ) );
	}

	#[Test]
	public function should_refuse_local_password_when_gateway_throttle_blocks_a_linked_member(): void {
		$existing = new WP_User( 89 );
		$existing->user_email = 'throttled@example.test';
		$GLOBALS['agend_test_users'][] = $existing;
		update_user_meta( 89, '_agend_apps_contact_id', 'contact-1' );
		Agend_Test_WP::set_filter( 'agend_apps_auth_login_limits', array( 'per_ip' => 0, 'per_email' => 0 ) );
		$this->assertNull( agend_apps_wp_login_authenticate( null, 'throttled@example.test', 'password' ) );
		$this->assertSame( 'agend_apps_invalid_credentials', agend_apps_wp_login_refuse_wordpress_password( $existing, 'throttled@example.test', 'password' )->get_error_code() );
		$this->assertSame( 0, $this->requestCount( '/auth/login' ) );
	}

	#[Test]
	public function should_reject_code_without_gateway_request_when_wp_login_nonce_is_missing_or_wrong(): void {
		$public = agend_apps_auth_create_mfa_challenge( array( 'data' => array( 'mfa_token' => 'private-token', 'factors' => array( array( 'id' => 'factor-1', 'factor_type' => 'totp' ) ) ) ), 'nonce@example.test' );
		$input = array( 'challenge_id' => $public['challenge_id'], 'factor_id' => 'factor-1', 'code' => '123456' );
		$this->assertSame( 'agend_apps_mfa_nonce', agend_apps_wp_login_verify_mfa( $input )->get_error_code() );
		$input['agend_mfa_nonce'] = 'wrong';
		$this->assertSame( 'agend_apps_mfa_nonce', agend_apps_wp_login_verify_mfa( $input )->get_error_code() );
		$this->assertSame( 0, $this->requestCount( '/auth/mfa/verify' ) );
		$this->assertSame( array(), Agend_Test_WP::$auth_cookie_users );
	}

	#[Test]
	public function should_clear_stale_enrolment_when_gateway_issues_a_direct_password_session(): void {
		$existing = new WP_User( 86 );
		$existing->user_email = 'removed@example.test';
		$GLOBALS['agend_test_users'][] = $existing;
		update_user_meta( 86, 'agend_mfa_enrolled', '1' );
		Agend_Test_WP::set_filter( 'agend_apps_member_login_user_id', 86 );
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'session' => array( 'access_token' => 'access', 'refresh_token' => 'refresh', 'expires_at' => time() + 3600 ) ) ) );
		$this->assertInstanceOf( WP_User::class, agend_apps_wp_login_authenticate( null, 'removed@example.test', 'password' ) );
		$this->assertSame( '', get_user_meta( 86, 'agend_mfa_enrolled', true ) );
		$this->assertSame( '', agend_apps_wp_login_arm_refusal()['email'] );
	}

	#[Test]
	public function should_refuse_a_known_mfa_member_when_the_gateway_is_unreachable(): void {
		$existing = new WP_User( 82 );
		$existing->user_email = 'known@example.test';
		$existing->user_pass = 'local-password';
		$GLOBALS['agend_test_users'][] = $existing;
		update_user_meta( 82, 'agend_mfa_enrolled', '1' );
		Agend_Test_WP::queue_response( 503, array( 'error' => array( 'code' => 'UNAVAILABLE' ) ) );
		$this->assertNull( agend_apps_wp_login_authenticate( null, 'known@example.test', 'local-password' ) );
		$this->assertSame( 'agend_apps_invalid_credentials', agend_apps_wp_login_refuse_wordpress_password( $existing, 'known@example.test', 'local-password' )->get_error_code() );
	}

	#[Test]
	public function should_complete_the_bridge_only_after_a_valid_code_and_write_the_enrolment_marker(): void {
		$existing = new WP_User( 83 );
		$existing->user_email = 'verified@example.test';
		$GLOBALS['agend_test_users'][] = $existing;
		$public = agend_apps_auth_create_mfa_challenge( array( 'data' => array( 'mfa_token' => 'private-token', 'factors' => array( array( 'id' => 'factor-1', 'factor_type' => 'totp' ) ) ) ), 'verified@example.test' );
		$id = $public['challenge_id'];
		$input = array( 'challenge_id' => $id, 'factor_id' => 'factor-1', 'code' => '123456', 'agend_mfa_nonce' => wp_create_nonce( 'agend_apps_mfa_' . $id ) );
		$bad = $input;
		$bad['agend_mfa_nonce'] = 'wrong';
		$this->assertSame( 'agend_apps_mfa_nonce', agend_apps_wp_login_verify_mfa( $bad )->get_error_code() );
		$this->assertFalse( Agend_Apps_Member_Session::has_session( 83 ) );
		Agend_Test_WP::set_filter( 'agend_apps_member_login_user_id', 83 );
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'session' => array( 'access_token' => 'access', 'refresh_token' => 'refresh', 'expires_at' => time() + 3600 ) ) ) );
		$this->assertInstanceOf( WP_User::class, agend_apps_wp_login_verify_mfa( $input ) );
		$this->assertTrue( Agend_Apps_Member_Session::has_session( 83 ) );
		$this->assertSame( '1', get_user_meta( 83, 'agend_mfa_enrolled', true ) );
		$this->assertFalse( agend_apps_auth_get_mfa_challenge( $id ) );
	}

	#[Test]
	public function should_fire_wp_login_once_and_honour_remember_and_redirect_when_code_succeeds(): void {
		$existing = new WP_User( 90 );
		$existing->user_email = 'action@example.test';
		$existing->user_login = 'action-member';
		$GLOBALS['agend_test_users'][] = $existing;
		$public = agend_apps_auth_create_mfa_challenge( array( 'data' => array( 'mfa_token' => 'private-token', 'factors' => array( array( 'id' => 'factor-1', 'factor_type' => 'totp' ) ) ) ), 'action@example.test', true );
		$id = $public['challenge_id'];
		Agend_Test_WP::set_filter( 'agend_apps_member_login_user_id', 90 );
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'session' => array( 'access_token' => 'access', 'refresh_token' => 'refresh', 'expires_at' => time() + 3600 ) ) ) );
		Agend_Test_WP::set_filter( 'login_redirect', '/member-home' );
		$input = array( 'challenge_id' => $id, 'factor_id' => 'factor-1', 'code' => '123456', 'agend_mfa_nonce' => wp_create_nonce( 'agend_apps_mfa_' . $id ), 'redirect_to' => '/original' );
		$this->assertInstanceOf( WP_User::class, agend_apps_wp_login_mfa_action_result( $input ) );
		$this->assertSame( array( 90 ), Agend_Test_WP::$auth_cookie_users );
		$this->assertSame( array( true ), Agend_Test_WP::$auth_cookie_remembers );
		$this->assertSame( 1, did_action( 'wp_login' ) );
		$this->assertSame( '/member-home', Agend_Test_WP::$redirects[0]['location'] );
	}

	#[Test]
	public function should_issue_nonpersistent_cookie_when_first_form_did_not_select_remember_me(): void {
		$existing = new WP_User( 93 );
		$existing->user_email = 'short-session@example.test';
		$existing->user_login = 'short-session';
		$GLOBALS['agend_test_users'][] = $existing;
		$public = agend_apps_auth_create_mfa_challenge( array( 'data' => array( 'mfa_token' => 'private-token', 'factors' => array( array( 'id' => 'factor-1', 'factor_type' => 'totp' ) ) ) ), 'short-session@example.test' );
		$id = $public['challenge_id'];
		Agend_Test_WP::set_filter( 'agend_apps_member_login_user_id', 93 );
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'session' => array( 'access_token' => 'access', 'refresh_token' => 'refresh', 'expires_at' => time() + 3600 ) ) ) );
		$input = array( 'challenge_id' => $id, 'factor_id' => 'factor-1', 'code' => '123456', 'agend_mfa_nonce' => wp_create_nonce( 'agend_apps_mfa_' . $id ) );
		$this->assertInstanceOf( WP_User::class, agend_apps_wp_login_mfa_action_result( $input ) );
		$this->assertSame( array( false ), Agend_Test_WP::$auth_cookie_remembers );
	}

	#[Test]
	public function should_keep_code_step_without_wp_login_when_code_is_wrong(): void {
		$public = agend_apps_auth_create_mfa_challenge( array( 'data' => array( 'mfa_token' => 'private-token', 'factors' => array( array( 'id' => 'factor-1', 'factor_type' => 'totp' ) ) ) ), 'wrong@example.test' );
		$id = $public['challenge_id'];
		Agend_Test_WP::queue_response( 401, array( 'error' => array( 'code' => 'INVALID_MFA_CODE', 'message' => 'Invalid code.' ) ) );
		$input = array( 'challenge_id' => $id, 'factor_id' => 'factor-1', 'code' => '000000', 'agend_mfa_nonce' => wp_create_nonce( 'agend_apps_mfa_' . $id ) );
		$this->assertInstanceOf( WP_Error::class, agend_apps_wp_login_mfa_action_result( $input ) );
		$this->assertIsArray( agend_apps_auth_get_mfa_challenge( $id ) );
		$this->assertSame( 0, did_action( 'wp_login' ) );
	}

	#[Test]
	public function should_refuse_gateway_check_when_wp_login_mfa_ip_limit_is_reached(): void {
		$public = agend_apps_auth_create_mfa_challenge( array( 'data' => array( 'mfa_token' => 'private-token', 'factors' => array( array( 'id' => 'factor-1', 'factor_type' => 'totp' ) ) ) ), 'limit@example.test' );
		$id = $public['challenge_id'];
		$input = array( 'challenge_id' => $id, 'factor_id' => 'factor-1', 'code' => '000000', 'agend_mfa_nonce' => wp_create_nonce( 'agend_apps_mfa_' . $id ) );
		Agend_Test_WP::set_filter( 'agend_apps_auth_mfa_per_ip_limit', 0 );
		$this->assertSame( 'agend_apps_mfa_throttled', agend_apps_wp_login_verify_mfa( $input )->get_error_code() );
		$this->assertSame( 0, $this->requestCount( '/auth/mfa/verify' ) );
	}

	#[Test]
	public function should_refuse_code_without_wp_login_when_challenge_is_expired(): void {
		$id = str_repeat( 'a', 64 );
		$input = array( 'challenge_id' => $id, 'factor_id' => 'factor-1', 'code' => '123456', 'agend_mfa_nonce' => wp_create_nonce( 'agend_apps_mfa_' . $id ) );
		$this->assertSame( 'agend_apps_invalid_credentials', agend_apps_wp_login_mfa_action_result( $input )->get_error_code() );
		$this->assertSame( 0, did_action( 'wp_login' ) );
	}

	#[Test]
	public function should_return_generic_error_without_wp_login_when_verified_member_has_no_wordpress_user(): void {
		$public = agend_apps_auth_create_mfa_challenge( array( 'data' => array( 'mfa_token' => 'private-token', 'factors' => array( array( 'id' => 'factor-1', 'factor_type' => 'totp' ) ) ) ), 'unresolved@example.test' );
		$id = $public['challenge_id'];
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'session' => array( 'access_token' => 'access', 'refresh_token' => 'refresh', 'expires_at' => time() + 3600 ) ) ) );
		$input = array( 'challenge_id' => $id, 'factor_id' => 'factor-1', 'code' => '123456', 'agend_mfa_nonce' => wp_create_nonce( 'agend_apps_mfa_' . $id ) );
		$this->assertSame( 'agend_apps_invalid_credentials', agend_apps_wp_login_mfa_action_result( $input )->get_error_code() );
		$this->assertSame( 0, did_action( 'wp_login' ) );
	}

	#[Test]
	public function should_make_no_register_call_when_the_wordpress_password_is_wrong(): void {
		$existing             = new WP_User( 21 );
		$existing->user_email = 'existing@example.test';
		$existing->user_pass  = 'correct-password';
		$GLOBALS['agend_test_users'][] = $existing;

		$result = agend_apps_wp_login_register_existing_user( 'existing@example.test', 'wrong-password' );

		$this->assertNull( $result );
		$this->assertSame( 0, $this->requestCount( '/auth/register' ) );
	}

	#[Test]
	public function should_call_register_once_when_the_wordpress_password_is_correct(): void {
		$existing             = new WP_User( 22 );
		$existing->user_email = 'existing@example.test';
		$existing->user_pass  = 'correct-password';
		$GLOBALS['agend_test_users'][] = $existing;

		$result = agend_apps_wp_login_register_existing_user( 'existing@example.test', 'correct-password' );

		$this->assertIsArray( $result );
		$this->assertSame( 1, $this->requestCount( '/auth/register' ) );
	}

	/**
	 * Asserts the verification-pending outcome common to all three
	 * verification-required cases: pending meta written, no session stored,
	 * the refusal armed with the verification reason for the submitted email,
	 * and priority 30 replacing whatever WordPress resolved with the
	 * verification-specific `WP_Error`
	 * (SPEC-CORE-20260907-wordpress-email-verification-handling Decision
	 * change A).
	 */
	private function assertVerificationPendingAndRefused( ?WP_User $result, int $user_id, string $email ): void {
		$this->assertNull( $result );
		$this->assertSame( '1', get_user_meta( $user_id, AGEND_APPS_VERIFICATION_PENDING_META, true ) );
		$this->assertFalse( Agend_Apps_Member_Session::has_session( $user_id ) );

		$armed = agend_apps_wp_login_arm_refusal();
		$this->assertSame( $email, $armed['email'] );
		$this->assertSame( 'verification', $armed['reason'] );

		$refused = agend_apps_wp_login_refuse_wordpress_password( new WP_User( $user_id ), $email, 'whatever' );

		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 'agend_apps_verification_required', $refused->get_error_code() );
	}

	#[Test]
	public function should_record_pending_and_arm_the_verification_refusal_on_a_202_login_response(): void {
		$existing             = new WP_User( 31 );
		$existing->user_email = 'pending@example.test';
		$existing->user_pass  = 'whatever';
		$GLOBALS['agend_test_users'][] = $existing;

		Agend_Test_WP::queue_response(
			202,
			array(
				'data' => array(
					'status'  => 'verification_required',
					'message' => 'Check your email to verify your address.',
				),
			)
		);

		$result = agend_apps_wp_login_authenticate( null, 'pending@example.test', 'whatever' );

		$this->assertVerificationPendingAndRefused( $result, 31, 'pending@example.test' );
	}

	#[Test]
	public function should_record_pending_and_arm_the_verification_refusal_on_a_503_verification_email_unavailable_response(): void {
		$existing             = new WP_User( 32 );
		$existing->user_email = 'pending2@example.test';
		$existing->user_pass  = 'whatever';
		$GLOBALS['agend_test_users'][] = $existing;

		Agend_Test_WP::queue_response(
			503,
			array(
				'error' => array(
					'code'    => 'VERIFICATION_EMAIL_UNAVAILABLE',
					'message' => 'Could not send the verification email.',
				),
			)
		);

		$result = agend_apps_wp_login_authenticate( null, 'pending2@example.test', 'whatever' );

		$this->assertVerificationPendingAndRefused( $result, 32, 'pending2@example.test' );
	}

	#[Test]
	public function should_record_pending_and_arm_the_verification_refusal_when_register_returns_pending_email_confirmation(): void {
		$existing             = new WP_User( 33 );
		$existing->user_email = 'newmember@example.test';
		$existing->user_pass  = 'correct-password';
		$GLOBALS['agend_test_users'][] = $existing;

		// The first request (login) is rejected as invalid credentials
		// (no dashboard account exists yet); the second (register) succeeds
		// but withholds the session pending verification.
		Agend_Test_WP::queue_response(
			401,
			array( 'error' => array( 'code' => 'INVALID_CREDENTIALS', 'message' => 'Invalid credentials.' ) )
		);
		Agend_Test_WP::queue_response(
			201,
			array(
				'data' => array(
					'session'                    => null,
					'pending_email_confirmation' => true,
				),
			)
		);

		$result = agend_apps_wp_login_authenticate( null, 'newmember@example.test', 'correct-password' );

		$this->assertVerificationPendingAndRefused( $result, 33, 'newmember@example.test' );
	}

	#[Test]
	public function should_still_return_the_generic_error_for_the_email_conflict_case(): void {
		$existing             = new WP_User( 41 );
		$existing->user_email = 'conflict@example.test';
		$existing->user_pass  = 'correct-password';
		$GLOBALS['agend_test_users'][] = $existing;

		Agend_Test_WP::queue_response(
			409,
			array( 'error' => array( 'code' => 'EMAIL_ALREADY_REGISTERED', 'message' => 'Email already registered.' ) )
		);

		$result = agend_apps_wp_login_register_existing_user( 'conflict@example.test', 'correct-password' );

		$this->assertNull( $result );

		$armed = agend_apps_wp_login_arm_refusal();
		$this->assertSame( 'conflict@example.test', $armed['email'] );
		$this->assertSame( 'conflict', $armed['reason'] );

		$refused = agend_apps_wp_login_refuse_wordpress_password( new WP_User( 41 ), 'conflict@example.test', 'correct-password' );

		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 'agend_apps_invalid_credentials', $refused->get_error_code() );
	}

	#[Test]
	public function should_refuse_wordpress_password_on_first_attempt_when_gateway_registration_finds_email_conflict(): void {
		$existing = new WP_User( 102 );
		$existing->user_email = 'first-conflict@example.test';
		$existing->user_pass = 'local-password';
		$GLOBALS['agend_test_users'][] = $existing;
		$this->assertSame( '', get_user_meta( 102, 'agend_mfa_enrolled', true ) );
		Agend_Test_WP::queue_response( 401, array( 'error' => array( 'code' => 'INVALID_CREDENTIALS', 'message' => 'Invalid.' ) ) );
		Agend_Test_WP::queue_response( 409, array( 'error' => array( 'code' => 'EMAIL_ALREADY_REGISTERED', 'message' => 'Email already registered.' ) ) );

		$this->assertTrue( wp_check_password( 'local-password', $existing->user_pass, $existing->ID ) );
		$this->assertNull( agend_apps_wp_login_authenticate( null, $existing->user_email, 'local-password' ) );
		$this->assertSame( 1, $this->requestCount( '/auth/login' ) );
		$this->assertSame( 1, $this->requestCount( '/auth/register' ) );
		$refused = agend_apps_wp_login_refuse_wordpress_password( $existing, $existing->user_email, 'local-password' );
		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 'agend_apps_invalid_credentials', $refused->get_error_code() );
	}

	/**
	 * A gateway answer the client cannot read must hand an unlinked login back
	 * to WordPress without throwing on an empty or scalar body.
	 */
	#[Test]
	public function should_hand_the_login_to_wordpress_when_a_2xx_login_response_has_no_body(): void {
		$existing                      = new WP_User( 51 );
		$existing->user_email          = 'nobody@example.test';
		$existing->user_pass           = 'correct-password';
		$GLOBALS['agend_test_users'][] = $existing;

		Agend_Test_WP::queue_response( 200, '' );

		$result = agend_apps_wp_login_authenticate( null, 'nobody@example.test', 'correct-password' );

		$this->assertNull( $result );
		$this->assertSame( '', agend_apps_wp_login_arm_refusal()['email'] );
		$this->assertFalse( Agend_Apps_Member_Session::has_session( 51 ) );
	}

	#[Test]
	public function should_hand_the_login_to_wordpress_when_a_2xx_register_response_has_no_body(): void {
		$existing                      = new WP_User( 52 );
		$existing->user_email          = 'noregister@example.test';
		$existing->user_pass           = 'correct-password';
		$GLOBALS['agend_test_users'][] = $existing;

		Agend_Test_WP::queue_response(
			401,
			array( 'error' => array( 'code' => 'INVALID_CREDENTIALS', 'message' => 'Invalid credentials.' ) )
		);
		Agend_Test_WP::queue_response( 201, '' );

		$result = agend_apps_wp_login_authenticate( null, 'noregister@example.test', 'correct-password' );

		$this->assertNull( $result );
		$this->assertSame( '', agend_apps_wp_login_arm_refusal()['email'] );
		$this->assertSame( '', get_user_meta( 52, AGEND_APPS_VERIFICATION_PENDING_META, true ) );
	}

	#[Test]
	public function should_hand_the_login_to_wordpress_when_a_2xx_login_body_decodes_to_a_scalar(): void {
		$existing                      = new WP_User( 53 );
		$existing->user_email          = 'scalar@example.test';
		$existing->user_pass           = 'correct-password';
		$GLOBALS['agend_test_users'][] = $existing;

		Agend_Test_WP::queue_response( 200, 'true' );

		$result = agend_apps_wp_login_authenticate( null, 'scalar@example.test', 'correct-password' );

		$this->assertNull( $result );
		$this->assertSame( '', agend_apps_wp_login_arm_refusal()['email'] );
	}
}
