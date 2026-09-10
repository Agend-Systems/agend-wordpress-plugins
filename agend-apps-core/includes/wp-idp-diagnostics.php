<?php
/**
 * WordPress-as-IdP diagnostics.
 *
 * docs/PLAN-wordpress-idp-option-b.md section 6, "Diagnostic panel". The
 * reason this exists at all: `agend-content-access` fails closed on an empty
 * bearer (`agend-content-access/includes/class-agend-content-access-decision.php:74-81`),
 * so when WordPress-IdP linking does not work for a member, the only symptom
 * anyone sees is "the member cannot see their content", with no error
 * surfaced anywhere. Without this file that is undiagnosable in the field.
 *
 * Everything here is a PURE READ. Nothing in this file may mint a token,
 * attempt a link, or refresh the cached key scopes -- an admin opening the
 * Identity and SSO settings page must never change the thing this panel is
 * measuring. In particular {@see agend_apps_wp_idp_scope_held()}
 * deliberately reads the `Agend_Apps_Key_Scopes` option directly rather than
 * calling `Agend_Apps_Key_Scopes::has()`/`all()`, because both of those call
 * `maybe_refresh()`, which can perform a live gateway request
 * (`GET /v1/health`) when the cache is stale or unknown.
 *
 * Loaded unconditionally (see `agend-apps-core.php`), unlike
 * `includes/wp-idp-link.php`, which only loads in `wordpress` sign-in mode.
 * The panel itself must degrade honestly in the other two modes rather than
 * disappearing, so every reference to a `wp-idp-link.php` symbol below is
 * guarded with `function_exists()`/`defined()`.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves which of the three possible sources produced a WordPress user's
 * Agend external id, mirroring the resolution order in
 * {@see agend_apps_user_external_id()} exactly (it is not reused directly,
 * because that function returns only the final value -- not which step
 * produced it, which is the diagnostic value this panel needs).
 *
 * @param int $user_id WordPress user id.
 * @return array{value: string, source: string} `source` is one of
 *         `meta_key` (the configured user-meta key), `minted` (the
 *         WordPress-minted GUID, `wordpress` mode only), `filter` (the
 *         `agend_apps_current_user_external_id` filter changed or supplied
 *         the value), or '' (no external id resolves).
 */
function agend_apps_wp_idp_external_id_source( int $user_id ): array {
	if ( 0 === $user_id || ! function_exists( 'agend_apps_external_id_meta_key' ) ) {
		return array(
			'value'  => '',
			'source' => '',
		);
	}

	$base   = (string) get_user_meta( $user_id, agend_apps_external_id_meta_key(), true );
	$source = ( '' !== $base ) ? 'meta_key' : '';

	if (
		'' === $base
		&& class_exists( 'Agend_Apps_Settings' )
		&& Agend_Apps_Settings::wordpress_idp_enabled()
		&& defined( 'AGEND_APPS_EXTERNAL_ID_META' )
	) {
		$base = (string) get_user_meta( $user_id, AGEND_APPS_EXTERNAL_ID_META, true );

		if ( '' !== $base ) {
			$source = 'minted';
		}
	}

	$final = (string) apply_filters( 'agend_apps_current_user_external_id', $base, $user_id );

	if ( $final !== $base ) {
		// The filter changed the value (supplied one where there was none, or
		// overrode an existing one) -- it is the source of truth either way.
		$source = ( '' !== $final ) ? 'filter' : '';
	} elseif ( '' === $final ) {
		$source = '';
	}

	return array(
		'value'  => $final,
		'source' => $source,
	);
}

/**
 * Reads the cached SSO token state for a WordPress user, without minting or
 * refreshing anything.
 *
 * Deliberately never returns the token itself or any part of it -- only
 * facts ABOUT it (cached, its expiry, whether that expiry has passed).
 *
 * @param int $user_id WordPress user id.
 * @return array{cached: bool, expires_at: int, expired: bool, negative_cached: bool}
 */
function agend_apps_wp_idp_token_state( int $user_id ): array {
	$state = array(
		'cached'          => false,
		'expires_at'      => 0,
		'expired'         => false,
		'negative_cached' => false,
	);

	if ( 0 === $user_id || ! class_exists( 'Agend_Apps_Token_Worker' ) ) {
		return $state;
	}

	$cached = get_user_meta( $user_id, Agend_Apps_Token_Worker::META_KEY, true );

	if ( is_array( $cached ) && isset( $cached['access_token'], $cached['expires_at'] ) && '' !== (string) $cached['access_token'] ) {
		$expires_at = (int) $cached['expires_at'];

		$state['cached']     = true;
		$state['expires_at'] = $expires_at;
		$state['expired']    = ( $expires_at - Agend_Apps_Token_Worker::EXPIRY_BUFFER ) <= time();
	}

	$state['negative_cached'] = false !== get_transient( Agend_Apps_Token_Worker::NEGATIVE_PREFIX . $user_id );

	return $state;
}

/**
 * Whether the connected API key is recorded as holding every one of the
 * given scopes.
 *
 * Reads the `Agend_Apps_Key_Scopes::OPTION` option directly rather than
 * calling `Agend_Apps_Key_Scopes::has()`/`all()` -- see the file docblock:
 * both of those can trigger a live `GET /v1/health` request when the cache
 * is unknown or stale, which this panel must never do.
 *
 * @param string[] $scopes Scopes to check for.
 * @return array{known: bool, held: bool} `known` is false when the scope
 *         cache has never been populated, in which case `held` is also
 *         false (an unfetched scope list is never treated as held).
 */
function agend_apps_wp_idp_scopes_held( array $scopes ): array {
	if ( ! class_exists( 'Agend_Apps_Key_Scopes' ) ) {
		return array(
			'known' => false,
			'held'  => false,
		);
	}

	$stored = get_option( Agend_Apps_Key_Scopes::OPTION, array() );

	if ( ! is_array( $stored ) || ! isset( $stored['scopes'] ) || ! is_array( $stored['scopes'] ) ) {
		return array(
			'known' => false,
			'held'  => false,
		);
	}

	$held_scopes = array_map( 'strval', $stored['scopes'] );

	foreach ( $scopes as $scope ) {
		if ( ! in_array( $scope, $held_scopes, true ) ) {
			return array(
				'known' => true,
				'held'  => false,
			);
		}
	}

	return array(
		'known' => true,
		'held'  => true,
	);
}

/**
 * Plain-language classification and guidance for a recorded link state
 * (docs/PLAN-wordpress-idp-option-b.md section 6, the per-state copy this
 * panel is required to show).
 *
 * @param string $state One of the `AGEND_APPS_LINK_STATE_*` constants, or ''.
 * @return array{label: string, severity: string, guidance: string} `severity`
 *         is one of `success` (linked), `info` (pending / never attempted --
 *         nothing to do), `warning` (error -- transient, retries itself), or
 *         `error` (conflict / no_contact / forbidden -- needs a human).
 */
function agend_apps_wp_idp_link_state_guidance( string $state ): array {
	$linked     = defined( 'AGEND_APPS_LINK_STATE_LINKED' ) ? AGEND_APPS_LINK_STATE_LINKED : 'linked';
	$pending    = defined( 'AGEND_APPS_LINK_STATE_PENDING' ) ? AGEND_APPS_LINK_STATE_PENDING : 'pending';
	$conflict   = defined( 'AGEND_APPS_LINK_STATE_CONFLICT' ) ? AGEND_APPS_LINK_STATE_CONFLICT : 'conflict';
	$no_contact = defined( 'AGEND_APPS_LINK_STATE_NO_CONTACT' ) ? AGEND_APPS_LINK_STATE_NO_CONTACT : 'no_contact';
	$forbidden  = defined( 'AGEND_APPS_LINK_STATE_FORBIDDEN' ) ? AGEND_APPS_LINK_STATE_FORBIDDEN : 'forbidden';
	$error      = defined( 'AGEND_APPS_LINK_STATE_ERROR' ) ? AGEND_APPS_LINK_STATE_ERROR : 'error';

	switch ( $state ) {
		case $linked:
			return array(
				'label'    => __( 'Linked', 'agend-apps-core' ),
				'severity' => 'success',
				'guidance' => __( 'This member is linked to their Agend identity. No action needed.', 'agend-apps-core' ),
			);

		case $pending:
			return array(
				'label'    => __( 'Pending confirmation', 'agend-apps-core' ),
				'severity' => 'info',
				'guidance' => __( 'The member was sent a confirmation email and has not completed it yet. Nothing is wrong; they need to click the link in that email.', 'agend-apps-core' ),
			);

		case $conflict:
			return array(
				'label'    => __( 'Conflict', 'agend-apps-core' ),
				'severity' => 'error',
				'guidance' => __( "This member's email is already linked under a different external id on this connection. The gateway never re-points a link automatically -- it needs fixing in the Agend dashboard.", 'agend-apps-core' ),
			);

		case $no_contact:
			return array(
				'label'    => __( 'No CRM contact', 'agend-apps-core' ),
				'severity' => 'error',
				'guidance' => __( 'No CRM contact matches this member\'s email, and the connection has JIT contact provisioning switched off. Turn it on in the Agend dashboard, or create the contact.', 'agend-apps-core' ),
			);

		case $forbidden:
			return array(
				'label'    => __( 'Forbidden', 'agend-apps-core' ),
				'severity' => 'error',
				'guidance' => __( "No SSO connection matches this site's entity id, and the connected API key cannot auto-create one. Create the connection in the Agend dashboard, or grant the key sso.connections.create.", 'agend-apps-core' ),
			);

		case $error:
			return array(
				'label'    => __( 'Error', 'agend-apps-core' ),
				'severity' => 'warning',
				'guidance' => __( 'A transient error occurred (a transport failure, an unrecognised gateway error, or a 5xx). It retries on its own at the member\'s next sign-in.', 'agend-apps-core' ),
			);

		default:
			return array(
				'label'    => __( 'Never attempted', 'agend-apps-core' ),
				'severity' => 'info',
				'guidance' => __( 'No link has been attempted yet for this member. Expected until they next sign in.', 'agend-apps-core' ),
			);
	}
}

/**
 * Builds every fact the WordPress-IdP diagnostic panel shows for one
 * WordPress user. Pure read: never mints a token, attempts a link, or
 * refreshes the cached key scopes (see the file docblock).
 *
 * @param int $user_id WordPress user id (0 = nothing to report).
 * @return array{
 *     wordpress_mode: bool,
 *     user_id: int,
 *     user_email: string,
 *     external_id: array{value: string, source: string},
 *     link: array{state: string, error_code: string, timestamp: int},
 *     link_guidance: array{label: string, severity: string, guidance: string},
 *     identity_ids: array{supabase_user_id: string, contact_id: string},
 *     token: array{cached: bool, expires_at: int, expired: bool, negative_cached: bool},
 *     scopes: array{
 *         identity_link: array{known: bool, held: bool},
 *         account_link: array{known: bool, held: bool}
 *     },
 *     idp_entity_id: string
 * }
 */
function agend_apps_wp_idp_diagnostics( int $user_id ): array {
	$wordpress_mode = class_exists( 'Agend_Apps_Settings' ) && Agend_Apps_Settings::wordpress_idp_enabled();

	$user = ( $user_id > 0 ) ? get_user_by( 'id', $user_id ) : false;

	$link = ( function_exists( 'agend_apps_wp_idp_link_state' ) )
		? agend_apps_wp_idp_link_state( $user_id )
		: array(
			'state'      => '',
			'error_code' => '',
			'timestamp'  => 0,
		);

	$identity_ids = function_exists( 'agend_apps_linked_identity_ids' )
		? agend_apps_linked_identity_ids( $user_id )
		: array(
			'supabase_user_id' => '',
			'contact_id'       => '',
		);

	// The scope sets the link step (`sso_identity_link`) and the token
	// worker (`sso_account_link`) each need -- kept in sync with
	// `includes/records/features.php`'s registry by reading it directly
	// (pure data, no gateway call) rather than duplicating the scope lists
	// here.
	$features            = function_exists( 'agend_apps_records_optional_features' ) ? agend_apps_records_optional_features() : array();
	$identity_link_scopes = (array) ( $features['sso_identity_link']['scopes'] ?? array( 'sso.identities.create' ) );
	$account_link_scopes  = (array) ( $features['sso_account_link']['scopes'] ?? array( 'sso.identities.read', 'sso.tokens.create' ) );

	return array(
		'wordpress_mode' => $wordpress_mode,
		'user_id'        => $user_id,
		'user_email'     => ( $user instanceof WP_User ) ? (string) $user->user_email : '',
		'external_id'    => agend_apps_wp_idp_external_id_source( $user_id ),
		'link'           => array(
			'state'      => (string) $link['state'],
			'error_code' => (string) $link['error_code'],
			'timestamp'  => (int) $link['timestamp'],
		),
		'link_guidance'  => agend_apps_wp_idp_link_state_guidance( (string) $link['state'] ),
		'identity_ids'   => $identity_ids,
		'token'          => agend_apps_wp_idp_token_state( $user_id ),
		'scopes'         => array(
			'identity_link' => agend_apps_wp_idp_scopes_held( $identity_link_scopes ),
			'account_link'  => agend_apps_wp_idp_scopes_held( $account_link_scopes ),
		),
		'idp_entity_id'  => function_exists( 'agend_apps_idp_entity_id' ) ? agend_apps_idp_entity_id() : '',
	);
}
