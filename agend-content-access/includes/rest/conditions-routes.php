<?php
/**
 * REST route serving the display condition vocabulary to the block editor panel.
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
		__( 'You are not allowed to read the display condition list.', 'agend-content-access' ),
		array( 'status' => rest_authorization_required_code() )
	);
}

/**
 * The vocabulary, shaped for the block editor panel.
 *
 * `Agend_Content_Access_Condition_Sets::all()` returns a map of provider to a
 * label and a map of value to label, which is the right shape for Elementor's
 * select and an awkward one for a script: PHP turns a numeric-looking key (a
 * plan whose slug is `2024`) into an integer, and a JSON object does not
 * promise an order. This returns lists, with every value already joined to its
 * provider as `"<provider>:<value>"`, which is exactly what the block stores,
 * so the script never has to know how a condition is spelled.
 *
 * ```
 * {
 *   "groups": [
 *     { "provider": "agend_wordpress", "label": "WordPress user",
 *       "conditions": [ { "value": "agend_wordpress:logged_in", "label": "Signed in" } ] }
 *   ],
 *   "fallbacks": [ { "id": 12, "title": "Join us" } ]
 * }
 * ```
 *
 * A group whose list could not be loaded is present with `conditions: []`, as
 * `Condition_Sets` keeps it, so the panel can say "this list may be
 * unavailable" and not "this site has none". `fallbacks` is public content
 * only, with no password-protected post either, from
 * `Condition_Sets::fallback_options()`, and does not include the
 * "Nothing" choice: the panel adds its own.
 *
 * Both lists pass through the site's filters, so every entry is checked rather
 * than trusted: one that is not the documented shape is skipped, not repaired.
 *
 * @return array{groups: array<int, array>, fallbacks: array<int, array>}
 */
function agend_content_access_conditions_payload(): array {
	$groups = array();

	foreach ( Agend_Content_Access_Condition_Sets::all() as $provider => $set ) {
		if ( ! is_array( $set ) ) {
			continue;
		}

		$conditions = array();

		foreach ( isset( $set['conditions'] ) && is_array( $set['conditions'] ) ? $set['conditions'] : array() as $value => $label ) {
			$conditions[] = array(
				'value' => $provider . ':' . $value,
				'label' => is_scalar( $label ) ? (string) $label : (string) $value,
			);
		}

		$groups[] = array(
			'provider'   => (string) $provider,
			'label'      => isset( $set['label'] ) && is_scalar( $set['label'] ) && '' !== (string) $set['label']
				? (string) $set['label']
				: (string) $provider,
			'conditions' => $conditions,
		);
	}

	$fallbacks = array();

	foreach ( Agend_Content_Access_Condition_Sets::fallback_options() as $id => $title ) {
		// The empty key is "Nothing", which the panel supplies itself.
		if ( '' === (string) $id || ! ctype_digit( (string) $id ) ) {
			continue;
		}

		$fallbacks[] = array(
			'id'    => (int) $id,
			'title' => is_scalar( $title ) ? (string) $title : (string) $id,
		);
	}

	return array(
		'groups'    => $groups,
		'fallbacks' => $fallbacks,
	);
}

/**
 * Returns the vocabulary.
 *
 * @return WP_REST_Response
 */
function agend_content_access_get_conditions() {
	return rest_ensure_response( agend_content_access_conditions_payload() );
}
