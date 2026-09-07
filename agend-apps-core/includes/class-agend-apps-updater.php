<?php
/**
 * GitHub Releases updater.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves plugin update information from a static manifest published on
 * GitHub Pages, for every installed Agend plugin (not just Core).
 *
 * None of the Agend plugins are on WordPress.org, so each plugin's main file
 * declares `Update URI: https://agend-systems.github.io/agend-wordpress-plugins/{slug}`.
 * WordPress 5.8+ dispatches update checks for a given `Update URI` hostname
 * through a per-hostname `update-plugins_{hostname}` filter, so this one
 * class -- loaded unconditionally by Core, which every sibling plugin
 * depends on -- covers the whole family from a single manifest fetch.
 *
 * Testable via dependency injection: `set_http_fetcher()` swaps the HTTP
 * transport and `reset_overrides()` restores the default. Production code
 * never calls the setter; only tests do.
 */
class Agend_Apps_Updater {

	/**
	 * Hostname carried by every Agend plugin's `Update URI` header.
	 *
	 * @var string
	 */
	const UPDATE_HOST = 'agend-systems.github.io';

	/**
	 * Default manifest URL, filterable via `agend_apps_update_manifest_url`.
	 *
	 * @var string
	 */
	const DEFAULT_MANIFEST_URL = 'https://agend-systems.github.io/agend-wordpress-plugins/manifest.json';

	/**
	 * Transient name the fetched (or sentinel) manifest is cached under.
	 *
	 * @var string
	 */
	const CACHE_TRANSIENT = 'agend_apps_update_manifest';

	/**
	 * Default cache TTL in seconds (12 hours), filterable via
	 * `agend_apps_update_cache_ttl`.
	 *
	 * @var int
	 */
	const DEFAULT_CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * How long a failed fetch is cached, so a dead endpoint does not get
	 * hit again on every admin page load until this cools down.
	 *
	 * @var int
	 */
	const FAILURE_CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * Sentinel cached value marking the manifest as unavailable.
	 *
	 * @var string
	 */
	const FETCH_FAILED_SENTINEL = 'agend_apps_update_fetch_failed';

	/**
	 * Injected HTTP fetcher, `null` to use `wp_remote_get()`.
	 *
	 * @var callable|null
	 */
	private static $http_fetcher = null;

	/**
	 * Registers the hooks that serve updates from the manifest.
	 *
	 * Called unconditionally from the plugin's top-level requires (not from
	 * `agend_apps_core_bootstrap()`, which is hooked on `plugins_loaded` and
	 * may bail early): update checks run during `wp-cron.php` and the
	 * Dashboard > Updates screen, contexts the rest of the bootstrap does not
	 * need to have run for.
	 */
	public static function boot() {
		add_filter( 'update-plugins_' . self::UPDATE_HOST, array( __CLASS__, 'filter_plugin_update' ), 10, 4 );
		add_filter( 'plugins_api', array( __CLASS__, 'filter_plugins_api' ), 10, 3 );
		add_action( 'delete_site_transient_update_plugins', array( __CLASS__, 'flush_cache' ) );

		// `upgrader_source_selection` is intentionally NOT hooked here: our
		// release zips already have the plugin slug as their single top-level
		// folder (matching what WordPress expects after extraction), so there
		// is no mismatched folder name to rename.
	}

	/**
	 * Swaps the HTTP transport used to fetch the manifest. Test-only.
	 *
	 * @param callable|null $fetcher Receives `( string $url, array $args )`
	 *                               and must return the same shape as
	 *                               `wp_remote_get()`. `null` restores the
	 *                               default.
	 */
	public static function set_http_fetcher( $fetcher ) {
		self::$http_fetcher = $fetcher;
	}

	/**
	 * Restores the injected HTTP fetcher to the WordPress default. Test-only.
	 */
	public static function reset_overrides() {
		self::$http_fetcher = null;
	}

	/**
	 * Clears the cached manifest (and its failure sentinel).
	 *
	 * Public so a forced "Check again" on Dashboard > Updates -- which
	 * WordPress implements by deleting the `update_plugins` site transient --
	 * also bypasses our own cache instead of serving a stale manifest.
	 */
	public static function flush_cache() {
		delete_site_transient( self::CACHE_TRANSIENT );
	}

	/**
	 * `update-plugins_{hostname}` filter: reports a newer version for a
	 * single plugin, or leaves WordPress's own `$update` untouched.
	 *
	 * @param array|false $update      Update array WordPress already has, or false.
	 * @param array       $plugin_data Parsed plugin header data.
	 * @param string      $plugin_file Plugin file, relative to the plugins directory.
	 * @param string[]    $locales     Requested locales. Unused: the manifest is not localised.
	 * @return array|false
	 */
	public static function filter_plugin_update( $update, $plugin_data, $plugin_file, $locales ) {
		unset( $locales );

		$manifest = self::get_manifest();

		if ( empty( $manifest['plugins'] ) || ! is_array( $manifest['plugins'] ) ) {
			return $update;
		}

		$slug = self::resolve_slug( $plugin_data, $plugin_file, $manifest['plugins'] );

		if ( '' === $slug || ! isset( $manifest['plugins'][ $slug ] ) || ! is_array( $manifest['plugins'][ $slug ] ) ) {
			return $update;
		}

		$entry = $manifest['plugins'][ $slug ];

		if ( empty( $entry['version'] ) || empty( $entry['package'] ) ) {
			return $update;
		}

		$installed_version = (string) ( $plugin_data['Version'] ?? '' );

		if ( '' !== $installed_version && ! version_compare( (string) $entry['version'], $installed_version, '>' ) ) {
			return $update;
		}

		$result = array(
			'id'           => self::UPDATE_HOST . '/' . $slug,
			'slug'         => $slug,
			'plugin'       => $plugin_file,
			'version'      => (string) $entry['version'],
			'url'          => (string) ( $entry['url'] ?? '' ),
			'package'      => (string) $entry['package'],
			'requires'     => (string) ( $entry['requires'] ?? '' ),
			'requires_php' => (string) ( $entry['requires_php'] ?? '' ),
			'tested'       => (string) ( $entry['tested'] ?? '' ),
		);

		return $result;
	}

	/**
	 * `plugins_api` filter: fills the "View details" modal for a known slug.
	 *
	 * @param false|object|array $result The result object, array, or false (default).
	 * @param string             $action The type of information being requested.
	 * @param object             $args   Plugin API arguments, including `->slug`.
	 * @return false|object|array
	 */
	public static function filter_plugins_api( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		$slug = isset( $args->slug ) ? (string) $args->slug : '';

		if ( '' === $slug ) {
			return $result;
		}

		$manifest = self::get_manifest();

		if ( empty( $manifest['plugins'][ $slug ] ) || ! is_array( $manifest['plugins'][ $slug ] ) ) {
			return $result;
		}

		$entry = $manifest['plugins'][ $slug ];

		$info                  = new stdClass();
		$info->name            = (string) ( $entry['name'] ?? $slug );
		$info->slug            = $slug;
		$info->version         = (string) ( $entry['version'] ?? '' );
		$info->author          = self::format_author( $entry );
		$info->author_profile  = (string) ( $entry['author_url'] ?? '' );
		$info->homepage        = (string) ( $entry['author_url'] ?? '' );
		$info->requires        = (string) ( $entry['requires'] ?? '' );
		$info->requires_php    = (string) ( $entry['requires_php'] ?? '' );
		$info->tested          = (string) ( $entry['tested'] ?? '' );
		$info->last_updated    = (string) ( $entry['last_updated'] ?? '' );
		$info->download_link   = (string) ( $entry['package'] ?? '' );
		$info->sections        = is_array( $entry['sections'] ?? null ) ? $entry['sections'] : array();

		return $info;
	}

	/**
	 * Builds the `author` string as an HTML link, matching what the
	 * `plugins_api` "View details" modal expects.
	 *
	 * @param array<string, mixed> $entry Manifest entry for one plugin.
	 * @return string
	 */
	private static function format_author( array $entry ) {
		$name = (string) ( $entry['author'] ?? 'Agend' );
		$url  = (string) ( $entry['author_url'] ?? '' );

		if ( '' === $url ) {
			return esc_html( $name );
		}

		return sprintf( '<a href="%1$s">%2$s</a>', esc_url( $url ), esc_html( $name ) );
	}

	/**
	 * Resolves a plugin's manifest slug: first from its directory name (the
	 * normal case), falling back to the last path segment of its
	 * `UpdateURI` header when the directory name is not itself a key in the
	 * manifest (e.g. installed under a renamed folder).
	 *
	 * @param array                $plugin_data     Parsed plugin header data.
	 * @param string               $plugin_file     Plugin file, relative to the plugins directory.
	 * @param array<string, mixed> $manifest_plugins The manifest's `plugins` map.
	 * @return string Slug, or '' when none could be resolved.
	 */
	private static function resolve_slug( $plugin_data, $plugin_file, array $manifest_plugins ) {
		$dir_slug = dirname( $plugin_file );

		if ( '' !== $dir_slug && '.' !== $dir_slug && isset( $manifest_plugins[ $dir_slug ] ) ) {
			return $dir_slug;
		}

		$update_uri = (string) ( $plugin_data['UpdateURI'] ?? '' );

		if ( '' !== $update_uri ) {
			$parts = wp_parse_url( $update_uri );
			$path  = trim( (string) ( $parts['path'] ?? '' ), '/' );

			if ( '' !== $path ) {
				$segments = explode( '/', $path );
				$uri_slug = (string) end( $segments );

				if ( isset( $manifest_plugins[ $uri_slug ] ) ) {
					return $uri_slug;
				}
			}
		}

		// Neither candidate is a manifest key. Prefer the directory name (it
		// is at least a plausible slug) so the caller's "not in manifest"
		// check below fails cleanly rather than on an empty string.
		return '' !== $dir_slug && '.' !== $dir_slug ? $dir_slug : '';
	}

	/**
	 * Returns the manifest, from cache when fresh, otherwise fetched and
	 * re-cached (a failed fetch is cached as the failure sentinel).
	 *
	 * @return array{plugins?: array<string, array<string, mixed>>} Empty array on failure.
	 */
	private static function get_manifest() {
		$cached = get_site_transient( self::CACHE_TRANSIENT );

		if ( self::FETCH_FAILED_SENTINEL === $cached ) {
			return array();
		}

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$manifest = self::fetch_manifest();

		if ( null === $manifest ) {
			set_site_transient( self::CACHE_TRANSIENT, self::FETCH_FAILED_SENTINEL, self::get_failure_cache_ttl() );
			return array();
		}

		set_site_transient( self::CACHE_TRANSIENT, $manifest, self::get_cache_ttl() );

		return $manifest;
	}

	/**
	 * Fetches and decodes the manifest over HTTP.
	 *
	 * @return array{plugins?: array<string, array<string, mixed>>}|null Decoded manifest, or null on any failure.
	 */
	private static function fetch_manifest() {
		$url = (string) apply_filters( 'agend_apps_update_manifest_url', self::DEFAULT_MANIFEST_URL );

		$args = array(
			'timeout' => 10,
			'headers' => array(
				'Accept'     => 'application/json',
				'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
			),
		);

		$response = null !== self::$http_fetcher
			? call_user_func( self::$http_fetcher, $url, $args )
			: wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			return null;
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) || ! isset( $data['plugins'] ) || ! is_array( $data['plugins'] ) ) {
			return null;
		}

		return $data;
	}

	/**
	 * Resolves the fresh-manifest cache TTL, filterable via
	 * `agend_apps_update_cache_ttl`.
	 *
	 * @return int Seconds.
	 */
	private static function get_cache_ttl() {
		return (int) apply_filters( 'agend_apps_update_cache_ttl', self::DEFAULT_CACHE_TTL );
	}

	/**
	 * Resolves the failed-fetch cache TTL.
	 *
	 * @return int Seconds.
	 */
	private static function get_failure_cache_ttl() {
		return self::FAILURE_CACHE_TTL;
	}
}

/**
 * Registers the updater's hooks.
 *
 * Called unconditionally near the top of `agend-apps-core.php`, alongside
 * the other unconditional requires: update checks run in admin and
 * `wp-cron.php` contexts where `agend_apps_core_bootstrap()` (hooked on
 * `plugins_loaded`, and gated on settings that may not be configured yet)
 * cannot be relied on to have run.
 */
function agend_apps_updater_boot() {
	Agend_Apps_Updater::boot();
}
