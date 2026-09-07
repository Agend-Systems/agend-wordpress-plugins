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
}
