<?php
/**
 * Directory categories request.
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
 * The most categories a gateway that predates the 500 ceiling returns.
 */
if ( ! defined( 'AGEND_APPS_DIRECTORY_CATEGORIES_LEGACY_LIMIT' ) ) {
	define( 'AGEND_APPS_DIRECTORY_CATEGORIES_LEGACY_LIMIT', 100 );
}

/**
 * Retrieves the account's active directory categories.
 *
 * Asks for up to 500, the gateway's ceiling, so a two-level tree larger than
 * the default page of 50 reaches the Category filter whole. Inactive
 * categories are left out by the request because the gateway reads with an
 * admin client, so the public `is_active` policy does not apply. Listing counts
 * are not requested: nothing here shows them. The legacy filter bar and the
 * directory catalogue's category exclusion options read this too.
 *
 * Results are cached under a key that carries the request arguments, so a
 * response cached for different arguments (an earlier release's 50-category
 * list) is never served for these. Category lists change infrequently, so
 * the default TTL is higher than other endpoints.
 *
 * When the request fails with a limit above 100, it is retried once at 100,
 * under that request's own cache entry.
 *
 * @return array|WP_Error Decoded categories array on success, or WP_Error on failure.
 */
function agend_apps_directory_get_categories() {
	/**
	 * Filters the directory categories request args before the request is sent.
	 *
	 * @param array $args Request args. `query` holds `limit`, `active_only`
	 *                    and `counts`.
	 */
	$args = (array) apply_filters(
		'agend_apps_directory_get_categories_args',
		array(
			'query' => array(
				'limit'       => 500,
				'active_only' => true,
				'counts'      => false,
			),
		)
	);

	$ttl      = Agend_Apps_Settings::get_cache_ttl( 'directory_categories' );
	$response = agend_apps_api()->get_cached( '/directory/categories', $args, Agend_Apps_Cache::build_key( 'directory_categories', $args ), $ttl );

	// An older gateway caps the list at 100 and rejects a larger limit
	// outright, so one retry at its ceiling keeps the filters populated, if
	// capped, rather than empty.
	$limit = (int) ( $args['query']['limit'] ?? 0 );
	if ( is_wp_error( $response ) && $limit > AGEND_APPS_DIRECTORY_CATEGORIES_LEGACY_LIMIT ) {
		$args['query']['limit'] = AGEND_APPS_DIRECTORY_CATEGORIES_LEGACY_LIMIT;
		$response               = agend_apps_api()->get_cached( '/directory/categories', $args, Agend_Apps_Cache::build_key( 'directory_categories', $args ), $ttl );
	}

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
