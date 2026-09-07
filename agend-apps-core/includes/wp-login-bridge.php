<?php
/**
 * Agend-first authentication for the WordPress login flow.
 *
 * Lets a member sign in at wp-login.php (or any surface that calls
 * `wp_signon()` / `wp_authenticate()`) with their Agend credentials: the
 * submitted email and password are checked against the Agend gateway FIRST.
 *
 * Agend is the credential authority (SPEC-CORE-20260907 Decisions 2.1, 2.2).
 * When the gateway rejects the credentials and a WordPress user with that
 * email exists, the bridge registers a dashboard account with the submitted
 * credentials; a 409 (the email already has a dashboard account, so the typed
 * password is a WordPress-only one) fails the login with the generic
 * incorrect-credentials message instead of falling through to WordPress
 * authentication. WordPress's own authentication runs only when the gateway
 * is unavailable, the username is not an email, or no WordPress user with
 * that email exists.
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
 * Never returns a `WP_Error` itself: WordPress's own handlers at priority 20
 * ignore an incoming error when a username and password are present and
 * authenticate against the WordPress password regardless, so a refusal raised
 * here would be overridden. The one refusal this bridge makes (the email
 * already has a dashboard account and the submitted password is not its
 * password) is applied by `agend_apps_wp_login_refuse_wordpress_password` at
 * priority 30, after WordPress has run. Every other failure falls through to
 * WordPress authentication, which owns the user-facing error.
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

	// Each authenticate run starts with no refusal armed, so a refusal from an
	// earlier attempt in the same process never leaks into this one.
	agend_apps_wp_login_arm_refusal( '' );

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

	if ( is_wp_error( $response ) ) {
		// No API key configured, gateway down or timing out: WordPress-specific
		// login logic takes over. Only the gateway's invalid-credentials answer
		// continues into registration.
		if ( ! agend_apps_auth_error_is_invalid_credentials( $response ) ) {
			return $user;
		}

		$response = agend_apps_wp_login_register_existing_user( $email, $password );

		if ( null === $response ) {
			return $user;
		}
	}

	$parsed  = agend_apps_auth_response_session( $response );
	$data    = $parsed['data'];
	$session = $parsed['session'];

	if ( empty( $session ) ) {
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

/**
 * Registers a dashboard account for an existing WordPress user whose
 * credentials the gateway rejected (SPEC-CORE-20260907 US-1.1, US-1.2).
 *
 * @param string $email    Submitted email (lower-cased, validated).
 * @param string $password Submitted password.
 * On a 409 the refusal is armed for priority 30 (see
 * `agend_apps_wp_login_refuse_wordpress_password`) and null is returned.
 *
 * @return array|null Decoded register response to continue the login with; null to
 *                    hand the request to the remaining authenticate handlers.
 */
function agend_apps_wp_login_register_existing_user( string $email, string $password ) {
	// An unknown email never creates a dashboard account from the login form:
	// only a person the site already knows is provisioned.
	$existing = get_user_by( 'email', $email );

	if ( ! $existing instanceof WP_User ) {
		return null;
	}

	$response = agend_apps_auth_register(
		agend_apps_provision_register_payload( $email, $password, (string) $existing->first_name, (string) $existing->last_name )
	);

	if ( ! is_wp_error( $response ) ) {
		return $response;
	}

	if ( ! agend_apps_auth_error_is_email_conflict( $response ) ) {
		// Weak password, 5xx, transport: WordPress logic decides.
		return null;
	}

	agend_apps_provision_record_outcome( $existing->ID, AGEND_APPS_PROVISION_CONFLICT );

	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			sprintf( '[Agend Apps] Login refused for user %d: email holds a dashboard account, WordPress password submitted.', $existing->ID )
		);
	}

	agend_apps_wp_login_arm_refusal( $email );

	return null;
}

/**
 * Email whose WordPress-password login is refused for the current request.
 *
 * @param string|null $email Email to arm, or null to read.
 * @return string The armed email ('' when none).
 */
function agend_apps_wp_login_arm_refusal( ?string $email = null ): string {
	static $armed = '';

	if ( null !== $email ) {
		$armed = $email;
	}

	return $armed;
}

/**
 * Converts a WordPress-password authentication into the generic credentials
 * error for an email that already has a dashboard account
 * (SPEC-CORE-20260907 Decision 2.1, US-1.2).
 *
 * Runs at priority 30, after `wp_authenticate_username_password` and
 * `wp_authenticate_email_password` (20), so it sees and overrides the
 * `WP_User` WordPress resolved from its own password. Only fires when the
 * bridge armed a refusal for this exact email during this request.
 *
 * @param null|WP_User|WP_Error $user     Result of earlier authenticate filters.
 * @param string                $username Submitted username or email.
 * @param string                $password Submitted password.
 * @return null|WP_User|WP_Error The generic error when armed, else the incoming value.
 */
function agend_apps_wp_login_refuse_wordpress_password( $user, $username, $password ) {
	unset( $password );

	$armed = agend_apps_wp_login_arm_refusal();

	if ( '' === $armed || strtolower( trim( (string) $username ) ) !== $armed ) {
		return $user;
	}

	return agend_apps_wp_login_generic_error();
}
add_filter( 'authenticate', 'agend_apps_wp_login_refuse_wordpress_password', 30, 3 );

/**
 * The generic incorrect-credentials error. Carries no hint that the email has
 * a dashboard account (SPEC-CORE-20260907 US-1.2; response uniformity).
 *
 * @return WP_Error
 */
function agend_apps_wp_login_generic_error(): WP_Error {
	return new WP_Error(
		'agend_apps_invalid_credentials',
		__( 'The email or password you entered is incorrect.', 'agend-apps-core' )
	);
}
