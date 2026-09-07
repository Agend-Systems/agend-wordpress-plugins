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
}
