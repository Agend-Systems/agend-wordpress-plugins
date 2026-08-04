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

	/**
	 * Default category allow-list for the Upbeat entitlement mirror
	 * (SPEC-AMS-20260804-upbeat-entitlement-mirror Decision 2.5).
	 *
	 * @var string
	 */
	const ENTITLEMENT_MIRROR_DEFAULT_CATEGORY = 'Web Personalisation';

	/**
	 * Default multi_select contact field key the mirror writes (Decision 2.7 /
	 * US-1.2 AC1).
	 *
	 * @var string
	 */
	const ENTITLEMENT_MIRROR_DEFAULT_FIELD_KEY = 'upbeat_entitlements';

	/**
	 * Default login-reconciliation throttle in seconds (US-2.3 AC1).
	 *
	 * @var int
	 */
	const ENTITLEMENT_MIRROR_DEFAULT_LOGIN_THROTTLE = 900;

	/**
	 * Whether the Upbeat entitlement mirror module is enabled.
	 *
	 * Defaults OFF: a module that fires gateway writes on every Upbeat webhook
	 * and every WordPress login must be opt-in per install
	 * (SPEC-AMS-20260804-upbeat-entitlement-mirror US-2.x scope note).
	 *
	 * @return bool
	 */
	public static function is_entitlement_mirror_enabled(): bool {
		return '1' === (string) get_option( 'agend_entitlement_mirror_enabled', '0' );
	}

	/**
	 * Returns the configured entitlement category allow-list.
	 *
	 * Stored as one category per line; defaults to `Web Personalisation`
	 * (PCA's reserved gating category, Decision 2.5).
	 *
	 * @return array<int, string> Trimmed, non-empty category names.
	 */
	public static function get_entitlement_mirror_categories(): array {
		$raw = (string) get_option( 'agend_entitlement_mirror_categories', self::ENTITLEMENT_MIRROR_DEFAULT_CATEGORY );

		$categories = array_filter(
			array_map( 'trim', preg_split( '/\r\n|\r|\n/', $raw ) )
		);

		if ( empty( $categories ) ) {
			$categories = array( self::ENTITLEMENT_MIRROR_DEFAULT_CATEGORY );
		}

		/**
		 * Filters the entitlement mirror's configured category allow-list.
		 *
		 * @param array<int, string> $categories Configured category allow-list.
		 */
		return (array) apply_filters( 'agend_entitlement_mirror_categories', array_values( $categories ) );
	}

	/**
	 * Returns the contact custom-field key the mirror writes the entitlement
	 * slug list to. Matches the gateway catalogue endpoint's `field_key`
	 * default (US-1.2 AC1).
	 *
	 * @return string
	 */
	public static function get_entitlement_mirror_field_key(): string {
		$value = (string) get_option( 'agend_entitlement_mirror_field_key', self::ENTITLEMENT_MIRROR_DEFAULT_FIELD_KEY );

		return '' !== trim( $value ) ? trim( $value ) : self::ENTITLEMENT_MIRROR_DEFAULT_FIELD_KEY;
	}

	/**
	 * Returns the `external_source` value the mirror uses to resolve and
	 * create contacts.
	 *
	 * OPEN QUESTION (spec Section 8, #1): the production value must match
	 * whatever SSO/registration provisioning stamps on `crm_contacts` for this
	 * install, or externalId resolution always falls through to the
	 * exact-email fallback. There is no platform-wide default -- this is
	 * per-install configuration, deliberately left unset (empty disables the
	 * externalId lookup and create-time identity entirely, falling back to
	 * email-only resolution) until the owning team confirms the value.
	 *
	 * @return string
	 */
	public static function get_entitlement_mirror_external_source(): string {
		$value = (string) get_option( 'agend_entitlement_mirror_external_source', '' );

		/**
		 * Filters the `external_source` value used to resolve/create contacts.
		 *
		 * @param string $value Configured external_source value (may be empty).
		 */
		return (string) apply_filters( 'agend_entitlement_mirror_external_source', trim( $value ) );
	}

	/**
	 * Returns the login-reconciliation throttle in seconds (US-2.3 AC1).
	 *
	 * @return int
	 */
	public static function get_entitlement_mirror_login_throttle(): int {
		$value = (int) get_option( 'agend_entitlement_mirror_login_throttle', self::ENTITLEMENT_MIRROR_DEFAULT_LOGIN_THROTTLE );

		return $value > 0 ? $value : self::ENTITLEMENT_MIRROR_DEFAULT_LOGIN_THROTTLE;
	}

	/**
	 * Whether the mirror's gateway writes should send the webhook-suppression
	 * header. Defaults OFF (US-2.2 business rule): other subscribers may
	 * legitimately want the resulting `contact_updated` events, so suppression
	 * is opt-in per install.
	 *
	 * @return bool
	 */
	public static function is_entitlement_mirror_webhook_suppression_enabled(): bool {
		return '1' === (string) get_option( 'agend_entitlement_mirror_suppress_webhooks', '0' );
	}
}
