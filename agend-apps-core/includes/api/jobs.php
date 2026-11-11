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
 *                     `state`, `postedAfter`, `featuredOnly`, `featuredFirst`, `lat`, `lng` and `radius` (km, all three or
 *                     none; `sortBy=distance` needs them), and the list filters `category`,
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

/**
 * Lists the signed-in member's own job submissions, in every status, newest
 * first.
 *
 * Scope: `jobs.submissions.self_read`. Needs the member's bearer token, which
 * the client attaches for the current user. Never cached: a member must see a
 * submission they have just made, edited or withdrawn.
 *
 * @param array $query Optional. `page` and `limit`. Default empty.
 * @return array|WP_Error Decoded response (`data` items and `pagination`), or WP_Error on failure.
 */
function agend_apps_jobs_get_my_submissions( array $query = array() ) {
	return agend_apps_api()->request( 'GET', '/jobs/me', array( 'query' => $query ) );
}

/**
 * Submits a job for the signed-in member, paid for by a reserved credit.
 *
 * Scope: `jobs.submissions.self_manage`. The credit_ref is sent as the
 * request's `Idempotency-Key` as well as in the body: it is unique to one
 * reservation, so a submit that timed out can be retried with the same fields
 * and the gateway returns the first response instead of creating a second
 * job. Retry only with identical fields; different fields under the same key
 * get `409 IDEMPOTENCY_KEY_REUSED`.
 *
 * A `400` or `403` means no job was created, so the caller gives the credit
 * back with {@see Agend_Apps_Jobs_Credits::release()}. A `409`
 * (`CREDIT_REF_IN_USE` or `IDEMPOTENCY_KEY_REUSED`) means an earlier attempt
 * with this credit_ref reached the gateway, and a timeout or `5xx` leaves the
 * outcome unknown: in all three, never give the credit back here. Retry a
 * timeout with the same fields, and otherwise leave the reservation for the
 * sweep, which looks it up and settles it.
 *
 * @param array  $fields     Job fields, as accepted by `POST /v1/jobs/me`.
 * @param string $credit_ref The credit_ref {@see Agend_Apps_Jobs_Credits::reserve()} returned.
 * @return array|WP_Error The submission item, or WP_Error on failure.
 */
function agend_apps_jobs_submit( array $fields, string $credit_ref ) {
	$fields['credit_ref'] = $credit_ref;

	return agend_apps_api()->request(
		'POST',
		'/jobs/me',
		array(
			'body'    => $fields,
			'headers' => array( 'Idempotency-Key' => $credit_ref ),
		)
	);
}

/**
 * Finds the signed-in member's submission made with a credit_ref.
 *
 * A credit_ref can appear twice when a rejected or withdrawn submission's
 * reference was used again, so a live submission is preferred over a refused
 * one.
 *
 * @param string $credit_ref credit_ref.
 * @param int    $max_pages  Pages of 100 to read at most. Default 20.
 * @return array|null|WP_Error The submission item, null when there is none, or WP_Error on failure.
 */
function agend_apps_jobs_find_my_submission( string $credit_ref, int $max_pages = 20 ) {
	$match = null;

	for ( $page = 1; $page <= $max_pages; $page++ ) {
		$response = agend_apps_jobs_get_my_submissions(
			array(
				'page'  => $page,
				'limit' => 100,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! is_array( $response ) || ! isset( $response['data'] ) || ! is_array( $response['data'] ) ) {
			return new WP_Error( 'agend_jobs_submissions_invalid', 'The gateway returned an unexpected submissions list.' );
		}

		foreach ( $response['data'] as $item ) {
			if ( ! is_array( $item ) || ( $item['credit_ref'] ?? null ) !== $credit_ref ) {
				continue;
			}

			if ( ! in_array( (string) ( $item['status'] ?? '' ), array( 'rejected', 'withdrawn' ), true ) ) {
				return $item;
			}

			$match = $match ?? $item;
		}

		$total_pages = (int) ( $response['pagination']['total_pages'] ?? 1 );

		if ( $page >= $total_pages || array() === $response['data'] ) {
			return $match;
		}
	}

	return new WP_Error( 'agend_jobs_submissions_too_many', 'The member has too many submissions to search.' );
}
