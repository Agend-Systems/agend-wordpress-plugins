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
 * One group of the vocabulary.
 *
 * @param string $key   Provider key, which is the prefix of every value in it.
 * @param string $label Heading the picker shows.
 * @param array  $items Items, already shaped.
 * @param bool   $stale Whether the list is the last known copy after a failed refresh.
 * @return array{key: string, label: string, stale: bool, items: array}
 */
function agend_content_access_vocabulary_group( string $key, string $label, array $items, bool $stale = false ): array {
	return array(
		'key'   => $key,
		'label' => $label,
		'stale' => $stale,
		'items' => array_values( $items ),
	);
}

/**
 * The rules an author can choose, grouped, for the block editor's picker.
 *
 * Every item is a rule exactly as a block stores it, `"<provider>:<value>"`, so
 * the script never has to know how a rule is spelled. New rules name plans and
 * segments by ID, and entitlements by gate key; a plan's or segment's slug is
 * offered as an alias, because slug rules still evaluate and older blocks and
 * imported conditions hold them, and the picker then recognises, labels and
 * removes them like the ID rule itself without offering a second choice.
 *
 * ```
 * {
 *   "groups": [
 *     { "key": "agend_membership", "label": "Membership", "stale": false, "items": [
 *       { "value": "agend_membership:is_member", "label": "Any current member", "kind": "member" },
 *       { "value": "agend_membership:<tier uuid>", "label": "Gold", "kind": "plan",
 *         "aliases": [ "agend_membership:gold" ] } ] },
 *     { "key": "agend_segments", ... "kind": "segment" },
 *     { "key": "agend_entitlements", ... "kind": "entitlement" or "platform", "description": "..." },
 *     { "key": "agend_wordpress", ... "kind": "login" or "role" }
 *   ],
 *   "fallbacks": [ { "id": 12, "title": "Join us" } ],
 *   "errors": [ { "group": "agend_entitlements", "message": "..." } ]
 * }
 * ```
 *
 * `label` is the plain name ("Gold"); the picker composes "Plan: Gold" from the
 * item's `kind`. `inactive: true` marks a plan that was switched off: it is not
 * offered for a new rule, but is kept so a block that names one can say which.
 * Platform gates come first in the entitlements, ahead of the authored ones, so
 * the few capabilities the platform defines are not buried among hundreds.
 *
 * A group whose list could not be loaded is present with its last known items
 * (possibly none) and `stale: true`, and its reason is in `errors`, so the
 * picker can show the message and say "this list may be unavailable" and not
 * "this site has none". `errors` is a list of `{group, message}`: one per list
 * that failed, in words an editor can act on (for example the API key scope the
 * entitlement list needs).
 *
 * A group a site adds through the `agend_content_access_condition_sets` filter
 * follows the four built in ones, each of its values joined to the provider key
 * as `kind: "other"`. The four built-in groups are read from the catalogue and
 * not from that filter, because the filter's plans and segments are keyed by
 * slug.
 *
 * `fallbacks` is public content only, with no password-protected post either,
 * from `Condition_Sets::fallback_options()`, and does not include the "Nothing"
 * choice: the panel adds its own. Every entry is checked rather than trusted:
 * one that is not the documented shape is skipped, not repaired.
 *
 * @param bool $refresh Optional. Read the lists from the gateway again instead of the cache.
 * @return array{groups: array<int, array>, fallbacks: array<int, array>, errors: array<int, array>}
 */
function agend_content_access_conditions_payload( bool $refresh = false ): array {
	$sections = Agend_Content_Access_Catalogue::sections( $refresh );
	$groups   = array();
	$errors   = array();

	// Membership: the standing rule, then every plan, by id.
	$membership = array(
		array(
			'value' => Agend_Content_Access_Condition_Providers::MEMBERSHIP . ':is_member',
			'label' => __( 'Any current member', 'agend-content-access' ),
			'kind'  => 'member',
		),
	);

	foreach ( $sections['plans']['items'] as $plan ) {
		if ( ! is_array( $plan ) || empty( $plan['id'] ) ) {
			continue;
		}

		$id   = (string) $plan['id'];
		$slug = isset( $plan['slug'] ) ? (string) $plan['slug'] : '';
		$item = array(
			'value'   => Agend_Content_Access_Condition_Providers::MEMBERSHIP . ':' . $id,
			'label'   => isset( $plan['name'] ) && '' !== (string) $plan['name'] ? (string) $plan['name'] : $id,
			'kind'    => 'plan',
			'aliases' => '' !== $slug && $slug !== $id ? array( Agend_Content_Access_Condition_Providers::MEMBERSHIP . ':' . $slug ) : array(),
		);

		if ( empty( $plan['active'] ) ) {
			$item['inactive'] = true;
		}

		$membership[] = $item;
	}

	$groups[] = agend_content_access_vocabulary_group(
		Agend_Content_Access_Condition_Providers::MEMBERSHIP,
		__( 'Membership', 'agend-content-access' ),
		$membership,
		$sections['plans']['stale']
	);

	// Segments, by id.
	$segments = array();

	foreach ( $sections['segments']['items'] as $segment ) {
		if ( ! is_array( $segment ) || empty( $segment['id'] ) ) {
			continue;
		}

		$id   = (string) $segment['id'];
		$slug = isset( $segment['slug'] ) ? (string) $segment['slug'] : '';

		$segments[] = array(
			'value'   => Agend_Content_Access_Condition_Providers::SEGMENTS . ':' . $id,
			'label'   => isset( $segment['name'] ) && '' !== (string) $segment['name'] ? (string) $segment['name'] : ( '' !== $slug ? $slug : $id ),
			'kind'    => 'segment',
			'aliases' => '' !== $slug && $slug !== $id ? array( Agend_Content_Access_Condition_Providers::SEGMENTS . ':' . $slug ) : array(),
		);
	}

	$groups[] = agend_content_access_vocabulary_group(
		Agend_Content_Access_Condition_Providers::SEGMENTS,
		__( 'Segments', 'agend-content-access' ),
		$segments,
		$sections['segments']['stale']
	);

	// Entitlements, by gate key, with the platform's own capabilities first.
	$platform     = array();
	$entitlements = array();

	foreach ( $sections['entitlements']['items'] as $entitlement ) {
		if ( ! is_array( $entitlement ) || empty( $entitlement['gate_key'] ) ) {
			continue;
		}

		$key  = (string) $entitlement['gate_key'];
		$gate = isset( $entitlement['kind'] ) && 'platform-gate' === $entitlement['kind'];
		$item = array(
			'value' => Agend_Content_Access_Condition_Providers::ENTITLEMENTS . ':' . $key,
			'label' => isset( $entitlement['name'] ) && '' !== (string) $entitlement['name'] ? (string) $entitlement['name'] : $key,
			'kind'  => $gate ? 'platform' : 'entitlement',
		);

		if ( isset( $entitlement['description'] ) && '' !== (string) $entitlement['description'] ) {
			$item['description'] = (string) $entitlement['description'];
		}

		if ( $gate ) {
			$platform[] = $item;
		} else {
			$entitlements[] = $item;
		}
	}

	$groups[] = agend_content_access_vocabulary_group(
		Agend_Content_Access_Condition_Providers::ENTITLEMENTS,
		__( 'Entitlements', 'agend-content-access' ),
		array_merge( $platform, $entitlements ),
		$sections['entitlements']['stale']
	);

	// WordPress: signed in or out, and every role.
	$wordpress = array(
		array(
			'value' => Agend_Content_Access_Condition_Providers::WORDPRESS . ':logged_in',
			'label' => __( 'Signed in', 'agend-content-access' ),
			'kind'  => 'login',
		),
		array(
			'value' => Agend_Content_Access_Condition_Providers::WORDPRESS . ':logged_out',
			'label' => __( 'Signed out', 'agend-content-access' ),
			'kind'  => 'login',
		),
	);

	if ( function_exists( 'wp_roles' ) ) {
		foreach ( wp_roles()->roles as $id => $role ) {
			$wordpress[] = array(
				'value' => Agend_Content_Access_Condition_Providers::WORDPRESS . ':role_' . $id,
				'label' => isset( $role['name'] ) && '' !== (string) $role['name'] ? (string) $role['name'] : (string) $id,
				'kind'  => 'role',
			);
		}
	}

	$groups[] = agend_content_access_vocabulary_group(
		Agend_Content_Access_Condition_Providers::WORDPRESS,
		__( 'WordPress', 'agend-content-access' ),
		$wordpress
	);

	// Anything a site added through the filter.
	$built_in = array(
		Agend_Content_Access_Condition_Providers::WORDPRESS,
		Agend_Content_Access_Condition_Providers::MEMBERSHIP,
		Agend_Content_Access_Condition_Providers::SEGMENTS,
		Agend_Content_Access_Condition_Providers::ENTITLEMENTS,
	);

	foreach ( Agend_Content_Access_Condition_Sets::all() as $provider => $set ) {
		$provider = (string) $provider;

		if ( in_array( $provider, $built_in, true ) || ! is_array( $set ) ) {
			continue;
		}

		$items = array();

		foreach ( isset( $set['conditions'] ) && is_array( $set['conditions'] ) ? $set['conditions'] : array() as $value => $label ) {
			$items[] = array(
				'value' => $provider . ':' . $value,
				'label' => is_scalar( $label ) ? (string) $label : (string) $value,
				'kind'  => 'other',
			);
		}

		$groups[] = agend_content_access_vocabulary_group(
			$provider,
			isset( $set['label'] ) && is_scalar( $set['label'] ) && '' !== (string) $set['label'] ? (string) $set['label'] : $provider,
			$items
		);
	}

	// Why a list is empty or out of date, in words an editor can act on.
	$sources = array(
		Agend_Content_Access_Condition_Providers::MEMBERSHIP   => 'plans',
		Agend_Content_Access_Condition_Providers::SEGMENTS     => 'segments',
		Agend_Content_Access_Condition_Providers::ENTITLEMENTS => 'entitlements',
	);

	foreach ( $sources as $provider => $section ) {
		$message = isset( $sections[ $section ]['error'] ) ? (string) $sections[ $section ]['error'] : '';

		if ( '' !== $message ) {
			$errors[] = array(
				'group'   => $provider,
				'message' => $message,
			);
		}
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
		'errors'    => $errors,
	);
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
