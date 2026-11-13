<?php
/**
 * Member job submissions: a signed-in member posts a job to the board through
 * this site, paid for with one of their job credits, and staff approve it in
 * Agend before it goes live.
 *
 * This is the server side of the Member Jobs surface. It owns the one rule
 * that must never be decided in the browser: what happens to the credit when
 * the gateway answers.
 *
 * 1. Check everything that can be checked without spending (signed in, job
 *    credits on, the API key holds the scope, the member has a gateway
 *    bearer), because a spend and a give-back are two writes to the CRM.
 * 2. Reserve a credit ({@see Agend_Apps_Jobs_Credits::reserve()}).
 * 3. Submit with the credit_ref as the Idempotency-Key.
 * 4. Settle:
 *    - created: attach the job to the reservation;
 *    - 400, 401, 403 or 429: no job was created, so give the credit back;
 *    - 409, a timeout, a 5xx or anything unreadable: the outcome is unknown,
 *      so the credit is left reserved. The webhook or the sweep settles it.
 *
 * Withdrawing gives the credit back straight away as well as through the
 * `jobs.submission.withdrawn` webhook; both are the same idempotent release.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Agend_Apps_Jobs_Submissions' ) ) :

	/**
	 * Validates, submits and manages a member's own job submissions.
	 */
	final class Agend_Apps_Jobs_Submissions {

		/**
		 * The scope every write needs.
		 *
		 * @var string
		 */
		const MANAGE_SCOPE = 'jobs.submissions.self_manage';

		/**
		 * The scope the list needs.
		 *
		 * @var string
		 */
		const READ_SCOPE = 'jobs.submissions.self_read';

		/**
		 * Gateway statuses that mean the submission was refused before a job
		 * was created, so the credit is given back.
		 *
		 * @var int[]
		 */
		const REFUSED_STATUSES = array( 400, 401, 403, 429 );

		/**
		 * The fields a member may send, with their gateway limits.
		 *
		 * `type` is one of text, textarea, html, url, email, enum, int, bool or
		 * ids. `nullable` fields are cleared with null on an edit; the others
		 * can only be changed.
		 *
		 * @return array<string, array{type: string, max?: int, values?: string[], nullable?: bool}>
		 */
		public static function fields(): array {
			return array(
				'title'                    => array( 'type' => 'text', 'max' => 200 ),
				'short_description'        => array( 'type' => 'textarea', 'max' => 500, 'nullable' => true ),
				'description'              => array( 'type' => 'html', 'max' => 10000, 'nullable' => true ),
				'company_name'             => array( 'type' => 'text', 'max' => 200, 'nullable' => true ),
				'company_website'          => array( 'type' => 'url', 'max' => 1000, 'nullable' => true ),
				'employment_type'          => array( 'type' => 'enum', 'values' => array( 'full-time', 'part-time', 'contract', 'casual', 'internship' ) ),
				'experience_level'         => array( 'type' => 'enum', 'values' => array( 'entry', 'mid', 'senior', 'executive' ), 'nullable' => true ),
				'location_type'            => array( 'type' => 'enum', 'values' => array( 'on-site', 'hybrid', 'remote' ) ),
				'location_city'            => array( 'type' => 'text', 'max' => 100, 'nullable' => true ),
				'location_state'           => array( 'type' => 'text', 'max' => 100, 'nullable' => true ),
				'location_postcode'        => array( 'type' => 'text', 'max' => 10, 'nullable' => true ),
				'location_country'         => array( 'type' => 'text', 'max' => 100, 'nullable' => true ),
				'salary_min'               => array( 'type' => 'int', 'max' => 100000000, 'nullable' => true ),
				'salary_max'               => array( 'type' => 'int', 'max' => 100000000, 'nullable' => true ),
				'salary_period'            => array( 'type' => 'enum', 'values' => array( 'hourly', 'annual', 'project' ) ),
				'show_salary'              => array( 'type' => 'bool' ),
				'application_url'          => array( 'type' => 'url', 'max' => 1000, 'nullable' => true ),
				'application_email'        => array( 'type' => 'email', 'max' => 320, 'nullable' => true ),
				'application_instructions' => array( 'type' => 'textarea', 'max' => 5000, 'nullable' => true ),
				'reference_number'         => array( 'type' => 'text', 'max' => 100, 'nullable' => true ),
				'contact_name'             => array( 'type' => 'text', 'max' => 200, 'nullable' => true ),
				'contact_phone'            => array( 'type' => 'text', 'max' => 50, 'nullable' => true ),
				'category_ids'             => array( 'type' => 'ids', 'max' => 50 ),
				'tag_ids'                  => array( 'type' => 'ids', 'max' => 50 ),
			);
		}

		/**
		 * The fields the gateway itself requires on a new submission. Anything
		 * else a site marks required is checked by the form.
		 *
		 * @var string[]
		 */
		const REQUIRED = array( 'title', 'employment_type', 'location_type' );

		/**
		 * Cleans a member's input into gateway fields, and says what is wrong.
		 *
		 * Unknown keys are dropped. On a new submission an empty optional field
		 * is left out; on an edit it becomes null so the gateway clears it.
		 * The description is typed as plain text and sent as paragraphs.
		 *
		 * @param array $input    Decoded request body.
		 * @param bool  $creating True for a new submission, false for an edit.
		 * @return array{fields: array, errors: array<string, string>}
		 */
		public static function sanitize( array $input, bool $creating ): array {
			$fields = array();
			$errors = array();

			foreach ( self::fields() as $name => $rule ) {
				if ( ! array_key_exists( $name, $input ) ) {
					if ( $creating && in_array( $name, self::REQUIRED, true ) ) {
						$errors[ $name ] = self::message( 'required' );
					}
					continue;
				}

				$result = self::clean( $input[ $name ], $rule );

				if ( isset( $result['error'] ) ) {
					$errors[ $name ] = self::message( $result['error'] );
					continue;
				}

				$value = $result['value'];

				if ( null === $value ) {
					if ( in_array( $name, self::REQUIRED, true ) ) {
						$errors[ $name ] = self::message( 'required' );
					} elseif ( ! $creating && ! empty( $rule['nullable'] ) ) {
						$fields[ $name ] = null;
					}
					continue;
				}

				$fields[ $name ] = $value;
			}

			if ( isset( $fields['salary_min'], $fields['salary_max'] ) && $fields['salary_min'] > $fields['salary_max'] ) {
				$errors['salary_max'] = self::message( 'salary_range' );
			}

			return array(
				'fields' => $fields,
				'errors' => $errors,
			);
		}

		/**
		 * One value cleaned to its rule: `value` (null when empty) or `error`.
		 *
		 * @param mixed $raw  Submitted value.
		 * @param array $rule Field rule from {@see fields()}.
		 * @return array{value?: mixed, error?: string}
		 */
		private static function clean( $raw, array $rule ): array {
			$type = $rule['type'];
			$max  = (int) ( $rule['max'] ?? 0 );

			if ( 'bool' === $type ) {
				return array( 'value' => filter_var( $raw, FILTER_VALIDATE_BOOLEAN ) );
			}

			if ( 'ids' === $type ) {
				$ids = array();

				foreach ( is_array( $raw ) ? $raw : array() as $id ) {
					$id = is_scalar( $id ) ? strtolower( trim( (string) $id ) ) : '';

					if ( 1 !== preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id ) ) {
						return array( 'error' => 'invalid' );
					}

					$ids[ $id ] = $id;
				}

				return count( $ids ) > $max ? array( 'error' => 'too_many' ) : array( 'value' => array_values( $ids ) );
			}

			if ( null === $raw || ! is_scalar( $raw ) ) {
				return array( 'value' => null );
			}

			$text = trim( (string) $raw );

			if ( '' === $text ) {
				return array( 'value' => null );
			}

			switch ( $type ) {
				case 'enum':
					return in_array( $text, $rule['values'], true ) ? array( 'value' => $text ) : array( 'error' => 'invalid' );

				case 'int':
					if ( 1 !== preg_match( '/^\d+$/', $text ) || (int) $text > $max ) {
						return array( 'error' => 'number' );
					}
					return array( 'value' => (int) $text );

				case 'url':
					$url = esc_url_raw( $text, array( 'http', 'https' ) );
					if ( '' === $url || 1 !== preg_match( '#^https?://[^/\s]+\.[^/\s]+#i', $url ) ) {
						return array( 'error' => 'url' );
					}
					return self::fits( $url, $max );

				case 'email':
					$email = sanitize_email( $text );
					if ( '' === $email || ! is_email( $email ) ) {
						return array( 'error' => 'email' );
					}
					return self::fits( $email, $max );

				case 'textarea':
					return self::fits( sanitize_textarea_field( $text ), $max );

				case 'html':
					return self::fits( self::paragraphs( sanitize_textarea_field( $text ) ), $max );

				default:
					return self::fits( sanitize_text_field( $text ), $max );
			}
		}

		/**
		 * The value, or a too-long error.
		 *
		 * @param string $value Cleaned value.
		 * @param int    $max   Character limit.
		 * @return array{value?: string, error?: string}
		 */
		private static function fits( string $value, int $max ): array {
			if ( '' === $value ) {
				return array( 'value' => null );
			}

			return mb_strlen( $value ) > $max ? array( 'error' => 'too_long' ) : array( 'value' => $value );
		}

		/**
		 * Plain text as paragraphs of escaped HTML, the shape the board renders.
		 *
		 * @param string $text Plain text.
		 * @return string
		 */
		public static function paragraphs( string $text ): string {
			$text = str_replace( array( "\r\n", "\r" ), "\n", $text );

			return '' === trim( $text ) ? '' : trim( wpautop( esc_html( $text ) ) );
		}

		/**
		 * A member-facing message for a field problem.
		 *
		 * @param string $key Problem key.
		 * @return string
		 */
		public static function message( string $key ): string {
			$messages = array(
				'required'     => __( 'This field is required.', 'agend-apps-core' ),
				'invalid'      => __( 'Please choose one of the options.', 'agend-apps-core' ),
				'too_many'     => __( 'Please choose fewer options.', 'agend-apps-core' ),
				'too_long'     => __( 'This is too long.', 'agend-apps-core' ),
				'number'       => __( 'Please enter a whole number.', 'agend-apps-core' ),
				'url'          => __( 'Please enter a full web address, starting with https://.', 'agend-apps-core' ),
				'email'        => __( 'Please enter a valid email address.', 'agend-apps-core' ),
				'salary_range' => __( 'The maximum must not be less than the minimum.', 'agend-apps-core' ),
				'check'        => __( 'Please check this field.', 'agend-apps-core' ),
			);

			return $messages[ $key ] ?? $messages['check'];
		}

		/**
		 * Whether the member can use their submissions at all, checked before
		 * any credit is spent.
		 *
		 * @param int    $user_id WordPress user id.
		 * @param string $scope   The scope the call needs.
		 * @return true|WP_Error
		 */
		public static function precheck( int $user_id, string $scope = self::MANAGE_SCOPE ) {
			if ( $user_id <= 0 ) {
				return new WP_Error( 'sign_in', __( 'Please sign in to post a job.', 'agend-apps-core' ), array( 'status' => 401 ) );
			}

			if ( self::MANAGE_SCOPE === $scope && ! Agend_Apps_Jobs_Credits::enabled() ) {
				return new WP_Error( 'unavailable', __( 'Job posting is not available on this site.', 'agend-apps-core' ), array( 'status' => 503 ) );
			}

			// Only a scope list the site has actually read can rule a call out.
			if ( class_exists( 'Agend_Apps_Key_Scopes' ) && Agend_Apps_Key_Scopes::known() && ! Agend_Apps_Key_Scopes::has( $scope ) ) {
				return new WP_Error( 'unavailable', __( 'Job posting is not available on this site.', 'agend-apps-core' ), array( 'status' => 503 ) );
			}

			if ( '' === agend_apps_get_bearer_token() ) {
				return new WP_Error( 'not_connected', __( 'Your account is not connected to the jobs board yet. Please sign out and sign in again, or contact us.', 'agend-apps-core' ), array( 'status' => 403 ) );
			}

			return true;
		}

		/**
		 * Spends a credit and submits the member's job.
		 *
		 * @param int   $user_id WordPress user id.
		 * @param array $fields  Fields from {@see sanitize()}, with no errors.
		 * @return array|WP_Error The pending submission item, or an error whose
		 *                        code the Member Jobs surface understands.
		 */
		public static function submit( int $user_id, array $fields ) {
			$ready = self::precheck( $user_id );

			if ( is_wp_error( $ready ) ) {
				return $ready;
			}

			$credit_ref = Agend_Apps_Jobs_Credits::reserve( $user_id );

			if ( is_wp_error( $credit_ref ) ) {
				return self::credit_error( $credit_ref );
			}

			return self::settle( $user_id, $credit_ref, agend_apps_jobs_submit( $fields, $credit_ref ) );
		}

		/**
		 * Acts on the gateway's answer to a submission.
		 *
		 * @param int            $user_id    WordPress user id.
		 * @param string         $credit_ref The reservation's credit_ref.
		 * @param array|WP_Error $response   The gateway's answer.
		 * @return array|WP_Error
		 */
		public static function settle( int $user_id, string $credit_ref, $response ) {
			if ( ! is_wp_error( $response ) ) {
				$item   = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array();
				$job_id = isset( $item['id'] ) && is_scalar( $item['id'] ) ? (string) $item['id'] : '';

				if ( '' === $job_id ) {
					return self::unconfirmed();
				}

				Agend_Apps_Jobs_Credits::attach( $user_id, $credit_ref, $job_id );

				return $item;
			}

			if ( ! in_array( self::status( $response ), self::REFUSED_STATUSES, true ) ) {
				return self::unconfirmed();
			}

			// A release the CRM cannot confirm stays release_pending and the
			// sweep finishes it, so its own answer changes nothing here.
			Agend_Apps_Jobs_Credits::release( $credit_ref );

			return self::gateway_error( $response );
		}

		/**
		 * Applies an edit to a pending submission.
		 *
		 * @param int    $user_id WordPress user id.
		 * @param string $job_id  The submission's job id.
		 * @param array  $fields  Fields from {@see sanitize()}, with no errors.
		 * @return array|WP_Error The updated item.
		 */
		public static function update( int $user_id, string $job_id, array $fields ) {
			$ready = self::precheck( $user_id );

			if ( is_wp_error( $ready ) ) {
				return $ready;
			}

			return self::item_or_error( agend_apps_jobs_update_my_submission( $job_id, $fields ) );
		}

		/**
		 * Withdraws a pending submission and gives its credit back.
		 *
		 * @param int    $user_id WordPress user id.
		 * @param string $job_id  The submission's job id.
		 * @return array|WP_Error The withdrawn item.
		 */
		public static function withdraw( int $user_id, string $job_id ) {
			$ready = self::precheck( $user_id );

			if ( is_wp_error( $ready ) ) {
				return $ready;
			}

			$item = self::item_or_error( agend_apps_jobs_withdraw_my_submission( $job_id ) );

			if ( ! is_wp_error( $item ) && isset( $item['credit_ref'] ) && is_string( $item['credit_ref'] ) && null !== Agend_Apps_Jobs_Credits::parse( $item['credit_ref'] ) ) {
				// The webhook does the same; whichever arrives first wins and
				// the other is acknowledged.
				Agend_Apps_Jobs_Credits::release( $item['credit_ref'], $job_id );
			}

			return $item;
		}

		/**
		 * Takes a published job off the board.
		 *
		 * @param int    $user_id WordPress user id.
		 * @param string $job_id  The submission's job id.
		 * @param bool   $filled  Whether the position was filled.
		 * @return array|WP_Error The closed item.
		 */
		public static function close( int $user_id, string $job_id, bool $filled ) {
			$ready = self::precheck( $user_id );

			if ( is_wp_error( $ready ) ) {
				return $ready;
			}

			return self::item_or_error( agend_apps_jobs_close_my_submission( $job_id, $filled ) );
		}

		/**
		 * The member's submissions, newest first, every status.
		 *
		 * @param int $user_id WordPress user id.
		 * @param int $page    Page number.
		 * @return array{items: array, pagination: array}|WP_Error
		 */
		public static function mine( int $user_id, int $page = 1 ) {
			$ready = self::precheck( $user_id, self::READ_SCOPE );

			if ( is_wp_error( $ready ) ) {
				return $ready;
			}

			$response = agend_apps_jobs_get_my_submissions(
				array(
					'page'  => max( 1, $page ),
					'limit' => 20,
				)
			);

			if ( is_wp_error( $response ) ) {
				return self::gateway_error( $response );
			}

			return array(
				'items'      => isset( $response['data'] ) && is_array( $response['data'] ) ? array_values( array_filter( $response['data'], 'is_array' ) ) : array(),
				'pagination' => isset( $response['meta']['pagination'] ) && is_array( $response['meta']['pagination'] ) ? $response['meta']['pagination'] : array(),
			);
		}

		/**
		 * How many credits the member can spend, and when the first expires.
		 *
		 * @param int $user_id WordPress user id.
		 * @return array{available: int, next_expiry: ?string}|WP_Error
		 */
		public static function credits( int $user_id ) {
			if ( $user_id <= 0 ) {
				return new WP_Error( 'sign_in', __( 'Please sign in to post a job.', 'agend-apps-core' ), array( 'status' => 401 ) );
			}

			$credits = Agend_Apps_Jobs_Credits::available( $user_id );

			if ( is_wp_error( $credits ) ) {
				return self::credit_error( $credits );
			}

			$expiries = array_filter( array_column( $credits, 'expires_at' ) );
			sort( $expiries );

			return array(
				'available'   => count( $credits ),
				'next_expiry' => array() === $expiries ? null : (string) reset( $expiries ),
			);
		}

		/**
		 * The item from a gateway answer, or the member-facing error.
		 *
		 * @param array|WP_Error $response Gateway answer.
		 * @return array|WP_Error
		 */
		private static function item_or_error( $response ) {
			if ( is_wp_error( $response ) ) {
				return self::gateway_error( $response );
			}

			return isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : self::failed();
		}

		/**
		 * The HTTP status of a gateway error, or 0 when none came back.
		 *
		 * @param WP_Error $error Gateway error.
		 * @return int
		 */
		public static function status( WP_Error $error ): int {
			$data = $error->get_error_data();

			return is_array( $data ) && isset( $data['status_code'] ) ? (int) $data['status_code'] : 0;
		}

		/**
		 * The gateway's own error code, upper-cased, or ''.
		 *
		 * @param WP_Error $error Gateway error.
		 * @return string
		 */
		private static function gateway_code( WP_Error $error ): string {
			$data = $error->get_error_data();

			return is_array( $data ) && isset( $data['body']['error']['code'] ) && is_scalar( $data['body']['error']['code'] )
				? strtoupper( (string) $data['body']['error']['code'] )
				: '';
		}

		/**
		 * A gateway error turned into a stable code and the site's own wording;
		 * the gateway's wording never reaches the member.
		 *
		 * @param WP_Error $error Gateway error.
		 * @return WP_Error
		 */
		public static function gateway_error( WP_Error $error ): WP_Error {
			$status = self::status( $error );
			$code   = self::gateway_code( $error );

			$known = array(
				'JOB_SUBMISSIONS_DISABLED'  => array( 'disabled', 403, __( 'The jobs board is not taking job submissions right now.', 'agend-apps-core' ) ),
				'NOT_ACCOUNT_CONTACT'       => array( 'not_contact', 403, __( 'Your account is not set up to post jobs yet. Please contact us.', 'agend-apps-core' ) ),
				'NOT_ACCOUNT_MEMBER'        => array( 'not_contact', 403, __( 'Your account is not set up to post jobs yet. Please contact us.', 'agend-apps-core' ) ),
				'MISSING_USER_TOKEN'        => array( 'not_connected', 403, __( 'Your account is not connected to the jobs board yet. Please sign out and sign in again, or contact us.', 'agend-apps-core' ) ),
				'INVALID_USER_TOKEN'        => array( 'not_connected', 403, __( 'Your account is not connected to the jobs board yet. Please sign out and sign in again, or contact us.', 'agend-apps-core' ) ),
				'INSUFFICIENT_SCOPES'       => array( 'unavailable', 503, __( 'Job posting is not available on this site.', 'agend-apps-core' ) ),
				'JOB_NOT_FOUND'             => array( 'not_found', 404, __( 'We could not find that job. Please reload the page.', 'agend-apps-core' ) ),
				'JOB_NOT_EDITABLE'          => array( 'not_editable', 409, __( 'This job can no longer be changed. Please reload the page.', 'agend-apps-core' ) ),
				'INVALID_STATUS_TRANSITION' => array( 'not_editable', 409, __( 'This job can no longer be changed. Please reload the page.', 'agend-apps-core' ) ),
			);

			if ( isset( $known[ $code ] ) ) {
				return new WP_Error( $known[ $code ][0], $known[ $code ][2], array( 'status' => $known[ $code ][1] ) );
			}

			if ( false !== strpos( $code, 'VALIDATION' ) || ( '' === $code && 400 === $status ) ) {
				$data   = $error->get_error_data();
				$fields = self::field_errors( is_array( $data ) ? ( $data['body']['error']['details']['fields'] ?? null ) : null );

				if ( array() !== $fields ) {
					return new WP_Error(
						'validation_failed',
						__( 'Please check the highlighted fields and try again.', 'agend-apps-core' ),
						array(
							'status' => 400,
							'fields' => $fields,
						)
					);
				}
			}

			if ( 'agend_apps_rate_limited' !== $error->get_error_code() && ( 429 === $status || false !== strpos( $code, 'RATE_LIMIT' ) ) ) {
				return new WP_Error( 'rate_limited', __( 'Too many requests. Please wait a minute and try again.', 'agend-apps-core' ), array( 'status' => 429 ) );
			}

			if ( 404 === $status ) {
				return new WP_Error( 'not_found', __( 'We could not find that job. Please reload the page.', 'agend-apps-core' ), array( 'status' => 404 ) );
			}

			return self::failed();
		}

		/**
		 * Our message for each field the gateway named. `details.fields` is a
		 * map of field name to messages, or a list of names.
		 *
		 * @param mixed $details The gateway's `details.fields`.
		 * @return array<string, string>
		 */
		private static function field_errors( $details ): array {
			if ( ! is_array( $details ) || array() === $details ) {
				return array();
			}

			$names  = array_keys( $details ) === range( 0, count( $details ) - 1 ) ? array_values( $details ) : array_keys( $details );
			$known  = self::fields();
			$fields = array();

			foreach ( $names as $name ) {
				if ( is_string( $name ) && isset( $known[ $name ] ) ) {
					$fields[ $name ] = self::message( 'check' );
				}
			}

			return $fields;
		}

		/**
		 * A credits-layer error with a code the surface understands. Its
		 * messages are already this site's own wording.
		 *
		 * @param WP_Error $error Credits error.
		 * @return WP_Error
		 */
		private static function credit_error( WP_Error $error ): WP_Error {
			$map = array(
				'agend_jobs_credits_none'        => array( 'no_credits', 402 ),
				'agend_jobs_credits_busy'        => array( 'busy', 409 ),
				'agend_jobs_credits_unavailable' => array( 'unavailable', 503 ),
				'agend_jobs_credits_no_member'   => array( 'sign_in', 401 ),
				'agend_jobs_credit_no_member'    => array( 'no_member', 403 ),
			);

			$code   = $error->get_error_code();
			$target = $map[ $code ] ?? array( 'credits_unavailable', 503 );

			return new WP_Error( $target[0], $error->get_error_message(), array( 'status' => $target[1] ) );
		}

		/**
		 * The answer when a job may or may not have been created.
		 *
		 * @return WP_Error
		 */
		private static function unconfirmed(): WP_Error {
			return new WP_Error(
				'unconfirmed',
				__( 'We could not confirm your job was received. Please check your jobs below in a few minutes before posting it again. If it has not appeared within an hour, contact us and we will return your credit.', 'agend-apps-core' ),
				array( 'status' => 202 )
			);
		}

		/**
		 * The generic failure.
		 *
		 * @return WP_Error
		 */
		private static function failed(): WP_Error {
			return new WP_Error( 'failed', __( 'Sorry, something went wrong. Please try again later.', 'agend-apps-core' ), array( 'status' => 502 ) );
		}
	}

endif;
