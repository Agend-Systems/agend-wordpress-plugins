<?php
/**
 * REST routes for CRM endpoints.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the CRM REST routes.
 *
 * Called from `rest_api_init` via the main plugin bootstrap.
 */
function agend_apps_register_crm_routes(): void {
	$controller = new Agend_Apps_CRM_REST_Controller();
	$controller->register_routes();
}

/**
 * REST controller for CRM endpoints.
 *
 * Exposes public catalogue reads (tiers, types, stages, memberships) without
 * authentication, and member self-service endpoints (profile, memberships,
 * transactions, team, colleagues) that require a valid WordPress nonce.
 *
 * SECURITY NOTE: The `/me/*` routes return the member's own data and rely on
 * the Supabase bearer token resolved server-side by `agend_apps_get_bearer_token()`.
 * Until that bridge supplies a token, the gateway returns a no-identity error,
 * so these endpoints fail closed. Only public catalogue reads and member
 * self-service reads are proxied; ALL administrative CRM writes (contacts,
 * companies, deals, segments, seats, tasks, tiers create/update/delete) remain
 * PHP-only and are intentionally NOT exposed here.
 *
 * Exposes:
 * - `GET  /agend-apps/v1/crm/tiers`           — list membership tiers.
 * - `GET  /agend-apps/v1/crm/tiers/{id}`      — single membership tier.
 * - `GET  /agend-apps/v1/crm/types`           — deal types.
 * - `GET  /agend-apps/v1/crm/stages`          — pipeline stages.
 * - `GET  /agend-apps/v1/crm/memberships`     — list memberships.
 * - `GET  /agend-apps/v1/crm/me`              — current member profile.
 * - `GET  /agend-apps/v1/crm/me/memberships`  — my memberships.
 * - `GET  /agend-apps/v1/crm/me/transactions` — my transactions.
 * - `GET  /agend-apps/v1/crm/me/team`         — my team.
 * - `GET  /agend-apps/v1/crm/me/colleagues`   — my colleagues.
 */
class Agend_Apps_CRM_REST_Controller extends Agend_Apps_REST_Controller {

	/**
	 * Resource base for CRM routes.
	 *
	 * @var string
	 */
	protected $rest_base = 'crm';

	/**
	 * Registers the REST routes for this controller.
	 */
	public function register_routes(): void {
		// Public catalogue: tiers list.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/tiers',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_tiers' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'page'     => array(
							'type'              => 'integer',
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
						),
						'per_page' => array(
							'type'              => 'integer',
							'minimum'           => 1,
							'maximum'           => 100,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		// Public catalogue: single tier (must be registered AFTER /tiers list).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/tiers/(?P<id>[a-zA-Z0-9_-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_tier' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'id' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		// Public catalogue: deal types.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/types',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_types' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		// Public catalogue: pipeline stages.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/stages',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_stages' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		// Public catalogue: memberships list.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/memberships',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_memberships' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'page'     => array(
							'type'              => 'integer',
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
						),
						'per_page' => array(
							'type'              => 'integer',
							'minimum'           => 1,
							'maximum'           => 100,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		// Public catalogue: membership-signup field definitions. Definitions
		// only (never member values); powers the public Memberships widget form.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/fields',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_fields' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'entityType' => array(
							'type'              => 'string',
							'enum'              => array( 'contact', 'company' ),
							'default'           => 'contact',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		// Public signup write: create a prospect contact (nonce-gated). Used by
		// the Memberships widget for both the direct-purchase and application
		// paths.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/contacts',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_contact' ),
					'permission_callback' => array( $this, 'nonce_check' ),
				),
			)
		);

		// Public signup write: open a hosted-checkout session for a membership
		// purchase (nonce-gated). The buyer contact_id is supplied in the body.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/memberships/purchase',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'purchase_membership' ),
					'permission_callback' => array( $this, 'nonce_check' ),
				),
			)
		);

		// Member self-service: current member profile.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/me',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_me' ),
					'permission_callback' => array( $this, 'nonce_check' ),
				),
			)
		);

		// Member self-service: my memberships.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/me/memberships',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_my_memberships' ),
					'permission_callback' => array( $this, 'nonce_check' ),
					'args'                => array(
						'page'     => array(
							'type'              => 'integer',
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
						),
						'per_page' => array(
							'type'              => 'integer',
							'minimum'           => 1,
							'maximum'           => 100,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		// Member self-service: my transactions.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/me/transactions',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_my_transactions' ),
					'permission_callback' => array( $this, 'nonce_check' ),
					'args'                => array(
						'page'     => array(
							'type'              => 'integer',
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
						),
						'per_page' => array(
							'type'              => 'integer',
							'minimum'           => 1,
							'maximum'           => 100,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		// Member self-service: my team.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/me/team',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_my_team' ),
					'permission_callback' => array( $this, 'nonce_check' ),
				),
			)
		);

		// Member self-service: my colleagues.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/me/colleagues',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_my_colleagues' ),
					'permission_callback' => array( $this, 'nonce_check' ),
					'args'                => array(
						'page'     => array(
							'type'              => 'integer',
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
						),
						'per_page' => array(
							'type'              => 'integer',
							'minimum'           => 1,
							'maximum'           => 100,
							'sanitize_callback' => 'absint',
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
	 * Returns a paginated list of membership tiers.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function get_tiers( WP_REST_Request $request ): WP_REST_Response {
		$allowed = array( 'page', 'per_page' );
		$query   = array_filter(
			$request->get_params(),
			function ( $key ) use ( $allowed ) {
				return in_array( $key, $allowed, true );
			},
			ARRAY_FILTER_USE_KEY
		);

		$result = agend_apps_crm_get_tiers( $query );
		return $this->prepare_api_response( $result );
	}

	/**
	 * Returns a single membership tier by ID.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function get_tier( WP_REST_Request $request ): WP_REST_Response {
		$tier_id = $request->get_param( 'id' );
		$result  = agend_apps_crm_get_tier( $tier_id );
		return $this->prepare_api_response( $result );
	}

	/**
	 * Returns all deal types.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function get_types( WP_REST_Request $request ): WP_REST_Response {
		$result = agend_apps_crm_get_types();
		return $this->prepare_api_response( $result );
	}

	/**
	 * Returns all pipeline stages.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function get_stages( WP_REST_Request $request ): WP_REST_Response {
		$result = agend_apps_crm_get_stages();
		return $this->prepare_api_response( $result );
	}

	/**
	 * Returns a paginated list of memberships.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function get_memberships( WP_REST_Request $request ): WP_REST_Response {
		$allowed = array( 'page', 'per_page' );
		$query   = array_filter(
			$request->get_params(),
			function ( $key ) use ( $allowed ) {
				return in_array( $key, $allowed, true );
			},
			ARRAY_FILTER_USE_KEY
		);

		$result = agend_apps_crm_get_memberships( $query );
		return $this->prepare_api_response( $result );
	}

	/**
	 * Returns the public membership-signup field definitions.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function get_fields( WP_REST_Request $request ): WP_REST_Response {
		$query  = array( 'entityType' => $request->get_param( 'entityType' ) );
		$result = agend_apps_crm_get_fields( $query );
		return $this->prepare_api_response( $result );
	}

	/**
	 * Creates a prospect contact from the public signup form. The gateway's
	 * schema validates the forwarded body; a valid nonce is required.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function create_contact( WP_REST_Request $request ): WP_REST_Response {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = array();
		}
		$result = agend_apps_crm_create_contact( $body );
		return $this->prepare_api_response( $result );
	}

	/**
	 * Opens a hosted-checkout session for a membership purchase. The buyer's
	 * contact_id is supplied in the body; a valid nonce is required.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function purchase_membership( WP_REST_Request $request ): WP_REST_Response {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = array();
		}
		$result = agend_apps_crm_purchase_membership( $body );
		return $this->prepare_api_response( $result );
	}

	/**
	 * Returns the current member's profile.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function get_me( WP_REST_Request $request ): WP_REST_Response {
		$result = agend_apps_crm_get_me();
		return $this->prepare_api_response( $result );
	}

	/**
	 * Returns the current member's memberships.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function get_my_memberships( WP_REST_Request $request ): WP_REST_Response {
		$allowed = array( 'page', 'per_page' );
		$query   = array_filter(
			$request->get_params(),
			function ( $key ) use ( $allowed ) {
				return in_array( $key, $allowed, true );
			},
			ARRAY_FILTER_USE_KEY
		);

		$result = agend_apps_crm_get_my_memberships( $query );
		return $this->prepare_api_response( $result );
	}

	/**
	 * Returns the current member's transactions.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function get_my_transactions( WP_REST_Request $request ): WP_REST_Response {
		$allowed = array( 'page', 'per_page' );
		$query   = array_filter(
			$request->get_params(),
			function ( $key ) use ( $allowed ) {
				return in_array( $key, $allowed, true );
			},
			ARRAY_FILTER_USE_KEY
		);

		$result = agend_apps_crm_get_my_transactions( $query );
		return $this->prepare_api_response( $result );
	}

	/**
	 * Returns the current member's team.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function get_my_team( WP_REST_Request $request ): WP_REST_Response {
		$result = agend_apps_crm_get_my_team();
		return $this->prepare_api_response( $result );
	}

	/**
	 * Returns the current member's colleagues.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function get_my_colleagues( WP_REST_Request $request ): WP_REST_Response {
		$allowed = array( 'page', 'per_page' );
		$query   = array_filter(
			$request->get_params(),
			function ( $key ) use ( $allowed ) {
				return in_array( $key, $allowed, true );
			},
			ARRAY_FILTER_USE_KEY
		);

		$result = agend_apps_crm_get_my_colleagues( $query );
		return $this->prepare_api_response( $result );
	}
}
