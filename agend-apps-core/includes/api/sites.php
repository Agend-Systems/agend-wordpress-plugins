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
 * Key of the stored entry that records the account has no published site
 * config, in place of the config itself.
 */
const AGEND_APPS_SITES_MISSING_CONFIG = 'agend_apps_missing_site_config';

/**
 * Returns the connected account's published site config (brand, theme, enabled
 * apps).
 *
 * Scope: `sites.config.browse`. Cached, found or not.
 *
 * The config belongs to the API key's account and is the same for every
 * viewer, so it is read anonymously: no member token is resolved or sent, and
 * signed-in and anonymous visitors share one stored entry. An account with no
 * published config is remembered in that entry for the same lifetime, so a
 * site that does not use one does not ask the gateway on every page view.
 *
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_sites_get_config() {
	/**
	 * Filters the site-config request args before the request is sent.
	 *
	 * @param array $args Request args. Set `anonymous` to false to send the
	 *                    member token; the response is then held for the PHP
	 *                    request only, and a missing config is not remembered.
	 */
	$args  = (array) apply_filters( 'agend_apps_sites_get_config_args', array() );
	$args += array( 'anonymous' => true );

	$cache_key = Agend_Apps_Cache::build_key( 'sites_config', array() );
	$ttl       = Agend_Apps_Settings::get_cache_ttl( 'sites_config' );

	$response = agend_apps_api()->get_cached( '/sites/config', $args, $cache_key, $ttl );

	if ( is_wp_error( $response ) ) {
		if ( ! empty( $args['anonymous'] ) ) {
			agend_apps_sites_remember_missing_config( $cache_key, $ttl, $response );
		}
		return $response;
	}

	if ( isset( $response[ AGEND_APPS_SITES_MISSING_CONFIG ] ) && is_array( $response[ AGEND_APPS_SITES_MISSING_CONFIG ] ) ) {
		$missing = $response[ AGEND_APPS_SITES_MISSING_CONFIG ];
		return new WP_Error( (string) ( $missing['code'] ?? '' ), (string) ( $missing['message'] ?? '' ), $missing['data'] ?? array() );
	}

	/**
	 * Filters the decoded site-config response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 */
	return apply_filters( 'agend_apps_sites_get_config_response', $response );
}

/**
 * Stores the gateway's answer that the account has no published site config
 * in the shared site config entry, and nothing else.
 *
 * A transport error, a 5xx, a 401 or 403, and a local scope, app or rate-limit
 * refusal can all clear on the next call, and remembering one would hide a
 * config that does exist. So would any other 404: the gateway answers
 * `NOT_FOUND` for an endpoint it does not have too, as a deployment without
 * this route or a misrouted URL would, so the message must name the site
 * config. If the gateway rewords it, nothing is remembered, which costs a
 * request per page view and never hides a config.
 *
 * A config stored while this request waited, by a request that started after
 * it was published, is kept: the found config always wins.
 *
 * @param string   $cache_key Cache key of the site config read.
 * @param int      $ttl       Lifetime of the site config entry. 0 stores nothing.
 * @param WP_Error $error     What the read returned.
 */
function agend_apps_sites_remember_missing_config( string $cache_key, int $ttl, WP_Error $error ): void {
	$data       = $error->get_error_data();
	$error_body = is_array( $data ) && is_array( $data['body'] ?? null ) ? ( $data['body']['error'] ?? null ) : null;

	$missing_config = 'agend_api_error' === $error->get_error_code()
		&& 404 === ( $data['status_code'] ?? null )
		&& is_array( $error_body )
		&& 'NOT_FOUND' === ( $error_body['code'] ?? null )
		&& false !== stripos( (string) ( $error_body['message'] ?? '' ), 'site config' );

	$entry = 'agend_apps_' . $cache_key;

	if ( ! $missing_config || $ttl <= 0 || false !== get_transient( $entry ) ) {
		return;
	}

	set_transient(
		$entry,
		array(
			AGEND_APPS_SITES_MISSING_CONFIG => array(
				'code'    => $error->get_error_code(),
				'message' => $error->get_error_message(),
				'data'    => $data,
			),
		),
		$ttl
	);
}
