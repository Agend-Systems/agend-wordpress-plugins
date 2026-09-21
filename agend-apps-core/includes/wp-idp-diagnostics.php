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
 * The `preflight` key added by {@see agend_apps_wp_idp_diagnostics()} (via
 * {@see agend_apps_connect_preflight()}, `includes/connect-site.php`) is the
 * one exception worth calling out explicitly: it runs two `WP_User_Query`
 * counts, the only queries this panel executes. Both are still reads that
 * measure nothing they change, and both are bounded (`number => 1`,
 * `count_total`) so neither can turn into an unbounded scan of the user
 * table.
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
	$linked           = defined( 'AGEND_APPS_LINK_STATE_LINKED' ) ? AGEND_APPS_LINK_STATE_LINKED : 'linked';
	$pending          = defined( 'AGEND_APPS_LINK_STATE_PENDING' ) ? AGEND_APPS_LINK_STATE_PENDING : 'pending';
	$pending_approval = defined( 'AGEND_APPS_LINK_STATE_PENDING_APPROVAL' ) ? AGEND_APPS_LINK_STATE_PENDING_APPROVAL : 'pending_approval';
	$conflict         = defined( 'AGEND_APPS_LINK_STATE_CONFLICT' ) ? AGEND_APPS_LINK_STATE_CONFLICT : 'conflict';
	$no_contact       = defined( 'AGEND_APPS_LINK_STATE_NO_CONTACT' ) ? AGEND_APPS_LINK_STATE_NO_CONTACT : 'no_contact';
	$forbidden        = defined( 'AGEND_APPS_LINK_STATE_FORBIDDEN' ) ? AGEND_APPS_LINK_STATE_FORBIDDEN : 'forbidden';
	$error            = defined( 'AGEND_APPS_LINK_STATE_ERROR' ) ? AGEND_APPS_LINK_STATE_ERROR : 'error';
	$asserted         = defined( 'AGEND_APPS_LINK_STATE_ASSERTED' ) ? AGEND_APPS_LINK_STATE_ASSERTED : 'asserted';

	switch ( $state ) {
		case $linked:
			return array(
				'label'    => __( 'Linked', 'agend-apps-core' ),
				'severity' => 'success',
				'guidance' => __( 'This member is linked to their Agend identity. No action needed.', 'agend-apps-core' ),
			);

		case $asserted:
			return array(
				'label'    => __( 'SAML assertion sent', 'agend-apps-core' ),
				'severity' => 'info',
				'guidance' => __( 'The member was redirected through the SAML identity provider; the link is confirmed when the round trip returns and the status check confirms it.', 'agend-apps-core' ),
			);

		case $pending_approval:
			return array(
				'label'    => __( 'Pending Agend approval', 'agend-apps-core' ),
				'severity' => 'info',
				'guidance' => __( "This site's SSO connection has been created but Agend has not approved it yet. SSO stays inactive until they do. Nothing on the WordPress side needs fixing.", 'agend-apps-core' ),
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
				'guidance' => __( 'No link has been attempted yet for this member. Expected until their next front-end page view.', 'agend-apps-core' ),
			);
	}
}

/**
 * Plain-language classification and guidance for a SAML link eligibility
 * reason ({@see agend_apps_saml_link_eligibility()}'s `reason`), mirroring
 * {@see agend_apps_wp_idp_link_state_guidance()}'s shape and tone -- same
 * severity vocabulary (`success`, `info`, `warning`, `error`), same "state
 * an operator can act on" purpose.
 *
 * @param string $reason One of the reasons `agend_apps_saml_link_eligibility()`
 *        returns (`''` for eligible), or an unrecognised string.
 * @return array{label: string, severity: string, guidance: string}
 */
function agend_apps_wp_idp_eligibility_guidance( string $reason ): array {
	switch ( $reason ) {
		case '':
			return array(
				'label'    => __( 'Eligible', 'agend-apps-core' ),
				'severity' => 'success',
				'guidance' => __( 'This member will be offered the link on their next front-end page view.', 'agend-apps-core' ),
			);

		case 'linked':
			return array(
				'label'    => __( 'Already linked', 'agend-apps-core' ),
				'severity' => 'success',
				'guidance' => __( 'This member is already linked. Nothing to do.', 'agend-apps-core' ),
			);

		case 'signed_out':
			return array(
				'label'    => __( 'Signed out', 'agend-apps-core' ),
				'severity' => 'info',
				'guidance' => __( 'No WordPress user is signed in, so the link flow does not apply.', 'agend-apps-core' ),
			);

		case 'mode_not_wordpress':
			return array(
				'label'    => __( 'Not WordPress sign-in mode', 'agend-apps-core' ),
				'severity' => 'info',
				'guidance' => __( 'This site is not in WordPress account sign-in mode, so the link flow does not apply.', 'agend-apps-core' ),
			);

		case 'mechanism_not_saml':
			return array(
				'label'    => __( 'SAML mechanism not selected', 'agend-apps-core' ),
				'severity' => 'info',
				'guidance' => __( 'The configured SSO link mechanism is not SAML, so this flow does not apply.', 'agend-apps-core' ),
			);

		case 'no_external_id':
			$meta_key = function_exists( 'agend_apps_external_id_meta_key' ) ? agend_apps_external_id_meta_key() : '';

			return array(
				'label'    => __( 'No external id', 'agend-apps-core' ),
				'severity' => 'error',
				'guidance' => ( '' !== $meta_key )
					? sprintf(
						/* translators: %s: the configured external-id user-meta key. */
						__( 'This member resolves no external id at all, so nothing can identify them to Agend. Populate the %s user-meta key for this member.', 'agend-apps-core' ),
						$meta_key
					)
					: __( 'This member resolves no external id at all, so nothing can identify them to Agend. Populate the configured external-id user-meta key for this member.', 'agend-apps-core' ),
			);

		case 'sp_not_registered':
			return array(
				'label'    => __( 'Service provider not registered', 'agend-apps-core' ),
				'severity' => 'error',
				'guidance' => __( 'The Agend service provider is not registered with the SAML IdP plugin on this site. Run "Connect this site" to register it.', 'agend-apps-core' ),
			);

		case 'sp_disabled':
			return array(
				'label'    => __( 'Service provider disabled', 'agend-apps-core' ),
				'severity' => 'error',
				'guidance' => __( 'The Agend service provider is registered but disabled at the IdP. Re-enable it there.', 'agend-apps-core' ),
			);

		case 'connection_pending_approval':
			return array(
				'label'    => __( 'Pending Agend approval', 'agend-apps-core' ),
				'severity' => 'info',
				'guidance' => __( 'This is not an error: Agend has not approved the connection yet, so SSO stays inactive until they do. This is the normal state right after connecting; nothing on the WordPress side needs fixing.', 'agend-apps-core' ),
			);

		case 'site_moved':
			return array(
				'label'    => __( 'Site moved', 'agend-apps-core' ),
				'severity' => 'error',
				'guidance' => __( 'This site\'s URL differs from the one recorded when this site was connected, so the link flow has deliberately stood down to avoid a cloned or migrated site acting as the original\'s identity provider. Re-run "Connect this site" on the real site, or scrub the cloned copy\'s secrets.', 'agend-apps-core' ),
			);

		case 'attempt_cap':
			return array(
				'label'    => __( 'Attempt cap reached', 'agend-apps-core' ),
				'severity' => 'warning',
				'guidance' => __( 'Every silent attempt has been spent and the one visible fallback has been used; this member is now in the 24-hour backoff. A successful round trip clears it, or fixing whatever caused the earlier attempts to fail.', 'agend-apps-core' ),
			);

		case 'unavailable':
			return array(
				'label'    => __( 'Unavailable', 'agend-apps-core' ),
				'severity' => 'info',
				'guidance' => __( 'The SAML link flow is not loaded on this site.', 'agend-apps-core' ),
			);

		default:
			return array(
				'label'    => __( 'Unrecognised reason', 'agend-apps-core' ),
				'severity' => 'info',
				'guidance' => sprintf(
					/* translators: %s: the raw, unrecognised eligibility reason. */
					__( 'Unrecognised eligibility reason: %s', 'agend-apps-core' ),
					$reason
				),
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
 *     idp_entity_id: string,
 *     preflight: array{nameid_empty: int, credentials_members: int, nameid_meta_key: string, blocks: bool}|array{},
 *     eligibility: array{eligible: bool, reason: string},
 *     eligibility_guidance: array{label: string, severity: string, guidance: string},
 *     connection: array{approval_state: string, slug: string, idp_entity_id: string, site_url: string},
 *     attempts: array{count: int, cap: int, capped: bool},
 *     bearer_source: string
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
			'attempts'   => 0,
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

	// `agend_apps_saml_link_eligibility()` only reads options and user meta
	// (see its own docblock in `wp-idp-saml-link.php`), so it is safe to call
	// from a pure-read panel. Guarded because `wp-idp-saml-link.php` only
	// loads in `wordpress` sign-in mode. The `entity_id` it also returns is
	// dropped here -- the panel already shows a connection entity id
	// separately, and a second one would just confuse.
	$eligibility_raw = function_exists( 'agend_apps_saml_link_eligibility' )
		? agend_apps_saml_link_eligibility( $user_id )
		: array(
			'eligible' => false,
			'reason'   => 'unavailable',
		);

	$eligibility = array(
		'eligible' => (bool) $eligibility_raw['eligible'],
		'reason'   => (string) $eligibility_raw['reason'],
	);

	// `agend_apps_connect_stored()` is a single option read -- safe for the
	// same reason. Guarded because `includes/connect-site.php`'s option may
	// simply never have been written yet on a site that has not connected.
	$connection_raw = function_exists( 'agend_apps_connect_stored' )
		? agend_apps_connect_stored()
		: array();

	$connection = array(
		'approval_state' => (string) ( $connection_raw['approval_state'] ?? '' ),
		'slug'           => (string) ( $connection_raw['slug'] ?? '' ),
		'idp_entity_id'  => (string) ( $connection_raw['idp_entity_id'] ?? '' ),
		'site_url'       => (string) ( $connection_raw['site_url'] ?? '' ),
	);

	$attempt_cap   = defined( 'AGEND_APPS_LINK_MAX_ATTEMPTS' ) ? AGEND_APPS_LINK_MAX_ATTEMPTS : 3;
	$attempt_count = (int) ( $link['attempts'] ?? 0 );

	$attempts = array(
		'count'  => $attempt_count,
		'cap'    => $attempt_cap,
		'capped' => $attempt_count >= $attempt_cap,
	);

	// Which path actually serves this member's bearer token right now --
	// the "still on credentials session fallback vs SSO-linked" distinction
	// the panel needs to answer.
	//
	// `Agend_Apps_Member_Session` runs at priority 9 and
	// `Agend_Apps_Token_Worker` at priority 10 on the same bearer filter, so
	// a member holding BOTH a stored credentials session and a linked SSO
	// identity is, in practice, served by the credentials session first --
	// it wins the filter chain. But for the purpose of THIS panel, a member
	// whose identity is confirmed `linked` has completed the migration
	// regardless of which cached bearer happens to answer first, so
	// `sso_linked` is reported ahead of `credentials_session` below. This
	// reports migration progress, not filter priority -- check
	// `Agend_Apps_Member_Session` and `Agend_Apps_Token_Worker` directly if
	// the live filter order matters for what you are debugging.
	$linked_state = defined( 'AGEND_APPS_LINK_STATE_LINKED' ) ? AGEND_APPS_LINK_STATE_LINKED : 'linked';

	if ( $linked_state === (string) $link['state'] ) {
		$bearer_source = 'sso_linked';
	} elseif ( class_exists( 'Agend_Apps_Member_Session' ) && method_exists( 'Agend_Apps_Member_Session', 'has_session' ) && Agend_Apps_Member_Session::has_session( $user_id ) ) {
		$bearer_source = 'credentials_session';
	} else {
		$bearer_source = 'none';
	}

	return array(
		'wordpress_mode'       => $wordpress_mode,
		'user_id'              => $user_id,
		'user_email'           => ( $user instanceof WP_User ) ? (string) $user->user_email : '',
		'external_id'          => agend_apps_wp_idp_external_id_source( $user_id ),
		'link'                 => array(
			'state'      => (string) $link['state'],
			'error_code' => (string) $link['error_code'],
			'timestamp'  => (int) $link['timestamp'],
		),
		'link_guidance'        => agend_apps_wp_idp_link_state_guidance( (string) $link['state'] ),
		'identity_ids'         => $identity_ids,
		'token'                => agend_apps_wp_idp_token_state( $user_id ),
		'scopes'               => array(
			'identity_link' => agend_apps_wp_idp_scopes_held( $identity_link_scopes ),
			'account_link'  => agend_apps_wp_idp_scopes_held( $account_link_scopes ),
		),
		'idp_entity_id'        => function_exists( 'agend_apps_idp_entity_id' ) ? agend_apps_idp_entity_id() : '',
		'preflight'            => function_exists( 'agend_apps_connect_preflight' ) ? agend_apps_connect_preflight() : array(),
		'eligibility'          => $eligibility,
		'eligibility_guidance' => agend_apps_wp_idp_eligibility_guidance( $eligibility['reason'] ),
		'connection'           => $connection,
		'attempts'             => $attempts,
		'bearer_source'        => $bearer_source,
	);
}
