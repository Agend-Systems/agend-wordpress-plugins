<?php
/**
 * REST routes for events endpoints.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the events REST routes.
 *
 * Called from `rest_api_init` via the main plugin bootstrap.
 */
function agend_apps_register_events_routes(): void {
	$controller = new Agend_Apps_Events_REST_Controller();
	$controller->register_routes();
}

/**
 * REST controller for events endpoints.
 *
 * Public read routes are openly readable (no authentication required). Member
 * routes and buyer writes require a valid WordPress nonce (`wp_rest`).
 * Admin writes (create/update/delete event, category, venue, ticket) and
 * registration management are intentionally NOT proxied: they are administrative
 * operations and are only available via the server-side PHP functions in
 * `includes/api/events.php`.
 *
 * Exposes:
 * - `GET  /agend-apps/v1/events`                   — events list.
 * - `GET  /agend-apps/v1/events/categories`        — categories list.
 * - `GET  /agend-apps/v1/events/venues`            — venues list.
 * - `GET  /agend-apps/v1/events/embed`             — embed feed.
 * - `GET  /agend-apps/v1/events/my-tickets`        — member tickets (nonce).
 * - `GET  /agend-apps/v1/events/my-purchases`      — member purchases (nonce).
 * - `GET  /agend-apps/v1/events/{slug}`            — single event.
 * - `GET  /agend-apps/v1/events/{slug}/tickets`    — event tickets.
 * - `POST /agend-apps/v1/events/{slug}/register`   — register attendee (nonce).
 * - `POST /agend-apps/v1/events/{slug}/waitlist`   — join waitlist (nonce).
 */
class Agend_Apps_Events_REST_Controller extends Agend_Apps_REST_Controller {

	/**
	 * Resource base for events routes.
	 *
	 * @var string
	 */
	protected $rest_base = 'events';

	/**
	 * Registers the REST routes for this controller.
	 */
	public function register_routes(): void {
		// Static routes registered first (before dynamic /{slug} route).

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_events' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'page'     => array(
							'type'              => 'integer',
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
						),
						'limit'    => array(
							'type'              => 'integer',
							'minimum'           => 1,
							'maximum'           => 100,
							'sanitize_callback' => 'absint',
						),
						'search'   => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'category' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'type'     => array(
							'type'              => 'string',
							'enum'              => array( 'physical', 'virtual', 'hybrid' ),
							'sanitize_callback' => 'sanitize_text_field',
						),
						'city'     => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'timeframe' => array(
							'type'              => 'string',
							'enum'              => array( 'upcoming', 'past', 'all' ),
							'sanitize_callback' => 'sanitize_text_field',
						),
						'sortBy'   => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'sortOrder' => array(
							'type'              => 'string',
							'enum'              => array( 'asc', 'desc' ),
							'sanitize_callback' => 'sanitize_text_field',
						),
						'excludeCategories' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'excludeTags' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'excludeVenueTypes' => array(
							'type'  => 'array',
							'items' => array(
								'type' => 'string',
								'enum' => array( 'physical', 'virtual', 'hybrid' ),
							),
						),
						'excludeCities' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
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
			'/' . $this->rest_base . '/venues',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_venues' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'page'  => array(
							'type'              => 'integer',
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
						),
						'limit' => array(
							'type'              => 'integer',
							'minimum'           => 1,
							'maximum'           => 100,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/embed',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_embed' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'page'  => array(
							'type'              => 'integer',
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
						),
						'limit' => array(
							'type'              => 'integer',
							'minimum'           => 1,
							'maximum'           => 100,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/my-tickets',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_my_tickets' ),
					'permission_callback' => array( $this, 'nonce_check' ),
					'args'                => array(
						'page'  => array(
							'type'              => 'integer',
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
						),
						'limit' => array(
							'type'              => 'integer',
							'minimum'           => 1,
							'maximum'           => 100,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/my-purchases',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_my_purchases' ),
					'permission_callback' => array( $this, 'nonce_check' ),
					'args'                => array(
						'page'  => array(
							'type'              => 'integer',
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
						),
						'limit' => array(
							'type'              => 'integer',
							'minimum'           => 1,
							'maximum'           => 100,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/registrations/pay',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'pay_registrations' ),
					'permission_callback' => array( $this, 'nonce_check' ),
				),
			)
		);

		// Dynamic routes registered after static routes.

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<slug>[a-zA-Z0-9_-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_event' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'slug' => array(
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
			'/' . $this->rest_base . '/(?P<slug>[a-zA-Z0-9_-]+)/tickets',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_tickets' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'slug' => array(
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
			'/' . $this->rest_base . '/(?P<slug>[a-zA-Z0-9_-]+)/ical',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_ical' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'slug' => array(
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
			'/' . $this->rest_base . '/(?P<slug>[a-zA-Z0-9_-]+)/register',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'register' ),
					'permission_callback' => array( $this, 'nonce_check' ),
					'args'                => array(
						'slug' => array(
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
			'/' . $this->rest_base . '/(?P<slug>[a-zA-Z0-9_-]+)/waitlist',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'join_waitlist' ),
					'permission_callback' => array( $this, 'nonce_check' ),
					'args'                => array(
						'slug' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
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
	 * Returns a paginated list of events.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function get_events( WP_REST_Request $request ): WP_REST_Response {
		$allowed = array( 'page', 'limit', 'search', 'category', 'type', 'city', 'timeframe', 'sortBy', 'sortOrder', 'excludeCategories', 'excludeTags', 'excludeVenueTypes', 'excludeCities' );
		$query   = array_filter(
			$request->get_params(),
			function ( $key ) use ( $allowed ) {
				return in_array( $key, $allowed, true );
			},
			ARRAY_FILTER_USE_KEY
		);

		$result = agend_apps_events_get_events( $query );
		return $this->prepare_api_response( $result );
	}

	/**
	 * Returns all event categories.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function get_categories( WP_REST_Request $request ): WP_REST_Response {
		$result = agend_apps_events_get_categories();
		return $this->prepare_api_response( $result );
	}

	/**
	 * Returns a paginated list of venues.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function get_venues( WP_REST_Request $request ): WP_REST_Response {
		$allowed = array( 'page', 'limit' );
		$query   = array_filter(
			$request->get_params(),
			function ( $key ) use ( $allowed ) {
				return in_array( $key, $allowed, true );
			},
			ARRAY_FILTER_USE_KEY
		);

		$result = agend_apps_events_get_venues( $query );
		return $this->prepare_api_response( $result );
	}

	/**
	 * Returns the embeddable events feed.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function get_embed( WP_REST_Request $request ): WP_REST_Response {
		$allowed = array( 'page', 'limit' );
		$query   = array_filter(
			$request->get_params(),
			function ( $key ) use ( $allowed ) {
				return in_array( $key, $allowed, true );
			},
			ARRAY_FILTER_USE_KEY
		);

		$result = agend_apps_events_get_embed( $query );
		return $this->prepare_api_response( $result );
	}

	/**
	 * Returns the current member's event tickets.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function get_my_tickets( WP_REST_Request $request ): WP_REST_Response {
		$allowed = array( 'page', 'limit' );
		$query   = array_filter(
			$request->get_params(),
			function ( $key ) use ( $allowed ) {
				return in_array( $key, $allowed, true );
			},
			ARRAY_FILTER_USE_KEY
		);

		$result = agend_apps_events_get_my_tickets( $query );
		return $this->prepare_api_response( $result );
	}

	/**
	 * Returns the current member's event purchases.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function get_my_purchases( WP_REST_Request $request ): WP_REST_Response {
		$allowed = array( 'page', 'limit' );
		$query   = array_filter(
			$request->get_params(),
			function ( $key ) use ( $allowed ) {
				return in_array( $key, $allowed, true );
			},
			ARRAY_FILTER_USE_KEY
		);

		$result = agend_apps_events_get_my_purchases( $query );
		return $this->prepare_api_response( $result );
	}

	/**
	 * Returns a single event by slug.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function get_event( WP_REST_Request $request ): WP_REST_Response {
		$slug    = $request->get_param( 'slug' );
		$include = sanitize_text_field( (string) $request->get_param( 'include' ) );
		$query   = '' !== $include ? array( 'include' => $include ) : array();
		$result  = agend_apps_events_get_event( $slug, $query );
		return $this->prepare_api_response( $result );
	}

	/**
	 * Returns the tickets for an event.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function get_tickets( WP_REST_Request $request ): WP_REST_Response {
		$slug   = $request->get_param( 'slug' );
		$result = agend_apps_events_get_tickets( $slug );
		return $this->prepare_api_response( $result );
	}

	/**
	 * Registers an attendee for an event.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function register( WP_REST_Request $request ): WP_REST_Response {
		$slug         = $request->get_param( 'slug' );
		$registration = $request->get_json_params();
		$result       = agend_apps_events_register( $slug, $registration );
		return $this->prepare_api_response( $result );
	}

	/**
	 * Joins the waitlist for an event.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function join_waitlist( WP_REST_Request $request ): WP_REST_Response {
		$slug    = $request->get_param( 'slug' );
		$payload = $request->get_json_params();
		$result  = agend_apps_events_join_waitlist( $slug, $payload );
		return $this->prepare_api_response( $result );
	}

	/**
	 * Opens a checkout session for one or more pending registrations.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function pay_registrations( WP_REST_Request $request ): WP_REST_Response {
		$payload = $request->get_json_params();
		$result  = agend_apps_events_pay_registration( is_array( $payload ) ? $payload : array() );
		return $this->prepare_api_response( $result );
	}

	/**
	 * Streams an event's iCal (.ics) file to the browser.
	 *
	 * Serves the raw ICS text with its calendar content type and attachment
	 * disposition, bypassing the REST JSON serialiser (a calendar file must
	 * not be JSON-encoded). Errors fall through to the normal JSON error
	 * response.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response (errors only; success exits after streaming).
	 */
	public function get_ical( WP_REST_Request $request ) {
		$slug   = $request->get_param( 'slug' );
		$result = agend_apps_events_get_event_ical( $slug );

		if ( is_wp_error( $result ) ) {
			return $this->prepare_api_response( $result );
		}

		$content_type = ! empty( $result['content_type'] )
			? (string) $result['content_type']
			: 'text/calendar; charset=utf-8';
		$disposition  = ! empty( $result['content_disposition'] )
			? (string) $result['content_disposition']
			: 'attachment; filename="' . sanitize_file_name( $slug ) . '.ics"';

		header( 'Content-Type: ' . $content_type );
		header( 'Content-Disposition: ' . $disposition );
		echo (string) $result['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw ICS passthrough; not HTML.
		exit;
	}
}
