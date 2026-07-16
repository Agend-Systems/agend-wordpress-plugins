<?php
/**
 * Sites API functions.
 *
 * Server-side PHP wrapper for the Agend gateway's `/v1/sites/config` endpoint.
 * Sibling plugins MUST call this helper rather than building gateway paths or
 * calling `agend_apps_api()` directly, so transport and path knowledge stay
 * centralised here.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns the connected account's published site config (brand, theme, enabled
 * apps).
 *
 * Scope: `sites.config.browse`. Cached.
 *
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_sites_get_config() {
	/**
	 * Filters the site-config request args before the request is sent.
	 *
	 * @param array $args Request args.
	 */
	$args = (array) apply_filters( 'agend_apps_sites_get_config_args', array() );

	$cache_key = Agend_Apps_Cache::build_key( 'sites_config', array() );
	$ttl       = Agend_Apps_Settings::get_cache_ttl( 'sites_config' );

	$response = agend_apps_api()->get_cached( '/sites/config', $args, $cache_key, $ttl );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded site-config response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 */
	return apply_filters( 'agend_apps_sites_get_config_response', $response );
}
