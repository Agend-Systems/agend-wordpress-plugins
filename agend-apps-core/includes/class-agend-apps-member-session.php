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
 * The stored value is encrypted with {@see Agend_Apps_Secret_Store}'s scheme,
 * so a database copy alone (a backup, an export, a staging clone, a Redis dump
 * of the user meta cache) holds no usable session. The plaintext names the
 * WordPress user it belongs to, so a value copied onto another user's row is
 * refused. A refresh token is redeemable directly at Supabase Auth without the
 * gateway, so it is never kept in plain text anywhere.
 *
 * The session ends at the gateway, not only locally, when the member signs out
 * of WordPress, their account is deleted, their password is reset or changed,
 * the site's API key is cleared, or the site stops offering credential sign-in.
 *
 * This is the credential-login counterpart to `Agend_Apps_Token_Worker` (which
 * mints for SAML-linked members via `/v1/sso/tokens`). Per SPEC-CORE-20260722
 * OQ3 the two are mutually exclusive per member: this provider runs first
 * (priority 9) and yields a stored session when present; otherwise it passes
 * through so the SSO worker can mint.
 */
class Agend_Apps_Member_Session {

	/**
	 * User-meta key storing the encrypted session. Underscore-prefixed so it is
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
	 * `wp_options` name prefix for the per-user refresh lock, completed by the
	 * WordPress user id. Removed with every other `agend_apps_` option on
	 * uninstall.
	 *
	 * @var string
	 */
	const REFRESH_LOCK_PREFIX = 'agend_apps_member_refresh_lock_';

	/**
	 * Seconds a held refresh lock is honoured. Longer than the API client's
	 * 15 s request timeout, so a slow but live refresh is never broken as stale.
	 *
	 * @var int
	 */
	const REFRESH_LOCK_TTL = 20;

	/**
	 * How many times a request that lost the lock re-reads the session before
	 * refreshing for itself; with {@see REFRESH_WAIT_POLL_MICROSECONDS} about
	 * three seconds.
	 *
	 * @var int
	 */
	const REFRESH_WAIT_POLLS = 30;

	/**
	 * Microseconds slept between those re-reads.
	 *
	 * @var int
	 */
	const REFRESH_WAIT_POLL_MICROSECONDS = 100000;

	/**
	 * Seconds {@see end_all()} spends revoking at the gateway before it falls
	 * back to deleting the remaining sessions locally only.
	 *
	 * @var int
	 */
	const END_ALL_REVOKE_SECONDS = 20;

	/**
	 * Gateway error codes on `/auth/refresh` that mean the refresh token itself
	 * is dead. The gateway answers every GoTrue rejection of the token
	 * (`refresh_token_not_found`, `refresh_token_already_used`,
	 * `session_not_found`, `session_expired`) and a removed contact with 401
	 * `UNAUTHORIZED`, and `MFA_REQUIRED` once it has revoked an aal1 session.
	 * Anything else, a transport error, a 5xx, a 429, or an API key or scope
	 * refusal, says nothing about the token, so the session is kept.
	 *
	 * @var string[]
	 */
	const DEAD_TOKEN_CODES = array( 'UNAUTHORIZED', 'MFA_REQUIRED' );

	/**
	 * Re-entrancy guard: the refresh request flows through the API client,
	 * which consults the bearer filter; without the guard this would recurse.
	 *
	 * @var bool
	 */
	private static $resolving = false;

	/**
	 * Hooks the provider into the bearer-token filter ahead of the SSO worker,
	 * and ends the stored session on every WordPress event that ends the
	 * member's standing on this site.
	 */
	public function __construct() {
		add_filter( 'agend_apps_bearer_token', array( $this, 'provide_token' ), 9 );

		add_action( 'wp_logout', array( __CLASS__, 'handle_user_event' ) );
		add_action( 'delete_user', array( __CLASS__, 'handle_user_event' ) );
		add_action( 'wpmu_delete_user', array( __CLASS__, 'handle_user_event' ) );
		add_action( 'after_password_reset', array( __CLASS__, 'handle_password_reset' ) );
		add_action( 'wp_set_password', array( __CLASS__, 'handle_password_set' ), 10, 2 );
		add_action( 'profile_update', array( __CLASS__, 'handle_profile_update' ), 10, 2 );
		add_action( 'update_option_agend_apps_member_auth_mode', array( __CLASS__, 'handle_mode_updated' ), 10, 2 );
		add_action( 'add_option_agend_apps_member_auth_mode', array( __CLASS__, 'handle_mode_added' ), 10, 2 );
	}

	/**
	 * Persists a member session for a WordPress user, encrypted.
	 *
	 * @param int   $user_id WordPress user id.
	 * @param array $session Session array with `access_token`, `refresh_token`,
	 *                       and `expires_at` (epoch seconds).
	 * @return bool True when a well-formed session was stored, false otherwise.
	 */
	public static function store( int $user_id, array $session ): bool {
		$sealed = self::seal( $user_id, $session );

		if ( '' === $sealed ) {
			return false;
		}

		update_user_meta( $user_id, self::META_KEY, $sealed );

		return true;
	}

	/**
	 * Discards a user's stored member session locally, without revoking it at
	 * the gateway. Use {@see end_session()} when the session must stop working.
	 *
	 * @param int $user_id WordPress user id.
	 */
	public static function clear( int $user_id ): void {
		delete_user_meta( $user_id, self::META_KEY );
	}

	/**
	 * Whether a usable member session is stored for a WordPress user. Decrypts
	 * but never writes and never calls the gateway.
	 *
	 * @param int $user_id WordPress user id.
	 * @return bool True when a decryptable, well-formed session is stored.
	 */
	public static function has_session( int $user_id ): bool {
		return null !== self::read( $user_id, false );
	}

	/**
	 * Whether the stored session is live, for display (SPEC-CORE-20260907
	 * US-4.4). Read-only: an admin viewing a member's profile must never
	 * refresh, and so rotate, that member's session from the admin's request.
	 * A session whose refresh token has died is cleared by the member's own
	 * next request, or by {@see end_session()}, and reads as not live from
	 * then on.
	 *
	 * @param int $user_id WordPress user id.
	 * @return bool
	 */
	public static function is_live( int $user_id ): bool {
		return self::has_session( $user_id );
	}

	/**
	 * Resolves the current user's access token for an outbound gateway call.
	 *
	 * @param mixed $token The token from an earlier filter (usually '').
	 * @return string The access token, or the passed-through value when no
	 *                stored session applies.
	 */
	public function provide_token( $token ): string {
		$passthrough = is_string( $token ) ? $token : '';

		// Another provider already supplied a token: it wins.
		if ( '' !== $passthrough ) {
			return $passthrough;
		}

		if ( self::$resolving ) {
			return $passthrough;
		}

		$user_id = get_current_user_id();

		if ( 0 === $user_id ) {
			return $passthrough;
		}

		$stored = self::read( $user_id );

		if ( null === $stored ) {
			return $passthrough;
		}

		if ( ! self::is_stale( $stored['session'] ) ) {
			return $stored['session']['access_token'];
		}

		$fresh = self::refresh_single_flight( $user_id, $stored );

		return '' !== $fresh ? $fresh : $passthrough;
	}

	/**
	 * Ends a member's session: revokes it at the gateway, then deletes it
	 * locally. The local delete happens whatever the gateway answers, so a
	 * gateway outage never leaves the session stored.
	 *
	 * Revocation needs a live access token, so a stale one is refreshed first.
	 * The gateway's logout is global: it ends every session the member holds.
	 *
	 * @param int $user_id WordPress user id.
	 */
	public static function end_session( int $user_id ): void {
		if ( 0 === $user_id ) {
			return;
		}

		if ( null === self::read( $user_id, false ) ) {
			self::clear( $user_id );
			return;
		}

		$lock = self::acquire_refresh_lock_waiting( $user_id );

		try {
			$stored = self::read( $user_id, true, true );

			if ( null === $stored ) {
				return;
			}

			$access_token = $stored['session']['access_token'];

			if ( self::is_stale( $stored['session'] ) ) {
				self::$resolving = true;
				$response        = agend_apps_auth_refresh( $stored['session']['refresh_token'] );
				self::$resolving = false;

				$renewed      = is_wp_error( $response ) ? array() : self::response_session( $response );
				$access_token = isset( $renewed['access_token'] ) ? (string) $renewed['access_token'] : '';
			}

			if ( '' !== $access_token ) {
				agend_apps_auth_logout( $access_token );
			}
		} finally {
			self::$resolving = false;
			self::clear( $user_id );

			if ( null !== $lock ) {
				self::release_refresh_lock( $user_id, $lock );
			}
		}
	}

	/**
	 * Ends every stored member session on this site: used when the site's API
	 * key is cleared, while the key can still reach the gateway. Revocation
	 * stops after {@see END_ALL_REVOKE_SECONDS} so a large site cannot hang
	 * the request; every remaining session is then deleted locally only.
	 *
	 * @return int How many sessions were ended one by one, with a revocation attempt, before the deadline.
	 */
	public static function end_all(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one indexed read of the users holding a session; no API lists users by meta key without loading every user.
		$user_ids = (array) $wpdb->get_col(
			$wpdb->prepare( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s", self::META_KEY )
		);

		$deadline = time() + self::END_ALL_REVOKE_SECONDS;
		$revoked  = 0;

		foreach ( $user_ids as $user_id ) {
			if ( time() >= $deadline ) {
				break;
			}

			try {
				self::end_session( (int) $user_id );
				++$revoked;
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		delete_metadata( 'user', 0, self::META_KEY, '', true );

		return $revoked;
	}

	/**
	 * `update_option_agend_apps_member_auth_mode` handler: a site that stops
	 * offering credential sign-in ends every credential session it holds
	 * (operator ruling, 2026-10-14). Those members keep their WordPress
	 * sign-in but lose Agend features on the site until they can link through
	 * SSO, which is the accepted cost: a credential session would otherwise
	 * outlive the sign-in method that created it, indefinitely.
	 *
	 * @param mixed $old_value The mode before the change.
	 * @param mixed $value     The mode after the change.
	 */
	public static function handle_mode_updated( $old_value, $value ): void {
		if ( self::is_credentials_mode( $old_value ) && ! self::is_credentials_mode( $value ) ) {
			self::end_all();
		}
	}

	/**
	 * `add_option_agend_apps_member_auth_mode` handler: the first save of the
	 * mode on an install that never stored one, which was therefore running in
	 * `credentials`, the default.
	 *
	 * @param mixed $option Option name.
	 * @param mixed $value  The mode saved.
	 */
	public static function handle_mode_added( $option, $value ): void {
		unset( $option );

		if ( ! self::is_credentials_mode( $value ) ) {
			self::end_all();
		}
	}

	/**
	 * Reads a stored mode the way {@see Agend_Apps_Settings::get_member_auth_mode()}
	 * does: anything unrecognised is `credentials`.
	 *
	 * @param mixed $value Stored option value.
	 * @return bool
	 */
	private static function is_credentials_mode( $value ): bool {
		return ! in_array( $value, array( Agend_Apps_Settings::MEMBER_AUTH_SSO, Agend_Apps_Settings::MEMBER_AUTH_WORDPRESS ), true );
	}

	/**
	 * `wp_logout`, `delete_user` and `wpmu_delete_user` handler.
	 *
	 * `wp_logout` passes the user id since WP 5.5 and fires after the current
	 * user is reset, so the argument is the only reliable source; a falsy one
	 * falls back to `get_current_user_id()` for older callers. Never lets a
	 * gateway problem break sign-out or user deletion.
	 *
	 * @param mixed $user_id WordPress user id.
	 */
	public static function handle_user_event( $user_id = 0 ): void {
		try {
			$user_id = (int) $user_id;

			self::end_session( 0 !== $user_id ? $user_id : get_current_user_id() );
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * `after_password_reset` handler.
	 *
	 * @param mixed $user The user whose password was reset.
	 */
	public static function handle_password_reset( $user ): void {
		if ( $user instanceof WP_User ) {
			self::handle_user_event( $user->ID );
		}
	}

	/**
	 * `wp_set_password` handler (WordPress 6.2+), which covers a password set
	 * by any code path, not only the reset form. The plaintext password is
	 * never read.
	 *
	 * @param mixed $password Unused.
	 * @param mixed $user_id  WordPress user id.
	 */
	public static function handle_password_set( $password, $user_id = 0 ): void {
		unset( $password );

		if ( 0 !== (int) $user_id ) {
			self::handle_user_event( (int) $user_id );
		}
	}

	/**
	 * `profile_update` handler: ends the session when the password changed.
	 *
	 * @param mixed $user_id       WordPress user id.
	 * @param mixed $old_user_data The user as it was before the update.
	 */
	public static function handle_profile_update( $user_id, $old_user_data = null ): void {
		$user = get_user_by( 'id', (int) $user_id );

		if (
			$user instanceof WP_User
			&& $old_user_data instanceof WP_User
			&& (string) $user->user_pass !== (string) $old_user_data->user_pass
		) {
			self::handle_user_event( (int) $user_id );
		}
	}

	/**
	 * Rotates a stale session once per member, however many requests need it
	 * at the same moment: the request holding the per-user lock refreshes, the
	 * others wait briefly and read what it stored.
	 *
	 * @param int   $user_id WordPress user id.
	 * @param array $stored  The stored session as {@see read()} returned it.
	 * @return string The new access token, or '' when there is none to use.
	 */
	private static function refresh_single_flight( int $user_id, array $stored ): string {
		$lock = self::acquire_refresh_lock( $user_id );

		for ( $poll = 0; null === $lock && $poll < self::REFRESH_WAIT_POLLS; $poll++ ) {
			self::pause( self::REFRESH_WAIT_POLL_MICROSECONDS );

			$settled = self::settled_token( $user_id, $stored );

			if ( null !== $settled ) {
				return $settled;
			}

			$lock = self::acquire_refresh_lock( $user_id );
		}

		try {
			// The request that held the lock before this one may have finished
			// between the read above and taking it.
			if ( null !== $lock ) {
				$settled = self::settled_token( $user_id, $stored );

				if ( null !== $settled ) {
					return $settled;
				}
			}

			self::$resolving = true;
			$response        = agend_apps_auth_refresh( $stored['session']['refresh_token'] );
			self::$resolving = false;

			if ( is_wp_error( $response ) ) {
				if ( self::refresh_token_is_dead( $response ) ) {
					self::delete_if_unchanged( $user_id, $stored['raw'] );
				}

				return '';
			}

			$renewed = self::response_session( $response );
			$sealed  = self::seal( $user_id, $renewed );

			// A 200 without a usable session is a gateway fault, not proof the
			// token is dead, so the stored session is kept.
			if ( '' === $sealed ) {
				return '';
			}

			// Written only over the value this request read: a sign-out or a
			// newer refresh that landed meanwhile is never overwritten.
			if ( ! self::replace_if_unchanged( $user_id, $stored['raw'], $sealed ) ) {
				return '';
			}

			// A refresh returned a live session: any earlier verification-pending
			// state is stale (SPEC-CORE-20260907 US-4.1 AC5). The constant is only
			// defined when credential login is enabled (see member-provisioning.php),
			// so guard it for SSO-mode installs where it is never loaded.
			if ( defined( 'AGEND_APPS_VERIFICATION_PENDING_META' ) ) {
				delete_user_meta( $user_id, AGEND_APPS_VERIFICATION_PENDING_META );
			}

			return (string) $renewed['access_token'];
		} finally {
			self::$resolving = false;

			if ( null !== $lock ) {
				self::release_refresh_lock( $user_id, $lock );
			}
		}
	}

	/**
	 * What another request has already settled for this member since `$stored`
	 * was read: a rotated session's access token, or '' when the session has
	 * gone (signed out, or found dead).
	 *
	 * @param int   $user_id WordPress user id.
	 * @param array $stored  The session this request read.
	 * @return string|null The answer, or null when nothing has changed yet.
	 */
	private static function settled_token( int $user_id, array $stored ): ?string {
		$current = self::read( $user_id, false, true );

		if ( null === $current ) {
			return '';
		}

		if ( $current['session']['refresh_token'] === $stored['session']['refresh_token'] ) {
			return null;
		}

		return self::is_stale( $current['session'] ) ? '' : $current['session']['access_token'];
	}

	/**
	 * Reads and decrypts the stored session.
	 *
	 * With `$repair`, a legacy plain-text session is encrypted in place, and a
	 * value that does not decrypt (the salts rotated) or that names another
	 * user is deleted as no session.
	 *
	 * @param int  $user_id              WordPress user id.
	 * @param bool $repair               Whether to migrate or delete what cannot be used.
	 * @param bool $bypass_request_cache Re-read from the store, not this request's copy.
	 * @return array{session: array{access_token: string, refresh_token: string, expires_at: int}, raw: string}|null
	 */
	private static function read( int $user_id, bool $repair = true, bool $bypass_request_cache = false ): ?array {
		if ( 0 === $user_id ) {
			return null;
		}

		if ( $bypass_request_cache ) {
			wp_cache_delete( $user_id, 'user_meta' );
		}

		$raw = get_user_meta( $user_id, self::META_KEY, true );

		if ( is_array( $raw ) ) {
			$session = self::well_formed( $raw );

			if ( null === $session || ! $repair ) {
				return null === $session ? null : array(
					'session' => $session,
					'raw'     => '',
				);
			}

			return self::store( $user_id, $session )
				? self::read( $user_id, false, $bypass_request_cache )
				: null;
		}

		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}

		$decoded = json_decode( Agend_Apps_Secret_Store::decrypt( $raw ), true );
		$session = ( is_array( $decoded ) && isset( $decoded['user_id'] ) && (int) $decoded['user_id'] === $user_id )
			? self::well_formed( $decoded )
			: null;

		if ( null === $session ) {
			if ( $repair ) {
				self::clear( $user_id );
			}

			return null;
		}

		return array(
			'session' => $session,
			'raw'     => $raw,
		);
	}

	/**
	 * Encrypts a session for storage, bound to its WordPress user.
	 *
	 * @param int   $user_id WordPress user id.
	 * @param array $session Session array.
	 * @return string The sealed value, or '' when the session is not well formed.
	 */
	private static function seal( int $user_id, array $session ): string {
		$session = self::well_formed( $session );

		if ( 0 === $user_id || null === $session ) {
			return '';
		}

		return Agend_Apps_Secret_Store::encrypt(
			(string) wp_json_encode(
				$session + array(
					'user_id'   => $user_id,
					'stored_at' => time(),
				)
			)
		);
	}

	/**
	 * The session's three required fields, or null when any is missing.
	 *
	 * @param array $session Candidate session.
	 * @return array{access_token: string, refresh_token: string, expires_at: int}|null
	 */
	private static function well_formed( array $session ): ?array {
		$access_token  = isset( $session['access_token'] ) ? (string) $session['access_token'] : '';
		$refresh_token = isset( $session['refresh_token'] ) ? (string) $session['refresh_token'] : '';
		$expires_at    = isset( $session['expires_at'] ) ? (int) $session['expires_at'] : 0;

		if ( '' === $access_token || '' === $refresh_token || 0 === $expires_at ) {
			return null;
		}

		return array(
			'access_token'  => $access_token,
			'refresh_token' => $refresh_token,
			'expires_at'    => $expires_at,
		);
	}

	/**
	 * Whether the access token is within the expiry buffer.
	 *
	 * @param array $session Well-formed session.
	 * @return bool
	 */
	private static function is_stale( array $session ): bool {
		return ( (int) $session['expires_at'] - self::EXPIRY_BUFFER ) <= time();
	}

	/**
	 * The session in a gateway auth response, enveloped or not.
	 *
	 * @param mixed $response Decoded response.
	 * @return array
	 */
	private static function response_session( $response ): array {
		$response = is_array( $response ) ? $response : array();
		$data     = ( isset( $response['data'] ) && is_array( $response['data'] ) ) ? $response['data'] : $response;

		return ( isset( $data['session'] ) && is_array( $data['session'] ) ) ? $data['session'] : array();
	}

	/**
	 * Whether a refresh failure proves the refresh token is dead.
	 *
	 * @param WP_Error $error The refresh failure.
	 * @return bool
	 */
	private static function refresh_token_is_dead( WP_Error $error ): bool {
		return 401 === agend_apps_auth_error_status( $error )
			&& in_array( agend_apps_auth_error_code( $error ), self::DEAD_TOKEN_CODES, true );
	}

	/**
	 * Compare-and-set write of the stored session.
	 *
	 * @param int    $user_id  WordPress user id.
	 * @param string $expected The raw value this request read.
	 * @param string $sealed   The new sealed value.
	 * @return bool Whether the write happened.
	 */
	private static function replace_if_unchanged( int $user_id, string $expected, string $sealed ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- update_user_meta() cannot condition on the current value without re-adding a deleted row.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->usermeta} SET meta_value = %s WHERE user_id = %d AND meta_key = %s AND meta_value = %s",
				$sealed,
				$user_id,
				self::META_KEY,
				$expected
			)
		);

		wp_cache_delete( $user_id, 'user_meta' );

		return 1 === (int) $wpdb->rows_affected;
	}

	/**
	 * Compare-and-delete of the stored session, so a refresh that failed
	 * definitively never deletes a session another request has just stored.
	 *
	 * @param int    $user_id  WordPress user id.
	 * @param string $expected The raw value this request read.
	 */
	private static function delete_if_unchanged( int $user_id, string $expected ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- delete_user_meta() cannot condition on a value it would have to unserialise first.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s AND meta_value = %s",
				$user_id,
				self::META_KEY,
				$expected
			)
		);

		wp_cache_delete( $user_id, 'user_meta' );
	}

	/**
	 * Takes the member's refresh lock, waiting as a refresh does; returns null
	 * when it could not be taken, in which case the caller proceeds anyway.
	 *
	 * @param int $user_id WordPress user id.
	 * @return string|null
	 */
	private static function acquire_refresh_lock_waiting( int $user_id ): ?string {
		$lock = self::acquire_refresh_lock( $user_id );

		for ( $poll = 0; null === $lock && $poll < self::REFRESH_WAIT_POLLS; $poll++ ) {
			self::pause( self::REFRESH_WAIT_POLL_MICROSECONDS );
			$lock = self::acquire_refresh_lock( $user_id );
		}

		return $lock;
	}

	/**
	 * Takes the member's refresh lock, breaking one older than
	 * {@see REFRESH_LOCK_TTL}. The same `INSERT IGNORE` and compare-and-swap
	 * as `Agend_Apps_Token_Worker::acquire_mint_lock()`, for the same reasons.
	 *
	 * @param int $user_id WordPress user id.
	 * @return string|null The value this request wrote, or null when another request holds the lock.
	 */
	private static function acquire_refresh_lock( int $user_id ): ?string {
		global $wpdb;

		$name  = self::REFRESH_LOCK_PREFIX . $user_id;
		$value = time() . ':' . bin2hex( random_bytes( 8 ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the lock must be atomic.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$name,
				$value
			)
		);

		if ( 1 === (int) $wpdb->rows_affected ) {
			return $value;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$held = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name )
		);

		if ( null === $held ) {
			return null;
		}

		[ $held_since ] = explode( ':', (string) $held, 2 );

		if ( (int) $held_since > time() - self::REFRESH_LOCK_TTL ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$value,
				$name,
				(string) $held
			)
		);

		return 1 === (int) $wpdb->rows_affected ? $value : null;
	}

	/**
	 * Releases the lock, only if this request still owns it.
	 *
	 * @param int    $user_id WordPress user id.
	 * @param string $value   The value {@see acquire_refresh_lock()} returned.
	 */
	private static function release_refresh_lock( int $user_id, string $value ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				self::REFRESH_LOCK_PREFIX . $user_id,
				$value
			)
		);
	}

	/**
	 * Sleeps between re-reads, via whatever `agend_apps_member_session_sleeper`
	 * filters in, or a real `usleep()`.
	 *
	 * @param int $microseconds Microseconds to sleep.
	 */
	private static function pause( int $microseconds ): void {
		/**
		 * Filters the sleep implementation used while another request
		 * refreshes the same member's session. A test sets this to a recording
		 * callable; production leaves it unfiltered.
		 *
		 * @param callable|null $sleeper Callable invoked with the microseconds to sleep, or null for the default.
		 */
		$sleeper = apply_filters( 'agend_apps_member_session_sleeper', null );

		if ( is_callable( $sleeper ) ) {
			call_user_func( $sleeper, $microseconds );
			return;
		}

		usleep( $microseconds );
	}
}
