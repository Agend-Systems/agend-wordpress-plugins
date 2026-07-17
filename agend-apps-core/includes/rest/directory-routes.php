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
 * Read routes are publicly readable (no authentication required). Review
 * submission is a write and requires a valid WordPress nonce (`wp_rest`).
 * Listing create/update/delete and bulk upsert are intentionally NOT proxied:
 * they are administrative writes and are only available via the server-side
 * PHP functions in `includes/api/directory.php`.
 *
 * Exposes:
 * - `GET  /agend-apps/v1/directory/listings`                  — paginated listing index.
 * - `GET  /agend-apps/v1/directory/listings/{slugOrId}`       — single listing.
 * - `GET  /agend-apps/v1/directory/listings/{slugOrId}/reviews` — approved reviews for a listing.
 * - `GET  /agend-apps/v1/directory/categories`                — category list.
 * - `GET  /agend-apps/v1/directory/search`                    — search listings.
 * - `POST /agend-apps/v1/directory/reviews`                   — submit a listing review.
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
			'/' . $this->rest_base . '/listings/(?P<listing_id>[a-zA-Z0-9_-]+)/reviews',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_listing_reviews' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'listing_id' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'page'       => array(
							'type'              => 'integer',
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
						),
						'limit'      => array(
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
						'q'                 => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'search'            => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'page'              => array(
							'type'              => 'integer',
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
						),
						'limit'             => array(
							'type'              => 'integer',
							'minimum'           => 1,
							'maximum'           => 100,
							'sanitize_callback' => 'absint',
						),
						'per_page'          => array(
							'type'              => 'integer',
							'minimum'           => 1,
							'maximum'           => 100,
							'sanitize_callback' => 'absint',
						),
						'category'          => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'rating'            => array(
							'type'              => 'number',
							'minimum'           => 1,
							'maximum'           => 5,
							'sanitize_callback' => 'floatval',
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
					),
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
						'reviewer_name'  => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'reviewer_email' => array(
							'required'          => true,
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
		$search_query = (string) ( $request->get_param( 'q' ) ?? $request->get_param( 'search' ) ?? '' );

		// Forward the widget's catalogue filters to the gateway search. `rating`,
		// `featured`, `sortBy`, `sortOrder`, and `excludeCategories` are accepted
		// verbatim by the gateway GET decoder; `category` is translated to the
		// canonical `category_ids`.
		$filter_keys = array(
			'page',
			'limit',
			'per_page',
			'category',
			'rating',
			'featured',
			'sortBy',
			'sortOrder',
			'excludeCategories',
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

		$result = agend_apps_directory_search( $search_query, $filters );
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
