<?php
/**
 * REST routes for jobs endpoints.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the jobs REST routes.
 *
 * Called from `rest_api_init` via the main plugin bootstrap.
 */
function agend_apps_register_jobs_routes(): void {
	$controller = new Agend_Apps_Jobs_REST_Controller();
	$controller->register_routes();
}

/**
 * REST controller for jobs endpoints.
 *
 * Read routes are publicly readable (no authentication required).
 *
 * Exposes:
 * - `GET  /agend-apps/v1/jobs`                      — paginated jobs index.
 * - `GET  /agend-apps/v1/jobs/{slug_or_id}`         — single job by slug or ID.
 */
class Agend_Apps_Jobs_REST_Controller extends Agend_Apps_REST_Controller {

	/**
	 * Resource base for jobs routes.
	 *
	 * @var string
	 */
	protected $rest_base = 'jobs';

	/**
	 * Registers the REST routes for this controller.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_jobs' ),
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
						'sortBy'   => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'sortOrder' => array(
							'type'              => 'string',
							'enum'              => array( 'asc', 'desc' ),
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<slug_or_id>[a-zA-Z0-9_-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_job' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'slug_or_id' => array(
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
	 * Returns a paginated list of jobs.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function get_jobs( WP_REST_Request $request ): WP_REST_Response {
		$allowed = array( 'page', 'limit', 'search', 'sortBy', 'sortOrder' );
		$query   = array_filter(
			$request->get_params(),
			function ( $key ) use ( $allowed ) {
				return in_array( $key, $allowed, true );
			},
			ARRAY_FILTER_USE_KEY
		);

		$result = agend_apps_jobs_get_jobs( $query );
		return $this->prepare_api_response( $result );
	}

	/**
	 * Returns a single job by slug or ID.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function get_job( WP_REST_Request $request ): WP_REST_Response {
		$slug_or_id = $request->get_param( 'slug_or_id' );
		$result     = agend_apps_jobs_get_job( $slug_or_id );
		return $this->prepare_api_response( $result );
	}
}
