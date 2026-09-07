<?php
/**
 * REST routes for member credential login, logout, and portal hand-off.
 *
 * These are the WordPress-side proxy for the Agend gateway's `/v1/auth/*`
 * session endpoints (SPEC-CORE-20260722-wordpress-member-login US-1.3, US-1.5).
 * The browser talks only to these nonce-gated routes; the secret API key and
 * the member's refresh token stay server-side. The access token is stored in
 * user meta and served to outbound gateway calls via the
 * `agend_apps_bearer_token` filter (see Agend_Apps_Member_Session), so it is
 * never returned to the browser.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the auth REST routes.
 *
 * Called from `rest_api_init` via the main plugin bootstrap.
 */
function agend_apps_register_auth_routes(): void {
	$controller = new Agend_Apps_Auth_REST_Controller();
	$controller->register_routes();
}

/**
 * Throttles repeated login attempts per client IP and per email.
 *
 * The gateway's own `auth-login` bucket is per API key, so every member shares
 * it through this single proxy origin (SPEC-CORE-20260722 OQ5). This adds a
 * per-IP and per-email cap at the proxy edge, the correct locus, before any
 * gateway call is made.
 *
 * @param string $email The submitted email (lower-cased).
 * @param string $ip    The client IP.
 * @return bool True when the attempt is allowed, false when it is throttled.
 */
function agend_apps_auth_login_throttle( string $email, string $ip ): bool {
	$window = 15 * MINUTE_IN_SECONDS;

	/**
	 * Filters the login-attempt limits at the WordPress proxy edge.
	 *
	 * @param array $limits {
	 *     @type int $per_ip    Max attempts per IP per window. Default 20.
	 *     @type int $per_email Max attempts per email per window. Default 5.
	 * }
	 */
	$limits = (array) apply_filters(
		'agend_apps_auth_login_limits',
		array(
			'per_ip'    => 20,
			'per_email' => 5,
		)
	);

	$checks = array(
		'agend_apps_login_ip_' . md5( $ip )       => (int) $limits['per_ip'],
		'agend_apps_login_email_' . md5( $email ) => (int) $limits['per_email'],
	);

	$allowed = true;

	foreach ( $checks as $key => $max ) {
		$count = (int) get_transient( $key );

		if ( $count >= $max ) {
			$allowed = false;
			continue;
		}

		set_transient( $key, $count + 1, $window );
	}

	return $allowed;
}

/**
 * Throttles repeated password-reset requests per client IP and per email.
 *
 * The gateway's own `auth-forgot-password` bucket is per API key, so every
 * member shares it through this single proxy origin. This adds a per-IP and
 * per-email cap at the proxy edge (SPEC-CORE-20260722 OQ5), on its own
 * transient namespace so it never consumes the login budget. Reset emails are
 * user-visible spam, so the caps are tighter than login.
 *
 * @param string $email The submitted email (lower-cased).
 * @param string $ip    The client IP.
 * @return bool True when the request is allowed, false when it is throttled.
 */
function agend_apps_auth_forgot_password_throttle( string $email, string $ip ): bool {
	$window = 15 * MINUTE_IN_SECONDS;

	/**
	 * Filters the password-reset-request limits at the WordPress proxy edge.
	 *
	 * @param array $limits {
	 *     @type int $per_ip    Max requests per IP per window. Default 10.
	 *     @type int $per_email Max requests per email per window. Default 3.
	 * }
	 */
	$limits = (array) apply_filters(
		'agend_apps_auth_forgot_password_limits',
		array(
			'per_ip'    => 10,
			'per_email' => 3,
		)
	);

	$checks = array(
		'agend_apps_pwreset_ip_' . md5( $ip )       => (int) $limits['per_ip'],
		'agend_apps_pwreset_email_' . md5( $email ) => (int) $limits['per_email'],
	);

	$allowed = true;

	foreach ( $checks as $key => $max ) {
		$count = (int) get_transient( $key );

		if ( $count >= $max ) {
			$allowed = false;
			continue;
		}

		set_transient( $key, $count + 1, $window );
	}

	return $allowed;
}

/**
 * Returns the guest cart session token when the current guest cart holds items.
 *
 * Reads the `agend_cart_session` cookie the shop sets for anonymous carts and
 * fetches that cart UNATTENDED (no member bearer), so a member's own cart is
 * never inspected here. Returns the token only when the cart has at least one
 * item, so an empty or absent guest cart never triggers a transfer that would
 * cancel the member's existing cart (SPEC-CORE-20260722 US-1.9). Must be called
 * before the member session is stored, while the request is still unattended.
 *
 * @return string The guest cart session token to transfer, or an empty string.
 */
function agend_apps_login_guest_cart_with_items(): string {
	$token = isset( $_COOKIE['agend_cart_session'] )
		? sanitize_text_field( wp_unslash( $_COOKIE['agend_cart_session'] ) )
		: '';

	if ( '' === $token ) {
		return '';
	}

	// Unattended fetch: pass the guest token as X-Cart-Session with no bearer.
	$cart = agend_apps_cart_get( $token, '' );

	if ( is_wp_error( $cart ) ) {
		return '';
	}

	$data = ( isset( $cart['data'] ) && is_array( $cart['data'] ) ) ? $cart['data'] : $cart;

	$count = 0;
	if ( isset( $data['item_count'] ) ) {
		$count = (int) $data['item_count'];
	} elseif ( isset( $data['items'] ) && is_array( $data['items'] ) ) {
		$count = count( $data['items'] );
	}

	return $count > 0 ? $token : '';
}

/**
 * REST controller for member auth endpoints.
 *
 * Exposes, under `agend-apps/v1/auth`:
 * - `POST /login`          — sign in with Agend credentials; stores the session
 *                            server-side and establishes the member's identity.
 * - `POST /logout`         — revoke the session and clear the stored tokens.
 * - `POST /portal-handoff` — mint a single-use portal sign-in URL for the
 *                            authenticated member.
 *
 * All routes are nonce-gated: they run server-side as the current visitor, so
 * the browser never sees the API key or the refresh token.
 */
class Agend_Apps_Auth_REST_Controller extends Agend_Apps_REST_Controller {

	/**
	 * Resource base for auth routes.
	 *
	 * @var string
	 */
	protected $rest_base = 'auth';

	/**
	 * Registers the REST routes for this controller.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/login',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'login' ),
					'permission_callback' => array( $this, 'nonce_check' ),
					'args'                => array(
						'email'    => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_email',
						),
						'password' => array(
							'required' => true,
							'type'     => 'string',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/resend-verification',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'resend_verification' ),
					'permission_callback' => array( $this, 'nonce_check' ),
					'args'                => array(
						'email' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_email',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/register',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'register' ),
					'permission_callback' => array( $this, 'nonce_check' ),
					'args'                => array(
						'email'      => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_email',
						),
						'password'   => array(
							'required' => true,
							'type'     => 'string',
						),
						'first_name' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'last_name'  => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/session',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'session_status' ),
					'permission_callback' => array( $this, 'nonce_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/logout',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'logout' ),
					'permission_callback' => array( $this, 'nonce_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/portal-handoff',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'portal_handoff' ),
					'permission_callback' => array( $this, 'nonce_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/forgot-password',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'forgot_password' ),
					'permission_callback' => array( $this, 'nonce_check' ),
					'args'                => array(
						'email' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_email',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/reset-password',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'reset_password' ),
					'permission_callback' => array( $this, 'nonce_check' ),
					'args'                => array(
						'email'        => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_email',
						),
						'token'        => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'new_password' => array(
							'required' => true,
							'type'     => 'string',
						),
					),
				),
			)
		);
	}

	/**
	 * Validates the WordPress REST nonce for the current request.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return bool True if the nonce is valid, false otherwise.
	 */
	public function nonce_check( WP_REST_Request $request ): bool {
		$nonce = $request->get_header( 'X-WP-Nonce' );

		return false !== wp_verify_nonce( $nonce, 'wp_rest' );
	}

	/**
	 * Signs a member in with their Agend credentials.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function login( WP_REST_Request $request ): WP_REST_Response {
		$email    = strtolower( (string) $request->get_param( 'email' ) );
		$password = (string) $request->get_param( 'password' );

		if ( '' === $email || '' === $password ) {
			return new WP_REST_Response(
				array(
					'code'    => 'missing_credentials',
					'message' => __( 'Email and password are required.', 'agend-apps-core' ),
				),
				400
			);
		}

		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		if ( ! agend_apps_auth_login_throttle( $email, $ip ) ) {
			return new WP_REST_Response(
				array(
					'code'    => 'too_many_attempts',
					'message' => __( 'Too many sign-in attempts. Please wait a few minutes and try again.', 'agend-apps-core' ),
				),
				429
			);
		}

		$response = agend_apps_auth_login( $email, $password );

		if ( is_wp_error( $response ) ) {
			if ( agend_apps_auth_response_is_verification_required( $response ) ) {
				agend_apps_wp_login_mark_verification_pending( $email );

				return new WP_REST_Response(
					array(
						'code'    => 'verification_required',
						'message' => __( 'Check your email to verify your address, then sign in.', 'agend-apps-core' ),
					),
					202
				);
			}

			return $this->error_to_response( $response );
		}

		// A 202 verification_required carries no session at all (SPEC-CORE-20260907
		// US-4.2 AC1); mirrors register()'s existing 202 handling below. This is
		// distinct from a genuine 200 whose session is simply malformed (AC2).
		if ( agend_apps_auth_response_is_verification_required( $response ) ) {
			$data = ( isset( $response['data'] ) && is_array( $response['data'] ) ) ? $response['data'] : $response;

			agend_apps_wp_login_mark_verification_pending( $email );

			return new WP_REST_Response(
				array(
					'code'    => 'verification_required',
					'message' => isset( $data['message'] ) && '' !== (string) $data['message']
						? (string) $data['message']
						: __( 'Check your email to verify your address, then sign in.', 'agend-apps-core' ),
				),
				202
			);
		}

		$data    = ( isset( $response['data'] ) && is_array( $response['data'] ) ) ? $response['data'] : $response;
		$session = ( isset( $data['session'] ) && is_array( $data['session'] ) ) ? $data['session'] : array();

		if ( empty( $session['access_token'] ) || empty( $session['refresh_token'] ) ) {
			return new WP_REST_Response(
				array(
					'code'    => 'invalid_session',
					'message' => __( 'The sign-in did not return a usable session.', 'agend-apps-core' ),
				),
				502
			);
		}

		/**
		 * Resolves the WordPress user id the Agend session is stored against.
		 *
		 * Defaults to the current WordPress user, so a member already signed in
		 * to the site has their Agend session attached. A site whose members
		 * are not necessarily WordPress users yet resolves (find-or-create) the
		 * user here and returns its id; returning 0 signals "no WordPress
		 * identity" and the caller is asked to sign in to the site first. The
		 * establishing code owns the account-matching and sign-in security
		 * (never adopting a higher-privileged WordPress account by email).
		 *
		 * @param int             $user_id Current WordPress user id (0 if none).
		 * @param string          $email   Authenticated member email.
		 * @param array           $data    Decoded login data (user, contact, session).
		 * @param WP_REST_Request $request Current request.
		 */
		// Capture the guest cart BEFORE establishing the member identity, so the
		// cart is inspected unattended (as the guest, not the member). Only a
		// guest cart that holds items is transferred, so an empty guest cart
		// never cancels the member's existing cart.
		$guest_cart_token = agend_apps_login_guest_cart_with_items();

		$user_id = (int) apply_filters(
			'agend_apps_member_login_user_id',
			get_current_user_id(),
			$email,
			$data,
			$request
		);

		if ( 0 === $user_id ) {
			return new WP_REST_Response(
				array(
					'code'    => 'wp_identity_required',
					'message' => __( 'Sign in to this site before linking your Agend account.', 'agend-apps-core' ),
				),
				409
			);
		}

		Agend_Apps_Member_Session::store( $user_id, $session );

		// A credential login supersedes any negative-cached SSO mint state.
		Agend_Apps_Token_Worker::clear_negative_cache( $user_id );

		// Transfer the guest cart onto the now-authenticated member. The gateway
		// cancels and replaces any existing current cart the member holds. Best
		// effort: a failure here never breaks the sign-in.
		$cart_transferred = false;
		if ( '' !== $guest_cart_token ) {
			$transfer = agend_apps_cart_transfer( $guest_cart_token, agend_apps_get_bearer_token() );
			if ( ! is_wp_error( $transfer ) ) {
				$cart_transferred = true;
				// Drop the guest cart cookie so the browser stops sending the old
				// guest token; the member's cart is now resolved from the bearer.
				setcookie( 'agend_cart_session', '', array( 'expires' => time() - HOUR_IN_SECONDS, 'path' => '/' ) );
			}
		}

		// Refresh the membership snapshot usermeta (content-restriction
		// standing) with the fresh bearer. Best effort: a gateway hiccup here
		// keeps the last known snapshot and never breaks the sign-in.
		agend_apps_member_sync_membership_meta( $user_id );

		return new WP_REST_Response(
			array(
				'ok'               => true,
				'user'             => isset( $data['user'] ) ? $data['user'] : null,
				'contact'          => isset( $data['contact'] ) ? $data['contact'] : null,
				'cart_transferred' => $cart_transferred,
				// Sign-in rotates the WordPress session, so the caller's REST
				// nonce is now stale. Return a fresh one for subsequent calls
				// (the frontend also reloads, which re-seeds window.agendApps).
				'nonce'            => wp_create_nonce( 'wp_rest' ),
			),
			200
		);
	}

	/**
	 * Requests another ownership verification email
	 * (SPEC-CORE-20260907 US-4.2 AC4).
	 *
	 * Response uniformity: every non-error gateway outcome, and a gateway
	 * 429, are all reported as the same 202 `verification_sent` so this route
	 * never discloses whether the email matched an account.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function resend_verification( WP_REST_Request $request ): WP_REST_Response {
		$email = strtolower( (string) $request->get_param( 'email' ) );

		$confirmation = new WP_REST_Response(
			array(
				'code'    => 'verification_sent',
				'message' => __( 'If a verification is pending for that address, a new link is on its way.', 'agend-apps-core' ),
			),
			202
		);

		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_REST_Response(
				array(
					'code'    => 'invalid_email',
					'message' => __( 'Enter a valid email address.', 'agend-apps-core' ),
				),
				400
			);
		}

		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		if ( ! agend_apps_auth_login_throttle( $email, $ip ) ) {
			return new WP_REST_Response(
				array(
					'code'    => 'too_many_attempts',
					'message' => __( 'Too many attempts. Please wait a few minutes and try again.', 'agend-apps-core' ),
				),
				429
			);
		}

		$response = agend_apps_auth_resend_verification( $email );

		// A gateway 429 is still reported as the generic 202: the resend
		// route never surfaces gateway-side rate-limit detail to the browser.
		if ( is_wp_error( $response ) && 429 !== agend_apps_auth_error_status( $response ) ) {
			return $this->error_to_response( $response );
		}

		return $confirmation;
	}

	/**
	 * Registers an Agend account from the site and signs the member in
	 * (SPEC-CORE-20260907 US-3.1).
	 *
	 * This is the one surface that names a duplicate email verbosely: the
	 * gateway's 409 `EMAIL_ALREADY_REGISTERED` is returned as
	 * `email_already_registered`. The login route and the wp-login.php bridge
	 * never do (SPEC-API-20260810 v1.1 Decision 2.10).
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function register( WP_REST_Request $request ): WP_REST_Response {
		$email      = strtolower( (string) $request->get_param( 'email' ) );
		$password   = (string) $request->get_param( 'password' );
		$first_name = (string) $request->get_param( 'first_name' );
		$last_name  = (string) $request->get_param( 'last_name' );

		if ( '' === $email || '' === $password ) {
			return new WP_REST_Response(
				array(
					'code'    => 'missing_fields',
					'message' => __( 'Email and password are required.', 'agend-apps-core' ),
				),
				400
			);
		}

		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		if ( ! agend_apps_auth_login_throttle( $email, $ip ) ) {
			return new WP_REST_Response(
				array(
					'code'    => 'too_many_attempts',
					'message' => __( 'Too many attempts. Please wait a few minutes and try again.', 'agend-apps-core' ),
				),
				429
			);
		}

		$response = agend_apps_auth_register(
			agend_apps_provision_register_payload( $email, $password, $first_name, $last_name )
		);

		if ( is_wp_error( $response ) ) {
			if ( agend_apps_auth_error_is_email_conflict( $response ) ) {
				return new WP_REST_Response(
					array(
						'code'    => 'email_already_registered',
						'message' => __( 'An account with this email already exists. Sign in instead.', 'agend-apps-core' ),
					),
					409
				);
			}

			return $this->error_to_response( $response );
		}

		$parsed  = agend_apps_auth_response_session( $response );
		$data    = $parsed['data'];
		$session = $parsed['session'];

		if ( empty( $session ) ) {
			return new WP_REST_Response(
				array(
					'code'    => 'verification_required',
					'message' => isset( $data['message'] ) && '' !== (string) $data['message']
						? (string) $data['message']
						: __( 'Check your email to verify your address, then sign in.', 'agend-apps-core' ),
				),
				202
			);
		}

		$guest_cart_token = agend_apps_login_guest_cart_with_items();

		/** This filter is documented in the login() method above. */
		$user_id = (int) apply_filters( 'agend_apps_member_login_user_id', 0, $email, $data, $request );

		if ( 0 === $user_id ) {
			return new WP_REST_Response(
				array(
					'code'    => 'wp_identity_required',
					'message' => __( 'Your Agend account was created, but this site could not sign you in. Please sign in.', 'agend-apps-core' ),
				),
				409
			);
		}

		Agend_Apps_Member_Session::store( $user_id, $session );
		Agend_Apps_Token_Worker::clear_negative_cache( $user_id );
		delete_user_meta( $user_id, AGEND_APPS_IDENTITY_CONFLICT_META );

		$cart_transferred = false;
		if ( '' !== $guest_cart_token ) {
			$transfer = agend_apps_cart_transfer( $guest_cart_token, agend_apps_get_bearer_token() );
			if ( ! is_wp_error( $transfer ) ) {
				$cart_transferred = true;
				setcookie( 'agend_cart_session', '', array( 'expires' => time() - HOUR_IN_SECONDS, 'path' => '/' ) );
			}
		}

		agend_apps_member_sync_membership_meta( $user_id );

		return new WP_REST_Response(
			array(
				'ok'               => true,
				'user'             => isset( $data['user'] ) ? $data['user'] : null,
				'contact'          => isset( $data['contact'] ) ? $data['contact'] : null,
				'cart_transferred' => $cart_transferred,
				'nonce'            => wp_create_nonce( 'wp_rest' ),
			),
			200
		);
	}

	/**
	 * Reports whether the current visitor has an active Agend member session.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function session_status( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		$user_id = get_current_user_id();

		// A pending user holds no Agend session yet but IS signed into
		// WordPress (Decision 2.1), so the widget still renders its signed-in
		// view -- with the verification notice rather than the portal button
		// (SPEC-CORE-20260907 US-4.2 AC5, US-4.3 AC3).
		$pending = 0 !== $user_id
			&& '1' === (string) get_user_meta( $user_id, AGEND_APPS_VERIFICATION_PENDING_META, true );

		$signed_in = 0 !== $user_id && ( $pending || Agend_Apps_Member_Session::has_session( $user_id ) );

		$email = '';
		if ( $pending ) {
			$wp_user = get_user_by( 'id', $user_id );
			$email   = ( $wp_user instanceof WP_User ) ? $wp_user->user_email : '';
		}

		return new WP_REST_Response(
			array(
				'signed_in'            => $signed_in,
				'portal_url'           => ( $signed_in && ! $pending ) ? Agend_Apps_Settings::get_portal_home_url() : '',
				'verification_pending' => $pending,
				'email'                => $email,
			),
			200
		);
	}

	/**
	 * Signs the current member out and clears the stored session.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function logout( WP_REST_Request $request ): WP_REST_Response {
		$user_id     = get_current_user_id();
		$had_session = 0 !== $user_id && Agend_Apps_Member_Session::has_session( $user_id );

		// Best-effort global revoke at the gateway while the bearer is still
		// resolvable; a failure must not stop the local session being cleared.
		if ( $had_session ) {
			agend_apps_auth_logout();
		}

		if ( 0 !== $user_id ) {
			Agend_Apps_Member_Session::clear( $user_id );
		}

		// End the WordPress session too, so the widget's "sign out" fully signs
		// the member out of the site.
		if ( $had_session ) {
			wp_logout();
		}

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * Mints a single-use portal sign-in link for the current member.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function portal_handoff( WP_REST_Request $request ): WP_REST_Response {
		$user_id = get_current_user_id();

		if ( 0 === $user_id || ! Agend_Apps_Member_Session::has_session( $user_id ) ) {
			return new WP_REST_Response(
				array(
					'code'    => 'not_signed_in',
					'message' => __( 'Sign in with your Agend account before opening the portal.', 'agend-apps-core' ),
				),
				401
			);
		}

		$response = agend_apps_auth_session_handoff();

		if ( is_wp_error( $response ) ) {
			return $this->error_to_response( $response );
		}

		$data = ( isset( $response['data'] ) && is_array( $response['data'] ) ) ? $response['data'] : $response;
		$url  = isset( $data['url'] ) ? (string) $data['url'] : '';

		if ( '' === $url ) {
			return new WP_REST_Response(
				array(
					'code'    => 'no_handoff_url',
					'message' => __( 'The portal sign-in link could not be created.', 'agend-apps-core' ),
				),
				502
			);
		}

		return new WP_REST_Response(
			array(
				'ok'  => true,
				'url' => $url,
			),
			200
		);
	}

	/**
	 * Requests a password-reset email for a member.
	 *
	 * Unauthenticated by design: a member who has forgotten their password
	 * cannot be signed in. The reset link in the email is minted by the gateway
	 * and lands on the Agend portal recovery page, where the member sets a new
	 * password and then returns here to sign in (SPEC-CORE-20260722 US-2.7).
	 *
	 * The response is deliberately identical whether or not the email matches an
	 * account, so this endpoint never discloses account existence. Throttled at
	 * the proxy edge per IP and per email.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function forgot_password( WP_REST_Request $request ): WP_REST_Response {
		$email = strtolower( (string) $request->get_param( 'email' ) );

		// Generic confirmation, reused for every non-error outcome so the caller
		// cannot tell whether the email matched an account.
		$confirmation = new WP_REST_Response(
			array(
				'ok'      => true,
				'message' => __( 'If an account exists for that email, a password reset link has been sent.', 'agend-apps-core' ),
			),
			200
		);

		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_REST_Response(
				array(
					'code'    => 'invalid_email',
					'message' => __( 'Enter a valid email address.', 'agend-apps-core' ),
				),
				400
			);
		}

		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		if ( ! agend_apps_auth_forgot_password_throttle( $email, $ip ) ) {
			return new WP_REST_Response(
				array(
					'code'    => 'too_many_requests',
					'message' => __( 'Too many reset requests. Please wait a few minutes and try again.', 'agend-apps-core' ),
				),
				429
			);
		}

		$payload = array( 'email' => $email );

		// Opt-in in-WordPress completion: when a reset page URL is configured,
		// ask the gateway to land the reset link on that page (the member-login
		// widget there reads the token and posts the new password). Left blank,
		// the gateway defaults the reset link to the member portal
		// (SPEC-CORE-20260722 US-2.7). The URL must also be on the API key's
		// redirect_url_allowlist or the gateway rejects it.
		$reset_url = Agend_Apps_Settings::get_member_reset_url();
		if ( '' !== $reset_url ) {
			$payload['redirect_to'] = $reset_url;
		}

		$response = agend_apps_auth_forgot_password( $payload );

		// A gateway error is not surfaced verbatim: revealing "no such account"
		// would defeat the anti-enumeration posture. Log-and-generic-confirm.
		if ( is_wp_error( $response ) ) {
			return $confirmation;
		}

		return $confirmation;
	}

	/**
	 * Completes a password reset with the recovery token and a new password.
	 *
	 * Used by the in-WordPress completion flow (SPEC-CORE-20260722 US-2.7): the
	 * reset email links back to a page hosting the Agend Member Login widget,
	 * which reads the recovery token from the URL and posts it here with the
	 * member's email and chosen password. Unauthenticated (the member is signed
	 * out) and nonce-gated. Unlike forgot-password this is NOT anti-enumeration:
	 * a bad/expired token or a weak password returns a real error so the widget
	 * can show it. Throttled per IP.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function reset_password( WP_REST_Request $request ): WP_REST_Response {
		$email        = strtolower( (string) $request->get_param( 'email' ) );
		$token        = (string) $request->get_param( 'token' );
		$new_password = (string) $request->get_param( 'new_password' );

		if ( '' === $email || '' === $token || '' === $new_password ) {
			return new WP_REST_Response(
				array(
					'code'    => 'missing_fields',
					'message' => __( 'Email, reset token, and a new password are all required.', 'agend-apps-core' ),
				),
				400
			);
		}

		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		if ( ! agend_apps_auth_forgot_password_throttle( $email, $ip ) ) {
			return new WP_REST_Response(
				array(
					'code'    => 'too_many_requests',
					'message' => __( 'Too many attempts. Please wait a few minutes and try again.', 'agend-apps-core' ),
				),
				429
			);
		}

		$response = agend_apps_auth_reset_password(
			array(
				'email'        => $email,
				'token'        => $token,
				'new_password' => $new_password,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->error_to_response( $response );
		}

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'message' => __( 'Your password has been updated. You can now sign in.', 'agend-apps-core' ),
			),
			200
		);
	}
}
