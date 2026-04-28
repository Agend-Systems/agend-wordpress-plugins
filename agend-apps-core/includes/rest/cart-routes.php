<?php
/**
 * REST routes for cart endpoints.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the cart REST routes.
 *
 * Called from `rest_api_init` via the main plugin bootstrap.
 */
function agend_apps_register_cart_routes(): void {
	$controller = new Agend_Apps_Cart_REST_Controller();
	$controller->register_routes();
}

/**
 * REST controller for cart endpoints.
 *
 * All cart routes require a valid WordPress nonce (`wp_rest`) and are open
 * to any logged-in or anonymous visitor. Identity is derived from the
 * `X-Cart-Session` request header and, when present, the current WP user ID.
 *
 * Exposes:
 * - `GET    /agend-apps/v1/cart`            — retrieve cart.
 * - `POST   /agend-apps/v1/cart/items`      — add item.
 * - `PUT    /agend-apps/v1/cart/items/{id}` — update item.
 * - `DELETE /agend-apps/v1/cart/items/{id}` — remove item.
 * - `DELETE /agend-apps/v1/cart`            — clear cart.
 */
class Agend_Apps_Cart_REST_Controller extends Agend_Apps_REST_Controller {

	/**
	 * Resource base for cart routes.
	 *
	 * @var string
	 */
	protected $rest_base = 'cart';

	/**
	 * Registers the REST routes for this controller.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_cart' ),
					'permission_callback' => array( $this, 'nonce_check' ),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/clear',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'clear_cart' ),
					'permission_callback' => array( $this, 'nonce_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/items',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'add_cart_item' ),
					'permission_callback' => array( $this, 'nonce_check' ),
					'args'                => $this->get_item_schema_args(),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/checkout',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'checkout_cart' ),
					'permission_callback' => array( $this, 'nonce_check' ),
					'args'                => array(
						'successUrl' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_url',
						),
						'cancelUrl'  => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_url',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/checkout/complete',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'checkout_complete' ),
					'permission_callback' => array( $this, 'nonce_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/checkout/cancel',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'checkout_cancel' ),
					'permission_callback' => array( $this, 'nonce_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/item/update',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_cart_item' ),
					'permission_callback' => array( $this, 'nonce_check' ),
					'args'                => array_merge(
						array(
							'itemId'   => array(
								'required'          => true,
								'type'              => 'string',
								'sanitize_callback' => 'sanitize_text_field',
							),
							'quantity' => array(
								'required'          => true,
								'type'              => 'integer',
								'minimum'           => 1,
								'maximum'           => 100,
								'sanitize_callback' => 'absint',
							),
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'remove_cart_item' ),
					'permission_callback' => array( $this, 'nonce_check' ),
					'args'                => array(
						'itemId' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/item/delete/(?P<item_id>[a-zA-Z0-9_-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'remove_cart_item' ),
					'permission_callback' => array( $this, 'nonce_check' ),
				),
			)
		);
	}

	/**
	 * Returns the shared argument schema for item write operations.
	 *
	 * @return array Argument schema array.
	 */
	private function get_item_schema_args(): array {
		return array(
			'productType' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'productId'   => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'quantity'    => array(
				'required'          => true,
				'type'              => 'integer',
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
			),
		);
	}

	/**
	 * Validates the WordPress REST nonce for the current request.
	 *
	 * @param WP_REST_Request $request Current request.
	 *
	 * @return bool True if the nonce is valid, false otherwise.
	 */
	public function nonce_check( WP_REST_Request $request ): bool {
		$nonce = $request->get_header( 'X-WP-Nonce' );

		return false !== wp_verify_nonce( $nonce, 'wp_rest' );
	}

	/**
	 * Returns the cart for the current identity.
	 *
	 * @param WP_REST_Request $request Current request.
	 *
	 * @return WP_REST_Response REST response.
	 */
	public function get_cart( WP_REST_Request $request ): WP_REST_Response {
		$identity = $this->get_identity( $request );
		$result   = agend_apps_cart_get( $identity['cart_session'], $identity['user_id'] );

		return $this->prepare_api_response( $result );
	}

	/**
	 * Extracts the cart identity headers from the request.
	 *
	 * @param WP_REST_Request $request Current request.
	 *
	 * @return array { cart_session: string, user_id: string }
	 */
	private function get_identity( WP_REST_Request $request ): array {
		$cart_session = (string) $request->get_header( 'X-Cart-Session' );
		$user_id      = 'a0000000-0000-0000-0000-0000000000da'; // This will have to be synced with the Supabase user post PoC.
//		$user_id      = '';

		return array(
			'cart_session' => $cart_session,
			'user_id'      => $user_id,
		);
	}

	/**
	 * Adds an item to the cart.
	 *
	 * @param WP_REST_Request $request Current request.
	 *
	 * @return WP_REST_Response REST response.
	 */
	public function add_cart_item( WP_REST_Request $request ): WP_REST_Response {
		$identity = $this->get_identity( $request );
		$item     = $request->get_json_params();
		$result   = agend_apps_cart_add_item( $item, $identity['cart_session'], $identity['user_id'] );

		return $this->prepare_api_response( $result );
	}

	/**
	 * Updates an existing item in the cart.
	 *
	 * @param WP_REST_Request $request Current request.
	 *
	 * @return WP_REST_Response REST response.
	 */
	public function update_cart_item( WP_REST_Request $request ): WP_REST_Response {
		$identity = $this->get_identity( $request );
		$item     = $request->get_json_params();
		$result   = agend_apps_cart_update_item( $item, $identity['cart_session'], $identity['user_id'] );

		return $this->prepare_api_response( $result );
	}

	/**
	 * Removes an item from the cart.
	 *
	 * @param WP_REST_Request $request Current request.
	 *
	 * @return WP_REST_Response REST response.
	 */
	public function remove_cart_item( WP_REST_Request $request ): WP_REST_Response {
		$identity = $this->get_identity( $request );
		$item_id  = $request->get_param( 'item_id' );
		$result   = agend_apps_cart_remove_item( $item_id, $identity['cart_session'], $identity['user_id'] );

		return $this->prepare_api_response( $result );
	}

	/**
	 * Clears all items from the cart.
	 *
	 * @param WP_REST_Request $request Current request.
	 *
	 * @return WP_REST_Response REST response.
	 */
	public function clear_cart( WP_REST_Request $request ): WP_REST_Response {
		$identity = $this->get_identity( $request );
		$result   = agend_apps_cart_clear( $identity['cart_session'], $identity['user_id'] );

		return $this->prepare_api_response( $result );
	}

	/**
	 * Initiates a checkout session for the current cart.
	 *
	 * @param WP_REST_Request $request Current request.
	 *
	 * @return WP_REST_Response REST response.
	 */
	public function checkout_cart( WP_REST_Request $request ): WP_REST_Response {
		$identity = $this->get_identity( $request );
		$urls     = array(
			'successUrl' => $request->get_param( 'successUrl' ),
			'cancelUrl'  => $request->get_param( 'cancelUrl' ),
		);
		$result   = agend_apps_cart_checkout( $urls, $identity['cart_session'], $identity['user_id'] );

		return $this->prepare_api_response( $result );
	}

	/**
	 * Completes an in-progress checkout session.
	 *
	 * @param WP_REST_Request $request Current request.
	 *
	 * @return WP_REST_Response REST response.
	 */
	public function checkout_complete( WP_REST_Request $request ): WP_REST_Response {
		$identity = $this->get_identity( $request );
		$result   = agend_apps_cart_checkout_complete( $identity['cart_session'], $identity['user_id'] );

		return $this->prepare_api_response( $result );
	}

	/**
	 * Cancels an in-progress checkout session.
	 *
	 * @param WP_REST_Request $request Current request.
	 *
	 * @return WP_REST_Response REST response.
	 */
	public function checkout_cancel( WP_REST_Request $request ): WP_REST_Response {
		$identity = $this->get_identity( $request );
		$result   = agend_apps_cart_checkout_cancel( $identity['cart_session'], $identity['user_id'] );

		return $this->prepare_api_response( $result );
	}
}
