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
 * User-meta key holding a member's minted Agend external id.
 *
 * Only used as a fallback, and only in `wordpress` mode (see
 * {@see agend_apps_user_external_id()}): a site without Upbeat has no
 * `imk_membership_number` to key on, so the WordPress-IdP design (section 5,
 * `docs/PLAN-wordpress-idp-option-b.md`) mints a GUID per user instead.
 * Underscore-prefixed like {@see AGEND_APPS_SUPABASE_USER_ID_META} so
 * WordPress treats it as protected meta: hidden from the profile UI and
 * excluded from the REST users endpoint. That is load-bearing, not
 * cosmetic -- this value is an authentication primitive. Anyone who can
 * write it, or hook the `agend_apps_current_user_external_id` filter, can
 * impersonate that member to the gateway.
 *
 * @var string
 */
const AGEND_APPS_EXTERNAL_ID_META = '_agend_apps_external_id';

/**
 * Resolves a given WordPress user's Agend external id.
 *
 * Extracted from {@see agend_apps_current_user_external_id()} so the linking
 * step (`includes/wp-idp-link.php`, not yet built) can resolve an arbitrary
 * user id. That step runs on `wp_login`, where `get_current_user_id()` is
 * still 0 because WordPress has not yet called `wp_set_current_user()` inside
 * `wp_signon()`, so the current-user-only version cannot serve it.
 *
 * Resolution order (docs/PLAN-wordpress-idp-option-b.md section 5):
 *
 * 1. Return "" immediately for user id 0, without applying the filter below.
 * 2. The configured user-meta key ({@see agend_apps_external_id_meta_key()}),
 *    exactly as before. This keeps Upbeat sites, and any site whose SAML
 *    NameID comes from a meta key, resolving unchanged.
 * 3. Only when that is empty AND the site is in `wordpress` mode
 *    ({@see Agend_Apps_Settings::wordpress_idp_enabled()}), the minted GUID
 *    meta. Gating on the mode means an Upbeat site that happens to be missing
 *    the configured meta for one member never falls through to a second,
 *    conflicting identity -- it stays unresolved, same as today.
 * 4. The `agend_apps_current_user_external_id` filter, applied last so it
 *    still overrides everything else. That is its existing contract; do not
 *    reorder this.
 *
 * @param int $user_id WordPress user id (0 = not logged in / not resolvable).
 * @return string The external id, or an empty string.
 */
function agend_apps_user_external_id( int $user_id ): string {
	if ( 0 === $user_id ) {
		return '';
	}

	$external_id = (string) get_user_meta( $user_id, agend_apps_external_id_meta_key(), true );

	if ( '' === $external_id && Agend_Apps_Settings::wordpress_idp_enabled() ) {
		$external_id = (string) get_user_meta( $user_id, AGEND_APPS_EXTERNAL_ID_META, true );
	}

	/**
	 * Filters a WordPress user's Agend external id.
	 *
	 * Set this to map WordPress users to a different IdP subject scheme (e.g.
	 * the WordPress user id or a computed value) when a meta key alone cannot
	 * express the mapping.
	 *
	 * Despite the hook name (kept for backwards compatibility -- it predates
	 * the per-user resolver), this now fires for an arbitrary user id, not
	 * only the current one: `$user_id` is passed explicitly as the second
	 * argument, so a correct callback is unaffected, but a callback that
	 * silently assumed `$user_id` always equalled `get_current_user_id()`
	 * would not be.
	 *
	 * @param string $external_id Resolved external id (may be empty).
	 * @param int    $user_id     The WordPress user id being resolved.
	 */
	return (string) apply_filters( 'agend_apps_current_user_external_id', $external_id, $user_id );
}

/**
 * Resolves the current WordPress user's Agend external id.
 *
 * Thin delegate to {@see agend_apps_user_external_id()} for the logged-in
 * user. Behaviour for `credentials` and `sso` mode sites is unchanged: the
 * GUID fallback only engages in `wordpress` mode.
 *
 * @return string The external id, or an empty string.
 */
function agend_apps_current_user_external_id(): string {
	return agend_apps_user_external_id( get_current_user_id() );
}

/**
 * Mints and stores a GUID external id for a WordPress user, if and only if
 * none already resolves.
 *
 * Deliberately has no callers yet and is hooked to nothing: the linking step
 * (`includes/wp-idp-link.php`) will be its only caller, on `wp_login` and
 * `user_register` (docs/PLAN-wordpress-idp-option-b.md section 4.2). Keeping
 * this out of every read path means resolving an id is always a pure read --
 * a mint only ever happens where the plan puts it.
 *
 * Write-once: if {@see agend_apps_user_external_id()} already resolves a
 * non-empty id for this user (whether from the configured meta key or a
 * previously-minted GUID), that id is returned unchanged and nothing is
 * minted. This is what stops a second, conflicting identity being minted for
 * an Upbeat member who already resolves on their membership number.
 *
 * The store uses `add_user_meta()` with `$unique = true` rather than
 * `update_user_meta()`, so a concurrent caller that mints between this
 * function's resolve and its write loses the race cleanly: WordPress refuses
 * the second insert, and this call returns whatever the winner wrote instead
 * of overwriting it. A regenerated id would orphan the member's existing
 * Agend identity rather than recover it, so this path must never overwrite.
 *
 * @param int $user_id WordPress user id (0 = not resolvable).
 * @return string The external id (existing or newly minted), or an empty
 *                string for user id 0.
 */
function agend_apps_ensure_external_id( int $user_id ): string {
	if ( 0 === $user_id ) {
		return '';
	}

	$existing = agend_apps_user_external_id( $user_id );

	if ( '' !== $existing ) {
		return $existing;
	}

	$guid = wp_generate_uuid4();

	if ( ! add_user_meta( $user_id, AGEND_APPS_EXTERNAL_ID_META, $guid, true ) ) {
		// Lost the race: another call already inserted one. Return that.
		return (string) get_user_meta( $user_id, AGEND_APPS_EXTERNAL_ID_META, true );
	}

	return $guid;
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
