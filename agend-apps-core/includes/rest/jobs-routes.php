<?php
/**
 * REST routes for jobs endpoints.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../records/jobs-visibility.php';

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
 * - `GET  /agend-apps/v1/jobs`                      — paginated jobs index, filterable
 *   by `search`, `category` (slug), `location`, `type` and `featuredOnly`.
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
						// WordPress checks `enum` and `maxLength` only for an
						// argument with no sanitize callback, so the filters
						// below name the validator explicitly.
						'sortBy'   => array(
							'type'              => 'string',
							'enum'              => array( 'created_at', 'published_at', 'title', 'salary_min', 'salary_max', 'expires_at' ),
							'validate_callback' => 'rest_validate_request_arg',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'sortOrder' => array(
							'type'              => 'string',
							'enum'              => array( 'asc', 'desc' ),
							'validate_callback' => 'rest_validate_request_arg',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'category' => array(
							'type'              => 'string',
							'maxLength'         => 120,
							'validate_callback' => 'rest_validate_request_arg',
							// Not sanitize_title directly: WordPress passes the
							// request as the second argument, which sanitize_title
							// reads as its fallback title for an empty value.
							'sanitize_callback' => static function ( $value ): string {
								return sanitize_title( (string) $value );
							},
						),
						'location' => array(
							'type'              => 'string',
							'maxLength'         => 120,
							'validate_callback' => 'rest_validate_request_arg',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'type'     => array(
							'type'              => 'string',
							'enum'              => array( 'full-time', 'part-time', 'contract', 'casual', 'internship' ),
							'validate_callback' => 'rest_validate_request_arg',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'experienceLevel' => array(
							'type'              => 'string',
							'enum'              => array( 'entry', 'mid', 'senior', 'executive' ),
							'validate_callback' => 'rest_validate_request_arg',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'featuredOnly' => array(
							'type' => 'boolean',
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
		// The jobs board visibility setting applies here as well as on the
		// rendered board, so the data is never sent to a visitor the board
		// would not show it to.
		if ( ! agend_apps_records_jobs_visitor_can_view( 'list' ) ) {
			return $this->sign_in_required();
		}

		$allowed = array( 'page', 'limit', 'search', 'sortBy', 'sortOrder', 'category', 'location', 'type', 'experienceLevel', 'featuredOnly' );
		$query   = array_filter(
			$request->get_params(),
			function ( $key ) use ( $allowed ) {
				return in_array( $key, $allowed, true );
			},
			ARRAY_FILTER_USE_KEY
		);

		// The gateway reads featuredOnly as the string 'true'; anything else
		// (including false) is the same as leaving it out.
		if ( array_key_exists( 'featuredOnly', $query ) ) {
			if ( rest_sanitize_boolean( $query['featuredOnly'] ) ) {
				$query['featuredOnly'] = 'true';
			} else {
				unset( $query['featuredOnly'] );
			}
		}

		// Empty strings would reach the gateway as `?category=` and fail its
		// min-length validation.
		$query = array_filter(
			$query,
			static function ( $value ) {
				return '' !== $value && null !== $value;
			}
		);

		$result = agend_apps_jobs_get_jobs( $query );

		// A visitor who may see the list but not a job's details gets each
		// job reduced to what a card shows.
		if ( ! is_wp_error( $result ) && ! agend_apps_records_jobs_visitor_can_view( 'detail' ) && isset( $result['data'] ) && is_array( $result['data'] ) ) {
			$result['data'] = array_map(
				static function ( $job ) {
					return is_array( $job ) ? agend_apps_records_jobs_teaser( $job ) : $job;
				},
				$result['data']
			);
		}

		return $this->prepare_api_response( $result );
	}

	/**
	 * The response for a visitor the jobs board visibility setting turns away.
	 *
	 * @return WP_REST_Response
	 */
	private function sign_in_required(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'success' => false,
				'code'    => 'agend_apps_sign_in_required',
				'message' => __( 'Sign in to see jobs.', 'agend-apps-core' ),
			),
			401
		);
	}

	/**
	 * Returns a single job by slug or ID.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function get_job( WP_REST_Request $request ): WP_REST_Response {
		if ( ! agend_apps_records_jobs_visitor_can_view( 'detail' ) ) {
			return $this->sign_in_required();
		}

		$slug_or_id = $request->get_param( 'slug_or_id' );
		$result     = agend_apps_jobs_get_job( $slug_or_id );
		return $this->prepare_api_response( $result );
	}
}
