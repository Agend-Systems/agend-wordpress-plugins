<?php
/**
 * HTTP client class.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central HTTP client for all outbound requests to the Agend Gateway API.
 *
 * Sibling plugins must never call `wp_remote_request()` directly. They should
 * use the public PHP functions in `includes/api/` which delegate here.
 */
class Agend_Apps_API {

	/**
	 * Configured API key.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Versioned base URL, e.g. `https://api.agend.dev/v1`.
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * Initialises the client from current plugin settings.
	 */
	public function __construct() {
		$this->api_key  = Agend_Apps_Settings::get_api_key();
		$this->base_url = Agend_Apps_Settings::get_base_url();
	}

	/**
	 * Sends an outbound HTTP request to the Agend API.
	 *
	 * @param string $method HTTP method: `GET`, `POST`, `PUT`, or `DELETE`.
	 * @param string $path   Relative path, e.g. `/cart/items`.
	 * @param array  $args   {
	 *     Optional. Request configuration.
	 *
	 *     @type array  $body         Request body as a PHP array; JSON-encoded before sending.
	 *     @type array  $query        Query string parameters appended to the URL.
	 *     @type array  $headers      Additional headers merged on top of defaults.
	 *     @type string $cart_session Forwarded as `X-Cart-Session` header when non-empty.
	 *     @type string $user_id      Forwarded as `X-User-ID` header when non-empty.
	 * }
	 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
	 */
	public function request( string $method, string $path, array $args = array() ) {
		// 1. Allow sibling plugins to rewrite the path.
		/**
		 * Filters the API request path before the URL is built.
		 *
		 * @param string $path   Relative path being requested.
		 * @param string $method HTTP method.
		 * @param array  $args   Request args.
		 */
		$path = (string) apply_filters( 'agend_apps_api_path', $path, $method, $args );

		// 2. Build the full URL.
		$url = $this->base_url . $path;

		if ( ! empty( $args['query'] ) && is_array( $args['query'] ) ) {
			$url = add_query_arg( $args['query'], $url );
		}

		// 3. Allow URL override.
		/**
		 * Filters the full API request URL.
		 *
		 * @param string $url The resolved full URL.
		 */
		$url = (string) apply_filters( 'agend_apps_api_url', $url );

		// 4. Build default headers.
		$headers = array(
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
			'x-api-key'    => $this->api_key,
		);

		// 5. Identity headers.
		if ( ! empty( $args['cart_session'] ) ) {
			$headers['X-Cart-Session'] = (string) $args['cart_session'];
		}
		if ( ! empty( $args['user_id'] ) ) {
			$headers['X-User-ID'] = (string) $args['user_id'];
		}

		// 6. Caller-supplied header overrides.
		if ( ! empty( $args['headers'] ) && is_array( $args['headers'] ) ) {
			$headers = array_merge( $headers, $args['headers'] );
		}

		// 7. Build wp_remote_request args.
		$request_args = array(
			'method'  => strtoupper( $method ),
			'headers' => $headers,
			'timeout' => 15,
		);

		if ( isset( $args['body'] ) && is_array( $args['body'] ) ) {
			$request_args['body'] = wp_json_encode( $args['body'] );
		}

		// 8. Check rate limit before sending.
		$rate_remaining = get_transient( 'agend_apps_rate_limit_remaining' );
		if ( false !== $rate_remaining && 0 === (int) $rate_remaining ) {
			$reset = get_transient( 'agend_apps_rate_limit_reset' );
			return new WP_Error(
				'agend_apps_rate_limited',
				__( 'Agend API rate limit exceeded. Please wait before retrying.', 'agend-apps-core' ),
				array( 'reset' => $reset )
			);
		}

		// 9. Allow final request arg overrides.
		/**
		 * Filters the full wp_remote_request args array before the request is sent.
		 *
		 * @param array  $request_args The wp_remote_request args.
		 * @param string $method       HTTP method.
		 * @param string $path         Relative path.
		 */
		$request_args = (array) apply_filters( 'agend_apps_api_request_args', $request_args, $method, $path );

		// 10. Send the request.
		$response = wp_remote_request( $url, $request_args );

		// 11. Propagate transport-level errors.
		if ( is_wp_error( $response ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[Agend Apps] HTTP request failed: ' . $response->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			}
			return $response;
		}

		// 12. Store rate limit header values for subsequent calls.
		$remaining  = wp_remote_retrieve_header( $response, 'X-RateLimit-Remaining' );
		$reset_time = wp_remote_retrieve_header( $response, 'X-RateLimit-Reset' );
		if ( '' !== $remaining ) {
			$ttl = ( '' !== $reset_time ) ? max( 1, (int) $reset_time - time() ) : 60;
			set_transient( 'agend_apps_rate_limit_remaining', (int) $remaining, $ttl );
			set_transient( 'agend_apps_rate_limit_reset', $reset_time, $ttl );
		}

		// 13. Parse the response.
		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		// 204 No Content — no body to decode.
		if ( 204 === $status_code ) {
			return array();
		}

		$decoded = json_decode( $body, true );

		if ( null === $decoded && '' !== $body ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[Agend Apps] Invalid JSON response from API: ' . $body ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			}
			return new WP_Error(
				'agend_apps_invalid_response',
				__( 'Invalid JSON response from Agend API.', 'agend-apps-core' ),
				array( 'body' => $body )
			);
		}

		// 14. Handle non-2xx status codes.
		if ( $status_code < 200 || $status_code >= 300 ) {
			$message = isset( $decoded['error']['message'] )
				? $decoded['error']['message']
				: __( 'An unknown API error occurred.', 'agend-apps-core' );

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions
					sprintf( '[Agend Apps] API error %d on %s %s: %s', $status_code, $method, $path, $message )
				);
			}

			return new WP_Error(
				'agend_api_error',
				$message,
				array(
					'status_code' => $status_code,
					'body'        => $decoded,
					'path'        => $path,
				)
			);
		}

		// 15. Apply response filter before returning.
		/**
		 * Filters the decoded API response before it is returned to the caller.
		 *
		 * @param array  $decoded     Decoded response body.
		 * @param string $method      HTTP method.
		 * @param string $path        Relative path.
		 * @param int    $status_code HTTP status code.
		 */
		$decoded = apply_filters( 'agend_apps_api_response', $decoded, $method, $path, $status_code );

		return $decoded;
	}

	/**
	 * Performs a cached GET request.
	 *
	 * Checks the transient store first; on a miss calls `request()` and caches
	 * the successful response.
	 *
	 * @param string $path      Relative path, e.g. `/directory/listings`.
	 * @param array  $args      Request args passed through to `request()`.
	 * @param string $cache_key Unique cache identifier (without the `agend_apps_` prefix).
	 * @param int    $ttl       Cache lifetime in seconds.
	 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
	 */
	public function get_cached( string $path, array $args, string $cache_key, int $ttl ) {
		$transient_name = 'agend_apps_' . $cache_key;
		$cached         = get_transient( $transient_name );

		if ( false !== $cached ) {
			return $cached;
		}

		$response = $this->request( 'GET', $path, $args );

		if ( ! is_wp_error( $response ) ) {
			set_transient( $transient_name, $response, $ttl );
		}

		return $response;
	}
}

/**
 * Returns a shared Agend_Apps_API instance.
 *
 * Uses a static variable so the class is only instantiated once per request,
 * avoiding redundant option reads.
 *
 * @return Agend_Apps_API
 */
function agend_apps_api(): Agend_Apps_API {
	static $instance = null;

	if ( null === $instance ) {
		$instance = new Agend_Apps_API();
	}

	return $instance;
}
