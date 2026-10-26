<?php
/**
 * Installs Agend theme releases without waiting for WordPress auto-updates.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Daily job that installs a newer Agend theme release itself.
 *
 * `Agend_Apps_Updater` tells WordPress about new Agend theme versions and asks
 * for them to auto-update, but WordPress only installs them from its own
 * twice-daily background updater, and hosts can switch that off (Kinsta's
 * "Automatic updates: Manual" does). This job checks the theme manifest once
 * a day and runs the same `Theme_Upgrader` an admin's "Update now" click runs,
 * so a release reaches every site regardless of that setting.
 *
 * It also runs about a minute after Agend Apps Core itself is installed or
 * updated, and after an admin clicks "Check for updates" under an Agend
 * plugin, so a release can be pushed to a site without waiting a day. Those
 * runs are queued as single cron events rather than run inline, so the theme
 * never installs inside the same request as a plugin update.
 *
 * Guard rails:
 * - Only themes whose `Update URI` points at the Agend update host, and only
 *   when the manifest offers a higher version.
 * - Never on a site whose files are under version control (a local
 *   development copy), where `DISALLOW_FILE_MODS` forbids file changes, or on
 *   a multisite sub-site.
 * - Never on a theme whose folder is a symlink. That is how DeployHQ installs
 *   what it deploys (a link into its own releases directory), so a linked
 *   theme belongs to a deployment tool; replacing it here would fight that
 *   tool and could break its next deploy.
 * - A site can opt a theme out with the `agend_apps_theme_auto_update` filter,
 *   the same filter that governs WordPress's own auto-update for it.
 * - After installing, the home page is requested; a 5xx response puts the
 *   previous version back from WordPress's own update backup, and that
 *   release is not tried again until a newer one is published.
 * - Each run is recorded in the `agend_apps_theme_self_update_log` option.
 *
 * Testable via dependency injection: `set_theme_lister()`, `set_guard()`,
 * `set_installer()` and `set_health_checker()` swap the WordPress-facing
 * steps, and `reset_overrides()` restores the defaults. Production code never
 * calls the setters; only tests do.
 */
class Agend_Apps_Theme_Self_Updater {

	/**
	 * Cron hook the daily check runs on.
	 *
	 * @var string
	 */
	const CRON_HOOK = 'agend_apps_theme_self_update';

	/**
	 * Cron hook for a one-off check queued after a plugin update or a manual
	 * "Check for updates".
	 *
	 * @var string
	 */
	const NOW_HOOK = 'agend_apps_theme_self_update_now';

	/**
	 * Option holding the Agend Apps Core version the job last saw, so a
	 * plugin update is noticed on the next request.
	 *
	 * @var string
	 */
	const SEEN_VERSION_OPTION = 'agend_apps_theme_self_update_core_version';

	/**
	 * Upgrader lock name, so overlapping cron runs never install twice.
	 *
	 * @var string
	 */
	const LOCK_NAME = 'agend_apps_theme_self_update';

	/**
	 * Option holding the most recent run results, newest first.
	 *
	 * @var string
	 */
	const LOG_OPTION = 'agend_apps_theme_self_update_log';

	/**
	 * How many log entries are kept.
	 *
	 * @var int
	 */
	const LOG_LIMIT = 20;

	/**
	 * Option mapping stylesheet => a release that was rolled back, so it is
	 * not reinstalled on every run.
	 *
	 * @var string
	 */
	const BAD_RELEASE_OPTION = 'agend_apps_theme_self_update_bad_releases';

	/**
	 * Injected theme lister, `null` for the WordPress default.
	 *
	 * @var callable|null
	 */
	private static $theme_lister = null;

	/**
	 * Injected environment guard, `null` for the WordPress default.
	 *
	 * @var callable|null
	 */
	private static $guard = null;

	/**
	 * Injected installer, `null` for the WordPress default.
	 *
	 * @var callable|null
	 */
	private static $installer = null;

	/**
	 * Injected post-install health check, `null` for the WordPress default.
	 *
	 * @var callable|null
	 */
	private static $health_checker = null;

	/**
	 * Injected Agend Apps Core version, `null` for `AGEND_APPS_CORE_VERSION`.
	 *
	 * @var string|null
	 */
	private static $core_version = null;

	/**
	 * Registers the cron callbacks and makes sure the daily event exists.
	 */
	public static function boot() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run' ) );
		add_action( self::NOW_HOOK, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	/**
	 * Schedules the daily check if it is not already scheduled, and queues a
	 * one-off check when Agend Apps Core has been installed or updated since
	 * the last request.
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'daily', self::CRON_HOOK );
		}

		$version = null !== self::$core_version ? self::$core_version : ( defined( 'AGEND_APPS_CORE_VERSION' ) ? AGEND_APPS_CORE_VERSION : '' );

		if ( '' !== $version && get_option( self::SEEN_VERSION_OPTION, '' ) !== $version ) {
			update_option( self::SEEN_VERSION_OPTION, $version, false );
			self::queue_now();
		}
	}

	/**
	 * Queues a one-off check about a minute from now, unless one is already
	 * queued. Called after a plugin update and from "Check for updates".
	 */
	public static function queue_now() {
		if ( ! wp_next_scheduled( self::NOW_HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::NOW_HOOK );
		}
	}

	/**
	 * Swaps the Agend Apps Core version the job compares against. Test-only.
	 *
	 * @param string|null $version Version, `null` for the real constant.
	 */
	public static function set_core_version( $version ) {
		self::$core_version = $version;
	}

	/**
	 * Swaps the theme lister. Test-only.
	 *
	 * @param callable|null $lister Returns `array<string, array>` of stylesheet
	 *                              => headers (`Version`, `UpdateURI`), plus
	 *                              `_symlink` (bool) when the theme folder is a link.
	 */
	public static function set_theme_lister( $lister ) {
		self::$theme_lister = $lister;
	}

	/**
	 * Swaps the environment guard. Test-only.
	 *
	 * @param callable|null $guard Returns a reason string to skip the run, or ''.
	 */
	public static function set_guard( $guard ) {
		self::$guard = $guard;
	}

	/**
	 * Swaps the installer. Test-only.
	 *
	 * @param callable|null $installer Receives the stylesheet and target version, returns
	 *                                 `array{result: mixed, restore: callable}`.
	 */
	public static function set_installer( $installer ) {
		self::$installer = $installer;
	}

	/**
	 * Swaps the post-install health check. Test-only.
	 *
	 * @param callable|null $checker Returns true when the site still serves pages.
	 */
	public static function set_health_checker( $checker ) {
		self::$health_checker = $checker;
	}

	/**
	 * Restores every injected step to the WordPress default. Test-only.
	 */
	public static function reset_overrides() {
		self::$theme_lister   = null;
		self::$guard          = null;
		self::$installer      = null;
		self::$health_checker = null;
		self::$core_version   = null;
	}

	/**
	 * Cron callback: installs every Agend theme the manifest has a newer
	 * release of.
	 */
	public static function run() {
		// Most sites running agend-apps-core have no Agend theme at all. They
		// stop here: no guard checks, no admin includes, no manifest request,
		// no log entry.
		$themes = self::list_agend_themes();

		if ( array() === $themes ) {
			return;
		}

		$blocked = null !== self::$guard ? (string) call_user_func( self::$guard ) : self::default_guard();

		if ( '' !== $blocked ) {
			self::log( '', 'skipped', $blocked );
			return;
		}

		// The run's own fetch is the freshest view of the manifest; the
		// updater's 12-hour cache would otherwise delay a release.
		Agend_Apps_Updater::flush_theme_cache();

		foreach ( $themes as $stylesheet => $headers ) {
			$installed = (string) ( $headers['Version'] ?? '' );
			$offer     = Agend_Apps_Updater::filter_theme_update( false, $headers, $stylesheet, array() );

			if ( ! is_array( $offer ) || empty( $offer['new_version'] ) || ! version_compare( (string) $offer['new_version'], $installed, '>' ) ) {
				continue;
			}

			if ( self::is_bad_release( $stylesheet, (string) $offer['new_version'] ) ) {
				continue;
			}

			if ( ! empty( $headers['_symlink'] ) ) {
				self::log( $stylesheet, 'skipped', 'the theme folder is a symlink, so a deployment tool manages it' );
				continue;
			}

			if ( ! apply_filters( 'agend_apps_theme_auto_update', true, $stylesheet ) ) {
				self::log( $stylesheet, 'skipped', 'opted out by agend_apps_theme_auto_update' );
				continue;
			}

			if ( ! self::requirements_met( $offer ) ) {
				self::log( $stylesheet, 'skipped', sprintf( 'version %s needs WordPress %s and PHP %s', $offer['new_version'], $offer['requires'] ?? '', $offer['requires_php'] ?? '' ) );
				continue;
			}

			self::install( $stylesheet, $installed, (string) $offer['new_version'] );
		}
	}

	/**
	 * Installs one theme release, checks the site afterwards and rolls back
	 * on a server error.
	 *
	 * @param string $stylesheet Theme directory name.
	 * @param string $from       Installed version.
	 * @param string $to         Version being installed.
	 */
	private static function install( $stylesheet, $from, $to ) {
		$outcome = null !== self::$installer
			? call_user_func( self::$installer, $stylesheet, $to )
			: self::default_install( $stylesheet, $to );

		$result  = $outcome['result'] ?? false;
		$restore = $outcome['restore'] ?? null;

		if ( is_wp_error( $result ) && 'agend_theme_locked' === $result->get_error_code() ) {
			self::log( $stylesheet, 'skipped', $result->get_error_message() );
			return;
		}

		if ( true !== $result ) {
			$message = is_wp_error( $result ) ? $result->get_error_message() : 'the upgrader did not complete';
			self::log( $stylesheet, 'failed', sprintf( '%s to %s: %s', $from, $to, $message ) );
			return;
		}

		$healthy = null !== self::$health_checker
			? (bool) call_user_func( self::$health_checker )
			: self::default_health_check();

		if ( $healthy ) {
			self::log( $stylesheet, 'updated', sprintf( '%s to %s', $from, $to ) );
			return;
		}

		if ( is_callable( $restore ) ) {
			call_user_func( $restore );
		}

		$bad                = get_option( self::BAD_RELEASE_OPTION, array() );
		$bad                = is_array( $bad ) ? $bad : array();
		$bad[ $stylesheet ] = $to;
		update_option( self::BAD_RELEASE_OPTION, $bad, false );

		self::log( $stylesheet, 'rolled_back', sprintf( '%s to %s: the home page returned a server error, %s restored', $from, $to, $from ) );
	}

	/**
	 * Whether this release of a theme was installed and rolled back before.
	 *
	 * @param string $stylesheet Theme directory name.
	 * @param string $version    Offered version.
	 * @return bool
	 */
	private static function is_bad_release( $stylesheet, $version ) {
		$bad = get_option( self::BAD_RELEASE_OPTION, array() );

		return is_array( $bad ) && isset( $bad[ $stylesheet ] ) && (string) $bad[ $stylesheet ] === $version;
	}

	/**
	 * Lists installed themes whose `Update URI` is on the Agend update host.
	 *
	 * @return array<string, array<string, string>> Stylesheet => headers.
	 */
	private static function list_agend_themes() {
		$themes = null !== self::$theme_lister ? (array) call_user_func( self::$theme_lister ) : self::default_theme_list();
		$agend  = array();

		foreach ( $themes as $stylesheet => $headers ) {
			$host = wp_parse_url( (string) ( $headers['UpdateURI'] ?? '' ), PHP_URL_HOST );

			if ( Agend_Apps_Updater::UPDATE_HOST === $host ) {
				$agend[ (string) $stylesheet ] = $headers;
			}
		}

		return $agend;
	}

	/**
	 * Whether this site can run the offered release.
	 *
	 * @param array<string, string> $offer Update offer from the manifest.
	 * @return bool
	 */
	private static function requirements_met( array $offer ) {
		$requires_wp  = (string) ( $offer['requires'] ?? '' );
		$requires_php = (string) ( $offer['requires_php'] ?? '' );

		if ( '' !== $requires_wp && version_compare( (string) get_bloginfo( 'version' ), $requires_wp, '<' ) ) {
			return false;
		}

		return '' === $requires_php || version_compare( PHP_VERSION, $requires_php, '>=' );
	}

	/**
	 * Appends a run result to the log option, newest first.
	 *
	 * @param string $stylesheet Theme directory name, '' for a whole-run skip.
	 * @param string $status     updated|failed|rolled_back|skipped.
	 * @param string $detail     Human-readable detail.
	 */
	private static function log( $stylesheet, $status, $detail ) {
		$log = get_option( self::LOG_OPTION, array() );
		$log = is_array( $log ) ? $log : array();

		array_unshift(
			$log,
			array(
				'time'   => gmdate( 'Y-m-d H:i:s' ),
				'theme'  => $stylesheet,
				'status' => $status,
				'detail' => $detail,
			)
		);

		update_option( self::LOG_OPTION, array_slice( $log, 0, self::LOG_LIMIT ), false );
	}

	/**
	 * Default guard: the reasons a site must never have files changed under it.
	 *
	 * @return string Reason to skip, or ''.
	 */
	private static function default_guard() {
		if ( is_multisite() && ! is_main_site() ) {
			return 'multisite sub-site';
		}

		if ( ! wp_is_file_mod_allowed( self::CRON_HOOK ) ) {
			return 'file changes are disallowed on this site';
		}

		self::load_upgrader();

		$updater = new WP_Automatic_Updater();

		if ( $updater->is_vcs_checkout( get_theme_root() ) ) {
			return 'the theme directory is under version control';
		}

		return '';
	}

	/**
	 * Default theme list from WordPress.
	 *
	 * @return array<string, array<string, string>>
	 */
	private static function default_theme_list() {
		$list = array();

		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			$list[ $stylesheet ] = array(
				'Version'   => (string) $theme->get( 'Version' ),
				'UpdateURI' => (string) $theme->get( 'UpdateURI' ),
				'_symlink'  => is_link( untrailingslashit( $theme->get_theme_root() ) . '/' . $stylesheet ),
			);
		}

		return $list;
	}

	/**
	 * Default installer: the same `Theme_Upgrader::upgrade()` an admin's
	 * "Update now" runs, which moves the old version into WordPress's
	 * `upgrade-temp-backup` directory first and restores it if the install
	 * itself fails.
	 *
	 * @param string $stylesheet Theme directory name.
	 * @param string $to         Version the manifest offers.
	 * @return array{result: mixed, restore: callable|null}
	 */
	private static function default_install( $stylesheet, $to ) {
		self::load_upgrader();

		if ( ! WP_Upgrader::create_lock( self::LOCK_NAME, 15 * MINUTE_IN_SECONDS ) ) {
			return array(
				'result'  => new WP_Error( 'agend_theme_locked', 'another update run is in progress' ),
				'restore' => null,
			);
		}

		// Theme_Upgrader::upgrade() installs what the update_themes transient
		// offers, so make sure it holds the manifest's release.
		$current = get_site_transient( 'update_themes' );
		$offered = (string) ( $current->response[ $stylesheet ]['new_version'] ?? '' );

		if ( $offered !== $to ) {
			delete_site_transient( 'update_themes' );
			wp_update_themes();
		}

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Theme_Upgrader( $skin );
		$result   = $upgrader->upgrade( $stylesheet, array( 'clear_update_cache' => true ) );

		// A failed download or unpack returns false with the reason in the
		// skin's messages; surface it in the log.
		if ( true !== $result && ! is_wp_error( $result ) ) {
			$messages = array_filter( array_map( static fn( $m ) => html_entity_decode( wp_strip_all_tags( (string) $m ), ENT_QUOTES ), (array) $skin->get_upgrade_messages() ) );
			$result   = new WP_Error( 'agend_theme_upgrade_failed', $messages ? implode( ' ', array_slice( $messages, -3 ) ) : 'the upgrader did not complete' );
		}

		WP_Upgrader::release_lock( self::LOCK_NAME );
		wp_clean_themes_cache();

		return array(
			'result'  => $result,
			'restore' => static function () use ( $upgrader, $stylesheet ) {
				$upgrader->restore_temp_backup(
					array(
						array(
							'slug' => $stylesheet,
							'src'  => get_theme_root( $stylesheet ),
							'dir'  => 'themes',
						),
					)
				);
				wp_clean_themes_cache();
			},
		);
	}

	/**
	 * Default health check: the home page must not return a server error. A
	 * request that cannot be made at all (loopback blocked) is not treated
	 * as a failure, since it says nothing about the new theme.
	 *
	 * @return bool
	 */
	private static function default_health_check() {
		$response = wp_remote_get(
			add_query_arg( 'agend-theme-check', time(), home_url( '/' ) ),
			array(
				'timeout'     => 20,
				'redirection' => 3,
			)
		);

		if ( is_wp_error( $response ) ) {
			return true;
		}

		return (int) wp_remote_retrieve_response_code( $response ) < 500;
	}

	/**
	 * Loads the admin upgrader classes, which cron requests do not load.
	 */
	private static function load_upgrader() {
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	}
}

/**
 * Registers the theme self-updater's hooks.
 *
 * Called unconditionally next to `agend_apps_updater_boot()`: the job runs
 * from `wp-cron.php`, where the full bootstrap cannot be relied on.
 */
function agend_apps_theme_self_updater_boot() {
	Agend_Apps_Theme_Self_Updater::boot();
}
