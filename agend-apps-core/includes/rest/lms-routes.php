<?php
/**
 * REST routes for LMS endpoints.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the LMS REST routes.
 *
 * Called from `rest_api_init` via the main plugin bootstrap.
 */
function agend_apps_register_lms_routes(): void {
	$controller = new Agend_Apps_LMS_REST_Controller();
	$controller->register_routes();
}

/**
 * REST controller for LMS endpoints.
 *
 * Read routes are publicly readable (no authentication required) except for
 * member/buyer routes which require a valid WordPress nonce (`wp_rest`).
 * Course and path creation, lesson management, certificate generation,
 * and webhooks are intentionally NOT proxied: they are administrative
 * writes and are only available via server-side PHP functions in
 * `includes/api/lms.php`.
 *
 * Exposes:
 * - `GET  /agend-apps/v1/lms/courses`                   — paginated course list.
 * - `GET  /agend-apps/v1/lms/courses/{id}/lessons`      — course lessons.
 * - `GET  /agend-apps/v1/lms/courses/{id}`              — single course.
 * - `GET  /agend-apps/v1/lms/paths`                     — paginated path list.
 * - `GET  /agend-apps/v1/lms/paths/{id}`                — single path.
 * - `GET  /agend-apps/v1/lms/discovery`                 — public discovery blocks.
 * - `GET  /agend-apps/v1/lms/badges/verify/{token}`     — verify badge.
 * - `GET  /agend-apps/v1/lms/certificates/verify/{code}` — verify certificate.
 * - `POST /agend-apps/v1/lms/courses/{id}/enroll`       — enroll in course.
 * - `POST /agend-apps/v1/lms/paths/{id}/enroll`         — enroll in path.
 * - `GET  /agend-apps/v1/lms/me/enrollments`            — my enrollments.
 * - `GET  /agend-apps/v1/lms/paths/{id}/progress`       — path progress.
 */
class Agend_Apps_LMS_REST_Controller extends Agend_Apps_REST_Controller {

	/**
	 * Resource base for LMS routes.
	 *
	 * @var string
	 */
	protected $rest_base = 'lms';

	/**
	 * Registers the REST routes for this controller.
	 */
	public function register_routes(): void {
		// PUBLIC READS — registered in order of specificity (most specific first)

		// GET /lms/courses/{id}/lessons (more specific, registered before bare {id})
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/courses/(?P<id>[a-zA-Z0-9_-]+)/lessons',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_course_lessons' ),
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

		// GET /lms/courses (bare route for listing)
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/courses',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_courses' ),
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
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		// GET /lms/courses/{id} (bare dynamic route)
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/courses/(?P<id>[a-zA-Z0-9_-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_course' ),
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

		// GET /lms/paths (bare route for listing)
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/paths',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_paths' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'page'      => array(
							'type'              => 'integer',
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
						),
						'per_page'  => array(
							'type'              => 'integer',
							'minimum'           => 1,
							'maximum'           => 100,
							'sanitize_callback' => 'absint',
						),
						'search'    => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'sortBy'    => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'sortOrder' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		// GET /lms/paths/{id}/progress (more specific, registered before bare {id})
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/paths/(?P<id>[a-zA-Z0-9_-]+)/progress',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_path_progress' ),
					'permission_callback' => array( $this, 'nonce_check' ),
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

		// GET /lms/paths/{id} (bare dynamic route)
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/paths/(?P<id>[a-zA-Z0-9_-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_path' ),
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

		// GET /lms/discovery (public discovery blocks)
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/discovery',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_discovery_blocks' ),
					'permission_callback' => '__return_true',
					'args'                => array(),
				),
			)
		);

		// GET /lms/badges/verify/{token} (public badge verification)
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/badges/verify/(?P<token>[a-zA-Z0-9_-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'verify_badge' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'token' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		// GET /lms/certificates/verify/{code} (public certificate verification)
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/certificates/verify/(?P<code>[a-zA-Z0-9_-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'verify_certificate' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'code' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		// MEMBER/BUYER WRITES — require nonce_check

		// POST /lms/courses/{id}/enroll (more specific, registered before bare {id})
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/courses/(?P<id>[a-zA-Z0-9_-]+)/enroll',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'enroll_course' ),
					'permission_callback' => array( $this, 'nonce_check' ),
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

		// POST /lms/paths/{id}/enroll (more specific, registered before bare {id})
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/paths/(?P<id>[a-zA-Z0-9_-]+)/enroll',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'enroll_path' ),
					'permission_callback' => array( $this, 'nonce_check' ),
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

		// GET /lms/me/enrollments (member-only, requires nonce_check)
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/me/enrollments',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_my_enrollments' ),
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
						'status'   => array(
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
	 * Returns a paginated list of courses.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function get_courses( WP_REST_Request $request ): WP_REST_Response {
		$allowed = array( 'page', 'per_page', 'search', 'sortBy', 'sortOrder' );
		$query   = array_filter(
			$request->get_params(),
			function ( $key ) use ( $allowed ) {
				return in_array( $key, $allowed, true );
			},
			ARRAY_FILTER_USE_KEY
		);

		$result = agend_apps_lms_get_courses( $query );
		return $this->prepare_api_response( $result );
	}

	/**
	 * Returns a single course by ID or slug.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function get_course( WP_REST_Request $request ): WP_REST_Response {
		$id     = $request->get_param( 'id' );
		$result = agend_apps_lms_get_course( $id );
		return $this->prepare_api_response( $result );
	}

	/**
	 * Returns lessons for a course.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function get_course_lessons( WP_REST_Request $request ): WP_REST_Response {
		$course_id = $request->get_param( 'id' );
		$result    = agend_apps_lms_get_course_lessons( $course_id );
		return $this->prepare_api_response( $result );
	}

	/**
	 * Returns a paginated list of learning paths.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function get_paths( WP_REST_Request $request ): WP_REST_Response {
		$allowed = array( 'page', 'per_page', 'search', 'sortBy', 'sortOrder' );
		$query   = array_filter(
			$request->get_params(),
			function ( $key ) use ( $allowed ) {
				return in_array( $key, $allowed, true );
			},
			ARRAY_FILTER_USE_KEY
		);

		$result = agend_apps_lms_get_paths( $query );
		return $this->prepare_api_response( $result );
	}

	/**
	 * Returns a single learning path by ID or slug.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function get_path( WP_REST_Request $request ): WP_REST_Response {
		$id     = $request->get_param( 'id' );
		$result = agend_apps_lms_get_path( $id );
		return $this->prepare_api_response( $result );
	}

	/**
	 * Returns public discovery blocks for course discovery.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function get_discovery_blocks( WP_REST_Request $request ): WP_REST_Response {
		$query  = array_filter( $request->get_params() );
		$result = agend_apps_lms_get_discovery_blocks( $query );
		return $this->prepare_api_response( $result );
	}

	/**
	 * Verifies a badge by token.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function verify_badge( WP_REST_Request $request ): WP_REST_Response {
		$token  = $request->get_param( 'token' );
		$result = agend_apps_lms_verify_badge( $token );
		return $this->prepare_api_response( $result );
	}

	/**
	 * Verifies a certificate by code.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function verify_certificate( WP_REST_Request $request ): WP_REST_Response {
		$code   = $request->get_param( 'code' );
		$result = agend_apps_lms_verify_certificate( $code );
		return $this->prepare_api_response( $result );
	}

	/**
	 * Enrolls the member in a course.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function enroll_course( WP_REST_Request $request ): WP_REST_Response {
		$course_id = $request->get_param( 'id' );
		$payload   = $request->get_json_params();
		$result    = agend_apps_lms_enroll_course( $course_id, $payload );
		return $this->prepare_api_response( $result );
	}

	/**
	 * Enrolls the member in a learning path.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function enroll_path( WP_REST_Request $request ): WP_REST_Response {
		$path_id = $request->get_param( 'id' );
		$payload = $request->get_json_params();
		$result  = agend_apps_lms_enroll_path( $path_id, $payload );
		return $this->prepare_api_response( $result );
	}

	/**
	 * Returns the authenticated member's enrollments.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function get_my_enrollments( WP_REST_Request $request ): WP_REST_Response {
		$allowed = array( 'page', 'per_page', 'status' );
		$query   = array_filter(
			$request->get_params(),
			function ( $key ) use ( $allowed ) {
				return in_array( $key, $allowed, true );
			},
			ARRAY_FILTER_USE_KEY
		);

		$result = agend_apps_lms_get_my_enrollments( $query );
		return $this->prepare_api_response( $result );
	}

	/**
	 * Returns the authenticated member's progress on a learning path.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function get_path_progress( WP_REST_Request $request ): WP_REST_Response {
		$path_id = $request->get_param( 'id' );
		$result  = agend_apps_lms_get_path_progress( $path_id );
		return $this->prepare_api_response( $result );
	}
}
