<?php
/**
 * Upbeat record refresh API client.
 *
 * Asks the gateway to pull one Upbeat record now, so a change a member has
 * just made reaches the site without waiting for the next poll.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Longest the member's request waits for the gateway to answer. A filter that
 * replaces the request's `timeout` widens that wait.
 */
const AGEND_APPS_RECORD_REFRESH_TIMEOUT_SECONDS = 3;

/**
 * Requests a refresh of one Upbeat record.
 *
 * Requires the `workflows.records.refresh` scope on the API key. Fire and
 * forget: it returns nothing and never throws, so a caller's own work (a
 * member's save confirmation) cannot be held up or broken by it. A failure,
 * a non-2xx answer or a timeout is recorded in the Agend API log by the API
 * client and swallowed here. Nothing is sent when either argument is empty.
 *
 * The call is made without the member token: the refresh is the site's
 * request, authorised by its API key alone.
 *
 * @param string $resource_path The Upbeat resource path, for example `members`.
 * @param string $lookup_value  The value identifying the record, for example a membership number.
 * @return void
 */
function agend_apps_request_upbeat_record_refresh( string $resource_path, string $lookup_value ): void {
	$resource_path = trim( $resource_path );
	$lookup_value  = trim( $lookup_value );

	if ( '' === $resource_path || '' === $lookup_value ) {
		return;
	}

	try {
		$args = array(
			'body'       => array(
				'resource_path' => $resource_path,
				'lookup_value'  => $lookup_value,
			),
			'timeout'    => AGEND_APPS_RECORD_REFRESH_TIMEOUT_SECONDS,
			// No member token, and no bearer resolver in the member's request.
			'unattended'          => true,
			// The member's save must never be refused by stale local
			// rate-limit state; the gateway's own answer is logged instead.
			'isolated_rate_limit' => true,
			// A stale "scope missing" verdict is acted on without the
			// synchronous /health recheck, which would exceed the timeout.
			'scope_recheck'       => false,
		);

		/**
		 * Filters the record refresh request args before the request is sent.
		 * `timeout` bounds how long the member's save waits.
		 *
		 * @param array  $args          Request args.
		 * @param string $resource_path Upbeat resource path.
		 * @param string $lookup_value  Record lookup value.
		 */
		$args = (array) apply_filters( 'agend_apps_upbeat_record_refresh_args', $args, $resource_path, $lookup_value );

		agend_apps_api()->request( 'POST', '/workflows/upbeat/record-refreshes', $args );
	} catch ( \Throwable $e ) {
		// The API client logs every exchange it makes, but a throw from a
		// filter or the logger happens before one. Nothing may reach the caller.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[Agend Apps] Upbeat record refresh failed: ' . get_class( $e ) . ': ' . $e->getMessage() );
		}
	}
}
