<?php
/**
 * Agend-first authentication for the WordPress login flow.
 *
 * Lets a member sign in at wp-login.php (or any surface that calls
 * `wp_signon()` / `wp_authenticate()`) with their Agend credentials: the
 * submitted email and password are checked against the Agend gateway FIRST,
 * and WordPress's own authentication only runs when that check does not
 * produce a user (wrong credentials, gateway unavailable, non-email username,
 * or no adoptable WordPress identity).
 *
 * A successful gateway login reuses the exact machinery of the member-login
 * REST proxy (SPEC-CORE-20260722-wordpress-member-login US-1.4): the same
 * identity policy (`agend_apps_member_login_user_id`, which finds-or-creates
 * a managed member user and never adopts an independent WordPress account by
 * email), the same server-side session store, and the same membership
 * snapshot sync — so a wp-login.php sign-in is indistinguishable from a
 * widget sign-in to every other Agend surface.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Attempts an Agend credential login before WordPress authentication.
 *
 * Hooked on `authenticate` at priority 15: WordPress's own handlers
 * (`wp_authenticate_username_password`, `wp_authenticate_email_password`)
 * run at 20 and return early when a `WP_User` has already been resolved, so
 * returning a user here short-circuits the WordPress-specific login logic,
 * and returning the incoming value lets it proceed unchanged.
 *
 * Never returns a `WP_Error`: every failure mode falls through to WordPress
 * authentication, which owns the user-facing error.
 *
 * @param null|WP_User|WP_Error $user     Result of earlier authenticate filters.
 * @param string                $username Submitted username or email.
 * @param string                $password Submitted password.
 * @return null|WP_User|WP_Error The authenticated member's user, or the incoming value untouched.
 */
function agend_apps_wp_login_authenticate( $user, $username, $password ) {
	// A previous filter already resolved a user; nothing to do.
	if ( $user instanceof WP_User ) {
		return $user;
	}

	// Programmatic surfaces (REST application passwords, XML-RPC) authenticate
	// per request; trying the gateway for each would waste a network call and
	// consume the member's login-throttle budget. Interactive logins only.
	if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
		return $user;
	}

	$email    = strtolower( trim( (string) $username ) );
	$password = (string) $password;

	// The gateway authenticates by email; a non-email username belongs to
	// WordPress.
	if ( '' === $email || '' === $password || ! is_email( $email ) ) {
		return $user;
	}

	/**
	 * Filters whether the WordPress login flow tries Agend credentials first.
	 *
	 * @param bool   $enabled Default true.
	 * @param string $email   The submitted email (lower-cased).
	 */
	if ( ! apply_filters( 'agend_apps_wp_login_bridge_enabled', true, $email ) ) {
		return $user;
	}

	// Same proxy-edge throttle as the member-login REST proxy (per IP and per
	// email). When throttled, skip the gateway attempt; WordPress
	// authentication and its own protections still run.
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

	if ( ! agend_apps_auth_login_throttle( $email, $ip ) ) {
		return $user;
	}

	$response = agend_apps_auth_login( $email, $password );

	// Wrong credentials, no API key configured, gateway down or timing out:
	// WordPress-specific login logic takes over.
	if ( is_wp_error( $response ) ) {
		return $user;
	}

	$data    = ( isset( $response['data'] ) && is_array( $response['data'] ) ) ? $response['data'] : $response;
	$session = ( isset( $data['session'] ) && is_array( $data['session'] ) ) ? $data['session'] : array();

	if ( empty( $session['access_token'] ) || empty( $session['refresh_token'] ) ) {
		return $user;
	}

	// Capture the guest cart BEFORE establishing the member identity, exactly
	// as the REST login does, so a guest cart with items follows the member.
	$guest_cart_token = function_exists( 'agend_apps_login_guest_cart_with_items' )
		? agend_apps_login_guest_cart_with_items()
		: '';

	/**
	 * This filter is documented in includes/rest/auth-routes.php.
	 *
	 * The user id passed is 0 (never the current user): on a login form the
	 * submitted credentials name the account to sign in to, so the identity
	 * must resolve from the authenticated email, not from whoever might
	 * already hold a WordPress session in this browser.
	 */
	$user_id = (int) apply_filters(
		'agend_apps_member_login_user_id',
		0,
		$email,
		$data,
		new WP_REST_Request( 'POST', '/agend-apps/v1/auth/login' )
	);

	// No adoptable WordPress identity (for example the email matches an
	// independent, unmanaged WordPress account): WordPress logic decides.
	if ( 0 === $user_id ) {
		return $user;
	}

	Agend_Apps_Member_Session::store( $user_id, $session );

	// A credential login supersedes any negative-cached SSO mint state.
	Agend_Apps_Token_Worker::clear_negative_cache( $user_id );

	// Transfer the guest cart onto the now-authenticated member. Best effort:
	// a failure here never breaks the sign-in.
	if ( '' !== $guest_cart_token && function_exists( 'agend_apps_cart_transfer' ) ) {
		$transfer = agend_apps_cart_transfer( $guest_cart_token, agend_apps_get_bearer_token() );
		if ( ! is_wp_error( $transfer ) ) {
			setcookie( 'agend_cart_session', '', array( 'expires' => time() - HOUR_IN_SECONDS, 'path' => '/' ) );
		}
	}

	// Refresh the membership snapshot usermeta (content-restriction standing)
	// with the fresh bearer. Best effort: a gateway hiccup here keeps the last
	// known snapshot and never breaks the sign-in.
	agend_apps_member_sync_membership_meta( $user_id );

	$wp_user = get_user_by( 'id', $user_id );

	return ( $wp_user instanceof WP_User ) ? $wp_user : $user;
}
add_filter( 'authenticate', 'agend_apps_wp_login_authenticate', 15, 3 );
