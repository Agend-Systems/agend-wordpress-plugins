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

/**
 * REST controller for directory endpoints.
 *
 * All directory routes are publicly readable (no authentication required).
 *
 * Exposes:
 * - `GET /agend-apps/v1/directory/listings`          — paginated listing index.
 * - `GET /agend-apps/v1/directory/listings/{id}`     — single listing.
 * - `GET /agend-apps/v1/directory/categories`        — category list.
 * - `GET /agend-apps/v1/directory/search`            — search listings.
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
						'category' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
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
			'/' . $this->rest_base . '/search',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'search' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'q'        => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
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
						'category' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);
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
		$result     = agend_apps_directory_get_listing( $listing_id );
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
	 * Searches directory listings.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function search( WP_REST_Request $request ): WP_REST_Response {
		$search_query = (string) $request->get_param( 'q' );

		$filter_keys = array( 'page', 'per_page', 'category' );
		$filters     = array_filter(
			$request->get_params(),
			function ( $key ) use ( $filter_keys ) {
				return in_array( $key, $filter_keys, true );
			},
			ARRAY_FILTER_USE_KEY
		);

		$result = agend_apps_directory_search( $search_query, $filters );
		return $this->prepare_api_response( $result );
	}
}
