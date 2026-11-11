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
 * The ledger is one record per credit (an option, not user meta), because a
 * credit can be shared: members of a firm draw on the firm's credits. Every
 * change to a record happens under that credit's lock, so a credit is never
 * spent twice and a release always finds the member who spent it. The ledger
 * is also what makes a release safe: a webhook is only honoured for a credit
 * this site spent, on the job the webhook names, so a duplicate or late
 * delivery can never hand back a credit that has since paid for another job.
 * A release the CRM cannot confirm is retried on cron rather than lost.
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
		 *
		 * - spending: a spend is in progress at the provider.
		 * - reserved: spent, the submission has not been accepted yet.
		 * - submitted: spent on the job in `job_id`.
		 * - release_pending: to be given back; the provider has not confirmed it yet.
		 * - released: given back, free to be spent again.
		 */
		const STATE_SPENDING        = 'spending';
		const STATE_RESERVED        = 'reserved';
		const STATE_SUBMITTED       = 'submitted';
		const STATE_RELEASE_PENDING = 'release_pending';
		const STATE_RELEASED        = 'released';

		/**
		 * States in which a credit is tied up and must not be spent again.
		 *
		 * A `submitted` credit is not in this list: once its job is live, the
		 * provider is the record. If the CRM shows it unused again, staff gave
		 * it back by hand and the member may spend it.
		 *
		 * @var string[]
		 */
		const BUSY_STATES = array( self::STATE_SPENDING, self::STATE_RESERVED, self::STATE_RELEASE_PENDING );

		/**
		 * Webhook event types that hand a credit back.
		 *
		 * @var string[]
		 */
		const RELEASE_EVENTS = array( 'jobs.submission.rejected', 'jobs.submission.withdrawn' );

		/**
		 * Option name prefix of a credit's ledger record.
		 *
		 * @var string
		 */
		const LEDGER_PREFIX = 'agend_apps_jobs_credit_';

		/**
		 * Option holding the credits whose release is waiting for a retry,
		 * as ledger key => attempts so far.
		 *
		 * @var string
		 */
		const PENDING_OPTION = 'agend_apps_jobs_credits_pending';

		/**
		 * Lock option name prefixes.
		 *
		 * @var string
		 */
		const CREDIT_LOCK_PREFIX = 'agend_apps_jobs_credit_lock_';
		const MEMBER_LOCK_PREFIX = 'agend_apps_jobs_member_lock_';

		/**
		 * Seconds before a lock is treated as left behind by a request that
		 * died. Longer than several slow CRM calls in a row, so a live request
		 * never loses its lock.
		 *
		 * @var int
		 */
		const LOCK_TTL = 600;

		/**
		 * How long attach() and release() wait for a credit's lock, in seconds.
		 *
		 * @var int
		 */
		const LOCK_WAIT = 10;

		/**
		 * Cron hook that retries pending releases.
		 *
		 * @var string
		 */
		const RETRY_HOOK = 'agend_apps_jobs_credits_retry';

		/**
		 * Seconds between retries of pending releases.
		 *
		 * @var int
		 */
		const RETRY_DELAY = 900;

		/**
		 * Retries before a pending release is given up and logged for staff.
		 *
		 * @var int
		 */
		const MAX_ATTEMPTS = 96;

		/**
		 * Job ids kept per credit to refuse late events about earlier jobs.
		 *
		 * @var int
		 */
		const HISTORY_LIMIT = 20;

		/**
		 * Microseconds between lock attempts while waiting. Overridable in tests.
		 *
		 * @var int
		 */
		public static $lock_poll = 250000;

		/**
		 * Registers the webhook listener and the retry hook.
		 *
		 * @return void
		 */
		public static function register(): void {
			add_action( 'agend_apps_webhook_received', array( __CLASS__, 'handle_webhook' ), 10, 2 );
			add_action( self::RETRY_HOOK, array( __CLASS__, 'retry_pending' ) );
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
		 * The member's unused credits, leaving out any tied up in a submission
		 * that is still in progress here.
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
				return self::no_member_error();
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

				$entry = self::entry( $ref );

				if ( null !== $entry && in_array( $entry['state'], self::BUSY_STATES, true ) ) {
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
		 * One reserve runs per member at a time (a double submit is refused),
		 * and each credit is spent under its own lock, so two members of a firm
		 * sharing the firm's credits never spend the same one. The ledger
		 * record is written before the provider is called, so a spend whose
		 * outcome is unknown is always given back rather than lost.
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
				return self::no_member_error();
			}

			$member_lock = self::acquire_lock( self::MEMBER_LOCK_PREFIX . $user_id );

			if ( null === $member_lock ) {
				return self::busy_error();
			}

			try {
				$credits = self::available( $user_id );

				if ( is_wp_error( $credits ) ) {
					return $credits;
				}

				$contended = false;

				foreach ( $credits as $credit ) {
					$ref  = $credit['ref'];
					$lock = self::acquire_lock( self::credit_lock_name( $ref ) );

					if ( null === $lock ) {
						$contended = true;
						continue;
					}

					try {
						$entry = self::entry( $ref );

						if ( null !== $entry && in_array( $entry['state'], self::BUSY_STATES, true ) ) {
							$contended = true;
							continue;
						}

						return self::spend_locked( $provider, $user_id, $ref, $entry );
					} finally {
						self::release_lock( self::credit_lock_name( $ref ), $lock );
					}
				}

				if ( $contended ) {
					return self::busy_error();
				}

				return new WP_Error( 'agend_jobs_credits_none', __( 'You have no job posting credits left.', 'agend-apps-core' ), array( 'status' => 402 ) );
			} finally {
				self::release_lock( self::MEMBER_LOCK_PREFIX . $user_id, $member_lock );
			}
		}

		/**
		 * Records the job a reserved credit paid for.
		 *
		 * @param int    $user_id WordPress user id.
		 * @param string $ref     Credit reference from reserve().
		 * @param string $job_id  Gateway job id.
		 * @return bool False when the credit is not reserved by this member,
		 *              including when a rejection for the job arrived first and
		 *              the credit is already being given back.
		 */
		public static function attach( int $user_id, string $ref, string $job_id ): bool {
			if ( '' === $job_id ) {
				return false;
			}

			return (bool) self::with_credit_lock(
				$ref,
				static function () use ( $user_id, $ref, $job_id ): bool {
					$entry = self::entry( $ref );

					if ( null === $entry || self::STATE_RESERVED !== $entry['state'] || $user_id !== $entry['user_id'] ) {
						return false;
					}

					$entry['state']  = self::STATE_SUBMITTED;
					$entry['job_id'] = $job_id;
					self::write_entry( $ref, $entry );

					return true;
				},
				false
			);
		}

		/**
		 * Gives a spent credit back.
		 *
		 * Without a job id, only a credit that never reached a job (the gateway
		 * refused the submission) is released. With one, the credit must be
		 * tied to that job, or still be reserved for a submission that has not
		 * been attached yet (a rejection can arrive before attach() runs). An
		 * event about a job the credit has already been given back for is
		 * ignored, so a late duplicate cannot free a credit that has paid for
		 * another job since.
		 *
		 * When the provider cannot confirm the release, the credit stays
		 * `release_pending` and is retried by cron, so it is never lost.
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

			return self::with_credit_lock(
				$ref,
				static function () use ( $provider, $ref, $job_id ) {
					$entry = self::entry( $ref );

					if ( null === $entry ) {
						return new WP_Error( 'agend_jobs_credits_unknown', 'No member on this site spent this credit.', array( 'status' => 404 ) );
					}

					if ( '' !== $job_id && in_array( $job_id, $entry['history'], true ) ) {
						return true;
					}

					if ( '' !== $job_id && $job_id === $entry['job_id'] && self::STATE_RELEASE_PENDING === $entry['state'] ) {
						return true;
					}

					$ok = '' === $job_id
						? self::STATE_RESERVED === $entry['state']
						: ( self::STATE_RESERVED === $entry['state'] || ( self::STATE_SUBMITTED === $entry['state'] && $job_id === $entry['job_id'] ) );

					if ( ! $ok ) {
						return new WP_Error( 'agend_jobs_credits_stale', 'The credit is not tied to this job, so it was left as it is.', array( 'status' => 409 ) );
					}

					$entry['state']  = self::STATE_RELEASE_PENDING;
					$entry['job_id'] = $job_id;
					self::write_entry( $ref, $entry );

					return self::restore_locked( $provider, $ref, $entry );
				},
				self::busy_error()
			);
		}

		/**
		 * Retries every pending release. Runs on cron; reschedules itself
		 * while any remain.
		 *
		 * @return void
		 */
		public static function retry_pending(): void {
			$provider = self::provider();
			$pending  = self::pending();

			if ( null === $provider || empty( $pending ) ) {
				return;
			}

			foreach ( array_keys( $pending ) as $key ) {
				$ref = (string) get_option( self::LEDGER_PREFIX . $key . '_ref', '' );

				if ( '' === $ref ) {
					self::forget_pending( $key );
					continue;
				}

				self::with_credit_lock(
					$ref,
					static function () use ( $provider, $ref ): bool {
						$entry = self::entry( $ref );

						if ( null === $entry || self::STATE_RELEASE_PENDING !== $entry['state'] ) {
							self::forget_pending( self::key( $ref ) );
							return true;
						}

						self::restore_locked( $provider, $ref, $entry );

						return true;
					},
					false
				);
			}

			self::schedule_retry();
		}

		/**
		 * Releases the credit behind a rejected or withdrawn submission.
		 *
		 * The delivery has already been signature checked by the receiver, and
		 * release() only acts on a credit this site spent, for the job the
		 * event names. A release the provider cannot confirm is queued for
		 * retry, so acknowledging the delivery never loses the credit.
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
		 * A credit's ledger record.
		 *
		 * @param string $ref Credit reference.
		 * @return array{state: string, user_id: int, job_id: string, history: string[], spent_at: string}|null
		 */
		public static function entry( string $ref ): ?array {
			if ( '' === $ref ) {
				return null;
			}

			$entry = get_option( self::LEDGER_PREFIX . self::key( $ref ), null );

			if ( ! is_array( $entry ) || ! isset( $entry['state'] ) ) {
				return null;
			}

			$entry['user_id'] = isset( $entry['user_id'] ) ? (int) $entry['user_id'] : 0;
			$entry['job_id']  = isset( $entry['job_id'] ) ? (string) $entry['job_id'] : '';
			$entry['history'] = isset( $entry['history'] ) && is_array( $entry['history'] ) ? array_values( array_map( 'strval', $entry['history'] ) ) : array();

			return $entry;
		}

		/**
		 * Credits whose release is waiting for a retry, as ledger key => attempts.
		 *
		 * @return array<string, int>
		 */
		public static function pending(): array {
			$pending = get_option( self::PENDING_OPTION, array() );

			return is_array( $pending ) ? $pending : array();
		}

		/**
		 * Spends a credit whose lock the caller holds.
		 *
		 * @param Agend_Apps_Jobs_Credit_Provider $provider Provider.
		 * @param int                             $user_id  WordPress user id.
		 * @param string                          $ref      Credit reference.
		 * @param array|null                      $entry    Its current ledger record.
		 * @return string|WP_Error
		 */
		private static function spend_locked( Agend_Apps_Jobs_Credit_Provider $provider, int $user_id, string $ref, ?array $entry ) {
			$entry = array(
				'state'    => self::STATE_SPENDING,
				'user_id'  => $user_id,
				'job_id'   => '',
				'history'  => null === $entry ? array() : $entry['history'],
				'spent_at' => gmdate( 'c' ),
			);
			self::write_entry( $ref, $entry );

			$spent = $provider->spend( $user_id, $ref );

			if ( is_wp_error( $spent ) ) {
				// The spend may have been applied before the error (a timeout
				// after the CRM committed). Give it back: restoring a credit
				// that is still unused changes nothing.
				$entry['state'] = self::STATE_RELEASE_PENDING;
				self::write_entry( $ref, $entry );
				self::restore_locked( $provider, $ref, $entry );

				return $spent;
			}

			$entry['state'] = self::STATE_RESERVED;
			self::write_entry( $ref, $entry );

			return $ref;
		}

		/**
		 * Restores a `release_pending` credit whose lock the caller holds, and
		 * queues a retry when the provider cannot confirm it.
		 *
		 * @param Agend_Apps_Jobs_Credit_Provider $provider Provider.
		 * @param string                          $ref      Credit reference.
		 * @param array                           $entry    Its ledger record, in release_pending.
		 * @return true|WP_Error
		 */
		private static function restore_locked( Agend_Apps_Jobs_Credit_Provider $provider, string $ref, array $entry ) {
			$restored = $provider->restore( $entry['user_id'], $ref );

			if ( is_wp_error( $restored ) ) {
				self::queue_retry( $ref, $restored );

				return $restored;
			}

			if ( '' !== $entry['job_id'] ) {
				$entry['history'][] = $entry['job_id'];
				$entry['history']   = array_slice( array_values( array_unique( $entry['history'] ) ), -self::HISTORY_LIMIT );
			}

			$entry['state']       = self::STATE_RELEASED;
			$entry['job_id']      = '';
			$entry['released_at'] = gmdate( 'c' );
			self::write_entry( $ref, $entry );
			self::forget_pending( self::key( $ref ) );

			return true;
		}

		/**
		 * Adds a credit to the retry queue, or gives up on it after
		 * MAX_ATTEMPTS and logs it for staff to put right by hand.
		 *
		 * @param string   $ref   Credit reference.
		 * @param WP_Error $error Why the release failed.
		 * @return void
		 */
		private static function queue_retry( string $ref, WP_Error $error ): void {
			$key      = self::key( $ref );
			$pending  = self::pending();
			$attempts = ( $pending[ $key ] ?? 0 ) + 1;

			if ( $attempts > self::MAX_ATTEMPTS ) {
				self::forget_pending( $key );
				self::log(
					'Gave up giving back a job credit; restore it by hand in the CRM.',
					array(
						'ledger' => self::LEDGER_PREFIX . $key,
						'code'   => $error->get_error_code(),
					)
				);
				return;
			}

			$pending[ $key ] = $attempts;
			update_option( self::PENDING_OPTION, $pending, false );
			self::schedule_retry();
		}

		/**
		 * Removes a credit from the retry queue.
		 *
		 * @param string $key Ledger key.
		 * @return void
		 */
		private static function forget_pending( string $key ): void {
			$pending = self::pending();

			if ( isset( $pending[ $key ] ) ) {
				unset( $pending[ $key ] );
				update_option( self::PENDING_OPTION, $pending, false );
			}
		}

		/**
		 * Schedules the next retry run while any release is pending.
		 *
		 * @return void
		 */
		private static function schedule_retry(): void {
			if ( ! empty( self::pending() ) && ! wp_next_scheduled( self::RETRY_HOOK ) ) {
				wp_schedule_single_event( time() + self::RETRY_DELAY, self::RETRY_HOOK );
			}
		}

		/**
		 * The ledger key of a credit reference.
		 *
		 * @param string $ref Credit reference.
		 * @return string
		 */
		private static function key( string $ref ): string {
			return md5( $ref );
		}

		/**
		 * Writes a credit's ledger record, and the reference it belongs to.
		 *
		 * @param string $ref   Credit reference.
		 * @param array  $entry Ledger record.
		 * @return void
		 */
		private static function write_entry( string $ref, array $entry ): void {
			$entry['updated_at'] = gmdate( 'c' );
			update_option( self::LEDGER_PREFIX . self::key( $ref ), $entry, false );
			update_option( self::LEDGER_PREFIX . self::key( $ref ) . '_ref', $ref, false );
		}

		/**
		 * Runs a callback under a credit's lock, waiting up to LOCK_WAIT seconds
		 * for it.
		 *
		 * @param string   $ref      Credit reference.
		 * @param callable $callback Work to do while holding the lock.
		 * @param mixed    $busy     Returned when the lock could not be taken.
		 * @return mixed
		 */
		private static function with_credit_lock( string $ref, callable $callback, $busy ) {
			$name     = self::credit_lock_name( $ref );
			$deadline = microtime( true ) + self::LOCK_WAIT;

			do {
				$lock = self::acquire_lock( $name );

				if ( null !== $lock ) {
					try {
						return $callback();
					} finally {
						self::release_lock( $name, $lock );
					}
				}

				if ( self::$lock_poll > 0 ) {
					usleep( self::$lock_poll );
				}
			} while ( microtime( true ) < $deadline && self::$lock_poll > 0 );

			return $busy;
		}

		/**
		 * The lock name of a credit.
		 *
		 * @param string $ref Credit reference.
		 * @return string
		 */
		private static function credit_lock_name( string $ref ): string {
			return self::CREDIT_LOCK_PREFIX . self::key( $ref );
		}

		/**
		 * Takes a lock row in wp_options.
		 *
		 * Taken with `INSERT IGNORE` rather than `add_option()`, which is
		 * `INSERT ... ON DUPLICATE KEY UPDATE` and can report success to two
		 * racing requests; the same pattern as the token worker's mint lock. A
		 * stale lock is replaced with a compare-and-swap, so two requests
		 * breaking it cannot both win, and the value carries a random token so
		 * a release only ever deletes its own lock.
		 *
		 * @param string $name Lock option name.
		 * @return string|null The value written, or null when the lock is held.
		 */
		private static function acquire_lock( string $name ): ?string {
			global $wpdb;

			$value = time() . ':' . bin2hex( random_bytes( 8 ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the lock must be atomic; see the docblock above.
			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
					$name,
					$value
				)
			);

			if ( 1 === (int) $wpdb->rows_affected ) {
				return $value;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$held = $wpdb->get_var(
				$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name )
			);

			if ( null === $held ) {
				return null;
			}

			[ $held_since ] = explode( ':', (string) $held, 2 );

			if ( (int) $held_since > time() - self::LOCK_TTL ) {
				return null;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
					$value,
					$name,
					(string) $held
				)
			);

			return 1 === (int) $wpdb->rows_affected ? $value : null;
		}

		/**
		 * Releases a lock this request holds.
		 *
		 * @param string $name  Lock option name.
		 * @param string $value The value acquire_lock() returned.
		 * @return void
		 */
		private static function release_lock( string $name, string $value ): void {
			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
					$name,
					$value
				)
			);
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
		 * The error returned when no credit provider is active.
		 *
		 * @return WP_Error
		 */
		private static function unavailable_error(): WP_Error {
			return new WP_Error( 'agend_jobs_credits_unavailable', __( 'Job posting is not available on this site.', 'agend-apps-core' ), array( 'status' => 503 ) );
		}

		/**
		 * The error returned for a signed-out visitor.
		 *
		 * @return WP_Error
		 */
		private static function no_member_error(): WP_Error {
			return new WP_Error( 'agend_jobs_credits_no_member', __( 'Sign in to post a job.', 'agend-apps-core' ), array( 'status' => 401 ) );
		}

		/**
		 * The error returned while another request holds a needed lock.
		 *
		 * @return WP_Error
		 */
		private static function busy_error(): WP_Error {
			return new WP_Error( 'agend_jobs_credits_busy', __( 'Another job submission is still being processed. Please try again in a moment.', 'agend-apps-core' ), array( 'status' => 409 ) );
		}
	}

endif;
