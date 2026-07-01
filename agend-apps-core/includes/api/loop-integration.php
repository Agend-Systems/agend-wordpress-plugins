<?php
/**
 * Loop WordPress integration API functions.
 *
 * Server-side PHP wrappers for the Agend gateway's
 * `/v1/loop/integration/*` endpoints (SPEC-LOOP-001). Sibling plugins
 * (e.g. agend-loop-sync) MUST call these helpers rather than building
 * gateway paths or calling `agend_apps_api()` directly, so transport and
 * path knowledge stay centralised here.
 *
 * Every function returns the decoded response array on success or a
 * WP_Error on failure (transport error, non-2xx, or invalid JSON).
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Syncs a single WordPress user into Loop.
 *
 * The user must already exist in Loop (provisioned via SSO); the gateway
 * returns a 404-mapped WP_Error otherwise.
 *
 * @param array $user {
 *     User payload.
 *
 *     @type string $idp_entity_id The site's SAML IdP entity id (Issuer). Required.
 *     @type int    $external_id   External member id asserted by the IdP NameID. Required.
 *     @type string $email         User email. Required.
 *     @type string $display_name  Optional. Display name.
 *     @type string $first_name    Optional. First name.
 *     @type string $last_name     Optional. Last name.
 *     @type string $avatar_url    Optional. Avatar URL.
 *     @type array  $roles         WordPress role slugs. Required (may be empty).
 * }
 * @return array|WP_Error Decoded synced user on success, or WP_Error on failure.
 */
function agend_apps_loop_sync_user( array $user ) {
	/**
	 * Filters the user-sync request args before the request is sent.
	 *
	 * @param array $args Request args.
	 * @param array $user User payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_loop_sync_user_args',
		array( 'body' => $user ),
		$user
	);

	$response = agend_apps_api()->request( 'POST', '/loop/integration/users/sync', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded user-sync response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $user     User payload.
	 */
	return apply_filters( 'agend_apps_loop_sync_user_response', $response, $user );
}

/**
 * Bulk-syncs WordPress users into Loop.
 *
 * Each user is resolved and synced independently; unresolved users are
 * reported as failed in the result, not as a fatal error.
 *
 * @param array  $users         Array of per-user payloads (see agend_apps_loop_sync_user()),
 *                              1 to 100 items. Each item carries external_id, email, roles, etc.
 *                              The site is passed separately as $idp_entity_id, not per user.
 * @param string $idp_entity_id The site's SAML IdP entity id (Issuer). Required by the gateway.
 * @return array|WP_Error Decoded bulk-sync result on success, or WP_Error on failure.
 */
function agend_apps_loop_bulk_sync_users( array $users, string $idp_entity_id ) {
	/**
	 * Filters the bulk-sync request args before the request is sent.
	 *
	 * @param array  $args          Request args.
	 * @param array  $users         User payloads.
	 * @param string $idp_entity_id Site IdP entity id.
	 */
	$args = (array) apply_filters(
		'agend_apps_loop_bulk_sync_users_args',
		array(
			'body' => array(
				'idp_entity_id' => $idp_entity_id,
				'users'         => array_values( $users ),
			),
		),
		$users,
		$idp_entity_id
	);

	$response = agend_apps_api()->request( 'POST', '/loop/integration/users/bulk-sync', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded bulk-sync response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $users    User payloads.
	 */
	return apply_filters( 'agend_apps_loop_bulk_sync_users_response', $response, $users );
}

/**
 * Lists synced committee channels.
 *
 * @param int $page  Optional. Page number (1-based). Default 1.
 * @param int $limit Optional. Page size (1 to 100). Default 50.
 * @return array|WP_Error Decoded list response on success, or WP_Error on failure.
 */
function agend_apps_loop_list_committees( int $page = 1, int $limit = 50 ) {
	/**
	 * Filters the committee-list request args before the request is sent.
	 *
	 * @param array $args Request args.
	 * @param int   $page Page number.
	 * @param int   $limit Page size.
	 */
	$args = (array) apply_filters(
		'agend_apps_loop_list_committees_args',
		array(
			'query' => array(
				'page'  => $page,
				'limit' => $limit,
			),
		),
		$page,
		$limit
	);

	$response = agend_apps_api()->request( 'GET', '/loop/integration/committees', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded committee-list response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 */
	return apply_filters( 'agend_apps_loop_list_committees_response', $response );
}

/**
 * Gets a single committee channel by its stable unique id.
 *
 * @param string $unique_id Committee unique id.
 * @return array|WP_Error Decoded committee on success, or WP_Error on failure.
 */
function agend_apps_loop_get_committee( string $unique_id ) {
	$path = '/loop/integration/committees/' . rawurlencode( $unique_id );

	$response = agend_apps_api()->request( 'GET', $path, array() );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded committee response before it is returned.
	 *
	 * @param array  $response  Decoded response body.
	 * @param string $unique_id Committee unique id.
	 */
	return apply_filters( 'agend_apps_loop_get_committee_response', $response, $unique_id );
}

/**
 * Creates or updates a committee channel (idempotent on unique_id).
 *
 * When a `roster` is supplied it supersedes the chair/secretary/member
 * fields and may contain multiple owners and moderators.
 *
 * @param array $committee {
 *     Committee payload.
 *
 *     @type string $idp_entity_id         The site's SAML IdP entity id (Issuer). Required.
 *     @type string $unique_id             Stable committee identifier. Required.
 *     @type string $name                  Committee name. Required.
 *     @type string $parent_unique_id      Optional. Parent committee unique id (sub-committee).
 *     @type array  $roster                Optional. Array of { external_id, channel_role, committee_role_label, email }.
 *     @type int    $chair_external_id     Optional. Legacy chair external id.
 *     @type int    $secretary_external_id Optional. Legacy secretary external id.
 *     @type array  $member_external_ids   Optional. Legacy member external ids.
 *     @type string $color                 Optional. Channel colour.
 *     @type bool   $is_active             Whether the committee is active. Defaults true server-side.
 * }
 * @return array|WP_Error Decoded { committee, created } on success, or WP_Error on failure.
 */
function agend_apps_loop_sync_committee( array $committee ) {
	/**
	 * Filters the committee-sync request args before the request is sent.
	 *
	 * @param array $args      Request args.
	 * @param array $committee Committee payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_loop_sync_committee_args',
		array( 'body' => $committee ),
		$committee
	);

	$response = agend_apps_api()->request( 'POST', '/loop/integration/committees', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded committee-sync response before it is returned.
	 *
	 * @param array $response  Decoded response body.
	 * @param array $committee Committee payload.
	 */
	return apply_filters( 'agend_apps_loop_sync_committee_response', $response, $committee );
}

/**
 * Archives a committee channel.
 *
 * @param string $unique_id  Committee unique id.
 * @param string $visibility Optional. Archive visibility: 'hidden' (default) or 'readonly'.
 * @return array|WP_Error Decoded { status: 'archived' } on success, or WP_Error on failure.
 */
function agend_apps_loop_archive_committee( string $unique_id, string $visibility = 'hidden' ) {
	$path = '/loop/integration/committees/' . rawurlencode( $unique_id );

	/**
	 * Filters the committee-archive request args before the request is sent.
	 *
	 * @param array  $args       Request args.
	 * @param string $unique_id  Committee unique id.
	 * @param string $visibility Archive visibility.
	 */
	$args = (array) apply_filters(
		'agend_apps_loop_archive_committee_args',
		array( 'body' => array( 'visibility' => $visibility ) ),
		$unique_id,
		$visibility
	);

	$response = agend_apps_api()->request( 'DELETE', $path, $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded committee-archive response before it is returned.
	 *
	 * @param array  $response  Decoded response body.
	 * @param string $unique_id Committee unique id.
	 */
	return apply_filters( 'agend_apps_loop_archive_committee_response', $response, $unique_id );
}

/**
 * Sets a committee's chair (owner) or secretary (moderator).
 *
 * @param string $unique_id  Committee unique id.
 * @param string $role       Either 'chair' or 'secretary'.
 * @param int    $wp_user_id WordPress user id to assign.
 * @return array|WP_Error Decoded { status: 'updated' } on success, or WP_Error on failure.
 */
function agend_apps_loop_set_committee_moderator( string $unique_id, string $role, int $wp_user_id ) {
	$path = '/loop/integration/committees/' . rawurlencode( $unique_id ) . '/moderator';

	/**
	 * Filters the committee-moderator request args before the request is sent.
	 *
	 * @param array  $args       Request args.
	 * @param string $unique_id  Committee unique id.
	 * @param string $role       Moderator role.
	 * @param int    $wp_user_id WordPress user id.
	 */
	$args = (array) apply_filters(
		'agend_apps_loop_set_committee_moderator_args',
		array(
			'body' => array(
				'role'       => $role,
				'wp_user_id' => $wp_user_id,
			),
		),
		$unique_id,
		$role,
		$wp_user_id
	);

	$response = agend_apps_api()->request( 'PATCH', $path, $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded committee-moderator response before it is returned.
	 *
	 * @param array  $response   Decoded response body.
	 * @param string $unique_id  Committee unique id.
	 * @param string $role       Moderator role.
	 * @param int    $wp_user_id WordPress user id.
	 */
	return apply_filters(
		'agend_apps_loop_set_committee_moderator_response',
		$response,
		$unique_id,
		$role,
		$wp_user_id
	);
}
