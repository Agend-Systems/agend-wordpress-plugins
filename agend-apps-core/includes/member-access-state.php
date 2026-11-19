<?php
/**
 * The member access state: one answer to "can this visitor's own Agend details
 * be read right now, and if not, why not and what can they do about it".
 *
 * Any surface that shows a signed-in member their own data asks this first
 * (the Member Summary does; the Learning Pathways progress feature will).
 * It replaces the helpers that each answer part of the question differently:
 *
 * - `window.agendApps.loggedIn` is true only when a stored credential session
 *   exists, so it is false for every `wordpress` and `sso` member. It is never
 *   consulted here; the server's view of the session is the only one used.
 * - {@see agend_apps_account_link_state()} reports `linked` for any signed-in
 *   user in `credentials` mode without looking for a session, and in
 *   `wordpress` mode ignores the recorded link state and spends a gateway call
 *   on every render. It stays as it is for its one caller (the WooCommerce My
 *   Account endpoint); this file does not use it except in `sso` mode, once,
 *   when there is no bearer, to get the address that starts the link.
 *
 * Signed in is `is_user_logged_in()` in every mode. Linked means the gateway
 * will be sent a member bearer: {@see agend_apps_get_bearer_token()} returns
 * something. That is the same call the API client makes itself before every
 * member request, so it is the one truthful test in all three modes, and it is
 * made at most once per request because the resolver behind it can mint a
 * token and can wait about three seconds for another request's mint. Only
 * when it comes back empty is the reason worked out, per sign-in mode.
 *
 * "Ready" means a bearer exists, not that the gateway has accepted it, and not
 * that a member record exists. Those two come out of the read itself, which
 * {@see agend_apps_member_access_from_error()} classifies.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether the current user's member bearer resolves, asked once per request.
 *
 * The answer is held per user id so a second caller in the same request (the
 * page render and then a fetch, say) does not run the token resolver again.
 * The bearer itself is never returned or held: callers that need it ask the
 * API client, which resolves it the way it always has.
 *
 * @param int  $user_id WordPress user id; 0 is never linked.
 * @param bool $forget  True to drop what is held (tests, and code that has
 *                      just changed the member's link).
 * @return bool
 */
function agend_apps_member_access_has_bearer( int $user_id, bool $forget = false ): bool {
	static $held = array();

	if ( $forget ) {
		$held = array();

		return false;
	}

	if ( $user_id <= 0 || ! function_exists( 'agend_apps_get_bearer_token' ) ) {
		return false;
	}

	if ( ! array_key_exists( $user_id, $held ) ) {
		$held[ $user_id ] = '' !== agend_apps_get_bearer_token();
	}

	return $held[ $user_id ];
}

/**
 * Drops the held bearer answer. For tests, and for code that has just linked
 * or unlinked the current member.
 */
function agend_apps_member_access_forget(): void {
	agend_apps_member_access_has_bearer( 0, true );
}

/**
 * The shape of every answer, so a caller can rely on every key.
 *
 * @param string $mode Member sign-in mode.
 * @return array{state: string, reason: string, mode: string, signed_in: bool, linked: bool, action: string, retry_after: int, sign_in_url: string, initiate_url: string, detail: string}
 */
function agend_apps_member_access_blank( string $mode ): array {
	return array(
		'state'        => 'unavailable',
		'reason'       => '',
		'mode'         => $mode,
		'signed_in'    => false,
		'linked'       => false,
		'action'       => 'none',
		'retry_after'  => 0,
		'sign_in_url'  => '',
		'initiate_url' => '',
		'detail'       => '',
	);
}

/**
 * The member sign-in mode: `credentials`, `sso` or `wordpress`.
 *
 * @return string
 */
function agend_apps_member_access_mode(): string {
	if ( class_exists( 'Agend_Apps_Settings' ) && method_exists( 'Agend_Apps_Settings', 'get_member_auth_mode' ) ) {
		return Agend_Apps_Settings::get_member_auth_mode();
	}

	return 'credentials';
}

/**
 * Plain-text detail for administrators about why a member cannot be read.
 *
 * Written for the person who runs the site, so it names things a member must
 * never be told (a missing key permission, a stored link state). It is only
 * ever put in an answer for a viewer who can manage options.
 *
 * @param string $reason Machine reason.
 * @param string $extra  Further text for the reason, such as the scopes missing.
 * @return string
 */
function agend_apps_member_access_admin_detail( string $reason, string $extra = '' ): string {
	if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
		return '';
	}

	switch ( $reason ) {
		case 'session_missing':
			$text = __( 'This user has no stored Agend session, so the site has no member token for them. Accounts made before member sign-in was switched on, and users who have not signed in since, have none. They need to sign out and sign in again.', 'agend-apps-core' );
			break;
		case 'session_expired':
			$text = __( 'Agend refused this member\'s stored sign-in. Signing out and in again replaces it.', 'agend-apps-core' );
			break;
		case 'no_external_id':
			$text = __( 'No external id is stored for this user, so the site cannot tell Agend who they are. In SSO mode it is read from the user meta key set under Identity and SSO.', 'agend-apps-core' );
			break;
		case 'missing_scope':
			$text = __( 'The connected key does not hold the permissions this part needs. Add them to the key in the Agend dashboard, then use Test connection in Agend Apps settings.', 'agend-apps-core' );
			break;
		case 'app_not_allowed':
			$text = __( 'The connected key is not allowed to use an Agend app this part needs.', 'agend-apps-core' );
			break;
		case 'sso_unlinked':
			$text = __( 'This user is not linked to an Agend member yet. They can start the link from the Connect button.', 'agend-apps-core' );
			break;
		case 'token_unavailable':
			$text = __( 'The user is linked, but no member token could be minted just now. Agend may be unreachable; the site tries again shortly.', 'agend-apps-core' );
			break;
		case 'link_check_failed':
			$text = __( 'Agend could not be asked whether this user is linked. A transport error or a gateway fault; it clears on its own.', 'agend-apps-core' );
			break;
		case 'contact_not_found':
			$text = __( 'Agend has no contact for this user, so there is no membership to show. The contact may need creating, or the email may differ.', 'agend-apps-core' );
			break;
		case 'busy':
			$text = __( 'The site has used most of its allowance of Agend calls for this minute, or Agend answered "too many requests".', 'agend-apps-core' );
			break;
		case 'gateway_error':
			$text = __( 'Agend answered with an error or could not be reached.', 'agend-apps-core' );
			break;
		default:
			// A recorded WordPress identity-provider link state has wording
			// written for administrators already; it is reused, never copied.
			$text = '';

			if ( 0 === strpos( $reason, 'link_' ) && function_exists( 'agend_apps_wp_idp_link_state_guidance' ) ) {
				$recorded = substr( $reason, 5 );
				$guidance = agend_apps_wp_idp_link_state_guidance( 'not_started' === $recorded ? '' : $recorded );
				$text     = (string) ( $guidance['guidance'] ?? '' );
			}
	}

	return trim( $text . ( '' !== $extra ? ' ' . $extra : '' ) );
}

/**
 * Builds an answer from a state, reason and action.
 *
 * @param array  $base   The blank answer for this request.
 * @param string $state  One of the states in {@see agend_apps_member_access_state()}.
 * @param string $reason Machine reason.
 * @param string $action What the member can do about it.
 * @param string $extra  Text added to the administrator detail.
 * @return array
 */
function agend_apps_member_access_with( array $base, string $state, string $reason, string $action, string $extra = '' ): array {
	$base['state']  = $state;
	$base['reason'] = $reason;
	$base['action'] = $action;
	$base['detail'] = agend_apps_member_access_admin_detail( $reason, $extra );

	return $base;
}

/**
 * Whether the key holds what a member token needs in `sso` and `wordpress`
 * mode. Without both scopes the token worker returns nothing without calling
 * Agend, so every member would look unlinked.
 *
 * @return bool
 */
function agend_apps_member_access_can_mint(): bool {
	if ( ! function_exists( 'agend_apps_records_feature_available' ) ) {
		return true;
	}

	return agend_apps_records_feature_available( 'sso_account_link' );
}

/**
 * Resolves the current visitor's access to their own Agend details.
 *
 * States:
 * - `signed_out`: not signed in to WordPress. No gateway call, no probe.
 * - `ready`: signed in and a member bearer resolves. Fetch.
 * - `connecting`: `wordpress` or `sso` mode and the link is not made yet, as
 *   happens right after a first sign-in. Retry shortly.
 * - `not_linked`: signed in but not connected to a member, for a reason the
 *   member or an administrator can act on (sign in again, connect, confirm by
 *   email, or a link fault only an administrator can clear).
 * - `not_configured`: the site cannot get member tokens at all (the key lacks
 *   the permissions), or the identity connection is not set up. An
 *   administrator fault; members are told it is unavailable.
 * - `no_member_record`: signed in, but Agend has no member for this user.
 *   Reached here only when the user has no external id or a recorded
 *   `no_contact` link; a read answering 404 reaches it through
 *   {@see agend_apps_member_access_from_error()}.
 * - `unavailable`: anything else that went wrong; retry.
 *
 * Reasons, by mode, when there is no bearer:
 * - `credentials`: no stored session is `session_missing`; a session that
 *   exists but yields no bearer is `token_unavailable`.
 * - `sso`: no external id is `no_external_id`; a key that cannot mint is
 *   `missing_scope`; otherwise the link status is read once, `unlinked`
 *   becoming `sso_unlinked` with the address that starts the link.
 * - `wordpress`: no external id is `no_external_id`; a key that cannot mint is
 *   `missing_scope`; otherwise the recorded link state decides (`link_pending`,
 *   `link_conflict`, `link_no_contact`, `link_forbidden`,
 *   `link_pending_approval`, and `connecting` while it is empty, asserted or
 *   errored). The SSO status endpoint is never called in this mode: WordPress
 *   is the identity provider, so it does not apply.
 *
 * Every answer carries `mode`, `signed_in`, `linked` (true only for `ready`),
 * `reason` (machine, '' when ready or signed out), `action` (`sign_in`,
 * `sign_in_again`, `connect`, `check_email`, `retry`, `contact`, `join` or
 * `none`), `retry_after` (seconds, for `connecting`), `sign_in_url`,
 * `initiate_url` and `detail`. `detail` is plain text for administrators and is
 * '' for everyone else. A caller shows members only
 * {@see agend_apps_member_access_message()}, never `reason`.
 *
 * @param array $args {
 *     Optional.
 *
 *     @type string $return_url Address to come back to after signing in or
 *                              linking. Defaults to the site home.
 * }
 * @return array{state: string, reason: string, mode: string, signed_in: bool, linked: bool, action: string, retry_after: int, sign_in_url: string, initiate_url: string, detail: string}
 */
function agend_apps_member_access_state( array $args = array() ): array {
	$mode       = agend_apps_member_access_mode();
	$base       = agend_apps_member_access_blank( $mode );
	$return_url = isset( $args['return_url'] ) && is_string( $args['return_url'] ) && '' !== $args['return_url'] ? $args['return_url'] : home_url( '/' );
	$user_id    = (int) get_current_user_id();

	$base['sign_in_url'] = wp_login_url( $return_url );

	if ( ! is_user_logged_in() || $user_id <= 0 ) {
		$base['state']  = 'signed_out';
		$base['action'] = 'sign_in';

		return $base;
	}

	$base['signed_in'] = true;

	// The one truthful test in every mode: the same call the API client makes
	// before a member request.
	if ( agend_apps_member_access_has_bearer( $user_id ) ) {
		$base['state']  = 'ready';
		$base['linked'] = true;
		$base['action'] = 'none';

		return $base;
	}

	if ( 'credentials' === $mode ) {
		$has_session = class_exists( 'Agend_Apps_Member_Session' ) && Agend_Apps_Member_Session::has_session( $user_id );

		if ( ! $has_session ) {
			return agend_apps_member_access_with( $base, 'not_linked', 'session_missing', 'sign_in_again' );
		}

		return agend_apps_member_access_with( $base, 'unavailable', 'token_unavailable', 'retry' );
	}

	// `sso` and `wordpress` share the first two checks: both mint the bearer
	// from the member's external id with the same two key permissions.
	$external_id = function_exists( 'agend_apps_user_external_id' ) ? agend_apps_user_external_id( $user_id ) : '';

	if ( '' === $external_id ) {
		return agend_apps_member_access_with( $base, 'no_member_record', 'no_external_id', 'join' );
	}

	if ( ! agend_apps_member_access_can_mint() ) {
		return agend_apps_member_access_with( $base, 'not_configured', 'missing_scope', 'retry' );
	}

	if ( 'sso' === $mode ) {
		return agend_apps_member_access_sso( $base, $user_id, $return_url );
	}

	return agend_apps_member_access_wordpress( $base, $user_id );
}

/**
 * The `sso` branch: the link status is read once, and only because there is no
 * bearer.
 *
 * @param array  $base       The answer so far.
 * @param int    $user_id    WordPress user id.
 * @param string $return_url Where the link flow returns to.
 * @return array
 */
function agend_apps_member_access_sso( array $base, int $user_id, string $return_url ): array {
	if ( ! function_exists( 'agend_apps_account_link_state' ) ) {
		return agend_apps_member_access_with( $base, 'unavailable', 'link_check_failed', 'retry' );
	}

	$link = agend_apps_account_link_state( $user_id, $return_url );

	switch ( (string) ( $link['state'] ?? '' ) ) {
		case 'unlinked':
			$base = agend_apps_member_access_with( $base, 'not_linked', 'sso_unlinked', '' !== (string) ( $link['initiate_url'] ?? '' ) ? 'connect' : 'contact' );
			$base['initiate_url'] = (string) ( $link['initiate_url'] ?? '' );

			return $base;

		case 'no_external_id':
			return agend_apps_member_access_with( $base, 'no_member_record', 'no_external_id', 'join' );

		case 'error':
			if ( 'missing_scope' === (string) ( $link['reason'] ?? '' ) ) {
				return agend_apps_member_access_with( $base, 'not_configured', 'missing_scope', 'retry' );
			}

			return agend_apps_member_access_with( $base, 'unavailable', 'link_check_failed', 'retry' );

		case 'linked':
			// Linked at Agend, yet no token: the mint failed just now.
			return agend_apps_member_access_with( $base, 'unavailable', 'token_unavailable', 'retry' );

		default:
			return agend_apps_member_access_with( $base, 'unavailable', 'link_check_failed', 'retry' );
	}
}

/**
 * The `wordpress` branch: the link was made by a background round trip after
 * sign-in and its outcome is recorded per user, so nothing is asked of Agend.
 *
 * @param array $base    The answer so far.
 * @param int   $user_id WordPress user id.
 * @return array
 */
function agend_apps_member_access_wordpress( array $base, int $user_id ): array {
	// The recorded-state reader is only loaded in this mode.
	$recorded = function_exists( 'agend_apps_wp_idp_link_state' ) ? agend_apps_wp_idp_link_state( $user_id ) : array();
	$state    = (string) ( $recorded['state'] ?? '' );

	switch ( $state ) {
		case 'pending':
			return agend_apps_member_access_with( $base, 'not_linked', 'link_pending', 'check_email' );

		case 'conflict':
			return agend_apps_member_access_with( $base, 'not_linked', 'link_conflict', 'contact' );

		case 'no_contact':
			return agend_apps_member_access_with( $base, 'no_member_record', 'link_no_contact', 'join' );

		case 'forbidden':
			return agend_apps_member_access_with( $base, 'not_configured', 'link_forbidden', 'contact' );

		case 'pending_approval':
			return agend_apps_member_access_with( $base, 'not_configured', 'link_pending_approval', 'contact' );

		case 'linked':
			// Recorded as linked, yet no token: the mint failed just now.
			return agend_apps_member_access_with( $base, 'unavailable', 'token_unavailable', 'retry' );

		case '':
		case 'asserted':
		case 'error':
			// Normal on a first sign-in: the round trip starts on the first page
			// load and completes on a later one, and a recorded error is retried
			// by the link flow itself.
			$base = agend_apps_member_access_with( $base, 'connecting', '' === $state ? 'link_not_started' : 'link_' . $state, 'retry' );
			$base['retry_after'] = 3;

			return $base;

		default:
			return agend_apps_member_access_with( $base, 'unavailable', 'link_check_failed', 'retry' );
	}
}

/**
 * Turns a failed read into the access state it means, on top of the state the
 * resolver gave (which was `ready`, or the read would not have been made).
 *
 * Order matters: the API client's own refusals come first, because a key that
 * lacks a scope for a `/me` route surfaces as a 401 after the client's
 * unattended retry, and would otherwise read as an expired session.
 *
 * - `agend_apps_scope_missing`, `agend_apps_app_not_allowed`: `not_configured`.
 * - 404: `no_member_record` (the member's contact does not exist).
 * - 401: the stored sign-in was refused. `credentials` mode: `not_linked`,
 *   `session_expired`, sign in again. The other modes: `unavailable`, retry.
 * - 429 or `agend_apps_rate_limited`: `unavailable`, reason `busy`, with
 *   `retry_after` from the reset time when there is one.
 * - anything else: `unavailable`, reason `gateway_error`.
 *
 * @param WP_Error|mixed $error  The failed read.
 * @param array          $access The resolver's answer for this request.
 * @return array The answer with the classified state.
 */
function agend_apps_member_access_from_error( $error, array $access ): array {
	$access = $access + agend_apps_member_access_blank( agend_apps_member_access_mode() );

	if ( ! is_wp_error( $error ) ) {
		return agend_apps_member_access_with( $access, 'unavailable', 'gateway_error', 'retry' );
	}

	$code = (string) $error->get_error_code();
	$data = $error->get_error_data();
	$data = is_array( $data ) ? $data : array();

	if ( 'agend_apps_scope_missing' === $code ) {
		$missing = isset( $data['missing'] ) && is_array( $data['missing'] ) ? implode( ', ', array_map( 'strval', $data['missing'] ) ) : '';

		return agend_apps_member_access_with( $access, 'not_configured', 'missing_scope', 'retry', '' !== $missing ? sprintf( /* translators: %s: scope names. */ __( 'Missing: %s.', 'agend-apps-core' ), $missing ) : '' );
	}

	if ( 'agend_apps_app_not_allowed' === $code ) {
		return agend_apps_member_access_with( $access, 'not_configured', 'app_not_allowed', 'retry' );
	}

	$status = isset( $data['status_code'] ) ? (int) $data['status_code'] : 0;

	if ( 'agend_apps_rate_limited' === $code || 429 === $status ) {
		$access = agend_apps_member_access_with( $access, 'unavailable', 'busy', 'retry' );
		$reset  = isset( $data['reset'] ) && is_numeric( $data['reset'] ) ? (int) $data['reset'] - time() : 0;

		$access['retry_after'] = $reset > 0 ? min( $reset, 300 ) : 30;

		return $access;
	}

	if ( 404 === $status ) {
		return agend_apps_member_access_with( $access, 'no_member_record', 'contact_not_found', 'join' );
	}

	if ( 401 === $status ) {
		if ( 'credentials' === $access['mode'] ) {
			return agend_apps_member_access_with( $access, 'not_linked', 'session_expired', 'sign_in_again' );
		}

		return agend_apps_member_access_with( $access, 'unavailable', 'session_expired', 'retry' );
	}

	return agend_apps_member_access_with( $access, 'unavailable', 'gateway_error', 'retry' );
}

/**
 * What a member may be told for an access state: a title and one plain
 * sentence, chosen only from the state and the action, never from the reason
 * or from anything the gateway said.
 *
 * Generic on purpose, so any surface can show it as it stands. A surface with
 * its own voice overrides the wording it cares about; it must keep to the same
 * rule of saying nothing a member cannot act on.
 *
 * @param array $access An answer from {@see agend_apps_member_access_state()}
 *                      or {@see agend_apps_member_access_from_error()}.
 * @return array{title: string, message: string}
 */
function agend_apps_member_access_message( array $access ): array {
	$state  = (string) ( $access['state'] ?? '' );
	$action = (string) ( $access['action'] ?? '' );
	$reason = (string) ( $access['reason'] ?? '' );

	switch ( $state ) {
		case 'signed_out':
			return array(
				'title'   => __( 'Sign in to continue', 'agend-apps-core' ),
				'message' => __( 'Sign in to see your own details.', 'agend-apps-core' ),
			);

		case 'connecting':
			return array(
				'title'   => __( 'Connecting your membership', 'agend-apps-core' ),
				'message' => __( 'This can take a few seconds the first time you sign in.', 'agend-apps-core' ),
			);

		case 'no_member_record':
			return array(
				'title'   => __( 'We could not find a membership for this account', 'agend-apps-core' ),
				'message' => __( 'If you are a member, contact us and we will match your account. If not, you can join.', 'agend-apps-core' ),
			);

		case 'not_linked':
			if ( 'sign_in_again' === $action ) {
				return array(
					'title'   => __( 'Sign in again', 'agend-apps-core' ),
					'message' => __( 'Your sign-in has run out. Sign out and sign in again to see your details.', 'agend-apps-core' ),
				);
			}

			if ( 'connect' === $action ) {
				return array(
					'title'   => __( 'Connect your membership', 'agend-apps-core' ),
					'message' => __( 'Connect your account to your membership to see your details here.', 'agend-apps-core' ),
				);
			}

			if ( 'check_email' === $action ) {
				return array(
					'title'   => __( 'Check your email', 'agend-apps-core' ),
					'message' => __( 'We sent you an email to confirm your membership link. Follow it, then come back to this page.', 'agend-apps-core' ),
				);
			}

			return array(
				'title'   => __( 'We cannot load your membership right now', 'agend-apps-core' ),
				'message' => __( 'Please contact us and we will sort it out.', 'agend-apps-core' ),
			);

		case 'not_configured':
			if ( 'contact' === $action ) {
				return array(
					'title'   => __( 'We cannot load your membership right now', 'agend-apps-core' ),
					'message' => __( 'Please contact us and we will sort it out.', 'agend-apps-core' ),
				);
			}

			return array(
				'title'   => __( 'Your membership is not available right now', 'agend-apps-core' ),
				'message' => __( 'Please try again later.', 'agend-apps-core' ),
			);

		default:
			if ( 'busy' === $reason ) {
				return array(
					'title'   => __( 'We are busy right now', 'agend-apps-core' ),
					'message' => __( 'Please try again in a moment.', 'agend-apps-core' ),
				);
			}

			return array(
				'title'   => __( 'We cannot load your membership right now', 'agend-apps-core' ),
				'message' => __( 'Please try again in a moment.', 'agend-apps-core' ),
			);
	}
}
