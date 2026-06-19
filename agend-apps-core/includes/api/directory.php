<?php
/**
 * Directory API functions.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retrieves a paginated, filterable list of directory listings.
 *
 * Results are cached. Cache is keyed on the query parameters so that
 * different filter combinations are stored independently.
 *
 * @param array $query Optional. Query parameters (e.g. `page`, `per_page`, `category`). Default empty.
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_directory_get_listings( array $query = array() ) {
	/**
	 * Filters the directory listings request args before the request is sent.
	 *
	 * @param array $args  Request args.
	 * @param array $query Original query parameters.
	 */
	$args = (array) apply_filters(
		'agend_apps_directory_get_listings_args',
		array( 'query' => $query ),
		$query
	);

	$cache_key = Agend_Apps_Cache::build_key( 'directory_listings', $query );
	$ttl       = Agend_Apps_Settings::get_cache_ttl( 'directory_listings' );

	$response = agend_apps_api()->get_cached( '/directory/listings', $args, $cache_key, $ttl );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded directory listings response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $query    Original query parameters.
	 */
	return apply_filters( 'agend_apps_directory_get_listings_response', $response, $query );
}

/**
 * Retrieves a single directory listing by ID.
 *
 * Results are cached per listing ID.
 *
 * @param string $listing_id The listing ID to retrieve.
 * @return array|WP_Error Decoded listing array on success, or WP_Error on failure.
 */
function agend_apps_directory_get_listing( string $listing_id ) {
	/**
	 * Filters the directory single-listing request args before the request is sent.
	 *
	 * @param array  $args       Request args.
	 * @param string $listing_id Listing ID.
	 */
	$args = (array) apply_filters(
		'agend_apps_directory_get_listing_args',
		array(),
		$listing_id
	);

	$cache_key = Agend_Apps_Cache::build_key( 'directory_listing_single', array( 'id' => $listing_id ) );
	$ttl       = Agend_Apps_Settings::get_cache_ttl( 'directory_listing_single' );

	$response = agend_apps_api()->get_cached(
		'/directory/listings/' . rawurlencode( $listing_id ),
		$args,
		$cache_key,
		$ttl
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded single directory listing response before it is returned.
	 *
	 * @param array  $response   Decoded response body.
	 * @param string $listing_id Listing ID.
	 */
	return apply_filters( 'agend_apps_directory_get_listing_response', $response, $listing_id );
}

/**
 * Retrieves all directory categories.
 *
 * Results are cached. Category lists change infrequently so the default
 * TTL is higher than other endpoints.
 *
 * @return array|WP_Error Decoded categories array on success, or WP_Error on failure.
 */
function agend_apps_directory_get_categories() {
	/**
	 * Filters the directory categories request args before the request is sent.
	 *
	 * @param array $args Request args.
	 */
	$args = (array) apply_filters( 'agend_apps_directory_get_categories_args', array() );

	$cache_key = Agend_Apps_Cache::build_key( 'directory_categories' );
	$ttl       = Agend_Apps_Settings::get_cache_ttl( 'directory_categories' );

	$response = agend_apps_api()->get_cached( '/directory/categories', $args, $cache_key, $ttl );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded directory categories response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 */
	return apply_filters( 'agend_apps_directory_get_categories_response', $response );
}

/**
 * Searches the directory with a given query string and optional filters.
 *
 * Results are cached per unique combination of search parameters.
 *
 * @param string $search_query The search term to query.
 * @param array  $filters      Optional. Additional filter parameters (e.g. `category`, `page`). Default empty.
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_directory_search( string $search_query, array $filters = array() ) {
	$query = array_merge( array( 'q' => $search_query ), $filters );

	/**
	 * Filters the directory search request args before the request is sent.
	 *
	 * @param array  $args         Request args.
	 * @param string $search_query Search term.
	 * @param array  $filters      Additional filter parameters.
	 */
	$args = (array) apply_filters(
		'agend_apps_directory_search_args',
		array( 'query' => $query ),
		$search_query,
		$filters
	);

	$cache_key = Agend_Apps_Cache::build_key( 'directory_search', $query );
	$ttl       = Agend_Apps_Settings::get_cache_ttl( 'directory_search' );

	$response = agend_apps_api()->get_cached( '/directory/search', $args, $cache_key, $ttl );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded directory search response before it is returned.
	 *
	 * @param array  $response     Decoded response body.
	 * @param string $search_query Search term.
	 * @param array  $filters      Additional filter parameters.
	 */
	return apply_filters( 'agend_apps_directory_search_response', $response, $search_query, $filters );
}

/**
 * Busts every cached directory read.
 *
 * Listing writes can affect index, single-listing, search, and category
 * results, so all directory caches are cleared after a successful mutation.
 */
function agend_apps_directory_flush_cache(): void {
	Agend_Apps_Cache::clear( 'directory_listings' );
	Agend_Apps_Cache::clear( 'directory_listing_single' );
	Agend_Apps_Cache::clear( 'directory_search' );
	Agend_Apps_Cache::clear( 'directory_categories' );
}

/**
 * Creates a directory listing.
 *
 * Requires the `directory.listings.manage` scope on the API key. The cache is
 * flushed after a successful create.
 *
 * @param array $listing {
 *     Listing payload (snake_case). See the gateway `createListingSchema`.
 *
 *     @type string $name              Listing name. Required.
 *     @type string $description       Optional. Long description.
 *     @type string $short_description Optional. Summary.
 *     @type string $email             Optional. Contact email.
 *     @type string $phone             Optional. Contact phone.
 *     @type string $website           Optional. Website URL.
 *     @type array  $category_ids      Optional. Category UUIDs.
 *     @type string $status            Optional. Listing status.
 *     @type bool   $publish           Optional. Whether to publish immediately.
 * }
 * @return array|WP_Error Decoded listing on success, or WP_Error on failure.
 */
function agend_apps_directory_create_listing( array $listing ) {
	/**
	 * Filters the directory create-listing request args before the request is sent.
	 *
	 * @param array $args    Request args.
	 * @param array $listing Listing payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_directory_create_listing_args',
		array( 'body' => $listing ),
		$listing
	);

	$response = agend_apps_api()->request( 'POST', '/directory/listings', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	agend_apps_directory_flush_cache();

	/**
	 * Filters the decoded create-listing response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $listing  Listing payload.
	 */
	return apply_filters( 'agend_apps_directory_create_listing_response', $response, $listing );
}

/**
 * Updates an existing directory listing.
 *
 * Requires the `directory.listings.manage` scope on the API key. The cache is
 * flushed after a successful update.
 *
 * @param string $listing_id The listing ID to update.
 * @param array  $listing    Updated listing payload (snake_case). Partial updates are allowed.
 * @return array|WP_Error Decoded listing on success, or WP_Error on failure.
 */
function agend_apps_directory_update_listing( string $listing_id, array $listing ) {
	/**
	 * Filters the directory update-listing request args before the request is sent.
	 *
	 * @param array  $args       Request args.
	 * @param string $listing_id Listing ID.
	 * @param array  $listing    Updated listing payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_directory_update_listing_args',
		array( 'body' => $listing ),
		$listing_id,
		$listing
	);

	$response = agend_apps_api()->request( 'PUT', '/directory/listings/' . rawurlencode( $listing_id ), $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	agend_apps_directory_flush_cache();

	/**
	 * Filters the decoded update-listing response before it is returned.
	 *
	 * @param array  $response   Decoded response body.
	 * @param string $listing_id Listing ID.
	 * @param array  $listing    Updated listing payload.
	 */
	return apply_filters( 'agend_apps_directory_update_listing_response', $response, $listing_id, $listing );
}

/**
 * Deletes a directory listing.
 *
 * Requires the `directory.listings.manage` scope on the API key. The cache is
 * flushed after a successful delete.
 *
 * @param string $listing_id The listing ID to delete.
 * @return array|WP_Error Decoded confirmation on success, or WP_Error on failure.
 */
function agend_apps_directory_delete_listing( string $listing_id ) {
	/**
	 * Filters the directory delete-listing request args before the request is sent.
	 *
	 * @param array  $args       Request args.
	 * @param string $listing_id Listing ID.
	 */
	$args = (array) apply_filters(
		'agend_apps_directory_delete_listing_args',
		array(),
		$listing_id
	);

	$response = agend_apps_api()->request( 'DELETE', '/directory/listings/' . rawurlencode( $listing_id ), $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	agend_apps_directory_flush_cache();

	/**
	 * Filters the decoded delete-listing response before it is returned.
	 *
	 * @param array  $response   Decoded response body.
	 * @param string $listing_id Listing ID.
	 */
	return apply_filters( 'agend_apps_directory_delete_listing_response', $response, $listing_id );
}

/**
 * Bulk creates or updates directory listings.
 *
 * Requires the `directory.listings.bulk_upsert` scope on the API key. The
 * cache is flushed after a successful call.
 *
 * @param array $listings Array of listing payloads (1 to 100 items). Each item follows the gateway `bulkUpsertListingItemSchema`.
 * @return array|WP_Error Decoded bulk-upsert result on success, or WP_Error on failure.
 */
function agend_apps_directory_bulk_upsert_listings( array $listings ) {
	/**
	 * Filters the directory bulk-upsert request args before the request is sent.
	 *
	 * @param array $args     Request args.
	 * @param array $listings Listing payloads.
	 */
	$args = (array) apply_filters(
		'agend_apps_directory_bulk_upsert_listings_args',
		array( 'body' => array( 'listings' => array_values( $listings ) ) ),
		$listings
	);

	$response = agend_apps_api()->request( 'POST', '/directory/listings/bulk-upsert', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	agend_apps_directory_flush_cache();

	/**
	 * Filters the decoded bulk-upsert response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $listings Listing payloads.
	 */
	return apply_filters( 'agend_apps_directory_bulk_upsert_listings_response', $response, $listings );
}

/**
 * Submits a review for a directory listing.
 *
 * Requires the `directory.reviews.manage` scope on the API key. Not cached.
 *
 * @param array $review {
 *     Review payload (snake_case). See the gateway `submitReviewSchema`.
 *
 *     @type string $listing_id     Listing UUID. Required.
 *     @type int    $rating         Rating from 1 to 5. Required.
 *     @type string $reviewer_name  Reviewer name (2 to 100 chars). Required.
 *     @type string $reviewer_email Reviewer email. Required.
 *     @type string $content        Review body (20 to 2000 chars). Required.
 * }
 * @return array|WP_Error Decoded review on success, or WP_Error on failure.
 */
function agend_apps_directory_submit_review( array $review ) {
	/**
	 * Filters the directory submit-review request args before the request is sent.
	 *
	 * @param array $args   Request args.
	 * @param array $review Review payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_directory_submit_review_args',
		array( 'body' => $review ),
		$review
	);

	$response = agend_apps_api()->request( 'POST', '/directory/reviews', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded submit-review response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $review   Review payload.
	 */
	return apply_filters( 'agend_apps_directory_submit_review_response', $response, $review );
}
