<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use Agend_Apps_Admin;
use Agend_Apps_Member_Session;
use Agend_Test_WP;
use PHPUnit\Framework\Attributes\Test;
use WP_User;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-secret-store.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/member-provisioning.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/auth.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-member-session.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/admin/class-agend-apps-admin.php';

/**
 * Every WordPress event that ends a member's standing on the site also ends
 * their Agend session: revoked at the gateway with the member's own token,
 * then deleted locally whatever the gateway answers.
 */
final class MemberSessionLifecycleTest extends TestCase {

	private const USER_ID = 42;

	protected function setUp(): void {
		parent::setUp();

		Agend_Test_WP::$filters['agend_apps_member_session_sleeper'] = static fn() => static function (): void {};

		new Agend_Apps_Member_Session();
	}

	protected function tearDown(): void {
		unset( $_POST['agend_apps_api_key_clear'] );
		parent::tearDown();
	}

	#[Test]
	public function should_revoke_with_the_members_token_and_delete_when_they_sign_out_of_wordpress(): void {
		$this->storeSession( self::USER_ID, 'access-1', 3600 );
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'status' => 'ok' ) ) );

		// wp_logout fires after the current user is reset to 0.
		do_action( 'wp_logout', self::USER_ID );

		$logouts = $this->requestsTo( '/auth/logout' );
		$this->assertCount( 1, $logouts );
		$this->assertSame( 'Bearer access-1', $logouts[0]['headers']['Authorization'] ?? null );
		$this->assertFalse( Agend_Apps_Member_Session::has_session( self::USER_ID ) );
		$this->assertSame( '', get_user_meta( self::USER_ID, Agend_Apps_Member_Session::META_KEY, true ) );
	}

	#[Test]
	public function should_refresh_first_and_revoke_with_the_new_token_when_the_stored_one_is_stale(): void {
		$this->storeSession( self::USER_ID, 'stale-access', -600 );
		Agend_Test_WP::queue_response( 200, $this->sessionBody( 'fresh-access', 'refresh-2' ) );
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'status' => 'ok' ) ) );

		do_action( 'wp_logout', self::USER_ID );

		$this->assertCount( 1, $this->requestsTo( '/auth/refresh' ) );
		$this->assertArrayNotHasKey( 'Authorization', $this->requestsTo( '/auth/refresh' )[0]['headers'] );
		$this->assertSame( 'Bearer fresh-access', $this->requestsTo( '/auth/logout' )[0]['headers']['Authorization'] ?? null );
		$this->assertFalse( Agend_Apps_Member_Session::has_session( self::USER_ID ) );
	}

	#[Test]
	public function should_delete_locally_without_a_logout_call_when_the_stale_session_is_already_dead(): void {
		$this->storeSession( self::USER_ID, 'stale-access', -600 );
		Agend_Test_WP::queue_response( 401, array( 'error' => array( 'code' => 'UNAUTHORIZED', 'message' => 'refresh_token is invalid or has expired' ) ) );

		do_action( 'wp_logout', self::USER_ID );

		$this->assertCount( 0, $this->requestsTo( '/auth/logout' ) );
		$this->assertFalse( Agend_Apps_Member_Session::has_session( self::USER_ID ) );
	}

	#[Test]
	public function should_still_delete_locally_when_the_gateway_logout_fails(): void {
		$this->storeSession( self::USER_ID, 'access-1', 3600 );
		Agend_Test_WP::queue_response( 500, array( 'error' => array( 'code' => 'INTERNAL_ERROR', 'message' => 'boom' ) ) );

		do_action( 'wp_logout', self::USER_ID );

		$this->assertCount( 1, $this->requestsTo( '/auth/logout' ) );
		$this->assertFalse( Agend_Apps_Member_Session::has_session( self::USER_ID ) );
	}

	#[Test]
	public function should_still_delete_locally_when_the_gateway_cannot_be_reached(): void {
		$this->storeSession( self::USER_ID, 'access-1', 3600 );
		Agend_Test_WP::queue_transport_error( 'http_request_failed', 'cURL error 6: Could not resolve host' );

		do_action( 'wp_logout', self::USER_ID );

		$this->assertFalse( Agend_Apps_Member_Session::has_session( self::USER_ID ) );
	}

	#[Test]
	public function should_end_the_session_with_the_members_token_when_an_admin_deletes_the_user(): void {
		$GLOBALS['agend_test_current_user_id'] = 1;
		$this->storeSession( self::USER_ID, 'member-access', 3600 );

		do_action( 'delete_user', self::USER_ID, null, new WP_User( self::USER_ID ) );

		$this->assertSame( 'Bearer member-access', $this->requestsTo( '/auth/logout' )[0]['headers']['Authorization'] ?? null );
		$this->assertFalse( Agend_Apps_Member_Session::has_session( self::USER_ID ) );
	}

	#[Test]
	public function should_end_the_session_when_the_password_is_reset(): void {
		$this->storeSession( self::USER_ID, 'access-1', 3600 );

		do_action( 'after_password_reset', new WP_User( self::USER_ID ), 'new-password' );

		$this->assertCount( 1, $this->requestsTo( '/auth/logout' ) );
		$this->assertFalse( Agend_Apps_Member_Session::has_session( self::USER_ID ) );
	}

	#[Test]
	public function should_end_the_session_when_a_password_is_set_by_any_code_path(): void {
		$this->storeSession( self::USER_ID, 'access-1', 3600 );

		do_action( 'wp_set_password', 'new-password', self::USER_ID, new WP_User( self::USER_ID ) );

		$this->assertFalse( Agend_Apps_Member_Session::has_session( self::USER_ID ) );
	}

	#[Test]
	public function should_end_the_session_when_a_profile_update_changes_the_password(): void {
		$this->storeSession( self::USER_ID, 'access-1', 3600 );
		$GLOBALS['agend_test_users'][] = $this->userWithPassword( 'new-hash' );

		do_action( 'profile_update', self::USER_ID, $this->userWithPassword( 'old-hash' ) );

		$this->assertFalse( Agend_Apps_Member_Session::has_session( self::USER_ID ) );
	}

	#[Test]
	public function should_keep_the_session_when_a_profile_update_leaves_the_password_alone(): void {
		$this->storeSession( self::USER_ID, 'access-1', 3600 );
		$GLOBALS['agend_test_users'][] = $this->userWithPassword( 'same-hash' );

		do_action( 'profile_update', self::USER_ID, $this->userWithPassword( 'same-hash' ) );

		$this->assertTrue( Agend_Apps_Member_Session::has_session( self::USER_ID ) );
		$this->assertSame( array(), Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_do_nothing_when_a_user_without_a_session_signs_out(): void {
		do_action( 'wp_logout', self::USER_ID );

		$this->assertSame( array(), Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_revoke_and_delete_every_session_when_the_site_api_key_is_cleared(): void {
		$this->storeSession( 41, 'access-41', 3600 );
		$this->storeSession( self::USER_ID, 'access-42', 3600 );
		update_user_meta( 43, 'unrelated_meta', 'kept' );
		$_POST['agend_apps_api_key_clear'] = '1';

		( new Agend_Apps_Admin() )->sanitize_api_key_setting( '' );

		$bearers = array_map(
			static fn( array $request ): string => (string) ( $request['headers']['Authorization'] ?? '' ),
			$this->requestsTo( '/auth/logout' )
		);
		sort( $bearers );
		$this->assertSame( array( 'Bearer access-41', 'Bearer access-42' ), $bearers );
		$this->assertFalse( Agend_Apps_Member_Session::has_session( 41 ) );
		$this->assertFalse( Agend_Apps_Member_Session::has_session( self::USER_ID ) );
		$this->assertSame( 'kept', get_user_meta( 43, 'unrelated_meta', true ) );
	}

	#[Test]
	public function should_keep_member_sessions_when_the_api_key_field_is_saved_without_clearing(): void {
		$this->storeSession( self::USER_ID, 'access-42', 3600 );

		( new Agend_Apps_Admin() )->sanitize_api_key_setting( '' );

		$this->assertTrue( Agend_Apps_Member_Session::has_session( self::USER_ID ) );
		$this->assertSame( array(), Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_revoke_and_delete_every_session_when_the_site_leaves_credentials_mode(): void {
		foreach ( array( 'sso', 'wordpress' ) as $new_mode ) {
			Agend_Test_WP::$requests = array();
			$this->storeSession( 41, 'access-41', 3600 );
			$this->storeSession( self::USER_ID, 'access-42', 3600 );

			do_action( 'update_option_agend_apps_member_auth_mode', 'credentials', $new_mode, 'agend_apps_member_auth_mode' );

			$this->assertCount( 2, $this->requestsTo( '/auth/logout' ), $new_mode );
			$this->assertFalse( Agend_Apps_Member_Session::has_session( 41 ), $new_mode );
			$this->assertFalse( Agend_Apps_Member_Session::has_session( self::USER_ID ), $new_mode );
		}
	}

	#[Test]
	public function should_end_every_session_when_a_never_saved_mode_is_first_saved_as_sso(): void {
		$this->storeSession( self::USER_ID, 'access-42', 3600 );

		do_action( 'add_option_agend_apps_member_auth_mode', 'agend_apps_member_auth_mode', 'sso' );

		$this->assertFalse( Agend_Apps_Member_Session::has_session( self::USER_ID ) );
	}

	#[Test]
	public function should_keep_sessions_when_the_mode_is_saved_unchanged_or_into_credentials(): void {
		$this->storeSession( self::USER_ID, 'access-42', 3600 );

		do_action( 'update_option_agend_apps_member_auth_mode', 'credentials', 'credentials', 'agend_apps_member_auth_mode' );
		do_action( 'update_option_agend_apps_member_auth_mode', 'sso', 'wordpress', 'agend_apps_member_auth_mode' );
		do_action( 'update_option_agend_apps_member_auth_mode', 'sso', 'credentials', 'agend_apps_member_auth_mode' );
		do_action( 'add_option_agend_apps_member_auth_mode', 'agend_apps_member_auth_mode', 'credentials' );

		$this->assertTrue( Agend_Apps_Member_Session::has_session( self::USER_ID ) );
		$this->assertSame( array(), Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_still_delete_every_session_when_revocation_throws_during_a_mode_switch(): void {
		$this->storeSession( 41, 'access-41', 3600 );
		$this->storeSession( self::USER_ID, 'access-42', 3600 );
		Agend_Test_WP::$filters['agend_apps_api_path'] = static function () {
			throw new \RuntimeException( 'transport failure' );
		};

		do_action( 'update_option_agend_apps_member_auth_mode', 'credentials', 'sso', 'agend_apps_member_auth_mode' );

		$this->assertFalse( Agend_Apps_Member_Session::has_session( 41 ) );
		$this->assertFalse( Agend_Apps_Member_Session::has_session( self::USER_ID ) );
	}

	private function storeSession( int $user_id, string $access, int $expires_in ): void {
		Agend_Apps_Member_Session::store(
			$user_id,
			array(
				'access_token'  => $access,
				'refresh_token' => 'refresh-' . $user_id,
				'expires_at'    => time() + $expires_in,
			)
		);
	}

	private function userWithPassword( string $hash ): WP_User {
		$user            = new WP_User( self::USER_ID );
		$user->user_pass = $hash;

		return $user;
	}

	private function sessionBody( string $access, string $refresh ): array {
		return array(
			'data' => array(
				'session' => array(
					'access_token'  => $access,
					'refresh_token' => $refresh,
					'expires_at'    => time() + 3600,
				),
			),
		);
	}

	/** @return array<int, array{url: string, headers: array<string, string>, body: mixed}> */
	private function requestsTo( string $suffix ): array {
		return array_values(
			array_filter(
				Agend_Test_WP::$requests,
				static fn( array $request ): bool => str_ends_with( $request['url'], $suffix )
			)
		);
	}
}
