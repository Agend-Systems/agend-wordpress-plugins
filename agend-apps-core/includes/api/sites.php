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
 * Scope: `sites.config.browse`. Cached, found or not.
 *
 * The config belongs to the API key's account and is the same for every
 * viewer, so it is read anonymously: no member token is resolved or sent, and
 * signed-in and anonymous visitors share one stored entry. An account with no
 * published config is remembered for the same lifetime, so a site that does
 * not use one does not ask the gateway on every page view.
 *
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_sites_get_config() {
	/**
	 * Filters the site-config request args before the request is sent.
	 *
	 * @param array $args Request args. `anonymous` is set by default; a filter
	 *                    that drops it sends the member token again, and the
	 *                    response is then held for the PHP request only.
	 */
	$args = (array) apply_filters( 'agend_apps_sites_get_config_args', array( 'anonymous' => true ) );

	$cache_key = Agend_Apps_Cache::build_key( 'sites_config', array() );
	$ttl       = Agend_Apps_Settings::get_cache_ttl( 'sites_config' );

	$response = agend_apps_sites_remembered_missing_config( $cache_key, $ttl );

	if ( null === $response ) {
		$response = agend_apps_api()->get_cached( '/sites/config', $args, $cache_key, $ttl );

		if ( is_wp_error( $response ) ) {
			agend_apps_sites_remember_missing_config( $cache_key, $ttl, $response );
		}
	}

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

/**
 * The transient a missing site config is remembered under. It begins with the
 * `sites_config` cache key, so clearing the "Site Config" row on the Cache tab
 * (or every row) removes it with the config itself.
 */
function agend_apps_sites_missing_config_transient( string $cache_key ): string {
	return 'agend_apps_' . $cache_key . '_not_found';
}

/**
 * The gateway's earlier answer that the account has no published site config,
 * rebuilt as the WP_Error it returned, or null when none is remembered.
 */
function agend_apps_sites_remembered_missing_config( string $cache_key, int $ttl ): ?WP_Error {
	if ( $ttl <= 0 ) {
		return null;
	}

	$stored = get_transient( agend_apps_sites_missing_config_transient( $cache_key ) );

	if ( ! is_array( $stored ) || ! isset( $stored['code'], $stored['message'], $stored['data'] ) ) {
		return null;
	}

	return new WP_Error( (string) $stored['code'], (string) $stored['message'], $stored['data'] );
}

/**
 * Remembers the gateway's not-found answer for the site config, and nothing
 * else. A transport error, a 5xx, a 401 or 403, and a local scope, app or
 * rate-limit refusal can all clear on the next call, and remembering one would
 * hide a config that does exist. So would a 404 for any other reason, such as
 * an unknown app.
 *
 * @param string   $cache_key Cache key of the site config read.
 * @param int      $ttl       Lifetime of the site config entry. 0 stores nothing.
 * @param WP_Error $error     What the read returned.
 */
function agend_apps_sites_remember_missing_config( string $cache_key, int $ttl, WP_Error $error ): void {
	$data = $error->get_error_data();

	$not_found = 'agend_api_error' === $error->get_error_code()
		&& is_array( $data )
		&& 404 === ( $data['status_code'] ?? null )
		&& 'NOT_FOUND' === ( $data['body']['error']['code'] ?? null );

	if ( ! $not_found || $ttl <= 0 ) {
		return;
	}

	set_transient(
		agend_apps_sites_missing_config_transient( $cache_key ),
		array(
			'code'    => $error->get_error_code(),
			'message' => $error->get_error_message(),
			'data'    => $data,
		),
		$ttl
	);
}
