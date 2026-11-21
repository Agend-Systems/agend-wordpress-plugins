<?php
/**
 * Unlocks the cart when the buyer comes back from the payment page without
 * paying.
 *
 * Starting a checkout locks the cart. The payment page's cancel (or Back)
 * link returns the buyer to the site's cancel URL, usually the cart page,
 * and nothing there used to tell the platform the checkout was abandoned:
 * the cart stayed locked until the lock expired, so "Proceed to Checkout"
 * straight after was refused.
 *
 * Each checkout's cancel URL now carries a single-use return token
 * (`agend_checkout=cancelled&agend_cancel=<token>`). Only a hash of the token
 * is kept, bound to the buyer (member or guest cart) and replaced by their
 * next checkout. The cart session script, which every cart surface loads,
 * posts the token to `POST /agend-apps/v1/cart/checkout/return-cancel`, which
 * cancels the checkout through Core only when the token is this buyer's
 * latest one, then forgets it. Every new checkout revokes the earlier token,
 * as soon as it starts, and a token is claimed atomically, so a crafted link,
 * an old cancel-return address opened after a newer checkout started, or a
 * replay cancels nothing. The one narrower case left, a newer checkout
 * started in the same instant a return is cancelling, is refused by the
 * platform: a new checkout cannot start while the earlier one holds the
 * cart's lock, and its cancel will not unlock a cart a newer session holds. The call is
 * best effort: a cart that is no longer locked, or was paid meanwhile, is
 * refused by the platform and left as it is, quietly.
 *
 * @package Agend_Apps_Shop
 */

defined( 'ABSPATH' ) || exit;

/** Query argument marking the payment page's cancel return. */
const AGEND_APPS_SHOP_CANCEL_MARKER = 'agend_checkout';

/** Query argument carrying the return token. */
const AGEND_APPS_SHOP_CANCEL_TOKEN = 'agend_cancel';

/** How long a return token is honoured: the platform's checkout lock. */
const AGEND_APPS_SHOP_CANCEL_TTL = 45 * MINUTE_IN_SECONDS;

/**
 * Who is checking out, as a storage key part: the signed-in member, else the
 * guest cart. '' when there is neither.
 *
 * @param string $cart   Guest cart session token.
 * @param string $bearer Member bearer token, or ''.
 * @return string
 */
function agend_apps_shop_cancel_buyer( string $cart, string $bearer ): string {
	if ( '' !== $bearer && get_current_user_id() > 0 ) {
		return 'member:' . get_current_user_id();
	}
	return '' !== $cart && strlen( $cart ) <= 512 ? 'cart:' . $cart : '';
}

/**
 * The transient holding the buyer's latest return token hash.
 *
 * @param string $buyer Buyer key.
 * @return string
 */
function agend_apps_shop_cancel_key( string $buyer ): string {
	return 'agend_shop_cancel_' . hash_hmac( 'sha256', $buyer, wp_salt( 'auth' ) );
}

/**
 * The stored form of a token: never the token itself.
 *
 * @param string $token Return token.
 * @return string
 */
function agend_apps_shop_cancel_hash( string $token ): string {
	return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
}

/**
 * Whether a URL is on this site.
 *
 * @param string $url Address.
 * @return bool
 */
function agend_apps_shop_cancel_url_is_local( string $url ): bool {
	$target = wp_parse_url( $url );
	$home   = wp_parse_url( home_url() );
	return is_array( $target ) && is_array( $home )
		&& isset( $target['host'], $home['host'] )
		&& strtolower( $target['host'] ) === strtolower( $home['host'] )
		&& ! isset( $target['user'] ) && ! isset( $target['pass'] );
}

/** Registers the cancel-return token on cart checkouts, and its route. */
function agend_apps_shop_register_checkout_cancel_landing(): void {
	$token = '';

	// A same-site cancel URL gets the marker and a fresh token. An off-site
	// cancel URL is left exactly as it is.
	add_filter(
		'agend_apps_cart_checkout_args',
		static function ( array $args, $urls = array(), $cart = '', $bearer = '' ) use ( &$token ): array {
			$token = '';
			// Starting a checkout revokes the buyer's earlier return token at
			// once, before the platform is asked, so a return still in flight
			// for the earlier checkout finds nothing to spend.
			$buyer = agend_apps_shop_cancel_buyer( is_string( $cart ) ? $cart : '', is_string( $bearer ) ? $bearer : '' );
			if ( '' !== $buyer ) {
				delete_transient( agend_apps_shop_cancel_key( $buyer ) );
			}
			$cancel = $args['body']['cancelUrl'] ?? '';
			if ( ! is_string( $cancel ) || '' === $cancel || ! agend_apps_shop_cancel_url_is_local( $cancel ) ) {
				return $args;
			}
			$token = wp_generate_password( 32, false, false );
			$args['body']['cancelUrl'] = add_query_arg(
				array(
					AGEND_APPS_SHOP_CANCEL_MARKER => 'cancelled',
					AGEND_APPS_SHOP_CANCEL_TOKEN  => $token,
				),
				$cancel
			);
			return $args;
		},
		10,
		4
	);

	// Every checkout the buyer starts revokes their earlier token, so only the
	// latest checkout's return can cancel. A checkout with a same-site cancel
	// URL then keeps its own token; one with an off-site cancel URL keeps none.
	add_filter(
		'agend_apps_cart_checkout_response',
		static function ( array $response, string $cart, string $bearer = '' ) use ( &$token ): array {
			$buyer = agend_apps_shop_cancel_buyer( $cart, $bearer );
			if ( '' !== $buyer ) {
				if ( '' !== $token ) {
					set_transient( agend_apps_shop_cancel_key( $buyer ), agend_apps_shop_cancel_hash( $token ), AGEND_APPS_SHOP_CANCEL_TTL );
				} else {
					delete_transient( agend_apps_shop_cancel_key( $buyer ) );
				}
			}
			$token = '';
			return $response;
		},
		10,
		3
	);

	add_action(
		'rest_api_init',
		static function (): void {
			register_rest_route(
				'agend-apps/v1',
				'/cart/checkout/return-cancel',
				array(
					'methods'             => 'POST',
					'callback'            => 'agend_apps_shop_cancel_on_return',
					'permission_callback' => '__return_true',
					'args'                => array(
						'token' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				)
			);
		}
	);
}

/**
 * Cancels the buyer's abandoned checkout when the return token is their
 * latest one. Always answers 200 with `cancelled` true or false: there is
 * nothing a visitor needs to be told either way.
 *
 * @param WP_REST_Request $request Current request.
 * @return WP_REST_Response
 */
function agend_apps_shop_cancel_on_return( WP_REST_Request $request ): WP_REST_Response {
	$token  = (string) $request->get_param( 'token' );
	$cart   = (string) $request->get_header( 'X-Cart-Session' );
	$bearer = function_exists( 'agend_apps_get_bearer_token' ) ? agend_apps_get_bearer_token() : '';
	$buyer  = agend_apps_shop_cancel_buyer( $cart, $bearer );
	$stored = '' !== $buyer ? get_transient( agend_apps_shop_cancel_key( $buyer ) ) : false;

	if ( '' === $token || strlen( $token ) > 64 || ! is_string( $stored ) || ! hash_equals( $stored, agend_apps_shop_cancel_hash( $token ) ) ) {
		return new WP_REST_Response( array( 'cancelled' => false ), 200 );
	}

	// Single use, even for requests sent at the same moment: the token is
	// claimed by inserting a row keyed to it. The options table's unique key
	// lets exactly one insert succeed; every other request is turned away.
	$hash  = agend_apps_shop_cancel_hash( $token );
	$claim = 'agend_shop_cancel_claim_' . substr( $hash, 0, 40 );
	if ( ! add_option( $claim, '1', '', false ) ) {
		return new WP_REST_Response( array( 'cancelled' => false ), 200 );
	}

	// With the claim held, the token must still be there: a request that ran
	// first has spent it, and a newer checkout's token is never touched.
	$current = get_transient( agend_apps_shop_cancel_key( $buyer ) );
	if ( ! is_string( $current ) || ! hash_equals( $current, $hash ) ) {
		delete_option( $claim );
		return new WP_REST_Response( array( 'cancelled' => false ), 200 );
	}
	delete_transient( agend_apps_shop_cancel_key( $buyer ) );

	$result = function_exists( 'agend_apps_cart_checkout_cancel' ) ? agend_apps_cart_checkout_cancel( $cart, $bearer ) : null;

	// The token is gone, so the claim row has done its job.
	delete_option( $claim );

	return new WP_REST_Response( array( 'cancelled' => is_array( $result ) ), 200 );
}
