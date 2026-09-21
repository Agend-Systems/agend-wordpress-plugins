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
 * `get_service_providers()`/`get_sp_by_entity_id()` are declared identically
 * here and in saml-idp-stub.php: `class_exists()` guards mean only the first
 * of the two fixtures to run actually declares the class, and PHPUnit's
 * alphabetical file order runs this one (via SsoLinkMechanismTest) first, so
 * this copy is the one WpIdpSamlLinkTest's and IdentityLinkRoutesTest's
 * SP-resolution/readiness tests actually get.
 *
 * @package Agend\Tests
 */

declare( strict_types=1 );

if ( ! class_exists( 'WP_SAML_IDP_Service_Provider' ) ) {
	class WP_SAML_IDP_Service_Provider {
		public static function get_service_providers() {
			return get_option( 'wp_saml_idp_service_providers', array() );
		}

		/**
		 * Mirrors the real plugin's lookup: the first registered SP array whose
		 * `entityId` matches, or `false` when none does.
		 *
		 * @param string $entity_id Entity id to look up.
		 * @return array|false
		 */
		public static function get_sp_by_entity_id( string $entity_id ) {
			foreach ( (array) self::get_service_providers() as $provider ) {
				if ( is_array( $provider ) && ( $provider['entityId'] ?? null ) === $entity_id ) {
					return $provider;
				}
			}

			return false;
		}
	}
}
