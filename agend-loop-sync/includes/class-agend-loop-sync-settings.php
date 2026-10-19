<?php
/**
 * Settings.
 *
 * @package Agend_Loop_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and registers the plugin settings. All settings are stored as
 * discrete `agend_loop_sync_*` options and registered under a single
 * settings group so the admin form can persist them via options.php.
 *
 * All methods are static; this class is never instantiated.
 */
class Agend_Loop_Sync_Settings {

	const GROUP = 'agend_loop_sync_group';

	const OPT_ENABLED          = 'agend_loop_sync_enabled';
	const OPT_USER_SYNC        = 'agend_loop_sync_user_sync_enabled';
	const OPT_COMMITTEE_SYNC   = 'agend_loop_sync_committee_sync_enabled';
	const OPT_SCHEDULE         = 'agend_loop_sync_committee_schedule';
	const OPT_ROLE_MAP         = 'agend_loop_sync_role_map';
	const OPT_FILTER_MODE      = 'agend_loop_sync_committee_filter_mode';
	const OPT_FILTER_GROUPS    = 'agend_loop_sync_committee_groups';
	const OPT_FILTER_ALLOWLIST = 'agend_loop_sync_committee_allowlist';
	const OPT_DRY_RUN          = 'agend_loop_sync_dry_run';
	const OPT_LAST_SYNC        = 'agend_loop_sync_last_committee_sync';
	const OPT_LAST_SUMMARY     = 'agend_loop_sync_last_sync_summary';
	const OPT_ADOPT_QUEUE      = 'agend_loop_sync_adopt_queue';

	/**
	 * Most members one committee sync can hand over. The gateway refuses a longer
	 * `adopt_external_ids` list, so a queue is capped here rather than failing a
	 * whole committee.
	 */
	const ADOPT_MAX_IDS = 2000;

	/**
	 * Allowed Loop channel roles a committee role may map to.
	 *
	 * @var string[]
	 */
	const CHANNEL_ROLES = array( 'owner', 'moderator', 'member' );

	/**
	 * Allowed WP-Cron schedules for the committee sync.
	 *
	 * @var string[]
	 */
	const SCHEDULES = array( 'hourly', 'twicedaily', 'daily' );

	/**
	 * Default committee-role to channel-role mapping.
	 *
	 * @return array<string, string>
	 */
	public static function default_role_map(): array {
		return array(
			'Chair'      => 'owner',
			'Convenor'   => 'owner',
			'President'   => 'owner',
			'Vice-Chair' => 'moderator',
			'Deputy'     => 'moderator',
			'Secretary'  => 'moderator',
			'Member'     => 'member',
		);
	}

	/**
	 * The WordPress SAML IdP entity id (Issuer) this site asserts.
	 *
	 * Sent as `idp_entity_id` on every Loop integration call so the gateway
	 * resolves members strictly against this site's SSO connection
	 * (SPEC-LOOP-002). Read from the agend-saml-idp settings, falling back to
	 * the metadata URL the IdP defaults to when no entity id is configured, so
	 * the value matches what the IdP asserts as its Issuer at login.
	 *
	 * @return string The IdP entity id.
	 */
	public static function idp_entity_id(): string {
		$idp_settings = get_option( 'wp_saml_idp_settings', array() );

		if ( is_array( $idp_settings ) && ! empty( $idp_settings['entity_id'] ) ) {
			return (string) $idp_settings['entity_id'];
		}

		return site_url( '/saml/metadata' );
	}

	/**
	 * Seeds default option values without overwriting existing ones.
	 *
	 * @return void
	 */
	public static function seed_defaults() {
		add_option( self::OPT_ENABLED, '0' );
		add_option( self::OPT_USER_SYNC, '1' );
		add_option( self::OPT_COMMITTEE_SYNC, '1' );
		add_option( self::OPT_SCHEDULE, 'daily' );
		add_option( self::OPT_ROLE_MAP, self::default_role_map() );
		add_option( self::OPT_FILTER_MODE, 'all' );
		add_option( self::OPT_FILTER_GROUPS, array() );
		add_option( self::OPT_FILTER_ALLOWLIST, array() );
		// Dry-run defaults ON so a fresh install never writes to Loop unattended.
		add_option( self::OPT_DRY_RUN, '1' );
	}

	/**
	 * Registers all settings for the settings group.
	 *
	 * @return void
	 */
	public static function register() {
		register_setting(
			self::GROUP,
			self::OPT_ENABLED,
			array( 'sanitize_callback' => array( __CLASS__, 'sanitize_bool' ) )
		);
		register_setting(
			self::GROUP,
			self::OPT_USER_SYNC,
			array( 'sanitize_callback' => array( __CLASS__, 'sanitize_bool' ) )
		);
		register_setting(
			self::GROUP,
			self::OPT_COMMITTEE_SYNC,
			array( 'sanitize_callback' => array( __CLASS__, 'sanitize_bool' ) )
		);
		register_setting(
			self::GROUP,
			self::OPT_DRY_RUN,
			array( 'sanitize_callback' => array( __CLASS__, 'sanitize_bool' ) )
		);
		register_setting(
			self::GROUP,
			self::OPT_SCHEDULE,
			array( 'sanitize_callback' => array( __CLASS__, 'sanitize_schedule' ) )
		);
		register_setting(
			self::GROUP,
			self::OPT_FILTER_MODE,
			array( 'sanitize_callback' => array( __CLASS__, 'sanitize_filter_mode' ) )
		);
		register_setting(
			self::GROUP,
			self::OPT_ROLE_MAP,
			array( 'sanitize_callback' => array( __CLASS__, 'sanitize_role_map' ) )
		);
		register_setting(
			self::GROUP,
			self::OPT_FILTER_GROUPS,
			array( 'sanitize_callback' => array( __CLASS__, 'sanitize_string_list' ) )
		);
		register_setting(
			self::GROUP,
			self::OPT_FILTER_ALLOWLIST,
			array( 'sanitize_callback' => array( __CLASS__, 'sanitize_string_list' ) )
		);

		// Reschedule the committee cron when the schedule setting changes, so a
		// new interval takes effect immediately rather than after the old event
		// next fires.
		add_action( 'update_option_' . self::OPT_SCHEDULE, array( __CLASS__, 'reschedule_committee_cron' ), 10, 0 );
	}

	/**
	 * Clears and re-schedules the committee WP-Cron event using the current
	 * schedule. Hooked to the schedule option changing.
	 *
	 * @return void
	 */
	public static function reschedule_committee_cron() {
		if ( ! defined( 'AGEND_LOOP_SYNC_CRON_HOOK' ) ) {
			return;
		}

		$timestamp = wp_next_scheduled( AGEND_LOOP_SYNC_CRON_HOOK );
		if ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, AGEND_LOOP_SYNC_CRON_HOOK );
		}

		wp_schedule_event( time() + MINUTE_IN_SECONDS, self::get_committee_schedule(), AGEND_LOOP_SYNC_CRON_HOOK );
	}

	/**
	 * Re-creates the committee cron event if it has gone missing.
	 *
	 * The event is only scheduled on activation, so a site that received the
	 * plugin by file sync or an update, or lost the event to a cron reset,
	 * would otherwise never run the scheduled sync.
	 *
	 * @return void
	 */
	public static function ensure_committee_cron() {
		if ( ! defined( 'AGEND_LOOP_SYNC_CRON_HOOK' ) || ! self::is_enabled() || ! self::is_committee_sync_enabled() ) {
			return;
		}

		if ( false === wp_next_scheduled( AGEND_LOOP_SYNC_CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, self::get_committee_schedule(), AGEND_LOOP_SYNC_CRON_HOOK );
		}
	}

	/* ----------------------------------------------------------------------
	 * Getters
	 * ------------------------------------------------------------------- */

	/**
	 * @return bool Whether the plugin is enabled.
	 */
	public static function is_enabled(): bool {
		return '1' === (string) get_option( self::OPT_ENABLED, '0' );
	}

	/**
	 * @return bool Whether user sync is enabled.
	 */
	public static function is_user_sync_enabled(): bool {
		return '1' === (string) get_option( self::OPT_USER_SYNC, '1' );
	}

	/**
	 * @return bool Whether committee sync is enabled.
	 */
	public static function is_committee_sync_enabled(): bool {
		return '1' === (string) get_option( self::OPT_COMMITTEE_SYNC, '1' );
	}

	/**
	 * @return bool Whether dry-run mode is enabled.
	 */
	public static function is_dry_run(): bool {
		return '1' === (string) get_option( self::OPT_DRY_RUN, '1' );
	}

	/**
	 * @return string The configured WP-Cron schedule.
	 */
	public static function get_committee_schedule(): string {
		$value = (string) get_option( self::OPT_SCHEDULE, 'daily' );
		return in_array( $value, self::SCHEDULES, true ) ? $value : 'daily';
	}

	/**
	 * @return string The committee filter mode: 'all', 'groups', or 'allowlist'.
	 */
	public static function get_filter_mode(): string {
		$value = (string) get_option( self::OPT_FILTER_MODE, 'all' );
		return in_array( $value, array( 'all', 'groups', 'allowlist' ), true ) ? $value : 'all';
	}

	/**
	 * @return array<string, string> The committee-role to channel-role map.
	 */
	public static function get_role_map(): array {
		$value = get_option( self::OPT_ROLE_MAP, array() );
		if ( ! is_array( $value ) || array() === $value ) {
			return self::default_role_map();
		}
		return $value;
	}

	/**
	 * @return string[] Committee group names to include (filter mode 'groups').
	 */
	public static function get_filter_groups(): array {
		$value = get_option( self::OPT_FILTER_GROUPS, array() );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * @return string[] Committee names to include (filter mode 'allowlist').
	 */
	public static function get_filter_allowlist(): array {
		$value = get_option( self::OPT_FILTER_ALLOWLIST, array() );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * The hand-over queue: members an administrator chose to hand to the
	 * committee sync, not yet sent. Cleaned on every read, so a damaged option
	 * can never put a bad value into a gateway request.
	 *
	 * @return array<string, int[]> Upbeat committee unique id => membership numbers (external ids).
	 */
	public static function get_adopt_queue(): array {
		return self::sanitize_adopt_queue( get_option( self::OPT_ADOPT_QUEUE, array() ) );
	}

	/**
	 * @param string $unique_id Upbeat committee unique id.
	 * @return int[] Membership numbers queued for that committee.
	 */
	public static function get_adopt_queue_for( string $unique_id ): array {
		$queue = self::get_adopt_queue();

		return $queue[ $unique_id ] ?? array();
	}

	/* ----------------------------------------------------------------------
	 * Hand-over queue changes
	 * ------------------------------------------------------------------- */

	/**
	 * Adds membership numbers to one committee's queue, keeping what is already
	 * queued. Anything beyond ADOPT_MAX_IDS is dropped.
	 *
	 * @param string $unique_id    Upbeat committee unique id.
	 * @param array  $external_ids Membership numbers to queue.
	 * @return int How many were newly queued.
	 */
	public static function queue_adoptions( string $unique_id, array $external_ids ): int {
		$unique_id = trim( $unique_id );
		if ( '' === $unique_id ) {
			return 0;
		}

		$queue  = self::get_adopt_queue();
		$before = $queue[ $unique_id ] ?? array();
		$merged = self::sanitize_external_ids( array_merge( $before, $external_ids ) );

		$queue[ $unique_id ] = $merged;
		update_option( self::OPT_ADOPT_QUEUE, self::sanitize_adopt_queue( $queue ), false );

		return count( $merged ) - count( $before );
	}

	/**
	 * Removes membership numbers from one committee's queue. Re-reads the queue
	 * first, so a number queued while a sync was running is not lost.
	 *
	 * @param string $unique_id    Upbeat committee unique id.
	 * @param array  $external_ids Membership numbers to remove.
	 * @return void
	 */
	public static function dequeue_adoptions( string $unique_id, array $external_ids ) {
		$queue = self::get_adopt_queue();
		if ( ! isset( $queue[ $unique_id ] ) ) {
			return;
		}

		$queue[ $unique_id ] = array_values( array_diff( $queue[ $unique_id ], self::sanitize_external_ids( $external_ids ) ) );
		update_option( self::OPT_ADOPT_QUEUE, self::sanitize_adopt_queue( $queue ), false );
	}

	/**
	 * Empties one committee's queue.
	 *
	 * @param string $unique_id Upbeat committee unique id.
	 * @return void
	 */
	public static function clear_adopt_queue( string $unique_id ) {
		$queue = self::get_adopt_queue();
		if ( ! isset( $queue[ $unique_id ] ) ) {
			return;
		}

		unset( $queue[ $unique_id ] );
		update_option( self::OPT_ADOPT_QUEUE, $queue, false );
	}

	/* ----------------------------------------------------------------------
	 * Sanitizers
	 * ------------------------------------------------------------------- */

	/**
	 * Coerces a checkbox value to '1' or '0'.
	 *
	 * @param mixed $value Raw value.
	 * @return string '1' or '0'.
	 */
	public static function sanitize_bool( $value ): string {
		return ( '1' === (string) $value || 1 === $value || true === $value || 'on' === $value ) ? '1' : '0';
	}

	/**
	 * Validates the WP-Cron schedule against the allowed set.
	 *
	 * @param mixed $value Raw value.
	 * @return string A valid schedule.
	 */
	public static function sanitize_schedule( $value ): string {
		$value = sanitize_text_field( (string) $value );
		return in_array( $value, self::SCHEDULES, true ) ? $value : 'daily';
	}

	/**
	 * Validates the filter mode against the allowed set.
	 *
	 * @param mixed $value Raw value.
	 * @return string A valid filter mode.
	 */
	public static function sanitize_filter_mode( $value ): string {
		$value = sanitize_text_field( (string) $value );
		return in_array( $value, array( 'all', 'groups', 'allowlist' ), true ) ? $value : 'all';
	}

	/**
	 * Parses the role-map textarea (one `Label = role` mapping per line) into
	 * a validated associative array. Lines whose channel role is not one of
	 * owner/moderator/member are dropped.
	 *
	 * @param mixed $value Raw textarea string or array.
	 * @return array<string, string> The sanitized role map.
	 */
	public static function sanitize_role_map( $value ): array {
		// Already an array (e.g. seeded default): validate in place.
		if ( is_array( $value ) ) {
			$map = array();
			foreach ( $value as $label => $role ) {
				$label = trim( sanitize_text_field( (string) $label ) );
				$role  = strtolower( trim( sanitize_text_field( (string) $role ) ) );
				if ( '' !== $label && in_array( $role, self::CHANNEL_ROLES, true ) ) {
					$map[ $label ] = $role;
				}
			}
			return $map;
		}

		$map   = array();
		$lines = preg_split( '/\r\n|\r|\n/', (string) $value );
		foreach ( $lines as $line ) {
			if ( false === strpos( $line, '=' ) ) {
				continue;
			}
			list( $label, $role ) = array_map( 'trim', explode( '=', $line, 2 ) );
			$label                = sanitize_text_field( $label );
			$role                 = strtolower( sanitize_text_field( $role ) );
			if ( '' !== $label && in_array( $role, self::CHANNEL_ROLES, true ) ) {
				$map[ $label ] = $role;
			}
		}

		return $map;
	}

	/**
	 * Parses a textarea (one entry per line) into a list of trimmed,
	 * non-empty strings.
	 *
	 * @param mixed $value Raw textarea string or array.
	 * @return string[] The sanitized list.
	 */
	public static function sanitize_string_list( $value ): array {
		if ( is_array( $value ) ) {
			$lines = $value;
		} else {
			$lines = preg_split( '/\r\n|\r|\n/', (string) $value );
		}

		$out = array();
		foreach ( $lines as $line ) {
			$line = trim( sanitize_text_field( (string) $line ) );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Cleans the hand-over queue: committees with a non-empty unique id, each
	 * with its distinct positive membership numbers. A committee left with none
	 * is dropped.
	 *
	 * @param mixed $value Raw option value.
	 * @return array<string, int[]> The sanitized queue.
	 */
	public static function sanitize_adopt_queue( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$queue = array();
		foreach ( $value as $unique_id => $external_ids ) {
			// PHP turns a numeric string key into an int, so cast it back.
			$unique_id = trim( (string) $unique_id );
			if ( '' === $unique_id || ! is_array( $external_ids ) ) {
				continue;
			}

			$external_ids = self::sanitize_external_ids( $external_ids );
			if ( array() !== $external_ids ) {
				$queue[ $unique_id ] = $external_ids;
			}
		}

		return $queue;
	}

	/**
	 * Reduces a list to distinct positive integers (at most ADOPT_MAX_IDS),
	 * the shape the gateway accepts. A value that is not plain digits, or does
	 * not fit a 64-bit int, is dropped.
	 *
	 * @param array $values Raw values.
	 * @return int[] The sanitized list.
	 */
	public static function sanitize_external_ids( array $values ): array {
		$out = array();
		foreach ( $values as $value ) {
			if ( is_int( $value ) ) {
				$id = $value;
			} elseif ( is_string( $value ) ) {
				$id = Agend_Loop_Sync_Contact_Resolver::to_external_id( $value );
			} else {
				continue;
			}

			if ( null !== $id && $id > 0 ) {
				$out[ $id ] = $id;
			}
		}

		return array_slice( array_values( $out ), 0, self::ADOPT_MAX_IDS );
	}
}
