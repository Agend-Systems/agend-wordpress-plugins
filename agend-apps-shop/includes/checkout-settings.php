<?php
/**
 * Checkout settings shared by the cart view, its script data and the admin
 * page: guest checkout, Pay on Invoice, and where the cart's sign-in prompt
 * and invoice link go.
 *
 * @package Agend_Apps_Shop
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether guests may check out with a name and email.
 *
 * Mirrors Agend Apps Core's cart checkout route, which enforces the same
 * setting server-side, so the cart and the route always agree.
 *
 * @return bool
 */
function agend_apps_shop_guest_checkout_allowed(): bool {
	if ( class_exists( 'Agend_Apps_Cart_REST_Controller' ) && method_exists( 'Agend_Apps_Cart_REST_Controller', 'guest_checkout_allowed' ) ) {
		return Agend_Apps_Cart_REST_Controller::guest_checkout_allowed();
	}
	return 'allow' === get_option( 'agend_apps_shop_guest_checkout', 'require' );
}

/**
 * Whether the cart offers Pay on Invoice to signed-in members, subject to
 * the organisation's invoice policy.
 *
 * @return bool
 */
function agend_apps_shop_pay_on_invoice_enabled(): bool {
	if ( class_exists( 'Agend_Apps_Cart_REST_Controller' ) && method_exists( 'Agend_Apps_Cart_REST_Controller', 'invoice_checkout_enabled' ) ) {
		return Agend_Apps_Cart_REST_Controller::invoice_checkout_enabled();
	}
	return '0' !== (string) get_option( 'agend_apps_shop_pay_on_invoice', '1' );
}

/**
 * The connected account's member portal home, for the link to an invoice
 * after a Pay on Invoice order. Empty when no account slug is configured,
 * so the cart never links to the generic portal.
 *
 * @return string
 */
function agend_apps_shop_portal_home_url(): string {
	if ( ! class_exists( 'Agend_Apps_Settings' ) || ! method_exists( 'Agend_Apps_Settings', 'get_portal_home_url' ) || ! method_exists( 'Agend_Apps_Settings', 'get_account_slug' ) ) {
		return '';
	}
	if ( '' === Agend_Apps_Settings::get_account_slug() ) {
		return '';
	}
	return esc_url_raw( Agend_Apps_Settings::get_portal_home_url() );
}

/**
 * Where the cart's sign-in prompt sends a visitor.
 *
 * In credentials mode with an event checkout sign-in page configured in
 * Agend Identity, that page (the member login widget returns to the cart
 * after sign-in). Otherwise the site's login URL with the cart as the
 * return address, which every SSO and login plugin already filters.
 *
 * @param string $return_url The cart page to come back to.
 * @return string
 */
function agend_apps_shop_cart_sign_in_url( string $return_url ): string {
	$page = function_exists( 'agend_apps_records_events_checkout_signin_url' ) ? agend_apps_records_events_checkout_signin_url() : '';
	if ( '' !== $page ) {
		return add_query_arg( 'agend_cart_signin', '1', $page );
	}
	return wp_login_url( $return_url );
}
