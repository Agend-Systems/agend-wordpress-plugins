<?php
/**
 * Credential-login member session store.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores the Supabase session a member obtains by signing in with their Agend
 * credentials on the WordPress site (SPEC-CORE-20260722-wordpress-member-login
 * US-1.3), and serves the access token to the API client via the
 * `agend_apps_bearer_token` filter.
 *
 * The full session (access token, refresh token, expiry) is held server-side in
 * user meta and NEVER sent to the browser: the browser talks only to the WP
 * REST proxy, which attaches the bearer server-side. The access token is
 * refreshed lazily via `POST /v1/auth/refresh` (rotating the stored refresh
 * token) when it is within the expiry buffer, so an in-flight request never
 * carries a token that expires mid-call.
 *
 * This is the credential-login counterpart to `Agend_Apps_Token_Worker` (which
 * mints for SAML-linked members via `/v1/sso/tokens`). Per SPEC-CORE-20260722
 * OQ3 the two are mutually exclusive per member: this provider runs first
 * (priority 9) and yields a stored session when present; otherwise it passes
 * through so the SSO worker can mint.
 */
class Agend_Apps_Member_Session {

	/**
	 * User-meta key storing the session envelope. Underscore-prefixed so it is
	 * hidden from the custom-fields UI; server-side only, never sent to the
	 * browser.
	 *
	 * @var string
	 */
	const META_KEY = '_agend_apps_member_session';

	/**
	 * Seconds before the recorded expiry at which the access token is treated
	 * as stale and refreshed, so a request never carries a token that expires
	 * mid-call.
	 *
	 * @var int
	 */
	const EXPIRY_BUFFER = 60;

	/**
	 * Re-entrancy guard: the refresh request flows through the API client,
	 * which consults the bearer filter; without the guard this would recurse.
	 *
	 * @var bool
	 */
	private static $resolving = false;

	/**
	 * Hooks the provider into the bearer-token filter ahead of the SSO worker.
	 */
	public function __construct() {
		add_filter( 'agend_apps_bearer_token', array( $this, 'provide_token' ), 9 );
	}

	/**
	 * Persists a member session for a WordPress user.
	 *
	 * @param int   $user_id WordPress user id.
	 * @param array $session Session array with `access_token`, `refresh_token`,
	 *                       and `expires_at` (epoch seconds).
	 * @return bool True when a well-formed session was stored, false otherwise.
	 */
	public static function store( int $user_id, array $session ): bool {
		if ( 0 === $user_id ) {
			return false;
		}

		$access_token  = isset( $session['access_token'] ) ? (string) $session['access_token'] : '';
		$refresh_token = isset( $session['refresh_token'] ) ? (string) $session['refresh_token'] : '';
		$expires_at    = isset( $session['expires_at'] ) ? (int) $session['expires_at'] : 0;

		if ( '' === $access_token || '' === $refresh_token || 0 === $expires_at ) {
			return false;
		}

		update_user_meta(
			$user_id,
			self::META_KEY,
			array(
				'access_token'  => $access_token,
				'refresh_token' => $refresh_token,
				'expires_at'    => $expires_at,
				'stored_at'     => time(),
			)
		);

		return true;
	}

	/**
	 * Discards a user's stored member session (e.g. on logout).
	 *
	 * @param int $user_id WordPress user id.
	 */
	public static function clear( int $user_id ): void {
		delete_user_meta( $user_id, self::META_KEY );
	}

	/**
	 * Whether a stored member session exists for a WordPress user.
	 *
	 * @param int $user_id WordPress user id.
	 * @return bool True when a session envelope is stored.
	 */
	public static function has_session( int $user_id ): bool {
		return is_array( get_user_meta( $user_id, self::META_KEY, true ) );
	}

	/**
	 * Whether the stored session is live: unexpired, or rotated successfully
	 * via `POST /v1/auth/refresh` (SPEC-CORE-20260907 US-4.4 AC3). A stored
	 * but dead session (the refresh token itself has expired or been
	 * revoked) is cleared and reported as not live, so the admin profile can
	 * show "Not linked" rather than a stale "Linked".
	 *
	 * Deliberately separate from {@see has_session()}, which stays a cheap
	 * existence check with no gateway call.
	 *
	 * @param int $user_id WordPress user id.
	 * @return bool
	 */
	public static function is_live( int $user_id ): bool {
		$stored = get_user_meta( $user_id, self::META_KEY, true );

		if (
			! is_array( $stored )
			|| ! isset( $stored['access_token'], $stored['refresh_token'], $stored['expires_at'] )
		) {
			return false;
		}

		if ( ( (int) $stored['expires_at'] - self::EXPIRY_BUFFER ) > time() ) {
			return true;
		}

		return '' !== ( new self() )->refresh( $user_id, (string) $stored['refresh_token'], '' );
	}

	/**
	 * Resolves the current user's access token for an outbound gateway call.
	 *
	 * @param mixed $token The token from an earlier filter (usually '').
	 * @return string The access token, or the passed-through value when no
	 *                stored session applies.
	 */
	public function provide_token( $token ): string {
		// Another provider already supplied a token — it wins.
		if ( is_string( $token ) && '' !== $token ) {
			return $token;
		}

		// The refresh call itself must go out unattended.
		if ( self::$resolving ) {
			return is_string( $token ) ? $token : '';
		}

		$user_id = get_current_user_id();

		if ( 0 === $user_id ) {
			return is_string( $token ) ? $token : '';
		}

		$stored = get_user_meta( $user_id, self::META_KEY, true );

		if (
			! is_array( $stored )
			|| ! isset( $stored['access_token'], $stored['refresh_token'], $stored['expires_at'] )
		) {
			return is_string( $token ) ? $token : '';
		}

		// Still valid — return the stored access token.
		if ( ( (int) $stored['expires_at'] - self::EXPIRY_BUFFER ) > time() ) {
			return (string) $stored['access_token'];
		}

		// Stale — rotate via refresh. On failure the session is cleared and we
		// fall through so the request proceeds unattended (or the SSO worker
		// takes over).
		return $this->refresh( $user_id, (string) $stored['refresh_token'], $token );
	}

	/**
	 * Rotates a stale session using its refresh token.
	 *
	 * @param int    $user_id       WordPress user id.
	 * @param string $refresh_token The stored refresh token.
	 * @param mixed  $passthrough   The incoming filter value to return on failure.
	 * @return string The new access token, or the passthrough value on failure.
	 */
	private function refresh( int $user_id, string $refresh_token, $passthrough ): string {
		self::$resolving = true;
		$response        = agend_apps_auth_refresh( $refresh_token );
		self::$resolving = false;

		if ( is_wp_error( $response ) ) {
			// The refresh token is invalid, expired, or the contact was revoked.
			// Drop the session so the member is treated as signed out.
			self::clear( $user_id );
			return is_string( $passthrough ) ? $passthrough : '';
		}

		$data    = ( isset( $response['data'] ) && is_array( $response['data'] ) ) ? $response['data'] : $response;
		$session = ( isset( $data['session'] ) && is_array( $data['session'] ) ) ? $data['session'] : array();

		if ( ! self::store( $user_id, $session ) ) {
			self::clear( $user_id );
			return is_string( $passthrough ) ? $passthrough : '';
		}

		// A refresh returned a live session: any earlier verification-pending
		// state is stale (SPEC-CORE-20260907 US-4.1 AC5).
		delete_user_meta( $user_id, AGEND_APPS_VERIFICATION_PENDING_META );

		return (string) $session['access_token'];
	}
}
