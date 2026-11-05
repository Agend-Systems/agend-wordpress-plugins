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
 *   by `search`, `location`, `state`, `postedWithin` (days), `featuredOnly`
 *   and the list filters `category`, `tag`, `type`, `locationType` and
 *   `experienceLevel`; `featuredFirst` puts featured listings first.
 * - `GET  /agend-apps/v1/jobs/categories`           : the board's active categories.
 * - `GET  /agend-apps/v1/jobs/tags`                 : the board's active tags.
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
						// The list filters take several values, as an array or a
						// comma-separated string (WordPress splits one for an
						// `array` argument). A category matches any listed one,
						// a tag only when the job carries every listed tag.
						'category' => $this->slug_list_arg(),
						'tag'      => $this->slug_list_arg(),
						'location' => array(
							'type'              => 'string',
							'maxLength'         => 120,
							'validate_callback' => 'rest_validate_request_arg',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'state'    => array(
							'type'              => 'string',
							'maxLength'         => 60,
							'validate_callback' => 'rest_validate_request_arg',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'type'     => $this->enum_list_arg( array( 'full-time', 'part-time', 'contract', 'casual', 'internship' ) ),
						'locationType' => $this->enum_list_arg( array( 'on-site', 'hybrid', 'remote' ) ),
						'experienceLevel' => $this->enum_list_arg( array( 'entry', 'mid', 'senior', 'executive' ) ),
						// Days back from today, turned into the gateway's
						// postedAfter date here so a board's cache key holds
						// for the day rather than changing every second.
						'postedWithin' => array(
							'type'              => 'integer',
							'enum'              => self::POSTED_WITHIN_DAYS,
							'validate_callback' => 'rest_validate_request_arg',
							'sanitize_callback' => 'absint',
						),
						'featuredFirst' => array(
							'type' => 'boolean',
						),
						'featuredOnly' => array(
							'type' => 'boolean',
						),
					),
				),
			)
		);

		// Registered ahead of the single-job route so these are never read as
		// a job slug (the gateway reserves both slugs for the same reason).
		foreach ( array( 'categories', 'tags' ) as $kind ) {
			register_rest_route(
				$this->namespace,
				'/' . $this->rest_base . '/' . $kind,
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this, 'get_' . $kind ),
						'permission_callback' => '__return_true',
					),
				)
			);
		}

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

		$allowed = array( 'page', 'limit', 'search', 'sortBy', 'sortOrder', 'category', 'tag', 'location', 'state', 'type', 'locationType', 'experienceLevel', 'postedWithin', 'featuredOnly', 'featuredFirst' );
		$query   = array_filter(
			$request->get_params(),
			function ( $key ) use ( $allowed ) {
				return in_array( $key, $allowed, true );
			},
			ARRAY_FILTER_USE_KEY
		);

		// The gateway reads these flags as the string 'true'; anything else
		// (including false) is the same as leaving them out.
		foreach ( array( 'featuredOnly', 'featuredFirst' ) as $flag ) {
			if ( ! array_key_exists( $flag, $query ) ) {
				continue;
			}
			if ( rest_sanitize_boolean( $query[ $flag ] ) ) {
				$query[ $flag ] = 'true';
			} else {
				unset( $query[ $flag ] );
			}
		}

		if ( array_key_exists( 'postedWithin', $query ) ) {
			$days = (int) $query['postedWithin'];
			unset( $query['postedWithin'] );
			if ( in_array( $days, self::POSTED_WITHIN_DAYS, true ) ) {
				$query['postedAfter'] = gmdate( 'Y-m-d\T00:00:00\Z', time() - $days * DAY_IN_SECONDS );
			}
		}

		// Empty strings and empty lists would reach the gateway as `?category=`
		// and fail its min-length validation.
		$query = array_filter(
			$query,
			static function ( $value ) {
				return '' !== $value && null !== $value && array() !== $value;
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
	 * The `postedWithin` values a board offers, in days.
	 *
	 * @var int[]
	 */
	public const POSTED_WITHIN_DAYS = array( 1, 3, 7, 14, 30 );

	/**
	 * The most values one list filter forwards; the gateway takes 20.
	 *
	 * @var int
	 */
	public const LIST_FILTER_MAX_VALUES = 20;

	/**
	 * A list-of-slugs argument: categories or tags.
	 *
	 * @return array
	 */
	private function slug_list_arg(): array {
		return array(
			'type'              => 'array',
			'items'             => array(
				'type'      => 'string',
				'maxLength' => 120,
			),
			'maxItems'          => self::LIST_FILTER_MAX_VALUES,
			'validate_callback' => 'rest_validate_request_arg',
			// Not sanitize_title directly: WordPress passes the request as the
			// second argument, which sanitize_title reads as its fallback
			// title for an empty value.
			'sanitize_callback' => static function ( $value ): array {
				return array_values( array_unique( array_filter( array_map( 'sanitize_title', wp_parse_list( $value ) ) ) ) );
			},
		);
	}

	/**
	 * A list argument whose values come from a fixed set.
	 *
	 * @param string[] $values The accepted values.
	 * @return array
	 */
	private function enum_list_arg( array $values ): array {
		return array(
			'type'              => 'array',
			'items'             => array(
				'type' => 'string',
				'enum' => $values,
			),
			'maxItems'          => self::LIST_FILTER_MAX_VALUES,
			'validate_callback' => 'rest_validate_request_arg',
			'sanitize_callback' => static function ( $value ) use ( $values ): array {
				return array_values( array_intersect( array_unique( wp_parse_list( $value ) ), $values ) );
			},
		);
	}

	/**
	 * Returns the board's active job categories.
	 *
	 * @return WP_REST_Response REST response.
	 */
	public function get_categories(): WP_REST_Response {
		return $this->get_terms( 'categories' );
	}

	/**
	 * Returns the board's active job tags.
	 *
	 * @return WP_REST_Response REST response.
	 */
	public function get_tags(): WP_REST_Response {
		return $this->get_terms( 'tags' );
	}

	/**
	 * A term list for the board's filters, under the same visibility rule as
	 * the job list: a members-only board names nothing to a signed-out visitor.
	 *
	 * @param string $kind `categories` or `tags`.
	 * @return WP_REST_Response REST response.
	 */
	private function get_terms( string $kind ): WP_REST_Response {
		if ( ! agend_apps_records_jobs_visitor_can_view( 'list' ) ) {
			return $this->sign_in_required();
		}

		return $this->prepare_api_response( agend_apps_jobs_get_terms( $kind ) );
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
