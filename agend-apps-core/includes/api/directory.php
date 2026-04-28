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
