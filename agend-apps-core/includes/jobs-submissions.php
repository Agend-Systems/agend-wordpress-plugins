<?php
/**
 * Member job submissions: a signed-in member posts a job to the board through
 * this site, paid for with one of their job credits, and staff approve it in
 * Agend before it goes live.
 *
 * This is the server side of the Member Jobs surface. Agend decides
 * everything about credits: when a job is posted it finds or creates the
 * member's contact, accepts the job as `checking_credit`, and checks in the
 * background for an unused credit. The job then becomes `pending` (a credit
 * paid for it) or `no_credit` (nothing was spent). The browser polls the job
 * for up to 30 seconds to show which.
 *
 * Every call uses the site's API key and names the member by their contact
 * pair ({@see Agend_Apps_Jobs_Credits::contact()}). The member never signs in
 * to Agend.
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
		 * The scope a new submission needs.
		 *
		 * @var string
		 */
		const CREATE_SCOPE = 'jobs.submissions.create';

		/**
		 * The scope an edit, withdrawal or close needs.
		 *
		 * @var string
		 */
		const MANAGE_SCOPE = 'jobs.submissions.manage';

		/**
		 * The scope the list and a single job need.
		 *
		 * @var string
		 */
		const READ_SCOPE = 'jobs.submissions.browse';

		/**
		 * The longest description a member may type. The gateway's 10000
		 * applies to the HTML it is turned into, which escaping and the
		 * paragraph tags make longer.
		 *
		 * @var int
		 */
		const DESCRIPTION_TEXT_MAX = 8000;

		/**
		 * Item keys never sent to the browser: the credit reference names the
		 * CRM record, the account id is the organisation's, and the contact
		 * block holds Agend's own ids.
		 *
		 * @var string[]
		 */
		const PRIVATE_KEYS = array( 'credit_ref', 'account_id', 'contact' );

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
				'title'                    => array(
					'type' => 'text',
					'max'  => 200,
				),
				'short_description'        => array(
					'type'     => 'textarea',
					'max'      => 500,
					'nullable' => true,
				),
				'description'              => array(
					'type'     => 'html',
					'max'      => 10000,
					'nullable' => true,
				),
				'company_name'             => array(
					'type'     => 'text',
					'max'      => 200,
					'nullable' => true,
				),
				'company_website'          => array(
					'type'     => 'url',
					'max'      => 1000,
					'nullable' => true,
				),
				'employment_type'          => array(
					'type'   => 'enum',
					'values' => array( 'full-time', 'part-time', 'contract', 'casual', 'internship' ),
				),
				'experience_level'         => array(
					'type'     => 'enum',
					'values'   => array( 'entry', 'mid', 'senior', 'executive' ),
					'nullable' => true,
				),
				'location_type'            => array(
					'type'   => 'enum',
					'values' => array( 'on-site', 'hybrid', 'remote' ),
				),
				'location_city'            => array(
					'type'     => 'text',
					'max'      => 100,
					'nullable' => true,
				),
				'location_state'           => array(
					'type'     => 'text',
					'max'      => 100,
					'nullable' => true,
				),
				'location_postcode'        => array(
					'type'     => 'text',
					'max'      => 10,
					'nullable' => true,
				),
				'location_country'         => array(
					'type'     => 'text',
					'max'      => 100,
					'nullable' => true,
				),
				'salary_min'               => array(
					'type'     => 'int',
					'max'      => 100000000,
					'nullable' => true,
				),
				'salary_max'               => array(
					'type'     => 'int',
					'max'      => 100000000,
					'nullable' => true,
				),
				'salary_period'            => array(
					'type'   => 'enum',
					'values' => array( 'hourly', 'annual', 'project' ),
				),
				'show_salary'              => array( 'type' => 'bool' ),
				'application_url'          => array(
					'type'     => 'url',
					'max'      => 1000,
					'nullable' => true,
				),
				'application_email'        => array(
					'type'     => 'email',
					'max'      => 320,
					'nullable' => true,
				),
				'application_instructions' => array(
					'type'     => 'textarea',
					'max'      => 5000,
					'nullable' => true,
				),
				'reference_number'         => array(
					'type'     => 'text',
					'max'      => 100,
					'nullable' => true,
				),
				'contact_name'             => array(
					'type'     => 'text',
					'max'      => 200,
					'nullable' => true,
				),
				'contact_phone'            => array(
					'type'     => 'text',
					'max'      => 50,
					'nullable' => true,
				),
				'category_ids'             => array(
					'type' => 'ids',
					'max'  => 50,
				),
				'tag_ids'                  => array(
					'type' => 'ids',
					'max'  => 50,
				),
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
					$plain = sanitize_textarea_field( $text );
					if ( mb_strlen( $plain ) > self::DESCRIPTION_TEXT_MAX ) {
						return array( 'error' => 'too_long' );
					}
					return self::fits( self::paragraphs( $plain ), $max );

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
		 * Whether this site can offer job posting at all: the credits layer is
		 * loaded and the API key is not known to lack the scope to post.
		 *
		 * @return bool
		 */
		public static function available(): bool {
			return class_exists( 'Agend_Apps_Jobs_Credits' ) && ! self::lacks( self::CREATE_SCOPE );
		}

		/**
		 * Whether the site's key is known to lack a scope. Only a scope list
		 * the site has actually read can rule a call out.
		 *
		 * @param string $scope Scope.
		 * @return bool
		 */
		private static function lacks( string $scope ): bool {
			return class_exists( 'Agend_Apps_Key_Scopes' ) && Agend_Apps_Key_Scopes::known() && ! Agend_Apps_Key_Scopes::has( $scope );
		}

		/**
		 * The member's contact pair, once everything that can be checked
		 * without a call has passed.
		 *
		 * @param int    $user_id WordPress user id.
		 * @param string $scope   The scope the call needs.
		 * @return array{external_source: string, external_id: string}|WP_Error
		 */
		public static function precheck( int $user_id, string $scope = self::MANAGE_SCOPE ) {
			if ( $user_id <= 0 ) {
				return new WP_Error( 'sign_in', __( 'Please sign in to post a job.', 'agend-apps-core' ), array( 'status' => 401 ) );
			}

			if ( ! class_exists( 'Agend_Apps_Jobs_Credits' ) || self::lacks( $scope ) ) {
				return self::not_offered();
			}

			// The API client would refuse the call without sending it.
			$remaining = get_transient( 'agend_apps_rate_limit_remaining' );
			if ( false !== $remaining && 0 === (int) $remaining ) {
				return self::rate_limited();
			}

			$contact = Agend_Apps_Jobs_Credits::contact( $user_id );

			if ( null === $contact ) {
				return new WP_Error( 'not_connected', __( 'Your account does not have a membership number yet, so it cannot post jobs. Please contact us.', 'agend-apps-core' ), array( 'status' => 403 ) );
			}

			return $contact;
		}

		/**
		 * The contact block a new submission carries: the pair plus the
		 * member's name and email, which Agend uses only when it has to create
		 * the member's contact. It never matches a contact by email.
		 *
		 * @param int   $user_id WordPress user id.
		 * @param array $contact The member's contact pair.
		 * @return array|WP_Error
		 */
		public static function submitter( int $user_id, array $contact ) {
			$user  = get_userdata( $user_id );
			$first = trim( (string) get_user_meta( $user_id, 'first_name', true ) );
			$last  = trim( (string) get_user_meta( $user_id, 'last_name', true ) );
			$email = $user ? trim( (string) $user->user_email ) : '';

			if ( '' === $first || '' === $last || mb_strlen( $first ) > 100 || mb_strlen( $last ) > 100 ) {
				return new WP_Error( 'profile_incomplete', __( 'Please add your first and last name to your account details, then post your job.', 'agend-apps-core' ), array( 'status' => 400 ) );
			}

			if ( '' === $email || ! is_email( $email ) || strlen( $email ) > 320 ) {
				return new WP_Error( 'profile_incomplete', __( 'Please add a valid email address to your account details, then post your job.', 'agend-apps-core' ), array( 'status' => 400 ) );
			}

			return array(
				'external_source' => $contact['external_source'],
				'external_id'     => $contact['external_id'],
				'first_name'      => $first,
				'last_name'       => $last,
				'email'           => $email,
			);
		}

		/**
		 * The Idempotency-Key for one attempt to post a job: the browser's
		 * request id for the form, scoped to the member. The browser keeps the
		 * id while the outcome is unknown, so a retry can never post twice.
		 *
		 * @param int    $user_id    WordPress user id.
		 * @param string $request_id The browser's request id.
		 * @return string
		 */
		public static function idempotency_key( int $user_id, string $request_id ): string {
			if ( 1 !== preg_match( '/^[A-Za-z0-9-]{16,64}$/', $request_id ) ) {
				$request_id = wp_generate_uuid4();
			}

			return 'wp-' . $user_id . '-' . strtolower( $request_id );
		}

		/**
		 * Posts the member's job. Agend checks for a credit in the background.
		 *
		 * @param int    $user_id    WordPress user id.
		 * @param array  $fields     Fields from {@see sanitize()}, with no errors.
		 * @param string $request_id The browser's request id for this form.
		 * @return array{id: string, status: string}|WP_Error The accepted job,
		 *                     usually `checking_credit`, or an error whose code
		 *                     the Member Jobs surface understands.
		 */
		public static function submit( int $user_id, array $fields, string $request_id ) {
			$contact = self::precheck( $user_id, self::CREATE_SCOPE );

			if ( is_wp_error( $contact ) ) {
				return $contact;
			}

			$submitter = self::submitter( $user_id, $contact );

			if ( is_wp_error( $submitter ) ) {
				return $submitter;
			}

			$response = agend_apps_jobs_create_submission( $submitter, $fields, self::idempotency_key( $user_id, $request_id ) );

			if ( is_wp_error( $response ) ) {
				$status = self::status( $response );

				// No answer, or a server error: the job may exist.
				if ( 0 === $status || $status >= 500 ) {
					return 'agend_apps_rate_limited' === $response->get_error_code() ? self::rate_limited() : self::unconfirmed();
				}

				return self::gateway_error( $response );
			}

			$item = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array();
			$id   = isset( $item['id'] ) && is_string( $item['id'] ) ? strtolower( $item['id'] ) : '';

			if ( false === ( $response['success'] ?? true ) || ! self::is_uuid( $id ) ) {
				return self::unconfirmed();
			}

			// A credit is about to be spent: the next count must ask Agend.
			Agend_Apps_Jobs_Credits::forget( $contact );

			return array(
				'id'     => $id,
				'status' => self::display_status( $item ),
			);
		}

		/**
		 * One of the member's submissions. The browser polls this after a post.
		 *
		 * @param int    $user_id WordPress user id.
		 * @param string $job_id  The submission's id.
		 * @return array|WP_Error The item.
		 */
		public static function get( int $user_id, string $job_id ) {
			$contact = self::precheck( $user_id, self::READ_SCOPE );

			if ( is_wp_error( $contact ) ) {
				return $contact;
			}

			$item = self::item_or_error( agend_apps_jobs_get_submission( $job_id, $contact ) );

			// The check spent a credit, or found none: either way, recount.
			if ( ! is_wp_error( $item ) && 'checking_credit' !== ( $item['status'] ?? '' ) ) {
				Agend_Apps_Jobs_Credits::forget( $contact );
			}

			return $item;
		}

		/**
		 * Applies an edit to a pending submission.
		 *
		 * @param int    $user_id WordPress user id.
		 * @param string $job_id  The submission's id.
		 * @param array  $fields  Fields from {@see sanitize()}, with no errors.
		 * @return array|WP_Error The updated item.
		 */
		public static function update( int $user_id, string $job_id, array $fields ) {
			$contact = self::precheck( $user_id );

			if ( is_wp_error( $contact ) ) {
				return $contact;
			}

			return self::item_or_error( agend_apps_jobs_update_submission( $job_id, $contact, $fields ) );
		}

		/**
		 * Withdraws a pending submission. Agend frees its credit.
		 *
		 * @param int    $user_id WordPress user id.
		 * @param string $job_id  The submission's id.
		 * @return array|WP_Error The withdrawn item.
		 */
		public static function withdraw( int $user_id, string $job_id ) {
			$contact = self::precheck( $user_id );

			if ( is_wp_error( $contact ) ) {
				return $contact;
			}

			$item = self::item_or_error( agend_apps_jobs_withdraw_submission( $job_id, $contact ) );

			if ( ! is_wp_error( $item ) ) {
				Agend_Apps_Jobs_Credits::forget( $contact );
			}

			return $item;
		}

		/**
		 * Takes a live job off the board. The credit stays spent.
		 *
		 * @param int    $user_id WordPress user id.
		 * @param string $job_id  The submission's id.
		 * @param bool   $filled  Whether the position was filled.
		 * @return array|WP_Error The closed item.
		 */
		public static function close( int $user_id, string $job_id, bool $filled ) {
			$contact = self::precheck( $user_id );

			if ( is_wp_error( $contact ) ) {
				return $contact;
			}

			return self::item_or_error( agend_apps_jobs_close_submission( $job_id, $contact, $filled ) );
		}

		/**
		 * The member's submissions, newest first, every status.
		 *
		 * @param int $user_id WordPress user id.
		 * @param int $page    Page number.
		 * @return array{items: array, pagination: array}|WP_Error
		 */
		public static function mine( int $user_id, int $page = 1 ) {
			$contact = self::precheck( $user_id, self::READ_SCOPE );

			if ( is_wp_error( $contact ) ) {
				return $contact;
			}

			$response = agend_apps_jobs_get_submissions(
				$contact,
				array(
					'page'  => max( 1, $page ),
					'limit' => 20,
				)
			);

			if ( is_wp_error( $response ) ) {
				return self::gateway_error( $response );
			}

			return array(
				'items'      => isset( $response['data'] ) && is_array( $response['data'] ) ? array_map( array( __CLASS__, 'public_item' ), array_values( array_filter( $response['data'], 'is_array' ) ) ) : array(),
				'pagination' => isset( $response['meta']['pagination'] ) && is_array( $response['meta']['pagination'] ) ? $response['meta']['pagination'] : array(),
			);
		}

		/**
		 * How many unused job credits the member has, as Agend counts them.
		 *
		 * @param int $user_id WordPress user id.
		 * @return array{available: int}|WP_Error
		 */
		public static function credits( int $user_id ) {
			$contact = self::precheck( $user_id, self::READ_SCOPE );

			if ( is_wp_error( $contact ) ) {
				return $contact;
			}

			$count = Agend_Apps_Jobs_Credits::count( $user_id );

			if ( ! is_wp_error( $count ) ) {
				return array( 'available' => $count );
			}

			if ( 'agend_jobs_credits_not_available' === $count->get_error_code() ) {
				return self::not_offered();
			}

			return new WP_Error( 'credits_unavailable', $count->get_error_message(), array( 'status' => 503 ) );
		}

		/**
		 * Whether a string is a UUID.
		 *
		 * @param string $value Value.
		 * @return bool
		 */
		public static function is_uuid( string $value ): bool {
			return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value );
		}

		/**
		 * The status the member sees. Agend's `approved` is shown as `published`
		 * or, past its listing period, `expired`; `closed` as `filled` when the
		 * member said the position was filled. The rest pass through.
		 *
		 * @param array $item Gateway submission item.
		 * @return string
		 */
		public static function display_status( array $item ): string {
			$status = (string) ( $item['status'] ?? '' );

			if ( 'approved' === $status ) {
				return empty( $item['expired'] ) ? 'published' : 'expired';
			}

			if ( 'closed' === $status && ! empty( $item['filled'] ) ) {
				return 'filled';
			}

			return $status;
		}

		/**
		 * An item as the browser gets it: without {@see PRIVATE_KEYS}, and with
		 * the status the member sees.
		 *
		 * @param array $item Gateway submission item.
		 * @return array
		 */
		public static function public_item( array $item ): array {
			$public           = array_diff_key( $item, array_flip( self::PRIVATE_KEYS ) );
			$public['status'] = self::display_status( $item );

			return $public;
		}

		/**
		 * The public item from a gateway answer, or the member-facing error.
		 *
		 * @param array|WP_Error $response Gateway answer.
		 * @return array|WP_Error
		 */
		private static function item_or_error( $response ) {
			if ( is_wp_error( $response ) ) {
				return self::gateway_error( $response );
			}

			return isset( $response['data'] ) && is_array( $response['data'] ) ? self::public_item( $response['data'] ) : self::failed();
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
			if ( 'agend_apps_scope_missing' === $error->get_error_code() ) {
				return 'INSUFFICIENT_SCOPES';
			}

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

			$offline   = __( 'Job posting is not available on this site.', 'agend-apps-core' );
			$not_found = __( 'We could not find that job. Please reload the page.', 'agend-apps-core' );
			$stale     = __( 'This job can no longer be changed. Please reload the page.', 'agend-apps-core' );

			$known = array(
				'JOB_SUBMISSIONS_DISABLED'   => array( 'disabled', 403, __( 'The jobs board is not taking job submissions right now.', 'agend-apps-core' ) ),
				'SOURCE_NOT_ALLOWED'         => array( 'unavailable', 503, $offline ),
				'JOB_CREDITS_NOT_CONFIGURED' => array( 'unavailable', 503, $offline ),
				'INSUFFICIENT_SCOPES'        => array( 'unavailable', 503, $offline ),
				'CONTACT_CONFLICT'           => array( 'not_contact', 409, __( 'Your account matches more than one record, so jobs cannot be posted from it yet. Please contact us.', 'agend-apps-core' ) ),
				'JOB_NOT_FOUND'              => array( 'not_found', 404, $not_found ),
				'JOB_NOT_EDITABLE'           => array( 'not_editable', 409, $stale ),
				'INVALID_STATUS_TRANSITION'  => array( 'not_editable', 409, $stale ),
			);

			if ( isset( $known[ $code ] ) ) {
				return new WP_Error( $known[ $code ][0], $known[ $code ][2], array( 'status' => $known[ $code ][1] ) );
			}

			// This form was already sent: the first attempt may have landed.
			if ( 'IDEMPOTENCY_KEY_REUSED' === $code || 'IDEMPOTENCY_IN_PROGRESS' === $code ) {
				return self::unconfirmed();
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

			if ( 'agend_apps_rate_limited' === $error->get_error_code() || 429 === $status || false !== strpos( $code, 'RATE_LIMIT' ) ) {
				return self::rate_limited();
			}

			if ( 404 === $status ) {
				return new WP_Error( 'not_found', $not_found, array( 'status' => 404 ) );
			}

			return self::failed();
		}

		/**
		 * Our message for each field the gateway named. `details.fields` is a
		 * map of field name to messages, or a list of names. Names may be
		 * prefixed with `job.`, the body key the fields are sent under.
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
				$name = is_string( $name ) && 0 === strpos( $name, 'job.' ) ? substr( $name, 4 ) : $name;

				if ( is_string( $name ) && isset( $known[ $name ] ) ) {
					$fields[ $name ] = self::message( 'check' );
				}
			}

			return $fields;
		}

		/**
		 * The answer when the site cannot offer job posting.
		 *
		 * @return WP_Error
		 */
		private static function not_offered(): WP_Error {
			return new WP_Error( 'unavailable', __( 'Job posting is not available on this site.', 'agend-apps-core' ), array( 'status' => 503 ) );
		}

		/**
		 * The answer when the site's API key is out of requests for now.
		 *
		 * @return WP_Error
		 */
		private static function rate_limited(): WP_Error {
			return new WP_Error( 'rate_limited', __( 'Too many requests. Please wait a minute and try again.', 'agend-apps-core' ), array( 'status' => 429 ) );
		}

		/**
		 * The answer when a job may or may not have been posted.
		 *
		 * @return WP_Error
		 */
		private static function unconfirmed(): WP_Error {
			return new WP_Error(
				'unconfirmed',
				__( 'We could not confirm your job was received. Please check your jobs below in a minute before posting it again.', 'agend-apps-core' ),
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
