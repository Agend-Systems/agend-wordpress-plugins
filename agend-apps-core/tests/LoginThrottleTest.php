<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\AppsCore;

use Agend\Tests\TestCase;
use Agend_Apps_Auth_REST_Controller;
use Agend_Test_WP;
use PHPUnit\Framework\Attributes\Test;
use WP_REST_Request;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/member-provisioning.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/auth.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-member-session.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-token-worker.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/wp-login-bridge.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/rest/class-agend-apps-rest-controller.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/rest/auth-routes.php';

/**
 * The sign-in throttle at the proxy edge counts only failed sign-ins, per
 * email and per IP, in a fixed 15-minute window; a successful sign-in clears
 * the email's count. A member who signs in correctly is never locked out,
 * and guessing a password is still capped.
 */
final class LoginThrottleTest extends TestCase {

	private const EMAIL = 'member@example.test';

	protected function setUp(): void {
		parent::setUp();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
		Agend_Test_WP::set_filter( 'agend_apps_member_login_user_id', 75 );
	}

	private function signIn( string $email = self::EMAIL ) {
		$request = new WP_REST_Request( 'POST', '/agend-apps/v1/auth/login' );
		$request->set_param( 'email', $email );
		$request->set_param( 'password', 'password' );

		return ( new Agend_Apps_Auth_REST_Controller() )->login( $request );
	}

	private function queueSuccess(): void {
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'user' => array( 'id' => 'u1' ), 'session' => array( 'access_token' => 'access', 'refresh_token' => 'refresh', 'expires_at' => time() + 3600 ) ) ) );
	}

	private function queueWrongPassword(): void {
		Agend_Test_WP::queue_response( 401, array( 'error' => array( 'code' => 'INVALID_CREDENTIALS', 'message' => 'Invalid login credentials' ) ) );
	}

	private function loginRequests(): int {
		return count( array_filter( Agend_Test_WP::$requests, static fn ( $r ) => str_contains( (string) $r['url'], '/auth/login' ) ) );
	}

	#[Test]
	public function should_never_lock_out_a_member_who_signs_in_correctly(): void {
		for ( $i = 1; $i <= 8; $i++ ) {
			$this->queueSuccess();
			$this->assertNotSame( 429, $this->signIn()->get_status(), "sign-in {$i}" );
		}
		$this->assertSame( 8, $this->loginRequests() );
	}

	#[Test]
	public function should_stop_a_sixth_wrong_password_without_asking_the_gateway(): void {
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->queueWrongPassword();
			$this->assertSame( 401, $this->signIn()->get_status(), "attempt {$i}" );
		}

		$blocked = $this->signIn();

		$this->assertSame( 429, $blocked->get_status() );
		$this->assertSame( 'too_many_attempts', $blocked->get_data()['code'] );
		$this->assertMatchesRegularExpression( '/^Too many unsuccessful sign-in attempts\. Please wait 15 minutes, then try again, or reset your password\.$/', $blocked->get_data()['message'] );
		$this->assertSame( '900', $blocked->get_headers()['Retry-After'] );
		$this->assertSame( 5, $this->loginRequests(), 'the blocked attempt never reaches the gateway' );
	}

	#[Test]
	public function should_clear_the_email_count_when_the_member_then_signs_in(): void {
		for ( $i = 1; $i <= 4; $i++ ) {
			$this->queueWrongPassword();
			$this->signIn();
		}
		$this->queueSuccess();
		$this->assertSame( 200, $this->signIn()->get_status() );

		for ( $i = 1; $i <= 5; $i++ ) {
			$this->queueWrongPassword();
			$this->assertSame( 401, $this->signIn()->get_status(), "fresh attempt {$i}" );
		}
		$this->assertSame( 429, $this->signIn()->get_status() );
	}

	#[Test]
	public function should_keep_the_window_fixed_from_the_first_failure(): void {
		$key = 'agend_apps_login_fail_email_' . md5( self::EMAIL );

		$this->queueWrongPassword();
		$this->signIn();
		$first = get_transient( $key );
		$this->queueWrongPassword();
		$this->signIn();
		$second = get_transient( $key );

		$this->assertSame( 2, $second['count'] );
		$this->assertSame( $first['ends'], $second['ends'], 'a later failure does not restart the window' );
		$this->assertLessThanOrEqual( 900, Agend_Test_WP::$transient_ttls[ $key ] );
	}

	#[Test]
	public function should_let_the_member_try_again_once_the_window_has_passed(): void {
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->queueWrongPassword();
			$this->signIn();
		}
		$this->assertSame( 429, $this->signIn()->get_status() );

		// The window ends: both counters read as empty from then on.
		foreach ( Agend_Test_WP::$transients as $key => $value ) {
			if ( str_starts_with( $key, 'agend_apps_login_fail_' ) ) {
				Agend_Test_WP::$transients[ $key ]['ends'] = time() - 1;
			}
		}
		$this->queueSuccess();
		$this->assertSame( 200, $this->signIn()->get_status() );
	}

	#[Test]
	public function should_say_how_long_to_wait_in_whole_minutes(): void {
		$this->assertSame( 'Too many unsuccessful sign-in attempts. Please wait 1 minute, then try again, or reset your password.', agend_apps_auth_throttle_message( 20 ) );
		$this->assertSame( 'Too many unsuccessful sign-in attempts. Please wait 3 minutes, then try again, or reset your password.', agend_apps_auth_throttle_message( 121 ) );
	}

	#[Test]
	public function should_not_count_an_outage_or_a_bad_api_key_as_a_wrong_password(): void {
		for ( $i = 1; $i <= 6; $i++ ) {
			Agend_Test_WP::queue_response( 503, array( 'error' => array( 'code' => 'UNAVAILABLE', 'message' => 'Down.' ) ) );
			$this->signIn();
			Agend_Test_WP::queue_response( 401, array( 'error' => array( 'code' => 'INVALID_API_KEY', 'message' => 'Invalid key.' ) ) );
			$this->signIn();
		}
		$this->assertSame( 12, $this->loginRequests() );
		$this->assertFalse( get_transient( 'agend_apps_login_fail_email_' . md5( self::EMAIL ) ) );
	}

	#[Test]
	public function should_still_cap_one_address_guessing_across_many_emails(): void {
		for ( $i = 1; $i <= 20; $i++ ) {
			$this->queueWrongPassword();
			$this->signIn( "guess{$i}@example.test" );
		}
		$this->assertSame( 429, $this->signIn( 'guess21@example.test' )->get_status() );

		// A guesser cannot reset the address's count by signing in to an
		// account of their own.
		$this->assertSame( 20, get_transient( 'agend_apps_login_fail_ip_' . md5( '203.0.113.7' ) )['count'] );
	}

	#[Test]
	public function should_keep_registration_and_resends_out_of_the_sign_in_count(): void {
		$this->assertTrue( agend_apps_auth_attempt_throttle( self::EMAIL, '203.0.113.7' ) );
		$this->assertFalse( get_transient( 'agend_apps_login_fail_email_' . md5( self::EMAIL ) ) );
		$this->assertSame( 1, get_transient( 'agend_apps_auth_attempt_email_' . md5( self::EMAIL ) )['count'] );

		for ( $i = 2; $i <= 5; $i++ ) {
			$this->assertTrue( agend_apps_auth_attempt_throttle( self::EMAIL, '203.0.113.7' ) );
		}
		$this->assertFalse( agend_apps_auth_attempt_throttle( self::EMAIL, '203.0.113.7' ), 'still capped per attempt' );
		$this->assertTrue( agend_apps_auth_login_throttle( self::EMAIL, '203.0.113.7' ), 'signing in is unaffected' );
	}
}
