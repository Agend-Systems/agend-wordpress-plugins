<?php
/**
 * Base REST controller class.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract base controller for all Agend Apps REST routes.
 *
 * Sets the shared namespace and provides helper methods for translating
 * WP_Error and raw API responses into WP_REST_Response objects.
 */
abstract class Agend_Apps_REST_Controller extends WP_REST_Controller {

	/**
	 * REST API namespace for all Agend Apps routes.
	 *
	 * @var string
	 */
	protected $namespace = 'agend-apps/v1';

	/**
	 * Whether a visitor without a member session may check out as a guest.
	 *
	 * Reads the Agend Apps Shop "Guest checkout" setting. Anything other than
	 * an explicit `allow` requires sign-in, so a site that never saved the
	 * setting keeps checkout for signed-in members only.
	 *
	 * @return bool
	 */
	public static function guest_checkout_allowed(): bool {
		$allowed = 'allow' === get_option( 'agend_apps_shop_guest_checkout', 'require' );

		/**
		 * Filters whether guests may check out or register.
		 *
		 * @param bool $allowed True when the shop setting allows guest checkout.
		 */
		return (bool) apply_filters( 'agend_apps_cart_guest_checkout_allowed', $allowed );
	}

	/**
	 * The refusal sent when a route needs a signed-in member.
	 *
	 * @return WP_REST_Response 401 response.
	 */
	protected function checkout_sign_in_required(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'code'    => 'agend_apps_sign_in_required',
				'message' => __( 'Sign in to check out.', 'agend-apps-core' ),
				'data'    => array( 'status_code' => 401 ),
			),
			401
		);
	}

	/**
	 * Converts a WP_Error or decoded API array into a WP_REST_Response.
	 *
	 * When the value is a WP_Error the HTTP status code is derived from
	 * the `status_code` key in the error data, falling back to 502.
	 * Successful responses are wrapped in a 200 response.
	 *
	 * @param array|WP_Error $result The value returned by an API function.
	 * @return WP_REST_Response REST response.
	 */
	protected function prepare_api_response( $result ): WP_REST_Response {
		if ( is_wp_error( $result ) ) {
			return $this->error_to_response( $result );
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Converts a WP_Error into a WP_REST_Response with an appropriate HTTP status.
	 *
	 * Status code resolution order:
	 * 1. `status_code` key in error data (forwarded from the upstream API).
	 * 2. 429 for `agend_apps_rate_limited` errors.
	 * 3. 502 fallback for all other errors.
	 *
	 * @param WP_Error $error The WP_Error to convert.
	 * @return WP_REST_Response REST response with error body.
	 */
	protected function error_to_response( WP_Error $error ): WP_REST_Response {
		$data   = $error->get_error_data();
		$code   = $error->get_error_code();
		$status = 502;

		if ( isset( $data['status_code'] ) && is_int( $data['status_code'] ) ) {
			$status = $data['status_code'];
		} elseif ( 'agend_apps_rate_limited' === $code ) {
			$status = 429;
		}

		$body = array(
			'code'    => $code,
			'message' => $error->get_error_message(),
			'data'    => $data,
		);

		return new WP_REST_Response( $body, $status );
	}
}
