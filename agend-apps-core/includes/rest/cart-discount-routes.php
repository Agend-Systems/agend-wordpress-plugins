<?php
/**
 * Discount codes on the cart.
 *
 * Proxies the gateway's `POST /cart/discount` (apply a code) and
 * `DELETE /cart/discount` (remove it) for the cart surfaces:
 *
 * - `POST   /agend-apps/v1/cart/discount` with `{ discount_code, guest_buyer? }`
 * - `DELETE /agend-apps/v1/cart/discount`
 *
 * The platform keeps the applied code on the cart and answers with a discount
 * quote (`code`, `applies`, `refusal_message`, `version`,
 * `undiscounted_total_cents`, `discount_cents`, `subtotal_cents`, `tax_cents`,
 * `total_cents`, `show_gst`, `lines`). `GET /cart` returns the same quote while
 * a code is applied, and both card checkout and Pay on Invoice charge the
 * discounted amount from the stored code. A refused code is a 400
 * `INVALID_DISCOUNT_CODE` with a message written for the buyer.
 *
 * Identity follows the other cart routes: the guest cart session from the
 * `X-Cart-Session` header and the member bearer when there is one. A guest
 * must name the buyer, as the platform requires.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies a discount code to the current cart.
 *
 * Scope: `cart.items.manage`.
 *
 * @param string     $code         Discount code as typed.
 * @param string     $cart_session Guest cart session token.
 * @param string     $bearer_token Member bearer token, or ''.
 * @param array|null $guest_buyer  `{ name, email }` for a guest, else null.
 * @return array|WP_Error Decoded quote response, or WP_Error.
 */
function agend_apps_cart_apply_discount( string $code, string $cart_session, string $bearer_token = '', ?array $guest_buyer = null ) {
	$body = array( 'discount_code' => $code );
	if ( '' === $bearer_token && is_array( $guest_buyer ) ) {
		$body['guest_buyer'] = $guest_buyer;
	}

	/**
	 * Filters the apply-discount request args before the request is sent.
	 *
	 * @param array  $args         Request args.
	 * @param string $cart_session Cart session token.
	 * @param string $bearer_token Bearer token, or ''.
	 */
	$args = (array) apply_filters(
		'agend_apps_cart_apply_discount_args',
		array(
			'body'         => $body,
			'cart_session' => $cart_session,
			'bearer_token' => $bearer_token,
		),
		$cart_session,
		$bearer_token
	);

	$response = agend_apps_api()->request( 'POST', '/cart/discount', $args );
	agend_apps_cart_discount_forget_cart( $cart_session, $bearer_token );

	return $response;
}

/**
 * Removes the discount code from the current cart.
 *
 * Scope: `cart.items.manage`.
 *
 * @param string $cart_session Guest cart session token.
 * @param string $bearer_token Member bearer token, or ''.
 * @return array|WP_Error Decoded quote response, or WP_Error.
 */
function agend_apps_cart_remove_discount( string $cart_session, string $bearer_token = '' ) {
	$response = agend_apps_api()->request(
		'DELETE',
		'/cart/discount',
		array(
			'cart_session' => $cart_session,
			'bearer_token' => $bearer_token,
		)
	);
	agend_apps_cart_discount_forget_cart( $cart_session, $bearer_token );

	return $response;
}

/**
 * Drops the cached cart, whose totals the discount has changed.
 *
 * @param string $cart_session Guest cart session token.
 * @param string $bearer_token Member bearer token, or ''.
 */
function agend_apps_cart_discount_forget_cart( string $cart_session, string $bearer_token ): void {
	if ( function_exists( 'agend_apps_cart_cache_key' ) ) {
		delete_transient( 'agend_apps_' . agend_apps_cart_cache_key( $bearer_token, $cart_session ) );
	}
}

/**
 * REST controller for the cart discount routes.
 */
class Agend_Apps_Cart_Discount_REST_Controller extends Agend_Apps_REST_Controller {

	/**
	 * Resource base.
	 *
	 * @var string
	 */
	protected $rest_base = 'cart/discount';

	/**
	 * Registers the routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'apply' ),
					'permission_callback' => array( $this, 'nonce_check' ),
					'args'                => array(
						'discount_code' => array(
							'type'     => 'string',
							'required' => true,
						),
						'guest_buyer'   => array(
							'type' => 'object',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'remove' ),
					'permission_callback' => array( $this, 'nonce_check' ),
				),
			)
		);
	}

	/**
	 * Same check as the other cart routes: a valid REST nonce.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return bool
	 */
	public function nonce_check( WP_REST_Request $request ): bool {
		return false !== wp_verify_nonce( $request->get_header( 'X-WP-Nonce' ), 'wp_rest' );
	}

	/**
	 * Applies a code.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response
	 */
	public function apply( WP_REST_Request $request ): WP_REST_Response {
		$code = trim( sanitize_text_field( (string) $request->get_param( 'discount_code' ) ) );
		if ( '' === $code || strlen( $code ) > 100 ) {
			return new WP_REST_Response(
				array(
					'code'    => 'agend_apps_invalid_discount_code',
					'message' => __( 'Enter a discount code.', 'agend-apps-core' ),
					'data'    => array( 'status_code' => 400 ),
				),
				400
			);
		}

		$guest = $request->get_param( 'guest_buyer' );
		$buyer = null;
		if ( is_array( $guest ) ) {
			$name  = trim( sanitize_text_field( (string) ( $guest['name'] ?? '' ) ) );
			$email = sanitize_email( (string) ( $guest['email'] ?? '' ) );
			if ( '' !== $name && '' !== $email ) {
				$buyer = array(
					'name'  => $name,
					'email' => $email,
				);
			}
		}

		$result = agend_apps_cart_apply_discount(
			$code,
			(string) $request->get_header( 'X-Cart-Session' ),
			agend_apps_get_bearer_token(),
			$buyer
		);

		return $this->prepare_api_response( $result );
	}

	/**
	 * Removes the code.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response
	 */
	public function remove( WP_REST_Request $request ): WP_REST_Response {
		$result = agend_apps_cart_remove_discount(
			(string) $request->get_header( 'X-Cart-Session' ),
			agend_apps_get_bearer_token()
		);

		return $this->prepare_api_response( $result );
	}
}

/**
 * Registers the cart discount routes.
 */
function agend_apps_register_cart_discount_routes(): void {
	( new Agend_Apps_Cart_Discount_REST_Controller() )->register_routes();
}
