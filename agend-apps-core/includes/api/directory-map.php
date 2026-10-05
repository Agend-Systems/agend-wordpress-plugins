<?php
/**
 * Agend Apps API wrappers for the directory map: the markers, geocode and
 * map-settings endpoints, and the coordinate rounding the directory search
 * shares with them.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rounds the coordinates in a directory query so near-identical searches
 * share one cached response.
 *
 * A visitor's own position, or a map dragged a few pixels, differs in the
 * sixth decimal place every time, and the cache key is a hash of the whole
 * query, so unrounded values would write a new transient for every search.
 * Three decimals of latitude or longitude is about 110 m, well inside the one
 * decimal place a distance is shown to; a bbox edge keeps four so a map's
 * viewport still matches what the visitor sees.
 *
 * @param array $query Query parameters.
 * @return array The same query with `lat`, `lng`, `radius` and `bbox` rounded.
 */
function agend_apps_directory_round_coordinates( array $query ): array {
	foreach ( array( 'lat', 'lng' ) as $key ) {
		if ( isset( $query[ $key ] ) && is_numeric( $query[ $key ] ) ) {
			$query[ $key ] = round( (float) $query[ $key ], 3 );
		}
	}
	if ( isset( $query['radius'] ) && is_numeric( $query['radius'] ) ) {
		$query['radius'] = round( (float) $query['radius'], 1 );
	}
	if ( isset( $query['bbox'] ) && is_string( $query['bbox'] ) && '' !== $query['bbox'] ) {
		$edges = array_map( 'trim', explode( ',', $query['bbox'] ) );
		if ( 4 === count( $edges ) && count( array_filter( $edges, 'is_numeric' ) ) === 4 ) {
			$query['bbox'] = implode(
				',',
				array_map(
					static function ( $edge ) {
						return (string) round( (float) $edge, 4 );
					},
					$edges
				)
			);
		}
	}
	return $query;
}

/**
 * Retrieves the directory listings matching a search as map markers.
 *
 * Takes the same filters as agend_apps_directory_search() and returns one
 * slim row per listing with every geocoded location, up to `limit` (the
 * gateway's own default is 500, its maximum 2000). `meta.truncated` says
 * whether more matched than were returned. Listings with no map location are
 * never included.
 *
 * @param string $search_query The search term.
 * @param array  $filters      Optional. The search filters, plus `limit` and
 *                             `bbox` (north,south,east,west). Default empty.
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_directory_get_markers( string $search_query, array $filters = array() ) {
	$query = agend_apps_directory_round_coordinates( array_merge( array( 'q' => $search_query ), $filters ) );

	/**
	 * Filters the directory markers request args before the request is sent.
	 *
	 * @param array  $args         Request args.
	 * @param string $search_query Search term.
	 * @param array  $filters      Additional filter parameters.
	 */
	$args = (array) apply_filters(
		'agend_apps_directory_get_markers_args',
		array( 'query' => $query ),
		$search_query,
		$filters
	);

	$cache_key = Agend_Apps_Cache::build_key( 'directory_markers', $query );
	$ttl       = Agend_Apps_Settings::get_cache_ttl( 'directory_markers' );

	$response = agend_apps_api()->get_cached( '/directory/markers', $args, $cache_key, $ttl );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded directory markers response before it is returned.
	 *
	 * @param array  $response     Decoded response body.
	 * @param string $search_query Search term.
	 * @param array  $filters      Additional filter parameters.
	 */
	return apply_filters( 'agend_apps_directory_get_markers_response', $response, $search_query, $filters );
}

/**
 * Resolves a free-form address, suburb or postcode to a point.
 *
 * Needs the account's directory.geocoding feature: the gateway answers 403
 * without it, 404 when nothing matches, and 503 when its geocoder cannot
 * answer. Only a match is cached, keyed on the normalised text, so the same
 * suburb typed by every visitor costs one upstream lookup a day.
 *
 * @param string $place The text to resolve, 1 to 200 characters.
 * @return array|WP_Error Decoded response (`data.latitude`, `data.longitude`,
 *                        `data.display_name`) on success, or WP_Error.
 */
function agend_apps_directory_geocode( string $place ) {
	$place = trim( preg_replace( '/\s+/', ' ', $place ) );
	$query = array( 'q' => $place );

	/**
	 * Filters the directory geocode request args before the request is sent.
	 *
	 * @param array  $args  Request args.
	 * @param string $place The text being resolved.
	 */
	$args = (array) apply_filters( 'agend_apps_directory_geocode_args', array( 'query' => $query ), $place );

	$cache_key = Agend_Apps_Cache::build_key( 'directory_geocode', array( 'q' => strtolower( $place ) ) );
	$ttl       = Agend_Apps_Settings::get_cache_ttl( 'directory_geocode' );

	$response = agend_apps_api()->get_cached( '/directory/geocode', $args, $cache_key, $ttl );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded directory geocode response before it is returned.
	 *
	 * @param array  $response Decoded response body.
	 * @param string $place    The text that was resolved.
	 */
	return apply_filters( 'agend_apps_directory_geocode_response', $response, $place );
}

/**
 * Retrieves the directory's map defaults: the account's configured default
 * centre, the padded bounds of every publicly visible geocoded listing, and
 * whether place search (geocoding) is available to this account.
 *
 * @return array|WP_Error Decoded response on success, or WP_Error on failure.
 */
function agend_apps_directory_get_map_settings() {
	/**
	 * Filters the directory map-settings request args before the request is sent.
	 *
	 * @param array $args Request args.
	 */
	$args = (array) apply_filters( 'agend_apps_directory_get_map_settings_args', array( 'query' => array() ) );

	$cache_key = Agend_Apps_Cache::build_key( 'directory_map_settings', array() );
	$ttl       = Agend_Apps_Settings::get_cache_ttl( 'directory_map_settings' );

	$response = agend_apps_api()->get_cached( '/directory/map-settings', $args, $cache_key, $ttl );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded directory map-settings response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 */
	return apply_filters( 'agend_apps_directory_get_map_settings_response', $response );
}
