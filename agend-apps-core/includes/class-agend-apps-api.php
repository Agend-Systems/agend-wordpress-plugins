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

		// Raw passthrough (e.g. iCal downloads): no JSON handling. Errors are
		// still surfaced as WP_Error with the upstream status so REST
		// controllers can translate them.
		if ( ! empty( $args['raw'] ) ) {
			if ( $status_code < 200 || $status_code >= 300 ) {
				return new WP_Error(
					'agend_api_error',
					__( 'The Agend API returned an error for this download.', 'agend-apps-core' ),
					array(
						'status_code' => $status_code,
						'path'        => $path,
					)
				);
			}

			return array(
				'body'                => $body,
				'status_code'         => $status_code,
				'content_type'        => (string) wp_remote_retrieve_header( $response, 'content-type' ),
				'content_disposition' => (string) wp_remote_retrieve_header( $response, 'content-disposition' ),
			);
		}

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
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions
						sprintf( '[Agend Apps] Gateway rejected the member bearer (%d) on GET %s; retried unattended.', $status_code, $path )
					);
				}

				$retry_args                = $args;
				$retry_args['bearer_token'] = '';
				$retry_args['unattended']   = true;

				return $this->request( $method, $path, $retry_args );
			}

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
						$path,
						strlen( $body ),
						gettype( $decoded )
					)
				);
			}

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

		return $decoded;
	}

	/**
	 * Performs a cached GET request.
	 *
	 * Checks the transient store first; on a miss calls `request()` and caches
	 * the successful response.
	 *
	 * IDENTITY BYPASS. Transients are SHARED across every visitor to the site,
	 * so a response computed for one signed-in member must never be stored in
	 * or served from them. When a member bearer is attached the shared cache is
	 * skipped entirely, in both directions.
	 *
	 * This matters because several gateway endpoints enrich their response when
	 * a bearer is present, and the cache key does not include the member:
	 *
	 *   - `/events` and `/events/{slug}` add `viewer_price_group` and
	 *     `my_registration`, which is the caller's OWN ticket and registration
	 *     id.
	 *   - `/lms/courses` and `/lms/courses/{id}` add the caller's enrolment
	 *     context.
	 *   - `/cart` is per-identity in its entirety.
	 *   - `/cms/content` and `/cms/content/{slug}` add the caller's access
	 *     projection (SPEC-CMS-20260727 US-2.3).
	 *
	 * Without this bypass, member A loading an event page would populate a
	 * shared transient with their registration details, and the next visitor to
	 * that page inside the TTL would be served them.
	 *
	 * The bearer is resolved ONCE here and passed through in `$args`, because
	 * the resolver reads user meta and can trigger a token refresh; letting
	 * `request()` resolve it a second time would double that work.
	 *
	 * The cost is that signed-in members do not benefit from the shared cache
	 * on catalogue reads that would in fact have been identical for them. That
	 * is the correct trade: we cannot tell from here which endpoints vary by
	 * identity, and guessing wrong leaks one member's data to another.
	 *
	 * @param string $path      Relative path, e.g. `/directory/listings`.
	 * @param array  $args      Request args passed through to `request()`.
	 * @param string $cache_key Unique cache identifier (without the `agend_apps_` prefix).
	 * @param int    $ttl       Cache lifetime in seconds.
	 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
	 */
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
	 * Cache-key prefixes whose responses change faster than any useful TTL, so
	 * an identity-scoped entry would serve the same member a stale answer. A
	 * cart mutates on the visitor's own next click.
	 *
	 * @var string[]
	 */
	private const IDENTITY_UNCACHEABLE_PREFIXES = array( 'cart' );

	/**
	 * Suffix that binds a transient to one member, so an identity-attached
	 * response is never readable by another visitor.
	 *
	 * Derived from the bearer itself, never from the WordPress user id. The
	 * token is the identity the response was actually fetched under, and the
	 * two can diverge: a shared or generic WordPress login, or a bearer
	 * resolved for someone other than the current user, collapses distinct
	 * members onto one id and reinstates the leak this scoping exists to
	 * prevent. A refreshed token simply starts a new entry, which costs a
	 * fetch rather than correctness.
	 *
	 * The digest is truncated only for key length; it is never reversed and
	 * never leaves the options table.
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

	public function get_cached( string $path, array $args, string $cache_key, int $ttl ) {
		$bearer_token = isset( $args['bearer_token'] ) && '' !== $args['bearer_token']
			? (string) $args['bearer_token']
			: agend_apps_get_bearer_token();

		if ( '' !== $bearer_token ) {
			// Record whether the bearer was ours, so request() may drop it and
			// retry unattended when the gateway rejects it (401/403).
			$args['bearer_auto_resolved'] = empty( $args['bearer_token'] );
			$args['bearer_token']         = $bearer_token;

			if ( $this->is_identity_uncacheable( $cache_key ) ) {
				return $this->request( 'GET', $path, $args );
			}

			// Identity-scoped rather than bypassed: the original defect was
			// that the key carried the query but not the member, so one
			// member's response was served to the next visitor. Naming the
			// member in the key keeps that impossible while restoring a cache
			// for signed-in visitors, who otherwise pay a live round trip on
			// every page render.
			$cache_key .= $this->identity_cache_suffix( $bearer_token );
		}

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
