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
 * `array{ scopes: string[], fetched_at: int, key_hash: string }`. The key
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
			if ( ! in_array( $scope, $held, true ) ) {
				return false;
			}
		}

		return true;
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
		$result = agend_apps_verify_api_key();

		if ( is_wp_error( $result ) ) {
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

		update_option(
			self::OPTION,
			array(
				'scopes'     => $scopes,
				'fetched_at' => time(),
				'key_hash'   => self::current_key_hash(),
			)
		);

		return $scopes;
	}

	/**
	 * Refreshes the cached scopes when unknown, when the configured key or
	 * environment has changed since the last fetch, or when the cache is
	 * older than {@see MAX_AGE}. A failed refresh leaves the stale value in
	 * place (see {@see refresh()}).
	 */
	public static function maybe_refresh(): void {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) || ! isset( $stored['fetched_at'], $stored['key_hash'] ) ) {
			self::refresh();
			return;
		}

		if ( (string) $stored['key_hash'] !== self::current_key_hash() ) {
			self::refresh();
			return;
		}

		if ( (int) $stored['fetched_at'] < ( time() - self::MAX_AGE ) ) {
			self::refresh();
		}
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

// A saved API key or environment change invalidates the cached scopes
// immediately, rather than waiting on the next admin_init/all() lazy check.
add_action( 'update_option_agend_apps_api_key', array( 'Agend_Apps_Key_Scopes', 'refresh' ) );
add_action( 'update_option_agend_apps_environment', array( 'Agend_Apps_Key_Scopes', 'refresh' ) );
