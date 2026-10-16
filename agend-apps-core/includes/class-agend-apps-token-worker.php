<?php
/**
 * SSO token worker.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides the logged-in WordPress member's Supabase bearer token to the API
 * client (addendum E-11, first consumer of the `agend_apps_bearer_token`
 * filter).
 *
 * The worker resolves the member's external id, mints a short-lived access
 * token via `POST /v1/sso/tokens` (resolve-only: the member must already be
 * linked in `sso_identities`), caches it in user meta keyed with its expiry,
 * and re-mints lazily on expiry (D-5: no refresh token in v1 by design).
 *
 * Failure never breaks a request: any miss (logged out, no external id, not
 * linked, mint error) returns an empty string, which leaves the gateway call
 * unattended (API-key only) exactly as before the worker existed. Unlinked
 * and errored mints are negative-cached so the gateway is not hammered once
 * per proxy call.
 *
 * Concurrent requests for one member share a single mint: the request that
 * takes the per-user lock mints, the others wait briefly and read its token
 * from user meta. The lock is a flag, never the token.
 */
class Agend_Apps_Token_Worker {

	/**
	 * User-meta key storing the minted token envelope. Underscore-prefixed so
	 * it is hidden from the custom-fields UI; server-side only, never sent to
	 * the browser.
	 *
	 * @var string
	 */
	const META_KEY = '_agend_apps_sso_token';

	/**
	 * Transient prefix for the per-user negative cache.
	 *
	 * @var string
	 */
	const NEGATIVE_PREFIX = 'agend_apps_sso_no_token_';

	/**
	 * Seconds before the recorded expiry at which the token is considered
	 * stale, so an in-flight request never carries a token that expires
	 * mid-call.
	 *
	 * @var int
	 */
	const EXPIRY_BUFFER = 60;

	/**
	 * Negative-cache TTL after a 404 (member not linked). Kept short so a
	 * member who links via SSO gains their bearer promptly; the account-link
	 * status route also clears it explicitly on a linked result.
	 *
	 * @var int
	 */
	const NEGATIVE_TTL_UNLINKED = 300;

	/**
	 * Negative-cache TTL after any other mint failure (transport, 5xx).
	 *
	 * @var int
	 */
	const NEGATIVE_TTL_ERROR = 60;

	/**
	 * A token the gateway rejects within this many seconds of being minted is
	 * not a stale token but a gateway that refuses ours. Minting another would
	 * be rejected too, so the failure is negative-cached for
	 * {@see NEGATIVE_TTL_ERROR} instead of costing a mint on every request.
	 *
	 * @var int
	 */
	const REJECTED_REMINT_GRACE = 60;

	/**
	 * `wp_options` name prefix for the per-user mint lock, completed by the
	 * WordPress user id. Autoload is off, so the row never enters the
	 * autoloaded options set.
	 *
	 * @var string
	 */
	const MINT_LOCK_PREFIX = 'agend_apps_sso_mint_lock_';

	/**
	 * Seconds a held mint lock is honoured. Longer than the API client's 15 s
	 * request timeout, so a slow but live mint is never broken as stale and
	 * minted twice. A lock stranded by a killed request costs a waiter no more
	 * than the bounded wait, after which it mints for itself.
	 *
	 * @var int
	 */
	const MINT_LOCK_TTL = 20;

	/**
	 * How many times a request that lost the lock re-reads the member's token
	 * before minting for itself. With {@see MINT_WAIT_POLL_MICROSECONDS} this
	 * bounds the wait at about three seconds. Counted rather than clocked so
	 * the bound holds when the sleep is replaced in a test.
	 *
	 * @var int
	 */
	const MINT_WAIT_POLLS = 30;

	/**
	 * Microseconds slept between those re-reads.
	 *
	 * @var int
	 */
	const MINT_WAIT_POLL_MICROSECONDS = 100000;

	/**
	 * Re-entrancy guard: the mint request itself flows through the API client,
	 * which consults the bearer filter; without the guard the worker would
	 * recurse.
	 *
	 * @var bool
	 */
	private static $resolving = false;

	/**
	 * Hooks the worker into the bearer-token filter and clears its cached
	 * state on logout or when the gateway rejects the token it supplied.
	 */
	public function __construct() {
		add_filter( 'agend_apps_bearer_token', array( $this, 'provide_token' ) );
		add_action( 'agend_apps_bearer_rejected', array( $this, 'handle_rejected' ), 10, 2 );

		// docs/PLAN-wordpress-idp-option-b.md section 4.5: a minted token and
		// its negative cache are keyed to the session that requested them, but
		// nothing previously cleared either on logout, so a token minted
		// before sign-out stayed valid in the database until its own expiry.
		// Registered here, unconditionally, mirroring how this worker itself
		// is instantiated unconditionally in agend-apps-core.php -- the gap
		// predates `wordpress` mode and applies to `sso` mode members today
		// too, so the fix is not gated on member sign-in mode either.
		add_action( 'wp_logout', array( $this, 'handle_logout' ) );
	}

	/**
	 * Clears the logged-out member's cached token and negative cache.
	 *
	 * `wp_logout` has passed the user id as its first argument since WP 5.5,
	 * but the hook has historically fired with no argument at all (some
	 * callers still do), so a falsy argument falls back to
	 * `get_current_user_id()`, which is still populated at this point in the
	 * logout flow. Failure here must never break logout, so any unexpected
	 * error is swallowed rather than propagated.
	 *
	 * @param int $user_id WordPress user id, or 0 when the hook fired without one.
	 */
	public function handle_logout( $user_id = 0 ): void {
		try {
			$user_id = (int) $user_id;

			if ( 0 === $user_id ) {
				$user_id = get_current_user_id();
			}

			if ( 0 === $user_id ) {
				return;
			}

			self::clear_token( $user_id );
			self::clear_negative_cache( $user_id );
		} catch ( \Throwable $e ) {
			// Never let cleanup break logout.
		}
	}

	/**
	 * Discards the cached token when the gateway rejects it with a 401.
	 *
	 * A Supabase session is revoked server side whenever the member signs out
	 * anywhere with global scope, so a cached token can be dead well before
	 * its recorded expiry. Only an exact match clears the cache: the rejected
	 * bearer may be another provider's (the credentials-mode member session),
	 * and a 403 is a permission answer a new token cannot change.
	 *
	 * A token rejected straight after being minted also sets the negative
	 * cache, so a gateway that refuses every token we mint costs one mint per
	 * member per minute rather than one per request. Failure here must never
	 * break the request, so any unexpected error is swallowed.
	 *
	 * @param mixed $token       The bearer the gateway rejected.
	 * @param int   $status_code HTTP status of the rejection.
	 */
	public function handle_rejected( $token, $status_code = 0 ): void {
		try {
			if ( 401 !== (int) $status_code ) {
				return;
			}

			$user_id = get_current_user_id();

			if ( 0 === $user_id ) {
				return;
			}

			$cached = get_user_meta( $user_id, self::META_KEY, true );

			if (
				! is_array( $cached )
				|| ! isset( $cached['access_token'] )
				|| ! is_string( $cached['access_token'] )
				|| $cached['access_token'] !== (string) $token
			) {
				return;
			}

			self::clear_token( $user_id );

			if ( isset( $cached['minted_at'] ) && ( time() - (int) $cached['minted_at'] ) < self::REJECTED_REMINT_GRACE ) {
				set_transient( self::NEGATIVE_PREFIX . $user_id, 1, self::NEGATIVE_TTL_ERROR );
			}
		} catch ( \Throwable $e ) {
			// Never let cleanup break the request.
		}
	}

	/**
	 * Resolves the current user's bearer token for an outbound gateway call.
	 *
	 * @param mixed $token The token from an earlier filter (usually '').
	 * @return string The bearer token, or an empty string when unavailable.
	 */
	public function provide_token( $token ): string {
		// Another provider already supplied a token — it wins.
		if ( is_string( $token ) && '' !== $token ) {
			return $token;
		}

		// The mint call itself must go out unattended.
		if ( self::$resolving ) {
			return '';
		}

		// The sso_account_link optional feature (SPEC-CORE-20260908
		// scope-gated features): a key without sso.tokens.create always gets a
		// 403 from POST /v1/sso/tokens, so never attempt the mint at all.
		if ( function_exists( 'agend_apps_records_feature_available' ) && ! agend_apps_records_feature_available( 'sso_account_link' ) ) {
			return '';
		}

		$user_id = get_current_user_id();

		if ( 0 === $user_id ) {
			return '';
		}

		$external_id = agend_apps_current_user_external_id();

		if ( '' === $external_id ) {
			return '';
		}

		// Valid cached token for the same external id.
		$cached = self::cached_token( $user_id, $external_id );

		if ( '' !== $cached ) {
			return $cached;
		}

		if ( false !== get_transient( self::NEGATIVE_PREFIX . $user_id ) ) {
			return '';
		}

		$lock = self::acquire_mint_lock( $user_id );

		for ( $poll = 0; null === $lock && $poll < self::MINT_WAIT_POLLS; $poll++ ) {
			self::pause( self::MINT_WAIT_POLL_MICROSECONDS );

			$settled = self::settled_answer( $user_id, $external_id );

			if ( null !== $settled ) {
				return $settled;
			}

			$lock = self::acquire_mint_lock( $user_id );
		}

		try {
			// The request that held the lock before this one may have finished
			// between the read above and taking it.
			if ( null !== $lock ) {
				$settled = self::settled_answer( $user_id, $external_id );

				if ( null !== $settled ) {
					return $settled;
				}
			}

			self::$resolving = true;
			$response        = agend_apps_sso_mint_token( agend_apps_idp_entity_id(), $external_id );
			self::$resolving = false;

			if ( is_wp_error( $response ) ) {
				$data        = $response->get_error_data();
				$status_code = ( is_array( $data ) && isset( $data['status_code'] ) ) ? (int) $data['status_code'] : 0;
				$ttl         = ( 404 === $status_code ) ? self::NEGATIVE_TTL_UNLINKED : self::NEGATIVE_TTL_ERROR;
				set_transient( self::NEGATIVE_PREFIX . $user_id, 1, $ttl );
				return '';
			}

			$minted = ( isset( $response['data'] ) && is_array( $response['data'] ) ) ? $response['data'] : $response;

			if ( empty( $minted['access_token'] ) || empty( $minted['expires_at'] ) ) {
				set_transient( self::NEGATIVE_PREFIX . $user_id, 1, self::NEGATIVE_TTL_ERROR );
				return '';
			}

			// A successful mint confirms the identity is linked; record the ids the
			// gateway returned alongside it (both optional -- an older gateway
			// omits them) the same way agend_apps_account_link_state() does.
			if ( function_exists( 'agend_apps_record_linked_identity' ) ) {
				agend_apps_record_linked_identity( $user_id, $minted );
			}

			// This worker no longer promotes `asserted` to `linked`: a mint needs
			// `sso.tokens.create`, while the link itself only needs
			// `sso.identities.read`, so promoting on a mint made the recorded
			// state depend on a strictly stronger scope than the thing it
			// describes -- on a key without the mint scope a member could never
			// leave `asserted` at all. Promotion now happens on the SAML round
			// trip's own return leg, in `includes/wp-idp-saml-link.php`'s
			// `agend_apps_saml_link_promote()`.
			update_user_meta(
				$user_id,
				self::META_KEY,
				array(
					'access_token' => (string) $minted['access_token'],
					'expires_at'   => (int) $minted['expires_at'],
					'external_id'  => $external_id,
					'minted_at'    => time(),
				)
			);

			return (string) $minted['access_token'];
		} finally {
			// A mint that throws would otherwise leave the guard set and switch
			// the worker off for the rest of the request.
			self::$resolving = false;

			if ( null !== $lock ) {
				self::release_mint_lock( $user_id, $lock );
			}
		}
	}

	/**
	 * Reads the user's cached token, valid only for the given external id.
	 *
	 * @param int    $user_id             WordPress user id.
	 * @param string $external_id         The external id the token must have been minted for.
	 * @param bool   $bypass_request_cache Re-read user meta from the store, not this request's copy of it.
	 * @return string The token, or an empty string when none is cached, it is near expiry, or it belongs to another external id.
	 */
	private static function cached_token( int $user_id, string $external_id, bool $bypass_request_cache = false ): string {
		if ( $bypass_request_cache ) {
			wp_cache_delete( $user_id, 'user_meta' );
		}

		$cached = get_user_meta( $user_id, self::META_KEY, true );

		if (
			is_array( $cached )
			&& isset( $cached['access_token'], $cached['expires_at'], $cached['external_id'] )
			&& $cached['external_id'] === $external_id
			&& ( (int) $cached['expires_at'] - self::EXPIRY_BUFFER ) > time()
		) {
			return (string) $cached['access_token'];
		}

		return '';
	}

	/**
	 * What another request has already settled for this member: a usable
	 * token, or a negative-cached failure.
	 *
	 * @param int    $user_id     WordPress user id.
	 * @param string $external_id The member's current external id.
	 * @return string|null The token, an empty string when a mint failure is negative-cached, or null when nothing is settled yet.
	 */
	private static function settled_answer( int $user_id, string $external_id ): ?string {
		$token = self::cached_token( $user_id, $external_id, true );

		if ( '' !== $token ) {
			return $token;
		}

		if ( false !== get_transient( self::NEGATIVE_PREFIX . $user_id ) ) {
			return '';
		}

		return null;
	}

	/**
	 * Takes the member's mint lock, breaking one older than
	 * {@see MINT_LOCK_TTL}.
	 *
	 * Taken with `INSERT IGNORE` straight against `wp_options` rather than
	 * `add_option()`, which is `INSERT ... ON DUPLICATE KEY UPDATE` and can
	 * report success to two racing requests; the same reasoning and queries as
	 * `Agend_Directory_Sync_Job::acquire_lock()`. A stale lock is replaced with
	 * a compare-and-swap, so two requests breaking the same lock cannot both win.
	 *
	 * The value is "timestamp:owner" so the release can delete only this
	 * request's own row. It holds no token and nothing about the member.
	 *
	 * @param int $user_id WordPress user id.
	 * @return string|null The value this request wrote, or null when another request holds the lock.
	 */
	private static function acquire_mint_lock( int $user_id ): ?string {
		global $wpdb;

		$name  = self::MINT_LOCK_PREFIX . $user_id;
		$value = time() . ':' . bin2hex( random_bytes( 8 ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the lock must be atomic; see the docblock above.
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

		if ( (int) $held_since > time() - self::MINT_LOCK_TTL ) {
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
	 * Releases the lock, but only if this request still owns it: a lock broken
	 * as stale and re-taken by another request is not this one's to delete.
	 *
	 * @param int    $user_id WordPress user id.
	 * @param string $value   The value {@see acquire_mint_lock()} returned.
	 */
	private static function release_mint_lock( int $user_id, string $value ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				self::MINT_LOCK_PREFIX . $user_id,
				$value
			)
		);
	}

	/**
	 * Sleeps between re-reads while another request mints, via whatever
	 * `agend_apps_token_worker_sleeper` filters in, or a real `usleep()`.
	 *
	 * @param int $microseconds Microseconds to sleep.
	 */
	private static function pause( int $microseconds ): void {
		/**
		 * Filters the sleep implementation used while waiting for another
		 * request's mint. A test sets this to a recording callable so the suite
		 * never blocks on a real sleep; production leaves it unfiltered.
		 *
		 * @param callable|null $sleeper Callable invoked with the microseconds to sleep, or null for the default.
		 */
		$sleeper = apply_filters( 'agend_apps_token_worker_sleeper', null );

		if ( is_callable( $sleeper ) ) {
			call_user_func( $sleeper, $microseconds );
			return;
		}

		usleep( $microseconds );
	}

	/**
	 * Clears the negative cache for a user.
	 *
	 * Called by the account-link status route when it observes a linked
	 * result, so a freshly linked member gains their bearer on the next call
	 * instead of waiting out the negative TTL.
	 *
	 * @param int $user_id WordPress user id.
	 */
	public static function clear_negative_cache( int $user_id ): void {
		delete_transient( self::NEGATIVE_PREFIX . $user_id );
	}

	/**
	 * Discards a user's cached token (e.g. when the external id mapping
	 * changes or an admin needs to force a re-mint).
	 *
	 * @param int $user_id WordPress user id.
	 */
	public static function clear_token( int $user_id ): void {
		delete_user_meta( $user_id, self::META_KEY );
	}
}
