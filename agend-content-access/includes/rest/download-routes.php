<?php
/**
 * Protected download route: the member-facing side.
 *
 * @package Agend_Content_Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the download route.
 */
function agend_content_access_register_download_routes(): void {
	register_rest_route(
		'agend-content-access/v1',
		'/download/(?P<id>[A-Za-z0-9\-]+)',
		array(
			array(
				'methods'  => WP_REST_Server::READABLE,
				'callback' => 'agend_content_access_download',
				// Open by design. The gateway is the authority: it re-resolves
				// the caller's membership and re-evaluates the governing policy
				// on every request. Gating here on `is_user_logged_in()` would
				// add a second, weaker opinion that could disagree with it, and
				// an anonymous request simply arrives with no bearer and is
				// refused by Agend, which is the correct answer anyway.
				'permission_callback' => '__return_true',
				'args'                => array(
					'id' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			),
		)
	);
}

/**
 * Requests a protected download and redirects the visitor to it.
 *
 * This is a thin relay, deliberately. It attaches the visitor's Agend bearer,
 * which only this site can do because the session lives here, and forwards the
 * answer. It makes NO access decision of its own: duplicating the policy check
 * in WordPress would create a second authority that could drift from the real
 * one, and the whole design rests on there being exactly one.
 *
 * On success Agend returns a short-lived signed URL and the visitor is
 * redirected to it. The URL is never rendered into a page, never logged here,
 * and expires within a minute, so it does not survive being shared.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function agend_content_access_download( $request ) {
	$asset_id = (string) $request->get_param( 'id' );

	if ( ! function_exists( 'agend_apps_cms_get_asset_download' ) ) {
		return new WP_Error(
			'agend_content_access_core_missing',
			__( 'Downloads are unavailable. Agend Apps Core is not active.', 'agend-content-access' ),
			array( 'status' => 503 )
		);
	}

	$response = agend_apps_cms_get_asset_download( $asset_id );

	if ( is_wp_error( $response ) ) {
		// One message for every refusal, matching the gateway's own uniform
		// denial. Distinguishing "no such file" from "not for you" here would
		// undo the enumeration protection the gateway is careful to provide.
		return new WP_Error(
			'agend_content_access_download_unavailable',
			__( 'This download is not available to you.', 'agend-content-access' ),
			array( 'status' => 404 )
		);
	}

	$data = isset( $response['data'] ) && is_array( $response['data'] )
		? $response['data']
		: $response;

	$url = isset( $data['url'] ) ? (string) $data['url'] : '';

	if ( '' === $url ) {
		return new WP_Error(
			'agend_content_access_download_unavailable',
			__( 'This download is not available to you.', 'agend-content-access' ),
			array( 'status' => 404 )
		);
	}

	$redirect = new WP_REST_Response( null, 302 );

	$redirect->header( 'Location', $url );
	// The redirect carries the signed URL in a header. Nothing may store it:
	// it was minted for this visitor and a shared cache would hand it on.
	$redirect->header( 'Cache-Control', 'private, no-store' );

	return $redirect;
}
