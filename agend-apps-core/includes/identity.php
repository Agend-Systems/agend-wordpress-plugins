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

/**
 * User-meta key holding a member's Agend Supabase user id.
 *
 * Underscore-prefixed (hidden from the profile UI); never sent to the
 * browser.
 *
 * @var string
 */
const AGEND_APPS_SUPABASE_USER_ID_META = '_agend_apps_supabase_user_id';

/**
 * User-meta key holding a member's Agend CRM contact id.
 *
 * Underscore-prefixed (hidden from the profile UI); never sent to the
 * browser.
 *
 * @var string
 */
const AGEND_APPS_CONTACT_ID_META = '_agend_apps_contact_id';

/**
 * Records a WordPress user's linked Agend identity ids from a gateway
 * response.
 *
 * Shared by every surface that observes a linked identity: the credential
 * login flow (member-identity.php's `agend_apps_member_store_contact_ref()`),
 * the account-link state resolver (`agend_apps_account_link_state()`) on a
 * linked SSO status, and the SSO token worker on a successful mint. Both ids
 * are optional in the response (an older gateway omits them, or a contactless
 * member has no `contact_id`), and an empty/missing value never overwrites an
 * already-recorded one -- a later call that happens not to carry an id (e.g.
 * a status check against an older gateway) must not erase what an earlier
 * call already established.
 *
 * @param int   $user_id WordPress user id.
 * @param array $data    Decoded response data. Reads `user_id` and
 *                        `contact_id` (both optional, `contact_id` may be
 *                        null).
 */
function agend_apps_record_linked_identity( int $user_id, array $data ): void {
	if ( 0 === $user_id ) {
		return;
	}

	if ( isset( $data['user_id'] ) && '' !== (string) $data['user_id'] ) {
		update_user_meta( $user_id, AGEND_APPS_SUPABASE_USER_ID_META, sanitize_text_field( (string) $data['user_id'] ) );
	}

	if ( isset( $data['contact_id'] ) && '' !== (string) $data['contact_id'] ) {
		update_user_meta( $user_id, AGEND_APPS_CONTACT_ID_META, sanitize_text_field( (string) $data['contact_id'] ) );
	}
}

/**
 * Resolves the Agend identity ids already recorded for a WordPress user.
 *
 * @param int $user_id WordPress user id (0 = not logged in).
 * @return array{supabase_user_id: string, contact_id: string} Both empty
 *         strings when nothing has been recorded, or `$user_id` is 0.
 */
function agend_apps_linked_identity_ids( int $user_id ): array {
	if ( 0 === $user_id ) {
		return array(
			'supabase_user_id' => '',
			'contact_id'       => '',
		);
	}

	return array(
		'supabase_user_id' => (string) get_user_meta( $user_id, AGEND_APPS_SUPABASE_USER_ID_META, true ),
		'contact_id'       => (string) get_user_meta( $user_id, AGEND_APPS_CONTACT_ID_META, true ),
	);
}
