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
 * @package Agend\Tests
 */

declare( strict_types=1 );

if ( ! class_exists( 'WP_SAML_IDP_Service_Provider' ) ) {
	class WP_SAML_IDP_Service_Provider {}
}
