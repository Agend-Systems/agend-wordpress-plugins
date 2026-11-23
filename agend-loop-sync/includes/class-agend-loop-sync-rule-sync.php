<?php
/**
 * Community rule sync.
 *
 * @package Agend_Loop_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps criteria-based Loop channels ("communities") in step with membership.
 *
 * Each enabled rule owns one private Loop channel, keyed `rule:<slug>`. A run
 * reads every local member with a membership number from Upbeat (one member
 * detail call each, and one registrations call each only when a rule lists
 * events; the membership plugin caches both), works out which rules each member
 * meets, and sends every rule's roster to the gateway. Members who meet the
 * criteria are added and members who stop meeting them are removed. Members the
 * rule's lapse gate turns away lose access, and get it back automatically when
 * their status allows it again.
 *
 * Safety:
 *
 * - If any member lookup fails, or no member was read, every rule runs in merge
 *   mode (add and update only) and nothing is archived: a member who could not
 *   be read is indistinguishable from one who stopped matching.
 * - The gateway removes only memberships the sync itself added, so channel
 *   members someone added by hand in Loop are never removed.
 * - Roster members with no Loop account yet are skipped by the gateway and
 *   reported; they join on a later run once they have one.
 * - A rule that matches nobody, on a complete run, sends an empty roster in
 *   replace mode, which clears the memberships this sync added (members added
 *   by hand stay, and the channel is not archived). In merge mode nothing is
 *   sent.
 * - Channels of deleted or disabled rules are archived, on a complete run only.
 * - Dry run logs what would be sent and sends nothing.
 *
 * Channels created by hand in Loop, and those of the committee sync, are never
 * touched: this runner only reads, updates and archives `rule:` channels.
 */
class Agend_Loop_Sync_Rule_Sync {

	/**
	 * Option that stops two runs overlapping (a cron run and a manual one). Its
	 * value is `<expiry timestamp>:<run token>`; see acquire_lock().
	 */
	const LOCK = 'agend_loop_sync_rules_lock';

	/**
	 * Seconds a run's lock lasts without being renewed. A running scan renews it
	 * well before then; only a crashed run leaves it to expire.
	 */
	const LOCK_TTL = 600;

	/**
	 * The lock value this run holds (`<expiry>:<token>`), or null when it holds
	 * none.
	 *
	 * @var string|null
	 */
	private $lock_value = null;

	/**
	 * Set when a renewal found the lock was no longer ours.
	 *
	 * @var bool
	 */
	private $lock_lost = false;

	/**
	 * Upbeat client override, used by tests. Null means the real
	 * Iugo_Membership_Kiosk_API singleton.
	 *
	 * @var object|null
	 */
	private $api_override;

	/**
	 * @param object|null $api Optional Upbeat client; defaults to the membership plugin's.
	 */
	public function __construct( $api = null ) {
		$this->api_override = $api;
	}

	/**
	 * The Upbeat client, or null when the membership plugin is not available.
	 *
	 * @return object|null
	 */
	private function api() {
		if ( null !== $this->api_override ) {
			return $this->api_override;
		}

		return class_exists( 'Iugo_Membership_Kiosk_API' ) ? Iugo_Membership_Kiosk_API::instance() : null;
	}

	/**
	 * Registers the cron handlers and the triggers that schedule a debounced run.
	 *
	 * @return void
	 */
	public function register() {
		add_action( AGEND_LOOP_SYNC_RULES_CRON_HOOK, array( $this, 'run' ) );
		add_action( AGEND_LOOP_SYNC_RULES_DEBOUNCE_HOOK, array( $this, 'run' ) );
		add_action( 'iugo_membership_kiosk_invalidate_member_caches_complete', array( $this, 'on_member_changed' ), 10, 2 );
		add_action( 'wp_login', array( $this, 'on_login' ), 10, 2 );
	}

	/**
	 * A member's Upbeat data changed (their cache was invalidated): schedule one
	 * rule run a few minutes out. Never runs the sync in this request.
	 *
	 * @param mixed $membership_number Unused.
	 * @param mixed $event_name        Unused.
	 * @return void
	 */
	public function on_member_changed( $membership_number = '', $event_name = '' ) {
		Agend_Loop_Sync_Settings::schedule_debounced_rule_run();
	}

	/**
	 * A member signed in, so they may have a Loop account now: schedule one rule
	 * run a few minutes out. Only members with a membership number count; staff
	 * sign-ins change nothing. Never runs the sync in this request.
	 *
	 * @param mixed $user_login Unused.
	 * @param mixed $user       The signed-in user.
	 * @return void
	 */
	public function on_login( $user_login = '', $user = null ) {
		if ( ! is_object( $user ) || ! isset( $user->ID ) ) {
			return;
		}

		if ( '' === trim( (string) get_user_meta( (int) $user->ID, 'imk_membership_number', true ) ) ) {
			return;
		}

		Agend_Loop_Sync_Settings::schedule_debounced_rule_run();
	}

	/**
	 * Runs a full community rule sync.
	 *
	 * @return array<string, mixed> A summary of the run.
	 */
	public function run(): array {
		$summary = array(
			'members_scanned'         => 0,
			'skipped_invalid_members' => 0,
			'failed_members'          => 0,
			'rules_total'             => 0,
			'rules_synced'            => 0,
			'rules'                   => array(),
			'applied_members'         => 0,
			'skipped_members'         => 0,
			'removed_members'         => 0,
			'held_members'            => 0,
			'held_results'            => array(),
			'archived'                => 0,
			'mode'                    => 'replace',
			'unresolved_external_ids' => array(),
			'errors'                  => array(),
			'dry_run'                 => Agend_Loop_Sync_Settings::is_dry_run(),
			'member_counts_reported'  => false,
			// Whether the run got as far as reading members. When false, `not_run`
			// says why: disabled, unavailable or locked.
			'ran'                     => false,
			'not_run'                 => '',
		);

		if ( ! Agend_Loop_Sync_Settings::is_enabled() || ! Agend_Loop_Sync_Settings::is_rule_sync_enabled() ) {
			// Nothing ran, so keep the last real run's time and summary.
			$summary['errors'][] = 'Community rule sync is disabled in settings';
			$summary['not_run']  = 'disabled';
			return $summary;
		}

		if ( null === $this->api() || ! function_exists( 'agend_apps_loop_sync_committee' ) ) {
			$summary['errors'][] = 'Required plugins (membership API / agend-apps-core) are unavailable';
			$summary['not_run']  = 'unavailable';
			return $this->finish( $summary );
		}

		if ( ! $this->acquire_lock() ) {
			// Another run is in progress; leave its result to it.
			$summary['errors'][] = 'A community rule run is already in progress';
			$summary['not_run']  = 'locked';
			return $summary;
		}

		$this->lock_lost = false;

		try {
			$summary['ran'] = true;
			$this->run_rules( $summary );
		} finally {
			$this->release_lock();
		}

		return $this->finish( $summary );
	}

	/**
	 * Takes the run lock, atomically.
	 *
	 * The lock is an option row whose value is `<expiry>:<token>`, the token
	 * being random per run. The row is inserted with INSERT IGNORE directly
	 * against the database (WordPress's own add_option() is an upsert, so two
	 * racing callers can both be told they added it), and the affected-row count
	 * says whether this run's row won. A lock past its expiry is taken over with
	 * an UPDATE that only matches the exact value just read, so two runs racing
	 * for the same takeover cannot both win. Direct SQL also bypasses any
	 * persistent object cache. Without $wpdb it falls back to add_option().
	 *
	 * @return bool Whether this run now holds the lock.
	 */
	private function acquire_lock(): bool {
		global $wpdb;

		$value = ( time() + self::LOCK_TTL ) . ':' . bin2hex( random_bytes( 8 ) );

		if ( ! ( $wpdb instanceof wpdb ) ) {
			return $this->acquire_lock_without_wpdb( $value );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the lock must be atomic; see the docblock.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				self::LOCK,
				$value
			)
		);
		wp_cache_delete( self::LOCK, 'options' );

		if ( 1 === (int) $wpdb->rows_affected ) {
			$this->lock_value = $value;
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$existing = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK )
		);

		if ( null === $existing || ! self::lock_expired( (string) $existing ) ) {
			return false;
		}

		if ( $this->swap_lock( (string) $existing, $value ) ) {
			$this->lock_value = $value;
			return true;
		}

		return false;
	}

	/**
	 * The add_option() version of acquire_lock(), for a host with no $wpdb.
	 *
	 * @param string $value The lock value to take.
	 * @return bool Whether this run now holds the lock.
	 */
	private function acquire_lock_without_wpdb( string $value ): bool {
		$existing = get_option( self::LOCK, false );

		if ( false !== $existing ) {
			if ( ! self::lock_expired( (string) $existing ) ) {
				return false;
			}

			delete_option( self::LOCK );
		}

		if ( add_option( self::LOCK, $value, '', false ) ) {
			$this->lock_value = $value;
			return true;
		}

		return false;
	}

	/**
	 * Replaces the lock value, only if it is still `$old`.
	 *
	 * @param string $old The value the caller read.
	 * @param string $new The value to write.
	 * @return bool Whether the swap happened.
	 */
	private function swap_lock( string $old, string $new ): bool {
		global $wpdb;

		if ( ! ( $wpdb instanceof wpdb ) ) {
			// Best effort: re-read, compare, write.
			if ( (string) get_option( self::LOCK, '' ) !== $old ) {
				return false;
			}

			update_option( self::LOCK, $new, false );
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- compare-and-swap; see acquire_lock().
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$new,
				self::LOCK,
				$old
			)
		);
		wp_cache_delete( self::LOCK, 'options' );

		return 1 === (int) $wpdb->rows_affected;
	}

	/**
	 * Whether a stored lock value is past its expiry. A malformed value counts
	 * as expired.
	 *
	 * @param string $value Stored value.
	 * @return bool
	 */
	private static function lock_expired( string $value ): bool {
		return (int) strtok( $value, ':' ) <= time();
	}

	/**
	 * Pushes this run's lock expiry out by the full TTL when less than half of it
	 * remains, but only while the stored value is still ours. Sets lock_lost when
	 * it is not, so the run stops before it sends a roster built under a lock it
	 * no longer holds.
	 *
	 * @param bool $force Renew whatever the time remaining.
	 * @return bool Whether this run still holds the lock.
	 */
	private function renew_lock( bool $force = false ): bool {
		if ( null === $this->lock_value ) {
			return false;
		}

		$expiry = (int) strtok( $this->lock_value, ':' );
		if ( ! $force && $expiry - time() > self::LOCK_TTL / 2 ) {
			return true;
		}

		$token = (string) substr( $this->lock_value, (int) strpos( $this->lock_value, ':' ) + 1 );
		$new   = ( time() + self::LOCK_TTL ) . ':' . $token;

		if ( $this->swap_lock( $this->lock_value, $new ) ) {
			$this->lock_value = $new;
			return true;
		}

		$this->lock_lost = true;
		return false;
	}

	/**
	 * Releases the lock, only if the stored value is still this run's, so a run
	 * whose lock expired never deletes the lock of the run that took over.
	 *
	 * @return void
	 */
	private function release_lock(): void {
		global $wpdb;

		if ( null === $this->lock_value ) {
			return;
		}

		if ( $wpdb instanceof wpdb ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- compare-and-delete; see acquire_lock().
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
					self::LOCK,
					$this->lock_value
				)
			);
			wp_cache_delete( self::LOCK, 'options' );
		} elseif ( (string) get_option( self::LOCK, '' ) === $this->lock_value ) {
			delete_option( self::LOCK );
		}

		$this->lock_value = null;
	}

	/**
	 * The body of a run, once the preconditions hold.
	 *
	 * @param array<string, mixed> $summary Run summary, by reference.
	 * @return void
	 */
	private function run_rules( array &$summary ): void {
		$rules                  = Agend_Loop_Sync_Settings::get_enabled_rules();
		$summary['rules_total'] = count( $rules );

		$rosters = $this->build_rosters( $rules, $summary );

		if ( $this->lock_lost ) {
			$summary['errors'][] = 'The run lock was lost to another run; nothing was sent';
			Agend_Loop_Sync_Logger::warning( 'Rule sync stopped: run lock lost' );
			return;
		}

		// Replace mode and archiving both treat the data as complete. With no
		// enabled rule nothing was read and nothing depends on it. Otherwise, if
		// any member's lookup failed, or no member was read at all, a missing
		// member is indistinguishable from one who stopped matching, so only add
		// and update.
		$complete = array() === $rules || ( 0 === $summary['failed_members'] && $summary['members_scanned'] > 0 );
		if ( ! $complete ) {
			$summary['mode']     = 'merge';
			$summary['errors'][] = 'Upbeat data was incomplete; ran in merge mode with no removals or archiving';
			Agend_Loop_Sync_Logger::warning(
				'Rule sync ran in merge mode: Upbeat data incomplete',
				array(
					'failed_members'  => $summary['failed_members'],
					'members_scanned' => $summary['members_scanned'],
				)
			);
		}

		// Loop's view of the channels this site has synced: needed to archive an
		// emptied rule's channel and the channels of removed rules. A dry run makes
		// no gateway calls, so it does not read it and archives nothing.
		$loop_committees = $complete && ! Agend_Loop_Sync_Settings::is_dry_run() ? Agend_Loop_Sync_Gateway::fetch_loop_committees( $summary ) : null;
		$live            = $this->live_channels( $loop_committees );

		foreach ( $rules as $rule ) {
			// Each send can take a while; renew the lock every time, which also
			// confirms it is still ours, and never send a roster once it is not.
			if ( ! $this->renew_lock( true ) ) {
				$summary['errors'][] = 'The run lock was lost to another run; remaining rules were not sent';
				Agend_Loop_Sync_Logger::warning( 'Rule sync stopped: run lock lost' );
				return;
			}

			$this->sync_rule( $rule, $rosters[ $rule['slug'] ]['members'] ?? array(), $rosters[ $rule['slug'] ]['matched'] ?? 0, $live, $loop_committees, $summary );
		}

		if ( null !== $loop_committees ) {
			$this->archive_removed_rules( $rules, $live, $summary );
		}

		Agend_Loop_Sync_Logger::info( 'Rule sync run complete', array_diff_key( $summary, array( 'held_results' => true, 'rules' => true ) ) );
	}

	/**
	 * Reads every local member once and works out which rules each meets.
	 *
	 * @param array<int, array<string, mixed>> $rules   Enabled rules.
	 * @param array<string, mixed>             $summary Run summary, by reference for counters.
	 * @return array<string, array{members: array<int, string>, matched: int}> Rule slug => channel role by external id, and how many members met the criteria.
	 */
	private function build_rosters( array $rules, array &$summary ): array {
		$rosters = array();
		foreach ( $rules as $rule ) {
			$rosters[ $rule['slug'] ] = array(
				'members' => array(),
				'matched' => 0,
			);
		}

		if ( array() === $rules ) {
			return $rosters;
		}

		$needs_events = false;
		foreach ( $rules as $rule ) {
			$needs_events = $needs_events || Agend_Loop_Sync_Rule_Matcher::uses_events( $rule );
		}

		foreach ( Agend_Loop_Sync_Gateway::member_users() as $user ) {
			$membership_number = trim( (string) get_user_meta( $user->ID, 'imk_membership_number', true ) );
			if ( '' === $membership_number ) {
				continue;
			}

			$external_id = Agend_Loop_Sync_Contact_Resolver::to_external_id( $membership_number );
			if ( null === $external_id ) {
				++$summary['skipped_invalid_members'];
				continue;
			}

			++$summary['members_scanned'];

			// One Upbeat read per member can outlast the lock; renew it as the
			// scan goes. If it was lost, stop: the roster would be stale.
			if ( ! $this->renew_lock() ) {
				return $rosters;
			}

			$detail = $this->api()->get_member_details_by_id( $membership_number );

			// A failed lookup is not an empty one. Count it so the run does not
			// remove anyone.
			if ( ! is_object( $detail ) ) {
				++$summary['failed_members'];
				continue;
			}

			$registrations = array();
			if ( $needs_events ) {
				$result = $this->api()->get_member_registrations( $membership_number );
				if ( ! is_object( $result ) || ! method_exists( $result, 'get_registrations' ) ) {
					++$summary['failed_members'];
					continue;
				}

				$registrations = $result->get_registrations();
			}

			foreach ( $rules as $rule ) {
				if ( Agend_Loop_Sync_Rule_Matcher::matches( $rule, $detail, $registrations, (int) $user->ID ) ) {
					$rosters[ $rule['slug'] ]['members'][ $external_id ] = 'member';
					++$rosters[ $rule['slug'] ]['matched'];
				}
			}
		}

		// Always-include members are in whatever the criteria say, and the highest
		// role wins, so a moderator who also meets the criteria stays a moderator.
		foreach ( $rules as $rule ) {
			foreach ( $rule['always_include'] as $entry ) {
				$external_id = Agend_Loop_Sync_Contact_Resolver::to_external_id( (string) $entry['membership_number'] );
				if ( null === $external_id ) {
					continue;
				}

				$current = $rosters[ $rule['slug'] ]['members'][ $external_id ] ?? '';
				if ( '' === $current || Agend_Loop_Sync_Role_Mapper::rank( $entry['role'] ) > Agend_Loop_Sync_Role_Mapper::rank( $current ) ) {
					$rosters[ $rule['slug'] ]['members'][ $external_id ] = $entry['role'];
				}
			}
		}

		return $rosters;
	}

	/**
	 * The live (unarchived) Loop channels, by unique id.
	 *
	 * @param array<int, array<string, mixed>>|null $loop_committees Everything Loop holds, or null when it could not be read.
	 * @return array<string, true>
	 */
	private function live_channels( ?array $loop_committees ): array {
		$live = array();
		foreach ( (array) $loop_committees as $item ) {
			$unique_id = (string) ( $item['unique_id'] ?? '' );
			if ( '' !== $unique_id && empty( $item['is_archived'] ) ) {
				$live[ $unique_id ] = true;
			}
		}

		return $live;
	}

	/**
	 * Sends one rule's roster (empty in replace mode when nobody matches).
	 *
	 * @param array<string, mixed>                  $rule            The rule.
	 * @param array<int, string>                    $members         Channel role by external id.
	 * @param int                                   $matched         How many members met the criteria.
	 * @param array<string, true>                   $live            Live Loop channels by unique id.
	 * @param array<int, array<string, mixed>>|null $loop_committees Everything Loop holds, or null when unread.
	 * @param array<string, mixed>                  $summary         Run summary, by reference.
	 * @return void
	 */
	private function sync_rule( array $rule, array $members, int $matched, array $live, ?array $loop_committees, array &$summary ): void {
		$unique_id = Agend_Loop_Sync_Gateway::RULE_PREFIX . $rule['slug'];

		$stats = array(
			'name'    => $rule['name'],
			'matched' => $matched,
			'sent'    => count( $members ),
			'applied' => 0,
			'skipped' => 0,
			'removed' => 0,
			'held'    => 0,
			'outcome' => '',
		);

		ksort( $members );
		$roster = array();
		foreach ( $members as $external_id => $role ) {
			$roster[] = array(
				'external_id'  => (int) $external_id,
				'channel_role' => $role,
			);
		}

		// Nobody matches. In merge mode there is nothing to add, so nothing is
		// sent. In replace mode the empty roster is sent: it is how the
		// memberships this sync added are cleared. Members added by hand stay.
		if ( array() === $roster && 'replace' !== $summary['mode'] ) {
			$stats['outcome']                  = 'empty_skipped';
			$summary['rules'][ $rule['slug'] ] = $stats;
			return;
		}

		if ( count( $roster ) > Agend_Loop_Sync_Gateway::ROSTER_MAX ) {
			$summary['errors'][] = sprintf( '%s: %d members exceeds the gateway limit of %d; not synced', $rule['name'], count( $roster ), Agend_Loop_Sync_Gateway::ROSTER_MAX );
			Agend_Loop_Sync_Logger::error(
				'Rule roster too large',
				array(
					'rule'    => $rule['slug'],
					'members' => count( $roster ),
				)
			);
			$stats['outcome']                  = 'too_large';
			$summary['rules'][ $rule['slug'] ] = $stats;
			return;
		}

		$payload = array(
			'idp_entity_id' => Agend_Loop_Sync_Settings::idp_entity_id(),
			'unique_id'     => $unique_id,
			'name'          => $rule['name'],
			'is_active'     => true,
			'roster'        => $roster,
			// Replace mode removes a member who stopped matching; merge mode (used
			// when any Upbeat read failed) only adds and updates.
			'mode'          => $summary['mode'],
		);

		/**
		 * Filters whether the optional channel flags are sent with a rule's roster.
		 *
		 * The flags `member_can_leave` and `allow_manual_members` need a gateway
		 * that accepts them; a gateway that does not may reject the request for an
		 * unknown field, so they are off by default. Return true once the gateway
		 * supports them.
		 *
		 * @param bool  $send Whether to send the flags. Default false.
		 * @param array $rule The rule.
		 */
		if ( apply_filters( 'agend_loop_sync_send_rule_channel_flags', false, $rule ) ) {
			$payload['member_can_leave']     = (bool) $rule['member_can_leave'];
			$payload['allow_manual_members'] = (bool) $rule['allow_manual_members'];
		}

		if ( Agend_Loop_Sync_Settings::is_dry_run() ) {
			Agend_Loop_Sync_Logger::info(
				'Dry run: would sync rule',
				array(
					'unique_id'   => $unique_id,
					'mode'        => $payload['mode'],
					'roster_size' => count( $roster ),
				)
			);
			$stats['outcome']                  = 'dry_run';
			$summary['rules'][ $rule['slug'] ] = $stats;
			++$summary['rules_synced'];
			return;
		}

		$response = agend_apps_loop_sync_committee( $payload );

		if ( is_wp_error( $response ) ) {
			$message             = $response->get_error_message();
			$summary['errors'][] = sprintf( '%s: %s', $rule['name'], $message );
			Agend_Loop_Sync_Logger::error(
				'Rule sync failed',
				array(
					'rule'  => $rule['slug'],
					'error' => $message,
				)
			);
			$stats['outcome']                  = 'failed';
			$summary['rules'][ $rule['slug'] ] = $stats;
			return;
		}

		$data   = is_array( $response ) ? ( $response['data'] ?? $response ) : array();
		$data   = is_array( $data ) ? $data : array();
		$counts = Agend_Loop_Sync_Gateway::apply_response( $data, $summary );

		$stats['applied'] = $counts['applied'];
		$stats['skipped'] = $counts['skipped'];
		$stats['removed'] = $counts['removed'];
		$stats['held']    = Agend_Loop_Sync_Gateway::record_holds( $rule['name'], $data, $summary, 'rule' );
		$stats['outcome'] = array() === $roster ? 'emptied' : 'synced';

		Agend_Loop_Sync_Logger::info(
			'Rule synced',
			array(
				'rule'    => $rule['slug'],
				'members' => count( $roster ),
				'applied' => $counts['applied'],
				'skipped' => $counts['skipped'],
				'removed' => $counts['removed'],
			)
		);

		$summary['rules'][ $rule['slug'] ] = $stats;
		++$summary['rules_synced'];
	}

	/**
	 * Archives the Loop channels of rules that were deleted or disabled. Only
	 * `rule:` channels are considered, and only live ones.
	 *
	 * @param array<int, array<string, mixed>> $rules   Enabled rules.
	 * @param array<string, true>              $live    Live Loop channels by unique id.
	 * @param array<string, mixed>             $summary Run summary, by reference.
	 * @return void
	 */
	private function archive_removed_rules( array $rules, array $live, array &$summary ): void {
		$current = array();
		foreach ( $rules as $rule ) {
			$current[ Agend_Loop_Sync_Gateway::RULE_PREFIX . $rule['slug'] ] = true;
		}

		foreach ( array_keys( $live ) as $unique_id ) {
			if ( Agend_Loop_Sync_Gateway::is_rule_channel( (string) $unique_id ) && ! isset( $current[ $unique_id ] ) ) {
				Agend_Loop_Sync_Gateway::archive_channel( (string) $unique_id, 'rule deleted or disabled', $summary, 'rule channel' );
			}
		}
	}

	/**
	 * Persists the run timestamp and summary, then returns the summary.
	 *
	 * @param array<string, mixed> $summary Run summary.
	 * @return array<string, mixed>
	 */
	private function finish( array $summary ): array {
		$summary = Agend_Loop_Sync_Gateway::trim_summary( $summary );

		update_option( Agend_Loop_Sync_Settings::OPT_LAST_RULE_SYNC, time(), false );
		update_option( Agend_Loop_Sync_Settings::OPT_LAST_RULE_SUMMARY, $summary, false );

		return $summary;
	}
}
