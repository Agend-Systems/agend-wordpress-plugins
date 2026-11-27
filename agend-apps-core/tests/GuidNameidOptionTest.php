<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use Agend_Apps_Identity_Admin;
use Agend_Apps_Settings;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/identity.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-secret-store.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/connect-site.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/admin/class-agend-apps-identity-admin.php';

/**
 * The minted GUID as an agend-saml-idp NameID option
 * ({@see \agend_apps_saml_idp_nameid_options()}), and the Identity and SSO
 * settings page's note on which NameID the IdP must assert.
 */
final class GuidNameidOptionTest extends TestCase {

	private const IDP_OPTIONS = array(
		'user_email'   => 'Email Address',
		'user_login'   => 'Username',
		'ID'           => 'User ID',
		'display_name' => 'Display Name',
	);

	#[Test]
	public function should_add_the_guid_option_when_in_wordpress_mode_with_no_meta_key_configured(): void {
		update_option( 'agend_apps_member_auth_mode', Agend_Apps_Settings::MEMBER_AUTH_WORDPRESS );

		$options = \agend_apps_saml_idp_nameid_options( self::IDP_OPTIONS );

		$this->assertSame( 'Agend external id (GUID)', $options[ \AGEND_APPS_EXTERNAL_ID_META ] );
		$this->assertSame( self::IDP_OPTIONS, array_diff_key( $options, array( \AGEND_APPS_EXTERNAL_ID_META => true ) ) );
	}

	#[Test]
	public function should_offer_the_same_key_the_connect_mapping_asserts_when_the_guid_option_is_added(): void {
		update_option( 'agend_apps_member_auth_mode', Agend_Apps_Settings::MEMBER_AUTH_WORDPRESS );

		$options = \agend_apps_saml_idp_nameid_options( self::IDP_OPTIONS );

		$this->assertArrayHasKey( \agend_apps_connect_nameid_meta_key(), $options );
	}

	#[Test]
	public function should_leave_the_options_unchanged_when_a_meta_key_is_configured_in_wordpress_mode(): void {
		update_option( 'agend_apps_member_auth_mode', Agend_Apps_Settings::MEMBER_AUTH_WORDPRESS );
		update_option( 'agend_apps_external_id_meta_key', 'member_number' );

		$this->assertSame( self::IDP_OPTIONS, \agend_apps_saml_idp_nameid_options( self::IDP_OPTIONS ) );
	}

	#[Test]
	public function should_leave_the_options_unchanged_when_in_credentials_mode(): void {
		update_option( 'agend_apps_member_auth_mode', Agend_Apps_Settings::MEMBER_AUTH_CREDENTIALS );

		$this->assertSame( self::IDP_OPTIONS, \agend_apps_saml_idp_nameid_options( self::IDP_OPTIONS ) );
	}

	#[Test]
	public function should_leave_the_options_unchanged_when_in_sso_mode(): void {
		update_option( 'agend_apps_member_auth_mode', Agend_Apps_Settings::MEMBER_AUTH_SSO );

		$this->assertSame( self::IDP_OPTIONS, \agend_apps_saml_idp_nameid_options( self::IDP_OPTIONS ) );
	}

	#[Test]
	public function should_hook_the_option_to_the_idp_filter_when_the_plugin_boots(): void {
		$core = (string) file_get_contents( AGEND_TESTS_ROOT . '/agend-apps-core/agend-apps-core.php' );

		$this->assertStringContainsString( "add_filter( 'wp_saml_idp_nameid_options', 'agend_apps_saml_idp_nameid_options' );", $core );
		$this->assertLessThan(
			strpos( $core, "add_filter( 'wp_saml_idp_nameid_options'" ),
			strpos( $core, "require_once AGEND_APPS_CORE_DIR . 'includes/identity.php';" )
		);
	}

	#[Test]
	public function should_return_a_non_array_value_unchanged_when_another_filter_broke_the_options(): void {
		update_option( 'agend_apps_member_auth_mode', Agend_Apps_Settings::MEMBER_AUTH_WORDPRESS );

		$this->assertNull( \agend_apps_saml_idp_nameid_options( null ) );
	}

	#[Test]
	public function should_name_the_guid_key_and_the_option_to_choose_when_the_settings_field_renders_in_guid_mode(): void {
		update_option( 'agend_apps_member_auth_mode', Agend_Apps_Settings::MEMBER_AUTH_WORDPRESS );

		$html = $this->renderMetaKeyField();

		$this->assertStringContainsString( '<code>' . \AGEND_APPS_EXTERNAL_ID_META . '</code>', $html );
		$this->assertStringContainsString( 'Agend external id (GUID)', $html );
		$this->assertStringContainsString( 'saving that form changes the NameID', $html );
	}

	#[Test]
	public function should_name_the_configured_key_in_the_check_before_saving_when_the_settings_field_renders_with_a_meta_key(): void {
		update_option( 'agend_apps_member_auth_mode', Agend_Apps_Settings::MEMBER_AUTH_WORDPRESS );
		update_option( 'agend_apps_external_id_meta_key', 'member_number' );

		$html = $this->renderMetaKeyField();

		$this->assertSame( 2, substr_count( $html, '<code>member_number</code>' ) );
		$this->assertStringNotContainsString( 'Agend external id (GUID)', $html );
		$this->assertStringContainsString( 'saving that form changes the NameID', $html );
	}

	#[Test]
	public function should_not_mention_the_idp_nameid_when_the_settings_field_renders_outside_wordpress_mode(): void {
		update_option( 'agend_apps_member_auth_mode', Agend_Apps_Settings::MEMBER_AUTH_CREDENTIALS );

		$html = $this->renderMetaKeyField();

		$this->assertStringNotContainsString( 'NameID for the Agend service provider', $html );
		$this->assertStringNotContainsString( 'agend-saml-idp', $html );
	}

	private function renderMetaKeyField(): string {
		ob_start();
		( new Agend_Apps_Identity_Admin() )->render_external_id_meta_key_field();

		return (string) ob_get_clean();
	}
}
