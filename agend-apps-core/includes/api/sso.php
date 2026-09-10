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

/**
 * Links a WordPress user to an Agend identity, server to server
 * (docs/PLAN-wordpress-idp-option-b.md section 4.2; shipped contract per the
 * `wp-idp-link` build brief, which supersedes that plan document's section
 * 2 draft).
 *
 * Scope: `sso.identities.create`. Not idempotent-free of side effects: a
 * successful call either creates the link (and, if the email had no
 * platform user yet, a passwordless one) or confirms an existing one, and
 * MAY trigger a withheld-verification email (202) that re-posting rotates.
 * Callers MUST NOT re-post for a user already in the `pending` state; see
 * `includes/wp-idp-link.php`, which is the only intended caller.
 *
 * Responses:
 * - 201 `data.linked=true, data.created=true` -- new link, user created.
 * - 200 `data.linked=true, data.created=false` -- idempotent re-post of an
 *   already-linked pair.
 * - 202 `data.status="verification_required"` -- WITHHELD. Nothing created;
 *   the gateway emailed the address a single-use confirm link.
 *
 * @param string $idp_entity_id The site's SSO connection entity id.
 * @param string $external_id   The member's opaque external subject (the
 *                              minted GUID or configured meta key value).
 * @param string $email         The member's email address.
 * @param array  $contact       Optional. `first_name` and/or `last_name`,
 *                               each 1-120 chars. Only non-empty names are
 *                               sent (see below).
 * @return array|WP_Error Decoded response envelope on success (201/200/202
 *                        all decode without error; the caller distinguishes
 *                        them via `status_code`), or WP_Error on failure
 *                        (400/401/403/404/409/429/500, or transport).
 */
function agend_apps_sso_link_identity( string $idp_entity_id, string $external_id, string $email, array $contact = array() ) {
	// This runs on the wp_login path (includes/wp-idp-link.php). The client's
	// default 15s timeout (class-agend-apps-api.php:193) is sized for
	// background/admin calls, not "a member is waiting for the login form to
	// finish". Fixed here, in the wrapper, rather than left to the caller: the
	// docblock convention this function follows (no request-args filter)
	// means there is no filter hook a caller could use to raise or lower it,
	// so the one call site setting it here IS the contract.
	$timeout = 5;

	$body = array(
		'idp_entity_id' => $idp_entity_id,
		'external_id'   => $external_id,
		'email'         => $email,
	);

	// The gateway body is strict: an empty string or null field is rejected
	// outright, so `contact` is only included at all when it would carry at
	// least one non-empty name, and only the non-empty names are sent.
	$contact_fields = array_filter(
		array(
			'first_name' => isset( $contact['first_name'] ) ? trim( (string) $contact['first_name'] ) : '',
			'last_name'  => isset( $contact['last_name'] ) ? trim( (string) $contact['last_name'] ) : '',
		),
		static function ( $value ) {
			return '' !== $value;
		}
	);

	if ( ! empty( $contact_fields ) ) {
		$body['contact'] = $contact_fields;
	}

	$args = array(
		'body'    => $body,
		'timeout' => $timeout,
	);

	// Deliberately NO request-args filter here, mirroring
	// agend_apps_sso_mint_token() immediately above: the payload is an
	// identity assertion (which WordPress user binds to which Agend member)
	// and must not be rewritable by a sibling plugin.
	$response = agend_apps_api()->request( 'POST', '/sso/identities', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	// Deliberately NO response filter either: the caller must see exactly
	// what the gateway decided, including the ids it minted.
	return $response;
}
