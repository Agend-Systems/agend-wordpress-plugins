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

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/member-provisioning.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/auth.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-member-session.php';

/**
 * `agend_apps_auth_response_is_verification_required()`
 * (SPEC-CORE-20260907-wordpress-email-verification-handling US-4.1 Decision
 * change B): the HTTP status carried onto a decoded success array is the
 * primary signal, the structural `data.status` check is a fallback only for
 * when that status is absent, and a WP_Error is verification-required only
 * on the exact 503 + `VERIFICATION_EMAIL_UNAVAILABLE` pairing.
 */
final class AuthApiTest extends TestCase {

	#[Test]
	public function should_be_false_for_a_202_response_with_no_body_status(): void {
		$response = array(
			'status_code' => 202,
			'data'        => array(),
		);

		$this->assertFalse( agend_apps_auth_response_is_verification_required( $response ) );
	}

	#[Test]
	public function should_distinguish_mfa_from_email_verification_and_keep_the_token_server_side(): void {
		$response = array( 'status_code' => 202, 'data' => array( 'status' => 'mfa_required', 'mfa_token' => 'private-token', 'factors' => array( array( 'id' => 'factor-1', 'factor_type' => 'totp', 'friendly_name' => 'Phone' ) ) ) );
		$this->assertTrue( agend_apps_auth_response_is_mfa_required( $response ) );
		$this->assertFalse( agend_apps_auth_response_is_verification_required( $response ) );
		$public = agend_apps_auth_create_mfa_challenge( $response, 'member@example.test' );
		$this->assertSame( 64, strlen( $public['challenge_id'] ) );
		$this->assertStringNotContainsString( 'private-token', json_encode( $public ) );
		$this->assertSame( 'private-token', agend_apps_auth_get_mfa_challenge( $public['challenge_id'] )['mfa_token'] );
		$this->assertArrayNotHasKey( 'password', agend_apps_auth_get_mfa_challenge( $public['challenge_id'] ) );
	}

	#[Test]
	public function should_reject_factor_without_gateway_request_when_factor_is_not_in_challenge(): void {
		$public = agend_apps_auth_create_mfa_challenge( array( 'data' => array( 'mfa_token' => 'private-token', 'factors' => array( array( 'id' => 'allowed-factor', 'factor_type' => 'totp' ) ) ) ), 'member@example.test' );
		$result = agend_apps_auth_verify_mfa_challenge( $public['challenge_id'], 'other-factor', '123456' );
		$this->assertSame( 'agend_apps_invalid_mfa_factor', $result->get_error_code() );
		$this->assertSame( array(), Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_delete_challenge_and_refuse_reuse_when_mfa_verification_succeeds(): void {
		$public = agend_apps_auth_create_mfa_challenge( array( 'data' => array( 'mfa_token' => 'private-token', 'factors' => array( array( 'id' => 'factor-1', 'factor_type' => 'totp' ) ) ) ), 'member@example.test' );
		$id = $public['challenge_id'];
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'session' => array( 'access_token' => 'access', 'refresh_token' => 'refresh', 'expires_at' => time() + 3600 ) ) ) );
		$this->assertIsArray( agend_apps_auth_verify_mfa_challenge( $id, 'factor-1', '123456' ) );
		$this->assertFalse( agend_apps_auth_get_mfa_challenge( $id ) );
		$this->assertSame( 'agend_apps_mfa_expired', agend_apps_auth_verify_mfa_challenge( $id, 'factor-1', '123456' )->get_error_code() );
		$this->assertCount( 1, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_delete_challenge_when_five_codes_are_wrong(): void {
		$public = agend_apps_auth_create_mfa_challenge( array( 'data' => array( 'mfa_token' => 'private-token', 'factors' => array( array( 'id' => 'factor-1', 'factor_type' => 'totp' ) ) ) ), 'member@example.test' );
		$id = $public['challenge_id'];
		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			Agend_Test_WP::queue_response( 401, array( 'error' => array( 'code' => 'INVALID_MFA_CODE', 'message' => 'Wrong code.' ) ) );
			$this->assertInstanceOf( WP_Error::class, agend_apps_auth_verify_mfa_challenge( $id, 'factor-1', '000000' ) );
		}
		$this->assertFalse( agend_apps_auth_get_mfa_challenge( $id ) );
		$this->assertSame( 'agend_apps_mfa_expired', agend_apps_auth_verify_mfa_challenge( $id, 'factor-1', '000000' )->get_error_code() );
		$this->assertCount( 5, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_delete_challenge_when_gateway_rejects_the_token(): void {
		$public = agend_apps_auth_create_mfa_challenge( array( 'data' => array( 'mfa_token' => 'private-token', 'factors' => array( array( 'id' => 'factor-1', 'factor_type' => 'totp' ) ) ) ), 'member@example.test' );
		Agend_Test_WP::queue_response( 401, array( 'error' => array( 'code' => 'INVALID_MFA_TOKEN', 'message' => 'Expired.' ) ) );
		$this->assertInstanceOf( WP_Error::class, agend_apps_auth_verify_mfa_challenge( $public['challenge_id'], 'factor-1', '123456' ) );
		$this->assertFalse( agend_apps_auth_get_mfa_challenge( $public['challenge_id'] ) );
	}

	#[Test]
	public function should_clear_a_stored_member_session_when_refresh_requires_mfa(): void {
		$GLOBALS['agend_test_current_user_id'] = 77;
		Agend_Apps_Member_Session::store( 77, array( 'access_token' => 'old', 'refresh_token' => 'refresh', 'expires_at' => time() - 1 ) );
		Agend_Test_WP::queue_response( 401, array( 'error' => array( 'code' => 'MFA_REQUIRED', 'message' => 'MFA required.' ) ) );
		$this->assertSame( '', ( new Agend_Apps_Member_Session() )->provide_token( '' ) );
		$this->assertFalse( Agend_Apps_Member_Session::has_session( 77 ) );
	}

	#[Test]
	public function should_be_false_for_a_200_response_whose_body_carries_a_status_key(): void {
		$response = array(
			'status_code' => 200,
			'data'        => array(
				'status' => 'verification_required',
			),
		);

		$this->assertFalse( agend_apps_auth_response_is_verification_required( $response ) );
	}

	#[Test]
	public function should_fall_back_to_the_structural_status_when_status_code_is_absent(): void {
		$response = array(
			'data' => array(
				'status'  => 'verification_required',
				'message' => 'Check your email.',
			),
		);

		$this->assertTrue( agend_apps_auth_response_is_verification_required( $response ) );
	}

	#[Test]
	public function should_be_true_for_a_503_verification_email_unavailable_error(): void {
		$error = new WP_Error(
			'agend_api_error',
			'upstream',
			array(
				'status_code' => 503,
				'body'        => array( 'error' => array( 'code' => 'VERIFICATION_EMAIL_UNAVAILABLE' ) ),
			)
		);

		$this->assertTrue( agend_apps_auth_response_is_verification_required( $error ) );
	}

	#[Test]
	public function should_be_false_for_a_503_error_with_a_different_gateway_code(): void {
		$error = new WP_Error(
			'agend_api_error',
			'upstream',
			array(
				'status_code' => 503,
				'body'        => array( 'error' => array( 'code' => 'SERVICE_UNAVAILABLE' ) ),
			)
		);

		$this->assertFalse( agend_apps_auth_response_is_verification_required( $error ) );
	}

	#[Test]
	public function should_be_false_for_a_verification_email_unavailable_code_on_a_different_status(): void {
		$error = new WP_Error(
			'agend_api_error',
			'upstream',
			array(
				'status_code' => 500,
				'body'        => array( 'error' => array( 'code' => 'VERIFICATION_EMAIL_UNAVAILABLE' ) ),
			)
		);

		$this->assertFalse( agend_apps_auth_response_is_verification_required( $error ) );
	}
}
