<?php
/**
 * Uninstall routine for Agend Apps Core.
 *
 * Removes all plugin options and transients from the database when the plugin
 * is deleted via the WordPress admin. Settings are intentionally kept on
 * deactivation; this file handles the permanent removal case only.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Remove all agend_apps_* options.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( 'agend_apps_' ) . '%'
	)
);

// Remove all agend_apps transients (values and their timeout entries).
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_agend_apps_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_agend_apps_' ) . '%'
	)
);

// Drop the API log table on every site: each site in a network keeps its
// own. The plugin is not loaded during uninstall, so the table name is
// spelled out rather than read from Agend_Apps_Log_Store.
$agend_apps_site_ids = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( 0 );

foreach ( $agend_apps_site_ids as $agend_apps_site_id ) {
	if ( $agend_apps_site_id ) {
		switch_to_blog( (int) $agend_apps_site_id );
	}

	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}agend_apps_api_log" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	wp_clear_scheduled_hook( 'agend_apps_api_log_prune' );

	if ( $agend_apps_site_id ) {
		restore_current_blog();
	}
}
