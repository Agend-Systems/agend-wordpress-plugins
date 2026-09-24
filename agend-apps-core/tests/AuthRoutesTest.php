<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use Agend_Apps_Auth_REST_Controller;
use Agend_Test_WP;
use Agend_Apps_Member_Session;
use PHPUnit\Framework\Attributes\Test;
use WP_REST_Request;
use WP_User;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/member-provisioning.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/auth.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-member-session.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-token-worker.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/wp-login-bridge.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/rest/class-agend-apps-rest-controller.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/rest/auth-routes.php';

/**
 * The REST auth proxy's verification-required handling
 * (SPEC-CORE-20260907-wordpress-email-verification-handling US-4.2): the
 * login() 202 path mirrors register()'s existing one, the 502 invalid_session
 * path narrows to a genuine broken 200, and resend-verification is uniform.
 */
final class AuthRoutesTest extends TestCase {

	private function controller(): Agend_Apps_Auth_REST_Controller {
		return new Agend_Apps_Auth_REST_Controller();
	}

	private function loginRequest( string $email, string $password ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/agend-apps/v1/auth/login' );
		$request->set_param( 'email', $email );
		$request->set_param( 'password', $password );

		return $request;
	}

	#[Test]
	public function should_require_mfa_without_creating_a_member_session_then_complete_it_once(): void {
		Agend_Test_WP::queue_response( 202, array( 'data' => array( 'status' => 'mfa_required', 'mfa_token' => 'private-token', 'factors' => array( array( 'id' => 'factor-1', 'factor_type' => 'totp', 'friendly_name' => 'Phone' ) ) ) ) );
		$pending = $this->controller()->login( $this->loginRequest( 'mfa@example.test', 'secret-password' ) );
		$this->assertSame( 202, $pending->get_status() );
		$this->assertSame( 'mfa_required', $pending->get_data()['code'] );
		$this->assertStringNotContainsString( 'private-token', json_encode( $pending->get_data() ) );
		$this->assertFalse( Agend_Apps_Member_Session::has_session( 75 ) );
		$id = $pending->get_data()['challenge_id'];
		$this->assertArrayNotHasKey( 'password', agend_apps_auth_get_mfa_challenge( $id ) );

		Agend_Test_WP::set_filter( 'agend_apps_member_login_user_id', 75 );
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'user' => array( 'id' => 'u1' ), 'session' => array( 'access_token' => 'access', 'refresh_token' => 'refresh', 'expires_at' => time() + 3600 ) ) ) );
		$request = new WP_REST_Request( 'POST', '/agend-apps/v1/auth/mfa/verify' );
		$request->set_param( 'challenge_id', $id );
		$request->set_param( 'factor_id', 'factor-1' );
		$request->set_param( 'code', '123456' );
		$done = $this->controller()->verify_mfa( $request );
		$this->assertSame( 200, $done->get_status() );
		$this->assertTrue( Agend_Apps_Member_Session::has_session( 75 ) );
		$this->assertSame( '1', get_user_meta( 75, 'agend_mfa_enrolled', true ) );
		$this->assertFalse( agend_apps_auth_get_mfa_challenge( $id ) );
		$this->assertSame( 400, $this->controller()->verify_mfa( $request )->get_status() );
	}

	#[Test]
	public function should_leave_the_challenge_available_after_a_wrong_code_without_signing_in(): void {
		$challenge = agend_apps_auth_create_mfa_challenge( array( 'data' => array( 'mfa_token' => 'private-token', 'factors' => array( array( 'id' => 'factor-1', 'factor_type' => 'totp' ) ) ) ), 'mfa@example.test' );
		Agend_Test_WP::queue_response( 401, array( 'error' => array( 'code' => 'INVALID_MFA_CODE', 'message' => 'Invalid code.' ) ) );
		$request = new WP_REST_Request( 'POST', '/agend-apps/v1/auth/mfa/verify' );
		$request->set_param( 'challenge_id', $challenge['challenge_id'] );
		$request->set_param( 'factor_id', 'factor-1' );
		$request->set_param( 'code', '000000' );
		$response = $this->controller()->verify_mfa( $request );
		$this->assertSame( 401, $response->get_status() );
		$this->assertIsArray( agend_apps_auth_get_mfa_challenge( $challenge['challenge_id'] ) );
		$this->assertFalse( Agend_Apps_Member_Session::has_session( 76 ) );
	}

	#[Test]
	public function should_clear_stale_enrolment_when_rest_login_receives_a_direct_session(): void {
		update_user_meta( 78, 'agend_mfa_enrolled', '1' );
		Agend_Test_WP::set_filter( 'agend_apps_member_login_user_id', 78 );
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'session' => array( 'access_token' => 'access', 'refresh_token' => 'refresh', 'expires_at' => time() + 3600 ) ) ) );
		$response = $this->controller()->login( $this->loginRequest( 'removed@example.test', 'password' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '', get_user_meta( 78, 'agend_mfa_enrolled', true ) );
	}

	#[Test]
	public function should_return_202_verification_required_with_the_gateway_message_on_a_202_login_response(): void {
		Agend_Test_WP::queue_response(
			202,
			array(
				'data' => array(
					'status'  => 'verification_required',
					'message' => 'Please check your inbox.',
				),
			)
		);

		$response = $this->controller()->login( $this->loginRequest( 'pending@example.test', 'whatever' ) );

		$this->assertSame( 202, $response->get_status() );
		$this->assertSame( 'verification_required', $response->get_data()['code'] );
		$this->assertSame( 'Please check your inbox.', $response->get_data()['message'] );
	}

	#[Test]
	public function should_use_the_default_message_on_a_202_login_response_with_no_gateway_message(): void {
		Agend_Test_WP::queue_response(
			202,
			array( 'data' => array( 'status' => 'verification_required' ) )
		);

		$response = $this->controller()->login( $this->loginRequest( 'pending2@example.test', 'whatever' ) );

		$this->assertSame( 202, $response->get_status() );
		$this->assertSame( 'verification_required', $response->get_data()['code'] );
		$this->assertNotSame( '', $response->get_data()['message'] );
	}

	#[Test]
	public function should_record_pending_for_the_resolved_wordpress_user_on_a_202_login_response(): void {
		$existing                      = new WP_User( 51 );
		$existing->user_email          = 'pending3@example.test';
		$GLOBALS['agend_test_users'][] = $existing;

		Agend_Test_WP::queue_response(
			202,
			array( 'data' => array( 'status' => 'verification_required' ) )
		);

		$this->controller()->login( $this->loginRequest( 'pending3@example.test', 'whatever' ) );

		$this->assertSame( '1', get_user_meta( 51, AGEND_APPS_VERIFICATION_PENDING_META, true ) );
	}

	#[Test]
	public function should_return_202_verification_required_on_a_503_verification_email_unavailable_login_error(): void {
		Agend_Test_WP::queue_response(
			503,
			array(
				'error' => array(
					'code'    => 'VERIFICATION_EMAIL_UNAVAILABLE',
					'message' => 'Could not send the email.',
				),
			)
		);

		$response = $this->controller()->login( $this->loginRequest( 'pending4@example.test', 'whatever' ) );

		$this->assertSame( 202, $response->get_status() );
		$this->assertSame( 'verification_required', $response->get_data()['code'] );
	}

	#[Test]
	public function should_return_502_invalid_session_only_for_a_genuine_200_with_no_usable_session(): void {
		Agend_Test_WP::queue_response(
			200,
			array( 'data' => array( 'user' => array( 'id' => 'u1' ) ) )
		);

		$response = $this->controller()->login( $this->loginRequest( 'broken@example.test', 'whatever' ) );

		$this->assertSame( 502, $response->get_status() );
		$this->assertSame( 'invalid_session', $response->get_data()['code'] );
	}

	#[Test]
	public function should_return_202_verification_sent_for_a_valid_email(): void {
		$request = new WP_REST_Request( 'POST', '/agend-apps/v1/auth/resend-verification' );
		$request->set_param( 'email', 'someone@example.test' );

		$response = $this->controller()->resend_verification( $request );

		$this->assertSame( 202, $response->get_status() );
		$this->assertSame( 'verification_sent', $response->get_data()['code'] );
	}

	#[Test]
	public function should_return_202_verification_sent_even_when_the_gateway_rate_limits_it(): void {
		Agend_Test_WP::queue_response(
			429,
			array( 'error' => array( 'code' => 'RATE_LIMITED', 'message' => 'Too many requests.' ) )
		);

		$request = new WP_REST_Request( 'POST', '/agend-apps/v1/auth/resend-verification' );
		$request->set_param( 'email', 'someone2@example.test' );

		$response = $this->controller()->resend_verification( $request );

		$this->assertSame( 202, $response->get_status() );
		$this->assertSame( 'verification_sent', $response->get_data()['code'] );
	}

	#[Test]
	public function should_reject_an_invalid_email_on_resend(): void {
		$request = new WP_REST_Request( 'POST', '/agend-apps/v1/auth/resend-verification' );
		$request->set_param( 'email', 'not-an-email' );

		$response = $this->controller()->resend_verification( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_email', $response->get_data()['code'] );
	}

	#[Test]
	public function should_report_verification_pending_and_hide_the_portal_url_on_session_status(): void {
		$user_id                          = 61;
		$GLOBALS['agend_test_current_user_id'] = $user_id;
		$wp_user                           = new WP_User( $user_id );
		$wp_user->user_email               = 'pending5@example.test';
		$GLOBALS['agend_test_users'][]     = $wp_user;
		update_user_meta( $user_id, AGEND_APPS_VERIFICATION_PENDING_META, '1' );

		$response = $this->controller()->session_status( new WP_REST_Request( 'GET', '/agend-apps/v1/auth/session' ) );
		$data     = $response->get_data();

		$this->assertTrue( $data['signed_in'] );
		$this->assertTrue( $data['verification_pending'] );
		$this->assertSame( '', $data['portal_url'] );
		$this->assertSame( 'pending5@example.test', $data['email'] );
	}
}
