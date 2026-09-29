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
 * authentication. A username linked to Agend resolves to its email and goes
 * through the gateway too. WordPress-only users retain their existing local
 * fallback. Agend-linked users may use the local password only when the
 * gateway is genuinely unavailable and they are not known to have MFA.
 *
 * A WordPress login is likewise refused, not allowed through, when the
 * gateway withholds the session for email verification (a login 202 or a
 * register 201 with `pending_email_confirmation`, or the 503
 * `VERIFICATION_EMAIL_UNAVAILABLE` the gateway raises when it cannot even send
 * the ownership link): the WordPress user meta is marked pending and the
 * priority-30 refusal is armed with a verification-specific error, superseding
 * the earlier design that let WordPress authenticate through in this case
 * (SPEC-CORE-20260907-wordpress-email-verification-handling Decision change
 * A, US-4.1).
 *
 * A successful gateway login reuses the exact machinery of the member-login
 * REST proxy (SPEC-CORE-20260722-wordpress-member-login US-1.4): the same
 * identity policy (`agend_apps_member_login_user_id`, which finds-or-creates
 * a managed member user and never adopts an independent WordPress account by
 * email), the same server-side session store, and the same membership
 * snapshot sync — so a wp-login.php sign-in is indistinguishable from a
 * widget sign-in to every other Agend surface.
 *
 * Registration for an existing WordPress user proceeds only after
 * `wp_check_password()` accepts the submitted password against that user's
 * own WordPress password hash (SPEC-CORE-20260907 US-1.1 Decision 2.8):
 * the gateway register call is never the thing that proves the password.
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
 * here would be overridden. The two refusals this bridge makes — the email
 * already has a dashboard account and the submitted password is not its
 * password, and the gateway has withheld the session for email verification —
 * are applied by `agend_apps_wp_login_refuse_wordpress_password` at priority
 * 30, after WordPress has run. An Agend-linked user's local password may be
 * used after a genuine gateway outage only if they are not marked MFA-enrolled.
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
	$email_user = get_user_by( 'email', $email );
	$login_user = get_user_by( 'login', $email );
	// An email address names its email owner for the gateway. A different
	// user's matching login name never overrides that identity.
	$local_user = $email_user instanceof WP_User ? $email_user : $login_user;
	$linked = agend_apps_wp_login_is_agend_linked( $local_user );
	$marked = $local_user instanceof WP_User && get_user_meta( $local_user->ID, 'agend_mfa_enrolled', true );
	// On a collision, a failed gateway answer must not let WordPress's
	// username handler sign in a different Agend-linked user locally.
	$other_linked = $email_user instanceof WP_User && $login_user instanceof WP_User && $email_user->ID !== $login_user->ID
		&& ( agend_apps_wp_login_is_agend_linked( $login_user ) || get_user_meta( $login_user->ID, 'agend_mfa_enrolled', true ) );
	$refuse_local = $linked || $marked || $other_linked;
	if ( $marked || $other_linked ) {
		// A known MFA member must never use the local password, including when
		// the gateway is down, throttled, disabled or a username was submitted.
		agend_apps_wp_login_arm_refusal( $email );
	}

	if ( '' === $email || '' === $password ) {
		return $user;
	}
	if ( ! is_email( $email ) ) {
		if ( ! $linked || ! $local_user instanceof WP_User || ! is_email( $local_user->user_email ) ) {
			return $user;
		}
		$email = strtolower( $local_user->user_email );
	} elseif ( ! $email_user instanceof WP_User && $login_user instanceof WP_User && $linked ) {
		$email = strtolower( $login_user->user_email );
	}

	/**
	 * Filters whether the WordPress login flow tries Agend credentials first.
	 *
	 * @param bool   $enabled Default true.
	 * @param string $email   The submitted email (lower-cased).
	 */
	if ( ! apply_filters( 'agend_apps_wp_login_bridge_enabled', true, $email ) ) {
		if ( $refuse_local ) {
			agend_apps_wp_login_arm_refusal( strtolower( trim( (string) $username ) ) );
		}
		return $user;
	}

	// Same proxy-edge throttle as the member-login REST proxy (per IP and per
	// email). A throttle is not an outage and cannot unlock the local password.
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

	if ( ! agend_apps_auth_login_throttle( $email, $ip ) ) {
		if ( $refuse_local ) {
			agend_apps_wp_login_arm_refusal( strtolower( trim( (string) $username ) ) );
		}
		return $user;
	}

	$response = agend_apps_auth_login( $email, $password );

	if ( is_wp_error( $response ) ) {
		// The gateway withheld the login because verification is outstanding
		// and the ownership email itself could not be sent
		// (SPEC-CORE-20260907 US-4.1 AC1, AC3). Record pending and arm the
		// verification refusal for priority 30, same as a 202 below (Decision
		// change A supersedes the earlier "let WordPress authenticate through"
		// design).
		if ( agend_apps_auth_response_is_verification_required( $response ) ) {
			agend_apps_wp_login_mark_verification_pending( $email );
			agend_apps_wp_login_arm_refusal( strtolower( trim( (string) $username ) ), AGEND_APPS_WP_LOGIN_REFUSAL_VERIFICATION );
			return $user;
		}

		// No API key configured, gateway down or timing out: WordPress-specific
		// login logic takes over. Only the gateway's invalid-credentials answer
		// continues into registration.
		if ( ! agend_apps_auth_error_is_invalid_credentials( $response ) ) {
			$status = agend_apps_auth_error_status( $response );
			if ( $refuse_local && 0 !== $status && $status < 500 ) {
				agend_apps_wp_login_arm_refusal( strtolower( trim( (string) $username ) ) );
			}
			return $user;
		}

		$response = agend_apps_wp_login_register_existing_user( $email, $password );

		if ( null === $response ) {
			if ( $refuse_local ) {
				agend_apps_wp_login_arm_refusal( strtolower( trim( (string) $username ) ) );
			}
			return $user;
		}
	}

	if ( agend_apps_auth_response_is_verification_required( $response ) ) {
		agend_apps_wp_login_mark_verification_pending( $email );
		agend_apps_wp_login_arm_refusal( strtolower( trim( (string) $username ) ), AGEND_APPS_WP_LOGIN_REFUSAL_VERIFICATION );
		return $user;
	}

	if ( agend_apps_auth_response_is_mfa_required( $response ) ) {
		if ( $local_user instanceof WP_User && strtolower( $local_user->user_email ) === $email ) {
			update_user_meta( $local_user->ID, 'agend_mfa_enrolled', '1' );
		}
		$challenge = agend_apps_auth_create_mfa_challenge( $response, $email, ! empty( $_POST['rememberme'] ) );
		agend_apps_wp_login_mfa_step( $challenge );
		agend_apps_wp_login_arm_refusal( strtolower( trim( (string) $username ) ), AGEND_APPS_WP_LOGIN_REFUSAL_MFA );
		return $user;
	}

	return agend_apps_wp_login_complete( $response, $email, $user, false, strtolower( trim( (string) $username ) ), $refuse_local );
}
add_filter( 'authenticate', 'agend_apps_wp_login_authenticate', 15, 3 );

/** Whether a WordPress user has a recorded Agend identity, session or conflict. */
function agend_apps_wp_login_is_agend_linked( $user ): bool {
	if ( ! $user instanceof WP_User ) {
		return false;
	}
	// An identity conflict is set after the gateway says the email already has
	// an Agend account. Its local password must therefore be refused too.
	foreach ( array( '_agend_apps_managed', '_agend_apps_supabase_user_id', '_agend_apps_contact_id', '_agend_apps_member_session', '_agend_apps_verification_pending', '_agend_apps_identity_conflict' ) as $key ) {
		if ( get_user_meta( $user->ID, $key, true ) ) {
			return true;
		}
	}
	return false;
}

/** Complete the bridge's established login steps after a session is issued. */
function agend_apps_wp_login_complete( $response, string $email, $user, bool $mfa_verified, string $identifier = '', bool $refuse_local = true ) {

	$parsed  = agend_apps_auth_response_session( $response );
	$data    = $parsed['data'];
	$session = $parsed['session'];

	if ( empty( $session ) ) {
		if ( ! empty( $data['pending_email_confirmation'] ) ) {
			agend_apps_wp_login_mark_verification_pending( $email );
			agend_apps_wp_login_arm_refusal( '' !== $identifier ? $identifier : $email, AGEND_APPS_WP_LOGIN_REFUSAL_VERIFICATION );
		} elseif ( $refuse_local ) {
			agend_apps_wp_login_arm_refusal( '' !== $identifier ? $identifier : $email );
		}
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

	// A session came back: any earlier verification-pending state is stale
	// (SPEC-CORE-20260907 US-4.1 AC4). Cleared before the fresh session is
	// stored.
	delete_user_meta( $user_id, AGEND_APPS_VERIFICATION_PENDING_META );
	Agend_Apps_Member_Session::store( $user_id, $session );
	if ( $mfa_verified ) {
		update_user_meta( $user_id, 'agend_mfa_enrolled', '1' );
	} else {
		// The gateway issued a session from the password alone, so its current
		// factor state supersedes an enrolment marker from an earlier login.
		delete_user_meta( $user_id, 'agend_mfa_enrolled' );
	}

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
	if ( $wp_user instanceof WP_User ) {
		agend_apps_wp_login_arm_refusal( '' );
	}

	return ( $wp_user instanceof WP_User ) ? $wp_user : $user;
}

/** Hold only the public challenge id and factor names for the next login page render. */
function agend_apps_wp_login_mfa_step( ?array $step = null ): array {
	static $current = array();
	if ( null !== $step ) {
		$current = $step;
	}
	return $current;
}

/** Show a second, nonce-protected form on wp-login.php after password acceptance. */
function agend_apps_wp_login_mfa_message( string $message ): string {
	$step = agend_apps_wp_login_mfa_step();
	if ( empty( $step['challenge_id'] ) || empty( $step['factors'] ) ) {
		return $message;
	}
	$html = '<style>#loginform{display:none}</style><form id="agend-mfa-form" method="post" action="' . esc_url( add_query_arg( 'action', 'agend_mfa', wp_login_url() ) ) . '">';
	$html .= '<p>' . esc_html__( 'Enter the six-digit code from your authenticator app.', 'agend-apps-core' ) . '</p>';
	$html .= '<input type="hidden" name="challenge_id" value="' . esc_attr( $step['challenge_id'] ) . '">';
	$html .= '<input type="hidden" name="agend_mfa_nonce" value="' . esc_attr( wp_create_nonce( 'agend_apps_mfa_' . $step['challenge_id'] ) ) . '">';
	if ( isset( $_REQUEST['redirect_to'] ) && is_string( $_REQUEST['redirect_to'] ) ) {
		$html .= '<input type="hidden" name="redirect_to" value="' . esc_attr( wp_unslash( $_REQUEST['redirect_to'] ) ) . '">';
	}
	if ( 1 === count( $step['factors'] ) ) {
		$html .= '<input type="hidden" name="factor_id" value="' . esc_attr( $step['factors'][0]['id'] ) . '">';
	} else {
		$html .= '<label for="agend-mfa-factor">' . esc_html__( 'Authenticator', 'agend-apps-core' ) . '</label><select id="agend-mfa-factor" name="factor_id">';
		foreach ( $step['factors'] as $factor ) {
			$html .= '<option value="' . esc_attr( $factor['id'] ) . '">' . esc_html( ! empty( $factor['friendly_name'] ) ? $factor['friendly_name'] : __( 'Authenticator app', 'agend-apps-core' ) ) . '</option>';
		}
		$html .= '</select>';
	}
	$html .= '<label for="agend-mfa-code">' . esc_html__( 'Code', 'agend-apps-core' ) . '</label><input id="agend-mfa-code" name="code" type="text" inputmode="numeric" pattern="[0-9]{6}" autocomplete="one-time-code" required>';
	$html .= '<p><button type="submit" class="button button-primary button-large">' . esc_html__( 'Verify code', 'agend-apps-core' ) . '</button></p></form>';
	return $message . $html;
}
add_filter( 'login_message', 'agend_apps_wp_login_mfa_message' );

/** Process the second wp-login.php form. WordPress issues its cookie only here. */
function agend_apps_wp_login_verify_mfa( array $input ) {
	$id = isset( $input['challenge_id'] ) ? sanitize_text_field( wp_unslash( $input['challenge_id'] ) ) : '';
	$nonce = isset( $input['agend_mfa_nonce'] ) ? sanitize_text_field( wp_unslash( $input['agend_mfa_nonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'agend_apps_mfa_' . $id ) ) {
		return new WP_Error( 'agend_apps_mfa_nonce', __( 'Please sign in again.', 'agend-apps-core' ) );
	}
	$challenge = agend_apps_auth_get_mfa_challenge( $id );
	if ( ! is_array( $challenge ) || empty( $challenge['email'] ) ) {
		return agend_apps_wp_login_generic_error();
	}
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	if ( ! agend_apps_auth_mfa_throttle( $ip ) ) {
		return new WP_Error( 'agend_apps_mfa_throttled', __( 'Too many code attempts. Please wait a minute and try again.', 'agend-apps-core' ) );
	}
	$factor_id = isset( $input['factor_id'] ) ? sanitize_text_field( wp_unslash( $input['factor_id'] ) ) : '';
	$code = isset( $input['code'] ) ? sanitize_text_field( wp_unslash( $input['code'] ) ) : '';
	$response = agend_apps_auth_verify_mfa_challenge( $id, $factor_id, $code );
	if ( is_wp_error( $response ) ) {
		return $response;
	}
	$result = agend_apps_wp_login_complete( $response, (string) $challenge['email'], null, true );
	return $result instanceof WP_User ? $result : agend_apps_wp_login_generic_error();
}

/** Complete WordPress's sign-in side effects; separated from page rendering for testing. */
function agend_apps_wp_login_mfa_action_result( array $input ) {
	$id = isset( $input['challenge_id'] ) && is_string( $input['challenge_id'] ) ? sanitize_text_field( wp_unslash( $input['challenge_id'] ) ) : '';
	$challenge = agend_apps_auth_get_mfa_challenge( $id );
	$result = agend_apps_wp_login_verify_mfa( $input );
	if ( $result instanceof WP_User ) {
		wp_set_current_user( $result->ID );
		wp_set_auth_cookie( $result->ID, is_array( $challenge ) && ! empty( $challenge['remember'] ) );
		do_action( 'wp_login', $result->user_login, $result );
		$requested = isset( $input['redirect_to'] ) && is_string( $input['redirect_to'] ) ? wp_unslash( $input['redirect_to'] ) : '';
		$redirect = apply_filters( 'login_redirect', '' !== $requested ? $requested : admin_url(), $requested, $result );
		wp_safe_redirect( $redirect );
	}
	return $result;
}

function agend_apps_wp_login_mfa_action(): void {
	$result = agend_apps_wp_login_mfa_action_result( $_POST );
	if ( $result instanceof WP_User ) {
		exit;
	}
	$id = isset( $_POST['challenge_id'] ) ? sanitize_text_field( wp_unslash( $_POST['challenge_id'] ) ) : '';
	$challenge = agend_apps_auth_get_mfa_challenge( $id );
	if ( is_array( $challenge ) && ! empty( $challenge['factors'] ) ) {
		agend_apps_wp_login_mfa_step( array( 'challenge_id' => $id, 'factors' => $challenge['factors'] ) );
		login_header( __( 'Verify your code', 'agend-apps-core' ), '<div id="login_error">' . esc_html( $result->get_error_message() ) . '</div>' );
		login_footer();
		exit;
	}
	wp_die( esc_html( $result->get_error_message() ), esc_html__( 'Sign-in failed', 'agend-apps-core' ), array( 'response' => 400 ) );
}
add_action( 'login_form_agend_mfa', 'agend_apps_wp_login_mfa_action' );

/**
 * Records verification-pending on the WordPress user for the submitted email,
 * when one exists, so the profile and the widget can surface it
 * (SPEC-CORE-20260907 US-4.1 AC3). A no-op when no WordPress user holds this
 * email: there is nothing to flag.
 *
 * @param string $email Submitted email (lower-cased, validated).
 */
function agend_apps_wp_login_mark_verification_pending( string $email ): void {
	$existing = get_user_by( 'email', $email );

	if ( $existing instanceof WP_User ) {
		agend_apps_provision_record_outcome( $existing->ID, AGEND_APPS_PROVISION_PENDING );
	}
}

/**
 * Registers a dashboard account for an existing WordPress user whose
 * credentials the gateway rejected (SPEC-CORE-20260907 US-1.1, US-1.2).
 *
 * Registration proceeds only when `wp_check_password()` accepts the
 * submitted password against the existing WordPress user's own password
 * hash (US-1.1 AC1): the submitted password is otherwise unproven, and
 * calling the gateway register with it would let anyone who knows a
 * WordPress user's email create that user's dashboard account with a
 * password of their own choosing.
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

	// The submitted password must be proven against WordPress's own record
	// before it is ever forwarded to the gateway as the password to register
	// with (US-1.1 AC1, AC3). WordPress's own priority-20 handlers have not
	// run yet at this point in the filter chain, so this check cannot be
	// skipped in favour of "let WordPress decide" here.
	if ( ! wp_check_password( $password, (string) $existing->user_pass, $existing->ID ) ) {
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
 * Refusal reasons `agend_apps_wp_login_refuse_wordpress_password` can emit
 * (SPEC-CORE-20260907-wordpress-email-verification-handling Decision change
 * A). `CONFLICT` is the default when a caller arms with one argument, so the
 * existing email-conflict call site did not need to change.
 *
 * @var string
 */
const AGEND_APPS_WP_LOGIN_REFUSAL_CONFLICT     = 'conflict';
const AGEND_APPS_WP_LOGIN_REFUSAL_VERIFICATION = 'verification';
const AGEND_APPS_WP_LOGIN_REFUSAL_MFA = 'mfa';

/**
 * Email (and reason) whose WordPress-password login is refused for the
 * current request.
 *
 * @param string|null $email  Email to arm ('' clears the arming), or null to read.
 * @param string      $reason One of the `AGEND_APPS_WP_LOGIN_REFUSAL_*` constants.
 *                            Ignored when `$email` is null; forced to '' when
 *                            `$email` is ''.
 * @return array{email: string, reason: string} The currently armed state.
 */
function agend_apps_wp_login_arm_refusal( ?string $email = null, string $reason = AGEND_APPS_WP_LOGIN_REFUSAL_CONFLICT ): array {
	static $armed = array(
		'email'  => '',
		'reason' => '',
	);

	if ( null !== $email ) {
		$armed = array(
			'email'  => $email,
			'reason' => ( '' === $email ) ? '' : $reason,
		);
	}

	return $armed;
}

/**
 * Converts a WordPress-password authentication into the refusal the bridge
 * armed for this email: the generic credentials error for an email that
 * already has a dashboard account (SPEC-CORE-20260907 Decision 2.1, US-1.2),
 * or the verification-required error for a session the gateway withheld
 * pending an ownership link (Decision change A, US-4.1).
 *
 * Runs at priority 30, after `wp_authenticate_username_password` and
 * `wp_authenticate_email_password` (20), so it sees and overrides the
 * `WP_User` WordPress resolved from its own password. Only fires when the
 * bridge armed a refusal for this exact email during this request.
 *
 * @param null|WP_User|WP_Error $user     Result of earlier authenticate filters.
 * @param string                $username Submitted username or email.
 * @param string                $password Submitted password.
 * @return null|WP_User|WP_Error The armed error, else the incoming value.
 */
function agend_apps_wp_login_refuse_wordpress_password( $user, $username, $password ) {
	unset( $password );

	$armed = agend_apps_wp_login_arm_refusal();

	if ( '' === $armed['email'] || strtolower( trim( (string) $username ) ) !== $armed['email'] ) {
		return $user;
	}

	if ( AGEND_APPS_WP_LOGIN_REFUSAL_VERIFICATION === $armed['reason'] ) {
		return agend_apps_wp_login_verification_required_error();
	}
	if ( AGEND_APPS_WP_LOGIN_REFUSAL_MFA === $armed['reason'] ) {
		return new WP_Error( 'agend_apps_mfa_required', __( 'Enter your authenticator code to finish signing in.', 'agend-apps-core' ) );
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

/**
 * The verification-required refusal (SPEC-CORE-20260907-wordpress-email
 * -verification-handling Decision change A, US-4.1). Distinct code and
 * message from the generic credentials error: by the time the gateway answers
 * with this state it has already accepted the submitted password, so naming
 * the reason discloses nothing an attacker does not already have.
 *
 * @return WP_Error
 */
function agend_apps_wp_login_verification_required_error(): WP_Error {
	return new WP_Error(
		'agend_apps_verification_required',
		__( 'Check your email for a link to verify your address, then sign in again.', 'agend-apps-core' )
	);
}
