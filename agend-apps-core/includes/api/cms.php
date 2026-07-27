<?php
/**
 * CMS API functions.
 *
 * Server-side PHP wrappers for the Agend gateway's `/v1/cms/*` endpoints.
 * Sibling plugins MUST call these helpers rather than building gateway paths
 * or calling `agend_apps_api()` directly, so transport and path knowledge stay
 * centralised here.
 *
 * Public catalogue reads (content list and single content) are cached for
 * ANONYMOUS callers only. When a member bearer is attached the shared
 * transient store is bypassed in both directions, because the gateway
 * projects these responses per member (SPEC-CMS-20260727 US-2.3) and a shared
 * transient would serve one member's projection to the next visitor. The
 * bypass lives in `Agend_Apps_API::get_cached()`, so it applies to every
 * cached endpoint, not only these two.
 *
 * Mutations are never cached.
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
 * Lists CMS content.
 *
 * Scope: `cms.content.browse`. Cached for anonymous callers; bypassed when a
 * member bearer is attached.
 *
 * @param array $query Optional. Query parameters (camelCase): `page`, `limit`, `collection`, `category`, `tag`, `language`, `sortBy`, `sortOrder`, `search`. Default empty.
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_cms_get_content( array $query = array() ) {
	/**
	 * Filters the CMS content list request args before the request is sent.
	 *
	 * @param array $args  Request args.
	 * @param array $query Original query parameters.
	 */
	$args = (array) apply_filters(
		'agend_apps_cms_get_content_args',
		array( 'query' => $query ),
		$query
	);

	$cache_key = Agend_Apps_Cache::build_key( 'cms_content', $query );
	$ttl       = Agend_Apps_Settings::get_cache_ttl( 'cms_content' );

	$response = agend_apps_api()->get_cached( '/cms/content', $args, $cache_key, $ttl );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded CMS content list response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $query    Original query parameters.
	 */
	return apply_filters( 'agend_apps_cms_get_content_response', $response, $query );
}

/**
 * Retrieves a single CMS content item by slug.
 *
 * Scope: `cms.content.browse`. Cached for anonymous callers; bypassed when a
 * member bearer is attached.
 *
 * @param string $slug    Content slug.
 * @param array  $query   Optional. Query parameters (camelCase): `collection`, `language`. Default empty.
 * @return array|WP_Error Decoded content item on success, or WP_Error on failure.
 */
function agend_apps_cms_get_content_by_slug( string $slug, array $query = array() ) {
	/**
	 * Filters the single-content request args before the request is sent.
	 *
	 * @param array  $args  Request args.
	 * @param string $slug  Content slug.
	 * @param array  $query Query parameters.
	 */
	$args = (array) apply_filters(
		'agend_apps_cms_get_content_by_slug_args',
		array( 'query' => $query ),
		$slug,
		$query
	);

	$cache_key = Agend_Apps_Cache::build_key( 'cms_content_single', array( 'slug' => $slug ) );
	$ttl       = Agend_Apps_Settings::get_cache_ttl( 'cms_content_single' );

	$response = agend_apps_api()->get_cached( '/cms/content/' . rawurlencode( $slug ), $args, $cache_key, $ttl );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded single-content response before it is returned.
	 *
	 * @param array  $response Decoded response body.
	 * @param string $slug     Content slug.
	 * @param array  $query    Query parameters.
	 */
	return apply_filters( 'agend_apps_cms_get_content_by_slug_response', $response, $slug, $query );
}
