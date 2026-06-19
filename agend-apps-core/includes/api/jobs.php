<?php
/**
 * Jobs API functions.
 *
 * Server-side PHP wrappers for the Agend gateway's `/v1/jobs/*` endpoints.
 * Sibling plugins MUST call these helpers rather than building gateway paths
 * or calling `agend_apps_api()` directly, so transport and path knowledge stay
 * centralised here.
 *
 * Public catalogue reads (jobs) are cached. All endpoints rely on the Agend
 * API key and are accessed via the public, unauthenticated browse scope
 * `jobs.listings.browse`.
 *
 * Every function returns the decoded response array on success or a WP_Error
 * on failure (transport error, non-2xx, or invalid JSON).
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists jobs.
 *
 * Scope: `jobs.listings.browse`. Cached.
 *
 * @param array $query Optional. Query parameters (camelCase): `page`, `limit`, `search`, `sortBy`, `sortOrder`, date filters. Default empty.
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_jobs_get_jobs( array $query = array() ) {
	/**
	 * Filters the jobs list request args before the request is sent.
	 *
	 * @param array $args  Request args.
	 * @param array $query Original query parameters.
	 */
	$args = (array) apply_filters(
		'agend_apps_jobs_get_jobs_args',
		array( 'query' => $query ),
		$query
	);

	$cache_key = Agend_Apps_Cache::build_key( 'jobs_list', $query );
	$ttl       = Agend_Apps_Settings::get_cache_ttl( 'jobs_list' );

	$response = agend_apps_api()->get_cached( '/jobs', $args, $cache_key, $ttl );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded jobs list response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $query    Original query parameters.
	 */
	return apply_filters( 'agend_apps_jobs_get_jobs_response', $response, $query );
}

/**
 * Retrieves a single job by slug or ID.
 *
 * Scope: `jobs.listings.browse`. Cached.
 *
 * @param string $slug_or_id Job slug or UUID.
 * @return array|WP_Error Decoded job on success, or WP_Error on failure.
 */
function agend_apps_jobs_get_job( string $slug_or_id ) {
	/**
	 * Filters the single-job request args before the request is sent.
	 *
	 * @param array  $args        Request args.
	 * @param string $slug_or_id  Job slug or UUID.
	 */
	$args = (array) apply_filters( 'agend_apps_jobs_get_job_args', array(), $slug_or_id );

	$cache_key = Agend_Apps_Cache::build_key( 'jobs_single', array( 'slug_or_id' => $slug_or_id ) );
	$ttl       = Agend_Apps_Settings::get_cache_ttl( 'jobs_single' );

	$response = agend_apps_api()->get_cached( '/jobs/' . rawurlencode( $slug_or_id ), $args, $cache_key, $ttl );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded single-job response before it is returned.
	 *
	 * @param array  $response   Decoded response body.
	 * @param string $slug_or_id Job slug or UUID.
	 */
	return apply_filters( 'agend_apps_jobs_get_job_response', $response, $slug_or_id );
}
