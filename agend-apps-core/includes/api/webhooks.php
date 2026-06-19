<?php
/**
 * Webhooks API functions.
 *
 * Server-side PHP wrappers for the Agend gateway's `/v1/webhooks/*` endpoints.
 * Sibling plugins MUST call these helpers rather than building gateway paths
 * or calling `agend_apps_api()` directly, so transport and path knowledge stay
 * centralised here.
 *
 * Administrative management endpoints (subscriptions, deliveries) are never
 * cached. Every function returns the decoded response array on success or a
 * WP_Error on failure (transport error, non-2xx, or invalid JSON).
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists webhook subscriptions for the current account.
 *
 * Scope: `webhooks.subscriptions.read`.
 *
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_webhooks_list_subscriptions() {
	/**
	 * Filters the list-subscriptions request args before the request is sent.
	 *
	 * @param array $args Request args.
	 */
	$args = (array) apply_filters( 'agend_apps_webhooks_list_subscriptions_args', array() );

	$response = agend_apps_api()->request( 'GET', '/webhooks/subscriptions', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded list-subscriptions response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 */
	return apply_filters( 'agend_apps_webhooks_list_subscriptions_response', $response );
}

/**
 * Retrieves a single webhook subscription by ID.
 *
 * Scope: `webhooks.subscriptions.read`.
 *
 * @param string $id Subscription ID (UUID).
 * @return array|WP_Error Decoded subscription on success, or WP_Error on failure.
 */
function agend_apps_webhooks_get_subscription( string $id ) {
	/**
	 * Filters the get-subscription request args before the request is sent.
	 *
	 * @param array  $args Request args.
	 * @param string $id   Subscription ID.
	 */
	$args = (array) apply_filters( 'agend_apps_webhooks_get_subscription_args', array(), $id );

	$response = agend_apps_api()->request( 'GET', '/webhooks/subscriptions/' . rawurlencode( $id ), $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded get-subscription response before it is returned.
	 *
	 * @param array  $response Decoded response body.
	 * @param string $id       Subscription ID.
	 */
	return apply_filters( 'agend_apps_webhooks_get_subscription_response', $response, $id );
}

/**
 * Creates a webhook subscription.
 *
 * Scope: `webhooks.subscriptions.manage`.
 *
 * The subscription secret is returned only at creation time. Store it
 * immediately — it cannot be retrieved later.
 *
 * @param array $subscription Subscription payload (snake_case):
 *                             - url: HTTPS endpoint URL
 *                             - events: array of event IDs (e.g. 'events.event.published')
 *                             - description: optional description (max 200 chars)
 * @return array|WP_Error Decoded subscription on success (including secret), or WP_Error on failure.
 */
function agend_apps_webhooks_create_subscription( array $subscription ) {
	/**
	 * Filters the create-subscription request args before the request is sent.
	 *
	 * @param array $args          Request args.
	 * @param array $subscription  Subscription payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_webhooks_create_subscription_args',
		array( 'body' => $subscription ),
		$subscription
	);

	$response = agend_apps_api()->request( 'POST', '/webhooks/subscriptions', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded create-subscription response before it is returned.
	 *
	 * @param array $response     Decoded response body.
	 * @param array $subscription Subscription payload.
	 */
	return apply_filters( 'agend_apps_webhooks_create_subscription_response', $response, $subscription );
}

/**
 * Updates a webhook subscription.
 *
 * Scope: `webhooks.subscriptions.manage`.
 *
 * @param string $id             Subscription ID (UUID).
 * @param array  $subscription   Updated subscription payload (snake_case):
 *                               - url: optional updated HTTPS endpoint URL
 *                               - events: optional updated array of event IDs
 *                               - description: optional updated description
 *                               - active: optional boolean to enable/disable
 * @return array|WP_Error Decoded subscription on success, or WP_Error on failure.
 */
function agend_apps_webhooks_update_subscription( string $id, array $subscription ) {
	/**
	 * Filters the update-subscription request args before the request is sent.
	 *
	 * @param array  $args         Request args.
	 * @param string $id           Subscription ID.
	 * @param array  $subscription Updated subscription payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_webhooks_update_subscription_args',
		array( 'body' => $subscription ),
		$id,
		$subscription
	);

	$response = agend_apps_api()->request( 'PATCH', '/webhooks/subscriptions/' . rawurlencode( $id ), $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded update-subscription response before it is returned.
	 *
	 * @param array  $response     Decoded response body.
	 * @param string $id           Subscription ID.
	 * @param array  $subscription Updated subscription payload.
	 */
	return apply_filters( 'agend_apps_webhooks_update_subscription_response', $response, $id, $subscription );
}

/**
 * Deletes a webhook subscription.
 *
 * Scope: `webhooks.subscriptions.manage`.
 *
 * @param string $id Subscription ID (UUID).
 * @return array|WP_Error Confirmation object on success, or WP_Error on failure.
 */
function agend_apps_webhooks_delete_subscription( string $id ) {
	/**
	 * Filters the delete-subscription request args before the request is sent.
	 *
	 * @param array  $args Request args.
	 * @param string $id   Subscription ID.
	 */
	$args = (array) apply_filters( 'agend_apps_webhooks_delete_subscription_args', array(), $id );

	$response = agend_apps_api()->request( 'DELETE', '/webhooks/subscriptions/' . rawurlencode( $id ), $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded delete-subscription response before it is returned.
	 *
	 * @param array  $response Decoded response body.
	 * @param string $id       Subscription ID.
	 */
	return apply_filters( 'agend_apps_webhooks_delete_subscription_response', $response, $id );
}

/**
 * Rotates the secret for a webhook subscription.
 *
 * Scope: `webhooks.subscriptions.manage`.
 *
 * A new secret is generated and returned. The old secret is invalidated.
 * Store the new secret immediately — it cannot be retrieved later.
 *
 * @param string $id Subscription ID (UUID).
 * @return array|WP_Error Subscription with new secret on success, or WP_Error on failure.
 */
function agend_apps_webhooks_rotate_subscription_secret( string $id ) {
	/**
	 * Filters the rotate-secret request args before the request is sent.
	 *
	 * @param array  $args Request args.
	 * @param string $id   Subscription ID.
	 */
	$args = (array) apply_filters( 'agend_apps_webhooks_rotate_subscription_secret_args', array(), $id );

	$response = agend_apps_api()->request( 'POST', '/webhooks/subscriptions/' . rawurlencode( $id ) . '/rotate-secret', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded rotate-secret response before it is returned.
	 *
	 * @param array  $response Decoded response body.
	 * @param string $id       Subscription ID.
	 */
	return apply_filters( 'agend_apps_webhooks_rotate_subscription_secret_response', $response, $id );
}

/**
 * Sends a test webhook delivery for a subscription.
 *
 * Scope: `webhooks.subscriptions.manage`.
 *
 * Fires a sample webhook event to the subscription's URL. Useful for
 * validating the endpoint is reachable and correctly processes the signature.
 *
 * @param string $id Subscription ID (UUID).
 * @return array|WP_Error Test delivery result on success, or WP_Error on failure.
 */
function agend_apps_webhooks_test_subscription( string $id ) {
	/**
	 * Filters the test-subscription request args before the request is sent.
	 *
	 * @param array  $args Request args.
	 * @param string $id   Subscription ID.
	 */
	$args = (array) apply_filters( 'agend_apps_webhooks_test_subscription_args', array(), $id );

	$response = agend_apps_api()->request( 'POST', '/webhooks/subscriptions/' . rawurlencode( $id ) . '/test', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded test-subscription response before it is returned.
	 *
	 * @param array  $response Decoded response body.
	 * @param string $id       Subscription ID.
	 */
	return apply_filters( 'agend_apps_webhooks_test_subscription_response', $response, $id );
}

/**
 * Lists webhook delivery logs.
 *
 * Scope: `webhooks.deliveries.read`.
 *
 * @param array $query Optional. Query parameters (snake_case):
 *                      - subscription_id: filter by subscription ID
 *                      - status: filter by status (pending, pending_retry, delivered, failed)
 *                      - limit: max results (default 50, max 200)
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_webhooks_list_deliveries( array $query = array() ) {
	/**
	 * Filters the list-deliveries request args before the request is sent.
	 *
	 * @param array $args  Request args.
	 * @param array $query Original query parameters.
	 */
	$args = (array) apply_filters(
		'agend_apps_webhooks_list_deliveries_args',
		array( 'query' => $query ),
		$query
	);

	$response = agend_apps_api()->request( 'GET', '/webhooks/deliveries', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded list-deliveries response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $query    Original query parameters.
	 */
	return apply_filters( 'agend_apps_webhooks_list_deliveries_response', $response, $query );
}

/**
 * Replays a previously failed webhook delivery.
 *
 * Scope: `webhooks.deliveries.replay`.
 *
 * Re-sends a delivery that has failed. The same payload and signature are
 * used. Useful for testing fixes to a webhook receiver.
 *
 * @param string $id Delivery ID (UUID).
 * @return array|WP_Error Replay result on success, or WP_Error on failure.
 */
function agend_apps_webhooks_replay_delivery( string $id ) {
	/**
	 * Filters the replay-delivery request args before the request is sent.
	 *
	 * @param array  $args Request args.
	 * @param string $id   Delivery ID.
	 */
	$args = (array) apply_filters( 'agend_apps_webhooks_replay_delivery_args', array(), $id );

	$response = agend_apps_api()->request( 'POST', '/webhooks/deliveries/' . rawurlencode( $id ) . '/replay', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded replay-delivery response before it is returned.
	 *
	 * @param array  $response Decoded response body.
	 * @param string $id       Delivery ID.
	 */
	return apply_filters( 'agend_apps_webhooks_replay_delivery_response', $response, $id );
}
