<?php
/**
 * SSO API functions.
 *
 * Server-side PHP wrappers for the Agend gateway's `/v1/sso/connections/*`
 * endpoints. Sibling plugins MUST call these helpers rather than building
 * gateway paths or calling `agend_apps_api()` directly, so transport and
 * path knowledge stay centralised here.
 *
 * All SSO connection management endpoints are administrative and never cached.
 * Callers must hold the appropriate API key scopes (`sso.connections.browse`,
 * `sso.connections.create`, `sso.connections.update`, `sso.connections.delete`).
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
 * Lists SSO connections.
 *
 * Scope: `sso.connections.browse`. Administrative; not cached.
 *
 * @param array $query Optional. Query parameters (camelCase): `page` (int ≥ 1, default 1), `limit` (int 1-100, default 50). Default empty.
 * @return array|WP_Error Decoded response array containing `connections` array and `pagination` on success, or WP_Error on failure.
 */
function agend_apps_sso_list_connections( array $query = array() ) {
	/**
	 * Filters the list-connections request args before the request is sent.
	 *
	 * @param array $args  Request args.
	 * @param array $query Original query parameters.
	 */
	$args = (array) apply_filters(
		'agend_apps_sso_list_connections_args',
		array( 'query' => $query ),
		$query
	);

	$response = agend_apps_api()->request( 'GET', '/sso/connections', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded list-connections response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $query    Original query parameters.
	 */
	return apply_filters( 'agend_apps_sso_list_connections_response', $response, $query );
}

/**
 * Retrieves a single SSO connection by ID.
 *
 * Scope: `sso.connections.browse`. Administrative; not cached.
 *
 * @param string $id SSO connection UUID.
 * @return array|WP_Error Decoded connection on success, or WP_Error on failure.
 */
function agend_apps_sso_get_connection( string $id ) {
	/**
	 * Filters the get-connection request args before the request is sent.
	 *
	 * @param array  $args Request args.
	 * @param string $id   SSO connection UUID.
	 */
	$args = (array) apply_filters( 'agend_apps_sso_get_connection_args', array(), $id );

	$response = agend_apps_api()->request( 'GET', '/sso/connections/' . rawurlencode( $id ), $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded get-connection response before it is returned.
	 *
	 * @param array  $response Decoded response body.
	 * @param string $id       SSO connection UUID.
	 */
	return apply_filters( 'agend_apps_sso_get_connection_response', $response, $id );
}

/**
 * Creates an SSO connection.
 *
 * Scope: `sso.connections.create`. Administrative; not cached.
 *
 * @param array $connection Connection payload (snake_case) forwarded as the request body.
 *        Expected keys: `provider_name` (string), `idp_certificate` (string, PEM-encoded X.509).
 * @return array|WP_Error Decoded connection on success, or WP_Error on failure.
 */
function agend_apps_sso_create_connection( array $connection ) {
	/**
	 * Filters the create-connection request args before the request is sent.
	 *
	 * @param array $args       Request args.
	 * @param array $connection Connection payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_sso_create_connection_args',
		array( 'body' => $connection ),
		$connection
	);

	$response = agend_apps_api()->request( 'POST', '/sso/connections', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded create-connection response before it is returned.
	 *
	 * @param array $response    Decoded response body.
	 * @param array $connection  Connection payload.
	 */
	return apply_filters( 'agend_apps_sso_create_connection_response', $response, $connection );
}

/**
 * Updates an SSO connection.
 *
 * Scope: `sso.connections.update`. Administrative; not cached.
 *
 * @param string $id         SSO connection UUID.
 * @param array  $connection Updated connection payload (snake_case). All fields optional.
 *        Expected keys (optional): `provider_name` (string), `idp_certificate` (string, PEM-encoded X.509).
 * @return array|WP_Error Decoded connection on success, or WP_Error on failure.
 */
function agend_apps_sso_update_connection( string $id, array $connection ) {
	/**
	 * Filters the update-connection request args before the request is sent.
	 *
	 * @param array  $args       Request args.
	 * @param string $id         SSO connection UUID.
	 * @param array  $connection Updated connection payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_sso_update_connection_args',
		array( 'body' => $connection ),
		$id,
		$connection
	);

	$response = agend_apps_api()->request( 'PATCH', '/sso/connections/' . rawurlencode( $id ), $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded update-connection response before it is returned.
	 *
	 * @param array  $response   Decoded response body.
	 * @param string $id         SSO connection UUID.
	 * @param array  $connection Updated connection payload.
	 */
	return apply_filters( 'agend_apps_sso_update_connection_response', $response, $id, $connection );
}

/**
 * Deletes an SSO connection.
 *
 * Scope: `sso.connections.delete`. Administrative; not cached.
 *
 * @param string $id SSO connection UUID.
 * @return array|WP_Error Decoded confirmation on success, or WP_Error on failure.
 */
function agend_apps_sso_delete_connection( string $id ) {
	/**
	 * Filters the delete-connection request args before the request is sent.
	 *
	 * @param array  $args Request args.
	 * @param string $id   SSO connection UUID.
	 */
	$args = (array) apply_filters( 'agend_apps_sso_delete_connection_args', array(), $id );

	$response = agend_apps_api()->request( 'DELETE', '/sso/connections/' . rawurlencode( $id ), $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded delete-connection response before it is returned.
	 *
	 * @param array  $response Decoded response body.
	 * @param string $id       SSO connection UUID.
	 */
	return apply_filters( 'agend_apps_sso_delete_connection_response', $response, $id );
}
