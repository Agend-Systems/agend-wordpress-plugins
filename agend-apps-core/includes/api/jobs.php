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
 * Request args shared by the member job submission routes.
 *
 * These routes take the site API key alone and name the member by their
 * contact pair, so the signed-in member's bearer is never attached: a site
 * acts for many members with one key.
 *
 * @param array $args Further request args.
 * @return array
 */
function agend_apps_jobs_submission_args( array $args = array() ): array {
	return array_merge( array( 'unattended' => true ), $args );
}

/**
 * The contact pair as query args, for the GET routes.
 *
 * @param array $contact `external_source` and `external_id`.
 * @return array
 */
function agend_apps_jobs_contact_query( array $contact ): array {
	return array(
		'external_source' => (string) ( $contact['external_source'] ?? '' ),
		'external_id'     => (string) ( $contact['external_id'] ?? '' ),
	);
}

/**
 * Lists a member's job submissions, in every status, newest first.
 *
 * Scope: `jobs.submissions.browse`. A member the gateway has no contact for
 * gets an empty list. Never cached: a member must see a submission they have
 * just made, edited or withdrawn.
 *
 * @param array $contact The member's contact pair, `external_source` and `external_id`.
 * @param array $query   Optional. `page` and `limit`. Default empty.
 * @return array|WP_Error Decoded response (`data` items and `meta.pagination`), or WP_Error on failure.
 */
function agend_apps_jobs_get_submissions( array $contact, array $query = array() ) {
	return agend_apps_api()->request(
		'GET',
		'/jobs/submissions',
		agend_apps_jobs_submission_args( array( 'query' => array_merge( agend_apps_jobs_contact_query( $contact ), $query ) ) )
	);
}

/**
 * Reads one of a member's job submissions.
 *
 * Scope: `jobs.submissions.browse`. The website polls this after a post until
 * the credit check settles. A job that is not this member's is a `404
 * JOB_NOT_FOUND`, the same as one that does not exist.
 *
 * @param string $id      Submission id.
 * @param array  $contact The member's contact pair.
 * @return array|WP_Error Decoded response (`data` item), or WP_Error on failure.
 */
function agend_apps_jobs_get_submission( string $id, array $contact ) {
	return agend_apps_api()->request(
		'GET',
		'/jobs/submissions/' . rawurlencode( $id ),
		agend_apps_jobs_submission_args( array( 'query' => agend_apps_jobs_contact_query( $contact ) ) )
	);
}

/**
 * Counts a member's unused job credits.
 *
 * Scope: `jobs.submissions.browse`. Agend reads the member's credits from the
 * CRM and caches the count briefly. The website uses it only to decide whether
 * to offer the posting form: Agend checks the credit again when a job is
 * posted. A `503 CREDIT_COUNT_UNAVAILABLE` means no count could be read yet.
 *
 * @param array $contact The member's contact pair.
 * @return array|WP_Error Decoded response (`data.available`, `data.checked_at`, `data.stale`), or WP_Error on failure.
 */
function agend_apps_jobs_get_credits( array $contact ) {
	return agend_apps_api()->request(
		'GET',
		'/jobs/credits',
		agend_apps_jobs_submission_args( array( 'query' => agend_apps_jobs_contact_query( $contact ) ) )
	);
}

/**
 * Posts a job for a member.
 *
 * Scope: `jobs.submissions.create`. Agend finds the member's contact by its
 * pair, creating it when there is none, and accepts the job as
 * `checking_credit` (202) while it checks for an unused job credit. Poll
 * {@see agend_apps_jobs_get_submission()} for the outcome: `pending` when a
 * credit paid for it, `no_credit` when none did.
 *
 * Retrying with the same idempotency key and the same body returns the first
 * answer instead of posting a second job; a different body under the same key
 * gets `409 IDEMPOTENCY_KEY_REUSED`.
 *
 * @param array  $contact         `external_source`, `external_id`, `first_name`, `last_name` and `email`.
 * @param array  $job             Job fields.
 * @param string $idempotency_key Unique to one attempt to post this job.
 * @return array|WP_Error Decoded response (`data.id`, `data.status`, `data.contact`, `data.contact_created`), or WP_Error on failure.
 */
function agend_apps_jobs_create_submission( array $contact, array $job, string $idempotency_key ) {
	return agend_apps_api()->request(
		'POST',
		'/jobs/submissions',
		agend_apps_jobs_submission_args(
			array(
				'body'    => array(
					'contact' => $contact,
					'job'     => $job,
				),
				'headers' => array( 'Idempotency-Key' => $idempotency_key ),
			)
		)
	);
}

/**
 * Edits a member's pending job submission.
 *
 * Scope: `jobs.submissions.manage`. Anything but a `pending` job is a `409
 * JOB_NOT_EDITABLE`.
 *
 * @param string $id      Submission id.
 * @param array  $contact The member's contact pair.
 * @param array  $job     Job fields to change.
 * @return array|WP_Error Decoded response (`data` item), or WP_Error on failure.
 */
function agend_apps_jobs_update_submission( string $id, array $contact, array $job ) {
	return agend_apps_api()->request(
		'PATCH',
		'/jobs/submissions/' . rawurlencode( $id ),
		agend_apps_jobs_submission_args(
			array(
				'body' => array(
					'contact' => agend_apps_jobs_contact_query( $contact ),
					'job'     => $job,
				),
			)
		)
	);
}

/**
 * Withdraws a member's pending job submission. Agend frees its credit.
 *
 * Scope: `jobs.submissions.manage`.
 *
 * @param string $id      Submission id.
 * @param array  $contact The member's contact pair.
 * @return array|WP_Error Decoded response (`data` item), or WP_Error on failure.
 */
function agend_apps_jobs_withdraw_submission( string $id, array $contact ) {
	return agend_apps_api()->request(
		'POST',
		'/jobs/submissions/' . rawurlencode( $id ) . '/withdraw',
		agend_apps_jobs_submission_args( array( 'body' => array( 'contact' => agend_apps_jobs_contact_query( $contact ) ) ) )
	);
}

/**
 * Takes a member's live job off the board. The credit stays spent.
 *
 * Scope: `jobs.submissions.manage`.
 *
 * @param string $id      Submission id.
 * @param array  $contact The member's contact pair.
 * @param bool   $filled  Whether the position was filled.
 * @return array|WP_Error Decoded response (`data` item), or WP_Error on failure.
 */
function agend_apps_jobs_close_submission( string $id, array $contact, bool $filled ) {
	return agend_apps_api()->request(
		'POST',
		'/jobs/submissions/' . rawurlencode( $id ) . '/close',
		agend_apps_jobs_submission_args(
			array(
				'body' => array(
					'contact' => agend_apps_jobs_contact_query( $contact ),
					'filled'  => $filled,
				),
			)
		)
	);
}
