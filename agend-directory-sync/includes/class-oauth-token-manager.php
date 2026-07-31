<?php
/**
 * OAuth 2.0 client-credentials token acquisition for the Custom HTTP API
 * source.
 *
 * Acquires an access token via the `client_credentials` grant, authenticating
 * to the token endpoint with HTTP Basic (client id + secret) per US-2.2
 * criterion 3. The client secret is read from the
 * `AGEND_DIRECTORY_SYNC_OAUTH_CLIENT_SECRET` constant at call time only; it is
 * never copied into an option or any serialisable object state
 * (SPEC-DIR-20260731 Decision 2.4). The acquired access token is cached in a
 * transient keyed on the token endpoint + client id, expiring 60 seconds
 * before the token response's `expires_in` (US-2.2 criterion 4); the transient
 * never stores the refresh token or client secret.
 *
 * @package Agend_Directory_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Agend_Directory_Sync_Oauth_Token_Manager' ) ) :
	final class Agend_Directory_Sync_Oauth_Token_Manager {

		/**
		 * Assumed token lifetime when the token response omits `expires_in`.
		 * A conservative common default; the 60-second safety margin is still
		 * applied on top of it.
		 */
		public const DEFAULT_EXPIRES_IN = 3600;

		/**
		 * Safety margin subtracted from the reported `expires_in` so the
		 * cached token is always treated as expired slightly before the
		 * upstream server would reject it (US-2.2 criterion 4).
		 */
		public const EXPIRY_SAFETY_MARGIN = 60;

		/**
		 * Timeout for the token request itself.
		 */
		public const TOKEN_REQUEST_TIMEOUT = 30;

		/**
		 * Get a valid access token, from the transient cache unless
		 * `$force_refresh` is true (used by the caller's 401 retry).
		 *
		 * @throws RuntimeException When the token request fails, or the
		 *                          client secret constant is undefined.
		 */
		public static function get_access_token(
			string $token_url,
			string $client_id,
			string $scope,
			bool $force_refresh = false
		): string {
			$key = self::transient_key( $token_url, $client_id );

			if ( ! $force_refresh ) {
				$cached = get_transient( $key );
				if ( is_array( $cached ) && '' !== (string) ( $cached['access_token'] ?? '' ) ) {
					return (string) $cached['access_token'];
				}
			}

			$token_data   = self::acquire_token( $token_url, $client_id, $scope );
			$access_token = (string) ( $token_data['access_token'] ?? '' );

			if ( '' === $access_token ) {
				throw new RuntimeException( __( 'OAuth token response did not include an access_token.', 'agend-directory-sync' ) );
			}

			$expires_in = isset( $token_data['expires_in'] ) && is_numeric( $token_data['expires_in'] )
				? (int) $token_data['expires_in']
				: self::DEFAULT_EXPIRES_IN;

			$ttl = $expires_in - self::EXPIRY_SAFETY_MARGIN;
			// A transient with a 0 expiration means "never expires" in
			// WordPress, which would be wrong for a short-lived token; clamp
			// to a 1-second minimum so a very short-lived or already-expired
			// token is never cached indefinitely.
			if ( $ttl <= 0 ) {
				$ttl = 1;
			}

			set_transient(
				$key,
				array(
					'access_token' => $access_token,
					'expires_at'   => time() + $ttl,
				),
				$ttl
			);

			return $access_token;
		}

		/**
		 * Delete the cached access token so the next `get_access_token()`
		 * call acquires a fresh one. Called on a 401 from a data request
		 * (US-2.2 criterion 4).
		 */
		public static function invalidate( string $token_url, string $client_id ): void {
			delete_transient( self::transient_key( $token_url, $client_id ) );
		}

		/**
		 * POST the client-credentials grant. HTTP Basic auth carries the
		 * client id + secret (US-2.2 criterion 3); the secret is read from
		 * the constant here and nowhere else. Failure messages report only
		 * the HTTP status and the OAuth `error` code, never the response's
		 * token fields (US-2.2 criterion 7).
		 *
		 * @return array<string, mixed>
		 *
		 * @throws RuntimeException When the constant is undefined, the
		 *                          request transport-fails, the endpoint
		 *                          responds non-2xx, or the body is not JSON.
		 */
		private static function acquire_token( string $token_url, string $client_id, string $scope ): array {
			if ( ! defined( 'AGEND_DIRECTORY_SYNC_OAUTH_CLIENT_SECRET' ) ) {
				throw new RuntimeException( __( 'AGEND_DIRECTORY_SYNC_OAUTH_CLIENT_SECRET is not defined in wp-config.php.', 'agend-directory-sync' ) );
			}

			$client_secret = (string) constant( 'AGEND_DIRECTORY_SYNC_OAUTH_CLIENT_SECRET' );

			$body = array( 'grant_type' => 'client_credentials' );
			if ( '' !== trim( $scope ) ) {
				$body['scope'] = $scope;
			}

			/**
			 * Filter the OAuth token request args before the request is
			 * sent. The Authorization header (HTTP Basic) is fixed and not
			 * filterable here, since it carries the secret.
			 *
			 * @param array $args The wp_remote_post args, minus Authorization.
			 */
			$args = (array) apply_filters(
				'agend_directory_sync_oauth_token_request_args',
				array(
					'timeout' => self::TOKEN_REQUEST_TIMEOUT,
					'headers' => array(
						'Content-Type' => 'application/x-www-form-urlencoded',
						'Accept'       => 'application/json',
					),
					'body'    => $body,
				)
			);

			// The Authorization header always wins over anything a filter
			// set, so a filter can never accidentally drop client auth.
			$args['headers']['Authorization'] = 'Basic ' . base64_encode( $client_id . ':' . $client_secret );

			$response = wp_remote_post( $token_url, $args );

			if ( is_wp_error( $response ) ) {
				throw new RuntimeException(
					sprintf(
						/* translators: %s: underlying WP HTTP transport error message. */
						__( 'OAuth token request failed: %s', 'agend-directory-sync' ),
						$response->get_error_message()
					)
				);
			}

			$status   = (int) wp_remote_retrieve_response_code( $response );
			$raw_body = (string) wp_remote_retrieve_body( $response );
			$decoded  = json_decode( $raw_body, true );

			if ( $status < 200 || $status >= 300 ) {
				$error_code = is_array( $decoded ) && isset( $decoded['error'] ) ? (string) $decoded['error'] : '';

				throw new RuntimeException(
					'' !== $error_code
						? sprintf(
							/* translators: 1: token endpoint HTTP status code, 2: OAuth `error` code. */
							__( 'OAuth token endpoint returned HTTP %1$d (error: %2$s).', 'agend-directory-sync' ),
							$status,
							$error_code
						)
						: sprintf(
							/* translators: %d: token endpoint HTTP status code. */
							__( 'OAuth token endpoint returned HTTP %d.', 'agend-directory-sync' ),
							$status
						)
				);
			}

			if ( ! is_array( $decoded ) ) {
				throw new RuntimeException( __( 'OAuth token endpoint response was not valid JSON.', 'agend-directory-sync' ) );
			}

			return $decoded;
		}

		/**
		 * Transient key for the cached access token: md5 of the token
		 * endpoint + client id, so distinct source configurations never
		 * collide (US-2.2 criterion 4).
		 */
		private static function transient_key( string $token_url, string $client_id ): string {
			return 'agend_dsync_oauth_' . md5( $token_url . '|' . $client_id );
		}
	}
endif;
