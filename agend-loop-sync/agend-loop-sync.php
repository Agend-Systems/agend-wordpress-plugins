<?php
/**
 * Plugin Name:       Agend Loop Sync
 * Plugin URI:        https://agend.com.au
 * Update URI:        https://agend-systems.github.io/agend-wordpress-plugins/agend-loop-sync
 * Description:       Syncs WordPress members into Agend Loop via the Agend gateway, on sign-in, profile and role changes. Optionally keeps a private Loop channel for each Upbeat committee, and criteria-based community channels from membership rules, on a schedule or on demand.
 * Version:           1.6.1
 * Author:            Agend
 * Author URI:        https://agend.com.au
 * Text Domain:       agend-loop-sync
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Tested up to:      7.1
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
define( 'AGEND_LOOP_SYNC_VERSION', '1.6.1' );

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
 * WP-Cron hook name for the scheduled community rule sync.
 *
 * @var string
 */
define( 'AGEND_LOOP_SYNC_RULES_CRON_HOOK', 'agend_loop_sync_rules_event' );

/**
 * WP-Cron hook name for the single, debounced community rule run scheduled after
 * a membership change or sign-in.
 *
 * @var string
 */
define( 'AGEND_LOOP_SYNC_RULES_DEBOUNCE_HOOK', 'agend_loop_sync_rules_debounced_event' );

/**
 * WP-Cron hook name for the single retry, about an hour out, of members whose
 * Loop account could not be created yet.
 *
 * @var string
 */
define( 'AGEND_LOOP_SYNC_PROVISION_RETRY_HOOK', 'agend_loop_sync_provision_retry_event' );

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
	require_once AGEND_LOOP_SYNC_DIR . 'includes/class-agend-loop-sync-gateway.php';
	require_once AGEND_LOOP_SYNC_DIR . 'includes/class-agend-loop-sync-provision-retry.php';
	require_once AGEND_LOOP_SYNC_DIR . 'includes/class-agend-loop-sync-user-sync.php';
	require_once AGEND_LOOP_SYNC_DIR . 'includes/class-agend-loop-sync-committee-sync.php';
	require_once AGEND_LOOP_SYNC_DIR . 'includes/class-agend-loop-sync-rule-matcher.php';
	require_once AGEND_LOOP_SYNC_DIR . 'includes/class-agend-loop-sync-rule-sync.php';
	require_once AGEND_LOOP_SYNC_DIR . 'includes/class-agend-loop-sync.php';

	if ( is_admin() ) {
		require_once AGEND_LOOP_SYNC_DIR . 'admin/class-agend-loop-sync-admin.php';
	}

	Agend_Loop_Sync::instance()->init();
}
add_action( 'plugins_loaded', 'agend_loop_sync_bootstrap' );

/**
 * Activation hook: seeds default options and schedules the committee and rule crons.
 */
function agend_loop_sync_activate() {
	require_once AGEND_LOOP_SYNC_DIR . 'includes/class-agend-loop-sync-settings.php';

	Agend_Loop_Sync_Settings::activate();
}
register_activation_hook( __FILE__, 'agend_loop_sync_activate' );

/**
 * Deactivation hook: clears the scheduled committee, rule and provisioning retry crons.
 *
 * Options persist across deactivation so reconfiguration is not required on
 * re-activation.
 */
function agend_loop_sync_deactivate() {
	$timestamp = wp_next_scheduled( AGEND_LOOP_SYNC_CRON_HOOK );
	if ( false !== $timestamp ) {
		wp_unschedule_event( $timestamp, AGEND_LOOP_SYNC_CRON_HOOK );
	}

	wp_clear_scheduled_hook( AGEND_LOOP_SYNC_RULES_CRON_HOOK );
	wp_clear_scheduled_hook( AGEND_LOOP_SYNC_RULES_DEBOUNCE_HOOK );
	wp_clear_scheduled_hook( AGEND_LOOP_SYNC_PROVISION_RETRY_HOOK );
}
register_deactivation_hook( __FILE__, 'agend_loop_sync_deactivate' );
