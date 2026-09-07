<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use WP_Error;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/member-provisioning.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/auth.php';

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
	public function should_be_true_for_a_202_response_with_no_body_status(): void {
		$response = array(
			'status_code' => 202,
			'data'        => array(),
		);

		$this->assertTrue( agend_apps_auth_response_is_verification_required( $response ) );
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
