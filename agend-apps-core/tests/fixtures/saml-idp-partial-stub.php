<?php
/**
 * Test fixture: declares only ONE of the two classes `agend-saml-idp`
 * registers (see `WP_SAML_IDP_Service_Provider`/`WP_SAML_IDP_Endpoints` in
 * agend-embed/includes/class-agend-embed-sso-drivers.php:126-134), so
 * SsoLinkMechanismTest can prove a partial declaration does not count as the
 * plugin being present. Class declarations cannot be nested inside a test
 * method (they would be nested inside the test CLASS), so this lives in its
 * own plain file, required only from the test that needs it.
 *
 * `get_service_providers()` is declared identically here and in
 * saml-idp-stub.php: `class_exists()` guards mean only the first of the two
 * fixtures to run actually declares the class, and PHPUnit's alphabetical
 * file order runs this one (via SsoLinkMechanismTest) first, so this copy is
 * the one WpIdpSamlLinkTest's SP-resolution tests actually get.
 *
 * @package Agend\Tests
 */

declare( strict_types=1 );

if ( ! class_exists( 'WP_SAML_IDP_Service_Provider' ) ) {
	class WP_SAML_IDP_Service_Provider {
		public static function get_service_providers() {
			return get_option( 'wp_saml_idp_service_providers', array() );
		}
	}
}
