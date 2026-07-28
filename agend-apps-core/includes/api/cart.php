<?php
/**
 * Cart API functions.
 *
 * Server-side PHP wrappers for the Agend gateway's `/v1/cart/*` endpoints.
 *
 * Identity model: a logged-in member is identified by a Supabase bearer token
 * (a verified JWT) attached to the request by the shared client; a guest is
 * identified by an opaque `X-Cart-Session` token. The legacy `X-User-ID`
 * assertion header was removed from the gateway and is no longer sent. Each
 * cart function therefore takes an optional `$bearer_token`; when empty the
 * shared resolver (`agend_apps_get_bearer_token()`) is consulted and, failing
 * that, the request proceeds as a guest keyed on the cart session.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds a scoped cache key for a cart request.
 *
 * Member requests (a bearer token is present) are keyed on a hash of the
 * token; guest requests are keyed on the cart session token. This prevents one
 * identity from reading another identity's cached cart data. The token is
 * hashed rather than stored verbatim so the raw JWT never lands in an option
 * name.
 *
 * @param string $bearer_token Supabase bearer token, or empty string for a guest.
 * @param string $cart_session Guest cart session token.
 * @return string Cache key prefixed with the endpoint key.
 */
function agend_apps_cart_cache_key( string $bearer_token, string $cart_session ): string {
	if ( '' !== $bearer_token ) {
		return Agend_Apps_Cache::build_key( 'cart_get', array( 'token' => md5( $bearer_token ) ) );
	}

	return Agend_Apps_Cache::build_key( 'cart_get_anonymous', array( 'cart_session' => $cart_session ) );
}

/**
 * Retrieves the current cart for a given identity.
 *
 * Member carts are cached under the `cart_get` TTL; guest carts are cached
 * under `cart_get_anonymous`.
 *
 * @param string $cart_session Cart session token forwarded as `X-Cart-Session`.
 * @param string $bearer_token Optional. Supabase bearer token forwarded as `Authorization: Bearer`. Default empty string.
 * @return array|WP_Error Decoded cart array on success, or WP_Error on failure.
 */
function agend_apps_cart_get( string $cart_session, string $bearer_token = '' ) {
	/**
	 * Filters the cart get request args before the request is sent.
	 *
	 * @param array  $args         Request args.
	 * @param string $cart_session Cart session token.
	 * @param string $bearer_token Supabase bearer token, or empty string.
	 */
	$args = (array) apply_filters(
		'agend_apps_cart_get_args',
		array(
			'cart_session' => $cart_session,
			'bearer_token' => $bearer_token,
		),
		$cart_session,
		$bearer_token
	);

	$endpoint_key = '' !== $bearer_token ? 'cart_get' : 'cart_get_anonymous';
	$cache_key    = agend_apps_cart_cache_key( $bearer_token, $cart_session );
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
	 * @param string $bearer_token Supabase bearer token, or empty string.
	 */
	return apply_filters( 'agend_apps_cart_get_response', $response, $cart_session, $bearer_token );
}

/**
 * Adds an item to the cart.
 *
 * Clears any cached cart for the given identity after a successful add.
 *
 * @param array  $item         Item payload forwarded as the request body.
 * @param string $cart_session Cart session token forwarded as `X-Cart-Session`.
 * @param string $bearer_token Optional. Supabase bearer token forwarded as `Authorization: Bearer`. Default empty string.
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_cart_add_item( array $item, string $cart_session, string $bearer_token = '' ) {
	/**
	 * Filters the cart add-item request args before the request is sent.
	 *
	 * @param array  $args         Request args.
	 * @param array  $item         Item payload.
	 * @param string $cart_session Cart session token.
	 * @param string $bearer_token Supabase bearer token, or empty string.
	 */
	$args = (array) apply_filters(
		'agend_apps_cart_add_item_args',
		array(
			'body'         => $item,
			'cart_session' => $cart_session,
			'bearer_token' => $bearer_token,
		),
		$item,
		$cart_session,
		$bearer_token
	);

	$response = agend_apps_api()->request( 'POST', '/cart/items', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$cache_key = agend_apps_cart_cache_key( $bearer_token, $cart_session );
	delete_transient( 'agend_apps_' . $cache_key );

	/**
	 * Filters the decoded cart add-item response before it is returned.
	 *
	 * @param array  $response     Decoded response body.
	 * @param string $cart_session Cart session token.
	 * @param string $bearer_token Supabase bearer token, or empty string.
	 */
	return apply_filters( 'agend_apps_cart_add_item_response', $response, $cart_session, $bearer_token );
}

/**
 * Updates an existing item in the cart.
 *
 * Clears any cached cart for the given identity after a successful update.
 *
 * @param array  $item         Updated item payload forwarded as the request body.
 * @param string $cart_session Cart session token forwarded as `X-Cart-Session`.
 * @param string $bearer_token Optional. Supabase bearer token forwarded as `Authorization: Bearer`. Default empty string.
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_cart_update_item( array $item, string $cart_session, string $bearer_token = '' ) {
	/**
	 * Filters the cart update-item request args before the request is sent.
	 *
	 * @param array  $args         Request args.
	 * @param array  $item         Updated item payload.
	 * @param string $cart_session Cart session token.
	 * @param string $bearer_token Supabase bearer token, or empty string.
	 */
	$args = (array) apply_filters(
		'agend_apps_cart_update_item_args',
		array(
			'body'         => $item,
			'cart_session' => $cart_session,
			'bearer_token' => $bearer_token,
		),
		$item,
		$cart_session,
		$bearer_token
	);

	$response = agend_apps_api()->request( 'POST', '/cart/items/update', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$cache_key = agend_apps_cart_cache_key( $bearer_token, $cart_session );
	delete_transient( 'agend_apps_' . $cache_key );

	/**
	 * Filters the decoded cart update-item response before it is returned.
	 *
	 * @param array  $response     Decoded response body.
	 * @param string $item_id      Item ID.
	 * @param string $cart_session Cart session token.
	 * @param string $bearer_token Supabase bearer token, or empty string.
	 */
	return apply_filters( 'agend_apps_cart_update_item_response', $response, $item['itemId'], $cart_session, $bearer_token );
}

/**
 * Sets (replaces) the attendee assignments for a cart item.
 *
 * Forwards the full attendee array for a line to the gateway
 * `POST /v1/cart/items/attendees` endpoint. The supplied array is the
 * authoritative set for the line; the gateway validates each attendee against
 * the ticket's event attendee-field definitions. Clears any cached cart for
 * the given identity after a successful call.
 *
 * @param array  $payload      Attendee payload forwarded as the request body. Expects `itemId` (string) and `attendees` (array).
 * @param string $cart_session Cart session token forwarded as `X-Cart-Session`.
 * @param string $bearer_token Optional. Supabase bearer token forwarded as `Authorization: Bearer`. Default empty string.
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_cart_set_item_attendees( array $payload, string $cart_session, string $bearer_token = '' ) {
	/**
	 * Filters the cart set-attendees request args before the request is sent.
	 *
	 * @param array  $args         Request args.
	 * @param array  $payload      Attendee payload.
	 * @param string $cart_session Cart session token.
	 * @param string $bearer_token Supabase bearer token, or empty string.
	 */
	$args = (array) apply_filters(
		'agend_apps_cart_set_item_attendees_args',
		array(
			'body'         => $payload,
			'cart_session' => $cart_session,
			'bearer_token' => $bearer_token,
		),
		$payload,
		$cart_session,
		$bearer_token
	);

	$response = agend_apps_api()->request( 'POST', '/cart/items/attendees', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$cache_key = agend_apps_cart_cache_key( $bearer_token, $cart_session );
	delete_transient( 'agend_apps_' . $cache_key );

	$item_id = isset( $payload['itemId'] ) ? (string) $payload['itemId'] : '';

	/**
	 * Filters the decoded cart set-attendees response before it is returned.
	 *
	 * @param array  $response     Decoded response body.
	 * @param string $item_id      Item ID.
	 * @param string $cart_session Cart session token.
	 * @param string $bearer_token Supabase bearer token, or empty string.
	 */
	return apply_filters( 'agend_apps_cart_set_item_attendees_response', $response, $item_id, $cart_session, $bearer_token );
}

/**
 * Removes an item from the cart.
 *
 * Clears any cached cart for the given identity after a successful removal.
 *
 * @param string $item_id      Passed Item ID to be removed from the cart.
 * @param string $cart_session Cart session token forwarded as `X-Cart-Session`.
 * @param string $bearer_token Optional. Supabase bearer token forwarded as `Authorization: Bearer`. Default empty string.
 *
 * @return array|WP_Error Decoded `{ status: 'removed' }` on success, or WP_Error on failure.
 */
function agend_apps_cart_remove_item( string $item_id, string $cart_session, string $bearer_token = '' ) {
	/**
	 * Filters the cart remove-item request args before the request is sent.
	 *
	 * @param array  $args         Request args.
	 * @param string $cart_session Cart session token.
	 * @param string $bearer_token Supabase bearer token, or empty string.
	 */
	$args = (array) apply_filters(
		'agend_apps_cart_remove_item_args',
		array(
			'body'         => array(
				'itemId' => $item_id,
			),
			'cart_session' => $cart_session,
			'bearer_token' => $bearer_token,
		),
		$cart_session,
		$bearer_token
	);

	$response = agend_apps_api()->request( 'POST', '/cart/items/remove', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$cache_key = agend_apps_cart_cache_key( $bearer_token, $cart_session );
	delete_transient( 'agend_apps_' . $cache_key );

	/**
	 * Filters the decoded cart remove-item response before it is returned.
	 *
	 * @param array  $response     Decoded response body.
	 * @param string $item_id      Item ID.
	 * @param string $cart_session Cart session token.
	 * @param string $bearer_token Supabase bearer token, or empty string.
	 */
	return apply_filters( 'agend_apps_cart_remove_item_response', $response, $item_id, $cart_session, $bearer_token );
}

/**
 * Initiates a checkout session for the current cart.
 *
 * Locks the cart status to `locked` on success. The cart cache is busted
 * after a successful call. Does not cache the response.
 *
 * @param array  $urls         Array containing `successUrl` (string) and `cancelUrl` (string).
 * @param string $cart_session Cart session token forwarded as `X-Cart-Session`.
 * @param string $bearer_token Optional. Supabase bearer token forwarded as `Authorization: Bearer`. Default empty string.
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_cart_checkout( array $urls, string $cart_session, string $bearer_token = '' ) {
	/**
	 * Filters the cart checkout request args before the request is sent.
	 *
	 * @param array  $args         Request args.
	 * @param array  $urls         Checkout URL payload.
	 * @param string $cart_session Cart session token.
	 * @param string $bearer_token Supabase bearer token, or empty string.
	 */
	$args = (array) apply_filters(
		'agend_apps_cart_checkout_args',
		array(
			'body'         => $urls,
			'cart_session' => $cart_session,
			'bearer_token' => $bearer_token,
		),
		$urls,
		$cart_session,
		$bearer_token
	);

	$response = agend_apps_api()->request( 'POST', '/cart/checkout', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$cache_key = agend_apps_cart_cache_key( $bearer_token, $cart_session );
	delete_transient( 'agend_apps_' . $cache_key );

	/**
	 * Filters the decoded cart checkout response before it is returned.
	 *
	 * @param array  $response     Decoded response body.
	 * @param string $cart_session Cart session token.
	 * @param string $bearer_token Supabase bearer token, or empty string.
	 */
	return apply_filters( 'agend_apps_cart_checkout_response', $response, $cart_session, $bearer_token );
}

/**
 * Completes an in-progress checkout session.
 *
 * Busts the cart cache after a successful call.
 *
 * @param string $cart_session Cart session token forwarded as `X-Cart-Session`.
 * @param string $bearer_token Optional. Supabase bearer token forwarded as `Authorization: Bearer`. Default empty string.
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_cart_checkout_complete( string $cart_session, string $bearer_token = '' ) {
	/**
	 * Filters the cart checkout complete request args before the request is sent.
	 *
	 * @param array  $args         Request args.
	 * @param string $cart_session Cart session token.
	 * @param string $bearer_token Supabase bearer token, or empty string.
	 */
	$args = (array) apply_filters(
		'agend_apps_cart_checkout_complete_args',
		array(
			'cart_session' => $cart_session,
			'bearer_token' => $bearer_token,
		),
		$cart_session,
		$bearer_token
	);

	$response = agend_apps_api()->request( 'POST', '/cart/checkout/complete', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$cache_key = agend_apps_cart_cache_key( $bearer_token, $cart_session );
	delete_transient( 'agend_apps_' . $cache_key );

	/**
	 * Filters the decoded cart checkout complete response before it is returned.
	 *
	 * @param array  $response     Decoded response body.
	 * @param string $cart_session Cart session token.
	 * @param string $bearer_token Supabase bearer token, or empty string.
	 */
	return apply_filters( 'agend_apps_cart_checkout_complete_response', $response, $cart_session, $bearer_token );
}

/**
 * Cancels an in-progress checkout session, returning the cart to `current` status.
 *
 * Busts the cart cache after a successful call.
 *
 * @param string $cart_session Cart session token forwarded as `X-Cart-Session`.
 * @param string $bearer_token Optional. Supabase bearer token forwarded as `Authorization: Bearer`. Default empty string.
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_cart_checkout_cancel( string $cart_session, string $bearer_token = '' ) {
	/**
	 * Filters the cart checkout cancel request args before the request is sent.
	 *
	 * @param array  $args         Request args.
	 * @param string $cart_session Cart session token.
	 * @param string $bearer_token Supabase bearer token, or empty string.
	 */
	$args = (array) apply_filters(
		'agend_apps_cart_checkout_cancel_args',
		array(
			'cart_session' => $cart_session,
			'bearer_token' => $bearer_token,
		),
		$cart_session,
		$bearer_token
	);

	$response = agend_apps_api()->request( 'POST', '/cart/checkout/cancel', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$cache_key = agend_apps_cart_cache_key( $bearer_token, $cart_session );
	delete_transient( 'agend_apps_' . $cache_key );

	/**
	 * Filters the decoded cart checkout cancel response before it is returned.
	 *
	 * @param array  $response     Decoded response body.
	 * @param string $cart_session Cart session token.
	 * @param string $bearer_token Supabase bearer token, or empty string.
	 */
	return apply_filters( 'agend_apps_cart_checkout_cancel_response', $response, $cart_session, $bearer_token );
}

/**
 * Clears all items from the cart.
 *
 * Clears any cached cart for the given identity after a successful clear.
 *
 * @param string $cart_session Cart session token forwarded as `X-Cart-Session`.
 * @param string $bearer_token Optional. Supabase bearer token forwarded as `Authorization: Bearer`. Default empty string.
 * @return array|WP_Error Decoded `{ status: 'deleted' }` on success, or WP_Error on failure.
 */
function agend_apps_cart_clear( string $cart_session, string $bearer_token = '' ) {
	/**
	 * Filters the cart clear request args before the request is sent.
	 *
	 * @param array  $args         Request args.
	 * @param string $cart_session Cart session token.
	 * @param string $bearer_token Supabase bearer token, or empty string.
	 */
	$args = (array) apply_filters(
		'agend_apps_cart_clear_args',
		array(
			'cart_session' => $cart_session,
			'bearer_token' => $bearer_token,
		),
		$cart_session,
		$bearer_token
	);

	$response = agend_apps_api()->request( 'POST', '/cart/delete', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$cache_key = agend_apps_cart_cache_key( $bearer_token, $cart_session );
	delete_transient( 'agend_apps_' . $cache_key );

	/**
	 * Filters the decoded cart clear response before it is returned.
	 *
	 * @param array  $response     Decoded response body.
	 * @param string $cart_session Cart session token.
	 * @param string $bearer_token Supabase bearer token, or empty string.
	 */
	return apply_filters( 'agend_apps_cart_clear_response', $response, $cart_session, $bearer_token );
}

/**
 * Transfers a guest cart onto the authenticated member's account.
 *
 * Called after a guest signs in: the guest cart identified by `$cart_session`
 * is merged onto the member identified by the bearer token. Both caches are
 * busted after a successful transfer.
 *
 * @param string $cart_session Guest cart session token forwarded as `X-Cart-Session`.
 * @param string $bearer_token Supabase bearer token forwarded as `Authorization: Bearer`.
 * @return array|WP_Error Decoded transferred cart on success, or WP_Error on failure.
 */
function agend_apps_cart_transfer( string $cart_session, string $bearer_token ) {
	/**
	 * Filters the cart transfer request args before the request is sent.
	 *
	 * @param array  $args         Request args.
	 * @param string $cart_session Guest cart session token.
	 * @param string $bearer_token Supabase bearer token.
	 */
	$args = (array) apply_filters(
		'agend_apps_cart_transfer_args',
		array(
			// The gateway resolves the destination member from the bearer token
			// and reads the guest cart to transfer from the body. X-Cart-Session
			// is deliberately NOT sent: it would resolve a guest identity and the
			// route requires an authenticated contact.
			'body'         => array( 'guestSessionToken' => $cart_session ),
			'bearer_token' => $bearer_token,
		),
		$cart_session,
		$bearer_token
	);

	$response = agend_apps_api()->request( 'POST', '/cart/transfer', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	// Bust both the guest cache and the member cache so the next read is fresh.
	delete_transient( 'agend_apps_' . agend_apps_cart_cache_key( '', $cart_session ) );
	delete_transient( 'agend_apps_' . agend_apps_cart_cache_key( $bearer_token, $cart_session ) );

	/**
	 * Filters the decoded cart transfer response before it is returned.
	 *
	 * @param array  $response     Decoded response body.
	 * @param string $cart_session Guest cart session token.
	 * @param string $bearer_token Supabase bearer token.
	 */
	return apply_filters( 'agend_apps_cart_transfer_response', $response, $cart_session, $bearer_token );
}
