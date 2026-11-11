<?php
/**
 * Member job posting credits.
 *
 * When members post jobs from the website, each submission is paid for with a
 * credit the member holds in the association's CRM. Core never talks to a CRM
 * itself: a sibling plugin supplies an {@see Agend_Apps_Jobs_Credit_Provider}
 * through the `agend_apps_jobs_credit_provider` filter, and this file
 * coordinates around it.
 *
 * The lifecycle of one credit:
 *
 * 1. {@see Agend_Apps_Jobs_Credits::reserve()} spends the member's first
 *    available credit (soonest to expire) at the provider and returns its
 *    reference. The submission is then sent to the gateway with that
 *    reference as its `credit_ref`.
 * 2. {@see Agend_Apps_Jobs_Credits::attach()} records the job the credit paid
 *    for, once the gateway has accepted the submission.
 * 3. {@see Agend_Apps_Jobs_Credits::release()} gives the credit back: straight
 *    away when the gateway refuses the submission, or later when a
 *    `jobs.submission.rejected` or `jobs.submission.withdrawn` webhook arrives.
 *
 * Every spend is recorded in a per-member ledger in user meta. The ledger is
 * what makes a release safe: a webhook is only honoured when the credit it
 * names was spent on this site and is still tied to the job the webhook is
 * about, so a duplicate or late delivery can never hand back a credit that has
 * since paid for a different job.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! interface_exists( 'Agend_Apps_Jobs_Credit_Provider' ) ) :

	/**
	 * A source of member job posting credits, such as a CRM entitlement.
	 *
	 * Implementations must be safe to call for a member with no CRM record
	 * (return no credits) and must report a lookup or write failure as a
	 * WP_Error rather than as "no credits", so a member is never told they
	 * have run out because the CRM was briefly unreachable.
	 */
	interface Agend_Apps_Jobs_Credit_Provider {

		/**
		 * Short machine name for logs, e.g. `upbeat`.
		 *
		 * @return string
		 */
		public function get_key(): string;

		/**
		 * Whether the provider is configured and its CRM connection is present.
		 *
		 * @return bool
		 */
		public function is_available(): bool;

		/**
		 * The member's unused, current credits, soonest to expire first.
		 *
		 * @param int $user_id WordPress user id.
		 * @return array<int, array{ref: string, expires_at: ?string}>|WP_Error
		 *         `expires_at` is ISO 8601 or null when the credit does not expire.
		 */
		public function get_available_credits( int $user_id );

		/**
		 * Marks one of the member's credits as used.
		 *
		 * @param int    $user_id WordPress user id.
		 * @param string $ref     Credit reference from get_available_credits().
		 * @return true|WP_Error
		 */
		public function spend( int $user_id, string $ref );

		/**
		 * Marks a previously spent credit as unused again.
		 *
		 * @param int    $user_id WordPress user id the credit was spent by.
		 * @param string $ref     Credit reference.
		 * @return true|WP_Error
		 */
		public function restore( int $user_id, string $ref );
	}

endif;

if ( ! class_exists( 'Agend_Apps_Jobs_Credits' ) ) :

	/**
	 * Coordinates member job credits around the active provider.
	 */
	final class Agend_Apps_Jobs_Credits {

		/**
		 * Ledger states.
		 */
		const STATE_RESERVED  = 'reserved';
		const STATE_SUBMITTED = 'submitted';
		const STATE_RELEASED  = 'released';

		/**
		 * Webhook event types that hand a credit back.
		 *
		 * @var string[]
		 */
		const RELEASE_EVENTS = array( 'jobs.submission.rejected', 'jobs.submission.withdrawn' );

		/**
		 * User meta key prefix. One pair of keys per credit: the bare key holds
		 * the reference (so the member can be found from a webhook) and the
		 * `_entry` key holds the ledger record.
		 *
		 * @var string
		 */
		const META_PREFIX = '_agend_apps_jobs_credit_';

		/**
		 * Seconds a member's reserve lock is honoured before it is treated as
		 * abandoned (a request that died mid-reserve).
		 *
		 * @var int
		 */
		const LOCK_TTL = 30;

		/**
		 * Registers the webhook listener.
		 *
		 * @return void
		 */
		public static function register(): void {
			add_action( 'agend_apps_webhook_received', array( __CLASS__, 'handle_webhook' ), 10, 2 );
		}

		/**
		 * The active provider, or null when no provider is registered or it is
		 * not available.
		 *
		 * @return Agend_Apps_Jobs_Credit_Provider|null
		 */
		public static function provider(): ?Agend_Apps_Jobs_Credit_Provider {
			/**
			 * Filters the provider of member job posting credits.
			 *
			 * @param Agend_Apps_Jobs_Credit_Provider|null $provider Null when none is registered.
			 */
			$provider = apply_filters( 'agend_apps_jobs_credit_provider', null );

			if ( ! ( $provider instanceof Agend_Apps_Jobs_Credit_Provider ) || ! $provider->is_available() ) {
				return null;
			}

			return $provider;
		}

		/**
		 * Whether member job credits are available on this site.
		 *
		 * @return bool
		 */
		public static function enabled(): bool {
			return null !== self::provider();
		}

		/**
		 * The member's unused credits, leaving out any this site has already
		 * spent but the CRM still reports as unused (a stale read).
		 *
		 * @param int $user_id WordPress user id.
		 * @return array<int, array{ref: string, expires_at: ?string}>|WP_Error
		 */
		public static function available( int $user_id ) {
			$provider = self::provider();

			if ( null === $provider ) {
				return self::unavailable_error();
			}

			if ( $user_id <= 0 ) {
				return new WP_Error( 'agend_jobs_credits_no_member', __( 'Sign in to post a job.', 'agend-apps-core' ), array( 'status' => 401 ) );
			}

			$credits = $provider->get_available_credits( $user_id );

			if ( is_wp_error( $credits ) ) {
				return $credits;
			}

			$available = array();

			foreach ( (array) $credits as $credit ) {
				$ref = isset( $credit['ref'] ) ? (string) $credit['ref'] : '';

				if ( '' === $ref ) {
					continue;
				}

				$entry = self::entry( $user_id, $ref );

				if ( null !== $entry && self::STATE_RELEASED !== $entry['state'] ) {
					continue;
				}

				$available[] = array(
					'ref'        => $ref,
					'expires_at' => isset( $credit['expires_at'] ) && '' !== (string) $credit['expires_at'] ? (string) $credit['expires_at'] : null,
				);
			}

			return $available;
		}

		/**
		 * Spends the member's first available credit and records it.
		 *
		 * Only one reserve runs per member at a time, so a double-clicked
		 * submit cannot spend the same credit twice.
		 *
		 * @param int $user_id WordPress user id.
		 * @return string|WP_Error The spent credit's reference.
		 */
		public static function reserve( int $user_id ) {
			$provider = self::provider();

			if ( null === $provider ) {
				return self::unavailable_error();
			}

			if ( $user_id <= 0 ) {
				return new WP_Error( 'agend_jobs_credits_no_member', __( 'Sign in to post a job.', 'agend-apps-core' ), array( 'status' => 401 ) );
			}

			if ( ! self::lock( $user_id ) ) {
				return new WP_Error( 'agend_jobs_credits_busy', __( 'Another job submission is still being processed. Please try again in a moment.', 'agend-apps-core' ), array( 'status' => 409 ) );
			}

			try {
				$credits = self::available( $user_id );

				if ( is_wp_error( $credits ) ) {
					return $credits;
				}

				if ( empty( $credits ) ) {
					return new WP_Error( 'agend_jobs_credits_none', __( 'You have no job posting credits left.', 'agend-apps-core' ), array( 'status' => 402 ) );
				}

				$ref   = $credits[0]['ref'];
				$spent = $provider->spend( $user_id, $ref );

				if ( is_wp_error( $spent ) ) {
					return $spent;
				}

				self::write_entry(
					$user_id,
					$ref,
					array(
						'state'    => self::STATE_RESERVED,
						'job_id'   => '',
						'spent_at' => gmdate( 'c' ),
					)
				);

				return $ref;
			} finally {
				self::unlock( $user_id );
			}
		}

		/**
		 * Records the job a reserved credit paid for.
		 *
		 * @param int    $user_id WordPress user id.
		 * @param string $ref     Credit reference from reserve().
		 * @param string $job_id  Gateway job id.
		 * @return bool False when the credit is not reserved by this member.
		 */
		public static function attach( int $user_id, string $ref, string $job_id ): bool {
			$entry = self::entry( $user_id, $ref );

			if ( null === $entry || self::STATE_RESERVED !== $entry['state'] || '' === $job_id ) {
				return false;
			}

			$entry['state']  = self::STATE_SUBMITTED;
			$entry['job_id'] = $job_id;

			self::write_entry( $user_id, $ref, $entry );

			return true;
		}

		/**
		 * Gives a spent credit back.
		 *
		 * With a job id, the credit is only released when it is still tied to
		 * that job. Without one, only a credit that never reached a job (the
		 * gateway refused the submission) is released.
		 *
		 * @param string $ref    Credit reference.
		 * @param string $job_id Gateway job id, or '' for a refused submission.
		 * @return true|WP_Error
		 */
		public static function release( string $ref, string $job_id = '' ) {
			$provider = self::provider();

			if ( null === $provider ) {
				return self::unavailable_error();
			}

			$user_id = self::owner( $ref );

			if ( 0 === $user_id ) {
				return new WP_Error( 'agend_jobs_credits_unknown', 'No member on this site spent this credit.', array( 'status' => 404 ) );
			}

			$entry    = self::entry( $user_id, $ref );
			$expected = '' === $job_id ? self::STATE_RESERVED : self::STATE_SUBMITTED;

			if ( null === $entry || $expected !== $entry['state'] || ( '' !== $job_id && $job_id !== $entry['job_id'] ) ) {
				return new WP_Error( 'agend_jobs_credits_stale', 'The credit is not tied to this job, so it was left as it is.', array( 'status' => 409 ) );
			}

			$restored = $provider->restore( $user_id, $ref );

			if ( is_wp_error( $restored ) ) {
				return $restored;
			}

			$entry['state']       = self::STATE_RELEASED;
			$entry['released_at'] = gmdate( 'c' );

			self::write_entry( $user_id, $ref, $entry );

			return true;
		}

		/**
		 * Releases the credit behind a rejected or withdrawn submission.
		 *
		 * The delivery has already been signature checked by the receiver. The
		 * ledger check in release() means only a credit this site spent, on
		 * the job the event names, is ever handed back.
		 *
		 * @param string $type     Event type.
		 * @param array  $envelope Decoded delivery envelope.
		 * @return void
		 */
		public static function handle_webhook( $type, $envelope ): void {
			if ( ! in_array( (string) $type, self::RELEASE_EVENTS, true ) || ! is_array( $envelope ) ) {
				return;
			}

			$data   = ( isset( $envelope['data'] ) && is_array( $envelope['data'] ) ) ? $envelope['data'] : array();
			$ref    = isset( $data['credit_ref'] ) && is_scalar( $data['credit_ref'] ) ? trim( (string) $data['credit_ref'] ) : '';
			$job_id = isset( $data['job_id'] ) && is_scalar( $data['job_id'] ) ? trim( (string) $data['job_id'] ) : '';

			if ( '' === $ref || '' === $job_id || null === self::provider() ) {
				return;
			}

			$result = self::release( $ref, $job_id );

			if ( is_wp_error( $result ) ) {
				self::log(
					'Job credit not released for ' . $type . ': ' . $result->get_error_message(),
					array(
						'job_id' => $job_id,
						'code'   => $result->get_error_code(),
					)
				);
			}
		}

		/**
		 * Logs a credit event under WP_DEBUG and exposes it to site monitoring.
		 *
		 * @param string $message Message.
		 * @param array  $context Structured context. Never includes the credit reference.
		 * @return void
		 */
		private static function log( string $message, array $context = array() ): void {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[Agend Apps] ' . $message . ' ' . wp_json_encode( $context ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			}

			/**
			 * Fires on every job credit log event.
			 *
			 * @param string $message Log message.
			 * @param array  $context Structured context.
			 */
			do_action( 'agend_apps_jobs_credits_log', $message, $context );
		}

		/**
		 * The ledger record for one of a member's credits.
		 *
		 * @param int    $user_id WordPress user id.
		 * @param string $ref     Credit reference.
		 * @return array{state: string, job_id: string, spent_at: string}|null
		 */
		public static function entry( int $user_id, string $ref ): ?array {
			$entry = get_user_meta( $user_id, self::meta_key( $ref ) . '_entry', true );

			if ( ! is_array( $entry ) || ! isset( $entry['state'] ) ) {
				return null;
			}

			$entry['job_id'] = isset( $entry['job_id'] ) ? (string) $entry['job_id'] : '';

			return $entry;
		}

		/**
		 * The user meta key for a credit reference.
		 *
		 * @param string $ref Credit reference.
		 * @return string
		 */
		private static function meta_key( string $ref ): string {
			return self::META_PREFIX . md5( $ref );
		}

		/**
		 * Writes a ledger record, and the lookup key that finds its member.
		 *
		 * @param int    $user_id WordPress user id.
		 * @param string $ref     Credit reference.
		 * @param array  $entry   Ledger record.
		 * @return void
		 */
		private static function write_entry( int $user_id, string $ref, array $entry ): void {
			update_user_meta( $user_id, self::meta_key( $ref ), $ref );
			update_user_meta( $user_id, self::meta_key( $ref ) . '_entry', $entry );
		}

		/**
		 * The member who spent a credit on this site, or 0.
		 *
		 * @param string $ref Credit reference.
		 * @return int
		 */
		private static function owner( string $ref ): int {
			if ( '' === $ref ) {
				return 0;
			}

			$ids = get_users(
				array(
					'meta_key'    => self::meta_key( $ref ),
					'meta_value'  => $ref,
					'number'      => 1,
					'fields'      => 'ID',
					'count_total' => false,
				)
			);

			return empty( $ids ) ? 0 : (int) $ids[0];
		}

		/**
		 * Takes the member's reserve lock. add_option() is atomic, so only one
		 * request can hold it; a lock older than LOCK_TTL is treated as left
		 * behind by a request that died and is taken over.
		 *
		 * @param int $user_id WordPress user id.
		 * @return bool
		 */
		private static function lock( int $user_id ): bool {
			$name = 'agend_apps_jobs_credit_lock_' . $user_id;

			if ( add_option( $name, time(), '', 'no' ) ) {
				return true;
			}

			$held_since = (int) get_option( $name, 0 );

			if ( $held_since > 0 && time() - $held_since < self::LOCK_TTL ) {
				return false;
			}

			delete_option( $name );

			return (bool) add_option( $name, time(), '', 'no' );
		}

		/**
		 * Releases the member's reserve lock.
		 *
		 * @param int $user_id WordPress user id.
		 * @return void
		 */
		private static function unlock( int $user_id ): void {
			delete_option( 'agend_apps_jobs_credit_lock_' . $user_id );
		}

		/**
		 * The error returned when no credit provider is active.
		 *
		 * @return WP_Error
		 */
		private static function unavailable_error(): WP_Error {
			return new WP_Error( 'agend_jobs_credits_unavailable', __( 'Job posting is not available on this site.', 'agend-apps-core' ), array( 'status' => 503 ) );
		}
	}

endif;
