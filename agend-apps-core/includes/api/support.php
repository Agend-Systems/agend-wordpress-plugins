<?php
/**
 * Support API functions.
 *
 * Server-side PHP wrappers for the Agend gateway's `/v1/support/*` endpoints.
 * Sibling plugins MUST call these helpers rather than building gateway paths
 * or calling `agend_apps_api()` directly, so transport and path knowledge stay
 * centralised here.
 *
 * The support ticket endpoint is identity-specific (`requireUserAuth`), so reads
 * are never cached. Member endpoints rely on the Supabase bearer token resolved
 * by `agend_apps_get_bearer_token()` and attached automatically by the client.
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
 * Lists the current member's support tickets.
 *
 * Scope: `support.tickets.browse`. Identity-specific; not cached.
 * Requires a member bearer token.
 *
 * @param array $query Optional. Query parameters (camelCase): `page`, `limit`, `search`, `sortBy`, `sortOrder`. Default empty.
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_support_get_my_tickets( array $query = array() ) {
	/**
	 * Filters the my-support-tickets request args before the request is sent.
	 *
	 * @param array $args  Request args.
	 * @param array $query Original query parameters.
	 */
	$args = (array) apply_filters(
		'agend_apps_support_get_my_tickets_args',
		array( 'query' => $query ),
		$query
	);

	$response = agend_apps_api()->request( 'GET', '/support/me/tickets', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded my-support-tickets response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $query    Original query parameters.
	 */
	return apply_filters( 'agend_apps_support_get_my_tickets_response', $response, $query );
}
