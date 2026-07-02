<?php
/**
 * SSO kick-off URL resolution for the Agend embed.
 *
 * The embed asks the host page to start SSO because only a navigation
 * initiated by the host (same-site initiator, no cross-site redirect chain)
 * carries the WordPress SameSite=Lax login cookie into the IdP's SSO
 * endpoint. A navigation started by the cross-origin embed document is
 * stripped of it by the browser, so the member would see a login form
 * despite holding a live session on this site.
 *
 * Each driver resolves a kick-off URL TEMPLATE: an absolute URL on this site
 * that starts an IdP-initiated SSO flow, containing the literal placeholder
 * `%RETURN_URL%` where the (URL-encoded) post-login return URL belongs. The
 * placeholder is substituted in the browser from the embed's request, so the
 * member lands back on the exact embed surface that asked.
 *
 * @package Agend_Embed
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves the IdP-initiated SSO kick-off URL template for the active IdP.
 */
class Agend_Embed_SSO_Drivers {

	/**
	 * Placeholder the browser replaces with the URL-encoded return URL.
	 */
	const RETURN_URL_PLACEHOLDER = '%RETURN_URL%';

	/**
	 * Resolves the kick-off URL template.
	 *
	 * Resolution order:
	 *  1. An explicit `kickoff_url` shortcode attribute (must contain the
	 *     placeholder) — full manual control for unsupported IdPs.
	 *  2. The Agend SAML IdP plugin (`agend-saml-idp`).
	 *  3. The miniOrange "SAML IDP (Identity Provider)" plugin.
	 *  4. Whatever the `agend_embed_sso_kickoff_url` filter returns — the
	 *     extension point for any other IdP (see README for the contract).
	 *
	 * @param array $atts {
	 *     Shortcode attributes relevant to SSO.
	 *
	 *     @type string $sp          Optional. IdP-specific Service Provider
	 *                               selector (entity id for agend-saml-idp,
	 *                               SP name for miniOrange). Defaults to the
	 *                               sole registered SP where discoverable.
	 *     @type string $kickoff_url Optional. Explicit URL template override.
	 * }
	 * @return string|null Kick-off URL template, or null when no IdP-initiated
	 *                     SSO is available (the embed then falls back to its
	 *                     own in-frame sign-in flow).
	 */
	public static function resolve( $atts ) {
		$template = null;

		if ( ! empty( $atts['kickoff_url'] ) ) {
			$template = self::validate_template( $atts['kickoff_url'] );
		}

		if ( null === $template ) {
			$template = self::agend_saml_idp_template( $atts['sp'] ?? '' );
		}

		if ( null === $template ) {
			$template = self::miniorange_idp_template( $atts['sp'] ?? '' );
		}

		/**
		 * Filters the resolved SSO kick-off URL template for the Agend embed.
		 *
		 * Return an absolute URL on THIS site that starts an IdP-initiated SSO
		 * flow towards the Agend Service Provider, containing the literal
		 * `%RETURN_URL%` placeholder where the URL-encoded post-login return
		 * URL belongs. Return null to disable host-assisted SSO (the embed
		 * falls back to its own in-frame sign-in flow).
		 *
		 * @param string|null $template Resolved template, or null when no
		 *                              built-in driver matched.
		 * @param array       $atts     The shortcode attributes.
		 */
		$template = apply_filters( 'agend_embed_sso_kickoff_url', $template, $atts );

		return is_string( $template ) ? self::validate_template( $template ) : null;
	}

	/**
	 * Requires the placeholder and a same-site https URL.
	 *
	 * The kick-off must be same-site or the browser will not attach the
	 * WordPress login cookie, which defeats the whole mechanism.
	 *
	 * @param string $template Candidate URL template.
	 * @return string|null The template, or null when invalid.
	 */
	private static function validate_template( $template ) {
		if ( false === strpos( $template, self::RETURN_URL_PLACEHOLDER ) ) {
			return null;
		}

		$site_host     = wp_parse_url( home_url(), PHP_URL_HOST );
		$template_host = wp_parse_url( $template, PHP_URL_HOST );

		if ( empty( $template_host ) || $template_host !== $site_host ) {
			return null;
		}

		return $template;
	}

	/**
	 * Driver: Agend SAML IdP (`agend-saml-idp`).
	 *
	 * Kick-off endpoint: `/saml/idp-sso?idp_initiated=1&sp=<entity id>` with a
	 * `wp_saml_idp_sso_<entity id>` nonce, and `RelayState` as the return URL.
	 *
	 * @param string $sp_entity_id Optional. SP entity id; defaults to the sole
	 *                             enabled Service Provider.
	 * @return string|null URL template, or null when unavailable.
	 */
	private static function agend_saml_idp_template( $sp_entity_id ) {
		if (
			! class_exists( 'WP_SAML_IDP_Service_Provider' ) ||
			! class_exists( 'WP_SAML_IDP_Endpoints' )
		) {
			return null;
		}

		if ( empty( $sp_entity_id ) ) {
			$providers = WP_SAML_IDP_Service_Provider::get_service_providers();
			$enabled   = array();

			foreach ( is_array( $providers ) ? $providers : array() as $provider ) {
				if ( ! empty( $provider['enabled'] ) && ! empty( $provider['entityId'] ) ) {
					$enabled[] = $provider['entityId'];
				}
			}

			if ( 1 !== count( $enabled ) ) {
				return null;
			}

			$sp_entity_id = $enabled[0];
		}

		if ( ! WP_SAML_IDP_Service_Provider::is_service_provider_enabled( $sp_entity_id ) ) {
			return null;
		}

		$url = add_query_arg(
			array(
				'idp_initiated' => '1',
				'sp'            => $sp_entity_id,
				'_wpnonce'      => wp_create_nonce( 'wp_saml_idp_sso_' . $sp_entity_id ),
			),
			WP_SAML_IDP_Endpoints::get_endpoint_url( 'idp-sso' )
		);

		// Appended raw: the placeholder must survive URL-encoding untouched.
		return $url . '&RelayState=' . self::RETURN_URL_PLACEHOLDER;
	}

	/**
	 * Driver: miniOrange "SAML IDP (Identity Provider)".
	 *
	 * Kick-off endpoint: `/?option=saml_user_login&sp=<SP name>` with
	 * `relayState` as the return URL. No nonce is required by that plugin.
	 *
	 * @param string $sp_name Optional. SP name as registered in miniOrange;
	 *                        defaults to the sole registered SP.
	 * @return string|null URL template, or null when unavailable.
	 */
	private static function miniorange_idp_template( $sp_name ) {
		if ( ! defined( 'MSI_VERSION' ) || ! class_exists( '\IDP\Helper\Database\MoDbQueries' ) ) {
			return null;
		}

		if ( empty( $sp_name ) ) {
			$queries = \IDP\Helper\Database\MoDbQueries::mo_idp_get_instance();
			$sp_list = $queries->mo_idp_get_sp_list();

			if ( ! is_array( $sp_list ) || 1 !== count( $sp_list ) || empty( $sp_list[0]->mo_idp_sp_name ) ) {
				return null;
			}

			$sp_name = $sp_list[0]->mo_idp_sp_name;
		}

		$url = add_query_arg(
			array(
				'option' => 'saml_user_login',
				'sp'     => $sp_name,
			),
			home_url( '/' )
		);

		// Appended raw: the placeholder must survive URL-encoding untouched.
		return $url . '&relayState=' . self::RETURN_URL_PLACEHOLDER;
	}
}
