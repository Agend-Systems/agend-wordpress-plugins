<?php
/**
 * API key scope cache.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Caches the scopes the connected API key holds, so optional features that
 * need a gateway scope the key may lack (SPEC-CORE-20260908 scope-gated
 * features) can check availability without a gateway round trip on every
 * request.
 *
 * The scopes come from `GET /v1/health` ({@see agend_apps_verify_api_key()}),
 * which echoes `data.scopes`. Stored in the `agend_apps_key_scopes` option as
 * `array{ scopes: string[], app_ids: string[], fetched_at: int, key_hash: string }`. The key
 * itself is never logged or stored here — only a one-way hash of the key and
 * environment, used solely to detect that either changed.
 */
class Agend_Apps_Key_Scopes {

	/**
	 * Option name storing the cached scopes.
	 *
	 * @var string
	 */
	const OPTION = 'agend_apps_key_scopes';

	/**
	 * Maximum age, in seconds, before a cached fetch is considered stale and
	 * due for a background refresh.
	 *
	 * @var int
	 */
	const MAX_AGE = HOUR_IN_SECONDS;

	/**
	 * Minimum age, in seconds, before the request guard may spend one gateway
	 * round trip re-checking the scopes it is about to refuse a call on, and
	 * the minimum gap between two such re-checks.
	 *
	 * @var int
	 */
	const RECHECK_AFTER = 5 * MINUTE_IN_SECONDS;

	/**
	 * Transient that throttles the guard's re-check to one per
	 * {@see RECHECK_AFTER}.
	 *
	 * @var string
	 */
	const RECHECK_TRANSIENT = 'agend_apps_key_scopes_recheck';

	/**
	 * Cron hook that refreshes scopes which are stored but past
	 * {@see MAX_AGE}, so no visitor's request waits on the gateway for it.
	 *
	 * @var string
	 */
	const REFRESH_HOOK = 'agend_apps_key_scopes_refresh';

	/**
	 * Transient a failed refresh sets, holding the key hash it failed for.
	 * Failure does not advance `fetched_at`, so without it every request
	 * would retry a gateway that is slow or down.
	 *
	 * @var string
	 */
	const BACKOFF_TRANSIENT = 'agend_apps_key_scopes_backoff';

	/**
	 * How long, in seconds, {@see maybe_refresh()} leaves a failing key alone.
	 *
	 * @var int
	 */
	const BACKOFF = 5 * MINUTE_IN_SECONDS;

	/**
	 * Whether a refresh is currently in flight.
	 *
	 * The refresh itself calls the gateway, and anything on that path that
	 * asks whether a scope is held (the token worker's `sso_account_link`
	 * check, for one) would otherwise re-enter the refresh and recurse until
	 * the request dies. While the flag is set, `all()`/`has()` answer from
	 * whatever is already stored.
	 *
	 * @var bool
	 */
	private static $refreshing = false;

	/**
	 * Returns every scope the connected API key currently holds.
	 *
	 * Triggers a lazy {@see maybe_refresh()} first (front-end callers have no
	 * `admin_init` hook to rely on), so a first call on a fresh install
	 * performs the one gateway round trip needed to populate the cache.
	 *
	 * @return string[] Held scopes. Empty when never fetched or the key holds none.
	 */
	public static function all(): array {
		self::maybe_refresh();

		$stored = get_option( self::OPTION, array() );

		return ( is_array( $stored ) && isset( $stored['scopes'] ) && is_array( $stored['scopes'] ) )
			? array_values( array_map( 'strval', $stored['scopes'] ) )
			: array();
	}

	/**
	 * Whether the connected key holds every one of the given scopes.
	 *
	 * @param string ...$scopes One or more scope strings. An empty list is
	 *                            trivially satisfied (nothing to require).
	 * @return bool True when every scope is present.
	 */
	public static function has( string ...$scopes ): bool {
		if ( empty( $scopes ) ) {
			return true;
		}

		$held = self::all();

		foreach ( $scopes as $scope ) {
			if ( ! self::satisfies( $held, $scope ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether a held scope list covers one scope, the way the gateway reads it:
	 * `*` is superadmin, `app.*` covers every scope under that app, and
	 * otherwise the scope must be listed exactly.
	 *
	 * @param string[] $held  Scopes the key holds.
	 * @param string   $scope Scope to test.
	 * @return bool
	 */
	public static function satisfies( array $held, string $scope ): bool {
		if ( in_array( '*', $held, true ) || in_array( $scope, $held, true ) ) {
			return true;
		}

		foreach ( $held as $candidate ) {
			if ( '.*' === substr( $candidate, -2 ) && 0 === strpos( $scope, substr( $candidate, 0, -1 ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The apps the key is restricted to, as last stored.
	 *
	 * Reads the stored option only and never refreshes: the request guard calls
	 * this from inside {@see Agend_Apps_API::request()}, and a lazy refresh
	 * would re-enter it. The gateway reports `[]` both for an unrestricted key
	 * and for one restricted to nothing, so an empty list means "no
	 * restriction", never "no apps".
	 *
	 * @return string[]
	 */
	public static function apps(): array {
		$stored = get_option( self::OPTION, array() );

		return ( is_array( $stored ) && isset( $stored['app_ids'] ) && is_array( $stored['app_ids'] ) )
			? array_values( array_map( 'strval', $stored['app_ids'] ) )
			: array();
	}

	/**
	 * Whether the key may use the given app. True when the key carries no app
	 * restriction (an empty list, see {@see apps()}). No lazy refresh.
	 *
	 * @param string $app App slug, e.g. `events`.
	 * @return bool
	 */
	public static function has_app( string $app ): bool {
		$apps = self::apps();

		return empty( $apps ) || in_array( $app, $apps, true );
	}

	/**
	 * The stored scopes, with no lazy refresh. For the request guard, which
	 * cannot call {@see all()} without re-entering the API client.
	 *
	 * @return string[]
	 */
	public static function stored_scopes(): array {
		$stored = get_option( self::OPTION, array() );

		return ( is_array( $stored ) && isset( $stored['scopes'] ) && is_array( $stored['scopes'] ) )
			? array_values( array_map( 'strval', $stored['scopes'] ) )
			: array();
	}

	/**
	 * When the stored scopes were fetched, as a Unix timestamp. Zero when never.
	 *
	 * @return int
	 */
	public static function fetched_at(): int {
		$stored = get_option( self::OPTION, array() );

		return ( is_array( $stored ) && isset( $stored['fetched_at'] ) ) ? (int) $stored['fetched_at'] : 0;
	}

	/**
	 * Whether a refresh is in flight right now.
	 *
	 * @return bool
	 */
	public static function refreshing(): bool {
		return self::$refreshing;
	}

	/**
	 * Whether the scopes have ever been successfully fetched for the
	 * currently configured key/environment.
	 *
	 * False for a fresh install, and also false again the moment the key or
	 * environment changes (the stale cache is kept for `has()`/`all()`, but
	 * no longer counts as "known" until a refresh succeeds against the new
	 * key).
	 *
	 * @return bool
	 */
	public static function known(): bool {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) || ! isset( $stored['fetched_at'], $stored['key_hash'] ) ) {
			return false;
		}

		return (string) $stored['key_hash'] === self::current_key_hash();
	}

	/**
	 * Fetches the current scopes from the gateway and stores them.
	 *
	 * On failure the previously stored value (if any) is left untouched, so a
	 * transient outage never wipes a known-good scope list.
	 *
	 * @return string[]|WP_Error The freshly fetched scopes, or the gateway error.
	 */
	public static function refresh() {
		if ( self::$refreshing ) {
			return new WP_Error(
				'agend_apps_key_scopes_refreshing',
				__( 'A key scope refresh is already in flight.', 'agend-apps-core' )
			);
		}

		self::$refreshing = true;

		try {
			// Unattended: the scopes belong to the API key, not to any member,
			// so no bearer is resolved for this call. Resolving one would run
			// the token worker, whose own scope check lands back here.
			$result = agend_apps_verify_api_key( array( 'unattended' => true ) );
		} finally {
			self::$refreshing = false;
		}

		if ( is_wp_error( $result ) ) {
			set_transient( self::BACKOFF_TRANSIENT, self::current_key_hash(), self::BACKOFF );

			return $result;
		}

		return self::store_from_response( $result );
	}

	/**
	 * Stores the scopes carried by an already-fetched `GET /v1/health`
	 * response, without a second gateway round trip.
	 *
	 * Used by the admin "Verify Key" handler, which already has the response
	 * on hand, so scope caching does not cost it a duplicate request.
	 *
	 * @param array $result Decoded `agend_apps_verify_api_key()` response.
	 * @return string[] The stored scopes.
	 */
	public static function store_from_response( array $result ): array {
		$data   = ( isset( $result['data'] ) && is_array( $result['data'] ) ) ? $result['data'] : $result;
		$scopes = ( isset( $data['scopes'] ) && is_array( $data['scopes'] ) )
			? array_values( array_map( 'strval', $data['scopes'] ) )
			: array();

		$app_ids = ( isset( $data['app_ids'] ) && is_array( $data['app_ids'] ) )
			? array_values( array_map( 'strval', $data['app_ids'] ) )
			: array();

		update_option(
			self::OPTION,
			array(
				'scopes'     => $scopes,
				'app_ids'    => $app_ids,
				'fetched_at' => time(),
				'key_hash'   => self::current_key_hash(),
			)
		);

		delete_transient( self::BACKOFF_TRANSIENT );

		return $scopes;
	}

	/**
	 * Refreshes the cached scopes when unknown, when the configured key or
	 * environment has changed since the last fetch, or when the cache is
	 * older than {@see MAX_AGE}. A failed refresh leaves the stale value in
	 * place (see {@see refresh()}).
	 *
	 * Unknown scopes and a changed key refresh inline, because the caller has
	 * nothing trustworthy to answer from. Scopes that are merely stale are
	 * served as stored and refreshed by a single cron event, because this runs
	 * on visitor requests (including anonymous ones) and the gateway call must
	 * not be one of them.
	 *
	 * Does nothing while the current key is in back-off after a failed refresh.
	 */
	public static function maybe_refresh(): void {
		if ( self::$refreshing || self::backing_off() ) {
			return;
		}

		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) || ! isset( $stored['fetched_at'], $stored['key_hash'] ) ) {
			self::refresh();
			return;
		}

		if ( (string) $stored['key_hash'] !== self::current_key_hash() ) {
			self::refresh();
			return;
		}

		if ( (int) $stored['fetched_at'] < ( time() - self::MAX_AGE ) && ! wp_next_scheduled( self::REFRESH_HOOK ) ) {
			wp_schedule_single_event( time(), self::REFRESH_HOOK );
		}
	}

	/**
	 * Whether the currently configured key failed its last refresh within
	 * {@see BACKOFF}. Keyed on the hash so that changing the key or environment
	 * is never held back by a failure recorded against the old one.
	 *
	 * @return bool
	 */
	private static function backing_off(): bool {
		return get_transient( self::BACKOFF_TRANSIENT ) === self::current_key_hash();
	}

	/**
	 * A one-way hash of the currently configured API key and environment,
	 * used only to detect that either has changed. Never reversible into the
	 * key itself.
	 *
	 * @return string
	 */
	private static function current_key_hash(): string {
		$key = ( class_exists( 'Agend_Apps_Settings' ) && method_exists( 'Agend_Apps_Settings', 'get_api_key' ) )
			? Agend_Apps_Settings::get_api_key()
			: '';
		$env = (string) get_option( 'agend_apps_environment', 'production' );

		return sha1( $key . '|' . $env );
	}
}

add_action( 'admin_init', array( 'Agend_Apps_Key_Scopes', 'maybe_refresh' ) );
add_action( Agend_Apps_Key_Scopes::REFRESH_HOOK, array( 'Agend_Apps_Key_Scopes', 'refresh' ) );

// A saved API key or environment change invalidates the cached scopes
// immediately, rather than waiting on the next admin_init/all() lazy check.
add_action( 'update_option_agend_apps_api_key', array( 'Agend_Apps_Key_Scopes', 'refresh' ) );
add_action( 'update_option_agend_apps_environment', array( 'Agend_Apps_Key_Scopes', 'refresh' ) );
