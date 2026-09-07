<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use WP_Error;
use WP_User;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/auth.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/member-provisioning.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/wp-login-bridge.php';

/**
 * The priority-30 refusal behind the login bridge: once the bridge has learned
 * (from the gateway's 409) that an email already holds a dashboard account,
 * the WordPress-password login WordPress resolved at priority 20 is replaced
 * by the generic credentials error, for that email only.
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
				\Agend_Test_WP::$requests,
				static fn( array $request ): bool => str_contains( $request['url'], $path )
			)
		);
	}

	#[Test]
	public function should_make_no_register_call_when_the_wordpress_password_is_wrong(): void {
		$existing                       = new WP_User( 21 );
		$existing->user_email           = 'existing@example.test';
		$existing->user_pass            = 'correct-password';
		$GLOBALS['agend_test_users'][]  = $existing;

		$result = agend_apps_wp_login_register_existing_user( 'existing@example.test', 'wrong-password' );

		$this->assertNull( $result );
		$this->assertSame( 0, $this->requestCount( '/auth/register' ) );
	}

	#[Test]
	public function should_call_register_once_when_the_wordpress_password_is_correct(): void {
		$existing                       = new WP_User( 22 );
		$existing->user_email           = 'existing@example.test';
		$existing->user_pass            = 'correct-password';
		$GLOBALS['agend_test_users'][]  = $existing;

		$result = agend_apps_wp_login_register_existing_user( 'existing@example.test', 'correct-password' );

		$this->assertIsArray( $result );
		$this->assertSame( 1, $this->requestCount( '/auth/register' ) );
	}
}
