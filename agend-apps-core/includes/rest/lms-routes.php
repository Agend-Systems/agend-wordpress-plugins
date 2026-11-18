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
 * The two GET verify routes are public and reach the gateway with the site key,
 * so they sit behind the credential guard (code allow-list, per-visitor limits
 * and a site-wide ceiling: see `includes/credential-guard.php`). They are kept
 * for themes that already call them and are deprecated in favour of the POST
 * `credentials/verify` route, which also checks a captcha (a GET cannot carry a
 * token) and keeps the code out of URLs, logs and shared caches.
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
 * - `POST /agend-apps/v1/lms/credentials/verify`: the Credential check form (a code and a captcha token).
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

		// POST /lms/credentials/verify (the Credential check form). Public: the
		// captcha and the throttle are the gate, not a nonce. A nonce would
		// fail on a page-cached visit from a scanned QR code for no gain, and
		// the check is read-only.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/credentials/verify',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'verify_credential' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'code'          => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => array( $this, 'sanitize_credential_input' ),
						),
						'captcha_token' => array(
							'type'              => 'string',
							'sanitize_callback' => array( $this, 'sanitize_credential_input' ),
						),
						'holder_key'    => array(
							'type'              => 'string',
							'sanitize_callback' => array( $this, 'sanitize_credential_input' ),
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

		// GET /lms/me/enrollments/{courseId} (member-only, requires nonce_check;
		// more specific, registered before the bare list route)
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/me/enrollments/(?P<course_id>[a-zA-Z0-9-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_my_course_enrollment' ),
					'permission_callback' => array( $this, 'nonce_check' ),
					'args'                => array(
						'course_id' => array(
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
		$allowed = array( 'page', 'per_page', 'limit', 'search', 'category', 'difficulty', 'deliveryMode', 'excludeCategories', 'excludeDifficulties', 'excludeDeliveryModes', 'sortBy', 'sortOrder' );
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
		return $this->guarded_verify( $request, 'badge', (string) $request->get_param( 'token' ) );
	}

	/**
	 * Verifies a certificate by code.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function verify_certificate( WP_REST_Request $request ): WP_REST_Response {
		return $this->guarded_verify( $request, 'certificate', (string) $request->get_param( 'code' ) );
	}

	/**
	 * Runs a public verification lookup behind the credential guard.
	 *
	 * Anyone can call these two GET routes, so the order is: the code must be
	 * a shape the plugin forwards (refused with 400 and nothing counted, so a
	 * certificate number can never be used to walk the gateway's sequence),
	 * then the visitor's limits, then the site-wide ceiling, then the call.
	 * The call goes out with the site key alone (see the wrappers).
	 *
	 * There is no captcha: a GET cannot carry a token, so a visitor over the
	 * limit is simply refused. The throttled and refused answers are never
	 * cached, because they belong to one visitor.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @param string          $kind    'badge' or 'certificate'.
	 * @param string          $raw     The code as the visitor sent it.
	 * @return WP_REST_Response
	 */
	private function guarded_verify( WP_REST_Request $request, string $kind, string $raw ): WP_REST_Response {
		$parsed = agend_apps_credential_parse_code( $raw, $kind );

		if ( null === $parsed ) {
			return $this->verify_refusal( agend_apps_credential_invalid_code_error() );
		}

		$gate = agend_apps_credential_throttle( agend_apps_visitor_ip( $request ), time() );

		if ( '' !== $gate['reason'] ) {
			$refusal = $this->verify_refusal(
				new WP_Error(
					'visitor' === $gate['reason'] ? 'agend_apps_credential_rate_limited' : 'agend_apps_credential_busy',
					__( 'Too many checks. Please try again shortly.', 'agend-apps-core' ),
					array(
						'status_code' => 429,
						'retry_after' => $gate['retry_after'],
					)
				)
			);
			$refusal->header( 'Retry-After', (string) $gate['retry_after'] );

			return $refusal;
		}

		$result = 'badge' === $kind
			? agend_apps_lms_verify_badge( $parsed['code'] )
			: agend_apps_lms_verify_certificate( $parsed['code'] );

		$response = $this->prepare_api_response( $result );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * A refusal of a verification lookup: the error envelope the proxy always
	 * uses, never cached.
	 *
	 * @param WP_Error $error Error carrying `status_code` in its data.
	 * @return WP_REST_Response
	 */
	private function verify_refusal( WP_Error $error ): WP_REST_Response {
		$response = $this->error_to_response( $error );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * Keeps what a visitor typed as text: no control characters, and no more
	 * than a pasted link can need.
	 *
	 * Not `sanitize_text_field()`: that drops percent-encoded octets, which
	 * would damage a pasted link, and the code is validated against a strict
	 * allow-list afterwards anyway.
	 *
	 * @param mixed $value Raw parameter.
	 * @return string
	 */
	public function sanitize_credential_input( $value ): string {
		return is_string( $value ) ? substr( (string) preg_replace( '/[\x00-\x1F\x7F]/', '', trim( $value ) ), 0, 2000 ) : '';
	}

	/**
	 * Checks a certificate or badge code from the Credential check form.
	 *
	 * Always answers with a `status` the form can show (see
	 * {@see agend_apps_credentials_handle()}), never a gateway body, and never
	 * lets a shared cache keep the answer: it belongs to one code and one
	 * visitor's throttle.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function verify_credential( WP_REST_Request $request ): WP_REST_Response {
		$holder = hash_equals( agend_apps_credentials_holder_key(), (string) $request->get_param( 'holder_key' ) );

		$result = agend_apps_credentials_handle(
			(string) $request->get_param( 'code' ),
			(string) $request->get_param( 'captcha_token' ),
			$holder,
			agend_apps_visitor_ip( $request ),
			time()
		);

		$response = new WP_REST_Response( $result['body'], $result['http'] );
		$response->header( 'Cache-Control', 'no-store' );

		if ( isset( $result['body']['retry_after'] ) ) {
			$response->header( 'Retry-After', (string) (int) $result['body']['retry_after'] );
		}

		return $response;
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
		// Without a member bearer the gateway would 401; an empty list is the
		// correct member-facing answer for a logged-out or not-yet-linked
		// visitor (mirrors get_my_course_enrollment).
		if ( ! is_user_logged_in() || '' === agend_apps_get_bearer_token() ) {
			return new WP_REST_Response(
				array(
					'success' => true,
					'data'    => array(),
				),
				200
			);
		}

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
	 * Returns the current member's enrolment state for a single course.
	 *
	 * Translates every "no enrolment" condition (logged out, no linked member
	 * identity, gateway 404) into a normal `{ enrolled: false }` payload so the
	 * widget renders the anonymous pricing panel instead of an error state. The
	 * bearer token is resolved server-side by the token worker; the browser
	 * never supplies identity.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function get_my_course_enrollment( WP_REST_Request $request ): WP_REST_Response {
		if ( ! is_user_logged_in() ) {
			return new WP_REST_Response(
				array(
					'logged_in' => false,
					'enrolled'  => false,
				),
				200
			);
		}

		// Without a member bearer the gateway would 401; short-circuit so an
		// unlinked member sees the normal not-enrolled panel.
		if ( '' === agend_apps_get_bearer_token() ) {
			return new WP_REST_Response(
				array(
					'logged_in' => true,
					'enrolled'  => false,
					'reason'    => 'no_member_identity',
				),
				200
			);
		}

		$course_id = $request->get_param( 'course_id' );
		$result    = agend_apps_lms_get_my_course_enrollment( $course_id );

		if ( is_wp_error( $result ) ) {
			$data        = $result->get_error_data();
			$status_code = ( is_array( $data ) && isset( $data['status_code'] ) ) ? (int) $data['status_code'] : 0;

			// 404 = not enrolled, a normal state for this surface.
			if ( 404 === $status_code ) {
				return new WP_REST_Response(
					array(
						'logged_in' => true,
						'enrolled'  => false,
					),
					200
				);
			}

			return $this->error_to_response( $result );
		}

		$enrollment = ( isset( $result['data'] ) && is_array( $result['data'] ) ) ? $result['data'] : $result;

		return new WP_REST_Response(
			array(
				'logged_in'  => true,
				'enrolled'   => true,
				'enrollment' => $enrollment,
			),
			200
		);
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
