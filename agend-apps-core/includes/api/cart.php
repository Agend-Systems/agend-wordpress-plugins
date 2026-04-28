<?php
/**
 * Cart API functions.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds a scoped cache key for a cart request.
 *
 * User-authenticated requests are keyed on user ID; anonymous requests
 * are keyed on cart session token. This prevents one identity from
 * reading another identity's cached cart data.
 *
 * @param string $user_id      Logged-in user ID, or empty string for anonymous.
 * @param string $cart_session Anonymous cart session token.
 * @return string Cache key prefixed with the endpoint key.
 */
function agend_apps_cart_cache_key( string $user_id, string $cart_session ): string {
	if ( '' !== $user_id ) {
		return Agend_Apps_Cache::build_key( 'cart_get', array( 'user_id' => $user_id ) );
	}

	return Agend_Apps_Cache::build_key( 'cart_get_anonymous', array( 'cart_session' => $cart_session ) );
}

/**
 * Retrieves the current cart for a given identity.
 *
 * When a `user_id` is supplied the result is cached under the
 * `cart_get` TTL; anonymous requests are cached under `cart_get_anonymous`.
 *
 * @param string $cart_session Cart session token forwarded as `X-Cart-Session`.
 * @param string $user_id      Optional. Authenticated user ID forwarded as `X-User-ID`. Default empty string.
 * @return array|WP_Error Decoded cart array on success, or WP_Error on failure.
 */
function agend_apps_cart_get( string $cart_session, string $user_id = '' ) {
	/**
	 * Filters the cart get request args before the request is sent.
	 *
	 * @param array  $args         Request args.
	 * @param string $cart_session Cart session token.
	 * @param string $user_id      Authenticated user ID, or empty string.
	 */
	$args = (array) apply_filters(
		'agend_apps_cart_get_args',
		array(
			'cart_session' => $cart_session,
			'user_id'      => $user_id,
		),
		$cart_session,
		$user_id
	);

	$endpoint_key = '' !== $user_id ? 'cart_get' : 'cart_get_anonymous';
	$cache_key    = agend_apps_cart_cache_key( $user_id, $cart_session );
	$ttl          = Agend_Apps_Settings::get_cache_ttl( $endpoint_key );

	$response = agend_apps_api()->get_cached( '/cart', $args, $cache_key, $ttl );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded cart get response before it is returned.
	 *
	 * @param array  $response     Decoded response body.
	 * @param string $cart_session Cart session token.
	 * @param string $user_id      Authenticated user ID, or empty string.
	 */
	return apply_filters( 'agend_apps_cart_get_response', $response, $cart_session, $user_id );
}

/**
 * Adds an item to the cart.
 *
 * Clears any cached cart for the given identity after a successful add.
 *
 * @param array  $item         Item payload forwarded as the request body.
 * @param string $cart_session Cart session token forwarded as `X-Cart-Session`.
 * @param string $user_id      Optional. Authenticated user ID forwarded as `X-User-ID`. Default empty string.
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_cart_add_item( array $item, string $cart_session, string $user_id = '' ) {
	/**
	 * Filters the cart add-item request args before the request is sent.
	 *
	 * @param array  $args         Request args.
	 * @param array  $item         Item payload.
	 * @param string $cart_session Cart session token.
	 * @param string $user_id      Authenticated user ID, or empty string.
	 */
	$args = (array) apply_filters(
		'agend_apps_cart_add_item_args',
		array(
			'body'         => $item,
			'cart_session' => $cart_session,
			'user_id'      => $user_id,
		),
		$item,
		$cart_session,
		$user_id
	);

	$response = agend_apps_api()->request( 'POST', '/cart/items', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$cache_key = agend_apps_cart_cache_key( $user_id, $cart_session );
	delete_transient( 'agend_apps_' . $cache_key );

	/**
	 * Filters the decoded cart add-item response before it is returned.
	 *
	 * @param array  $response     Decoded response body.
	 * @param string $cart_session Cart session token.
	 * @param string $user_id      Authenticated user ID, or empty string.
	 */
	return apply_filters( 'agend_apps_cart_add_item_response', $response, $cart_session, $user_id );
}

/**
 * Updates an existing item in the cart.
 *
 * Clears any cached cart for the given identity after a successful update.
 *
 * @param array  $item         Updated item payload forwarded as the request body.
 * @param string $cart_session Cart session token forwarded as `X-Cart-Session`.
 * @param string $user_id      Optional. Authenticated user ID forwarded as `X-User-ID`. Default empty string.
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_cart_update_item( array $item, string $cart_session, string $user_id = '' ) {
	/**
	 * Filters the cart update-item request args before the request is sent.
	 *
	 * @param array  $args         Request args.
	 * @param array  $item         Updated item payload.
	 * @param string $cart_session Cart session token.
	 * @param string $user_id      Authenticated user ID, or empty string.
	 */
	$args = (array) apply_filters(
		'agend_apps_cart_update_item_args',
		array(
			'body'         => $item,
			'cart_session' => $cart_session,
			'user_id'      => $user_id,
		),
		$item,
		$cart_session,
		$user_id
	);

	$response = agend_apps_api()->request( 'POST', '/cart/items/update', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$cache_key = agend_apps_cart_cache_key( $user_id, $cart_session );
	delete_transient( 'agend_apps_' . $cache_key );

	/**
	 * Filters the decoded cart update-item response before it is returned.
	 *
	 * @param array  $response     Decoded response body.
	 * @param string $item_id      Item ID.
	 * @param string $cart_session Cart session token.
	 * @param string $user_id      Authenticated user ID, or empty string.
	 */
	return apply_filters( 'agend_apps_cart_update_item_response', $response, $item['itemId'], $cart_session, $user_id );
}

/**
 * Removes an item from the cart.
 *
 * Clears any cached cart for the given identity after a successful removal.
 *
 * @param string $item_id      Passed Item ID to be removed from the cart.
 * @param string $cart_session Cart session token forwarded as `X-Cart-Session`.
 * @param string $user_id      Optional. Authenticated user ID forwarded as `X-User-ID`. Default empty string.
 *
 * @return array|WP_Error Empty array on success (204 No Content), or WP_Error on failure.
 */
function agend_apps_cart_remove_item( string $item_id, string $cart_session, string $user_id = '' ) {
	/**
	 * Filters the cart remove-item request args before the request is sent.
	 *
	 * @param array  $args         Request args.
	 * @param string $cart_session Cart session token.
	 * @param string $user_id      Authenticated user ID, or empty string.
	 */
	$args = (array) apply_filters(
		'agend_apps_cart_remove_item_args',
		array(
			'body'         => array(
				'itemId' => $item_id,
			),
			'cart_session' => $cart_session,
			'user_id'      => $user_id,
		),
		$cart_session,
		$user_id
	);

	$response = agend_apps_api()->request( 'POST', '/cart/items/remove', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$cache_key = agend_apps_cart_cache_key( $user_id, $cart_session );
	delete_transient( 'agend_apps_' . $cache_key );

	/**
	 * Filters the decoded cart remove-item response before it is returned.
	 *
	 * @param array  $response     Decoded response body.
	 * @param string $item_id      Item ID.
	 * @param string $cart_session Cart session token.
	 * @param string $user_id      Authenticated user ID, or empty string.
	 */
	return apply_filters( 'agend_apps_cart_remove_item_response', $response, $item_id, $cart_session, $user_id );
}

/**
 * Initiates a checkout session for the current cart.
 *
 * Locks the cart status to `locked` on success. The cart cache is busted
 * after a successful call. Does not cache the response.
 *
 * @param array  $urls         Array containing `successUrl` (string) and `cancelUrl` (string).
 * @param string $cart_session Cart session token forwarded as `X-Cart-Session`.
 * @param string $user_id      Optional. Authenticated user ID forwarded as `X-User-ID`. Default empty string.
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_cart_checkout( array $urls, string $cart_session, string $user_id = '' ) {
	/**
	 * Filters the cart checkout request args before the request is sent.
	 *
	 * @param array  $args         Request args.
	 * @param array  $urls         Checkout URL payload.
	 * @param string $cart_session Cart session token.
	 * @param string $user_id      Authenticated user ID, or empty string.
	 */
	$args = (array) apply_filters(
		'agend_apps_cart_checkout_args',
		array(
			'body'         => $urls,
			'cart_session' => $cart_session,
			'user_id'      => $user_id,
		),
		$urls,
		$cart_session,
		$user_id
	);

	$response = agend_apps_api()->request( 'POST', '/cart/checkout', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$cache_key = agend_apps_cart_cache_key( $user_id, $cart_session );
	delete_transient( 'agend_apps_' . $cache_key );

	/**
	 * Filters the decoded cart checkout response before it is returned.
	 *
	 * @param array  $response     Decoded response body.
	 * @param string $cart_session Cart session token.
	 * @param string $user_id      Authenticated user ID, or empty string.
	 */
	return apply_filters( 'agend_apps_cart_checkout_response', $response, $cart_session, $user_id );
}

/**
 * Completes an in-progress checkout session.
 *
 * Busts the cart cache after a successful call.
 *
 * @param string $cart_session Cart session token forwarded as `X-Cart-Session`.
 * @param string $user_id      Optional. Authenticated user ID forwarded as `X-User-ID`. Default empty string.
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_cart_checkout_complete( string $cart_session, string $user_id = '' ) {
	/**
	 * Filters the cart checkout complete request args before the request is sent.
	 *
	 * @param array  $args         Request args.
	 * @param string $cart_session Cart session token.
	 * @param string $user_id      Authenticated user ID, or empty string.
	 */
	$args = (array) apply_filters(
		'agend_apps_cart_checkout_complete_args',
		array(
			'cart_session' => $cart_session,
			'user_id'      => $user_id,
		),
		$cart_session,
		$user_id
	);

	$response = agend_apps_api()->request( 'POST', '/cart/checkout/complete', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$cache_key = agend_apps_cart_cache_key( $user_id, $cart_session );
	delete_transient( 'agend_apps_' . $cache_key );

	/**
	 * Filters the decoded cart checkout complete response before it is returned.
	 *
	 * @param array  $response     Decoded response body.
	 * @param string $cart_session Cart session token.
	 * @param string $user_id      Authenticated user ID, or empty string.
	 */
	return apply_filters( 'agend_apps_cart_checkout_complete_response', $response, $cart_session, $user_id );
}

/**
 * Cancels an in-progress checkout session, returning the cart to `current` status.
 *
 * Busts the cart cache after a successful call.
 *
 * @param string $cart_session Cart session token forwarded as `X-Cart-Session`.
 * @param string $user_id      Optional. Authenticated user ID forwarded as `X-User-ID`. Default empty string.
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_cart_checkout_cancel( string $cart_session, string $user_id = '' ) {
	/**
	 * Filters the cart checkout cancel request args before the request is sent.
	 *
	 * @param array  $args         Request args.
	 * @param string $cart_session Cart session token.
	 * @param string $user_id      Authenticated user ID, or empty string.
	 */
	$args = (array) apply_filters(
		'agend_apps_cart_checkout_cancel_args',
		array(
			'cart_session' => $cart_session,
			'user_id'      => $user_id,
		),
		$cart_session,
		$user_id
	);

	$response = agend_apps_api()->request( 'POST', '/cart/checkout/cancel', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$cache_key = agend_apps_cart_cache_key( $user_id, $cart_session );
	delete_transient( 'agend_apps_' . $cache_key );

	/**
	 * Filters the decoded cart checkout cancel response before it is returned.
	 *
	 * @param array  $response     Decoded response body.
	 * @param string $cart_session Cart session token.
	 * @param string $user_id      Authenticated user ID, or empty string.
	 */
	return apply_filters( 'agend_apps_cart_checkout_cancel_response', $response, $cart_session, $user_id );
}

/**
 * Clears all items from the cart.
 *
 * Clears any cached cart for the given identity after a successful clear.
 *
 * @param string $cart_session Cart session token forwarded as `X-Cart-Session`.
 * @param string $user_id      Optional. Authenticated user ID forwarded as `X-User-ID`. Default empty string.
 * @return array|WP_Error Empty array on success (204 No Content), or WP_Error on failure.
 */
function agend_apps_cart_clear( string $cart_session, string $user_id = '' ) {
	/**
	 * Filters the cart clear request args before the request is sent.
	 *
	 * @param array  $args         Request args.
	 * @param string $cart_session Cart session token.
	 * @param string $user_id      Authenticated user ID, or empty string.
	 */
	$args = (array) apply_filters(
		'agend_apps_cart_clear_args',
		array(
			'cart_session' => $cart_session,
			'user_id'      => $user_id,
		),
		$cart_session,
		$user_id
	);

	$response = agend_apps_api()->request( 'POST', '/cart/delete', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$cache_key = agend_apps_cart_cache_key( $user_id, $cart_session );
	delete_transient( 'agend_apps_' . $cache_key );

	/**
	 * Filters the decoded cart clear response before it is returned.
	 *
	 * @param array  $response     Decoded response body.
	 * @param string $cart_session Cart session token.
	 * @param string $user_id      Authenticated user ID, or empty string.
	 */
	return apply_filters( 'agend_apps_cart_clear_response', $response, $cart_session, $user_id );
}
