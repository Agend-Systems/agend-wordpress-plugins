<?php
/**
 * REST route serving the access rule vocabulary to the block editor panel.
 *
 * @package Agend_Content_Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The route's registration arguments.
 *
 * Returned by a function of its own so the permission and callback it names can
 * be asserted without a WordPress REST server.
 *
 * @return array The arguments `register_rest_route()` takes.
 */
function agend_content_access_conditions_route(): array {
	return array(
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'agend_content_access_get_conditions',
			'permission_callback' => 'agend_content_access_can_read_conditions',
			'args'                => array(
				'refresh' => array(
					'type'              => 'boolean',
					'default'           => false,
					'sanitize_callback' => 'rest_sanitize_boolean',
				),
			),
		),
	);
}

/**
 * Registers the conditions route.
 *
 * Called from `rest_api_init`.
 */
function agend_content_access_register_conditions_routes(): void {
	register_rest_route(
		'agend-content-access/v1',
		'/conditions',
		agend_content_access_conditions_route()
	);
}

/**
 * Permission callback for the conditions route.
 *
 * The same rule as the catalogue: the capability that lets somebody edit
 * content at all, and never open. The vocabulary names the site's roles, plans,
 * segments and entitlements, which is tenant configuration with no reason to be
 * readable by an anonymous visitor.
 *
 * @return bool|WP_Error True when permitted, WP_Error otherwise.
 */
function agend_content_access_can_read_conditions() {
	if ( current_user_can( 'edit_posts' ) ) {
		return true;
	}

	return new WP_Error(
		'agend_content_access_forbidden',
		__( 'You are not allowed to read the access rule list.', 'agend-content-access' ),
		array( 'status' => rest_authorization_required_code() )
	);
}

/**
 * The rules an author can choose, grouped, for the block editor's picker.
 *
 * `Agend_Content_Access_Condition_Sets::vocabulary()` builds it and documents the
 * shape, because Elementor's rule control reads the same vocabulary and the block
 * route is not loaded where Elementor's editor is. This stays as the route's one
 * entry point.
 *
 * @param bool $refresh Optional. Read the lists from the gateway again instead of the cache.
 * @return array{groups: array<int, array>, fallbacks: array<int, array>, errors: array<int, array>}
 */
function agend_content_access_conditions_payload( bool $refresh = false ): array {
	return Agend_Content_Access_Condition_Sets::vocabulary( $refresh );
}

/**
 * Returns the vocabulary.
 *
 * `?refresh=1` reads the plan, segment and entitlement lists from the gateway
 * again instead of the cache, which is what the panel's "Try again" asks for.
 *
 * @param WP_REST_Request|null $request Request.
 * @return WP_REST_Response
 */
function agend_content_access_get_conditions( $request = null ) {
	$refresh = null !== $request && (bool) $request->get_param( 'refresh' );

	return rest_ensure_response( agend_content_access_conditions_payload( $refresh ) );
}
