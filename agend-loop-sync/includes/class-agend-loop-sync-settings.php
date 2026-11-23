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

	const OPT_RULE_SYNC        = 'agend_loop_sync_rule_sync_enabled';
	const OPT_RULES            = 'agend_loop_sync_rules';
	const OPT_ACCESS_STATUSES  = 'agend_loop_sync_access_statuses';
	const OPT_RULE_SCHEDULE    = 'agend_loop_sync_rule_schedule';
	const OPT_LAST_RULE_SYNC   = 'agend_loop_sync_last_rule_sync';
	const OPT_LAST_RULE_SUMMARY = 'agend_loop_sync_last_rule_summary';

	/**
	 * Seconds to wait after a membership change or sign-in before the single
	 * debounced rule run fires.
	 */
	const RULE_DEBOUNCE_SECONDS = 300;

	/**
	 * Longest rule slug. The gateway key is `rule:<slug>`, well inside its 255.
	 */
	const RULE_SLUG_MAX = 64;

	/**
	 * Longest rule (channel) name.
	 */
	const RULE_NAME_MAX = 80;

	/**
	 * Most rules one site can define.
	 */
	const RULES_MAX = 50;

	/**
	 * Most always-include entries one rule can hold.
	 */
	const ALWAYS_INCLUDE_MAX = 200;

	/**
	 * How a rule combines its criteria: every configured one must pass, or any.
	 *
	 * @var string[]
	 */
	const RULE_MATCH_MODES = array( 'all', 'any' );

	/**
	 * Member statuses an administrator can allow to keep access, as Upbeat spells
	 * them (the strings behind the membership kiosk's status helpers). Any other
	 * status counts as lapsed.
	 *
	 * @var string[]
	 */
	const ACCESS_STATUS_CHOICES = array( 'Financial', 'Renewal Due', 'Overdue', 'Reinstatable', 'Lapsed' );

	/**
	 * The statuses that keep access unless an administrator changes them: access
	 * ends at Reinstatable or any lapsed status.
	 *
	 * @var string[]
	 */
	const DEFAULT_ACCESS_STATUSES = array( 'Financial', 'Renewal Due', 'Overdue' );

	/**
	 * The status that always keeps access, whatever is saved.
	 */
	const ALWAYS_ALLOWED_STATUS = 'Financial';

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
		// Committee channels are opt-in: a site may manage channel membership in
		// Loop and use member sync alone. Only new installs get this; a site
		// that already has the option keeps its setting.
		add_option( self::OPT_COMMITTEE_SYNC, '0' );
		add_option( self::OPT_SCHEDULE, 'daily' );
		add_option( self::OPT_ROLE_MAP, self::default_role_map() );
		add_option( self::OPT_FILTER_MODE, 'all' );
		add_option( self::OPT_FILTER_GROUPS, array() );
		add_option( self::OPT_FILTER_ALLOWLIST, array() );
		// Dry-run defaults ON so a fresh install never writes to Loop unattended.
		add_option( self::OPT_DRY_RUN, '1' );
		// Community rules are opt-in, like committee channels.
		add_option( self::OPT_RULE_SYNC, '0' );
		add_option( self::OPT_RULE_SCHEDULE, 'hourly' );
		add_option( self::OPT_ACCESS_STATUSES, self::DEFAULT_ACCESS_STATUSES );
		add_option( self::OPT_RULES, array() );
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

		register_setting(
			self::GROUP,
			self::OPT_RULE_SYNC,
			array( 'sanitize_callback' => array( __CLASS__, 'sanitize_bool' ) )
		);
		register_setting(
			self::GROUP,
			self::OPT_RULE_SCHEDULE,
			array( 'sanitize_callback' => array( __CLASS__, 'sanitize_rule_schedule' ) )
		);
		register_setting(
			self::GROUP,
			self::OPT_ACCESS_STATUSES,
			array( 'sanitize_callback' => array( __CLASS__, 'sanitize_access_statuses' ) )
		);

		// The rules themselves are deliberately not registered here: they are
		// changed only through the rule screen's own nonce-checked actions, which
		// keep a saved rule's slug fixed.

		// Reschedule the committee cron when the schedule setting changes, so a
		// new interval takes effect immediately rather than after the old event
		// next fires.
		add_action( 'update_option_' . self::OPT_SCHEDULE, array( __CLASS__, 'reschedule_committee_cron' ), 10, 0 );

		// Schedule or clear the committee cron as soon as either switch changes,
		// rather than waiting for the next request's init.
		// add_option_* covers the first save on a site that never stored the
		// option. Requests that change these options outside the settings
		// screen (WP-CLI, REST) are caught by the init check.
		foreach ( array( self::OPT_ENABLED, self::OPT_COMMITTEE_SYNC ) as $switch ) {
			add_action( 'update_option_' . $switch, array( __CLASS__, 'sync_committee_cron' ), 10, 0 );
			add_action( 'add_option_' . $switch, array( __CLASS__, 'sync_committee_cron' ), 10, 0 );
		}

		// The same for the community rule cron.
		add_action( 'update_option_' . self::OPT_RULE_SCHEDULE, array( __CLASS__, 'reschedule_rules_cron' ), 10, 0 );
		foreach ( array( self::OPT_ENABLED, self::OPT_RULE_SYNC ) as $switch ) {
			add_action( 'update_option_' . $switch, array( __CLASS__, 'sync_rules_cron' ), 10, 0 );
			add_action( 'add_option_' . $switch, array( __CLASS__, 'sync_rules_cron' ), 10, 0 );
		}
	}

	/**
	 * Runs on plugin activation: seeds the defaults, then schedules the
	 * committee cron only when the plugin and committee sync are both on (never
	 * on a fresh install, which ships disabled).
	 *
	 * @return void
	 */
	public static function activate() {
		self::seed_defaults();
		self::sync_committee_cron();
		self::sync_rules_cron();
	}

	/**
	 * Seeds the defaults on a site that has never stored them.
	 *
	 * The activation hook seeds only the site it runs on, and not at all when
	 * the plugin arrives by deploy, as an mu-plugin, or on a multisite subsite
	 * created after network activation. Without a stored committee setting the
	 * getter reads it as on, so such a site would start committee sync the
	 * moment the plugin is enabled. The master switch is stored on every
	 * activated or saved site, so its absence means the defaults were never
	 * seeded; existing sites are left untouched.
	 *
	 * @return void
	 */
	public static function maybe_seed_defaults() {
		if ( false === get_option( self::OPT_ENABLED, false ) ) {
			self::seed_defaults();
		}
	}

	/**
	 * Whether the committee cron should be scheduled: the plugin and committee
	 * sync are both on.
	 *
	 * @return bool
	 */
	public static function committee_cron_wanted(): bool {
		return self::is_enabled() && self::is_committee_sync_enabled();
	}

	/**
	 * Clears the committee WP-Cron event, if scheduled.
	 *
	 * @return void
	 */
	public static function clear_committee_cron() {
		if ( ! defined( 'AGEND_LOOP_SYNC_CRON_HOOK' ) ) {
			return;
		}

		wp_clear_scheduled_hook( AGEND_LOOP_SYNC_CRON_HOOK );
	}

	/**
	 * Keeps the committee cron in step with the settings: scheduled while the
	 * plugin and committee sync are both on, cleared otherwise. A site using
	 * member sync alone then never runs a committee sync.
	 *
	 * @return void
	 */
	public static function sync_committee_cron() {
		if ( self::committee_cron_wanted() ) {
			self::ensure_committee_cron();
			return;
		}

		self::clear_committee_cron();
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

		self::clear_committee_cron();

		if ( ! self::committee_cron_wanted() ) {
			return;
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
		if ( ! defined( 'AGEND_LOOP_SYNC_CRON_HOOK' ) || ! self::committee_cron_wanted() ) {
			return;
		}

		if ( false === wp_next_scheduled( AGEND_LOOP_SYNC_CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, self::get_committee_schedule(), AGEND_LOOP_SYNC_CRON_HOOK );
		}
	}

	/* ----------------------------------------------------------------------
	 * Community rule cron
	 * ------------------------------------------------------------------- */

	/**
	 * Whether the rule cron should be scheduled: the plugin and rule sync are
	 * both on.
	 *
	 * @return bool
	 */
	public static function rules_cron_wanted(): bool {
		return self::is_enabled() && self::is_rule_sync_enabled();
	}

	/**
	 * Clears the recurring rule event and any pending debounced run.
	 *
	 * @return void
	 */
	public static function clear_rules_cron() {
		if ( defined( 'AGEND_LOOP_SYNC_RULES_CRON_HOOK' ) ) {
			wp_clear_scheduled_hook( AGEND_LOOP_SYNC_RULES_CRON_HOOK );
		}

		if ( defined( 'AGEND_LOOP_SYNC_RULES_DEBOUNCE_HOOK' ) ) {
			wp_clear_scheduled_hook( AGEND_LOOP_SYNC_RULES_DEBOUNCE_HOOK );
		}
	}

	/**
	 * Keeps the rule cron in step with the settings: scheduled while the plugin
	 * and rule sync are both on, cleared otherwise.
	 *
	 * @return void
	 */
	public static function sync_rules_cron() {
		if ( self::rules_cron_wanted() ) {
			self::ensure_rules_cron();
			return;
		}

		self::clear_rules_cron();
	}

	/**
	 * Clears and re-schedules the recurring rule event using the current
	 * schedule. Hooked to the schedule option changing.
	 *
	 * @return void
	 */
	public static function reschedule_rules_cron() {
		if ( ! defined( 'AGEND_LOOP_SYNC_RULES_CRON_HOOK' ) ) {
			return;
		}

		wp_clear_scheduled_hook( AGEND_LOOP_SYNC_RULES_CRON_HOOK );

		if ( ! self::rules_cron_wanted() ) {
			return;
		}

		wp_schedule_event( time() + MINUTE_IN_SECONDS, self::get_rule_schedule(), AGEND_LOOP_SYNC_RULES_CRON_HOOK );
	}

	/**
	 * Re-creates the recurring rule event if it has gone missing.
	 *
	 * @return void
	 */
	public static function ensure_rules_cron() {
		if ( ! defined( 'AGEND_LOOP_SYNC_RULES_CRON_HOOK' ) || ! self::rules_cron_wanted() ) {
			return;
		}

		if ( false === wp_next_scheduled( AGEND_LOOP_SYNC_RULES_CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, self::get_rule_schedule(), AGEND_LOOP_SYNC_RULES_CRON_HOOK );
		}
	}

	/**
	 * Schedules one rule run a few minutes out, unless one is already waiting.
	 *
	 * Called when a member's Upbeat data changes or a member signs in, so a
	 * status change or a new Loop account is picked up without waiting for the
	 * next scheduled run. A burst of changes shares one run: nothing is
	 * scheduled while an earlier one is pending, and nothing runs in the
	 * triggering request.
	 *
	 * @return bool True when a run was scheduled by this call.
	 */
	public static function schedule_debounced_rule_run(): bool {
		if ( ! defined( 'AGEND_LOOP_SYNC_RULES_DEBOUNCE_HOOK' ) || ! self::rules_cron_wanted() || array() === self::get_enabled_rules() ) {
			return false;
		}

		if ( false !== wp_next_scheduled( AGEND_LOOP_SYNC_RULES_DEBOUNCE_HOOK ) ) {
			return false;
		}

		return (bool) wp_schedule_single_event( time() + self::RULE_DEBOUNCE_SECONDS, AGEND_LOOP_SYNC_RULES_DEBOUNCE_HOOK );
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
	 * @return bool Whether community rule sync is enabled.
	 */
	public static function is_rule_sync_enabled(): bool {
		return '1' === (string) get_option( self::OPT_RULE_SYNC, '0' );
	}

	/**
	 * @return string The configured WP-Cron schedule for the rule sync.
	 */
	public static function get_rule_schedule(): string {
		$value = (string) get_option( self::OPT_RULE_SCHEDULE, 'hourly' );
		return in_array( $value, self::SCHEDULES, true ) ? $value : 'hourly';
	}

	/**
	 * The member statuses that keep access to rule channels. Cleaned on every
	 * read, and always including Financial.
	 *
	 * @return string[]
	 */
	public static function get_access_statuses(): array {
		$stored = get_option( self::OPT_ACCESS_STATUSES, self::DEFAULT_ACCESS_STATUSES );

		return self::sanitize_access_statuses( is_array( $stored ) ? $stored : self::DEFAULT_ACCESS_STATUSES );
	}

	/**
	 * Every saved rule, cleaned on every read so a damaged option can never put a
	 * bad value into a gateway request.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_rules(): array {
		return self::sanitize_rules( get_option( self::OPT_RULES, array() ) );
	}

	/**
	 * @return array<int, array<string, mixed>> The enabled rules.
	 */
	public static function get_enabled_rules(): array {
		return array_values(
			array_filter(
				self::get_rules(),
				static function ( $rule ) {
					return ! empty( $rule['enabled'] );
				}
			)
		);
	}

	/**
	 * @param string $slug Rule slug.
	 * @return array<string, mixed>|null The rule, or null when none has that slug.
	 */
	public static function get_rule( string $slug ): ?array {
		foreach ( self::get_rules() as $rule ) {
			if ( $rule['slug'] === $slug ) {
				return $rule;
			}
		}

		return null;
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
	 * Community rule changes
	 * ------------------------------------------------------------------- */

	/**
	 * Saves one rule: adds it, or updates the saved rule named by $original_slug.
	 *
	 * The slug is the gateway key of the rule's channel, so it is fixed once a
	 * rule is saved: an update keeps $original_slug whatever slug is posted. A
	 * new rule with no slug takes one from its name. Slugs and names are unique
	 * (names ignoring case).
	 *
	 * @param mixed  $raw           Raw rule fields.
	 * @param string $original_slug Slug of the rule being edited, or '' for a new rule.
	 * @return array<string, mixed>|WP_Error The saved rule, or an error whose code says why it was refused: invalid_name, invalid_slug, slug_taken, name_taken, too_many_rules or rule_not_found.
	 */
	public static function save_rule( $raw, string $original_slug = '' ) {
		$rule     = self::sanitize_rule( $raw );
		$original = self::sanitize_rule_slug( $original_slug );
		$rules    = self::get_rules();
		$index    = null;

		if ( '' !== $original ) {
			foreach ( $rules as $i => $saved ) {
				if ( $saved['slug'] === $original ) {
					$index = $i;
					break;
				}
			}

			if ( null === $index ) {
				return new WP_Error( 'rule_not_found', 'That rule no longer exists.' );
			}

			$rule['slug'] = $original;
		} elseif ( '' === $rule['slug'] ) {
			$rule['slug'] = self::sanitize_rule_slug( $rule['name'] );
		}

		if ( '' === $rule['name'] ) {
			return new WP_Error( 'invalid_name', 'A rule needs a name.' );
		}

		if ( '' === $rule['slug'] ) {
			return new WP_Error( 'invalid_slug', 'A rule needs a slug of letters, numbers and hyphens.' );
		}

		foreach ( $rules as $i => $saved ) {
			if ( $i === $index ) {
				continue;
			}

			if ( $saved['slug'] === $rule['slug'] ) {
				return new WP_Error( 'slug_taken', 'Another rule already uses that slug.' );
			}

			if ( 0 === strcasecmp( $saved['name'], $rule['name'] ) ) {
				return new WP_Error( 'name_taken', 'Another rule already uses that name.' );
			}
		}

		if ( null === $index ) {
			if ( count( $rules ) >= self::RULES_MAX ) {
				return new WP_Error( 'too_many_rules', 'The site already has the most rules allowed.' );
			}

			$rules[] = $rule;
		} else {
			$rules[ $index ] = $rule;
		}

		update_option( self::OPT_RULES, array_values( $rules ), false );

		return $rule;
	}

	/**
	 * Deletes a rule. Its Loop channel is archived by the next complete rule run.
	 *
	 * @param string $slug Rule slug.
	 * @return bool True when a rule was deleted.
	 */
	public static function delete_rule( string $slug ): bool {
		$slug  = self::sanitize_rule_slug( $slug );
		$rules = self::get_rules();
		$kept  = array();

		foreach ( $rules as $rule ) {
			if ( $rule['slug'] !== $slug ) {
				$kept[] = $rule;
			}
		}

		if ( count( $kept ) === count( $rules ) ) {
			return false;
		}

		update_option( self::OPT_RULES, $kept, false );

		return true;
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
	 * Validates the rule sync schedule against the allowed set.
	 *
	 * @param mixed $value Raw value.
	 * @return string A valid schedule.
	 */
	public static function sanitize_rule_schedule( $value ): string {
		$value = sanitize_text_field( (string) $value );
		return in_array( $value, self::SCHEDULES, true ) ? $value : 'hourly';
	}

	/**
	 * Reduces the access statuses to the known ones, in their usual order, and
	 * always includes Financial so a financial member can never lose access to a
	 * rule channel through this setting.
	 *
	 * @param mixed $value Raw value: a list of status names.
	 * @return string[] The sanitized statuses.
	 */
	public static function sanitize_access_statuses( $value ): array {
		$posted = array( strtolower( self::ALWAYS_ALLOWED_STATUS ) );
		foreach ( is_array( $value ) ? $value : array() as $status ) {
			$posted[] = strtolower( trim( sanitize_text_field( (string) $status ) ) );
		}

		$out = array();
		foreach ( self::ACCESS_STATUS_CHOICES as $choice ) {
			if ( in_array( strtolower( $choice ), $posted, true ) ) {
				$out[] = $choice;
			}
		}

		return $out;
	}

	/**
	 * Makes a rule slug: lower case letters, numbers and single hyphens, at most
	 * RULE_SLUG_MAX long. '' when nothing usable is left.
	 *
	 * @param mixed $value Raw value.
	 * @return string The slug.
	 */
	public static function sanitize_rule_slug( $value ): string {
		$slug = strtolower( trim( (string) $value ) );
		$slug = (string) preg_replace( '/[^a-z0-9]+/', '-', $slug );
		$slug = trim( substr( $slug, 0, self::RULE_SLUG_MAX ), '-' );

		return $slug;
	}

	/**
	 * Cleans one rule to the shape the runner reads. Pure: does not check
	 * uniqueness (see save_rule() and sanitize_rules()).
	 *
	 * @param mixed $raw Raw rule fields.
	 * @return array<string, mixed> slug, name, enabled, match, membership_types, membership_levels, event_ids, apply_access_statuses, always_include, member_can_leave, allow_manual_members.
	 */
	public static function sanitize_rule( $raw ): array {
		$raw = is_array( $raw ) ? $raw : array();

		$flag = static function ( string $key, bool $default ) use ( $raw ): bool {
			return array_key_exists( $key, $raw ) ? '1' === self::sanitize_bool( $raw[ $key ] ) : $default;
		};

		$match = strtolower( trim( sanitize_text_field( (string) ( $raw['match'] ?? 'all' ) ) ) );

		return array(
			'slug'                  => self::sanitize_rule_slug( $raw['slug'] ?? '' ),
			'name'                  => trim( mb_substr( trim( sanitize_text_field( (string) ( $raw['name'] ?? '' ) ) ), 0, self::RULE_NAME_MAX ) ),
			'enabled'               => $flag( 'enabled', false ),
			'match'                 => in_array( $match, self::RULE_MATCH_MODES, true ) ? $match : 'all',
			'membership_types'      => self::sanitize_unique_list( $raw['membership_types'] ?? array() ),
			'membership_levels'     => self::sanitize_unique_list( $raw['membership_levels'] ?? array() ),
			'event_ids'             => self::sanitize_unique_list( $raw['event_ids'] ?? array() ),
			'apply_access_statuses' => $flag( 'apply_access_statuses', true ),
			'always_include'        => self::sanitize_always_include( $raw['always_include'] ?? array() ),
			'member_can_leave'      => $flag( 'member_can_leave', true ),
			'allow_manual_members'  => $flag( 'allow_manual_members', true ),
		);
	}

	/**
	 * Cleans the saved rules: valid rules only, with unique slugs and unique
	 * names (names ignoring case). The first of two clashing rules is kept.
	 *
	 * @param mixed $value Raw option value.
	 * @return array<int, array<string, mixed>> The sanitized rules.
	 */
	public static function sanitize_rules( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$rules = array();
		$slugs = array();
		$names = array();

		foreach ( $value as $raw ) {
			$rule = self::sanitize_rule( $raw );
			$name = strtolower( $rule['name'] );

			if ( '' === $rule['slug'] || '' === $rule['name'] || isset( $slugs[ $rule['slug'] ] ) || isset( $names[ $name ] ) ) {
				continue;
			}

			$slugs[ $rule['slug'] ] = true;
			$names[ $name ]         = true;
			$rules[]                = $rule;

			if ( count( $rules ) >= self::RULES_MAX ) {
				break;
			}
		}

		return $rules;
	}

	/**
	 * Like sanitize_string_list(), but entries that differ only in case count as
	 * one (the first spelling is kept), since rules match ignoring case.
	 *
	 * @param mixed $value Raw textarea string or array.
	 * @return string[] The sanitized list.
	 */
	public static function sanitize_unique_list( $value ): array {
		$out  = array();
		$seen = array();
		foreach ( self::sanitize_string_list( $value ) as $item ) {
			$key = strtolower( $item );
			if ( ! isset( $seen[ $key ] ) ) {
				$seen[ $key ] = true;
				$out[]        = $item;
			}
		}

		return $out;
	}

	/**
	 * Cleans a rule's always-include list: members who are in the channel
	 * whatever the criteria say. Accepts rows of { membership_number, role }, or
	 * a textarea with one `membership number = role` per line (a bare number
	 * means member). Numbers must be plain digits the gateway accepts; roles
	 * default to member. A number listed twice keeps its highest role.
	 *
	 * @param mixed $value Raw rows or textarea string.
	 * @return array<int, array{membership_number: string, role: string}> The sanitized list.
	 */
	public static function sanitize_always_include( $value ): array {
		$rows = array();
		if ( is_array( $value ) ) {
			foreach ( $value as $row ) {
				if ( is_array( $row ) ) {
					$rows[] = array( (string) ( $row['membership_number'] ?? '' ), (string) ( $row['role'] ?? '' ) );
				}
			}
		} else {
			foreach ( preg_split( '/\r\n|\r|\n/', (string) $value ) as $line ) {
				$parts  = explode( '=', $line, 2 );
				$rows[] = array( $parts[0], $parts[1] ?? '' );
			}
		}

		$by_id = array();
		foreach ( $rows as $row ) {
			$number = trim( sanitize_text_field( $row[0] ) );
			$role   = strtolower( trim( sanitize_text_field( $row[1] ) ) );
			$id     = Agend_Loop_Sync_Contact_Resolver::to_external_id( $number );
			if ( null === $id ) {
				continue;
			}

			$role = in_array( $role, self::CHANNEL_ROLES, true ) ? $role : 'member';

			if ( ! isset( $by_id[ $id ] ) || Agend_Loop_Sync_Role_Mapper::rank( $role ) > Agend_Loop_Sync_Role_Mapper::rank( $by_id[ $id ]['role'] ) ) {
				$by_id[ $id ] = array(
					'membership_number' => $number,
					'role'              => $role,
				);
			}
		}

		return array_slice( array_values( $by_id ), 0, self::ALWAYS_INCLUDE_MAX );
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
