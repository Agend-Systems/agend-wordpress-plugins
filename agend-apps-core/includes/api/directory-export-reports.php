<?php
/**
 * Directory export report API wrappers.
 *
 * Split out of api/directory.php so the editor's report-options code (and
 * its tests) can load the export report listing on its own, without the
 * listing and review wrappers that the block preview path is expected NOT to
 * have available in a unit test process.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retrieves the export reports the caller may run.
 *
 * Which reports come back depends on the caller: a report whose audience is
 * members only, or restricted to particular entitlements or segments, is
 * absent for a caller who does not qualify. A caller presenting only an API
 * key sees the anonymous set.
 *
 * Scope: `directory.export_reports.browse`. With `scope=all` in the request
 * query (see agend_apps_records_export_reports_authoring_listing()) and a key
 * that also holds `directory.listings.manage`, the gateway instead returns
 * every published report regardless of audience, for authoring; it answers
 * 403 without that scope and 422 for any other `scope` value.
 *
 * @return array|WP_Error Decoded response with `data` as a list of
 *                        `{id, name, description, audience, parameters}`,
 *                        or WP_Error on failure.
 */
function agend_apps_directory_get_export_reports() {
	// Never call an endpoint the connected key cannot use (SPEC-CORE-20260908
	// scope-gated features): a key without directory.export_reports.browse
	// gets a 403 for this call, which is exactly what the optional-feature
	// registry exists to pre-empt.
	if ( function_exists( 'agend_apps_records_feature_available' ) && ! agend_apps_records_feature_available( 'directory_export_reports' ) ) {
		return array( 'data' => array() );
	}

	/**
	 * Filters the export report list request args before the request is sent.
	 *
	 * @param array $args Request args.
	 */
	$args = (array) apply_filters( 'agend_apps_directory_get_export_reports_args', array() );

	// Keyed on the query the filter produced, not on a fixed empty array: the
	// editor's authoring listing (`scope=all`, every published report) and the
	// visitor-facing default listing (audience-filtered) must never share a
	// transient, or a designer's full list would be served to a visitor.
	$query     = isset( $args['query'] ) && is_array( $args['query'] ) ? $args['query'] : array();
	$cache_key = Agend_Apps_Cache::build_key( 'directory_export_reports', $query );
	$ttl       = Agend_Apps_Settings::get_cache_ttl( 'directory_export_reports' );

	// Identity-scoped: get_cached() bypasses the shared transient whenever a
	// bearer is attached, so a member's reachable report list is never served
	// to the next visitor.
	$response = agend_apps_api()->get_cached( '/directory/export-reports', $args, $cache_key, $ttl );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded export report list before it is returned.
	 *
	 * @param array $response Decoded response body.
	 */
	return apply_filters( 'agend_apps_directory_get_export_reports_response', $response );
}

/**
 * Runs an export report and returns the file it produced.
 *
 * Returns the file bytes plus its content headers rather than a decoded body,
 * so a proxy can stream it straight to the browser. Never cached: the output
 * depends on the caller's entitlements and on the parameters supplied.
 *
 * Scope: `directory.export_reports.run`.
 *
 * @param string $report_id  The report's uuid.
 * @param array  $parameters Report parameters, keyed by the parameter name the
 *                           report declares. Unknown names are rejected upstream.
 * @param string $format     'csv' or 'xlsx'.
 * @return array|WP_Error Array with `body`, `content_type` and
 *                        `content_disposition`, or WP_Error on failure.
 */
function agend_apps_directory_run_export_report( string $report_id, array $parameters = array(), string $format = 'csv' ) {
	$query = array( 'format' => in_array( $format, array( 'csv', 'xlsx' ), true ) ? $format : 'csv' );

	foreach ( $parameters as $name => $value ) {
		$name = trim( (string) $name );
		if ( '' === $name || 'format' === $name || is_array( $value ) ) {
			continue;
		}
		if ( null === $value || '' === (string) $value ) {
			continue;
		}
		$query[ $name ] = (string) $value;
	}

	return agend_apps_api()->request(
		'GET',
		'/directory/export-reports/' . rawurlencode( $report_id ),
		array(
			'query'   => $query,
			'raw'     => true,
			// An export can scan a large result set; the default client
			// timeout is tuned for small JSON reads.
			'timeout' => 30,
		)
	);
}
