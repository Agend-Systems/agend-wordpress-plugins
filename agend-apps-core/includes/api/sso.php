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

/**
 * Checks whether an external identity is linked to a member on the account.
 *
 * Scope: `sso.identities.read`. Resolve only: the gateway mints nothing and
 * never provisions, so an unlinked identity is a normal `{ linked: false }`
 * result rather than an error. Administrative; not cached.
 *
 * @param string $idp_entity_id The SAML IdP entity id naming the connection.
 * @param string $external_id   The member's opaque external subject (SAML NameID).
 * @return array|WP_Error Decoded response envelope containing `data.linked` on success, or WP_Error on failure.
 */
function agend_apps_sso_get_link_status( string $idp_entity_id, string $external_id ) {
	/**
	 * Filters the link-status request args before the request is sent.
	 *
	 * @param array  $args          Request args.
	 * @param string $idp_entity_id The SAML IdP entity id.
	 * @param string $external_id   The external subject.
	 */
	$args = (array) apply_filters(
		'agend_apps_sso_get_link_status_args',
		array(
			'query' => array(
				'idpEntityId' => $idp_entity_id,
				'externalId'  => $external_id,
			),
		),
		$idp_entity_id,
		$external_id
	);

	$response = agend_apps_api()->request( 'GET', '/sso/identities/status', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded link-status response before it is returned.
	 *
	 * @param array  $response      Decoded response body.
	 * @param string $idp_entity_id The SAML IdP entity id.
	 * @param string $external_id   The external subject.
	 */
	return apply_filters( 'agend_apps_sso_get_link_status_response', $response, $idp_entity_id, $external_id );
}

/**
 * Mints a short-lived Supabase access token for an already-linked member.
 *
 * Scope: `sso.tokens.create`. Resolve only: the gateway never provisions, so
 * an unlinked member returns a 404 `EXTERNAL_IDENTITY_NOT_FOUND` WP_Error. No
 * refresh token is returned (the caller re-mints on expiry). The token is
 * impersonation-grade material: never log it, never cache it in a transient,
 * and never return it to the browser. The token worker
 * (`Agend_Apps_Token_Worker`) is the intended caller.
 *
 * @param string $idp_entity_id The SAML IdP entity id naming the connection.
 * @param string $external_id   The member's opaque external subject (SAML NameID).
 * @return array|WP_Error Decoded response envelope containing `data.access_token`,
 *                        `data.expires_at` (epoch seconds), `data.user_id`, and
 *                        `data.token_type` on success, or WP_Error on failure.
 */
function agend_apps_sso_mint_token( string $idp_entity_id, string $external_id ) {
	$args = array(
		'body' => array(
			'idp_entity_id' => $idp_entity_id,
			'external_id'   => $external_id,
		),
	);

	// Deliberately NO request-args filter here: the payload is an identity
	// assertion and must not be rewritable by sibling plugins.
	$response = agend_apps_api()->request( 'POST', '/sso/tokens', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	// Deliberately NO response filter either: the minted token must reach the
	// worker exactly as issued.
	return $response;
}
