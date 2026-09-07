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
 * Whether a decoded gateway response or a gateway error means the caller must
 * verify their email before a session is issued (SPEC-CORE-20260907
 * Decision 2.1, US-4.1 AC1).
 *
 * `Agend_Apps_API::request()` deliberately does not carry the upstream HTTP
 * status code onto a 2xx decoded response (its own 2xx handling is out of
 * scope for this story), so a login 202 is identified structurally: only
 * the withheld-session `{ status: 'verification_required', message }` body
 * carries a `status` key at all, a genuine 200 login response never does.
 *
 * @param array|WP_Error $response Decoded response (from login()) or a gateway
 *                                 error (from login() or register()).
 * @return bool
 */
function agend_apps_auth_response_is_verification_required( $response ): bool {
	if ( is_wp_error( $response ) ) {
		return 'VERIFICATION_EMAIL_UNAVAILABLE' === agend_apps_auth_error_code( $response );
	}

	if ( ! is_array( $response ) ) {
		return false;
	}

	$data = ( isset( $response['data'] ) && is_array( $response['data'] ) ) ? $response['data'] : $response;

	return isset( $data['status'] ) && 'verification_required' === $data['status'];
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
	 * Filters the decoded login response before it is returned.
	 *
	 * @param array  $response Decoded response body.
	 * @param string $email    User email address.
	 */
	return apply_filters( 'agend_apps_auth_login_response', $response, $email );
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
	 * Filters the decoded register response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $payload  Registration payload.
	 */
	return apply_filters( 'agend_apps_auth_register_response', $response, $payload );
}

/**
 * Refreshes an authentication session using a refresh token.
 *
 * Scope: `auth.sessions.refresh`.
 *
 * @param string $refresh_token Valid refresh token from a previous session.
 * @return array|WP_Error Decoded response with new session on success, or WP_Error on failure.
 */
function agend_apps_auth_refresh( string $refresh_token ) {
	/**
	 * Filters the refresh request args before the request is sent.
	 *
	 * @param array  $args           Request args.
	 * @param string $refresh_token Refresh token.
	 */
	$args = (array) apply_filters(
		'agend_apps_auth_refresh_args',
		array(
			'body' => array(
				'refresh_token' => $refresh_token,
			),
		),
		$refresh_token
	);

	$response = agend_apps_api()->request( 'POST', '/auth/refresh', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded refresh response before it is returned.
	 *
	 * @param array  $response      Decoded response body.
	 * @param string $refresh_token Refresh token.
	 */
	return apply_filters( 'agend_apps_auth_refresh_response', $response, $refresh_token );
}

/**
 * Logs out the current authenticated user.
 *
 * Scope: `auth.sessions.delete`. Requires a member bearer token.
 *
 * @return array|WP_Error Decoded confirmation response on success, or WP_Error on failure.
 */
function agend_apps_auth_logout() {
	/**
	 * Filters the logout request args before the request is sent.
	 *
	 * @param array $args Request args.
	 */
	$args = (array) apply_filters( 'agend_apps_auth_logout_args', array() );

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
