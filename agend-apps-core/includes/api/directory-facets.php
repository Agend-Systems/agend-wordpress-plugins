<?php
/**
 * Directory facets request.
 *
 * Kept apart from directory.php so it can be loaded and tested without the
 * request functions that file declares.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retrieves the filterable facets for the directory, with the values that
 * exist for each.
 *
 * Answers "what can a visitor filter by, and what values does it admit" so a
 * filter control can be built without hardcoding a value list. The response is
 * scoped to the caller's entitlements: a custom field the caller may not read
 * is absent entirely rather than present and empty, so the endpoint cannot be
 * used to probe an account's field schema.
 *
 * A set that names at least one facet and no `custom.<key>` facet is the same
 * for every viewer, so it is read without the member token, from the entry an
 * anonymous visitor shares.
 *
 * @param string[] $fields Optional. Facet names (`categories`, `tags`,
 *                         `badges`, `custom.<field_key>`). Empty returns every
 *                         facet the caller may read.
 * @return array|WP_Error Decoded response, or WP_Error on failure.
 */
function agend_apps_directory_get_facets( array $fields = array() ) {
	$query = agend_apps_directory_facets_query( $fields );

	/**
	 * Filters the directory facets request args before the request is sent.
	 *
	 * @param array $args   Request args.
	 * @param array $fields Requested facet names.
	 */
	$args = (array) apply_filters(
		'agend_apps_directory_get_facets_args',
		array( 'query' => $query ),
		$fields
	);

	$cache_key = Agend_Apps_Cache::build_key( 'directory_facets', $query );
	$ttl       = Agend_Apps_Settings::get_cache_ttl( 'directory_facets' );

	// Otherwise the member's grants decide which custom facets come back, so
	// get_cached() sends their token and never shares the response.
	if ( agend_apps_directory_facets_viewer_independent( $fields ) ) {
		$args['anonymous'] = true;
	}

	$response = agend_apps_api()->get_cached( '/directory/facets', $args, $cache_key, $ttl );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded directory facets response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $fields   Requested facet names.
	 */
	return apply_filters( 'agend_apps_directory_get_facets_response', $response, $fields );
}

/**
 * The facets query for a list of facet names, in the order given: the cache
 * key is built from it, so the browser's request and a page render's preload
 * address the same entry only when the names arrive in the same order.
 *
 * @param string[] $fields Facet names.
 * @return array
 */
function agend_apps_directory_facets_query( array $fields ): array {
	$names = array_values( array_filter( array_map( 'trim', $fields ), 'strlen' ) );
	return empty( $names ) ? array() : array( 'fields' => implode( ',', $names ) );
}

/**
 * Whether a facet set's response is the same for every viewer: it names at
 * least one facet and no `custom.<key>` facet. Naming none returns every
 * custom facet the caller's grants cover.
 *
 * @param string[] $fields Facet names.
 * @return bool
 */
function agend_apps_directory_facets_viewer_independent( array $fields ): bool {
	$names = array_values( array_filter( array_map( 'trim', $fields ), 'strlen' ) );
	if ( empty( $names ) ) {
		return false;
	}
	foreach ( $names as $name ) {
		if ( 0 === strpos( $name, 'custom.' ) ) {
			return false;
		}
	}
	return true;
}

/**
 * The shared anonymous facets entry for a list of facet names, as the REST
 * route would return it, read without calling the gateway. Null when the
 * entry is cold.
 *
 * @param string[] $fields Facet names, in the order the filter template renders them.
 * @return array{fetched_at: int, ttl: int, body: array}|null
 */
function agend_apps_directory_peek_facets( array $fields ): ?array {
	$cached = agend_apps_api()->peek_cached( Agend_Apps_Cache::build_key( 'directory_facets', agend_apps_directory_facets_query( $fields ) ) );
	if ( null === $cached ) {
		return null;
	}

	/** This filter is documented in agend_apps_directory_get_facets(). */
	$body = apply_filters( 'agend_apps_directory_get_facets_response', $cached['response'], $fields );

	return is_array( $body ) ? array(
		'fetched_at' => $cached['fetched_at'],
		'ttl'        => Agend_Apps_Settings::get_cache_ttl( 'directory_facets' ),
		'body'       => $body,
	) : null;
}
