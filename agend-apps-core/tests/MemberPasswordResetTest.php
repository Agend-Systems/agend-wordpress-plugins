<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use Agend_Test_WP;
use PHPUnit\Framework\Attributes\Test;
use WP_User;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-member-session.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-token-worker.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/auth.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/member-provisioning.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/wp-login-bridge.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/rest/class-agend-apps-rest-controller.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/rest/auth-routes.php';

/**
 * A WordPress password reset also requests the Agend reset email, because the
 * login bridge checks sign-ins against the gateway password, which a
 * WordPress-only reset never changes. The requested-email record is static
 * for the request, so each test uses its own address.
 */
final class MemberPasswordResetTest extends TestCase {

	private function user( int $id, string $login, string $email ): WP_User {
		$user             = new WP_User( $id );
		$user->user_login = $login;
		$user->user_email = $email;
		$GLOBALS['agend_test_users'][] = $user;

		return $user;
	}

	/** @return array<int, array<string, mixed>> Recorded gateway requests to /auth/forgot-password. */
	private function forgotRequests(): array {
		return array_values(
			array_filter(
				Agend_Test_WP::$requests,
				static fn ( array $r ): bool => str_contains( $r['url'], '/auth/forgot-password' )
			)
		);
	}

	#[Test]
	public function should_request_the_agend_reset_when_wordpress_starts_a_reset(): void {
		$this->user( 301, 'invitee', 'Invitee@Example.test' );
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'ok' => true ) ) );

		agend_apps_member_reset_on_retrieve_password( 'invitee' );

		$requests = $this->forgotRequests();
		$this->assertCount( 1, $requests );
		$this->assertSame( array( 'email' => 'invitee@example.test' ), json_decode( (string) $requests[0]['body'], true ) );
		$this->assertContains( 'invitee@example.test', agend_apps_member_reset_requested() );
	}

	#[Test]
	public function should_land_the_agend_reset_on_the_configured_reset_page(): void {
		$this->user( 302, 'paged', 'paged@example.test' );
		update_option( 'agend_apps_member_reset_url', 'https://site.test/login/' );
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'ok' => true ) ) );

		agend_apps_member_reset_on_retrieve_password( 'paged' );

		$body = json_decode( (string) $this->forgotRequests()[0]['body'], true );
		$this->assertSame( 'https://site.test/login/', $body['redirect_to'] );
	}

	#[Test]
	public function should_not_request_the_agend_reset_when_the_bridge_is_off_for_the_email(): void {
		$this->user( 303, 'localadmin', 'localadmin@example.test' );
		Agend_Test_WP::set_filter( 'agend_apps_wp_login_bridge_enabled', false );

		agend_apps_member_reset_on_retrieve_password( 'localadmin' );

		$this->assertCount( 0, $this->forgotRequests() );
		$this->assertNotContains( 'localadmin@example.test', agend_apps_member_reset_requested() );
	}

	#[Test]
	public function should_do_nothing_for_an_unknown_login(): void {
		agend_apps_member_reset_on_retrieve_password( 'nobody' );

		$this->assertCount( 0, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_not_record_the_reset_when_the_gateway_fails(): void {
		$this->user( 304, 'outage', 'outage@example.test' );
		Agend_Test_WP::queue_response( 503, array( 'error' => array( 'code' => 'UNAVAILABLE' ) ) );

		agend_apps_member_reset_on_retrieve_password( 'outage' );

		$this->assertCount( 1, $this->forgotRequests() );
		$this->assertNotContains( 'outage@example.test', agend_apps_member_reset_requested() );
	}

	#[Test]
	public function should_stop_at_the_forgot_password_throttle(): void {
		$this->user( 305, 'busy', 'busy@example.test' );
		Agend_Test_WP::set_filter(
			'agend_apps_auth_forgot_password_limits',
			array(
				'per_ip'    => 10,
				'per_email' => 0,
			)
		);

		agend_apps_member_reset_on_retrieve_password( 'busy' );

		$this->assertCount( 0, $this->forgotRequests() );
	}

	#[Test]
	public function should_stop_the_wordpress_reset_email_only_after_the_agend_reset_was_requested(): void {
		$sent   = $this->user( 306, 'noted', 'noted@example.test' );
		$unsent = $this->user( 307, 'plain', 'plain@example.test' );
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'ok' => true ) ) );
		agend_apps_member_reset_on_retrieve_password( 'noted' );

		$this->assertFalse( agend_apps_member_skip_wordpress_reset_email( true, 'noted', $sent ) );
		$this->assertTrue( agend_apps_member_skip_wordpress_reset_email( true, 'plain', $unsent ) );
	}

	#[Test]
	public function should_send_the_agend_reset_in_place_of_the_wordpress_invite(): void {
		$invited = $this->user( 308, 'invited', 'invited@example.test' );
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'ok' => true ) ) );

		$this->assertFalse( agend_apps_member_skip_wordpress_invite( true, $invited ) );
		$this->assertCount( 1, $this->forgotRequests() );
	}

	#[Test]
	public function should_keep_the_wordpress_invite_when_the_agend_reset_fails(): void {
		$invited = $this->user( 309, 'stranded', 'stranded@example.test' );
		Agend_Test_WP::queue_response( 503, array( 'error' => array( 'code' => 'UNAVAILABLE' ) ) );

		$this->assertTrue( agend_apps_member_skip_wordpress_invite( true, $invited ) );
	}

	#[Test]
	public function should_keep_the_wordpress_invite_when_the_bridge_is_off_for_the_email(): void {
		$invited = $this->user( 310, 'wpadmin', 'wpadmin@example.test' );
		Agend_Test_WP::set_filter( 'agend_apps_wp_login_bridge_enabled', false );

		$this->assertTrue( agend_apps_member_skip_wordpress_invite( true, $invited ) );
		$this->assertCount( 0, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_ask_the_gateway_only_once_per_email_in_a_request(): void {
		$user = $this->user( 311, 'twice', 'twice@example.test' );
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'ok' => true ) ) );

		$this->assertTrue( agend_apps_member_request_agend_reset( $user ) );
		$this->assertTrue( agend_apps_member_request_agend_reset( $user ) );
		$this->assertCount( 1, $this->forgotRequests() );
	}
}
