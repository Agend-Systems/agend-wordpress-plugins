<?php
/**
 * Loop membership API functions.
 *
 * Server-side PHP wrapper for the Agend gateway's
 * `GET /v1/loop/integration/members/channels`. Like the other
 * `/v1/loop/integration/*` routes it is asked with the site's own key, but it
 * lives apart from `loop-integration.php` so the channel membership condition
 * can load it without the user and committee sync wrappers, which
 * agend-loop-sync's tests replace with doubles.
 *
 * Returns the decoded response array on success or a WP_Error on failure
 * (transport error, non-2xx, or invalid JSON).
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether a member belongs to an active Loop channel, asked with the site's
 * own key and naming the member by contact pair.
 *
 * Scope: `loop.memberships.read`. The key's allowed contact sources must list
 * `external_source`, or the gateway answers `403 SOURCE_NOT_ALLOWED`. Always
 * unattended: the member's bearer is never sent. Not cached here; callers
 * cache.
 *
 * `data.in_active_channel` is true when the member belongs to a channel that
 * is neither archived nor a direct message. `data.resolved` is false when the
 * pair names no Agend user in the organisation yet; `in_active_channel` is
 * then false.
 *
 * @param array $contact The member's contact pair, `external_source` and `external_id`.
 * @return array|WP_Error Decoded response (`data.in_active_channel`, `data.resolved`) on success, or WP_Error on failure.
 */
function agend_apps_loop_get_member_channels( array $contact ) {
	/**
	 * Filters the member-channels request args before the request is sent.
	 *
	 * `unattended` is set again afterwards: the member's bearer never goes
	 * to this route.
	 *
	 * @param array $args    Request args.
	 * @param array $contact The member's contact pair.
	 */
	$args = (array) apply_filters(
		'agend_apps_loop_get_member_channels_args',
		array(
			'unattended' => true,
			'query'      => array(
				'external_source' => (string) ( $contact['external_source'] ?? '' ),
				'external_id'     => (string) ( $contact['external_id'] ?? '' ),
			),
		),
		$contact
	);

	$args['unattended'] = true;

	$response = agend_apps_api()->request( 'GET', '/loop/integration/members/channels', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded member-channels response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $contact  The member's contact pair.
	 */
	return apply_filters( 'agend_apps_loop_get_member_channels_response', $response, $contact );
}

/**
 * The channels a member belongs to, asked with the site's own key and naming
 * the member by contact pair.
 *
 * The same route and scope as {@see agend_apps_loop_get_member_channels()},
 * asked with `include=channels`. `data.channels` is then the member's active
 * (not archived, not direct message) channels in the organisation, by name:
 * each with `id`, `name`, `slug`, `description`, `channel_type` and
 * `unique_id`. A gateway that predates `include` ignores it and answers
 * without a `channels` key, which callers must treat as "not available yet",
 * not as an empty list. Always unattended: the member's bearer is never sent.
 * Not cached here; callers cache.
 *
 * @param array $contact The member's contact pair, `external_source` and `external_id`.
 * @return array|WP_Error Decoded response on success, or WP_Error on failure.
 */
function agend_apps_loop_get_member_channel_list( array $contact ) {
	/**
	 * Filters the member channel list request args before the request is sent.
	 *
	 * `unattended` is set again afterwards: the member's bearer never goes
	 * to this route.
	 *
	 * @param array $args    Request args.
	 * @param array $contact The member's contact pair.
	 */
	$args = (array) apply_filters(
		'agend_apps_loop_get_member_channel_list_args',
		array(
			'unattended' => true,
			'query'      => array(
				'external_source' => (string) ( $contact['external_source'] ?? '' ),
				'external_id'     => (string) ( $contact['external_id'] ?? '' ),
				'include'         => 'channels',
			),
		),
		$contact
	);

	$args['unattended'] = true;

	$response = agend_apps_api()->request( 'GET', '/loop/integration/members/channels', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded member channel list response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $contact  The member's contact pair.
	 */
	return apply_filters( 'agend_apps_loop_get_member_channel_list_response', $response, $contact );
}
