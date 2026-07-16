<?php
/**
 * Current-user Agend identity resolution.
 *
 * Resolves the logged-in WordPress user to the opaque external subject the
 * SAML IdP asserts as NameID, plus the IdP entity id that names the account's
 * SSO connection in Agend. Both are the inputs an account-link status check
 * (and, later, a token mint) key on, so they MUST resolve the same identity a
 * live SSO login would provision.
 *
 * The defaults mirror agend-loop-sync (membership number as external id, the
 * WordPress SAML IdP settings as the entity id) so a synced member resolves
 * consistently. Both are filterable for IdP-agnostic deployments.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns the user-meta key holding a member's Agend external id.
 *
 * Configurable via the `agend_apps_external_id_meta_key` option (OQ8: the
 * correct subject scheme is determined by the site's IdP plugin, which is not
 * guaranteed to be agend-saml-idp, so it must not be hardcoded). Defaults to
 * the Upbeat membership number key that agend-loop-sync writes.
 *
 * @return string The meta key.
 */
function agend_apps_external_id_meta_key(): string {
	$meta_key = (string) get_option( 'agend_apps_external_id_meta_key', '' );

	if ( '' === $meta_key ) {
		$meta_key = 'imk_membership_number';
	}

	/**
	 * Filters the user-meta key holding the Agend external id.
	 *
	 * @param string $meta_key The configured meta key.
	 */
	return (string) apply_filters( 'agend_apps_external_id_meta_key', $meta_key );
}

/**
 * Resolves the current WordPress user's Agend external id.
 *
 * Reads the configured user-meta key (default: the Upbeat membership number
 * agend-loop-sync writes, matching the SAML NameID the IdP asserts). Returns
 * an empty string when there is no logged-in user or the user has no external
 * id, so callers can treat "" as "cannot check / not linkable".
 *
 * @return string The external id, or an empty string.
 */
function agend_apps_current_user_external_id(): string {
	$user_id = get_current_user_id();

	if ( 0 === $user_id ) {
		return '';
	}

	$external_id = (string) get_user_meta( $user_id, agend_apps_external_id_meta_key(), true );

	/**
	 * Filters the current user's Agend external id.
	 *
	 * Set this to map WordPress users to a different IdP subject scheme (e.g.
	 * the WordPress user id or a computed value) when a meta key alone cannot
	 * express the mapping.
	 *
	 * @param string $external_id Resolved external id (may be empty).
	 * @param int    $user_id     Current WordPress user id.
	 */
	return (string) apply_filters( 'agend_apps_current_user_external_id', $external_id, $user_id );
}

/**
 * Resolves the SAML IdP entity id (Issuer) for this site.
 *
 * Reads the WordPress SAML IdP plugin settings, falling back to the standard
 * metadata URL. This is the Issuer that names the account's SSO connection in
 * Agend.
 *
 * @return string The IdP entity id.
 */
function agend_apps_idp_entity_id(): string {
	$idp_settings = get_option( 'wp_saml_idp_settings', array() );

	$entity_id = ( is_array( $idp_settings ) && ! empty( $idp_settings['entity_id'] ) )
		? (string) $idp_settings['entity_id']
		: site_url( '/saml/metadata' );

	/**
	 * Filters the resolved SAML IdP entity id.
	 *
	 * @param string $entity_id The resolved IdP entity id.
	 */
	return (string) apply_filters( 'agend_apps_idp_entity_id', $entity_id );
}
