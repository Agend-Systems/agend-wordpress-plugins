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
