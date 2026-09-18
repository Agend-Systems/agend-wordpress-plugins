<?php
/**
 * Test fixture: declares both classes `agend-saml-idp` registers (see
 * `WP_SAML_IDP_Service_Provider`/`WP_SAML_IDP_Endpoints` in
 * agend-embed/includes/class-agend-embed-sso-drivers.php:126-134), simulating
 * the plugin being active for SsoLinkMechanismTest and WpIdpSamlLinkTest.
 * Each is guarded individually so this file is safe to require after
 * saml-idp-partial-stub.php has already declared one of them. Class
 * declarations cannot be nested inside a test method (they would be nested
 * inside the test CLASS), so this lives in its own plain file.
 *
 * `get_service_providers()` mirrors the real plugin's
 * `WP_SAML_IDP_Service_Provider::get_service_providers()` (reads
 * `wp_saml_idp_service_providers`), so WpIdpSamlLinkTest's SP-resolution
 * tests exercise the same registry shape agend_apps_saml_agend_sp_entity_id()
 * reads in production. Declared identically in saml-idp-partial-stub.php: as
 * this file's own docblock notes, PHPUnit's alphabetical file order runs
 * SsoLinkMechanismTest before WpIdpSamlLinkTest, so whichever fixture wins the
 * `class_exists()` race must carry this method too, or a later require here
 * is a silent no-op that leaves the class without it.
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
		 * `entityId` matches, or `false` when none does. Backs
		 * `agend_apps_saml_sp_ready()`'s registered/enabled checks in
		 * WpIdpSamlLinkTest and IdentityLinkRoutesTest.
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

if ( ! class_exists( 'WP_SAML_IDP_Endpoints' ) ) {
	class WP_SAML_IDP_Endpoints {}
}
