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
 * returns a 404-mapped WP_Error otherwise. With `provision` true, a member who
 * has no Agend user on the site's SSO connection is provisioned first, as a
 * first SAML sign-in would, and the response carries `provisioning`
 * (`outcome`, plus `reason` when skipped). That needs the key to hold
 * `loop.users.provision` as well as `loop.users.sync`: without it the gateway
 * refuses the whole request with 403 INSUFFICIENT_SCOPES. The endpoint scope
 * map cannot express a scope that depends on the body, so it lists only
 * `loop.users.sync`. A member who could not be provisioned still comes back as
 * a 404, with the skip reason in the error message.
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
 *     @type bool   $provision     Optional. Provision the member first (see above). Default false.
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
 * With $provision true, each member with no Agend user on the site's SSO
 * connection is provisioned before the sync, as at a first SAML sign-in. Every
 * result item then carries `provisioning` (`outcome`, plus `reason` when
 * skipped), failed items included, and the result carries `provisioning`
 * counts by outcome. The key must hold `loop.users.provision` as well as
 * `loop.users.sync`, or the gateway refuses the whole request with 403
 * INSUFFICIENT_SCOPES. The gateway creates at most 2000 users per connection
 * per hour; a member over that limit is skipped with reason `rate_limited`.
 *
 * @param array  $users         Array of per-user payloads (see agend_apps_loop_sync_user()),
 *                              1 to 100 items. Each item carries external_id, email, roles, etc.
 *                              The site is passed separately as $idp_entity_id, not per user.
 * @param string $idp_entity_id The site's SAML IdP entity id (Issuer). Required by the gateway.
 * @param bool   $provision     Optional. Provision members before the sync. Default false,
 *                              which sends no `provision` field at all.
 * @return array|WP_Error Decoded bulk-sync result on success, or WP_Error on failure.
 */
function agend_apps_loop_bulk_sync_users( array $users, string $idp_entity_id, bool $provision = false ) {
	$body = array(
		'idp_entity_id' => $idp_entity_id,
		'users'         => array_values( $users ),
	);

	if ( $provision ) {
		$body['provision'] = true;
	}

	/**
	 * Filters the bulk-sync request args before the request is sent.
	 *
	 * @param array  $args          Request args.
	 * @param array  $users         User payloads.
	 * @param string $idp_entity_id Site IdP entity id.
	 */
	$args = (array) apply_filters(
		'agend_apps_loop_bulk_sync_users_args',
		array( 'body' => $body ),
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
 * The gateway path for one committee, optionally with a sub-resource.
 *
 * The unique id is percent-encoded with its colons left as they are. The
 * gateway reads the id from the raw request path without decoding it, so an
 * encoded colon arrives as a literal `%3A` and the committee is not found:
 * every community rule channel (`rule:<slug>`) would 404 on read, members,
 * moderator and archive. A colon is valid in a path segment (RFC 3986 pchar),
 * and the committee upsert carries the same id in its body unencoded.
 *
 * @param string $unique_id Committee unique id.
 * @param string $suffix    Optional sub-resource, starting with `/` (for example `/members`).
 * @return string The path, relative to the gateway base URL.
 */
function agend_apps_loop_committee_path( string $unique_id, string $suffix = '' ): string {
	return '/loop/integration/committees/' . str_replace( '%3A', ':', rawurlencode( $unique_id ) ) . $suffix;
}

/**
 * Gets a single committee channel by its stable unique id.
 *
 * @param string $unique_id Committee unique id.
 * @return array|WP_Error Decoded committee on success, or WP_Error on failure.
 */
function agend_apps_loop_get_committee( string $unique_id ) {
	$path = agend_apps_loop_committee_path( $unique_id );

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
 * Lists a committee channel's members with their Loop role and what manages them.
 *
 * Only members who have an identity in the site's SSO connection are listed,
 * keyed by the same external id the roster sync sends (the Upbeat membership
 * number). Members without one are counted in `unlinked_count` only. The
 * response carries no names, emails or Loop user ids.
 *
 * @param string $unique_id     Committee unique id.
 * @param string $idp_entity_id The site's SAML IdP entity id (Issuer). Required.
 * @return array|WP_Error Decoded { unique_id, members, unlinked_count } on success, or WP_Error on failure.
 *                        Each member is { external_id, role, managed_by }: `role` is one of owner, admin,
 *                        moderator or member; `managed_by` is one of wordpress_sync, group, upbeat or none.
 *                        The gateway answers 400 for an unknown IdP and 404 for an unknown committee.
 */
function agend_apps_loop_get_committee_members( string $unique_id, string $idp_entity_id ) {
	$path = agend_apps_loop_committee_path( $unique_id, '/members' );

	/**
	 * Filters the committee-members request args before the request is sent.
	 *
	 * @param array  $args          Request args.
	 * @param string $unique_id     Committee unique id.
	 * @param string $idp_entity_id Site IdP entity id.
	 */
	$args = (array) apply_filters(
		'agend_apps_loop_get_committee_members_args',
		array( 'query' => array( 'idp_entity_id' => $idp_entity_id ) ),
		$unique_id,
		$idp_entity_id
	);

	$response = agend_apps_api()->request( 'GET', $path, $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded committee-members response before it is returned.
	 *
	 * @param array  $response      Decoded response body.
	 * @param string $unique_id     Committee unique id.
	 * @param string $idp_entity_id Site IdP entity id.
	 */
	return apply_filters( 'agend_apps_loop_get_committee_members_response', $response, $unique_id, $idp_entity_id );
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
 *     @type int[]  $adopt_external_ids    Optional. Up to 2000 distinct roster external ids whose existing,
 *                                         unmanaged membership the sync should take over. A non-empty list
 *                                         also needs the `loop.committees.update` scope, else the gateway
 *                                         answers 403. The response then carries `adopted` and
 *                                         `adoption_results` ({ external_id, outcome } per id).
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
	$path = agend_apps_loop_committee_path( $unique_id );

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
	$path = agend_apps_loop_committee_path( $unique_id, '/moderator' );

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
