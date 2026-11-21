<?php
/**
 * Site-local closing dates shared by the public board and member submissions.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds WordPress-localised display and native date-input values when present.
 *
 * @param array $job Gateway job, already filtered for the visitor.
 * @return array
 */
function agend_apps_jobs_display_dates( array $job ): array {
	// Never trust derived values supplied upstream; WordPress owns the timezone.
	unset( $job['expires_at_formatted'], $job['expires_at_date'] );
	$iso = $job['expires_at'] ?? null;
	$timestamp = is_string( $iso ) && '' !== trim( $iso ) ? strtotime( $iso ) : false;
	if ( false !== $timestamp ) {
		$job['expires_at_formatted'] = wp_date( 'j F Y', $timestamp );
		$job['expires_at_date']      = wp_date( 'Y-m-d', $timestamp );
	}
	return $job;
}
