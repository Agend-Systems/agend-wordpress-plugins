<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use Agend_Apps_Admin;
use Agend_Apps_Settings;
use PHPUnit\Framework\Attributes\Test;

// Agend_Apps_Settings is a bootstrap-time test double (tests/doubles.php),
// declared once for the whole suite -- requiring the real
// includes/class-agend-apps-settings.php here would redeclare the class and
// fatal. The double's get_member_auth_mode()/credential_login_enabled() were
// extended alongside the real class to read the same option, so this test
// exercises the same decision the real class makes.
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/member-provisioning.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/auth.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-member-session.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/admin/class-agend-apps-admin.php';

/**
 * The site-wide member sign-in mode setting (SPEC-CORE-20260907 US-4.1): the
 * closed vocabulary the option resolves to, and the sanitiser that enforces
 * it on save. The bootstrap conditional in agend-apps-core.php that skips
 * requiring the credential login files in `sso` mode is not exercised here:
 * it runs at `plugins_loaded`, before any test file is loaded, and cannot be
 * driven from a unit test without reloading the whole plugin.
 */
final class MemberAuthModeTest extends TestCase {

	#[Test]
	public function should_return_credentials_when_the_option_is_missing(): void {
		$this->assertSame( 'credentials', Agend_Apps_Settings::get_member_auth_mode() );
	}

	#[Test]
	public function should_return_credentials_when_the_option_is_set_to_credentials(): void {
		update_option( 'agend_apps_member_auth_mode', 'credentials' );

		$this->assertSame( 'credentials', Agend_Apps_Settings::get_member_auth_mode() );
	}

	#[Test]
	public function should_return_sso_when_the_option_is_set_to_sso(): void {
		update_option( 'agend_apps_member_auth_mode', 'sso' );

		$this->assertSame( 'sso', Agend_Apps_Settings::get_member_auth_mode() );
	}

	#[Test]
	public function should_return_credentials_when_the_option_holds_an_unrecognised_value(): void {
		update_option( 'agend_apps_member_auth_mode', 'sso ' );

		$this->assertSame( 'credentials', Agend_Apps_Settings::get_member_auth_mode() );
	}

	#[Test]
	public function should_report_credential_login_enabled_when_the_mode_is_credentials(): void {
		update_option( 'agend_apps_member_auth_mode', 'credentials' );

		$this->assertTrue( Agend_Apps_Settings::credential_login_enabled() );
	}

	#[Test]
	public function should_report_credential_login_disabled_when_the_mode_is_sso(): void {
		update_option( 'agend_apps_member_auth_mode', 'sso' );

		$this->assertFalse( Agend_Apps_Settings::credential_login_enabled() );
	}

	#[Test]
	public function should_sanitise_sso_to_sso(): void {
		$admin = new Agend_Apps_Admin();

		$this->assertSame( 'sso', $admin->sanitize_member_auth_mode( 'sso' ) );
	}

	#[Test]
	public function should_sanitise_any_other_value_to_credentials(): void {
		$admin = new Agend_Apps_Admin();

		$this->assertSame( 'credentials', $admin->sanitize_member_auth_mode( 'credentials' ) );
		$this->assertSame( 'credentials', $admin->sanitize_member_auth_mode( '' ) );
		$this->assertSame( 'credentials', $admin->sanitize_member_auth_mode( 'SSO' ) );
		$this->assertSame( 'credentials', $admin->sanitize_member_auth_mode( 'garbage' ) );
	}
}
