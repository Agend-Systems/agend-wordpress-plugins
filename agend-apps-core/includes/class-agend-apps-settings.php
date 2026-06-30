<?php
/**
 * Settings helper class.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides static helpers for reading Agend Apps plugin settings from the
 * WordPress options table.
 *
 * All methods are static — this class is never instantiated.
 */
class Agend_Apps_Settings {

	/**
	 * Returns the configured API key.
	 *
	 * @return string The raw API key string, or an empty string if not set.
	 */
	public static function get_api_key(): string {
		return (string) get_option( 'agend_apps_api_key', '' );
	}

	/**
	 * Returns the configured Vercel deployment-protection bypass token.
	 *
	 * When non-empty, the API client sends it as the
	 * `x-vercel-protection-bypass` header so requests can reach a gateway
	 * deployment that sits behind Vercel deployment protection (typically the
	 * staging environment at `https://api.agend.info`). Optional — an empty
	 * string means the header is never sent.
	 *
	 * @return string The bypass token, or an empty string if not set.
	 */
	public static function get_vercel_bypass_token(): string {
		return (string) get_option( 'agend_apps_vercel_bypass_token', '' );
	}

	/**
	 * Returns the versioned API base URL for the configured environment.
	 *
	 * Derived from the `agend_apps_environment` option:
	 * - `production` → `https://api.agend.com.au/v1`
	 * - `staging`    → `https://api.agend.info/v1`
	 * - `local`      → `http://localhost:3072/v1`
	 * - `custom`     → `{agend_apps_custom_url}/v1`
	 *
	 * Applies the `agend_apps_base_url` filter before returning so sibling
	 * plugins can override the resolved URL.
	 *
	 * @return string Versioned base URL without a trailing slash.
	 */
	public static function get_base_url(): string {
		$url = rtrim( self::get_root_url(), '/' ) . '/v1';

		/**
		 * Filters the versioned Agend API base URL.
		 *
		 * @param string $url The resolved versioned base URL.
		 */
		return (string) apply_filters( 'agend_apps_base_url', $url );
	}

	/**
	 * Returns the unversioned API root URL for the configured environment.
	 *
	 * Used for endpoints that live outside the versioned namespace, such as
	 * the generic health check at `/api/health`.
	 *
	 * Applies the `agend_apps_root_url` filter before returning.
	 *
	 * @return string Unversioned root URL without a trailing slash.
	 */
	public static function get_root_url(): string {
		$environment = (string) get_option( 'agend_apps_environment', 'production' );

		switch ( $environment ) {
			case 'staging':
				$url = AGEND_APPS_API_STAGING_URL;
				break;

			case 'local':
				$url = AGEND_APPS_API_LOCAL_URL;
				break;

			case 'custom':
				$custom = (string) get_option( 'agend_apps_custom_url', '' );
				$url    = rtrim( $custom, '/' );
				break;

			case 'production':
			default:
				$url = AGEND_APPS_API_PRODUCTION_URL;
				break;
		}

		/**
		 * Filters the unversioned Agend API root URL.
		 *
		 * @param string $url The resolved root URL.
		 */
		return (string) apply_filters( 'agend_apps_root_url', $url );
	}

	/**
	 * Returns the cache TTL in seconds for the given endpoint key.
	 *
	 * Falls back to the endpoint's default TTL if no option value is stored.
	 *
	 * @param string $endpoint_key Endpoint key as defined in Agend_Apps_Cache::get_all_keys().
	 * @return int TTL in seconds.
	 */
	public static function get_cache_ttl( string $endpoint_key ): int {
		$keys    = Agend_Apps_Cache::get_all_keys();
		$default = isset( $keys[ $endpoint_key ] ) ? (int) $keys[ $endpoint_key ]['default_ttl'] : 60;

		return (int) get_option( 'agend_apps_cache_' . $endpoint_key, $default );
	}
}
