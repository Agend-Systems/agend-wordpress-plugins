<?php
/**
 * Plugin Name:       Agend Loop Sync
 * Plugin URI:        https://agend.dev
 * Description:       Syncs WordPress users, roles, and Upbeat committees into Agend Loop channels via the Agend gateway. Users sync on login/role change; committees sync on a schedule or on demand.
 * Version:           1.0.0
 * Author:            Agend
 * Author URI:        https://agend.dev
 * Text Domain:       agend-loop-sync
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  agend-apps-core, iugo-membership-kiosk
 *
 * @package Agend_Loop_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin version.
 *
 * @var string
 */
define( 'AGEND_LOOP_SYNC_VERSION', '1.0.0' );

/**
 * Absolute path to the plugin directory, with trailing slash.
 *
 * @var string
 */
define( 'AGEND_LOOP_SYNC_DIR', plugin_dir_path( __FILE__ ) );

/**
 * URL to the plugin directory, with trailing slash.
 *
 * @var string
 */
define( 'AGEND_LOOP_SYNC_URL', plugin_dir_url( __FILE__ ) );

/**
 * WP-Cron hook name for the scheduled committee sync.
 *
 * @var string
 */
define( 'AGEND_LOOP_SYNC_CRON_HOOK', 'agend_loop_sync_committee_event' );

/**
 * Loads plugin includes and boots the controller.
 *
 * Hooked on `plugins_loaded` so agend-apps-core and iugo-membership-kiosk are
 * loaded before any dependency check or sync runs.
 */
function agend_loop_sync_bootstrap() {
	require_once AGEND_LOOP_SYNC_DIR . 'includes/class-agend-loop-sync-logger.php';
	require_once AGEND_LOOP_SYNC_DIR . 'includes/class-agend-loop-sync-dependencies.php';
	require_once AGEND_LOOP_SYNC_DIR . 'includes/class-agend-loop-sync-settings.php';
	require_once AGEND_LOOP_SYNC_DIR . 'includes/class-agend-loop-sync-role-mapper.php';
	require_once AGEND_LOOP_SYNC_DIR . 'includes/class-agend-loop-sync-contact-resolver.php';
	require_once AGEND_LOOP_SYNC_DIR . 'includes/class-agend-loop-sync-user-sync.php';
	require_once AGEND_LOOP_SYNC_DIR . 'includes/class-agend-loop-sync-committee-sync.php';
	require_once AGEND_LOOP_SYNC_DIR . 'includes/class-agend-loop-sync.php';

	if ( is_admin() ) {
		require_once AGEND_LOOP_SYNC_DIR . 'admin/class-agend-loop-sync-admin.php';
	}

	Agend_Loop_Sync::instance()->init();
}
add_action( 'plugins_loaded', 'agend_loop_sync_bootstrap' );

/**
 * Activation hook: seeds default options and schedules the committee cron.
 */
function agend_loop_sync_activate() {
	require_once AGEND_LOOP_SYNC_DIR . 'includes/class-agend-loop-sync-settings.php';

	Agend_Loop_Sync_Settings::seed_defaults();

	$schedule = Agend_Loop_Sync_Settings::get_committee_schedule();
	if ( ! wp_next_scheduled( AGEND_LOOP_SYNC_CRON_HOOK ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, $schedule, AGEND_LOOP_SYNC_CRON_HOOK );
	}
}
register_activation_hook( __FILE__, 'agend_loop_sync_activate' );

/**
 * Deactivation hook: clears the scheduled committee cron.
 *
 * Options persist across deactivation so reconfiguration is not required on
 * re-activation.
 */
function agend_loop_sync_deactivate() {
	$timestamp = wp_next_scheduled( AGEND_LOOP_SYNC_CRON_HOOK );
	if ( false !== $timestamp ) {
		wp_unschedule_event( $timestamp, AGEND_LOOP_SYNC_CRON_HOOK );
	}
}
register_deactivation_hook( __FILE__, 'agend_loop_sync_deactivate' );
