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

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-secret-store.php';
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
	public function should_show_linked_without_refreshing_the_members_session_when_it_is_stale(): void {
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
		$stored = get_user_meta( 44, '_agend_apps_member_session', true );

		$admin = new Agend_Apps_Admin();

		ob_start();
		$admin->render_user_agend_account( $user );
		$html = ob_get_clean();

		// Viewing a profile never refreshes, and so rotates, another user's
		// session from the admin's request. A dead session is cleared by the
		// member's own next request instead.
		$this->assertStringContainsString( '>Linked<', $html );
		$this->assertSame( array(), \Agend_Test_WP::$requests );
		$this->assertSame( $stored, get_user_meta( 44, '_agend_apps_member_session', true ) );
	}

	#[Test]
	public function should_show_not_linked_when_the_stored_session_cannot_be_decrypted(): void {
		$user             = new WP_User( 45 );
		$user->user_email = 'rotated-salts@example.test';

		update_user_meta( 45, '_agend_apps_member_session', base64_encode( random_bytes( 64 ) ) );

		$admin = new Agend_Apps_Admin();

		ob_start();
		$admin->render_user_agend_account( $user );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Not linked', $html );
		$this->assertSame( array(), \Agend_Test_WP::$requests );
	}
}
