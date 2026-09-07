<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use Agend_Apps_Admin;
use PHPUnit\Framework\Attributes\Test;
use WP_User;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/member-provisioning.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/auth.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-member-session.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/admin/class-agend-apps-admin.php';

/**
 * The "Agend account" status block on the WordPress user profile
 * (SPEC-CORE-20260907-wordpress-email-verification-handling US-4.4): a
 * pending user shows a fourth state, ahead of "Linked", with a resend
 * control.
 */
final class AdminProfileTest extends TestCase {

	#[Test]
	public function should_render_the_pending_verification_state_and_a_resend_control_when_the_meta_is_set(): void {
		$user             = new WP_User( 42 );
		$user->user_email = 'pending@example.test';

		update_user_meta( 42, AGEND_APPS_VERIFICATION_PENDING_META, '1' );

		$admin = new Agend_Apps_Admin();

		ob_start();
		$admin->render_user_agend_account( $user );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Pending email verification', $html );
		$this->assertStringContainsString( 'Resend verification email', $html );
	}

	#[Test]
	public function should_not_render_a_resend_control_when_the_meta_is_not_set(): void {
		$user             = new WP_User( 43 );
		$user->user_email = 'not-pending@example.test';

		$admin = new Agend_Apps_Admin();

		ob_start();
		$admin->render_user_agend_account( $user );
		$html = ob_get_clean();

		$this->assertStringNotContainsString( 'Pending email verification', $html );
		$this->assertStringNotContainsString( 'Resend verification email', $html );
		$this->assertStringContainsString( 'Not linked', $html );
	}

	#[Test]
	public function should_show_not_linked_when_the_stored_session_is_dead(): void {
		$user             = new WP_User( 44 );
		$user->user_email = 'stale@example.test';

		update_user_meta(
			44,
			'_agend_apps_member_session',
			array(
				'access_token'  => 'a',
				'refresh_token' => 'r',
				'expires_at'    => time() - 3600,
				'stored_at'     => time() - 7200,
			)
		);

		$admin = new Agend_Apps_Admin();

		ob_start();
		$admin->render_user_agend_account( $user );
		$html = ob_get_clean();

		// The stored refresh fails (no gateway to answer), so the session is
		// treated as dead rather than "Linked" (US-4.4 AC3).
		$this->assertStringContainsString( 'Not linked', $html );
		$this->assertStringNotContainsString( '>Linked<', $html );
	}
}
