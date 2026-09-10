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
	 * Member sign-in mode: WordPress is the identity provider. The member's
	 * WordPress password is the only credential and is never sent to the
	 * Agend gateway; the Agend bearer is minted server to server from the
	 * WordPress session via the existing SSO token worker
	 * ({@see Agend_Apps_Token_Worker}). See
	 * `docs/SCOPE-wordpress-idp-member-auth.md` and
	 * `docs/PLAN-wordpress-idp-option-b.md` for the full design. This
	 * constant only introduces the mode; the linking step that makes it
	 * functional (`includes/wp-idp-link.php`) is not built yet.
	 *
	 * @var string
	 */
	const MEMBER_AUTH_WORDPRESS = 'wordpress';

	/**
	 * SSO link mechanism: resolve automatically from the detected IdP plugin
	 * (docs/PLAN-wordpress-idp-option-b.md section 6).
	 *
	 * @var string
	 */
	const SSO_LINK_MECHANISM_AUTO = 'auto';

	/**
	 * SSO link mechanism: the linked identity comes from the SAML assertion
	 * (the NameID a site's SAML IdP plugin sends), not the minted GUID.
	 *
	 * @var string
	 */
	const SSO_LINK_MECHANISM_SAML = 'saml';

	/**
	 * SSO link mechanism: the linked identity is the WordPress-minted GUID,
	 * sent server to server ({@see agend_apps_ensure_external_id()}).
	 *
	 * @var string
	 */
	const SSO_LINK_MECHANISM_SERVER = 'server';

	/**
	 * SSO link mechanism: linking is switched off entirely.
	 *
	 * @var string
	 */
	const SSO_LINK_MECHANISM_DISABLED = 'disabled';

	/**
	 * Returns the configured member sign-in mode.
	 *
	 * SPEC-CORE-20260907 US-4.1 AC1, widened by the WordPress-IdP scope
	 * (`docs/SCOPE-wordpress-idp-member-auth.md` decision 4): reads option
	 * `agend_apps_member_auth_mode` as a closed, three-value vocabulary --
	 * `credentials`, `sso`, or `wordpress`. A missing option, an upgraded
	 * install, or any unrecognised value all resolve to `credentials`, so
	 * existing installs do not change behaviour on upgrade.
	 *
	 * @return string One of `credentials`, `sso`, or `wordpress`.
	 */
	public static function get_member_auth_mode(): string {
		$value = get_option( 'agend_apps_member_auth_mode', self::MEMBER_AUTH_CREDENTIALS );

		if ( self::MEMBER_AUTH_SSO === $value || self::MEMBER_AUTH_WORDPRESS === $value ) {
			return $value;
		}

		return self::MEMBER_AUTH_CREDENTIALS;
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
	 * Whether WordPress is configured as the identity provider for member
	 * sign-in. Named predicate for callers, rather than comparing strings
	 * against `get_member_auth_mode()` directly.
	 *
	 * @return bool True only when the member sign-in mode is `wordpress`.
	 */
	public static function wordpress_idp_enabled(): bool {
		return self::MEMBER_AUTH_WORDPRESS === self::get_member_auth_mode();
	}

	/**
	 * Whether the `agend-saml-idp` plugin is active on this site.
	 *
	 * Uses exactly the checks `agend-embed` already uses to detect it
	 * (`Agend_Embed_Sso_Drivers::agend_saml_idp_template()`,
	 * `agend-embed/includes/class-agend-embed-sso-drivers.php:126-134`), so
	 * the two plugins stay consistent about what "present" means.
	 *
	 * @return bool True when both classes the plugin registers exist.
	 */
	public static function saml_idp_plugin_present(): bool {
		return class_exists( 'WP_SAML_IDP_Service_Provider' ) && class_exists( 'WP_SAML_IDP_Endpoints' );
	}

	/**
	 * Whether the miniOrange "SAML IDP (Identity Provider)" plugin is active
	 * on this site.
	 *
	 * Uses exactly the check `agend-embed` already uses
	 * (`agend-embed/includes/class-agend-embed-sso-drivers.php:177`).
	 *
	 * @return bool True when the plugin's version constant is defined.
	 */
	public static function miniorange_idp_plugin_present(): bool {
		return defined( 'MSI_VERSION' );
	}

	/**
	 * Names the SAML IdP plugin detected on this site, if any.
	 *
	 * Checked in this order because a site is not expected to run both; if
	 * one somehow does, the SAML IdP plugin (the one this feature exists to
	 * warn about, see {@see self::sso_link_mechanism()}) takes precedence.
	 *
	 * @return string `saml` for agend-saml-idp, `miniorange` for miniOrange,
	 *                or an empty string when neither is detected.
	 */
	public static function detected_idp_plugin(): string {
		$detected = '';

		if ( self::saml_idp_plugin_present() ) {
			$detected = 'saml';
		} elseif ( self::miniorange_idp_plugin_present() ) {
			$detected = 'miniorange';
		}

		/**
		 * Filters the SAML IdP plugin detected on this site.
		 *
		 * The two built-in checks cover the plugins `agend-embed` ships
		 * drivers for, but that plugin is deliberately IdP-agnostic via its
		 * own `agend_embed_sso_kickoff_url` filter, so a site running a third
		 * SAML IdP can declare it here and get the same automatic mechanism
		 * resolution and duplicate-identity warning. Return a non-empty
		 * string to assert an IdP is present, or '' to assert none is.
		 *
		 * @param string $detected `saml`, `miniorange`, or '' when neither
		 *                         built-in check matched.
		 */
		return (string) apply_filters( 'agend_apps_detected_idp_plugin', $detected );
	}

	/**
	 * Normalises a submitted or stored SSO link mechanism value to the closed
	 * four-value vocabulary, falling back to `auto` for anything else.
	 *
	 * Shared by the admin sanitiser (so an invalid value is never saved) and
	 * {@see self::sso_link_mechanism()} (so a value written by any other means
	 * -- direct `update_option()`, a filter, an older/rolled-back version --
	 * still resolves safely rather than fataling on an unrecognised string).
	 *
	 * @param mixed $value Raw value.
	 * @return string One of `auto`, `saml`, `server`, `disabled`.
	 */
	public static function normalize_sso_link_mechanism( $value ): string {
		$allowed = array(
			self::SSO_LINK_MECHANISM_AUTO,
			self::SSO_LINK_MECHANISM_SAML,
			self::SSO_LINK_MECHANISM_SERVER,
			self::SSO_LINK_MECHANISM_DISABLED,
		);

		return in_array( $value, $allowed, true ) ? (string) $value : self::SSO_LINK_MECHANISM_AUTO;
	}

	/**
	 * Resolves the effective SSO link mechanism (docs/PLAN-wordpress-idp-option-b.md
	 * section 6).
	 *
	 * Pure and deterministic against the current request's plugin state: the
	 * `auto` value resolves against IdP detection rather than being returned
	 * literally, so every caller acts on the mechanism actually in effect
	 * rather than re-deriving it. `saml`, `server`, and `disabled` are explicit
	 * admin choices and resolve to themselves.
	 *
	 * Nothing reads this yet -- the linking step it will gate
	 * (`includes/wp-idp-link.php`) is not built. It exists now so the settings
	 * page has something real to display and admins can set the mechanism
	 * ahead of that work landing.
	 *
	 * @return string One of `saml`, `server`, or `disabled` -- never `auto`.
	 */
	public static function sso_link_mechanism(): string {
		$configured = self::normalize_sso_link_mechanism( get_option( 'agend_apps_sso_link_mechanism', self::SSO_LINK_MECHANISM_AUTO ) );

		if ( self::SSO_LINK_MECHANISM_AUTO !== $configured ) {
			return $configured;
		}

		// Any detected IdP plugin, not just agend-saml-idp: miniOrange asserts
		// a NameID of its own, so a miniOrange site left on server-to-server
		// linking would hit exactly the duplicate-identity problem the
		// warning on the settings page describes. Resolving against
		// `detected_idp_plugin()` also means the third-party escape hatch on
		// that method steers `auto` too.
		return '' !== self::detected_idp_plugin() ? self::SSO_LINK_MECHANISM_SAML : self::SSO_LINK_MECHANISM_SERVER;
	}

	/**
	 * Whether the WordPress-to-Agend identity link should be attempted the
	 * moment a WordPress user account is created (`user_register`), rather
	 * than only at the user's first sign-in.
	 *
	 * Off by default: this fires for every WordPress user created, including
	 * administrators and bulk imports, which is not always desired.
	 *
	 * @return bool True when link-on-create is enabled.
	 */
	public static function link_on_user_create(): bool {
		return (bool) get_option( 'agend_apps_sso_link_on_user_create', false );
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
