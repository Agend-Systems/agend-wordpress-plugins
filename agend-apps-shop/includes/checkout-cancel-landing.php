<?php
/**
 * Unlocks the cart when the buyer comes back from the payment page without
 * paying.
 *
 * Starting a checkout locks the cart. The payment page's cancel (or Back)
 * link returns the buyer to the site's cancel URL, usually the cart page,
 * and nothing there used to tell the platform the checkout was abandoned:
 * the cart stayed locked until the lock expired, so "Proceed to Checkout"
 * straight after was refused. The cancel URL now carries a marker
 * (`agend_checkout=cancelled`), and the cart session script, which every cart
 * surface loads, asks `POST /cart/checkout/cancel` once when a page opens
 * with it. The call is best effort: a cart that is no longer locked, or was
 * paid meanwhile, is refused by the platform and left as it is.
 *
 * @package Agend_Apps_Shop
 */

defined( 'ABSPATH' ) || exit;

/** Query argument marking the payment page's cancel return. */
const AGEND_APPS_SHOP_CANCEL_MARKER = 'agend_checkout';

/**
 * Adds the cancel-return marker to a checkout's cancel URL when that URL is on
 * this site. An off-site cancel URL is left exactly as it is.
 *
 * @param array $args Cart checkout request args (`body.cancelUrl`).
 * @return array
 */
function agend_apps_shop_mark_cancel_url( array $args ): array {
	$cancel = $args['body']['cancelUrl'] ?? '';
	if ( ! is_string( $cancel ) || '' === $cancel ) {
		return $args;
	}

	$target = wp_parse_url( $cancel );
	$home   = wp_parse_url( home_url() );
	$local  = is_array( $target ) && is_array( $home )
		&& isset( $target['host'], $home['host'] )
		&& strtolower( $target['host'] ) === strtolower( $home['host'] )
		&& ! isset( $target['user'] ) && ! isset( $target['pass'] );
	if ( ! $local ) {
		return $args;
	}

	$args['body']['cancelUrl'] = add_query_arg( AGEND_APPS_SHOP_CANCEL_MARKER, 'cancelled', $cancel );
	return $args;
}

/** Registers the cancel-return marker on cart checkouts. */
function agend_apps_shop_register_checkout_cancel_landing(): void {
	add_filter( 'agend_apps_cart_checkout_args', 'agend_apps_shop_mark_cancel_url' );
}
