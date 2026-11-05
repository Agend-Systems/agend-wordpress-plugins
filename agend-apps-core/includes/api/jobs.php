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
 * @param array $query Optional. Query parameters (camelCase): `page`, `limit`, `search`, `sortBy`, `sortOrder`,
 *                     `state`, `postedAfter`, `featuredOnly`, `featuredFirst`, and the list filters `category`,
 *                     `tag`, `type`, `locationType` and `experienceLevel` supplied as arrays. Default empty.
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_jobs_get_jobs( array $query = array() ) {
	/**
	 * Filters the jobs list request args before the request is sent.
	 *
	 * @param array $args  Request args.
	 * @param array $query Original query parameters.
	 */
	// List-valued filters (category, tag, type, locationType,
	// experienceLevel) repeat on the gateway; split them out so the client
	// sends repeated bare keys rather than PHP-style brackets.
	$scalar = array();
	$multi  = array();

	foreach ( $query as $key => $value ) {
		if ( is_array( $value ) ) {
			$multi[ $key ] = array_values( $value );
		} else {
			$scalar[ $key ] = $value;
		}
	}

	$request_args = array( 'query' => $scalar );

	if ( $multi ) {
		$request_args['query_multi'] = $multi;
	}

	$args = (array) apply_filters(
		'agend_apps_jobs_get_jobs_args',
		$request_args,
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

/**
 * Lists the account's active job categories, in the board's display order.
 *
 * Scope: `jobs.listings.browse`. Cached.
 *
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_jobs_get_categories() {
	return agend_apps_jobs_get_terms( 'categories' );
}

/**
 * Lists the account's active job tags.
 *
 * Scope: `jobs.listings.browse`. Cached.
 *
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_jobs_get_tags() {
	return agend_apps_jobs_get_terms( 'tags' );
}

/**
 * Reads one of the jobs board's term lists.
 *
 * @param string $kind `categories` or `tags`.
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_jobs_get_terms( string $kind ) {
	$cache_key = Agend_Apps_Cache::build_key( 'jobs_terms', array( 'kind' => $kind ) );
	$ttl       = Agend_Apps_Settings::get_cache_ttl( 'jobs_terms' );

	$response = agend_apps_api()->get_cached( '/jobs/' . $kind, array(), $cache_key, $ttl );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters a decoded jobs term list (categories or tags) before it is returned.
	 *
	 * @param array  $response Decoded response body.
	 * @param string $kind     `categories` or `tags`.
	 */
	return apply_filters( 'agend_apps_jobs_get_terms_response', $response, $kind );
}
