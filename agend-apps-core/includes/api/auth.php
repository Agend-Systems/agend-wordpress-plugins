<?php
/**
 * Authentication API functions.
 *
 * Server-side PHP wrappers for the Agend gateway's `/v1/auth/*` endpoints.
 * Sibling plugins MUST call these helpers rather than building gateway paths
 * or calling `agend_apps_api()` directly, so transport and path knowledge stay
 * centralised here.
 *
 * Auth endpoints are never cached. All endpoints are POST (write/action).
 * Member-only endpoints rely on the Supabase bearer token resolved
 * by `agend_apps_get_bearer_token()` and attached automatically by the client.
 *
 * Every function returns the decoded response array on success or a WP_Error
 * on failure (transport error, non-2xx, or invalid JSON).
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the upstream HTTP status from a gateway `WP_Error`.
 *
 * Moved here from `includes/member-provisioning.php` (which this file's
 * bootstrap require predates) so the WordPress-IdP link step
 * (`includes/wp-idp-link.php`, docs/PLAN-wordpress-idp-option-b.md section
 * 4.2) can read it in `wordpress` mode, where `member-provisioning.php` is
 * never loaded (SPEC-CORE-20260907 US-4.1 gates it to `credentials` mode
 * only). This file is loaded unconditionally, so it is the correct shared
 * home; the `agend_apps_auth_*` naming already matched before the move.
 *
 * @param WP_Error $error Gateway error.
 * @return int Status code, or 0 when the error carries none (transport failure).
 */
function agend_apps_auth_error_status( WP_Error $error ): int {
	$data = $error->get_error_data();

	return ( is_array( $data ) && isset( $data['status_code'] ) ) ? (int) $data['status_code'] : 0;
}

/**
 * Reads the gateway error code (`error.code` in the response envelope) from a
 * gateway `WP_Error`.
 *
 * Moved here from `includes/member-provisioning.php`; see
 * {@see agend_apps_auth_error_status()} for why.
 *
 * @param WP_Error $error Gateway error.
 * @return string Upper-case gateway code, or '' when absent.
 */
function agend_apps_auth_error_code( WP_Error $error ): string {
	$data = $error->get_error_data();

	if ( ! is_array( $data ) || ! isset( $data['body']['error']['code'] ) ) {
		return '';
	}

	return strtoupper( (string) $data['body']['error']['code'] );
}

/**
 * Reads the gateway error detail code (`error.details.code`) from a gateway
 * `WP_Error`. Some gateway errors carry their specific reason here under a
 * generic top-level code (a `BAD_REQUEST` whose detail is
 * `CONTACT_ALREADY_LINKED`).
 *
 * Moved here from `includes/member-provisioning.php`; see
 * {@see agend_apps_auth_error_status()} for why.
 *
 * @param WP_Error $error Gateway error.
 * @return string Upper-case detail code, or '' when absent.
 */
function agend_apps_auth_error_detail_code( WP_Error $error ): string {
	$data = $error->get_error_data();

	if ( ! is_array( $data ) || ! isset( $data['body']['error']['details']['code'] ) ) {
		return '';
	}

	return strtoupper( (string) $data['body']['error']['details']['code'] );
}

/**
 * Whether a decoded gateway response or a gateway error means the caller must
 * verify their email before a session is issued (SPEC-CORE-20260907
 * Decision 2.1, US-4.1 AC1; Decision change B).
 *
 * `Agend_Apps_API::request()` carries the upstream HTTP status onto the
 * decoded array as `status_code`. The body status distinguishes email
 * verification from the MFA 202; a response with no status code may still be
 * recognised by its body after a filter has stripped the HTTP status.
 *
 * @param array|WP_Error $response Decoded response (from login()) or a gateway
 *                                 error (from login() or register()).
 * @return bool
 */
function agend_apps_auth_response_is_verification_required( $response ): bool {
	if ( is_wp_error( $response ) ) {
		return 503 === agend_apps_auth_error_status( $response )
			&& 'VERIFICATION_EMAIL_UNAVAILABLE' === agend_apps_auth_error_code( $response );
	}

	if ( ! is_array( $response ) ) {
		return false;
	}

	$data = ( isset( $response['data'] ) && is_array( $response['data'] ) ) ? $response['data'] : $response;

	return ( ! isset( $response['status_code'] ) || 202 === (int) $response['status_code'] )
		&& isset( $data['status'] ) && 'verification_required' === $data['status'];
}

/** Whether the gateway is requesting a second factor instead of issuing a session. */
function agend_apps_auth_response_is_mfa_required( $response ): bool {
	if ( ! is_array( $response ) ) {
		return false;
	}
	$data = ( isset( $response['data'] ) && is_array( $response['data'] ) ) ? $response['data'] : $response;
	return ( ! isset( $response['status_code'] ) || 202 === (int) $response['status_code'] )
		&& isset( $data['status'], $data['mfa_token'] )
		&& 'mfa_required' === $data['status'] && is_string( $data['mfa_token'] ) && '' !== $data['mfa_token'];
}

/** Store only the gateway token and identity needed after code verification. */
function agend_apps_auth_create_mfa_challenge( array $response, string $email, bool $remember = false ): array {
	$data = ( isset( $response['data'] ) && is_array( $response['data'] ) ) ? $response['data'] : $response;
	$id   = bin2hex( random_bytes( 32 ) );
	$factors = array_values( array_filter( isset( $data['factors'] ) && is_array( $data['factors'] ) ? $data['factors'] : array(), static function ( $factor ) {
		return is_array( $factor ) && isset( $factor['id'], $factor['factor_type'] ) && 'totp' === $factor['factor_type'] && is_string( $factor['id'] );
	} ) );
	set_transient( 'agend_apps_mfa_' . $id, array( 'mfa_token' => $data['mfa_token'], 'email' => $email, 'factors' => $factors, 'attempts' => 0, 'created_at' => time(), 'remember' => $remember ), 5 * MINUTE_IN_SECONDS );
	return array( 'challenge_id' => $id, 'factors' => $factors );
}

/** Read the server-side challenge; callers must never return its token. */
function agend_apps_auth_get_mfa_challenge( string $id ) {
	return preg_match( '/^[a-f0-9]{64}$/D', $id ) ? get_transient( 'agend_apps_mfa_' . $id ) : false;
}

/** Exchange a challenge and six-digit code for the normal login response. */
function agend_apps_auth_verify_mfa_challenge( string $id, string $factor_id, string $code ) {
	if ( ! preg_match( '/^[a-f0-9]{64}$/D', $id ) || ! preg_match( '/^[0-9]{6}$/D', $code ) ) {
		return new WP_Error( 'agend_apps_invalid_mfa_code', __( 'Enter a valid six-digit code.', 'agend-apps-core' ) );
	}
	$challenge = agend_apps_auth_get_mfa_challenge( $id );
	if ( ! is_array( $challenge ) || empty( $challenge['mfa_token'] ) || empty( $challenge['email'] ) ) {
		return new WP_Error( 'agend_apps_mfa_expired', __( 'Your code step has expired. Please sign in again.', 'agend-apps-core' ) );
	}
	if ( (int) ( $challenge['attempts'] ?? 0 ) >= 5 ) {
		delete_transient( 'agend_apps_mfa_' . $id );
		return new WP_Error( 'agend_apps_mfa_expired', __( 'Your code step has expired. Please sign in again.', 'agend-apps-core' ) );
	}
	$valid = false;
	foreach ( $challenge['factors'] as $factor ) {
		if ( hash_equals( (string) $factor['id'], $factor_id ) ) {
			$valid = true;
			break;
		}
	}
	if ( ! $valid ) {
		return new WP_Error( 'agend_apps_invalid_mfa_factor', __( 'Choose a valid authenticator.', 'agend-apps-core' ) );
	}
	$response = agend_apps_api()->request( 'POST', '/auth/mfa/verify', array( 'body' => array( 'mfa_token' => $challenge['mfa_token'], 'factor_id' => $factor_id, 'code' => $code ) ) );
	if ( ! is_wp_error( $response ) && ! empty( agend_apps_auth_response_session( $response )['session'] ) ) {
		delete_transient( 'agend_apps_mfa_' . $id );
	} else {
		if ( is_wp_error( $response ) && ( 0 === agend_apps_auth_error_status( $response ) || 429 === agend_apps_auth_error_status( $response ) || agend_apps_auth_error_status( $response ) >= 500 ) ) {
			return $response;
		}
		$challenge['attempts'] = (int) ( $challenge['attempts'] ?? 0 ) + 1;
		$code = is_wp_error( $response ) ? agend_apps_auth_error_code( $response ) : '';
		$token_rejected = in_array( $code, array( 'INVALID_MFA_TOKEN', 'MFA_TOKEN_EXPIRED', 'MFA_TOKEN_EXHAUSTED' ), true )
			|| ( is_wp_error( $response ) && 401 === agend_apps_auth_error_status( $response ) && ! in_array( $code, array( 'INVALID_MFA_CODE', 'INVALID_TOTP_CODE' ), true ) );
		if ( $challenge['attempts'] >= 5 || $token_rejected ) {
			delete_transient( 'agend_apps_mfa_' . $id );
		} else {
			$remaining = max( 1, 5 * MINUTE_IN_SECONDS - ( time() - (int) ( $challenge['created_at'] ?? time() ) ) );
			set_transient( 'agend_apps_mfa_' . $id, $challenge, $remaining );
		}
	}
	return $response;
}

/**
 * Logs in a user with email and password.
 *
 * Scope: `auth.sessions.create`.
 *
 * @param string $email    User email address.
 * @param string $password User password.
 * @return array|WP_Error Decoded response with user, contact, and session on success, or WP_Error on failure.
 */
function agend_apps_auth_login( string $email, string $password ) {
	/**
	 * Filters the login request args before the request is sent.
	 *
	 * @param array  $args     Request args.
	 * @param string $email    User email address.
	 * @param string $password User password.
	 */
	$args = (array) apply_filters(
		'agend_apps_auth_login_args',
		array(
			'body' => array(
				'email'    => $email,
				'password' => $password,
			),
		),
		$email,
		$password
	);

	$response = agend_apps_api()->request( 'POST', '/auth/login', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded login response before it is returned. The filter
	 * receives a copy without the session's access and refresh tokens, and
	 * cannot change the session: see {@see agend_apps_auth_filter_without_tokens()}.
	 *
	 * @param array  $response Decoded response body, session tokens removed.
	 * @param string $email    User email address.
	 */
	return agend_apps_auth_filter_without_tokens( 'agend_apps_auth_login_response', $response, $email );
}

/**
 * Registers a new user account.
 *
 * Scope: `auth.users.register`.
 *
 * @param array $payload Registration payload (snake_case) forwarded as the request body.
 *                       Keys: `email`, `password`, `contact` (optional), `redirect_to` (optional),
 *                       `create_contact` (optional), `invitation_token` (optional).
 * @return array|WP_Error Decoded response with user, contact (nullable), session, and pending_email_confirmation on success, or WP_Error on failure.
 */
function agend_apps_auth_register( array $payload ) {
	/**
	 * Filters the register request args before the request is sent.
	 *
	 * @param array $args    Request args.
	 * @param array $payload Registration payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_auth_register_args',
		array( 'body' => $payload ),
		$payload
	);

	$response = agend_apps_api()->request( 'POST', '/auth/register', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded register response before it is returned. The filter
	 * receives a copy without the session's access and refresh tokens, and
	 * cannot change the session: see {@see agend_apps_auth_filter_without_tokens()}.
	 *
	 * @param array $response Decoded response body, session tokens removed.
	 * @param array $payload  Registration payload.
	 */
	return agend_apps_auth_filter_without_tokens( 'agend_apps_auth_register_response', $response, $payload );
}

/**
 * Refreshes an authentication session using a refresh token.
 *
 * Scope: `auth.sessions.refresh`.
 *
 * Always sent unattended: the refresh token is the credential, and no other
 * user's bearer may ride the call (an admin viewing a member's profile would
 * otherwise attach their own).
 *
 * @param string $refresh_token Valid refresh token from a previous session.
 * @return array|WP_Error Decoded response with new session on success, or WP_Error on failure.
 */
function agend_apps_auth_refresh( string $refresh_token ) {
	/**
	 * Filters the refresh request args before the request is sent. The args
	 * carry no body: the refresh token is added after the filter, so it never
	 * reaches a filter and cannot be rewritten.
	 *
	 * @param array  $args          Request args.
	 * @param string $refresh_token Always ''. Kept so existing two-argument callbacks still run; the token is never passed to a filter.
	 */
	$args = (array) apply_filters( 'agend_apps_auth_refresh_args', array(), '' );

	$args['body']       = array( 'refresh_token' => $refresh_token );
	$args['unattended'] = true;
	unset( $args['bearer_token'] );

	$response = agend_apps_api()->request( 'POST', '/auth/refresh', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded refresh response before it is returned. The filter
	 * receives a copy without the session's access and refresh tokens, and
	 * cannot change the session: see {@see agend_apps_auth_filter_without_tokens()}.
	 *
	 * @param array  $response      Decoded response body, session tokens removed.
	 * @param string $refresh_token Always ''. Kept so existing two-argument callbacks still run.
	 */
	return agend_apps_auth_filter_without_tokens( 'agend_apps_auth_refresh_response', $response, '' );
}

/**
 * Runs a public response filter over a copy of a gateway auth response with
 * the session's access and refresh tokens removed, then puts the original
 * session back. A filter is extension surface: a plugin hooking it to log or
 * inspect a response must never receive a member's session, which is
 * redeemable at Supabase Auth without the gateway.
 *
 * A filter may still change every other field, or return a `WP_Error` to
 * refuse the response.
 *
 * @param string $hook     Filter name.
 * @param mixed  $response Decoded gateway response.
 * @param mixed  ...$args  Further filter arguments.
 * @return mixed The filtered response, carrying the original session.
 */
function agend_apps_auth_filter_without_tokens( string $hook, $response, ...$args ) {
	if ( ! is_array( $response ) ) {
		return apply_filters( $hook, $response, ...$args );
	}

	$enveloped = isset( $response['data'] ) && is_array( $response['data'] );
	$data      = $enveloped ? $response['data'] : $response;
	$session   = ( isset( $data['session'] ) && is_array( $data['session'] ) ) ? $data['session'] : null;

	if ( null === $session ) {
		return apply_filters( $hook, $response, ...$args );
	}

	$data['session'] = array_diff_key( $session, array_flip( array( 'access_token', 'refresh_token' ) ) );
	$redacted        = $enveloped ? array_merge( $response, array( 'data' => $data ) ) : $data;

	$filtered = apply_filters( $hook, $redacted, ...$args );

	if ( ! is_array( $filtered ) ) {
		return $filtered;
	}

	if ( $enveloped ) {
		$filtered['data']            = ( isset( $filtered['data'] ) && is_array( $filtered['data'] ) ) ? $filtered['data'] : array();
		$filtered['data']['session'] = $session;
	} else {
		$filtered['session'] = $session;
	}

	return $filtered;
}

/**
 * Logs a member out at the gateway, which revokes every session they hold.
 *
 * Scope: `auth.sessions.delete`. Requires a member bearer token.
 *
 * @param string $bearer_token The member's access token. '' resolves the
 *                             current user's bearer, as before; pass the
 *                             token explicitly when the member is not the
 *                             current user (a deletion by an admin, or a
 *                             `wp_logout` that has already reset the user).
 * @return array|WP_Error Decoded confirmation response on success, or WP_Error on failure.
 */
function agend_apps_auth_logout( string $bearer_token = '' ) {
	/**
	 * Filters the logout request args before the request is sent. An explicit
	 * bearer is added after the filter, so it never reaches a filter.
	 *
	 * @param array $args Request args.
	 */
	$args = (array) apply_filters( 'agend_apps_auth_logout_args', array() );

	if ( '' !== $bearer_token ) {
		$args['bearer_token'] = $bearer_token;
		unset( $args['unattended'] );
	}

	$response = agend_apps_api()->request( 'POST', '/auth/logout', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded logout response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 */
	return apply_filters( 'agend_apps_auth_logout_response', $response );
}

/**
 * Requests a single-use portal sign-in link for the current member.
 *
 * Scope: `auth.sessions.handoff`. Requires a member bearer token (attached
 * automatically from `agend_apps_get_bearer_token()`). The gateway mints a
 * single-use magic-link for the bearer user and returns a portal
 * `/auth/confirm` URL; following it establishes the shared Agend session and
 * lands the member on the portal.
 *
 * @return array|WP_Error Decoded response with `url` on success, or WP_Error on failure.
 */
function agend_apps_auth_session_handoff() {
	/**
	 * Filters the session-handoff request args before the request is sent.
	 *
	 * @param array $args Request args.
	 */
	$args = (array) apply_filters( 'agend_apps_auth_session_handoff_args', array() );

	$response = agend_apps_api()->request( 'POST', '/auth/session-handoff', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded session-handoff response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 */
	return apply_filters( 'agend_apps_auth_session_handoff_response', $response );
}

/**
 * Initiates a forgot-password flow.
 *
 * Scope: `auth.passwords.reset`.
 *
 * @param array $payload Password reset request payload (snake_case) forwarded as the request body.
 *                       Keys: `email`, `redirect_to` (optional).
 * @return array|WP_Error Decoded confirmation response on success, or WP_Error on failure.
 */
function agend_apps_auth_forgot_password( array $payload ) {
	/**
	 * Filters the forgot-password request args before the request is sent.
	 *
	 * @param array $args    Request args.
	 * @param array $payload Forgot-password payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_auth_forgot_password_args',
		array( 'body' => $payload ),
		$payload
	);

	$response = agend_apps_api()->request( 'POST', '/auth/forgot-password', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded forgot-password response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $payload  Forgot-password payload.
	 */
	return apply_filters( 'agend_apps_auth_forgot_password_response', $response, $payload );
}

/**
 * Resets a user password using a reset token.
 *
 * Scope: `auth.passwords.reset`.
 *
 * @param array $payload Password reset payload (snake_case) forwarded as the request body.
 *                       Keys: `email`, `token`, `new_password`.
 * @return array|WP_Error Decoded confirmation response on success, or WP_Error on failure.
 */
function agend_apps_auth_reset_password( array $payload ) {
	/**
	 * Filters the reset-password request args before the request is sent.
	 *
	 * @param array $args    Request args.
	 * @param array $payload Reset-password payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_auth_reset_password_args',
		array( 'body' => $payload ),
		$payload
	);

	$response = agend_apps_api()->request( 'POST', '/auth/reset-password', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded reset-password response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $payload  Reset-password payload.
	 */
	return apply_filters( 'agend_apps_auth_reset_password_response', $response, $payload );
}

/**
 * Changes the current user's password.
 *
 * Scope: `auth.passwords.update`. Requires a member bearer token.
 *
 * @param array $payload Change-password payload (snake_case) forwarded as the request body.
 *                       Keys: `current_password`, `new_password`.
 * @return array|WP_Error Decoded confirmation response on success, or WP_Error on failure.
 */
function agend_apps_auth_change_password( array $payload ) {
	/**
	 * Filters the change-password request args before the request is sent.
	 *
	 * @param array $args    Request args.
	 * @param array $payload Change-password payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_auth_change_password_args',
		array( 'body' => $payload ),
		$payload
	);

	$response = agend_apps_api()->request( 'POST', '/auth/change-password', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded change-password response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $payload  Change-password payload.
	 */
	return apply_filters( 'agend_apps_auth_change_password_response', $response, $payload );
}

/**
 * Requests another ownership verification email for an address
 * (SPEC-CORE-20260907 US-4.2 AC3).
 *
 * Scope: `auth.sessions.create`. The gateway's response is identical whether
 * or not a matching unverified contact exists, so this call never discloses
 * account existence.
 *
 * @param string $email Email address to resend the ownership link to.
 * @return array|WP_Error Decoded confirmation response on success, or WP_Error on failure.
 */
function agend_apps_auth_resend_verification( string $email ) {
	/**
	 * Filters the resend-verification request args before the request is sent.
	 *
	 * @param array  $args  Request args.
	 * @param string $email Email address.
	 */
	$args = (array) apply_filters(
		'agend_apps_auth_resend_verification_args',
		array(
			'body' => array(
				'email' => $email,
			),
		),
		$email
	);

	$response = agend_apps_api()->request( 'POST', '/auth/resend-verification', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded resend-verification response before it is returned.
	 *
	 * @param array  $response Decoded response body.
	 * @param string $email    Email address.
	 */
	return apply_filters( 'agend_apps_auth_resend_verification_response', $response, $email );
}
