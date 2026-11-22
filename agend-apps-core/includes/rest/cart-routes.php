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
 * `X-Cart-Session` request header (guest) and, when available, the Supabase
 * bearer token for the logged-in member resolved via
 * `agend_apps_get_bearer_token()`.
 *
 * Exposes:
 * - `GET    /agend-apps/v1/cart`            — retrieve cart.
 * - `POST   /agend-apps/v1/cart/items`      — add item.
 * - `POST   /agend-apps/v1/cart/items/attendees` — set item attendees.
 * - `PUT    /agend-apps/v1/cart/items/{id}` — update item.
 * - `DELETE /agend-apps/v1/cart/items/{id}` — remove item.
 * - `DELETE /agend-apps/v1/cart`            — clear cart.
 * - `POST   /agend-apps/v1/cart/transfer`   — merge a guest cart onto a member.
 * - `GET    /agend-apps/v1/cart/checkout-options` — payment options (member only).
 * - `POST   /agend-apps/v1/cart/checkout/invoice` — place the order on invoice (member only).
 *
 * Checkout policy follows two Agend Apps Shop settings, read here so the
 * rule holds whatever calls the route: `agend_apps_shop_guest_checkout`
 * (`require`, the default, refuses checkout without a member bearer; `allow`
 * lets a guest pay with a name and email) and `agend_apps_shop_pay_on_invoice`
 * (`1`, the default, offers Pay on Invoice when the organisation's invoice
 * policy allows it; `0` hides and refuses it).
 */
class Agend_Apps_Cart_REST_Controller extends Agend_Apps_REST_Controller {

	/**
	 * Resource base for cart routes.
	 *
	 * @var string
	 */
	protected $rest_base = 'cart';

	/**
	 * Longest purchase order number the gateway accepts
	 * (`PURCHASE_ORDER_NUMBER_MAX_LENGTH`).
	 *
	 * @var int
	 */
	const PURCHASE_ORDER_MAX_LENGTH = 100;

	/**
	 * Whether the site offers Pay on Invoice (subject to the organisation's
	 * invoice policy). On unless the Agend Apps Shop setting is switched off.
	 *
	 * @return bool
	 */
	public static function invoice_checkout_enabled(): bool {
		$enabled = '0' !== (string) get_option( 'agend_apps_shop_pay_on_invoice', '1' );

		/**
		 * Filters whether the cart offers Pay on Invoice.
		 *
		 * @param bool $enabled True when the shop setting offers Pay on Invoice.
		 */
		return (bool) apply_filters( 'agend_apps_cart_invoice_checkout_enabled', $enabled );
	}

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
			'/' . $this->rest_base . '/items/attendees',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'set_cart_item_attendees' ),
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
						'guest_buyer' => array(
							'type'       => 'object',
							'properties' => array(
								'name'  => array( 'type' => 'string' ),
								'email' => array( 'type' => 'string' ),
							),
						),
						'cancelUrl'  => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_url',
						),
						'discount_code'          => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'accepted_quote_version' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/checkout-options',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'checkout_options' ),
					'permission_callback' => array( $this, 'nonce_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/checkout/invoice',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'checkout_invoice' ),
					'permission_callback' => array( $this, 'nonce_check' ),
					'args'                => array(
						'idempotencyKey'      => array(
							'required'          => true,
							'type'              => 'string',
							'minLength'         => 1,
							'maxLength'         => 200,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'purchaseOrderNumber' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'companyId'           => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'discountCode'        => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'acceptedQuoteVersion' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
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

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/transfer',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'transfer_cart' ),
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
		$result   = agend_apps_cart_get( $identity['cart_session'], $identity['bearer_token'] );

		if ( ! is_wp_error( $result ) && ! empty( $result['data']['items'] ) ) {
			$event_names = array();
			foreach ( $result['data']['items'] as &$item ) {
				$slug = $item['metadata']['event_slug'] ?? '';
				if ( 'event_tickets' !== ( $item['product_type'] ?? '' ) || '' === $slug ) {
					continue;
				}
				if ( ! array_key_exists( $slug, $event_names ) ) {
					$cache_key = 'agend_apps_cart_event_name_' . md5( $slug );
					$name      = get_transient( $cache_key );
					if ( false === $name ) {
						$event = agend_apps_events_get_event( $slug );
						$name  = is_wp_error( $event ) ? '' : ( $event['data']['name'] ?? '' );
						set_transient( $cache_key, $name, '' === $name ? MINUTE_IN_SECONDS : 10 * MINUTE_IN_SECONDS );
					}
					$event_names[ $slug ] = $name;
				}
				if ( '' !== $event_names[ $slug ] ) {
					$item['name'] = $event_names[ $slug ] . ' - ' . $item['name'];
				}
			}
			unset( $item );
		}

		return $this->prepare_api_response( $result );
	}

	/**
	 * Resolves the cart identity for the current request.
	 *
	 * The guest cart session comes from the `X-Cart-Session` header forwarded
	 * by the frontend. The member identity is a Supabase bearer token resolved
	 * via `agend_apps_get_bearer_token()`; it is an empty string until the SSO
	 * bridge supplies one, in which case the cart behaves as a guest cart.
	 *
	 * @param WP_REST_Request $request Current request.
	 *
	 * @return array { cart_session: string, bearer_token: string }
	 */
	private function get_identity( WP_REST_Request $request ): array {
		return array(
			'cart_session' => (string) $request->get_header( 'X-Cart-Session' ),
			'bearer_token' => agend_apps_get_bearer_token(),
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
		$result   = agend_apps_cart_add_item( $item, $identity['cart_session'], $identity['bearer_token'] );

		return $this->prepare_api_response( $result );
	}

	/**
	 * Sets (replaces) the attendee assignments for a cart item.
	 *
	 * The full request body (`itemId` plus the `attendees` array) is forwarded
	 * to the gateway, which validates each attendee against the ticket's event
	 * attendee-field definitions.
	 *
	 * @param WP_REST_Request $request Current request.
	 *
	 * @return WP_REST_Response REST response.
	 */
	public function set_cart_item_attendees( WP_REST_Request $request ): WP_REST_Response {
		$identity = $this->get_identity( $request );
		$payload  = (array) $request->get_json_params();
		$result   = agend_apps_cart_set_item_attendees( $payload, $identity['cart_session'], $identity['bearer_token'] );

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
		$result   = agend_apps_cart_update_item( $item, $identity['cart_session'], $identity['bearer_token'] );

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
		$result   = agend_apps_cart_remove_item( $item_id, $identity['cart_session'], $identity['bearer_token'] );

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
		$result   = agend_apps_cart_clear( $identity['cart_session'], $identity['bearer_token'] );

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
		if ( '' === $identity['bearer_token'] && ! self::guest_checkout_allowed() ) {
			return $this->checkout_sign_in_required();
		}
		$urls     = array(
			'successUrl' => $request->get_param( 'successUrl' ),
			'cancelUrl'  => $request->get_param( 'cancelUrl' ),
		);
		$cart_url = get_option( 'agend_apps_shop_cart_page_url', '' ) ?: esc_url_raw( (string) $request->get_header( 'Referer' ) );
		$cart_url = $cart_url ?: home_url( '/' );
		if ( empty( $urls['successUrl'] ) ) {
			$urls['successUrl'] = get_option( 'agend_apps_shop_checkout_success_url', '' ) ?: add_query_arg( 'checkout', 'success', $cart_url );
		}
		if ( empty( $urls['cancelUrl'] ) ) {
			$urls['cancelUrl'] = get_option( 'agend_apps_shop_checkout_cancel_url', '' ) ?: $cart_url;
		}
		// The discount the buyer reviewed: the platform refuses the checkout
		// if the cart's quote has changed since, rather than charging another
		// amount.
		$code    = trim( (string) $request->get_param( 'discount_code' ) );
		$version = trim( (string) $request->get_param( 'accepted_quote_version' ) );
		if ( '' !== $code && strlen( $code ) <= 100 ) {
			$urls['discount_code'] = $code;
			if ( '' !== $version && strlen( $version ) <= 128 ) {
				$urls['accepted_quote_version'] = $version;
			}
		}
		$buyer = $request->get_param( 'guest_buyer' );
		if ( '' === $identity['bearer_token'] && is_array( $buyer ) ) {
			$urls['guest_buyer'] = array(
				'name'  => sanitize_text_field( $buyer['name'] ?? '' ),
				'email' => sanitize_email( $buyer['email'] ?? '' ),
			);
		}
		$result = agend_apps_cart_checkout( $urls, $identity['cart_session'], $identity['bearer_token'] );

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
		if ( '' === $identity['bearer_token'] && ! self::guest_checkout_allowed() ) {
			return $this->checkout_sign_in_required();
		}
		$result   = agend_apps_cart_checkout_complete( $identity['cart_session'], $identity['bearer_token'] );

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
		$result   = agend_apps_cart_checkout_cancel( $identity['cart_session'], $identity['bearer_token'] );

		return $this->prepare_api_response( $result );
	}

	/**
	 * Returns the payment options for the current cart.
	 *
	 * Member only: a guest is refused before any gateway call. With Pay on
	 * Invoice switched off in the shop settings the gateway answer is passed
	 * through with the invoice option marked unavailable
	 * (`reason: invoice_disabled`), so the cart shows card payment only. When
	 * the buyer may invoice more than one company, `approvedCompanies` lists
	 * each id with its name for the company choice.
	 *
	 * @param WP_REST_Request $request Current request.
	 *
	 * @return WP_REST_Response REST response.
	 */
	public function checkout_options( WP_REST_Request $request ): WP_REST_Response {
		$identity = $this->get_identity( $request );
		if ( '' === $identity['bearer_token'] ) {
			return $this->checkout_sign_in_required();
		}

		$result = agend_apps_cart_checkout_options( $identity['cart_session'], $identity['bearer_token'] );
		if ( is_wp_error( $result ) || ! isset( $result['data']['invoice'] ) || ! is_array( $result['data']['invoice'] ) ) {
			return $this->prepare_api_response( $result );
		}

		$invoice = &$result['data']['invoice'];
		if ( ! self::invoice_checkout_enabled() ) {
			$invoice['available']           = false;
			$invoice['reason']              = 'invoice_disabled';
			$invoice['reasonMessage']       = null;
			$invoice['paymentInstructions'] = null;
		}

		$company_ids = array_values( array_filter( (array) ( $invoice['approvedCompanyIds'] ?? array() ), 'is_string' ) );
		if ( ! empty( $invoice['available'] ) && count( $company_ids ) > 1 ) {
			$invoice['approvedCompanies'] = array_map(
				function ( string $id ): array {
					return array(
						'id'   => $id,
						'name' => $this->company_name( $id ),
					);
				},
				$company_ids
			);
		}
		unset( $invoice );

		return $this->prepare_api_response( $result );
	}

	/**
	 * Looks up a company's name for the invoice company choice.
	 *
	 * Sent with the site's key only (never the member's token), cached for ten
	 * minutes, and an empty string when the lookup fails, in which case the
	 * cart labels the option generically.
	 *
	 * @param string $company_id Company id from `approvedCompanyIds`.
	 * @return string Company name, or empty string.
	 */
	private function company_name( string $company_id ): string {
		$cache_key = 'agend_apps_cart_company_name_' . md5( $company_id );
		$name      = get_transient( $cache_key );
		if ( false !== $name ) {
			return (string) $name;
		}

		$response = agend_apps_api()->request(
			'GET',
			'/crm/companies/' . rawurlencode( $company_id ),
			array(
				'unattended'    => true,
				'scope_recheck' => false,
			)
		);
		$name     = is_wp_error( $response ) ? '' : sanitize_text_field( (string) ( $response['data']['name'] ?? '' ) );
		set_transient( $cache_key, $name, '' === $name ? MINUTE_IN_SECONDS : 10 * MINUTE_IN_SECONDS );

		return $name;
	}

	/**
	 * Places the current cart as an order on invoice.
	 *
	 * Member only, and refused when Pay on Invoice is switched off in the shop
	 * settings. The gateway still applies the organisation's invoice policy
	 * (eligibility, a required purchase order number) and its errors are
	 * passed through for the cart to show.
	 *
	 * @param WP_REST_Request $request Current request.
	 *
	 * @return WP_REST_Response REST response.
	 */
	public function checkout_invoice( WP_REST_Request $request ): WP_REST_Response {
		$identity = $this->get_identity( $request );
		if ( '' === $identity['bearer_token'] ) {
			return $this->checkout_sign_in_required();
		}
		if ( ! self::invoice_checkout_enabled() ) {
			return new WP_REST_Response(
				array(
					'code'    => 'agend_apps_invoice_disabled',
					'message' => __( 'Pay on Invoice is not available on this site.', 'agend-apps-core' ),
					'data'    => array( 'status_code' => 403 ),
				),
				403
			);
		}

		$payload = array(
			'paymentMethod'  => 'invoice',
			'idempotencyKey' => trim( (string) $request->get_param( 'idempotencyKey' ) ),
		);

		$po = trim( (string) $request->get_param( 'purchaseOrderNumber' ) );
		if ( '' !== $po ) {
			$length = function_exists( 'mb_strlen' ) ? mb_strlen( $po ) : strlen( $po );
			if ( $length > self::PURCHASE_ORDER_MAX_LENGTH ) {
				return new WP_REST_Response(
					array(
						'code'    => 'agend_apps_invalid_purchase_order',
						/* translators: %d: maximum number of characters. */
						'message' => sprintf( __( 'The purchase order number must be %d characters or fewer.', 'agend-apps-core' ), self::PURCHASE_ORDER_MAX_LENGTH ),
						'data'    => array( 'status_code' => 400 ),
					),
					400
				);
			}
			$payload['purchaseOrderNumber'] = $po;
		}

		$company_id = strtolower( trim( (string) $request->get_param( 'companyId' ) ) );
		if ( 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $company_id ) ) {
			$payload['companyId'] = $company_id;
		}

		$discount = trim( (string) $request->get_param( 'discountCode' ) );
		if ( '' !== $discount ) {
			$payload['discountCode'] = $discount;
		}
		$quote_version = trim( (string) $request->get_param( 'acceptedQuoteVersion' ) );
		if ( '' !== $quote_version && strlen( $quote_version ) <= 128 ) {
			$payload['acceptedQuoteVersion'] = $quote_version;
		}

		$result = agend_apps_cart_checkout_invoice( $payload, $identity['cart_session'], $identity['bearer_token'] );

		return $this->prepare_api_response( $result );
	}

	/**
	 * Merges a guest cart onto the authenticated member's account.
	 *
	 * Requires both a guest `X-Cart-Session` and a resolvable member bearer
	 * token; returns a 400 error when no member identity is available.
	 *
	 * @param WP_REST_Request $request Current request.
	 *
	 * @return WP_REST_Response REST response.
	 */
	public function transfer_cart( WP_REST_Request $request ): WP_REST_Response {
		$identity = $this->get_identity( $request );

		if ( '' === $identity['bearer_token'] ) {
			return new WP_REST_Response(
				array(
					'code'    => 'agend_apps_no_member_identity',
					'message' => __( 'A signed-in member is required to transfer a cart.', 'agend-apps-core' ),
					'data'    => array( 'status_code' => 400 ),
				),
				400
			);
		}

		$result = agend_apps_cart_transfer( $identity['cart_session'], $identity['bearer_token'] );

		return $this->prepare_api_response( $result );
	}
}
