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
 *    available credit (soonest to expire) and returns a `credit_ref`: the
 *    provider's credit reference plus a reservation id unique to this spend.
 *    The submission is sent to the gateway with it.
 * 2. {@see Agend_Apps_Jobs_Credits::attach()} records the job the reservation
 *    paid for, once the gateway has accepted the submission.
 * 3. {@see Agend_Apps_Jobs_Credits::release()} gives the credit back: straight
 *    away when the gateway refuses the submission, or later when a
 *    `jobs.submission.rejected` or `jobs.submission.withdrawn` webhook arrives.
 *
 * The ledger is one option per credit, not per member, because members of a
 * firm share the firm's credits. It is always read from the database, never
 * this request's cache, and every change happens under that credit's lock, so
 * a credit is never spent twice and a release always reaches the member who
 * spent it. Because every attach, release and webhook names one reservation, a
 * late or repeated event about an earlier use of a credit can never hand back
 * a credit that has been spent again since.
 *
 * Nothing is lost when a request fails part way: a webhook's release request
 * is saved before it is acted on, and a recurring sweep finishes saved
 * requests, retries releases the CRM could not confirm, and gives back spends
 * left half done by a request that died. Credits that still cannot be put
 * right are listed for administrators.
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
		 * Marks one of the member's credits as used, re-checking with the CRM
		 * that it is still unused.
		 *
		 * An error whose data has `'applied' => false` means nothing was
		 * changed; add `'taken' => true` when that is because the credit is
		 * already used or has expired, and core tries the member's next credit.
		 * Any other error means the outcome is unknown, and core gives the
		 * credit back to be safe.
		 *
		 * @param int    $user_id WordPress user id.
		 * @param string $ref     Credit reference from get_available_credits().
		 * @return true|WP_Error
		 */
		public function spend( int $user_id, string $ref );

		/**
		 * Marks a previously spent credit as unused again. Returns true when it
		 * is already unused.
		 *
		 * An error whose data has `'gone' => true` means the credit is known to
		 * have expired, so there is nothing to give back. Only use it when the
		 * record was found and is out of date: a record that cannot be found may
		 * still exist, and must be an ordinary error so it is retried.
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
		 * - release_failed: giving it back has failed for MAX_ATTEMPTS sweeps and
		 *   staff are told. The sweep keeps trying, so once staff put it right
		 *   in the CRM it becomes released.
		 * - released: given back, free to be spent again.
		 */
		const STATE_SPENDING        = 'spending';
		const STATE_RESERVED        = 'reserved';
		const STATE_SUBMITTED       = 'submitted';
		const STATE_RELEASE_PENDING = 'release_pending';
		const STATE_RELEASE_FAILED  = 'release_failed';
		const STATE_RELEASED        = 'released';

		/**
		 * States in which a credit is tied up and must not be spent again.
		 *
		 * A `submitted` credit is not in this list: once its job is live, the
		 * provider is the record. If the CRM shows it unused again, staff gave
		 * it back by hand and the member may spend it; spend() re-checks the
		 * CRM under the credit's lock before it does.
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
		 * Option name prefixes: a credit's ledger record, a received release
		 * request, and the two kinds of lock. Distinct prefixes, so a LIKE scan
		 * of one never matches another.
		 *
		 * @var string
		 */
		const LEDGER_PREFIX      = 'agend_apps_jobs_ledger_';
		const REQUEST_PREFIX     = 'agend_apps_jobs_release_';
		const CREDIT_LOCK_PREFIX = 'agend_apps_jobs_lock_credit_';
		const MEMBER_LOCK_PREFIX = 'agend_apps_jobs_lock_member_';

		/**
		 * Option listing credits that need staff attention, written by the sweep.
		 *
		 * @var string
		 */
		const ATTENTION_OPTION = 'agend_apps_jobs_credits_attention';

		/**
		 * admin-post action and nonce action for resolving a credit by hand.
		 *
		 * @var string
		 */
		const RESOLVE_ACTION = 'agend_apps_jobs_credit_resolve';

		/**
		 * Separator between the provider's credit reference and the
		 * reservation id in the `credit_ref` sent to the gateway.
		 *
		 * @var string
		 */
		const SEPARATOR = '#';

		/**
		 * Seconds before a lock is treated as left behind by a request that
		 * died. Longer than several slow CRM calls in a row, so a live request
		 * never loses its lock. A `spending` record older than this was left by
		 * a request that died, and the sweep gives it back.
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
		 * Cron hook of the recurring sweep, and its schedule.
		 *
		 * @var string
		 */
		const SWEEP_HOOK     = 'agend_apps_jobs_credits_sweep';
		const SWEEP_SCHEDULE = 'agend_apps_fifteen_minutes';

		/**
		 * Give-back attempts before a credit is marked release_failed (24 hours
		 * of 15 minute sweeps).
		 *
		 * @var int
		 */
		const MAX_ATTEMPTS = 96;

		/**
		 * Seconds a reservation may stay unattached before staff are told.
		 *
		 * @var int
		 */
		const STALE_RESERVATION = DAY_IN_SECONDS;

		/**
		 * Released reservation ids kept per credit, so a repeated event about
		 * one is acknowledged rather than refused.
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
		 * Registers the webhook listener, the sweep and the admin notice.
		 *
		 * @return void
		 */
		public static function register(): void {
			add_action( 'agend_apps_webhook_received', array( __CLASS__, 'handle_webhook' ), 10, 2 );
			add_action( self::SWEEP_HOOK, array( __CLASS__, 'sweep' ) );
			add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval
			add_action( 'admin_notices', array( __CLASS__, 'render_attention_notice' ) );
			add_action( 'admin_post_' . self::RESOLVE_ACTION, array( __CLASS__, 'handle_resolve' ) );
		}

		/**
		 * Adds the sweep's 15 minute schedule.
		 *
		 * @param array $schedules Cron schedules.
		 * @return array
		 */
		public static function cron_schedules( $schedules ): array {
			$schedules = is_array( $schedules ) ? $schedules : array();

			$schedules[ self::SWEEP_SCHEDULE ] = array(
				'interval' => 15 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 15 minutes (Agend job credits)', 'agend-apps-core' ),
			);

			return $schedules;
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
		 * The member's unused credits, leaving out any tied up here.
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
		 * Spends the member's first available credit.
		 *
		 * Returns the `credit_ref` to send with the submission: the provider's
		 * credit reference plus a reservation id unique to this spend, so every
		 * later attach, release or webhook names this spend exactly, never an
		 * earlier or later use of the same credit.
		 *
		 * One reserve runs per member at a time (a double submit is refused),
		 * and each credit is spent under its own lock, so two members of a firm
		 * sharing the firm's credits never spend the same one. The ledger
		 * record is written before the provider is called, so a spend whose
		 * outcome is unknown is given back rather than lost.
		 *
		 * @param int $user_id WordPress user id.
		 * @return string|WP_Error The credit_ref.
		 */
		public static function reserve( int $user_id ) {
			$provider = self::provider();

			if ( null === $provider ) {
				return self::unavailable_error();
			}

			if ( $user_id <= 0 ) {
				return self::no_member_error();
			}

			self::schedule_sweep();

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
					$name = self::credit_lock_name( $ref );
					$lock = self::acquire_lock( $name );

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

						$result = self::spend_locked( $provider, $user_id, $ref, $entry );

						if ( is_wp_error( $result ) && self::taken( $result ) ) {
							// The CRM says this credit was used or has expired
							// since the list was read; try the next one. Any
							// other error (the CRM is down) is the member's
							// answer, never "no credits left".
							continue;
						}

						return $result;
					} finally {
						self::release_lock( $name, $lock );
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
		 * Records the job a reservation paid for.
		 *
		 * @param int    $user_id    WordPress user id.
		 * @param string $credit_ref The credit_ref reserve() returned.
		 * @param string $job_id     Gateway job id.
		 * @return bool False when the reservation is not this member's current
		 *              one, including when a rejection for the job arrived first
		 *              and the credit has already been given back.
		 */
		public static function attach( int $user_id, string $credit_ref, string $job_id ): bool {
			$parts = self::parse( $credit_ref );

			if ( null === $parts || '' === $job_id ) {
				return false;
			}

			return (bool) self::with_credit_lock(
				$parts['ref'],
				self::LOCK_WAIT,
				static function () use ( $user_id, $parts, $job_id ): bool {
					$entry = self::entry( $parts['ref'] );

					if ( null === $entry || self::STATE_RESERVED !== $entry['state'] || $user_id !== $entry['user_id'] || $parts['reservation'] !== $entry['reservation'] ) {
						return false;
					}

					$entry['state']  = self::STATE_SUBMITTED;
					$entry['job_id'] = $job_id;
					self::write_entry( $parts['ref'], $entry );

					return true;
				},
				false
			);
		}

		/**
		 * Gives back the credit behind one reservation.
		 *
		 * Without a job id, only a reservation that never reached a job (the
		 * gateway refused the submission) is released. With one, the
		 * reservation must still be reserved (a rejection can arrive before
		 * attach() runs) or be submitted for that job. A reservation already
		 * given back is acknowledged; any other reservation of the same credit
		 * is refused, so a late or repeated event can never hand back a credit
		 * that has been spent again since.
		 *
		 * When the provider cannot confirm the release, the credit stays
		 * `release_pending` and the sweep retries it, so it is never lost.
		 *
		 * @param string $credit_ref The credit_ref reserve() returned.
		 * @param string $job_id     Gateway job id, or '' for a refused submission.
		 * @param int    $wait       Seconds to wait for the credit's lock.
		 * @return true|WP_Error
		 */
		public static function release( string $credit_ref, string $job_id = '', int $wait = self::LOCK_WAIT ) {
			$provider = self::provider();

			if ( null === $provider ) {
				return self::unavailable_error();
			}

			$parts = self::parse( $credit_ref );

			if ( null === $parts ) {
				return new WP_Error( 'agend_jobs_credits_unknown', 'The credit reference was not issued by this site.', array( 'status' => 404 ) );
			}

			return self::with_credit_lock(
				$parts['ref'],
				$wait,
				static function () use ( $provider, $parts, $job_id ) {
					$entry = self::entry( $parts['ref'] );

					if ( null === $entry ) {
						return new WP_Error( 'agend_jobs_credits_unknown', 'No member on this site spent this credit.', array( 'status' => 404 ) );
					}

					if ( in_array( $parts['reservation'], $entry['released'], true ) ) {
						return true;
					}

					if ( $parts['reservation'] !== $entry['reservation'] ) {
						return new WP_Error( 'agend_jobs_credits_stale', 'The credit has been spent again since, so it was left as it is.', array( 'status' => 409 ) );
					}

					if ( in_array( $entry['state'], array( self::STATE_RELEASE_PENDING, self::STATE_RELEASE_FAILED ), true ) ) {
						return true;
					}

					$ok = '' === $job_id
						? self::STATE_RESERVED === $entry['state']
						: ( self::STATE_RESERVED === $entry['state'] || ( self::STATE_SUBMITTED === $entry['state'] && $job_id === $entry['job_id'] ) );

					if ( ! $ok ) {
						return new WP_Error( 'agend_jobs_credits_stale', 'The credit is not tied to this job, so it was left as it is.', array( 'status' => 409 ) );
					}

					$entry['state']    = self::STATE_RELEASE_PENDING;
					$entry['job_id']   = '' !== $job_id ? $job_id : $entry['job_id'];
					$entry['attempts'] = 0;
					self::write_entry( $parts['ref'], $entry );

					return self::restore_locked( $provider, $parts['ref'], $entry );
				},
				self::busy_error()
			);
		}

		/**
		 * Releases the credit behind a rejected or withdrawn submission.
		 *
		 * The delivery has already been signature checked by the receiver. The
		 * request is saved before anything else, and only deleted once it has
		 * been dealt with, so a lock held by another request, a CRM outage or
		 * this request dying never loses it: the sweep picks it up.
		 *
		 * @param string $type     Event type.
		 * @param array  $envelope Decoded delivery envelope.
		 * @return void
		 */
		public static function handle_webhook( $type, $envelope ): void {
			if ( ! in_array( (string) $type, self::RELEASE_EVENTS, true ) || ! is_array( $envelope ) ) {
				return;
			}

			$data       = ( isset( $envelope['data'] ) && is_array( $envelope['data'] ) ) ? $envelope['data'] : array();
			$credit_ref = isset( $data['credit_ref'] ) && is_scalar( $data['credit_ref'] ) ? trim( (string) $data['credit_ref'] ) : '';
			$job_id     = isset( $data['job_id'] ) && is_scalar( $data['job_id'] ) ? trim( (string) $data['job_id'] ) : '';

			if ( null === self::parse( $credit_ref ) || '' === $job_id ) {
				return;
			}

			$name = self::REQUEST_PREFIX . md5( $credit_ref );
			self::write_option(
				$name,
				array(
					'credit_ref'  => $credit_ref,
					'job_id'      => $job_id,
					'type'        => (string) $type,
					'received_at' => time(),
				)
			);
			self::schedule_sweep();

			if ( null !== self::provider() ) {
				self::process_request( $name, 0 );
			}
		}

		/**
		 * Recovers everything a request could leave behind. Runs every 15
		 * minutes on cron.
		 *
		 * - Saved release requests are processed.
		 * - `release_pending` and `release_failed` credits are given back.
		 * - `spending` records older than the lock TTL (left by a request that
		 *   died mid-spend) are given back: restoring a credit that was never
		 *   spent changes nothing.
		 * - Credits that need staff attention are listed for the admin notice.
		 *
		 * @return void
		 */
		public static function sweep(): void {
			$provider = self::provider();

			if ( null === $provider ) {
				return;
			}

			foreach ( self::option_names( self::REQUEST_PREFIX ) as $name ) {
				self::process_request( $name, 0 );
			}

			$attention = array();

			foreach ( self::option_names( self::LEDGER_PREFIX ) as $name ) {
				$stored = self::read_option( $name );
				$ref    = is_array( $stored ) && isset( $stored['ref'] ) ? (string) $stored['ref'] : '';
				$entry  = '' === $ref ? null : self::entry( $ref );

				if ( null === $entry ) {
					continue;
				}

				// Checked without the lock to keep the sweep cheap; the work
				// itself re-reads under it.
				if ( self::needs_work( $entry ) ) {
					self::with_credit_lock(
						$ref,
						0,
						static function () use ( $provider, $ref ): bool {
							$entry = self::entry( $ref );

							if ( null === $entry || ! self::needs_work( $entry ) ) {
								return true;
							}

							if ( self::STATE_SPENDING === $entry['state'] ) {
								$entry['state'] = self::STATE_RELEASE_PENDING;
								self::write_entry( $ref, $entry );
							}

							self::restore_locked( $provider, $ref, $entry );

							return true;
						},
						false
					);

					$entry = self::entry( $ref );
				}

				if ( null !== $entry && self::needs_attention( $entry ) ) {
					$attention[] = array(
						'ref'     => $ref,
						'state'   => $entry['state'],
						'user_id' => $entry['user_id'],
						'since'   => $entry['updated_at'],
					);
				}
			}

			self::write_option( self::ATTENTION_OPTION, $attention );
		}

		/**
		 * Shows administrators the credits that need putting right by hand,
		 * each with the two ways to resolve it.
		 *
		 * @return void
		 */
		public static function render_attention_notice(): void {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$attention = get_option( self::ATTENTION_OPTION, array() );

			if ( ! is_array( $attention ) || empty( $attention ) ) {
				return;
			}

			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Member job posting credits need checking', 'agend-apps-core' ) . '</strong></p>';
			echo '<p>' . esc_html__( 'Each credit below is still marked used in the CRM. Check the member\'s job and the CRM record, then give the credit back (the member can spend it again) or keep it as used (the job went ahead).', 'agend-apps-core' ) . '</p><ul>';

			foreach ( array_slice( $attention, 0, 20 ) as $item ) {
				$ref   = (string) ( $item['ref'] ?? '' );
				$user  = get_user_by( 'id', (int) ( $item['user_id'] ?? 0 ) );
				$label = self::STATE_RELEASE_FAILED === ( $item['state'] ?? '' )
					? __( 'could not be given back automatically', 'agend-apps-core' )
					: __( 'its submission never completed', 'agend-apps-core' );

				echo '<li><code>' . esc_html( $ref ) . '</code> ';
				echo esc_html(
					sprintf(
						/* translators: 1: member name, 2: what went wrong, 3: date. */
						__( '(%1$s): %2$s, since %3$s.', 'agend-apps-core' ),
						$user ? $user->display_name : __( 'unknown member', 'agend-apps-core' ),
						$label,
						wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) ( $item['since'] ?? 0 ) )
					)
				);

				foreach ( array(
					'release' => __( 'Give the credit back', 'agend-apps-core' ),
					'keep'    => __( 'Keep it as used', 'agend-apps-core' ),
				) as $resolution => $button ) {
					echo ' <form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
					echo '<input type="hidden" name="action" value="' . esc_attr( self::RESOLVE_ACTION ) . '" />';
					echo '<input type="hidden" name="credit" value="' . esc_attr( $ref ) . '" />';
					echo '<input type="hidden" name="resolution" value="' . esc_attr( $resolution ) . '" />';
					wp_nonce_field( self::RESOLVE_ACTION . '|' . $ref );
					echo '<button type="submit" class="button button-small">' . esc_html( $button ) . '</button></form>';
				}

				echo '</li>';
			}

			echo '</ul></div>';
		}

		/**
		 * Resolves a credit from the admin notice, then returns to the page.
		 *
		 * @return void
		 */
		public static function handle_resolve(): void {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You are not allowed to do that.', 'agend-apps-core' ), '', array( 'response' => 403 ) );
			}

			$ref        = isset( $_POST['credit'] ) ? sanitize_text_field( wp_unslash( $_POST['credit'] ) ) : '';
			$resolution = isset( $_POST['resolution'] ) ? sanitize_key( wp_unslash( $_POST['resolution'] ) ) : '';

			check_admin_referer( self::RESOLVE_ACTION . '|' . $ref );

			self::resolve( $ref, $resolution );

			wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
			exit;
		}

		/**
		 * Resolves a stuck credit by hand: `release` gives it back (as if the
		 * submission had been refused), `keep` records it as used for a job
		 * that went ahead. Only a reserved or release_failed credit can be
		 * resolved, and the attention list is updated straight away.
		 *
		 * @param string $ref        Provider credit reference.
		 * @param string $resolution `release` or `keep`.
		 * @return true|WP_Error
		 */
		public static function resolve( string $ref, string $resolution ) {
			$provider = self::provider();

			if ( null === $provider ) {
				return self::unavailable_error();
			}

			if ( ! in_array( $resolution, array( 'release', 'keep' ), true ) ) {
				return new WP_Error( 'agend_jobs_credits_resolution', 'Unknown resolution.', array( 'status' => 400 ) );
			}

			$result = self::with_credit_lock(
				$ref,
				self::LOCK_WAIT,
				static function () use ( $provider, $ref, $resolution ) {
					$entry = self::entry( $ref );

					if ( null === $entry || ! in_array( $entry['state'], array( self::STATE_RESERVED, self::STATE_RELEASE_FAILED ), true ) ) {
						return new WP_Error( 'agend_jobs_credits_stale', 'The credit no longer needs resolving.', array( 'status' => 409 ) );
					}

					if ( 'keep' === $resolution ) {
						$entry['state']  = self::STATE_SUBMITTED;
						$entry['job_id'] = '' !== $entry['job_id'] ? $entry['job_id'] : 'resolved-by-staff';
						self::write_entry( $ref, $entry );

						return true;
					}

					$entry['state']    = self::STATE_RELEASE_PENDING;
					$entry['attempts'] = 0;
					self::write_entry( $ref, $entry );

					return self::restore_locked( $provider, $ref, $entry );
				},
				self::busy_error()
			);

			$attention = get_option( self::ATTENTION_OPTION, array() );

			if ( true === $result && is_array( $attention ) ) {
				self::write_option(
					self::ATTENTION_OPTION,
					array_values(
						array_filter(
							$attention,
							static function ( $item ) use ( $ref ): bool {
								return ! is_array( $item ) || ( $item['ref'] ?? '' ) !== $ref;
							}
						)
					)
				);
			}

			return $result;
		}

		/**
		 * A credit's ledger record, read from the database rather than this
		 * request's cache, so a change made by another request is always seen.
		 *
		 * @param string $ref Provider credit reference.
		 * @return array{ref: string, state: string, user_id: int, reservation: string, job_id: string, released: string[], attempts: int, updated_at: int}|null
		 */
		public static function entry( string $ref ): ?array {
			if ( '' === $ref ) {
				return null;
			}

			$entry = self::read_option( self::LEDGER_PREFIX . md5( $ref ) );

			if ( ! is_array( $entry ) || ! isset( $entry['state'] ) ) {
				return null;
			}

			$entry['ref']         = $ref;
			$entry['user_id']     = isset( $entry['user_id'] ) ? (int) $entry['user_id'] : 0;
			$entry['reservation'] = isset( $entry['reservation'] ) ? (string) $entry['reservation'] : '';
			$entry['job_id']      = isset( $entry['job_id'] ) ? (string) $entry['job_id'] : '';
			$entry['released']    = isset( $entry['released'] ) && is_array( $entry['released'] ) ? array_values( array_map( 'strval', $entry['released'] ) ) : array();
			$entry['attempts']    = isset( $entry['attempts'] ) ? (int) $entry['attempts'] : 0;
			$entry['updated_at']  = isset( $entry['updated_at'] ) ? (int) $entry['updated_at'] : 0;

			return $entry;
		}

		/**
		 * Splits a credit_ref into the provider's reference and the
		 * reservation id.
		 *
		 * @param string $credit_ref credit_ref.
		 * @return array{ref: string, reservation: string}|null
		 */
		public static function parse( string $credit_ref ): ?array {
			$at = strrpos( $credit_ref, self::SEPARATOR );

			if ( false === $at ) {
				return null;
			}

			$ref         = substr( $credit_ref, 0, $at );
			$reservation = substr( $credit_ref, $at + 1 );

			if ( '' === $ref || 1 !== preg_match( '/^[a-f0-9]{16}$/', $reservation ) ) {
				return null;
			}

			return array(
				'ref'         => $ref,
				'reservation' => $reservation,
			);
		}

		/**
		 * Processes one saved release request, deleting it once dealt with.
		 * It is kept when the credit's lock is held or the provider is down,
		 * and given up (with a log line) after a week.
		 *
		 * @param string $name Request option name.
		 * @param int    $wait Seconds to wait for the credit's lock.
		 * @return void
		 */
		private static function process_request( string $name, int $wait ): void {
			$request = self::read_option( $name );

			if ( ! is_array( $request ) || empty( $request['credit_ref'] ) ) {
				delete_option( $name );
				return;
			}

			$result = self::release( (string) $request['credit_ref'], (string) ( $request['job_id'] ?? '' ), $wait );

			if ( is_wp_error( $result ) && in_array( $result->get_error_code(), array( 'agend_jobs_credits_busy', 'agend_jobs_credits_unavailable' ), true ) ) {
				if ( (int) ( $request['received_at'] ?? 0 ) < time() - WEEK_IN_SECONDS ) {
					self::log( 'Gave up on a job credit release request after a week.', array( 'job_id' => (string) ( $request['job_id'] ?? '' ) ) );
					delete_option( $name );
				}
				return;
			}

			if ( is_wp_error( $result ) && 'agend_jobs_credits_unknown' !== $result->get_error_code() ) {
				self::log(
					'Job credit not released for ' . (string) ( $request['type'] ?? 'a release request' ) . ': ' . $result->get_error_message(),
					array(
						'job_id' => (string) ( $request['job_id'] ?? '' ),
						'code'   => $result->get_error_code(),
					)
				);
			}

			delete_option( $name );
		}

		/**
		 * Spends a credit whose lock the caller holds.
		 *
		 * @param Agend_Apps_Jobs_Credit_Provider $provider Provider.
		 * @param int                             $user_id  WordPress user id.
		 * @param string                          $ref      Provider credit reference.
		 * @param array|null                      $previous Its current ledger record.
		 * @return string|WP_Error
		 */
		private static function spend_locked( Agend_Apps_Jobs_Credit_Provider $provider, int $user_id, string $ref, ?array $previous ) {
			$entry = array(
				'ref'         => $ref,
				'state'       => self::STATE_SPENDING,
				'user_id'     => $user_id,
				'reservation' => bin2hex( random_bytes( 8 ) ),
				'job_id'      => '',
				'released'    => null === $previous ? array() : $previous['released'],
				'attempts'    => 0,
				'spent_at'    => time(),
			);
			self::write_entry( $ref, $entry );

			$spent = $provider->spend( $user_id, $ref );

			if ( ! self::still_ours( $ref, $entry['reservation'] ) ) {
				// The CRM took longer than the lock lasts and the sweep took
				// this spend over. Write nothing over its work.
				return self::void_spend( $provider, $ref );
			}

			if ( is_wp_error( $spent ) ) {
				if ( self::not_applied( $spent ) ) {
					// The CRM refused the spend outright: nothing changed, so
					// the ledger goes back to how it was.
					if ( null === $previous ) {
						delete_option( self::LEDGER_PREFIX . md5( $ref ) );
					} else {
						self::write_entry( $ref, $previous );
					}

					return $spent;
				}

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

			return $ref . self::SEPARATOR . $entry['reservation'];
		}

		/**
		 * Whether a credit's ledger record still belongs to this spend.
		 *
		 * @param string $ref         Provider credit reference.
		 * @param string $reservation This spend's reservation id.
		 * @return bool
		 */
		private static function still_ours( string $ref, string $reservation ): bool {
			$entry = self::entry( $ref );

			return null !== $entry && $reservation === $entry['reservation'] && self::STATE_SPENDING === $entry['state'];
		}

		/**
		 * Cleans up after a spend whose lock was taken over while the CRM was
		 * still answering. The sweep may already have given the credit back
		 * before this spend landed, so it is given back again, under the lock,
		 * unless someone else has spent it since (then it is left alone and
		 * logged; it cannot be told apart from their spend).
		 *
		 * @param Agend_Apps_Jobs_Credit_Provider $provider Provider.
		 * @param string                          $ref      Provider credit reference.
		 * @return WP_Error Always: this spend did not reserve a credit.
		 */
		private static function void_spend( Agend_Apps_Jobs_Credit_Provider $provider, string $ref ): WP_Error {
			self::with_credit_lock(
				$ref,
				self::LOCK_WAIT,
				static function () use ( $provider, $ref ): bool {
					$entry = self::entry( $ref );

					if ( null !== $entry && ( in_array( $entry['state'], self::BUSY_STATES, true ) || self::STATE_SUBMITTED === $entry['state'] ) ) {
						self::log( 'A slow job credit spend finished after the credit was spent again; check it in the CRM.', array( 'credit' => $ref ) );
						return true;
					}

					$restored = $provider->restore( null === $entry ? 0 : (int) $entry['user_id'], $ref );

					if ( is_wp_error( $restored ) && ! self::gone( $restored ) ) {
						self::log( 'A slow job credit spend could not be given back; check it in the CRM.', array( 'credit' => $ref ) );
					}

					return true;
				},
				false
			);

			return new WP_Error( 'agend_jobs_credits_timeout', __( 'The CRM took too long to respond. Please try again.', 'agend-apps-core' ), array( 'status' => 503 ) );
		}

		/**
		 * Restores a `release_pending` credit whose lock the caller holds. On
		 * failure the credit stays release_pending for the sweep, until
		 * MAX_ATTEMPTS, when it becomes release_failed for staff.
		 *
		 * @param Agend_Apps_Jobs_Credit_Provider $provider Provider.
		 * @param string                          $ref      Provider credit reference.
		 * @param array                           $entry    Its ledger record, in release_pending.
		 * @return true|WP_Error
		 */
		private static function restore_locked( Agend_Apps_Jobs_Credit_Provider $provider, string $ref, array $entry ) {
			$restored = $provider->restore( (int) $entry['user_id'], $ref );

			if ( is_wp_error( $restored ) && self::gone( $restored ) ) {
				// The credit has expired or no longer exists: there is nothing
				// to give back.
				self::log( 'Job credit not given back because it has expired.', array( 'credit' => $ref ) );
				$restored = true;
			}

			if ( is_wp_error( $restored ) ) {
				$entry['attempts'] = (int) ( $entry['attempts'] ?? 0 ) + 1;

				if ( $entry['attempts'] >= self::MAX_ATTEMPTS && self::STATE_RELEASE_FAILED !== $entry['state'] ) {
					$entry['state'] = self::STATE_RELEASE_FAILED;
					self::log(
						'Gave up giving back a job credit; restore it by hand in the CRM.',
						array(
							'credit' => $ref,
							'code'   => $restored->get_error_code(),
						)
					);
				}

				self::write_entry( $ref, $entry );

				return $restored;
			}

			$entry['released'][]  = (string) $entry['reservation'];
			$entry['released']    = array_slice( array_values( array_unique( $entry['released'] ) ), -self::HISTORY_LIMIT );
			$entry['state']       = self::STATE_RELEASED;
			$entry['released_at'] = time();
			self::write_entry( $ref, $entry );

			return true;
		}

		/**
		 * Whether a provider error says the change was definitely not made.
		 *
		 * @param WP_Error $error Provider error.
		 * @return bool
		 */
		private static function not_applied( WP_Error $error ): bool {
			$data = $error->get_error_data();

			return is_array( $data ) && array_key_exists( 'applied', $data ) && false === $data['applied'];
		}

		/**
		 * Whether a provider spend error says the credit is already used or has
		 * expired, so the next credit should be tried.
		 *
		 * @param WP_Error $error Provider error.
		 * @return bool
		 */
		private static function taken( WP_Error $error ): bool {
			$data = $error->get_error_data();

			return self::not_applied( $error ) && is_array( $data ) && ! empty( $data['taken'] );
		}

		/**
		 * Whether a provider error says the credit has expired, so it cannot
		 * and need not be given back.
		 *
		 * @param WP_Error $error Provider error.
		 * @return bool
		 */
		private static function gone( WP_Error $error ): bool {
			$data = $error->get_error_data();

			return is_array( $data ) && ! empty( $data['gone'] );
		}

		/**
		 * Whether the sweep has anything to do for a credit: a release to
		 * retry, or a spend left behind by a request that died.
		 *
		 * @param array $entry Ledger record.
		 * @return bool
		 */
		private static function needs_work( array $entry ): bool {
			if ( in_array( $entry['state'], array( self::STATE_RELEASE_PENDING, self::STATE_RELEASE_FAILED ), true ) ) {
				return true;
			}

			return self::STATE_SPENDING === $entry['state'] && $entry['updated_at'] < time() - self::LOCK_TTL;
		}

		/**
		 * Whether a credit needs staff attention: its release failed for good,
		 * or its reservation never reached a job.
		 *
		 * @param array $entry Ledger record.
		 * @return bool
		 */
		private static function needs_attention( array $entry ): bool {
			if ( self::STATE_RELEASE_FAILED === $entry['state'] ) {
				return true;
			}

			return self::STATE_RESERVED === $entry['state'] && $entry['updated_at'] < time() - self::STALE_RESERVATION;
		}

		/**
		 * Schedules the recurring sweep if it is not scheduled.
		 *
		 * @return void
		 */
		private static function schedule_sweep(): void {
			if ( ! wp_next_scheduled( self::SWEEP_HOOK ) ) {
				wp_schedule_event( time() + 15 * MINUTE_IN_SECONDS, self::SWEEP_SCHEDULE, self::SWEEP_HOOK );
			}
		}

		/**
		 * Writes a credit's ledger record.
		 *
		 * @param string $ref   Provider credit reference.
		 * @param array  $entry Ledger record.
		 * @return void
		 */
		private static function write_entry( string $ref, array $entry ): void {
			$entry['ref']        = $ref;
			$entry['updated_at'] = time();
			self::write_option( self::LEDGER_PREFIX . md5( $ref ), $entry );
		}

		/**
		 * Reads an option straight from the database, never this request's or a
		 * persistent object cache, so a change another request made under the
		 * lock is always seen.
		 *
		 * @param string $name Option name.
		 * @return mixed Null when the option does not exist.
		 */
		private static function read_option( string $name ) {
			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- must bypass every cache; see the docblock.
			$value = $wpdb->get_var(
				$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name )
			);

			return null === $value ? null : maybe_unserialize( $value );
		}

		/**
		 * Writes a non-autoloaded option, comparing against the database rather
		 * than this request's cache (update_option() skips the write when the
		 * cached value already matches).
		 *
		 * @param string $name  Option name.
		 * @param mixed  $value Value.
		 * @return void
		 */
		private static function write_option( string $name, $value ): void {
			self::forget_cached_option( $name );
			update_option( $name, $value, false );
		}

		/**
		 * Drops this request's cached copy of an option, including a cached
		 * "does not exist".
		 *
		 * @param string $name Option name.
		 * @return void
		 */
		private static function forget_cached_option( string $name ): void {
			wp_cache_delete( $name, 'options' );

			$notoptions = wp_cache_get( 'notoptions', 'options' );

			if ( ! is_array( $notoptions ) ) {
				return;
			}

			// WordPress keys it by option name; tolerate a plain list too.
			$position = array_search( $name, $notoptions, true );

			if ( isset( $notoptions[ $name ] ) || false !== $position ) {
				unset( $notoptions[ $name ] );

				if ( false !== $position ) {
					unset( $notoptions[ $position ] );
				}

				wp_cache_set( 'notoptions', $notoptions, 'options' );
			}
		}

		/**
		 * Option names starting with a prefix, from the database.
		 *
		 * @param string $prefix Option name prefix.
		 * @return string[]
		 */
		private static function option_names( string $prefix ): array {
			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a scan of this feature's own rows.
			$names = $wpdb->get_col(
				$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name", $wpdb->esc_like( $prefix ) . '%' )
			);

			return array_map( 'strval', (array) $names );
		}

		/**
		 * Runs a callback under a credit's lock, waiting up to $wait seconds
		 * for it.
		 *
		 * @param string   $ref      Provider credit reference.
		 * @param int      $wait     Seconds to wait; 0 tries once.
		 * @param callable $callback Work to do while holding the lock.
		 * @param mixed    $busy     Returned when the lock could not be taken.
		 * @return mixed
		 */
		private static function with_credit_lock( string $ref, int $wait, callable $callback, $busy ) {
			$name     = self::credit_lock_name( $ref );
			$deadline = microtime( true ) + $wait;

			do {
				$lock = self::acquire_lock( $name );

				if ( null !== $lock ) {
					try {
						return $callback();
					} finally {
						self::release_lock( $name, $lock );
					}
				}

				if ( $wait <= 0 || self::$lock_poll <= 0 ) {
					break;
				}

				usleep( self::$lock_poll );
			} while ( microtime( true ) < $deadline );

			return $busy;
		}

		/**
		 * The lock name of a credit.
		 *
		 * @param string $ref Provider credit reference.
		 * @return string
		 */
		private static function credit_lock_name( string $ref ): string {
			return self::CREDIT_LOCK_PREFIX . md5( $ref );
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
		 * @param array  $context Structured context.
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
