<?php
/**
 * Provisioning retry queue.
 *
 * @package Agend_Loop_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Members whose Loop account could not be created yet for a reason that passes
 * on its own: the gateway's hourly creation limit (`rate_limited`), an
 * unexpected fault (`provisioning_failed`), or a request that never got an
 * answer (`request_failed`). Each is sent again about an hour later, so a large
 * backfill finishes over several hours instead of failing half way.
 *
 * Stored as one option keyed by WordPress user id: the reason, how many retries
 * have run and when the member was first queued. Nothing personal is kept; the
 * payload is rebuilt from the user when the retry runs.
 */
class Agend_Loop_Sync_Provision_Retry {

	/**
	 * Skip reasons worth sending again later. Every other reason needs someone
	 * to change something first.
	 *
	 * @var string[]
	 */
	const RETRY_REASONS = array( 'rate_limited', 'provisioning_failed', 'request_failed' );

	/**
	 * Seconds between retries. The gateway's creation limit is per hour.
	 */
	const DELAY = HOUR_IN_SECONDS;

	/**
	 * Retries before a member is dropped from the queue and reported.
	 */
	const MAX_ATTEMPTS = 24;

	/**
	 * Most queued members one retry loads and sends, oldest first. A little
	 * over the gateway's 2000 creations an hour, since some will turn out to
	 * be linked or already in Loop; the rest wait for the next hour.
	 */
	const RUN_LIMIT = 2500;

	/**
	 * Whether a skip reason is worth retrying.
	 *
	 * @param string $reason The gateway's skip reason.
	 * @return bool
	 */
	public static function is_retryable( string $reason ): bool {
		return in_array( $reason, self::RETRY_REASONS, true );
	}

	/**
	 * The queue, keyed by WordPress user id, oldest first.
	 *
	 * @param bool $fresh Read the stored option again rather than this
	 *                    request's cached copy. Every read-modify-write does,
	 *                    so a long retry run does not overwrite what a sign-in
	 *                    queued or settled while it ran.
	 * @return array<int, array{reason: string, attempts: int, since: int}>
	 */
	public static function queued( bool $fresh = false ): array {
		if ( $fresh ) {
			wp_cache_delete( Agend_Loop_Sync_Settings::OPT_PROVISION_RETRY, 'options' );
		}

		$stored = get_option( Agend_Loop_Sync_Settings::OPT_PROVISION_RETRY, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$queue = array();
		foreach ( $stored as $wp_id => $entry ) {
			if ( (int) $wp_id > 0 && is_array( $entry ) ) {
				$queue[ (int) $wp_id ] = array(
					'reason'   => (string) ( $entry['reason'] ?? '' ),
					'attempts' => (int) ( $entry['attempts'] ?? 0 ),
					'since'    => (int) ( $entry['since'] ?? 0 ),
				);
			}
		}

		return $queue;
	}

	/**
	 * Queues members for a retry and makes sure one is scheduled. A member
	 * already queued keeps their attempt count and first-queued time.
	 *
	 * @param array<int, string> $reasons Skip reason by WordPress user id.
	 * @return void
	 */
	public static function add( array $reasons ): void {
		if ( array() === $reasons ) {
			return;
		}

		$queue = self::queued( true );
		foreach ( $reasons as $wp_id => $reason ) {
			$wp_id = (int) $wp_id;
			if ( $wp_id <= 0 ) {
				continue;
			}
			$queue[ $wp_id ] = array(
				'reason'   => (string) $reason,
				'attempts' => $queue[ $wp_id ]['attempts'] ?? 0,
				'since'    => $queue[ $wp_id ]['since'] ?? time(),
			);
		}

		self::store( $queue );
		self::schedule();
	}

	/**
	 * Takes members off the queue, for example once their account exists.
	 *
	 * @param int[] $wp_ids WordPress user ids.
	 * @return void
	 */
	public static function remove( array $wp_ids ): void {
		if ( array() === $wp_ids ) {
			return;
		}

		$queue = self::queued( true );
		$size  = count( $queue );
		foreach ( $wp_ids as $wp_id ) {
			unset( $queue[ (int) $wp_id ] );
		}

		if ( count( $queue ) !== $size ) {
			self::store( $queue );
		}
	}

	/**
	 * Empties the queue and cancels the pending retry.
	 *
	 * @return void
	 */
	public static function clear(): void {
		delete_option( Agend_Loop_Sync_Settings::OPT_PROVISION_RETRY );
		if ( defined( 'AGEND_LOOP_SYNC_PROVISION_RETRY_HOOK' ) ) {
			wp_clear_scheduled_hook( AGEND_LOOP_SYNC_PROVISION_RETRY_HOOK );
		}
	}

	/**
	 * Schedules one retry about an hour out, unless one is already waiting.
	 *
	 * @return void
	 */
	public static function schedule(): void {
		if ( ! defined( 'AGEND_LOOP_SYNC_PROVISION_RETRY_HOOK' ) ) {
			return;
		}

		if ( false === wp_next_scheduled( AGEND_LOOP_SYNC_PROVISION_RETRY_HOOK ) ) {
			wp_schedule_single_event( time() + self::DELAY, AGEND_LOOP_SYNC_PROVISION_RETRY_HOOK );
		}
	}

	/**
	 * Schedules the retry again when members are waiting but no retry is
	 * scheduled, as after the plugin is deactivated (which clears the event
	 * and keeps the queue) and activated again. Runs on init; reads the queue
	 * only when no retry is scheduled.
	 *
	 * @return void
	 */
	public static function ensure_scheduled(): void {
		if ( 0 === self::next_run() && array() !== self::queued() ) {
			self::schedule();
		}
	}

	/**
	 * When the next retry runs, or 0 when none is scheduled.
	 *
	 * @return int Unix timestamp.
	 */
	public static function next_run(): int {
		if ( ! defined( 'AGEND_LOOP_SYNC_PROVISION_RETRY_HOOK' ) ) {
			return 0;
		}

		$next = wp_next_scheduled( AGEND_LOOP_SYNC_PROVISION_RETRY_HOOK );

		return false === $next ? 0 : (int) $next;
	}

	/**
	 * Sends every queued member again, with provisioning, then re-queues the
	 * ones still held up and schedules the next retry. Runs from WP-Cron.
	 *
	 * Provisioning turned off, or blocked by something only a person can fix
	 * (a missing scope, an old Agend Apps Core), empties the queue: there is
	 * nothing a retry can do, and the backfill sends everyone again once it is
	 * fixed. The plugin or member sync being off, or dry run, leaves the queue
	 * for the next hour.
	 *
	 * Sends at most RUN_LIMIT members, oldest first, and drops members who can
	 * no longer be sent (no membership number or a bad email).
	 *
	 * @return array<string, mixed> The run summary, as the backfill reports it.
	 */
	public static function run(): array {
		$queue = self::queued();
		if ( array() === $queue ) {
			return array();
		}

		if ( ! Agend_Loop_Sync_Settings::is_provision_enabled() ) {
			Agend_Loop_Sync_Logger::info( 'Provisioning is off; cleared the provisioning retry queue', array( 'members' => count( $queue ) ) );
			self::clear();
			return array();
		}

		$blocker = Agend_Loop_Sync_Settings::provision_blocker();
		if ( '' !== $blocker ) {
			Agend_Loop_Sync_Logger::warning(
				'Provisioning is blocked; cleared the provisioning retry queue. Run the backfill again once it is fixed',
				array(
					'members' => count( $queue ),
					'blocker' => $blocker,
				)
			);
			self::clear();
			return array();
		}

		if (
			! Agend_Loop_Sync_Settings::is_enabled()
			|| ! Agend_Loop_Sync_Settings::is_user_sync_enabled()
			|| Agend_Loop_Sync_Settings::is_dry_run()
		) {
			self::schedule();
			return array();
		}

		$users = array();
		$gone  = array();
		foreach ( array_slice( array_keys( $queue ), 0, self::RUN_LIMIT ) as $wp_id ) {
			$user = get_userdata( $wp_id );
			if ( $user instanceof WP_User ) {
				$users[] = $user;
			} else {
				$gone[] = $wp_id;
			}
		}
		self::remove( $gone );

		$summary = Agend_Loop_Sync_User_Sync::sync_users( $users, true );
		self::remove( $summary['unsendable_ids'] ?? array() );

		// Count this retry against everyone it reached; members it did not send
		// (deferred behind a rate limit, or past this run's limit) keep their
		// count.
		$queue = self::queued( true );
		$drop  = array();
		foreach ( $summary['attempted_ids'] ?? array() as $wp_id ) {
			if ( ! isset( $queue[ $wp_id ] ) ) {
				continue;
			}
			++$queue[ $wp_id ]['attempts'];
			if ( $queue[ $wp_id ]['attempts'] >= self::MAX_ATTEMPTS ) {
				$drop[ $wp_id ] = $queue[ $wp_id ]['reason'];
				unset( $queue[ $wp_id ] );
			}
		}
		self::store( $queue );

		foreach ( $drop as $wp_id => $reason ) {
			Agend_Loop_Sync_Logger::error(
				'Gave up creating a Loop account after repeated retries',
				array(
					'wp_id'    => (int) $wp_id,
					'reason'   => $reason,
					'attempts' => self::MAX_ATTEMPTS,
				)
			);
		}

		// The sync above re-queued anyone still waiting, which schedules a retry;
		// when this run gave up on all of them there is nothing left to retry.
		if ( array() === $queue ) {
			self::clear();
		} else {
			self::schedule();
		}

		Agend_Loop_Sync_Logger::info(
			'Provisioning retry complete',
			array(
				'sent'      => (int) ( $summary['sent'] ?? 0 ),
				'remaining' => count( $queue ),
				'dropped'   => count( $drop ),
			)
		);

		return $summary;
	}

	/**
	 * Saves the queue, deleting the option once it is empty.
	 *
	 * @param array<int, array{reason: string, attempts: int, since: int}> $queue The queue.
	 * @return void
	 */
	private static function store( array $queue ): void {
		if ( array() === $queue ) {
			delete_option( Agend_Loop_Sync_Settings::OPT_PROVISION_RETRY );
			return;
		}

		update_option( Agend_Loop_Sync_Settings::OPT_PROVISION_RETRY, $queue, false );
	}
}
