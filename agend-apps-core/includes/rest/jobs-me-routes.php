<?php
/**
 * REST routes for a signed-in member's own job submissions.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller behind the Member Jobs surface.
 *
 * Exposes, for the signed-in WordPress user only:
 * - `GET   /agend-apps/v1/jobs/me`               : their submissions, newest first (`page`).
 * - `POST  /agend-apps/v1/jobs/me`               : spend a credit and submit a job.
 * - `GET   /agend-apps/v1/jobs/me/credits`       : how many credits they can spend.
 * - `PATCH /agend-apps/v1/jobs/me/{id}`          : edit a pending submission.
 * - `POST  /agend-apps/v1/jobs/me/{id}/withdraw` : withdraw a pending one (credit given back).
 * - `POST  /agend-apps/v1/jobs/me/{id}/close`    : take a published one down (`filled`).
 *
 * Identity comes from the WordPress session and the member's gateway bearer;
 * no member or credit is ever named by the browser. Every answer is
 * `{ success, data }` or `{ success: false, code, message, fields? }`, where
 * `code` is stable for the widget and `message` is the site's own wording.
 *
 * Registered before the public jobs routes, whose `jobs/{slug_or_id}` would
 * otherwise read `me` as a job slug.
 */
class Agend_Apps_Jobs_Me_REST_Controller extends Agend_Apps_REST_Controller {

	/**
	 * Resource base.
	 *
	 * @var string
	 */
	protected $rest_base = 'jobs/me';

	/**
	 * Job id path pattern.
	 *
	 * @var string
	 */
	const ID_PATTERN = '(?P<id>[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})';

	/**
	 * Registers the routes.
	 */
	public function register_routes(): void {
		$permission = array( $this, 'signed_in' );

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_mine' ),
					'permission_callback' => $permission,
					'args'                => array(
						'page' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'default' => 1,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'submit' ),
					'permission_callback' => $permission,
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/credits',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'credits' ),
					'permission_callback' => $permission,
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/' . self::ID_PATTERN,
			array(
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update' ),
					'permission_callback' => $permission,
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/' . self::ID_PATTERN . '/withdraw',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'withdraw' ),
					'permission_callback' => $permission,
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/' . self::ID_PATTERN . '/close',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'close' ),
					'permission_callback' => $permission,
					'args'                => array(
						'filled' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
			)
		);
	}

	/**
	 * Only a signed-in user. Core has already checked the REST nonce: a
	 * cookie request without a valid one arrives as user 0.
	 *
	 * @return true|WP_Error
	 */
	public function signed_in() {
		if ( get_current_user_id() > 0 ) {
			return true;
		}

		return new WP_Error( 'sign_in', __( 'Please sign in to manage your jobs.', 'agend-apps-core' ), array( 'status' => 401 ) );
	}

	/**
	 * Lists the member's submissions.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function list_mine( WP_REST_Request $request ): WP_REST_Response {
		return $this->answer( Agend_Apps_Jobs_Submissions::mine( get_current_user_id(), (int) $request->get_param( 'page' ) ) );
	}

	/**
	 * The member's spendable credits.
	 *
	 * @return WP_REST_Response
	 */
	public function credits(): WP_REST_Response {
		return $this->answer( Agend_Apps_Jobs_Submissions::credits( get_current_user_id() ) );
	}

	/**
	 * Spends a credit and submits a job.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function submit( WP_REST_Request $request ): WP_REST_Response {
		$clean = Agend_Apps_Jobs_Submissions::sanitize( $this->body( $request ), true );

		if ( array() !== $clean['errors'] ) {
			return $this->invalid( $clean['errors'] );
		}

		$result = Agend_Apps_Jobs_Submissions::submit( get_current_user_id(), $clean['fields'] );

		return is_wp_error( $result ) ? $this->answer( $result ) : new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $result,
			),
			201
		);
	}

	/**
	 * Edits a pending submission.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function update( WP_REST_Request $request ): WP_REST_Response {
		$clean = Agend_Apps_Jobs_Submissions::sanitize( $this->body( $request ), false );

		if ( array() !== $clean['errors'] ) {
			return $this->invalid( $clean['errors'] );
		}

		return $this->answer( Agend_Apps_Jobs_Submissions::update( get_current_user_id(), strtolower( (string) $request->get_param( 'id' ) ), $clean['fields'] ) );
	}

	/**
	 * Withdraws a pending submission.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function withdraw( WP_REST_Request $request ): WP_REST_Response {
		return $this->answer( Agend_Apps_Jobs_Submissions::withdraw( get_current_user_id(), strtolower( (string) $request->get_param( 'id' ) ) ) );
	}

	/**
	 * Takes a published job down.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function close( WP_REST_Request $request ): WP_REST_Response {
		return $this->answer( Agend_Apps_Jobs_Submissions::close( get_current_user_id(), strtolower( (string) $request->get_param( 'id' ) ), (bool) $request->get_param( 'filled' ) ) );
	}

	/**
	 * The JSON body as an array.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	private function body( WP_REST_Request $request ): array {
		$body = $request->get_json_params();

		return is_array( $body ) ? $body : array();
	}

	/**
	 * The validation answer.
	 *
	 * @param array<string, string> $errors Field messages.
	 * @return WP_REST_Response
	 */
	private function invalid( array $errors ): WP_REST_Response {
		return $this->answer(
			new WP_Error(
				'validation_failed',
				__( 'Please check the highlighted fields and try again.', 'agend-apps-core' ),
				array(
					'status' => 400,
					'fields' => $errors,
				)
			)
		);
	}

	/**
	 * A service result as the route's answer.
	 *
	 * @param mixed $result Data, or WP_Error.
	 * @return WP_REST_Response
	 */
	private function answer( $result ): WP_REST_Response {
		if ( ! is_wp_error( $result ) ) {
			return new WP_REST_Response(
				array(
					'success' => true,
					'data'    => $result,
				),
				200
			);
		}

		$data = $result->get_error_data();
		$body = array(
			'success' => false,
			'code'    => $result->get_error_code(),
			'message' => $result->get_error_message(),
		);

		if ( is_array( $data ) && ! empty( $data['fields'] ) && is_array( $data['fields'] ) ) {
			$body['fields'] = $data['fields'];
		}

		return new WP_REST_Response( $body, is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 502 );
	}
}
