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
	 * Versioned base URL, e.g. `https://api.agend.com.au/v1`.
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * Optional Vercel deployment-protection bypass token. When non-empty it is
	 * sent as the `x-vercel-protection-bypass` header on every request.
	 *
	 * @var string
	 */
	private $vercel_bypass_token;

	/**
	 * Member-token GET responses already fetched in this PHP request, keyed by
	 * the cache key plus the digest of the token that fetched them. Never
	 * persisted: a member's response is resolved against their entitlements on
	 * every request, so it lives only as long as the request that fetched it.
	 *
	 * @var array<string, array>
	 */
	private $identity_memo = array();

	/**
	 * The bearer the last successful exchange actually carried, '' for none.
	 * A rejected token is retried with a re-resolved one or with none, so the
	 * bearer a caller resolved beforehand is not always the one a response
	 * belongs to.
	 *
	 * @var string|null
	 */
	private $sent_bearer = null;

	/**
	 * Initialises the client from current plugin settings.
	 */
	public function __construct() {
		$this->api_key             = Agend_Apps_Settings::get_api_key();
		$this->base_url            = Agend_Apps_Settings::get_base_url();
		$this->vercel_bypass_token = Agend_Apps_Settings::get_vercel_bypass_token();
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
	 *     @type array  $query_multi  Repeatable query parameters, keyed by name with an array of
	 *                                values each. Serialised as repeated bare keys
	 *                                (`key=a&key=b`, no PHP-style brackets) because the gateway
	 *                                reads them via `searchParams.getAll()`.
	 *     @type array  $headers      Additional headers merged on top of defaults.
	 *     @type string $cart_session Forwarded as `X-Cart-Session` header when non-empty.
	 *     @type string $bearer_token Supabase user JWT forwarded as `Authorization: Bearer`.
	 *                                When omitted, the value of `agend_apps_get_bearer_token()`
	 *                                is used so a logged-in identity is attached automatically.
	 *     @type bool   $raw          Optional. When true the response body is returned verbatim
	 *                                (no JSON decoding) as an array with `body`, `status_code`,
	 *                                `content_type`, and `content_disposition` keys. For
	 *                                non-JSON payloads such as iCal files. Default false.
	 *     @type bool   $unattended   Internal use only. When true, skips the bearer resolver
	 *                                entirely (no Authorization header is sent), even though
	 *                                `bearer_token` was not supplied. Used by the internal
	 *                                401/403-on-GET retry below; callers should not set this.
	 *     @type bool   $bearer_auto_resolved Internal use only. Set by `get_cached()` when the
	 *                                `bearer_token` it passes came from the shared resolver.
	 *     @type bool   $bearer_reminted Internal use only. Set on the one retry made with a
	 *                                re-resolved bearer after a 401, so a second rejection falls
	 *                                back to the unattended retry instead of resolving again.
	 *     @type int    $log_attempt  Internal use only. The attempt number recorded in the API
	 *                                log; 2 for the first retry, 3 for the unattended retry that
	 *                                follows a re-resolved bearer.
	 * }
	 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
	 */
	public function request( string $method, string $path, array $args = array() ) {
		$prepared = $this->prepare_request( $method, $path, $args );

		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		$started  = microtime( true );
		$response = wp_remote_request( $prepared['url'], $prepared['request_args'] );
		$elapsed  = (int) round( ( microtime( true ) - $started ) * 1000 );

		$retry  = null;
		$result = $this->finish_request( $prepared, $response, $elapsed, $retry );

		return null === $retry ? $result : $this->request( $method, $prepared['path'], $retry );
	}

	/**
	 * Builds an exchange up to the point of sending it: the filtered path and
	 * URL, the headers with the member token, and the transport args. A call
	 * the local rate limit or the scope guard refuses is logged and returned
	 * as the WP_Error a caller receives; nothing is sent for it.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Relative path.
	 * @param array  $args   Request args, as accepted by request().
	 * @return array{method: string, path: string, url: string, args: array, request_args: array, bearer_token: string, bearer_auto_resolved: bool}|WP_Error
	 */
	private function prepare_request( string $method, string $path, array $args ) {
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
			$pairs = $this->query_pairs( $args['query'] );

			if ( $pairs ) {
				$url .= ( false === strpos( $url, '?' ) ? '?' : '&' ) . implode( '&', $pairs );
			}
		}

		// Repeatable parameters as repeated bare keys (`key=a&key=b`). PHP's
		// bracketed array serialisation (`key[0]=a`) is NOT understood by the
		// gateway, which reads repeats via `searchParams.getAll()`.
		if ( ! empty( $args['query_multi'] ) && is_array( $args['query_multi'] ) ) {
			$pairs = array();

			foreach ( $args['query_multi'] as $key => $values ) {
				foreach ( (array) $values as $value ) {
					if ( '' === (string) $value ) {
						continue;
					}
					$pairs[] = rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
				}
			}

			if ( $pairs ) {
				$url .= ( false === strpos( $url, '?' ) ? '?' : '&' ) . implode( '&', $pairs );
			}
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

		// Optional Vercel deployment-protection bypass. Only attached when a
		// token is configured (e.g. for the staging gateway behind Vercel
		// protection); never sent otherwise. A caller-supplied header of the
		// same name (step 6) still wins.
		if ( '' !== $this->vercel_bypass_token ) {
			$headers['x-vercel-protection-bypass'] = $this->vercel_bypass_token;
		}

		// 5. Identity headers.
		if ( ! empty( $args['cart_session'] ) ) {
			$headers['X-Cart-Session'] = (string) $args['cart_session'];
		}

		// User identity is carried by a Supabase bearer token (a verified JWT)
		// in the Authorization header, layered on top of the X-API-Key. The
		// legacy X-User-ID assertion header was removed from the gateway and is
		// now rejected, so it must never be sent. When no per-request token is
		// supplied the shared resolver is consulted so a logged-in identity is
		// attached automatically; an empty string leaves the request unattended
		// (guest / API-key-only).
		$bearer_supplied_by_caller = isset( $args['bearer_token'] ) && '' !== $args['bearer_token'];
		$bearer_unattended         = ! empty( $args['unattended'] );

		if ( $bearer_supplied_by_caller ) {
			$bearer_token = (string) $args['bearer_token'];
		} elseif ( $bearer_unattended ) {
			$bearer_token = '';
		} else {
			$bearer_token = agend_apps_get_bearer_token();
		}

		// Whether this bearer came from the auto-resolver (as opposed to being
		// supplied explicitly by the caller) determines whether the 401/403
		// retry below is allowed to fire: we only want to drop a bearer we
		// attached ourselves, never one the caller asked for on purpose.
		// `get_cached()` resolves the bearer itself and hands it over as
		// `bearer_token`, marking it `bearer_auto_resolved` so it still counts
		// as ours here.
		$bearer_auto_resolved = ( ! $bearer_supplied_by_caller || ! empty( $args['bearer_auto_resolved'] ) ) && ! $bearer_unattended;

		if ( '' !== $bearer_token ) {
			$headers['Authorization'] = 'Bearer ' . $bearer_token;
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
		} elseif ( isset( $args['body'] ) && is_string( $args['body'] ) ) {
			// A pre-encoded body, for the request shapes JSON cannot express.
			// Multipart uploads are the reason this exists: the caller builds
			// the body and supplies the matching Content-Type through
			// `$args['headers']`.
			//
			// Previously a string body was silently DROPPED here, so the
			// request went out with no payload and the failure surfaced as a
			// confusing validation error from the gateway rather than as
			// anything pointing back to this line.
			$request_args['body'] = $args['body'];
		}

		// A file upload is not a 15 second operation. The default stays put for
		// every ordinary call; only a caller that knows it is sending bytes
		// raises it.
		if ( isset( $args['timeout'] ) && is_numeric( $args['timeout'] ) ) {
			$request_args['timeout'] = (int) $args['timeout'];
		}

		// 8. Check rate limit before sending.
		$rate_remaining = get_transient( 'agend_apps_rate_limit_remaining' );
		if ( false !== $rate_remaining && 0 === (int) $rate_remaining ) {
			$reset = get_transient( 'agend_apps_rate_limit_reset' );

			$this->log_exchange( $method, $url, $request_args, $bearer_token, $args, null, null, 'rate_limited', null, 'agend_apps_rate_limited', 'Local rate-limit short-circuit; no request sent.' );

			// Carries the same `status_code` a live 429 would, so callers that
			// branch on status (retry scheduling, sweep pacing) treat the local
			// short-circuit and the gateway's own refusal identically.
			return new WP_Error(
				'agend_apps_rate_limited',
				__( 'Agend API rate limit exceeded. Please wait before retrying.', 'agend-apps-core' ),
				array(
					'status_code' => 429,
					'reset'       => $reset,
				)
			);
		}

		// 8b. Refuse a call the stored key is known not to be allowed to make.
		// The gateway would answer 403 anyway; refusing here saves the round
		// trip, and the log row says why a developer got nothing back.
		$refusal = $this->unauthorised_call( $method, $path );

		if ( null !== $refusal ) {
			$this->log_exchange( $method, $url, $request_args, $bearer_token, $args, null, null, 'blocked', null, $refusal['code'], $refusal['message'] );

			// Carries the same `status_code` a live 403 would, so callers that
			// branch on status treat the local refusal and the gateway's own
			// identically.
			return new WP_Error(
				$refusal['code'],
				$refusal['message'],
				array(
					'status_code' => 403,
					'path'        => $path,
					'missing'     => $refusal['missing'],
					'app'         => $refusal['app'],
				)
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

		return array(
			'method'               => $method,
			'path'                 => $path,
			'url'                  => $url,
			'args'                 => $args,
			'request_args'         => $request_args,
			'bearer_token'         => $bearer_token,
			'bearer_auto_resolved' => $bearer_auto_resolved,
		);
	}

	/**
	 * Turns the transport's answer to a prepared exchange into what request()
	 * returns, logging it once. A member token the gateway rejects is not
	 * retried here: `$retry` receives the args to send the call again with,
	 * and the return is null.
	 *
	 * @param array               $prepared  What prepare_request() returned.
	 * @param array|WP_Error      $response  The transport's response.
	 * @param int                 $elapsed   Milliseconds the exchange took.
	 * @param array|null          $retry     Set to the retry's args when one is due.
	 * @param callable|null       $reresolve Resolves the member token again after a 401;
	 *                                       agend_apps_get_bearer_token() when null.
	 * @return array|WP_Error|null
	 */
	private function finish_request( array $prepared, $response, int $elapsed, ?array &$retry, ?callable $reresolve = null ) {
		$method               = $prepared['method'];
		$path                 = $prepared['path'];
		$url                  = $prepared['url'];
		$args                 = $prepared['args'];
		$request_args         = $prepared['request_args'];
		$bearer_token         = $prepared['bearer_token'];
		$bearer_auto_resolved = $prepared['bearer_auto_resolved'];
		$retry                = null;

		// Every exit below records the exchange exactly once.
		$log = function ( string $outcome, ?int $status, string $error_code = '', string $error_message = '' ) use ( $method, $url, $request_args, $bearer_token, $args, $elapsed, $response ): void {
			$this->log_exchange( $method, $url, $request_args, $bearer_token, $args, $response, $status, $outcome, $elapsed, $error_code, $error_message );
		};

		// 11. Propagate transport-level errors.
		if ( is_wp_error( $response ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[Agend Apps] HTTP request failed: ' . $response->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			}
			$log( 'transport_error', null, (string) $response->get_error_code(), $response->get_error_message() );
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

		// Raw passthrough (e.g. iCal downloads): no JSON handling. Errors are
		// still surfaced as WP_Error with the upstream status so REST
		// controllers can translate them.
		if ( ! empty( $args['raw'] ) ) {
			if ( $status_code < 200 || $status_code >= 300 ) {
				$log( 'http_error', $status_code, 'agend_api_error' );
				return new WP_Error(
					'agend_api_error',
					__( 'The Agend API returned an error for this download.', 'agend-apps-core' ),
					array(
						'status_code' => $status_code,
						'path'        => $path,
						// A raw download otherwise throws the body away, and the
						// body is the only place a refusal says why it refused.
						// Carried verbatim so a controller can forward it without
						// a second request; decoding is the controller's business.
						'body'        => $body,
					)
				);
			}

			$log( 'success', $status_code );
			$this->sent_bearer = $bearer_token;

			return array(
				'body'                => $body,
				'status_code'         => $status_code,
				'content_type'        => (string) wp_remote_retrieve_header( $response, 'content-type' ),
				'content_disposition' => (string) wp_remote_retrieve_header( $response, 'content-disposition' ),
			);
		}

		// 204 No Content — no body to decode.
		if ( 204 === $status_code ) {
			$log( 'success', $status_code );
			$this->sent_bearer = $bearer_token;
			return array();
		}

		$decoded = json_decode( $body, true );

		if ( null === $decoded && '' !== $body ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// The length, never the body: the body can carry member data,
				// and the debug log has none of the API log's redaction.
				error_log( sprintf( '[Agend Apps] Invalid JSON response from API (%d bytes, status %d) on %s %s.', strlen( $body ), $status_code, $method, self::debug_path( $path ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			}
			$log( 'invalid_response', $status_code, 'agend_apps_invalid_response' );
			return new WP_Error(
				'agend_apps_invalid_response',
				__( 'Invalid JSON response from Agend API.', 'agend-apps-core' ),
				array( 'body' => $body )
			);
		}

		// 14. Handle non-2xx status codes.
		if ( $status_code < 200 || $status_code >= 300 ) {
			// Public catalogue data (e.g. GET /events, GET /lms/courses) must
			// still render for a signed-in WordPress user even when the
			// gateway does not accept their member session bearer for that
			// particular resource. Rather than let every catalogue read break
			// for logged-in users, retry once with no Authorization header
			// when the auto-resolved bearer itself appears to be the cause
			// (401/403 on a GET). A caller-supplied bearer, or any non-GET
			// method, is never retried: those are real permission failures,
			// not an identity mismatch we can safely drop.
			if (
				'GET' === strtoupper( $method )
				&& $bearer_auto_resolved
				&& '' !== $bearer_token
				&& ( 401 === $status_code || 403 === $status_code )
			) {
				/**
				 * Fires when the gateway rejects a bearer this client resolved
				 * itself, before the request is retried. A provider that caches
				 * the token it supplies through `agend_apps_bearer_token` hooks
				 * this to discard it: a Supabase session is revoked whenever the
				 * member signs out anywhere, so a cached token can be dead long
				 * before its recorded expiry.
				 *
				 * @param string $bearer_token The rejected bearer.
				 * @param int    $status_code  HTTP status: 401 or 403.
				 * @param string $path         Relative path that was requested.
				 */
				do_action( 'agend_apps_bearer_rejected', $bearer_token, $status_code, $path );

				// Only a 401 says the token itself is bad, so only a 401 is worth
				// a fresh one; a 403 is a permission answer no new token changes.
				// Once per request: a second rejection goes unattended below.
				$fresh_bearer = '';

				if ( 401 === $status_code && empty( $args['bearer_reminted'] ) ) {
					$fresh_bearer = null !== $reresolve ? (string) $reresolve() : agend_apps_get_bearer_token();
				}

				if ( '' !== $fresh_bearer && $fresh_bearer !== $bearer_token ) {
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions
							sprintf( '[Agend Apps] Gateway rejected the member bearer (401) on GET %s; retried with a re-resolved bearer.', self::debug_path( $path ) )
						);
					}

					$log( 'retried', $status_code, 'agend_api_error' );

					$retry                         = $args;
					$retry['bearer_token']         = $fresh_bearer;
					$retry['bearer_auto_resolved'] = true;
					$retry['bearer_reminted']      = true;
					$retry['log_attempt']          = (int) ( $args['log_attempt'] ?? 1 ) + 1;

					return null;
				}

				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions
						sprintf( '[Agend Apps] Gateway rejected the member bearer (%d) on GET %s; retried unattended.', $status_code, self::debug_path( $path ) )
					);
				}

				$log( 'retried', $status_code, 'agend_api_error' );

				$retry                 = $args;
				$retry['bearer_token'] = '';
				$retry['unattended']   = true;
				$retry['log_attempt']  = (int) ( $args['log_attempt'] ?? 1 ) + 1;

				return null;
			}

			$message = isset( $decoded['error']['message'] )
				? $decoded['error']['message']
				: __( 'An unknown API error occurred.', 'agend-apps-core' );

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions
					sprintf( '[Agend Apps] API error %d on %s %s: %s', $status_code, $method, self::debug_path( $path ), self::debug_text( (string) $message, (string) ( $request_args['body'] ?? '' ) ) )
				);
			}

			$log( 'http_error', $status_code, isset( $decoded['error']['code'] ) && is_scalar( $decoded['error']['code'] ) ? (string) $decoded['error']['code'] : 'agend_api_error', (string) $message );

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

		// Every caller relies on "array or WP_Error", and several hand the
		// result straight to an `array`-typed helper, so a non-array success
		// return is an uncaught TypeError rather than a failed request. Two
		// ways one gets here: `json_decode( '' )` is null and the invalid-JSON
		// guard above deliberately admits an empty body, so a 2xx with no body
		// arrives as null; and a 2xx whose body is a JSON scalar (`true`, `12`,
		// `"ok"`) decodes without error to a non-array. A response filter that
		// drops the array is caught here too. Reported as the same
		// invalid-response error, so callers that already fall back on an
		// unusable gateway answer need no new branch.
		if ( ! is_array( $decoded ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions
					sprintf(
						'[Agend Apps] Non-array %d response on %s %s (%d bytes, type %s).',
						$status_code,
						$method,
						self::debug_path( $path ),
						strlen( $body ),
						gettype( $decoded )
					)
				);
			}

			$log( 'invalid_response', $status_code, 'agend_apps_invalid_response' );

			return new WP_Error(
				'agend_apps_invalid_response',
				__( 'Invalid JSON response from Agend API.', 'agend-apps-core' ),
				array(
					'status_code' => $status_code,
					'body'        => $body,
					'path'        => $path,
				)
			);
		}

		// Carried onto the decoded array so a caller can distinguish response
		// shapes that share a body structure but differ by HTTP status (e.g.
		// login's 200 session vs 202 verification_required), without every
		// caller re-deriving it from a structural field
		// (SPEC-CORE-20260907-wordpress-email-verification-handling US-4.1
		// Decision change B). Never overwrites a `status_code` the gateway
		// itself put in the body.
		if ( ! isset( $decoded['status_code'] ) ) {
			$decoded['status_code'] = $status_code;
		}

		$log( 'success', $status_code );
		$this->sent_bearer = $bearer_token;

		return $decoded;
	}

	/**
	 * Decides whether a call must be refused because the stored API key is
	 * known not to be allowed to make it.
	 *
	 * Never refuses unless every one of these holds: the key's scopes are
	 * known for the configured key, no scope refresh is in flight, the path is
	 * not `/health` (the refresh itself), the endpoint is in the generated map,
	 * the key lacks the endpoint's app or a required scope, and the
	 * `agend_apps_api_block_unauthorised` filter agrees. Anything uncertain
	 * lets the call through to the gateway, which stays the authority.
	 *
	 * The cached scopes can be up to an hour old, and an admin may just have
	 * added a scope in the dashboard. So before refusing on data older than
	 * {@see Agend_Apps_Key_Scopes::RECHECK_AFTER}, one throttled refresh is
	 * made and the verdict re-evaluated against it.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Relative path, as filtered.
	 * @return array{code: string, message: string, missing: string[], app: string}|null Null to send.
	 */
	private function unauthorised_call( string $method, string $path ): ?array {
		if ( ! class_exists( 'Agend_Apps_Key_Scopes' ) || ! class_exists( 'Agend_Apps_Endpoint_Scopes' ) ) {
			return null;
		}

		if ( Agend_Apps_Key_Scopes::refreshing() || ! Agend_Apps_Key_Scopes::known() ) {
			return null;
		}

		$route = '/' . trim( (string) strtok( $path, '?' ), '/' );

		if ( '/health' === $route ) {
			return null;
		}

		$endpoint = Agend_Apps_Endpoint_Scopes::lookup( $method, $route );

		if ( null === $endpoint ) {
			return null;
		}

		$verdict = self::key_verdict( $endpoint );

		if ( null === $verdict ) {
			return null;
		}

		/**
		 * Filters whether a call the stored API key is known not to be allowed
		 * to make is refused locally instead of being sent.
		 *
		 * @param bool     $block   Whether to refuse the call. Default true.
		 * @param string   $method  HTTP method.
		 * @param string   $path    Relative path.
		 * @param string[] $missing Scopes the key lacks (empty when the app is
		 *                          what is not allowed).
		 */
		if ( ! apply_filters( 'agend_apps_api_block_unauthorised', true, strtoupper( $method ), $path, $verdict['missing'] ) ) {
			return null;
		}

		$age = time() - Agend_Apps_Key_Scopes::fetched_at();

		if ( $age >= Agend_Apps_Key_Scopes::RECHECK_AFTER && false === get_transient( Agend_Apps_Key_Scopes::RECHECK_TRANSIENT ) ) {
			// Set first, so a failing gateway is not asked again for five
			// minutes either.
			set_transient( Agend_Apps_Key_Scopes::RECHECK_TRANSIENT, 1, Agend_Apps_Key_Scopes::RECHECK_AFTER );
			Agend_Apps_Key_Scopes::refresh();

			if ( ! Agend_Apps_Key_Scopes::known() ) {
				return null;
			}

			$verdict = self::key_verdict( $endpoint );

			if ( null === $verdict ) {
				return null;
			}
		}

		return $verdict;
	}

	/**
	 * Compares the stored key against what an endpoint needs.
	 *
	 * Reads the stored option only, with no lazy refresh, because this runs
	 * inside `request()`.
	 *
	 * @param array{app: string, scopes: array<int, string[]>} $endpoint Map entry.
	 * @return array{code: string, message: string, missing: string[], app: string}|null Null when allowed.
	 */
	private static function key_verdict( array $endpoint ): ?array {
		$app = (string) $endpoint['app'];

		if ( ! Agend_Apps_Key_Scopes::has_app( $app ) ) {
			return array(
				'code'    => 'agend_apps_app_not_allowed',
				'message' => sprintf(
					/* translators: %s: app slug, e.g. events. */
					__( 'Not sent: the API key is not allowed to use the "%s" app.', 'agend-apps-core' ),
					$app
				),
				'missing' => array(),
				'app'     => $app,
			);
		}

		$held    = Agend_Apps_Key_Scopes::stored_scopes();
		$missing = null;

		// Any one alternative fully held is enough; report the closest miss.
		foreach ( $endpoint['scopes'] as $alternative ) {
			$lacking = array_values(
				array_filter(
					$alternative,
					static function ( $scope ) use ( $held ): bool {
						return ! Agend_Apps_Key_Scopes::satisfies( $held, (string) $scope );
					}
				)
			);

			if ( ! $lacking ) {
				return null;
			}

			if ( null === $missing || count( $lacking ) < count( $missing ) ) {
				$missing = $lacking;
			}
		}

		if ( null === $missing ) {
			return null;
		}

		return array(
			'code'    => 'agend_apps_scope_missing',
			'message' => sprintf(
				/* translators: %s: comma-separated scope names. */
				__( 'Not sent: the API key lacks the required scope: %s.', 'agend-apps-core' ),
				implode( ', ', $missing )
			),
			'missing' => $missing,
			'app'     => $app,
		);
	}

	/**
	 * A path safe for debug.log: the same redaction the API log applies, so
	 * a listing slug or an invitation token does not land there either.
	 */
	private static function debug_path( string $path ): string {
		return class_exists( 'Agend_Apps_Logger' ) ? agend_apps_logger()->redactor()->redact_path( $path ) : '[path]';
	}

	/**
	 * A gateway message safe for debug.log, with the same known-value pass
	 * as the API log: a message echoing a name from the request is scrubbed.
	 */
	private static function debug_text( string $text, string $request_body = '' ): string {
		if ( ! class_exists( 'Agend_Apps_Logger' ) ) {
			return '[message]';
		}

		$redactor = agend_apps_logger()->redactor();
		$redactor->forget();
		$redactor->remember( json_decode( $request_body, true ) );
		$redacted = $redactor->redact_text( $text );
		$redactor->forget();

		return $redacted;
	}

	/**
	 * Hands one exchange to the API log.
	 *
	 * Request headers are deliberately not passed: the API key and bearer
	 * never reach the logger, only which of them was used.
	 *
	 * @param string              $method        HTTP method.
	 * @param string              $url           Full request URL.
	 * @param array               $request_args  The wp_remote_request args.
	 * @param string              $bearer_token  Bearer attached, or ''.
	 * @param array               $args          The caller's request() args.
	 * @param array|WP_Error|null $response      Raw response, when one was received.
	 * @param int|null            $status        HTTP status.
	 * @param string              $outcome       Log outcome.
	 * @param int|null            $duration_ms   Round-trip time.
	 * @param string              $error_code    Error code.
	 * @param string              $error_message Error message.
	 */
	private function log_exchange( string $method, string $url, array $request_args, string $bearer_token, array $args, $response, ?int $status, string $outcome, ?int $duration_ms, string $error_code = '', string $error_message = '' ): void {
		if ( ! class_exists( 'Agend_Apps_Logger' ) || ! Agend_Apps_Logger::enabled() ) {
			return;
		}

		$headers = array();
		$body    = '';

		if ( is_array( $response ) ) {
			$body = (string) wp_remote_retrieve_body( $response );

			foreach ( Agend_Apps_Log_Redactor::SAFE_RESPONSE_HEADERS as $name ) {
				$value = wp_remote_retrieve_header( $response, $name );

				if ( '' !== $value && null !== $value ) {
					$headers[ $name ] = $value;
				}
			}
		}

		agend_apps_logger()->record(
			array(
				'source'           => 'agend-apps-core',
				'direction'        => 'outbound',
				'method'           => strtoupper( $method ),
				'url'              => $url,
				'status'           => $status,
				'outcome'          => $outcome,
				'duration_ms'      => $duration_ms,
				'attempt'          => (int) ( $args['log_attempt'] ?? 1 ),
				'auth_mode'        => '' !== $bearer_token ? 'bearer' : ( ! empty( $args['unattended'] ) ? 'unattended' : 'api_key' ),
				'request_body'     => is_string( $request_args['body'] ?? null ) ? $request_args['body'] : '',
				'response_body'    => ! empty( $args['raw'] ) && 'success' === $outcome ? '' : $body,
				'content_type'     => (string) ( $headers['content-type'] ?? '' ),
				'response_headers' => $headers,
				'error_code'       => $error_code,
				'error_message'    => $error_message,
			)
		);
	}

	/**
	 * Encodes query parameters as name=value pairs, nesting arrays in bracket
	 * notation.
	 *
	 * Not add_query_arg(): that helper encodes the key but leaves the value
	 * verbatim, because it is built for values a caller has already encoded.
	 * Ours are raw, so a search term of "annual gala" went out as
	 * `search=annual gala`, an invalid URL that the gateway reads truncated or
	 * not at all. Every value is encoded here instead.
	 *
	 * A nested array becomes `name[key]=value`, which is what the gateway's
	 * custom-field filters read. A plain list stays the caller's problem:
	 * repeatable parameters go through `query_multi`, because the gateway reads
	 * those as repeated bare keys rather than indexed brackets.
	 *
	 * @param array  $query  Query parameters.
	 * @param string $prefix Parent key when recursing.
	 * @return string[] Encoded `name=value` pairs.
	 */
	private function query_pairs( array $query, string $prefix = '' ): array {
		$pairs = array();

		foreach ( $query as $key => $value ) {
			$name = '' === $prefix ? (string) $key : $prefix . '[' . $key . ']';

			if ( is_array( $value ) ) {
				$pairs = array_merge( $pairs, $this->query_pairs( $value, $name ) );
				continue;
			}
			if ( null === $value ) {
				continue;
			}
			if ( is_bool( $value ) ) {
				$value = $value ? 'true' : 'false';
			}

			$pairs[] = rawurlencode( $name ) . '=' . rawurlencode( (string) $value );
		}

		return $pairs;
	}

	/**
	 * Cache-key prefixes never held even for the rest of the request: a cart
	 * mutates on the visitor's own next action, which may be in this request.
	 *
	 * @var string[]
	 */
	private const IDENTITY_UNCACHEABLE_PREFIXES = array( 'cart' );

	/**
	 * Cache-key prefixes whose shared entries also record when they were
	 * fetched, in a companion `<name>_at` transient, so a page render can
	 * inline them with their age ({@see peek_cached()}). A page cache can
	 * serve that HTML long after the entry itself expired.
	 *
	 * @var string[]
	 */
	private const FETCH_TIME_PREFIXES = array( 'directory_facets', 'directory_categories' );

	/**
	 * Suffix that binds a memo entry to one member token, so a response is
	 * never returned for a different token or for a call without one.
	 *
	 * Derived from the bearer itself, never from the WordPress user id. The
	 * token is the identity the response was actually fetched under, and the
	 * two can diverge: a shared or generic WordPress login, or a bearer
	 * resolved for someone other than the current user, collapses distinct
	 * members onto one id. The digest is truncated only for key length and
	 * never leaves the PHP process.
	 */
	private function identity_cache_suffix( string $bearer_token ): string {
		return '_b' . substr( hash( 'sha256', $bearer_token ), 0, 16 );
	}

	/**
	 * Whether this cache key must bypass the store entirely while a bearer is
	 * attached, regardless of identity scoping.
	 */
	private function is_identity_uncacheable( string $cache_key ): bool {
		foreach ( self::IDENTITY_UNCACHEABLE_PREFIXES as $prefix ) {
			if ( 0 === strpos( $cache_key, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Fetches a GET response, serving a stored copy where one may be shared.
	 *
	 * A read without a member token goes through the transient store, which
	 * every visitor to the site shares.
	 *
	 * A read with a member token (a bearer read) never touches the store, in
	 * either direction. Several gateway endpoints enrich their response for
	 * the member: `/events` adds the caller's own registration and price
	 * group, `/lms/courses` their enrolment, `/cms/content` their access
	 * projection, and directory reads the gated fields their grants cover. A
	 * member's results are resolved against their entitlements on every
	 * request, and gated values must not sit in `wp_options`, its backups and
	 * its clones. Within one PHP request a bearer read is held in memory, so
	 * one page render never asks the gateway the same question twice; nothing
	 * outlives the request. Cart reads are not held at all.
	 *
	 * The bearer is resolved once here and passed through in `$args`, because
	 * the resolver reads user meta and can mint a token.
	 *
	 * A response that is the same for every visitor (a category list, a facet
	 * set with no custom field in it) is read with `anonymous` set: no member
	 * token is resolved or sent, and the shared entry an anonymous visitor
	 * uses is read and written, for signed-in visitors too.
	 *
	 * @param string $path      Relative path, e.g. `/directory/export-reports`.
	 * @param array  $args      Request args, as accepted by `request()`, plus
	 *                          `anonymous` (bool): read as an anonymous visitor
	 *                          would. Only for a response that does not vary by
	 *                          viewer.
	 * @param string $cache_key Cache key the caller derived from the query.
	 * @param int    $ttl       Transient lifetime in seconds, for a read
	 *                          without a member token. 0 stores nothing.
	 * @param bool   $fresh     When true, skip the stored or held copy and
	 *                          always call the gateway live. A read without a
	 *                          member token still writes the result back, so
	 *                          a later cached read picks up the new value too.
	 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
	 */
	public function get_cached( string $path, array $args, string $cache_key, int $ttl, bool $fresh = false ) {
		$anonymous = ! empty( $args['anonymous'] );
		unset( $args['anonymous'] );

		if ( $anonymous ) {
			$args['bearer_token'] = '';
			$args['unattended']   = true;
			$bearer_token         = '';
		} else {
			$bearer_token = isset( $args['bearer_token'] ) && '' !== $args['bearer_token']
				? (string) $args['bearer_token']
				: agend_apps_get_bearer_token();
		}

		if ( '' !== $bearer_token ) {
			// Record whether the bearer was ours, so request() may drop it and
			// retry unattended when the gateway rejects it (401/403).
			$args['bearer_auto_resolved'] = empty( $args['bearer_token'] );
			$args['bearer_token']         = $bearer_token;

			if ( $this->is_identity_uncacheable( $cache_key ) ) {
				return $this->request( 'GET', $path, $args );
			}

			return $this->get_memoised( $path, $args, $cache_key, $fresh );
		}

		$transient_name = 'agend_apps_' . $cache_key;

		if ( ! $fresh ) {
			$cached = get_transient( $transient_name );

			if ( false !== $cached ) {
				return $cached;
			}
		}

		$response = $this->request( 'GET', $path, $args );

		// Always written, even on a forced-fresh call: the point of $fresh is
		// that THIS call cannot serve a stale answer, not that the shared cache
		// stays stale until it next expires on its own. A TTL of 0 would store
		// the entry with no expiry and autoloaded, so it is not written at all.
		if ( ! is_wp_error( $response ) && $ttl > 0 ) {
			set_transient( $transient_name, $response, $ttl );

			if ( $this->records_fetch_time( $cache_key ) ) {
				set_transient( $transient_name . '_at', time(), $ttl );
			}
		}

		return $response;
	}

	/**
	 * The shared entry for a cache key and when it was fetched, read without
	 * calling the gateway, or null when either is missing. For a key whose
	 * fetch time is recorded ({@see FETCH_TIME_PREFIXES}).
	 *
	 * @param string $cache_key Cache key, as passed to get_cached().
	 * @return array{fetched_at: int, response: array}|null
	 */
	public function peek_cached( string $cache_key ): ?array {
		if ( ! $this->records_fetch_time( $cache_key ) ) {
			return null;
		}

		$response   = get_transient( 'agend_apps_' . $cache_key );
		$fetched_at = get_transient( 'agend_apps_' . $cache_key . '_at' );

		if ( ! is_array( $response ) || ! is_numeric( $fetched_at ) ) {
			return null;
		}

		return array(
			'fetched_at' => (int) $fetched_at,
			'response'   => $response,
		);
	}

	private function records_fetch_time( string $cache_key ): bool {
		foreach ( self::FETCH_TIME_PREFIXES as $prefix ) {
			if ( 0 === strpos( $cache_key, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A bearer read through the in-request memo. The entry is keyed on the
	 * bearer the exchange actually carried: a retry after a rejected token is
	 * held under the re-minted token, never the rejected one, and a retry that
	 * carried no token is not held.
	 *
	 * @param string $path      Relative path.
	 * @param array  $args      Request args with the resolved `bearer_token`.
	 * @param string $cache_key Cache key the caller derived from the query.
	 * @param bool   $fresh     Skip a held copy.
	 * @return array|WP_Error
	 */
	private function get_memoised( string $path, array $args, string $cache_key, bool $fresh ) {
		$memo_key = $cache_key . $this->identity_cache_suffix( (string) $args['bearer_token'] );

		if ( ! $fresh && array_key_exists( $memo_key, $this->identity_memo ) ) {
			return $this->identity_memo[ $memo_key ];
		}

		$this->sent_bearer = null;
		$response          = $this->request( 'GET', $path, $args );

		if ( ! is_wp_error( $response ) && null !== $this->sent_bearer && '' !== $this->sent_bearer ) {
			$this->identity_memo[ $cache_key . $this->identity_cache_suffix( $this->sent_bearer ) ] = $response;
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

/**
 * Resolves the Supabase user bearer token for the current request.
 *
 * Returns a verified Supabase JWT for the logged-in WordPress user so the
 * gateway can act on their behalf (member cart, `/me` endpoints, authored
 * mutations). Returns an empty string when no user identity is available, in
 * which case the request proceeds unattended (API-key only) or, for the cart,
 * as a guest keyed on `X-Cart-Session`.
 *
 * The token itself is not minted here. A bridge plugin (the Agend SSO
 * integration) is expected to hook `agend_apps_bearer_token` and return the
 * current user's JWT. Until that bridge is in place the resolver returns an
 * empty string, which keeps every endpoint working in its unattended/guest
 * mode.
 *
 * @return string Supabase bearer token, or an empty string when unavailable.
 */
function agend_apps_get_bearer_token(): string {
	/**
	 * Filters the Supabase user bearer token attached to outbound gateway requests.
	 *
	 * @param string $token Bearer token. Default empty string.
	 */
	return (string) apply_filters( 'agend_apps_bearer_token', '' );
}
