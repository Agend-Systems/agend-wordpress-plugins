<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use Agend_Apps_Member_Provisioning;
use PHPUnit\Framework\Attributes\Test;
use WP_Error;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/member-provisioning.php';

/**
 * Pure decisions behind dashboard-account provisioning: how a gateway error
 * is classified, how a login or register response is unwrapped, how the
 * register payload is shaped, and what a provisioning outcome writes to user
 * meta. The gateway calls themselves are not exercised here.
 */
final class MemberProvisioningTest extends TestCase {

	private function gatewayError( int $status, string $code = '', string $detail = '' ): WP_Error {
		$body = '' === $code ? array() : array( 'error' => array( 'code' => $code ) );
		if ( '' !== $detail ) {
			$body['error']['details'] = array( 'code' => $detail );
		}

		return new WP_Error(
			'agend_api_error',
			'upstream',
			array(
				'status_code' => $status,
				'body'        => $body,
			)
		);
	}

	#[Test]
	public function should_classify_a_409_email_already_registered_as_an_email_conflict(): void {
		$this->assertTrue( agend_apps_auth_error_is_email_conflict( $this->gatewayError( 409, 'EMAIL_ALREADY_REGISTERED' ) ) );
	}

	#[Test]
	public function should_classify_a_400_contact_already_linked_as_an_email_conflict(): void {
		$this->assertTrue( agend_apps_auth_error_is_email_conflict( $this->gatewayError( 400, 'BAD_REQUEST', 'CONTACT_ALREADY_LINKED' ) ) );
	}

	#[Test]
	public function should_not_classify_a_400_with_another_detail_as_an_email_conflict(): void {
		$this->assertFalse( agend_apps_auth_error_is_email_conflict( $this->gatewayError( 400, 'BAD_REQUEST', 'SELF_REGISTRATION_DISABLED' ) ) );
		$this->assertFalse( agend_apps_auth_error_is_email_conflict( $this->gatewayError( 400, 'VALIDATION_ERROR' ) ) );
	}

	#[Test]
	public function should_not_classify_a_409_with_another_code_as_an_email_conflict(): void {
		$this->assertFalse( agend_apps_auth_error_is_email_conflict( $this->gatewayError( 409, 'CONTACT_ALREADY_LINKED' ) ) );
	}

	#[Test]
	public function should_not_classify_a_transport_error_as_an_email_conflict(): void {
		$this->assertFalse( agend_apps_auth_error_is_email_conflict( new WP_Error( 'http_request_failed', 'timeout' ) ) );
	}

	#[Test]
	public function should_classify_a_401_as_invalid_credentials_regardless_of_code(): void {
		$this->assertTrue( agend_apps_auth_error_is_invalid_credentials( $this->gatewayError( 401, 'INVALID_CREDENTIALS' ) ) );
		$this->assertTrue( agend_apps_auth_error_is_invalid_credentials( $this->gatewayError( 401 ) ) );
		$this->assertFalse( agend_apps_auth_error_is_invalid_credentials( $this->gatewayError( 502 ) ) );
	}

	#[Test]
	public function should_unwrap_the_data_envelope_and_keep_a_complete_session(): void {
		$parsed = agend_apps_auth_response_session(
			array(
				'data' => array(
					'user'    => array( 'id' => 'u1' ),
					'session' => array(
						'access_token'  => 'a',
						'refresh_token' => 'r',
						'expires_at'    => 100,
					),
				),
			)
		);

		$this->assertSame( 'u1', $parsed['data']['user']['id'] );
		$this->assertSame( 'a', $parsed['session']['access_token'] );
	}

	#[Test]
	public function should_return_an_empty_session_when_the_refresh_token_is_missing(): void {
		$parsed = agend_apps_auth_response_session(
			array( 'session' => array( 'access_token' => 'a' ) )
		);

		$this->assertSame( array(), $parsed['session'] );
	}

	#[Test]
	public function should_return_an_empty_session_when_the_gateway_withheld_it_pending_verification(): void {
		$parsed = agend_apps_auth_response_session(
			array(
				'data' => array(
					'session'                    => null,
					'pending_email_confirmation' => true,
				),
			)
		);

		$this->assertSame( array(), $parsed['session'] );
		$this->assertTrue( $parsed['data']['pending_email_confirmation'] );
	}

	#[Test]
	public function should_omit_the_contact_block_when_no_name_is_supplied(): void {
		$this->assertSame(
			array(
				'email'    => 'a@b.co',
				'password' => 'pw',
			),
			agend_apps_provision_register_payload( 'a@b.co', 'pw' )
		);
	}

	#[Test]
	public function should_include_only_the_supplied_name_parts_in_the_contact_block(): void {
		$payload = agend_apps_provision_register_payload( 'a@b.co', 'pw', 'Ada', '' );

		$this->assertSame( array( 'first_name' => 'Ada' ), $payload['contact'] );
	}

	#[Test]
	public function should_flag_an_identity_conflict_and_write_nothing_else_when_the_email_is_taken(): void {
		agend_apps_provision_record_outcome( 7, AGEND_APPS_PROVISION_CONFLICT );

		$this->assertSame( array( AGEND_APPS_IDENTITY_CONFLICT_META => '1' ), $GLOBALS['agend_test_user_meta'][7] );
	}

	#[Test]
	public function should_write_no_user_meta_when_provisioning_errors(): void {
		agend_apps_provision_record_outcome( 7, AGEND_APPS_PROVISION_ERROR );
		agend_apps_provision_record_outcome( 7, AGEND_APPS_PROVISION_PENDING );

		$this->assertArrayNotHasKey( 7, $GLOBALS['agend_test_user_meta'] );
	}

	#[Test]
	public function should_suppress_provisioning_while_the_plugin_creates_its_own_user(): void {
		$this->assertFalse( Agend_Apps_Member_Provisioning::is_suppressed() );

		Agend_Apps_Member_Provisioning::suppress();
		Agend_Apps_Member_Provisioning::suppress();
		$this->assertTrue( Agend_Apps_Member_Provisioning::is_suppressed() );

		Agend_Apps_Member_Provisioning::resume();
		$this->assertTrue( Agend_Apps_Member_Provisioning::is_suppressed() );

		Agend_Apps_Member_Provisioning::resume();
		$this->assertFalse( Agend_Apps_Member_Provisioning::is_suppressed() );

		Agend_Apps_Member_Provisioning::resume();
		$this->assertFalse( Agend_Apps_Member_Provisioning::is_suppressed() );
	}

	#[Test]
	public function should_skip_provisioning_when_no_user_or_credentials_are_available(): void {
		$this->assertSame( AGEND_APPS_PROVISION_SKIPPED, agend_apps_provision_dashboard_account( 0, 'a@b.co', 'pw' )['outcome'] );
		$this->assertSame( AGEND_APPS_PROVISION_SKIPPED, agend_apps_provision_dashboard_account( 3, '', 'pw' )['outcome'] );
		$this->assertSame( AGEND_APPS_PROVISION_SKIPPED, agend_apps_provision_dashboard_account( 3, 'a@b.co', '' )['outcome'] );
	}
}
