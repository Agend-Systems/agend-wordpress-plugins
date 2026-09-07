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
	 * Member sign-in mode: Agend credentials on this site (default).
	 *
	 * @var string
	 */
	const MEMBER_AUTH_CREDENTIALS = 'credentials';

	/**
	 * Member sign-in mode: SSO connection only, credential login switched off.
	 *
	 * @var string
	 */
	const MEMBER_AUTH_SSO = 'sso';

	/**
	 * Returns the configured member sign-in mode.
	 *
	 * SPEC-CORE-20260907 US-4.1 AC1: reads option `agend_apps_member_auth_mode`
	 * and treats any value other than exactly `sso` as `credentials`, so a
	 * missing option, an upgraded install, or a corrupted value all keep
	 * today's behaviour.
	 *
	 * @return string One of `credentials` or `sso`.
	 */
	public static function get_member_auth_mode(): string {
		$value = get_option( 'agend_apps_member_auth_mode', self::MEMBER_AUTH_CREDENTIALS );

		return self::MEMBER_AUTH_SSO === $value ? self::MEMBER_AUTH_SSO : self::MEMBER_AUTH_CREDENTIALS;
	}

	/**
	 * Whether the credential login surface (login bridge, provisioning hook,
	 * `/auth/*` REST routes, member-login widget) is active on this site.
	 *
	 * @return bool True when the member sign-in mode is `credentials`.
	 */
	public static function credential_login_enabled(): bool {
		return self::MEMBER_AUTH_CREDENTIALS === self::get_member_auth_mode();
	}

	/**
	 * Returns the configured API key.
	 *
	 * Resolution order: the optional `AGEND_APPS_API_KEY` wp-config.php
	 * constant, then the encrypted secret store. A key still sitting in the
	 * legacy plaintext `agend_apps_api_key` option (pre-1.3.0 installs) is
	 * migrated on first read: encrypted into the store and deleted from the
	 * plaintext option, so it stops appearing in database backups. The
	 * migration is one-way — rolling the plugin back past 1.3.0 after it has
	 * run means re-entering the key.
	 *
	 * @return string The raw API key string, or an empty string if not set.
	 */
	public static function get_api_key(): string {
		$key = Agend_Apps_Secret_Store::resolve( Agend_Apps_Secret_Store::KEY_API_KEY, 'AGEND_APPS_API_KEY' );
		if ( '' !== $key ) {
			return $key;
		}

		$legacy = (string) get_option( 'agend_apps_api_key', '' );
		if ( '' === $legacy ) {
			return '';
		}

		if ( Agend_Apps_Secret_Store::set( Agend_Apps_Secret_Store::KEY_API_KEY, $legacy ) ) {
			delete_option( 'agend_apps_api_key' );
		}

		return $legacy;
	}

	/**
	 * Returns the connected Agend account slug.
	 *
	 * Used to build browser SSO URLs on the API host, which are keyed by the
	 * account slug (`/api/auth/sso/{slug}/initiate`). The gateway derives the
	 * account from the API key for `/v1` calls, but the browser SSO endpoints
	 * live outside `/v1` and need the slug in the path.
	 *
	 * @return string The account slug, or an empty string if not set.
	 */
	public static function get_account_slug(): string {
		return (string) get_option( 'agend_apps_account_slug', '' );
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
	 * Returns the Agend member portal root URL.
	 *
	 * An explicit `agend_apps_portal_url` option wins (for associations on a
	 * custom portal domain). Otherwise the URL is resolved from the same
	 * `agend_apps_environment` option that drives the API root: production,
	 * staging, and local map to their portal constants; `custom` derives the
	 * portal host from the custom API URL by swapping the leading `api.`
	 * subdomain for `portal.`, falling back to the production portal.
	 *
	 * Applies the `agend_apps_portal_url` filter before returning.
	 *
	 * @return string Portal root URL without a trailing slash.
	 */
	public static function get_portal_url(): string {
		$explicit = (string) get_option( 'agend_apps_portal_url', '' );

		if ( '' !== $explicit ) {
			$url = rtrim( $explicit, '/' );
		} else {
			$environment = (string) get_option( 'agend_apps_environment', 'production' );

			switch ( $environment ) {
				case 'staging':
					$url = AGEND_APPS_PORTAL_STAGING_URL;
					break;

				case 'local':
					$url = AGEND_APPS_PORTAL_LOCAL_URL;
					break;

				case 'custom':
					$custom = (string) get_option( 'agend_apps_custom_url', '' );
					$url    = '' !== $custom
						? preg_replace( '#^(https?://)api\.#', '$1portal.', rtrim( $custom, '/' ) )
						: AGEND_APPS_PORTAL_PRODUCTION_URL;
					break;

				case 'production':
				default:
					$url = AGEND_APPS_PORTAL_PRODUCTION_URL;
					break;
			}
		}

		/**
		 * Filters the resolved Agend member portal root URL.
		 *
		 * @param string $url The resolved portal URL.
		 */
		return (string) apply_filters( 'agend_apps_portal_url', $url );
	}

	/**
	 * Returns the URL of the connected account's member portal home.
	 *
	 * Builds `{portal}/home/{slug}` from the portal root and the connected
	 * account slug setting, so plain portal links land members on the correct
	 * account portal rather than the generic organisation resolver. Falls back
	 * to the portal root when no account slug is configured. The authenticated
	 * hand-off (`/v1/auth/session-handoff`) derives the same destination
	 * server-side from the API key's account; this helper covers the
	 * unauthenticated fallback links.
	 *
	 * Applies the `agend_apps_portal_home_url` filter before returning.
	 *
	 * @return string Portal home URL without a trailing slash.
	 */
	public static function get_portal_home_url(): string {
		$url  = self::get_portal_url();
		$slug = self::get_account_slug();

		if ( '' !== $url && '' !== $slug ) {
			$url .= '/home/' . rawurlencode( $slug );
		}

		/**
		 * Filters the resolved account portal home URL.
		 *
		 * @param string $url The resolved portal home URL.
		 */
		return (string) apply_filters( 'agend_apps_portal_home_url', $url );
	}

	/**
	 * Returns the WordPress page URL that completes a password reset in place.
	 *
	 * Empty by default: the gateway then mints a reset link to the member
	 * PORTAL recovery page (SPEC-CORE-20260722 US-2.7). Set this to the URL of a
	 * page that hosts the Agend Member Login widget to keep the reset ON this
	 * WordPress site — the reset email links back to that page, the widget reads
	 * the recovery token and posts the new password to the gateway. The URL MUST
	 * also be added to the API key's `redirect_url_allowlist` (the gateway
	 * rejects an unlisted redirect target).
	 *
	 * @return string The reset page URL, or an empty string for the portal default.
	 */
	public static function get_member_reset_url(): string {
		$url = (string) get_option( 'agend_apps_member_reset_url', '' );

		/**
		 * Filters the in-WordPress password-reset completion page URL.
		 *
		 * @param string $url The configured reset page URL ('' = portal default).
		 */
		return (string) apply_filters( 'agend_apps_member_reset_url', trim( $url ) );
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
