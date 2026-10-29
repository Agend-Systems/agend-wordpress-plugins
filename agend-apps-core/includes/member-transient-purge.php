<?php
/**
 * Removes the member responses earlier versions stored as transients.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The transient rows a read carrying a member token was stored under before
 * such reads stopped being stored: the cache key, `_b`, and 16 hex characters
 * of the token's digest. Valid in MySQL and PCRE alike.
 */
const AGEND_APPS_MEMBER_TRANSIENT_PATTERN = '^_transient(_timeout)?_agend_apps_.+_b[0-9a-f]{16}$';

/** Set once the stored member responses have been removed. */
const AGEND_APPS_MEMBER_TRANSIENTS_PURGED_OPTION = 'agend_apps_member_transients_purged';

/**
 * Removes, once, every member response an earlier version stored, on the
 * first request after the update. They hold gated values in plain text, and
 * one stored with a lifetime of 0 never expires on its own.
 *
 * Under a persistent object cache transients are not in the options table,
 * so nothing is found there; those entries lapse with their lifetime.
 */
function agend_apps_maybe_purge_member_transients(): void {
	if ( '1' === (string) get_option( AGEND_APPS_MEMBER_TRANSIENTS_PURGED_OPTION, '' ) ) {
		return;
	}

	global $wpdb;

	$result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE ( option_name LIKE %s OR option_name LIKE %s ) AND option_name REGEXP %s",
			$wpdb->esc_like( '_transient_agend_apps_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_agend_apps_' ) . '%',
			AGEND_APPS_MEMBER_TRANSIENT_PATTERN
		)
	);

	// A failed delete is tried again on the next request.
	if ( false !== $result ) {
		update_option( AGEND_APPS_MEMBER_TRANSIENTS_PURGED_OPTION, '1', false );
	}
}
