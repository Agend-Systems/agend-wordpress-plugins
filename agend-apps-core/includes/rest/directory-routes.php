<?php
/**
 * REST routes for directory endpoints.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the directory REST routes.
 *
 * Called from `rest_api_init` via the main plugin bootstrap.
 */
function agend_apps_register_directory_routes(): void {
	$controller = new Agend_Apps_Directory_REST_Controller();
	$controller->register_routes();
}

/** Place searches one visitor address may make through the geocode route per window. */
const AGEND_APPS_DIRECTORY_GEOCODE_PER_IP_LIMIT = 10;

/** The geocode route's per-address window, in seconds. */
const AGEND_APPS_DIRECTORY_GEOCODE_PER_IP_WINDOW = 60;

/**
 * Counts one place search against a visitor address, and says whether it may
 * go ahead.
 *
 * The window is fixed from the address's first search, so a visitor searching
 * now and then is never refused, and an address that used up its searches
 * waits for that window to close rather than for a quiet minute. Every request
 * counts, cached places included, so the route cannot be used to probe the
 * cache unbounded.
 *
 * The transient key holds wp_hash() of the address, never the address: the
 * hash is salted with the site's own keys, so the stored key cannot be turned
 * back into an address by trying every IPv4 address.
 *
 * Two concurrent requests can read the same count, so a burst can overshoot
 * the limit by the requests in flight. Transients offer no atomic increment.
 *
 * @param string $ip  The visitor's `REMOTE_ADDR`.
 * @param int    $now Unix time.
 * @return int 0 when the search may go ahead, otherwise the seconds until the
 *             address may search again.
 */
function agend_apps_directory_geocode_throttle( string $ip, int $now ): int {
	$key   = 'agend_apps_geocode_ip_' . wp_hash( $ip );
	$entry = get_transient( $key );

	if ( ! is_array( $entry ) || ! isset( $entry['count'], $entry['reset'] ) || (int) $entry['reset'] <= $now ) {
		$entry = array(
			'count' => 0,
			'reset' => $now + AGEND_APPS_DIRECTORY_GEOCODE_PER_IP_WINDOW,
		);
	}

	$remaining = max( 1, (int) $entry['reset'] - $now );

	if ( (int) $entry['count'] >= AGEND_APPS_DIRECTORY_GEOCODE_PER_IP_LIMIT ) {
		return $remaining;
	}

	$entry['count'] = (int) $entry['count'] + 1;
	set_transient( $key, $entry, $remaining );

	return 0;
}

/**
 * REST controller for directory endpoints.
 *
 * Read routes are publicly readable (no authentication required). Review
 * submission is a write and requires a valid WordPress nonce (`wp_rest`).
 * Listing create/update/delete and bulk upsert are intentionally NOT proxied:
 * they are administrative writes and are only available via the server-side
 * PHP functions in `includes/api/directory.php` and
 * `includes/api/directory-export-reports.php`.
 *
 * Exposes:
 * - `GET  /agend-apps/v1/directory/listings`                  — paginated listing index.
 * - `GET  /agend-apps/v1/directory/listings/{slugOrId}`       — single listing.
 * - `GET  /agend-apps/v1/directory/listings/{slugOrId}/reviews` — approved reviews for a listing.
 * - `GET  /agend-apps/v1/directory/categories`                — category list.
 * - `GET  /agend-apps/v1/directory/search`                    — search listings.
 * - `GET  /agend-apps/v1/directory/markers`                   — search results as map markers.
 * - `GET  /agend-apps/v1/directory/geocode`                   — resolve a place to a point.
 * - `GET  /agend-apps/v1/directory/map-settings`              — map defaults.
 * - `POST /agend-apps/v1/directory/reviews`                   — submit a listing review.
 * - `POST /agend-apps/v1/directory/listings/{slugOrId}/enquiries` (send an enquiry to a listing).
 */
class Agend_Apps_Directory_REST_Controller extends Agend_Apps_REST_Controller {

	/**
	 * Resource base for directory routes.
	 *
	 * @var string
	 */
	protected $rest_base = 'directory';

	/**
	 * Registers the REST routes for this controller.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/listings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_listings' ),
					'permission_callback' => '__return_true',
					'args'                => $this->listings_args(),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/listings/(?P<listing_id>[a-zA-Z0-9_-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_listing' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'listing_id' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'include'    => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/listings/(?P<listing_id>[a-zA-Z0-9_-]+)/reviews',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_listing_reviews' ),
					'permission_callback' => '__return_true',
					'args'                => $this->reviews_args(),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/categories',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_categories' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/facets',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_facets' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'fields' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/export-reports',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_export_reports' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						// Set by the widget just before a download, so the
						// parameters it applies come from the report's
						// current declaration rather than a listing an
						// author edited since the transient was cached.
						'fresh' => array(
							'type'              => 'boolean',
							'sanitize_callback' => 'rest_sanitize_boolean',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/export-reports/(?P<report_id>[a-zA-Z0-9_-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'run_export_report' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'format' => array(
							'type'              => 'string',
							'enum'              => array( 'csv', 'xlsx' ),
							'default'           => 'csv',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/search',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'search' ),
					'permission_callback' => '__return_true',
					'args'                => $this->search_args(),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/markers',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_markers' ),
					'permission_callback' => '__return_true',
					'args'                => $this->markers_args(),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/geocode',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'geocode' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'q' => array(
							'required'          => true,
							'type'              => 'string',
							'minLength'         => 1,
							'maxLength'         => 200,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/map-settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_map_settings' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/reviews',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'submit_review' ),
					'permission_callback' => array( $this, 'nonce_check' ),
					'args'                => array(
						'listing_id'     => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'rating'         => array(
							'required'          => true,
							'type'              => 'integer',
							'minimum'           => 1,
							'maximum'           => 5,
							'sanitize_callback' => 'absint',
						),
						// Optional at the proxy layer: a signed-in member's
						// reviewer identity is derived server-side from the
						// bearer (SPEC-CORE-20260722 US-2.2/US-2.6). The
						// gateway still rejects guest submissions without them.
						'reviewer_name'  => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'reviewer_email' => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_email',
						),
						'content'        => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/listings/(?P<listing_id>[a-zA-Z0-9_-]+)/enquiries',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'submit_enquiry' ),
					'permission_callback' => array( $this, 'enquiry_permission' ),
					'args'                => $this->enquiry_args(),
				),
			)
		);

		// Member self-service: the signed-in member's own listing
		// (SPEC-CORE-20260722 US-2.6). Identity is derived server-side from
		// the member bearer; no listing id is accepted from the browser.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/me/listing',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_my_listing' ),
					'permission_callback' => array( $this, 'nonce_check' ),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update_my_listing' ),
					'permission_callback' => array( $this, 'nonce_check' ),
				),
			)
		);
	}

	/**
	 * The arguments `POST /directory/listings/{slugOrId}/enquiries` accepts.
	 *
	 * Sanitised here, validated in {@see submit_enquiry()}. Nothing is marked
	 * `required` and no length is declared: WordPress would answer a miss with
	 * its own wording, and the form needs a friendly message per field.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function enquiry_args(): array {
		return array(
			'listing_id'     => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'first_name'     => array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_enquiry_line' ),
			),
			'last_name'      => array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_enquiry_line' ),
			),
			'email'          => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_email',
			),
			'phone'          => array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_enquiry_line' ),
			),
			'message'        => array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_enquiry_message' ),
			),
			'terms_accepted' => array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
			'captcha_token'  => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	/**
	 * Cleans a single-line enquiry field: invalid UTF-8 and control characters
	 * go, line breaks and tabs become spaces, and the ends are trimmed. Markup
	 * is left alone: the value travels as JSON and the gateway sends plain text
	 * email, so rewriting `<` or deleting `%ab` would only corrupt what the
	 * visitor typed.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_enquiry_line( $value ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', self::strip_invisible_characters( self::strip_unsafe_characters( $value ), true ) ) );
	}

	/**
	 * Cleans the enquiry message: as {@see sanitize_enquiry_line()}, but line
	 * breaks and tabs are kept.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_enquiry_message( $value ): string {
		return trim( str_replace( "\r\n", "\n", self::strip_invisible_characters( self::strip_unsafe_characters( $value ), false ) ) );
	}

	/**
	 * Removes invalid UTF-8 and control characters, keeping tabs and line breaks.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function strip_unsafe_characters( $value ): string {
		$text  = (string) $value;
		$clean = function_exists( 'iconv' ) ? @iconv( 'UTF-8', 'UTF-8//IGNORE', $text ) : $text; // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$text  = false === $clean ? '' : $clean;

		return (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text );
	}

	/**
	 * Removes characters that can make text display misleadingly in the
	 * member's mail client. A name loses every Unicode format character
	 * (bidi overrides, zero-width characters) and C1 control. A message loses
	 * C1 controls and the bidi overrides and isolates only, so a zero-width
	 * joiner inside an emoji sequence survives. Tabs and line breaks are kept.
	 *
	 * @param string $text      Text already passed through strip_unsafe_characters().
	 * @param bool   $is_name   Whether the strict class for names applies.
	 * @return string
	 */
	private static function strip_invisible_characters( string $text, bool $is_name ): string {
		$pattern = $is_name
			? '/(?![\t\n\r])[\p{Cc}\p{Cf}]/u'
			: '/(?![\t\n\r])\p{Cc}|[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

		return (string) preg_replace( $pattern, '', $text );
	}

	/**
	 * Sends a visitor's enquiry to the listing through the Agend gateway.
	 *
	 * WordPress sends no email: the gateway does, and answers with nothing
	 * about the listing's address. The order is field validation, captcha,
	 * then the gateway call, so a visitor who mistyped a field keeps a captcha
	 * token that was never spent.
	 *
	 * The response is `{ success: true }` or `{ code, message, fields? }` with
	 * a visitor-safe message. A gateway or provider error body is never passed
	 * through.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function submit_enquiry( WP_REST_Request $request ): WP_REST_Response {
		$enquiry = $this->enquiry_from_request( $request );
		$fields  = $this->enquiry_field_errors( $enquiry );

		if ( array() !== $fields ) {
			return $this->enquiry_error( 'validation_failed', 400, $fields );
		}

		$visitor_ip = $this->visitor_ip( $request );
		$captcha    = Agend_Apps_Captcha::verify( (string) $request->get_param( 'captcha_token' ), $visitor_ip );

		if ( is_wp_error( $captcha ) ) {
			$reason = is_array( $captcha->get_error_data() ) ? (string) ( $captcha->get_error_data()['reason'] ?? '' ) : '';

			$this->log_captcha_refusal( $reason );

			return 'not_configured' === $reason
				? $this->enquiry_error( 'send_failed', 503 )
				: $this->enquiry_error( 'captcha_failed', 400 );
		}

		$result = agend_apps_directory_send_listing_enquiry( (string) $request->get_param( 'listing_id' ), $enquiry, $visitor_ip );

		if ( is_wp_error( $result ) ) {
			return $this->enquiry_gateway_error( $result );
		}

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * Records why a captcha refused a submission, so an administrator can find
	 * a configuration cause (a hostname not on the allow-list, a threshold set
	 * too high) that the visitor's message cannot name. Only the reason code is
	 * recorded: never the token, the secret or anything the visitor typed.
	 *
	 * @param string $reason Reason code from {@see Agend_Apps_Captcha::verify()}.
	 */
	private function log_captcha_refusal( string $reason ): void {
		if ( ! function_exists( 'agend_apps_logger' ) ) {
			return;
		}

		agend_apps_logger()->record(
			array(
				'source'       => 'agend-apps-core',
				'direction'    => 'inbound',
				'method'       => 'POST',
				'url'          => rest_url( $this->namespace . '/' . $this->rest_base . '/listings/enquiries' ),
				'status'       => 'not_configured' === $reason ? 503 : 400,
				'outcome'      => 'captcha_refused',
				'auth_mode'    => 'nonce',
				'store_bodies' => false,
				'error_code'   => '' === $reason ? 'unknown' : $reason,
				'error_message' => 'Captcha refused an enquiry: ' . ( '' === $reason ? 'unknown' : $reason ),
				'user_id'      => 0,
			)
		);
	}

	/**
	 * The enquiry payload the gateway expects, from the sanitised request.
	 *
	 * `phone` is left out when blank, and `terms_accepted` is sent only when
	 * the visitor ticked the box.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return array<string, mixed>
	 */
	protected function enquiry_from_request( WP_REST_Request $request ): array {
		$enquiry = array(
			'first_name' => trim( (string) $request->get_param( 'first_name' ) ),
			'last_name'  => trim( (string) $request->get_param( 'last_name' ) ),
			'email'      => trim( (string) $request->get_param( 'email' ) ),
			'message'    => trim( (string) $request->get_param( 'message' ) ),
		);

		$phone = trim( (string) $request->get_param( 'phone' ) );
		if ( '' !== $phone ) {
			$enquiry['phone'] = $phone;
		}

		if ( rest_sanitize_boolean( $request->get_param( 'terms_accepted' ) ) ) {
			$enquiry['terms_accepted'] = true;
		}

		return $enquiry;
	}

	/**
	 * Checks an enquiry against the gateway's limits, so a visitor hears about
	 * a bad field before any captcha token or rate limit is spent.
	 *
	 * @param array<string, mixed> $enquiry Payload from {@see enquiry_from_request()}.
	 * @return array<string, string> Visitor-safe message per failing field. Empty when valid.
	 */
	protected function enquiry_field_errors( array $enquiry ): array {
		$errors = array();

		foreach ( array(
			'first_name' => __( 'Please enter your first name.', 'agend-apps-core' ),
			'last_name'  => __( 'Please enter your last name.', 'agend-apps-core' ),
		) as $field => $missing ) {
			$length = $this->text_length( (string) $enquiry[ $field ] );

			if ( 0 === $length ) {
				$errors[ $field ] = $missing;
			} elseif ( $length > 100 ) {
				$errors[ $field ] = __( 'Please use 100 characters or fewer.', 'agend-apps-core' );
			}
		}

		$email = (string) $enquiry['email'];
		if ( '' === $email || strlen( $email ) > 254 || ! is_email( $email ) ) {
			$errors['email'] = __( 'Please enter a valid email address.', 'agend-apps-core' );
		}

		if ( isset( $enquiry['phone'] ) && $this->text_length( (string) $enquiry['phone'] ) > 40 ) {
			$errors['phone'] = __( 'Please use 40 characters or fewer.', 'agend-apps-core' );
		}

		$message_length = $this->text_length( (string) $enquiry['message'] );
		if ( $message_length < 10 ) {
			$errors['message'] = __( 'Please write a message of at least 10 characters.', 'agend-apps-core' );
		} elseif ( $message_length > 5000 ) {
			$errors['message'] = __( 'Please keep your message to 5,000 characters or fewer.', 'agend-apps-core' );
		}

		return $errors;
	}

	/**
	 * Length in UTF-16 code units, which is how the gateway's schema counts, so
	 * a message full of emoji is refused here when the gateway would refuse it.
	 * Not bytes: a name with accents is not penalised.
	 *
	 * @param string $text Text to measure.
	 * @return int
	 */
	private function text_length( string $text ): int {
		if ( function_exists( 'mb_convert_encoding' ) ) {
			return (int) ( strlen( mb_convert_encoding( $text, 'UTF-16LE', 'UTF-8' ) ) / 2 );
		}

		return strlen( $text );
	}

	/**
	 * The visitor's IP address as the gateway should rate limit it.
	 *
	 * `REMOTE_ADDR` only. A forwarded-for header is attacker-controlled unless
	 * a trusted proxy sets it, so a site behind one opts in through the
	 * `agend_apps_visitor_ip` filter.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return string A valid IP address, or '' when none can be established.
	 */
	protected function visitor_ip( WP_REST_Request $request ): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		/**
		 * Filters the visitor IP sent with an enquiry for rate limiting.
		 *
		 * Return the real client address on a site behind a trusted proxy.
		 *
		 * @param string          $ip      `REMOTE_ADDR`, or ''.
		 * @param WP_REST_Request $request Current request.
		 */
		$ip = (string) apply_filters( 'agend_apps_visitor_ip', $ip, $request );

		return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Turns a gateway failure into a visitor-safe enquiry error.
	 *
	 * Reads the gateway's error `code` from the standard error envelope and
	 * falls back to the HTTP status, so it keeps working if the gateway picks
	 * a different conventional code. Anything unrecognised is the generic
	 * failure: the gateway's own wording is never shown.
	 *
	 * @param WP_Error $error The client's error.
	 * @return WP_REST_Response
	 */
	protected function enquiry_gateway_error( WP_Error $error ): WP_REST_Response {
		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status_code'] ) ? (int) $data['status_code'] : 0;
		$code   = '';

		if ( is_array( $data ) && isset( $data['body']['error']['code'] ) && is_scalar( $data['body']['error']['code'] ) ) {
			$code = strtoupper( (string) $data['body']['error']['code'] );
		}

		if ( 'LISTING_NOT_ACCEPTING_ENQUIRIES' === $code ) {
			return $this->enquiry_error( 'not_accepting', 409 );
		}

		if ( 'TERMS_REQUIRED' === $code ) {
			return $this->enquiry_error( 'terms_required', 422 );
		}

		// A local short-circuit comes from other traffic on the site, not from
		// this visitor, so it is not reported as their rate limit.
		if ( 'agend_apps_rate_limited' === $error->get_error_code() ) {
			return $this->enquiry_error( 'send_failed', 502 );
		}

		if ( 429 === $status || 'RATE_LIMIT_EXCEEDED' === $code || 'RATE_LIMITED' === $code ) {
			return $this->enquiry_error( 'rate_limited', 429 );
		}

		if ( 404 === $status || 'NOT_FOUND' === $code ) {
			return $this->enquiry_error( 'not_found', 404 );
		}

		if ( false !== strpos( $code, 'VALIDATION' ) || ( '' === $code && in_array( $status, array( 400, 422 ), true ) ) ) {
			$fields = $this->gateway_field_errors( is_array( $data ) ? $data['body']['error']['details']['fields'] ?? null : null );

			// A validation error naming no field we know is the generic failure:
			// "check the highlighted fields" with nothing highlighted helps no one.
			return array() === $fields
				? $this->enquiry_error( 'send_failed', 502 )
				: $this->enquiry_error( 'validation_failed', 400, $fields );
		}

		return $this->enquiry_error( 'send_failed', 502 );
	}

	/**
	 * The route's own per-field messages for the fields the gateway named.
	 *
	 * The gateway's `details.fields` is a map of field name to messages, or a
	 * list of field names. Only the names are used; its wording never reaches
	 * the visitor.
	 *
	 * @param mixed $details The gateway's `details.fields`.
	 * @return array<string, string>
	 */
	protected function gateway_field_errors( $details ): array {
		if ( ! is_array( $details ) ) {
			return array();
		}

		$messages = array(
			'first_name'     => __( 'Please check your first name.', 'agend-apps-core' ),
			'last_name'      => __( 'Please check your last name.', 'agend-apps-core' ),
			'email'          => __( 'Please enter a valid email address.', 'agend-apps-core' ),
			'phone'          => __( 'Please check your phone number.', 'agend-apps-core' ),
			'message'        => __( 'Please check your message.', 'agend-apps-core' ),
			'terms_accepted' => __( 'Please accept the terms to send your enquiry.', 'agend-apps-core' ),
		);

		$names  = array_keys( $details ) === range( 0, count( $details ) - 1 ) ? array_values( $details ) : array_keys( $details );
		$fields = array();

		foreach ( $names as $name ) {
			if ( is_string( $name ) && isset( $messages[ $name ] ) ) {
				$fields[ $name ] = $messages[ $name ];
			}
		}

		return $fields;
	}

	/**
	 * A visitor-safe enquiry error response.
	 *
	 * @param string               $code   Stable code the widget keys off.
	 * @param int                  $status HTTP status.
	 * @param array<string,string> $fields Per-field messages, when the failure is about fields.
	 * @return WP_REST_Response
	 */
	protected function enquiry_error( string $code, int $status, array $fields = array() ): WP_REST_Response {
		$messages = array(
			'validation_failed' => __( 'Please check the highlighted fields and try again.', 'agend-apps-core' ),
			'captcha_failed'    => __( 'We could not confirm you are not a robot. Please try again.', 'agend-apps-core' ),
			'not_accepting'     => __( 'This listing is not accepting enquiries right now.', 'agend-apps-core' ),
			'terms_required'    => __( 'Please accept the terms to send your enquiry.', 'agend-apps-core' ),
			'rate_limited'      => __( 'You have sent several enquiries recently. Please wait a few minutes and try again.', 'agend-apps-core' ),
			'not_found'         => __( 'We could not find this listing.', 'agend-apps-core' ),
			'send_failed'       => __( 'Sorry, we could not send your enquiry right now. Please try again later.', 'agend-apps-core' ),
		);

		$body = array(
			'success' => false,
			'code'    => $code,
			'message' => $messages[ $code ] ?? $messages['send_failed'],
		);

		if ( array() !== $fields ) {
			$body['fields'] = $fields;
		}

		return new WP_REST_Response( $body, $status );
	}

	/**
	 * Permission check for the enquiry route: the same nonce check as the other
	 * writes, but a stale or missing nonce answers with this route's own code,
	 * so the form can tell the visitor to reload instead of showing core's
	 * wording. A page served from a full-page cache outlives its nonce.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return true|WP_Error
	 */
	public function enquiry_permission( WP_REST_Request $request ) {
		if ( $this->nonce_check( $request ) ) {
			return true;
		}

		return new WP_Error(
			'agend_enquiry_session_expired',
			__( 'Your session has expired. Please reload the page and try again.', 'agend-apps-core' ),
			array( 'status' => 403 )
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
	 * Returns a paginated list of directory listings.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function get_listings( WP_REST_Request $request ): WP_REST_Response {
		$allowed = array( 'page', 'per_page', 'category' );
		$query   = array_filter(
			$request->get_params(),
			function ( $key ) use ( $allowed ) {
				return in_array( $key, $allowed, true );
			},
			ARRAY_FILTER_USE_KEY
		);

		$result = agend_apps_directory_get_listings( $query );
		return $this->prepare_api_response( $result );
	}

	/**
	 * Returns a single directory listing.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function get_listing( WP_REST_Request $request ): WP_REST_Response {
		$listing_id = $request->get_param( 'listing_id' );

		$query   = array();
		$include = (string) $request->get_param( 'include' );
		if ( '' !== $include ) {
			$query['include'] = $include;
		}

		$result = agend_apps_directory_get_listing( $listing_id, $query );
		return $this->prepare_api_response( $result );
	}

	/**
	 * Returns all directory categories.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function get_categories( WP_REST_Request $request ): WP_REST_Response {
		$result = agend_apps_directory_get_categories();
		return $this->prepare_api_response( $result );
	}

	/**
	 * Returns the signed-in member's own listing (or null).
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function get_my_listing( WP_REST_Request $request ): WP_REST_Response {
		if ( 0 === get_current_user_id() ) {
			return new WP_REST_Response( array( 'data' => null ), 200 );
		}

		$result = agend_apps_directory_get_my_listing();
		return $this->prepare_api_response( $result );
	}

	/**
	 * Updates the signed-in member's own listing.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function update_my_listing( WP_REST_Request $request ): WP_REST_Response {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = array();
		}

		$result = agend_apps_directory_update_my_listing( $body );
		return $this->prepare_api_response( $result );
	}

	/**
	 * The query arguments `GET /directory/search` accepts, shared with
	 * `GET /directory/markers` so a map asks exactly the question its
	 * catalogue asked.
	 *
	 * @return array
	 */
	private function search_args(): array {
		return array(
			'q'                 => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'search'            => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'page'              => self::bounded_integer_arg( 1 ),
			'limit'             => self::bounded_integer_arg( 1, 100 ),
			'per_page'          => self::bounded_integer_arg( 1, 100 ),
			'category'          => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'rating'            => array(
				'type'              => 'number',
				'minimum'           => 1,
				'maximum'           => 5,
				'sanitize_callback' => 'rest_sanitize_request_arg',
			),
			'featured'          => array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
			'sortBy'            => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'sortOrder'         => array(
				'type'              => 'string',
				'enum'              => array( 'asc', 'desc' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'excludeCategories' => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			// Comma-separated ids, forwarded verbatim: the gateway
			// search decoder splits them itself.
			'tag_ids'           => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'badge_ids'         => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			// Comma-separated location values; the gateway splits them
			// itself and rejects a bracket-encoded array with a 422.
			'city'              => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'state'             => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'postcode'          => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'country'           => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			// One free-text value matched against the names of a listing's
			// locations. Never comma-split, so it is forwarded as typed.
			'locationName'      => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'sponsor_level'     => array(
				'type'              => 'integer',
				'minimum'           => 0,
				'maximum'           => 3,
				'sanitize_callback' => 'absint',
			),
			// Proximity search: the gateway applies it only when all
			// three arrive together. Sanitised by WordPress's own schema
			// sanitiser (a float for `number`): PHP's built-in floatval()
			// rejects the three arguments WordPress passes a sanitise
			// callback, so it fatalled every request carrying one of these.
			'lat'               => array(
				'type'              => 'number',
				'minimum'           => -90,
				'maximum'           => 90,
				'sanitize_callback' => 'rest_sanitize_request_arg',
			),
			'lng'               => array(
				'type'              => 'number',
				'minimum'           => -180,
				'maximum'           => 180,
				'sanitize_callback' => 'rest_sanitize_request_arg',
			),
			'radius'            => array(
				'type'              => 'number',
				'minimum'           => 1,
				'maximum'           => 1000,
				'sanitize_callback' => 'rest_sanitize_request_arg',
			),
			// A map viewport, north,south,east,west in degrees. The gateway rejects
			// it alongside lat/lng/radius, so a caller sends one or the other.
			'bbox'              => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	/**
	 * The query arguments `GET /directory/markers` accepts: every search
	 * argument, with a page size up to the gateway's 2000 marker cap, plus
	 * `projection` (`points` for id, name and coordinates only, `markers`
	 * for the full map marker) and `ids` (up to 100 comma-separated listing
	 * ids). A value of either outside that is dropped rather than forwarded,
	 * so the gateway only ever sees one the map itself would send.
	 *
	 * @return array
	 */
	private function markers_args(): array {
		$args = $this->search_args();
		unset( $args['page'], $args['per_page'], $args['sortBy'], $args['sortOrder'] );
		$args['limit']      = self::bounded_integer_arg( 1, 2000 );
		$args['projection'] = array(
			'type'              => 'string',
			'sanitize_callback' => static function ( $value ): string {
				return is_string( $value ) && in_array( $value, array( 'points', 'markers' ), true ) ? $value : '';
			},
		);
		$args['ids']        = array(
			'type'              => 'string',
			'sanitize_callback' => static function ( $value ): string {
				if ( ! is_string( $value ) || '' === $value ) {
					return '';
				}
				$ids = explode( ',', $value );
				if ( count( $ids ) > 100 ) {
					return '';
				}
				foreach ( $ids as $id ) {
					if ( 1 !== preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id ) ) {
						return '';
					}
				}
				return $value;
			},
		);
		return $args;
	}

	/**
	 * The query arguments `GET /directory/listings` accepts.
	 *
	 * @return array
	 */
	private function listings_args(): array {
		return array(
			'page'     => self::bounded_integer_arg( 1 ),
			'per_page' => self::bounded_integer_arg( 1, 100 ),
			'category' => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	/**
	 * The arguments `GET /directory/listings/{slugOrId}/reviews` accepts.
	 *
	 * @return array
	 */
	private function reviews_args(): array {
		return array(
			'listing_id' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'page'       => self::bounded_integer_arg( 1 ),
			'limit'      => self::bounded_integer_arg( 1, 100 ),
		);
	}

	/**
	 * An integer argument whose declared bounds are enforced.
	 *
	 * WordPress checks `minimum` and `maximum` only for an argument with no
	 * sanitize callback of its own, so the callback applies them itself. A
	 * value outside the range is moved to the nearest bound rather than
	 * refused, so a request asking for a larger page still gets a page.
	 *
	 * @param int      $minimum Smallest accepted value.
	 * @param int|null $maximum Largest accepted value, or null for no upper bound.
	 * @return array
	 */
	private static function bounded_integer_arg( int $minimum, ?int $maximum = null ): array {
		$arg = array(
			'type'              => 'integer',
			'minimum'           => $minimum,
			'sanitize_callback' => static function ( $value ) use ( $minimum, $maximum ): int {
				$value = max( $minimum, (int) $value );
				return null === $maximum ? $value : min( $maximum, $value );
			},
		);
		if ( null !== $maximum ) {
			$arg['maximum'] = $maximum;
		}
		return $arg;
	}

	/**
	 * Searches directory listings.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function search( WP_REST_Request $request ): WP_REST_Response {
		$search_query = (string) ( $request->get_param( 'q' ) ?? $request->get_param( 'search' ) ?? '' );

		$result = agend_apps_directory_search( $search_query, $this->search_filters( $request ) );
		return $this->prepare_api_response( $result );
	}

	/**
	 * The catalogue filters a search or markers request forwards to the
	 * gateway, with `category` translated to the canonical `category_ids`.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return array
	 */
	private function search_filters( WP_REST_Request $request ): array {
		// Forward the widget's catalogue filters to the gateway search. `rating`,
		// `featured`, `sortBy`, `sortOrder`, and `excludeCategories` are accepted
		// verbatim by the gateway GET decoder; `category` is translated to the
		// canonical `category_ids`.
		$filter_keys = array(
			'page',
			'limit',
			'per_page',
			'category',
			'tag_ids',
			'badge_ids',
			'city',
			'state',
			'postcode',
			'country',
			'locationName',
			'sponsor_level',
			'lat',
			'lng',
			'radius',
			'rating',
			'featured',
			'sortBy',
			'sortOrder',
			'excludeCategories',
			// Bracket notation arrives already parsed into a nested array by
			// PHP, and add_query_arg() re-encodes it the same way for the
			// gateway: custom_fields[key]=a,b and custom_fields[key][min]=5.
			'custom_fields',
			'bbox',
		);
		$filters     = array_filter(
			$request->get_params(),
			function ( $key ) use ( $filter_keys ) {
				return in_array( $key, $filter_keys, true );
			},
			ARRAY_FILTER_USE_KEY
		);

		// The gateway filters search results by `category_ids` (canonical),
		// not the legacy single `category` param. Translate it here so the
		// public REST contract stays stable while the upstream call is current.
		if ( isset( $filters['category'] ) ) {
			$filters['category_ids'] = $filters['category'];
			unset( $filters['category'] );
		}

		return $filters;
	}

	/**
	 * Returns the listings matching a search as map markers.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function get_markers( WP_REST_Request $request ): WP_REST_Response {
		$search_query = (string) ( $request->get_param( 'q' ) ?? $request->get_param( 'search' ) ?? '' );

		$filters = $this->search_filters( $request );
		// Paging and ordering mean nothing to a set of pins.
		unset( $filters['page'], $filters['per_page'], $filters['sortBy'], $filters['sortOrder'] );

		foreach ( array( 'projection', 'ids' ) as $key ) {
			$value = $request->get_param( $key );
			if ( is_string( $value ) && '' !== $value ) {
				$filters[ $key ] = $value;
			}
		}

		return $this->prepare_api_response( agend_apps_directory_get_markers( $search_query, $filters ) );
	}

	/**
	 * Resolves an address, suburb or postcode to a point.
	 *
	 * The route is open to anyone, so an address over its place search limit
	 * is refused here, before the gateway is called, and cannot spend the
	 * site's key or the place search budget the gateway shares between sites.
	 * The refusal is the error envelope the Location filter reads, with
	 * `data.status_code` 429, which it already shows as busy.
	 *
	 * Keyed on `REMOTE_ADDR` alone, as the login limits are, and deliberately
	 * not through the `agend_apps_visitor_ip` filter: a limit keyed on a
	 * forwarded header is escaped by varying the header wherever no trusted
	 * proxy sets it.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function geocode( WP_REST_Request $request ): WP_REST_Response {
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$retry = agend_apps_directory_geocode_throttle( $ip, time() );

		if ( $retry > 0 ) {
			$response = $this->error_to_response(
				new WP_Error(
					'agend_apps_geocode_rate_limited',
					__( 'Place search is busy. Please try again in a moment.', 'agend-apps-core' ),
					array( 'status_code' => 429 )
				)
			);
			$response->header( 'Retry-After', (string) $retry );
			// The refusal belongs to one address; a shared cache must not
			// replay it to other visitors searching the same place.
			$response->header( 'Cache-Control', 'no-store' );

			return $response;
		}

		return $this->prepare_api_response( agend_apps_directory_geocode( (string) $request->get_param( 'q' ) ) );
	}

	/**
	 * Returns the directory's map defaults.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function get_map_settings( WP_REST_Request $request ): WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- REST callback signature.
		return $this->prepare_api_response( agend_apps_directory_get_map_settings() );
	}

	/**
	 * Returns the export reports the current visitor may run.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function get_export_reports( WP_REST_Request $request ): WP_REST_Response {
		$fresh = rest_sanitize_boolean( $request->get_param( 'fresh' ) );
		return $this->prepare_api_response( agend_apps_directory_get_export_reports( $fresh ) );
	}

	/**
	 * Runs an export report and streams the file back to the browser.
	 *
	 * The gateway returns the file's bytes, so this echoes them with their own
	 * content headers rather than wrapping them in a JSON envelope. The
	 * browser never talks to the gateway directly: the API key and the
	 * member's bearer stay server-side, and the visitor's own reachable report
	 * set is what decides whether this returns anything at all.
	 *
	 * Every parameter except the reserved ones is forwarded as a report
	 * parameter; the gateway rejects a name the report does not declare.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response (errors only; success exits after streaming).
	 */
	public function run_export_report( WP_REST_Request $request ) {
		$report_id = (string) $request->get_param( 'report_id' );
		$format    = (string) ( $request->get_param( 'format' ) ?? 'csv' );

		$reserved   = array( 'report_id', 'format', '_wpnonce', '_locale', 'rest_route' );
		$parameters = array();
		foreach ( $request->get_params() as $name => $value ) {
			if ( in_array( $name, $reserved, true ) || is_array( $value ) ) {
				continue;
			}
			$parameters[ $name ] = sanitize_text_field( (string) $value );
		}

		$result = agend_apps_directory_run_export_report( $report_id, $parameters, $format );

		if ( is_wp_error( $result ) ) {
			$forwarded = agend_apps_directory_export_report_error_envelope( $result );

			// Forwarded verbatim when the gateway explained itself, so the
			// visitor reads why rather than a generic failure. Anything else,
			// including a gateway that predates the explanation, falls through
			// to the ordinary error translation.
			if ( null !== $forwarded ) {
				return new WP_REST_Response( $forwarded['body'], $forwarded['status'] );
			}

			return $this->prepare_api_response( $result );
		}

		$content_type = ! empty( $result['content_type'] )
			? (string) $result['content_type']
			: 'text/csv; charset=utf-8';
		$disposition  = ! empty( $result['content_disposition'] )
			? (string) $result['content_disposition']
			: 'attachment; filename="' . sanitize_file_name( $report_id ) . '.' . ( 'xlsx' === $format ? 'xlsx' : 'csv' ) . '"';

		header( 'Content-Type: ' . $content_type );
		header( 'Content-Disposition: ' . $disposition );
		// An export reflects the caller's own entitlements, so it must never
		// be held by a shared cache.
		header( 'Cache-Control: private, no-store' );
		echo (string) $result['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw file passthrough; not HTML.
		exit;
	}

	/**
	 * Returns the directory's filterable facets and their values.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function get_facets( WP_REST_Request $request ): WP_REST_Response {
		$fields = (string) ( $request->get_param( 'fields' ) ?? '' );
		$result = agend_apps_directory_get_facets(
			'' === $fields ? array() : explode( ',', $fields )
		);
		return $this->prepare_api_response( $result );
	}

	/**
	 * Returns the approved reviews for a single directory listing.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function get_listing_reviews( WP_REST_Request $request ): WP_REST_Response {
		$listing_id = $request->get_param( 'listing_id' );

		$query_keys = array( 'page', 'limit' );
		$query      = array_filter(
			$request->get_params(),
			function ( $key ) use ( $query_keys ) {
				return in_array( $key, $query_keys, true );
			},
			ARRAY_FILTER_USE_KEY
		);

		$result = agend_apps_directory_get_listing_reviews( $listing_id, $query );
		return $this->prepare_api_response( $result );
	}

	/**
	 * Submits a review for a directory listing.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function submit_review( WP_REST_Request $request ): WP_REST_Response {
		$review = array(
			'listing_id'     => $request->get_param( 'listing_id' ),
			'rating'         => (int) $request->get_param( 'rating' ),
			'reviewer_name'  => $request->get_param( 'reviewer_name' ),
			'reviewer_email' => $request->get_param( 'reviewer_email' ),
			'content'        => $request->get_param( 'content' ),
		);

		$result = agend_apps_directory_submit_review( $review );
		return $this->prepare_api_response( $result );
	}
}
