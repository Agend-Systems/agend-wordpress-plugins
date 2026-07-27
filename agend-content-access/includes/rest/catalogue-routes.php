<?php
/**
 * REST route serving the plan catalogue to the policy editor.
 *
 * @package Agend_Content_Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the catalogue route.
 *
 * Called from `rest_api_init`.
 */
function agend_content_access_register_catalogue_routes(): void {
	register_rest_route(
		'agend-content-access/v1',
		'/plans',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'agend_content_access_get_plans',
				'permission_callback' => 'agend_content_access_can_read_plans',
				'args'                => array(
					'refresh' => array(
						'type'              => 'boolean',
						'default'           => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
				),
			),
		)
	);
}

/**
 * Permission callback for the catalogue route.
 *
 * Deliberately NOT `__return_true`, unlike Core's public catalogue proxies.
 * This route exists only to populate an editor control, so it is gated on the
 * capability that lets somebody edit content in the first place. WordPress
 * verifies the `X-WP-Nonce` for cookie-authenticated REST requests before this
 * runs, so a logged-in editor's browser cannot be made to call it from another
 * origin.
 *
 * The plan list is not secret, but it is tenant configuration, and there is no
 * reason for it to be readable by anonymous visitors.
 *
 * @return bool|WP_Error True when permitted, WP_Error otherwise.
 */
function agend_content_access_can_read_plans() {
	if ( current_user_can( 'edit_posts' ) ) {
		return true;
	}

	return new WP_Error(
		'agend_content_access_forbidden',
		__( 'You are not allowed to read the membership plan catalogue.', 'agend-content-access' ),
		array( 'status' => rest_authorization_required_code() )
	);
}

/**
 * Returns the reduced plan catalogue.
 *
 * The response carries only what the editor control needs, plus the state it
 * needs to explain itself: whether the list is stale after a failed refresh,
 * and whether a new selected-plans policy may be saved right now.
 *
 * The tenant API key and the raw gateway payload never appear here. The
 * reduction happens server-side in the catalogue class, before this route sees
 * the data.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function agend_content_access_get_plans( $request ) {
	$catalogue = Agend_Content_Access_Catalogue::get( (bool) $request->get_param( 'refresh' ) );

	return rest_ensure_response(
		array(
			'plans'          => Agend_Content_Access_Catalogue::selectable( $catalogue['plans'] ),
			'all_plans'      => $catalogue['plans'],
			'stale'          => $catalogue['stale'],
			'can_select'     => Agend_Content_Access_Catalogue::can_select_plans( $catalogue ),
			// A human-readable reason for the editor, not a stack trace. It is
			// only ever a transport message from Core, never gateway internals.
			'error'          => $catalogue['error'],
		)
	);
}
