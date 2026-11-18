<?php
/**
 * Loop member API functions.
 *
 * Server-side PHP wrappers for the Agend gateway's member-facing `/v1/loop/me/*`
 * endpoints. These act as the signed-in member, so they need the member's
 * bearer token (supplied through `agend_apps_bearer_token`, normally by
 * {@see Agend_Apps_Token_Worker}). The site-level integration endpoints live in
 * `loop-integration.php`.
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
 * Lists the Loop channels the signed-in member belongs to.
 *
 * Scope: `loop.channels.browse`. Requires a member bearer token: without one
 * the gateway refuses the request. Not cached here; callers cache.
 *
 * Each channel carries `id`, `name`, `slug`, `description`, `privacy`,
 * `channel_type` (`channel`, `feed`, `committee`, `space`, `category` or `dm`),
 * `is_archived`, `member_count`, `role`, `joined_at` and `last_read_at`.
 *
 * @return array|WP_Error Decoded response (`data` is the list of channels) on success, or WP_Error on failure.
 */
function agend_apps_loop_get_my_channels() {
	/**
	 * Filters the my-channels request args before the request is sent.
	 *
	 * @param array $args Request args.
	 */
	$args = (array) apply_filters( 'agend_apps_loop_get_my_channels_args', array() );

	$response = agend_apps_api()->request( 'GET', '/loop/me/channels', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded my-channels response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 */
	return apply_filters( 'agend_apps_loop_get_my_channels_response', $response );
}
