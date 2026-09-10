<?php
/**
 * Test fixture: declares both classes `agend-saml-idp` registers (see
 * `WP_SAML_IDP_Service_Provider`/`WP_SAML_IDP_Endpoints` in
 * agend-embed/includes/class-agend-embed-sso-drivers.php:126-134), simulating
 * the plugin being active for SsoLinkMechanismTest. Each is guarded
 * individually so this file is safe to require after
 * saml-idp-partial-stub.php has already declared one of them. Class
 * declarations cannot be nested inside a test method (they would be nested
 * inside the test CLASS), so this lives in its own plain file.
 *
 * @package Agend\Tests
 */

declare( strict_types=1 );

if ( ! class_exists( 'WP_SAML_IDP_Service_Provider' ) ) {
	class WP_SAML_IDP_Service_Provider {}
}

if ( ! class_exists( 'WP_SAML_IDP_Endpoints' ) ) {
	class WP_SAML_IDP_Endpoints {}
}
