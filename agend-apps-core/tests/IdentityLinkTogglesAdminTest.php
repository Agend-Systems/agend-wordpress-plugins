<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use Agend_Apps_Identity_Admin;
use Agend_Test_WP;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/admin/class-agend-apps-identity-admin.php';

/**
 * The two link toggles on Settings > Identity and SSO, "Link members as they
 * sign in" and "Link members before directory pages load": registered beside
 * the existing background link toggle, rendered checked when never saved
 * (both default on), and unchecked when an admin has switched them off.
 */
final class IdentityLinkTogglesAdminTest extends TestCase {

	private const SECTION    = 'agend_apps_identity_sso_section';
	private const BACKGROUND = 'agend_apps_sso_background_link';
	private const SIGNIN     = 'agend_apps_sso_signin_link';
	private const PRERENDER  = 'agend_apps_sso_prerender_link';

	#[Test]
	public function should_register_both_toggles_on_the_identity_page_beside_the_background_link(): void {
		( new Agend_Apps_Identity_Admin() )->register_settings();

		$fields = array_keys( Agend_Test_WP::$settings_sections[ Agend_Apps_Identity_Admin::PAGE_SLUG ][ self::SECTION ]['fields'] );

		$this->assertContains( self::BACKGROUND, $fields );
		$this->assertContains( self::SIGNIN, $fields );
		$this->assertContains( self::PRERENDER, $fields );
		$this->assertSame(
			array( self::BACKGROUND, self::SIGNIN, self::PRERENDER ),
			array_values( array_intersect( $fields, array( self::BACKGROUND, self::SIGNIN, self::PRERENDER ) ) ),
			'in that order, in the same section'
		);
	}

	#[Test]
	public function should_render_both_toggles_checked_when_never_saved(): void {
		$admin = new Agend_Apps_Identity_Admin();

		delete_option( self::SIGNIN );
		delete_option( self::PRERENDER );

		ob_start();
		$admin->render_signin_link_field();
		$admin->render_prerender_link_field();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="agend_apps_sso_signin_link" value="1" checked', $html );
		$this->assertStringContainsString( 'name="agend_apps_sso_prerender_link" value="1" checked', $html );
		$this->assertStringContainsString( 'Link members as they sign in', $html );
		$this->assertStringContainsString( 'Link members before directory pages load', $html );
	}

	#[Test]
	public function should_render_each_toggle_unchecked_when_switched_off(): void {
		$admin = new Agend_Apps_Identity_Admin();

		update_option( self::SIGNIN, false );
		update_option( self::PRERENDER, true );

		ob_start();
		$admin->render_signin_link_field();
		$signin = (string) ob_get_clean();

		ob_start();
		$admin->render_prerender_link_field();
		$prerender = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'checked', $signin );
		$this->assertStringContainsString( 'checked', $prerender );

		update_option( self::SIGNIN, true );
		update_option( self::PRERENDER, false );

		ob_start();
		$admin->render_prerender_link_field();
		$prerender = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'checked', $prerender );
	}

	#[Test]
	public function should_say_in_each_description_that_it_needs_the_background_link(): void {
		$admin = new Agend_Apps_Identity_Admin();

		ob_start();
		$admin->render_signin_link_field();
		$admin->render_prerender_link_field();
		$html = (string) ob_get_clean();

		$this->assertSame( 2, substr_count( $html, 'Only applies when the background link above is on' ) );
	}
}
