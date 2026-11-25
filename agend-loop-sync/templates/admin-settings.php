<?php
/**
 * Settings page template.
 *
 * Rendered by Agend_Loop_Sync_Admin::render_page(). Reads settings via the
 * Agend_Loop_Sync_Settings static getters.
 *
 * @package Agend_Loop_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$als_role_map      = Agend_Loop_Sync_Settings::get_role_map();
$als_role_map_text = '';
foreach ( $als_role_map as $als_label => $als_role ) {
	$als_role_map_text .= $als_label . ' = ' . $als_role . "\n";
}

$als_groups_text    = implode( "\n", Agend_Loop_Sync_Settings::get_filter_groups() );
$als_allowlist_text = implode( "\n", Agend_Loop_Sync_Settings::get_filter_allowlist() );
$als_mode           = Agend_Loop_Sync_Settings::get_filter_mode();
$als_schedule       = Agend_Loop_Sync_Settings::get_committee_schedule();
$als_last_sync      = (int) get_option( Agend_Loop_Sync_Settings::OPT_LAST_SYNC, 0 );
$als_committees_on  = Agend_Loop_Sync_Settings::is_committee_sync_enabled();

$als_rules_on        = Agend_Loop_Sync_Settings::is_rule_sync_enabled();
$als_rule_schedule   = Agend_Loop_Sync_Settings::get_rule_schedule();
$als_access_statuses = Agend_Loop_Sync_Settings::get_access_statuses();
$als_rules           = Agend_Loop_Sync_Settings::get_rules();
$als_last_rule_sync  = (int) get_option( Agend_Loop_Sync_Settings::OPT_LAST_RULE_SYNC, 0 );
$als_rule_summary    = get_option( Agend_Loop_Sync_Settings::OPT_LAST_RULE_SUMMARY, array() );
$als_rule_summary    = is_array( $als_rule_summary ) ? $als_rule_summary : array();

$als_last_summary = get_option( Agend_Loop_Sync_Settings::OPT_LAST_SUMMARY, array() );
$als_last_summary = is_array( $als_last_summary ) ? $als_last_summary : array();

// What the gateway can answer for a member handed over, in words.
$als_outcome_labels = array(
	'adopted'           => __( 'adopted', 'agend-loop-sync' ),
	'already_managed'   => __( 'already managed by the sync', 'agend-loop-sync' ),
	'held'              => __( 'held in Loop, not adopted', 'agend-loop-sync' ),
	'inserted'          => __( 'added as a new member', 'agend-loop-sync' ),
	'managed_elsewhere' => __( 'managed by another source, not adopted', 'agend-loop-sync' ),
	'not_in_roster'     => __( 'not on the committee roster', 'agend-loop-sync' ),
	'unresolved'        => __( 'no Loop user yet', 'agend-loop-sync' ),
	'unknown'           => __( 'unknown outcome', 'agend-loop-sync' ),
);

// Why Agend did not create a member's Loop account, in words.
$als_provision_reasons = array(
	'connection_not_usable'    => __( 'single sign-on connection not ready', 'agend-loop-sync' ),
	'provisioning_not_allowed' => __( 'connection does not allow account creation', 'agend-loop-sync' ),
	'restricted_role'          => __( 'existing login with a staff role, left for the member to confirm', 'agend-loop-sync' ),
	'consent_required'         => __( 'existing login that needs the member\'s confirmation', 'agend-loop-sync' ),
	'rate_limited'             => __( 'hourly limit reached, will retry', 'agend-loop-sync' ),
	'conflict'                 => __( 'membership number already used by another login', 'agend-loop-sync' ),
	'provisioning_failed'      => __( 'unexpected fault, will retry', 'agend-loop-sync' ),
	'unknown'                  => __( 'other reason', 'agend-loop-sync' ),
);

// A sync hold in words: what it does, since when and why. Plain text, so escape
// it where it is printed.
$als_describe_hold = static function ( array $hold ): string {
	$kind     = (string) ( $hold['kind'] ?? '' );
	$role_cap = (string) ( $hold['role_cap'] ?? '' );

	if ( 'removal' === $kind ) {
		$parts = array( __( 'Removed from the channel.', 'agend-loop-sync' ) );
	} elseif ( 'role_cap' === $kind && '' !== $role_cap ) {
		/* translators: %s: the highest channel role the sync may give the member, for example "moderator". */
		$parts = array( sprintf( __( 'Role capped at %s.', 'agend-loop-sync' ), $role_cap ) );
	} elseif ( 'role_cap' === $kind ) {
		$parts = array( __( 'Role capped.', 'agend-loop-sync' ) );
	} elseif ( 'member_left' === $kind ) {
		$parts = array( __( 'Left the channel in Loop. The sync does not add them back unless they rejoin there.', 'agend-loop-sync' ) );
	} else {
		$parts = array( __( 'Held.', 'agend-loop-sync' ) );
	}

	$since = (string) ( $hold['held_since'] ?? '' );
	if ( '' !== $since ) {
		$timestamp = strtotime( $since );
		/* translators: %s: date and time the hold was placed. */
		$parts[] = sprintf( __( 'Since %s.', 'agend-loop-sync' ), false === $timestamp ? $since : wp_date( 'Y-m-d H:i', $timestamp ) );
	}

	$reason = (string) ( $hold['reason'] ?? '' );
	if ( '' !== $reason ) {
		/* translators: %s: the reason the Loop administrator gave. */
		$parts[] = sprintf( __( 'Reason: %s', 'agend-loop-sync' ), $reason );
	}

	return implode( ' ', $parts );
};

// Staleness: warn when the last committee sync is older than ~2x the schedule
// interval, which usually means WP-Cron is not firing.
$als_schedule_seconds = array(
	'hourly'     => HOUR_IN_SECONDS,
	'twicedaily' => 12 * HOUR_IN_SECONDS,
	'daily'      => DAY_IN_SECONDS,
);
$als_expected = $als_schedule_seconds[ $als_schedule ] ?? DAY_IN_SECONDS;
$als_is_stale = $als_last_sync > 0 && ( time() - $als_last_sync ) > ( 2 * $als_expected );
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Agend Loop Sync', 'agend-loop-sync' ); ?></h1>

	<?php
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( isset( $_GET['committee_sync'] ) && 'done' === sanitize_text_field( wp_unslash( $_GET['committee_sync'] ) ) ) :
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$als_done_synced = isset( $_GET['synced'] ) ? (int) $_GET['synced'] : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$als_done_scanned = isset( $_GET['members_scanned'] ) ? (int) $_GET['members_scanned'] : 0;
		?>
		<div class="notice notice-success is-dismissible">
			<p>
			<?php
			printf(
				/* translators: 1: committees synced, 2: members scanned. */
				esc_html__( 'Committee sync run. Committees synced: %1$d. Members scanned: %2$d.', 'agend-loop-sync' ),
				(int) $als_done_synced,
				(int) $als_done_scanned
			);
			?>
			</p>
		</div>
	<?php endif; ?>

	<?php
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( isset( $_GET['committee_sync'] ) && 'off' === sanitize_text_field( wp_unslash( $_GET['committee_sync'] ) ) ) :
		?>
		<div class="notice notice-warning is-dismissible">
			<p><?php esc_html_e( 'Committee sync is off, so that committee action was not run. Turn committee sync on to use it.', 'agend-loop-sync' ); ?></p>
		</div>
	<?php endif; ?>

	<?php
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$als_rules_notice = isset( $_GET['rules'] ) ? sanitize_text_field( wp_unslash( $_GET['rules'] ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$als_rules_code = isset( $_GET['rules_code'] ) ? sanitize_text_field( wp_unslash( $_GET['rules_code'] ) ) : '';
	$als_rule_errors = array(
		'invalid_name'   => __( 'The rule was not saved: it needs a name.', 'agend-loop-sync' ),
		'invalid_slug'   => __( 'The rule was not saved: it needs a slug of letters, numbers and hyphens.', 'agend-loop-sync' ),
		'slug_taken'     => __( 'The rule was not saved: another rule already uses that slug.', 'agend-loop-sync' ),
		'name_taken'     => __( 'The rule was not saved: another rule already uses that name.', 'agend-loop-sync' ),
		'too_many_rules' => __( 'The rule was not saved: the site already has the most rules allowed.', 'agend-loop-sync' ),
		'rule_not_found' => __( 'The rule was not saved: it no longer exists.', 'agend-loop-sync' ),
	);
	if ( 'ran' === $als_rules_notice ) :
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$als_ran_rules = isset( $_GET['rules_synced'] ) ? (int) $_GET['rules_synced'] : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$als_ran_scanned = isset( $_GET['members_scanned'] ) ? (int) $_GET['members_scanned'] : 0;
		?>
		<div class="notice notice-success is-dismissible">
			<p>
			<?php
			printf(
				/* translators: 1: rules synced, 2: members scanned. */
				esc_html__( 'Community rules run. Rules synced: %1$d. Members scanned: %2$d.', 'agend-loop-sync' ),
				(int) $als_ran_rules,
				(int) $als_ran_scanned
			);
			?>
			</p>
		</div>
	<?php elseif ( 'not_run' === $als_rules_notice ) : ?>
		<?php
		$als_not_run_reasons = array(
			'disabled'    => __( 'Loop Sync is turned off (Enable plugin, above), so no rules ran.', 'agend-loop-sync' ),
			'unavailable' => __( 'No rules ran: the membership plugin or agend-apps-core is not available.', 'agend-loop-sync' ),
			'locked'      => __( 'No rules ran: another community rule run is already in progress. Try again shortly.', 'agend-loop-sync' ),
		);
		?>
		<div class="notice notice-warning is-dismissible"><p><?php echo esc_html( $als_not_run_reasons[ $als_rules_code ] ?? __( 'No rules ran.', 'agend-loop-sync' ) ); ?></p></div>
	<?php elseif ( 'saved' === $als_rules_notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Rule saved. It takes effect on the next rule run.', 'agend-loop-sync' ); ?></p></div>
	<?php elseif ( 'deleted' === $als_rules_notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Rule deleted. Its Loop channel is archived by the next complete rule run.', 'agend-loop-sync' ); ?></p></div>
	<?php elseif ( 'off' === $als_rules_notice ) : ?>
		<div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'Community rules are off, so that action was not run. Turn community rules on to use them.', 'agend-loop-sync' ); ?></p></div>
	<?php elseif ( 'error' === $als_rules_notice ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $als_rule_errors[ $als_rules_code ] ?? __( 'The rule was not saved.', 'agend-loop-sync' ) ); ?></p></div>
	<?php endif; ?>

	<form method="post" action="options.php">
		<?php settings_fields( Agend_Loop_Sync_Settings::GROUP ); ?>

		<table class="form-table" role="presentation">
		<tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Enable plugin', 'agend-loop-sync' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( Agend_Loop_Sync_Settings::OPT_ENABLED ); ?>" value="1" <?php checked( Agend_Loop_Sync_Settings::is_enabled() ); ?> />
						<?php esc_html_e( 'Master switch. When off, nothing is synced.', 'agend-loop-sync' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Dry run', 'agend-loop-sync' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( Agend_Loop_Sync_Settings::OPT_DRY_RUN ); ?>" value="1" <?php checked( Agend_Loop_Sync_Settings::is_dry_run() ); ?> />
						<?php esc_html_e( 'Log what would be sent to Loop without making any gateway calls.', 'agend-loop-sync' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'User sync', 'agend-loop-sync' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( Agend_Loop_Sync_Settings::OPT_USER_SYNC ); ?>" value="1" <?php checked( Agend_Loop_Sync_Settings::is_user_sync_enabled() ); ?> />
						<?php esc_html_e( 'Sync a user to Loop on login, profile update, and role change.', 'agend-loop-sync' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Create Loop accounts', 'agend-loop-sync' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( Agend_Loop_Sync_Settings::OPT_PROVISION ); ?>" value="1" <?php checked( Agend_Loop_Sync_Settings::is_provision_enabled() ); ?> />
						<?php esc_html_e( 'Create a member\'s Loop account before their first sign-in, so they are in their communities straight away.', 'agend-loop-sync' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Applies to member sync and the backfill. Agend creates the account on this site\'s single sign-on connection, as a first sign-in would, and sends the member no email. Needs the loop.users.provision scope on the site key and a connection Agend has set up for it: ask Agend before turning this on.', 'agend-loop-sync' ); ?></p>
					<?php if ( Agend_Loop_Sync_Settings::is_provision_enabled() ) : ?>
						<?php $als_provision_blocker = Agend_Loop_Sync_Settings::provision_blocker(); ?>
						<?php if ( 'scope_missing' === $als_provision_blocker ) : ?>
							<p><strong><?php esc_html_e( 'The site key does not hold loop.users.provision, so no accounts are being created. Member sync carries on without it. Ask Agend to grant the scope.', 'agend-loop-sync' ); ?></strong></p>
						<?php elseif ( 'scope_refused' === $als_provision_blocker ) : ?>
							<p><strong><?php esc_html_e( 'Agend refused to create accounts in the last hour because the site key does not hold loop.users.provision, so none are being created. Member sync carries on without it. Ask Agend to grant the scope.', 'agend-loop-sync' ); ?></strong></p>
						<?php elseif ( 'core_too_old' === $als_provision_blocker ) : ?>
							<p><strong>
								<?php
								/* translators: %s: agend-apps-core version number. */
								printf( esc_html__( 'Agend Apps Core %s or later is needed to create accounts, so none are being created. Update Agend Apps Core.', 'agend-loop-sync' ), esc_html( Agend_Loop_Sync_Settings::PROVISION_MIN_CORE_VERSION ) );
								?>
							</strong></p>
						<?php endif; ?>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Committee sync', 'agend-loop-sync' ); ?></th>
				<td>
					<label>
						<input type="checkbox" id="als-committee-sync" name="<?php echo esc_attr( Agend_Loop_Sync_Settings::OPT_COMMITTEE_SYNC ); ?>" value="1" <?php checked( $als_committees_on ); ?> />
						<?php esc_html_e( 'Create a private Loop channel for each Upbeat committee and keep its members in step with the committee roster, on a schedule and on demand.', 'agend-loop-sync' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Optional. Leave off when channel membership is managed in Loop. Member sync works without it.', 'agend-loop-sync' ); ?></p>
				</td>
			</tr>
		</tbody>
		<tbody id="als-committee-settings"<?php echo $als_committees_on ? '' : ' hidden'; ?>>
			<tr>
				<th scope="row">
					<label for="als-schedule"><?php esc_html_e( 'Committee sync schedule', 'agend-loop-sync' ); ?></label>
				</th>
				<td>
					<select id="als-schedule" name="<?php echo esc_attr( Agend_Loop_Sync_Settings::OPT_SCHEDULE ); ?>">
						<?php foreach ( Agend_Loop_Sync_Settings::SCHEDULES as $als_sched_option ) : ?>
							<option value="<?php echo esc_attr( $als_sched_option ); ?>" <?php selected( $als_schedule, $als_sched_option ); ?>>
								<?php echo esc_html( $als_sched_option ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Changing this reschedules the cron immediately.', 'agend-loop-sync' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="als-role-map"><?php esc_html_e( 'Committee role mapping', 'agend-loop-sync' ); ?></label>
				</th>
				<td>
					<textarea id="als-role-map" name="<?php echo esc_attr( Agend_Loop_Sync_Settings::OPT_ROLE_MAP ); ?>" rows="8" cols="50" class="large-text code"><?php echo esc_textarea( $als_role_map_text ); ?></textarea>
					<p class="description">
						<?php esc_html_e( 'One mapping per line, in the form "Committee Role = channel role". Channel role must be owner, moderator, or member. Unmapped roles default to member. Use the member scan below to see the exact role strings.', 'agend-loop-sync' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="als-filter-mode"><?php esc_html_e( 'Committee filter', 'agend-loop-sync' ); ?></label>
				</th>
				<td>
					<select id="als-filter-mode" name="<?php echo esc_attr( Agend_Loop_Sync_Settings::OPT_FILTER_MODE ); ?>">
						<option value="all" <?php selected( $als_mode, 'all' ); ?>><?php esc_html_e( 'All web-visible committees', 'agend-loop-sync' ); ?></option>
						<option value="groups" <?php selected( $als_mode, 'groups' ); ?>><?php esc_html_e( 'Committees in selected groups', 'agend-loop-sync' ); ?></option>
						<option value="allowlist" <?php selected( $als_mode, 'allowlist' ); ?>><?php esc_html_e( 'Only committees on the allow-list', 'agend-loop-sync' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="als-groups"><?php esc_html_e( 'Committee groups', 'agend-loop-sync' ); ?></label>
				</th>
				<td>
					<textarea id="als-groups" name="<?php echo esc_attr( Agend_Loop_Sync_Settings::OPT_FILTER_GROUPS ); ?>" rows="4" cols="50" class="large-text code"><?php echo esc_textarea( $als_groups_text ); ?></textarea>
					<p class="description"><?php esc_html_e( 'One group name per line. Used when the filter is set to "selected groups".', 'agend-loop-sync' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="als-allowlist"><?php esc_html_e( 'Committee allow-list', 'agend-loop-sync' ); ?></label>
				</th>
				<td>
					<textarea id="als-allowlist" name="<?php echo esc_attr( Agend_Loop_Sync_Settings::OPT_FILTER_ALLOWLIST ); ?>" rows="4" cols="50" class="large-text code"><?php echo esc_textarea( $als_allowlist_text ); ?></textarea>
					<p class="description"><?php esc_html_e( 'One committee name per line. Used when the filter is set to "allow-list".', 'agend-loop-sync' ); ?></p>
				</td>
			</tr>
		</tbody>
		<tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Community rules', 'agend-loop-sync' ); ?></th>
				<td>
					<label>
						<input type="checkbox" id="als-rule-sync" name="<?php echo esc_attr( Agend_Loop_Sync_Settings::OPT_RULE_SYNC ); ?>" value="1" <?php checked( $als_rules_on ); ?> />
						<?php esc_html_e( 'Keep criteria-based private Loop channels in step with membership: members who meet a rule are added, members who stop meeting it are removed.', 'agend-loop-sync' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Optional. Channels you create and fill by hand in Loop are not affected.', 'agend-loop-sync' ); ?></p>
				</td>
			</tr>
		</tbody>
		<tbody id="als-rule-settings"<?php echo $als_rules_on ? '' : ' hidden'; ?>>
			<tr>
				<th scope="row">
					<label for="als-rule-schedule"><?php esc_html_e( 'Community rules schedule', 'agend-loop-sync' ); ?></label>
				</th>
				<td>
					<select id="als-rule-schedule" name="<?php echo esc_attr( Agend_Loop_Sync_Settings::OPT_RULE_SCHEDULE ); ?>">
						<?php foreach ( Agend_Loop_Sync_Settings::SCHEDULES as $als_sched_option ) : ?>
							<option value="<?php echo esc_attr( $als_sched_option ); ?>" <?php selected( $als_rule_schedule, $als_sched_option ); ?>>
								<?php echo esc_html( $als_sched_option ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'A change in a member\'s Upbeat record, or a member signing in, also schedules one extra run about five minutes later.', 'agend-loop-sync' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Statuses that keep access', 'agend-loop-sync' ); ?></th>
				<td>
					<fieldset>
						<legend class="screen-reader-text"><?php esc_html_e( 'Statuses that keep access', 'agend-loop-sync' ); ?></legend>
						<?php foreach ( Agend_Loop_Sync_Settings::ACCESS_STATUS_CHOICES as $als_status ) : ?>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( Agend_Loop_Sync_Settings::OPT_ACCESS_STATUSES ); ?>[]" value="<?php echo esc_attr( $als_status ); ?>" <?php checked( in_array( $als_status, $als_access_statuses, true ) ); ?> <?php disabled( Agend_Loop_Sync_Settings::ALWAYS_ALLOWED_STATUS === $als_status ); ?> />
								<?php echo esc_html( $als_status ); ?>
							</label><br />
						<?php endforeach; ?>
					</fieldset>
					<p class="description"><?php esc_html_e( 'A member whose Upbeat status is ticked keeps access to every rule channel that applies this lapse rule. Any other status removes them, and they return automatically when their status is ticked again. Financial always keeps access. The default keeps Financial, Renewal Due and Overdue; untick Overdue to cut access earlier.', 'agend-loop-sync' ); ?></p>
				</td>
			</tr>
		</tbody>
		</table>
		<script>
			( function () {
				var toggle = document.getElementById( 'als-rule-sync' );
				var rows = document.getElementById( 'als-rule-settings' );
				if ( toggle && rows ) {
					toggle.addEventListener( 'change', function () {
						rows.hidden = ! toggle.checked;
					} );
				}
			}() );
		</script>
		<script>
			( function () {
				var toggle = document.getElementById( 'als-committee-sync' );
				var rows = document.getElementById( 'als-committee-settings' );
				if ( toggle && rows ) {
					toggle.addEventListener( 'change', function () {
						rows.hidden = ! toggle.checked;
					} );
				}
			}() );
		</script>

		<?php submit_button(); ?>
	</form>

	<hr />

	<h2><?php esc_html_e( 'Member sync', 'agend-loop-sync' ); ?></h2>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="agend_loop_sync_backfill_users" />
		<?php wp_nonce_field( 'agend_loop_sync_backfill_users' ); ?>
		<?php submit_button( __( 'Backfill all users', 'agend-loop-sync' ), 'secondary', 'submit', false ); ?>
	</form>
	<p class="description">
		<?php esc_html_e( 'Backfill refreshes every local member with a membership number in Loop in one pass (batches of 100), updating display names and role-based channels. With "Create Loop accounts" on, it also creates the account of each member who has not signed in to Loop yet; members it cannot create yet, for example over Agend\'s limit of 2000 new accounts an hour, are tried again an hour later. With it off, members who have not signed in to Loop are counted as failed. Honours dry run.', 'agend-loop-sync' ); ?>
	</p>

	<?php
	$als_backfill = get_transient( 'agend_loop_sync_user_backfill' );
	if ( is_array( $als_backfill ) ) :
		?>
		<div class="notice notice-info inline">
			<p>
				<?php
				printf(
					/* translators: 1: total members, 2: sent, 3: succeeded, 4: failed, 5: batches. */
					esc_html__( 'User backfill: %1$d members, %2$d sent, %3$d succeeded, %4$d failed, in %5$d batch(es).', 'agend-loop-sync' ),
					(int) ( $als_backfill['total'] ?? 0 ),
					(int) ( $als_backfill['sent'] ?? 0 ),
					(int) ( $als_backfill['succeeded'] ?? 0 ),
					(int) ( $als_backfill['failed'] ?? 0 ),
					(int) ( $als_backfill['batches'] ?? 0 )
				);
				?>
				<?php if ( ! empty( $als_backfill['dry_run'] ) ) : ?>
					<em><?php esc_html_e( '(dry run: nothing was sent)', 'agend-loop-sync' ); ?></em>
				<?php endif; ?>
			</p>
			<?php if ( is_array( $als_backfill['provisioning'] ?? null ) && empty( $als_backfill['dry_run'] ) ) : ?>
				<?php $als_prov = $als_backfill['provisioning']; ?>
				<p>
					<?php
					printf(
						/* translators: 1: accounts created, 2: existing logins linked, 3: already in Loop, 4: not created. */
						esc_html__( 'Loop accounts: %1$d created, %2$d existing logins linked, %3$d already in Loop, %4$d not created.', 'agend-loop-sync' ),
						(int) ( $als_prov['provisioned'] ?? 0 ),
						(int) ( $als_prov['linked'] ?? 0 ),
						(int) ( $als_prov['already_linked'] ?? 0 ),
						(int) ( $als_prov['skipped'] ?? 0 )
					);
					?>
				</p>
				<?php if ( ! empty( $als_prov['reasons'] ) && is_array( $als_prov['reasons'] ) ) : ?>
					<ul class="ul-disc">
						<?php foreach ( $als_prov['reasons'] as $als_reason => $als_count ) : ?>
							<li><?php echo esc_html( sprintf( '%s: %d', $als_provision_reasons[ $als_reason ] ?? $als_reason, (int) $als_count ) ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<?php if ( (int) ( $als_prov['deferred'] ?? 0 ) > 0 ) : ?>
					<p>
						<?php
						/* translators: %d: members not sent this run. */
						printf( esc_html__( '%d members were not sent because Agend\'s hourly limit was reached; they are queued for the next retry.', 'agend-loop-sync' ), (int) $als_prov['deferred'] );
						?>
					</p>
				<?php endif; ?>
			<?php endif; ?>
			<?php foreach ( (array) ( $als_backfill['errors'] ?? array() ) as $als_backfill_error ) : ?>
				<p><strong><?php echo esc_html( (string) $als_backfill_error ); ?></strong></p>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php
	$als_retry_queue = Agend_Loop_Sync_Provision_Retry::queued();
	if ( array() !== $als_retry_queue ) :
		$als_retry_next = Agend_Loop_Sync_Provision_Retry::next_run();
		?>
		<p>
			<?php
			if ( $als_retry_next > 0 ) {
				printf(
					/* translators: 1: members waiting, 2: date and time of the next retry. */
					esc_html__( '%1$d members are waiting for their Loop account to be created. Next try: %2$s.', 'agend-loop-sync' ),
					count( $als_retry_queue ),
					esc_html( wp_date( 'Y-m-d H:i', $als_retry_next ) )
				);
			} else {
				printf(
					/* translators: %d: members waiting. */
					esc_html__( '%d members are waiting for their Loop account to be created.', 'agend-loop-sync' ),
					count( $als_retry_queue )
				);
			}
			?>
		</p>
	<?php endif; ?>

	<hr />

	<?php if ( $als_committees_on ) : ?>
	<h2><?php esc_html_e( 'Manual committee sync', 'agend-loop-sync' ); ?></h2>
	<p><?php esc_html_e( 'Committees are synced per member: each local user with a membership number is asked for their committee activities, which are aggregated into committee rosters. With dry run enabled, payloads are only logged.', 'agend-loop-sync' ); ?></p>
	<?php if ( $als_last_sync > 0 ) : ?>
		<p>
			<?php
			/* translators: %s: formatted date and time of the last sync run. */
			printf( esc_html__( 'Last run: %s', 'agend-loop-sync' ), esc_html( wp_date( 'Y-m-d H:i', $als_last_sync ) ) );
			?>
		</p>
	<?php endif; ?>

	<?php if ( $als_is_stale ) : ?>
		<div class="notice notice-warning inline">
			<p><?php esc_html_e( 'The last committee sync is older than expected for the configured schedule. Check that WP-Cron is running (or trigger it from the server), or run a manual sync below.', 'agend-loop-sync' ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $als_last_summary ) ) : ?>
		<p>
			<?php if ( ! empty( $als_last_summary['member_counts_reported'] ) ) : ?>
				<?php
				printf(
					/* translators: 1: committees synced, 2: members applied, 3: members skipped (no Loop user), 4: memberships removed, 5: committees archived. */
					esc_html__( 'Last run: %1$d committees synced, %2$d members applied, %3$d skipped (no Loop user yet), %4$d memberships removed, %5$d committees archived.', 'agend-loop-sync' ),
					(int) ( $als_last_summary['synced'] ?? 0 ),
					(int) ( $als_last_summary['applied_members'] ?? 0 ),
					(int) ( $als_last_summary['skipped_members'] ?? 0 ),
					(int) ( $als_last_summary['removed_members'] ?? 0 ),
					(int) ( $als_last_summary['archived'] ?? 0 )
				);
				?>
			<?php else : ?>
				<?php
				printf(
					/* translators: 1: committees synced, 2: committees archived. */
					esc_html__( 'Last run: %1$d committees synced, %2$d committees archived.', 'agend-loop-sync' ),
					(int) ( $als_last_summary['synced'] ?? 0 ),
					(int) ( $als_last_summary['archived'] ?? 0 )
				);
				?>
				<span class="description">
					<?php if ( ! empty( $als_last_summary['dry_run'] ) ) : ?>
						<?php esc_html_e( '(dry run: nothing was sent, so there are no member counts)', 'agend-loop-sync' ); ?>
					<?php else : ?>
						<?php esc_html_e( '(member counts were not reported by the gateway)', 'agend-loop-sync' ); ?>
					<?php endif; ?>
				</span>
			<?php endif; ?>
			<?php
			$als_unresolved = isset( $als_last_summary['unresolved_external_ids'] ) ? array_values( array_unique( (array) $als_last_summary['unresolved_external_ids'] ) ) : array();
			if ( ! empty( $als_unresolved ) ) :
				?>
				<br />
				<span class="description">
					<?php
					printf(
						/* translators: %s: comma-separated list of membership numbers. */
						esc_html__( 'Members not yet in Loop (they must sign in to Loop through SSO first): %s', 'agend-loop-sync' ),
						esc_html( implode( ', ', array_map( 'strval', $als_unresolved ) ) )
					);
					?>
				</span>
			<?php endif; ?>
		</p>

		<?php
		$als_adoption_outcomes = isset( $als_last_summary['adoption_outcomes'] ) ? (array) $als_last_summary['adoption_outcomes'] : array();
		$als_adoption_results  = isset( $als_last_summary['adoption_results'] ) ? (array) $als_last_summary['adoption_results'] : array();
		$als_adoption_errors   = isset( $als_last_summary['adoption_errors'] ) ? (array) $als_last_summary['adoption_errors'] : array();

		if ( array() !== $als_adoption_outcomes ) :
			$als_outcome_parts = array();
			foreach ( $als_adoption_outcomes as $als_outcome => $als_outcome_count ) {
				$als_outcome_parts[] = (int) $als_outcome_count . ' ' . ( $als_outcome_labels[ $als_outcome ] ?? $als_outcome_labels['unknown'] );
			}
			?>
			<p>
				<?php
				printf(
					/* translators: 1: members adopted, 2: comma-separated outcomes, for example "2 adopted, 1 not on the committee roster". */
					esc_html__( 'Hand-over in the last run: %1$d adopted. Outcomes: %2$s.', 'agend-loop-sync' ),
					(int) ( $als_last_summary['adopted'] ?? 0 ),
					esc_html( implode( ', ', $als_outcome_parts ) )
				);
				?>
			</p>
			<?php if ( array() !== $als_adoption_results ) : ?>
				<details>
					<summary><?php esc_html_e( 'Show each member handed over', 'agend-loop-sync' ); ?></summary>
					<ul>
						<?php foreach ( $als_adoption_results as $als_adoption_result ) : ?>
							<li>
								<?php
								printf(
									/* translators: 1: committee name, 2: membership number, 3: what happened to the member. */
									esc_html__( '%1$s: membership number %2$d, %3$s', 'agend-loop-sync' ),
									esc_html( (string) ( $als_adoption_result['committee'] ?? '' ) ),
									(int) ( $als_adoption_result['external_id'] ?? 0 ),
									esc_html( $als_outcome_labels[ $als_adoption_result['outcome'] ?? '' ] ?? $als_outcome_labels['unknown'] )
								);
								?>
							</li>
						<?php endforeach; ?>
					</ul>
					<?php
					$als_adoption_total = (int) ( $als_last_summary['adoption_results_total'] ?? 0 );
					if ( $als_adoption_total > count( $als_adoption_results ) ) :
						?>
						<p class="description">
							<?php
							printf(
								/* translators: 1: members listed, 2: members handed over in all. */
								esc_html__( 'Showing the first %1$d of %2$d members.', 'agend-loop-sync' ),
								(int) count( $als_adoption_results ),
								(int) $als_adoption_total
							);
							?>
						</p>
					<?php endif; ?>
				</details>
			<?php endif; ?>
		<?php endif; ?>

		<?php
		$als_held_total   = (int) ( $als_last_summary['held_members'] ?? 0 );
		$als_held_results = isset( $als_last_summary['held_results'] ) ? (array) $als_last_summary['held_results'] : array();

		if ( $als_held_total > 0 ) :
			?>
			<p>
				<?php
				printf(
					/* translators: %d: holds the sync honoured in the last run (one per member per committee). */
					esc_html__( 'Held by a Loop administrator in the last run: %d hold(s). The sync does not re-add a removed member and never raises a capped member above the cap, until a Loop administrator lifts the hold in Loop.', 'agend-loop-sync' ),
					(int) $als_held_total
				);
				?>
			</p>
			<?php if ( array() !== $als_held_results ) : ?>
				<details>
					<summary><?php esc_html_e( 'Show each held member', 'agend-loop-sync' ); ?></summary>
					<ul>
						<?php foreach ( $als_held_results as $als_held_result ) : ?>
							<li>
								<?php
								printf(
									/* translators: 1: committee name, 2: membership number, 3: what the hold does, since when and why, for example "Removed from the channel. Since 2026-10-19 10:30. Reason: Under review". */
									esc_html__( '%1$s: membership number %2$d. %3$s', 'agend-loop-sync' ),
									esc_html( (string) ( $als_held_result['committee'] ?? '' ) ),
									(int) ( $als_held_result['external_id'] ?? 0 ),
									esc_html( $als_describe_hold( (array) $als_held_result ) )
								);
								?>
							</li>
						<?php endforeach; ?>
					</ul>
					<?php if ( $als_held_total > count( $als_held_results ) ) : ?>
						<p class="description">
							<?php
							printf(
								/* translators: 1: members listed, 2: members held in all. */
								esc_html__( 'Showing the first %1$d of %2$d members.', 'agend-loop-sync' ),
								(int) count( $als_held_results ),
								(int) $als_held_total
							);
							?>
						</p>
					<?php endif; ?>
				</details>
			<?php endif; ?>
		<?php endif; ?>

		<?php foreach ( $als_adoption_errors as $als_adoption_error ) : ?>
			<div class="notice notice-warning inline"><p><?php echo esc_html( (string) $als_adoption_error ); ?></p></div>
		<?php endforeach; ?>
	<?php endif; ?>

	<p style="display:flex; gap:8px; align-items:flex-start;">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="agend_loop_sync_run_committees" />
			<?php wp_nonce_field( 'agend_loop_sync_run_committees' ); ?>
			<?php submit_button( __( 'Sync committees now', 'agend-loop-sync' ), 'secondary', 'submit', false ); ?>
		</form>
	</p>
	<p style="margin-top:16px;">
		<strong><?php esc_html_e( 'Diagnostics (read-only, no gateway calls):', 'agend-loop-sync' ); ?></strong>
	</p>
	<p style="display:flex; gap:8px; align-items:flex-start;">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="agend_loop_sync_scan_members" />
			<?php wp_nonce_field( 'agend_loop_sync_scan_members' ); ?>
			<?php submit_button( __( 'Scan members for committees', 'agend-loop-sync' ), 'secondary', 'submit', false ); ?>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="agend_loop_sync_inspect_committees" />
			<?php wp_nonce_field( 'agend_loop_sync_inspect_committees' ); ?>
			<?php submit_button( __( 'Inspect committee catalogue', 'agend-loop-sync' ), 'secondary', 'submit', false ); ?>
		</form>
	</p>
	<p class="description">
		<?php esc_html_e( 'Scan members reports which local users are in a committee (with email and membership number) so you can pick a test subject and configure the role map. Inspect committee catalogue lists all committees and the role strings, independent of local users.', 'agend-loop-sync' ); ?>
	</p>

	<?php
	$als_scan = get_transient( 'agend_loop_sync_member_scan' );
	if ( is_array( $als_scan ) ) :
		?>
		<hr />
		<h2><?php esc_html_e( 'Members in committees', 'agend-loop-sync' ); ?></h2>
		<?php if ( ! empty( $als_scan['error'] ) ) : ?>
			<div class="notice notice-warning inline"><p><?php echo esc_html( (string) $als_scan['error'] ); ?></p></div>
		<?php endif; ?>
		<p>
			<?php
			printf(
				/* translators: 1: members with a membership number, 2: members found in a committee. */
				esc_html__( 'Local members with a membership number: %1$d. Of those, in at least one committee: %2$d.', 'agend-loop-sync' ),
				(int) ( $als_scan['members_total'] ?? 0 ),
				(int) ( $als_scan['members_in_committee'] ?? 0 )
			);
			?>
		</p>

		<h3><?php esc_html_e( 'Committee roles found (map each to a channel role above)', 'agend-loop-sync' ); ?></h3>
		<?php if ( empty( $als_scan['roles'] ) ) : ?>
			<p><?php esc_html_e( 'No committee roles found among local members.', 'agend-loop-sync' ); ?></p>
		<?php else : ?>
			<table class="widefat striped" style="max-width:480px;">
				<thead><tr><th><?php esc_html_e( 'Committee role', 'agend-loop-sync' ); ?></th><th><?php esc_html_e( 'Count', 'agend-loop-sync' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( (array) $als_scan['roles'] as $als_role_name => $als_role_count ) : ?>
						<tr><td><code><?php echo esc_html( (string) $als_role_name ); ?></code></td><td><?php echo (int) $als_role_count; ?></td></tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<h3><?php esc_html_e( 'Members', 'agend-loop-sync' ); ?></h3>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'WP user', 'agend-loop-sync' ); ?></th>
					<th><?php esc_html_e( 'Email', 'agend-loop-sync' ); ?></th>
					<th><?php esc_html_e( 'Membership #', 'agend-loop-sync' ); ?></th>
					<th><?php esc_html_e( 'Committees (committee: role)', 'agend-loop-sync' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				$als_members = isset( $als_scan['members'] ) ? (array) $als_scan['members'] : array();
				if ( array() === $als_members ) :
					?>
					<tr><td colspan="4"><?php esc_html_e( 'No local members are in a committee. Set a test user\'s imk_membership_number meta to a real member number, then re-scan.', 'agend-loop-sync' ); ?></td></tr>
					<?php
				else :
					foreach ( $als_members as $als_m ) :
						$als_cmte_lines = array();
						foreach ( (array) $als_m['committees'] as $als_ca ) {
							$als_cmte_lines[] = esc_html( (string) $als_ca['committee'] . ': ' . (string) $als_ca['role'] );
						}
						?>
						<tr>
							<td>
								<?php echo esc_html( (string) $als_m['display_name'] ); ?>
								<br /><small>#<?php echo (int) $als_m['wp_id']; ?></small>
							</td>
							<td><?php echo esc_html( (string) $als_m['email'] ); ?></td>
							<td><?php echo esc_html( (string) $als_m['membership_number'] ); ?></td>
							<td><?php echo implode( '<br />', $als_cmte_lines ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- lines escaped above. ?></td>
						</tr>
						<?php
					endforeach;
				endif;
				?>
			</tbody>
		</table>
	<?php endif; ?>

	<?php
	$als_preview = get_transient( 'agend_loop_sync_inspection' );
	if ( is_array( $als_preview ) ) :
		?>
		<hr />
		<h2><?php esc_html_e( 'Committee catalogue', 'agend-loop-sync' ); ?></h2>
		<?php if ( ! empty( $als_preview['error'] ) ) : ?>
			<div class="notice notice-warning inline"><p><?php echo esc_html( (string) $als_preview['error'] ); ?></p></div>
		<?php endif; ?>
		<p>
			<?php
			/* translators: %d: committee count. */
			printf( esc_html__( 'Committees returned by Upbeat: %d. Member resolution is done per member (see the member scan above), not from this catalogue.', 'agend-loop-sync' ), (int) ( $als_preview['total_committees'] ?? 0 ) );
			?>
		</p>

		<h3><?php esc_html_e( 'Committees', 'agend-loop-sync' ); ?></h3>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Committee', 'agend-loop-sync' ); ?></th>
					<th><?php esc_html_e( 'Unique ID', 'agend-loop-sync' ); ?></th>
					<th><?php esc_html_e( 'On web', 'agend-loop-sync' ); ?></th>
					<th><?php esc_html_e( 'Groups', 'agend-loop-sync' ); ?></th>
					<th><?php esc_html_e( 'Activities', 'agend-loop-sync' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				$als_committees = isset( $als_preview['committees'] ) ? (array) $als_preview['committees'] : array();
				if ( array() === $als_committees ) :
					?>
					<tr><td colspan="5"><?php esc_html_e( 'No committees returned.', 'agend-loop-sync' ); ?></td></tr>
					<?php
				else :
					foreach ( $als_committees as $als_c ) :
						?>
						<tr>
							<td><?php echo esc_html( (string) $als_c['name'] ); ?></td>
							<td><?php echo esc_html( (string) ( $als_c['unique_id'] ?? '' ) ); ?></td>
							<td><?php echo $als_c['allow_on_web'] ? esc_html__( 'yes', 'agend-loop-sync' ) : esc_html__( 'no', 'agend-loop-sync' ); ?></td>
							<td><?php echo esc_html( implode( ', ', (array) $als_c['groups'] ) ); ?></td>
							<td><?php echo (int) $als_c['members']; ?></td>
						</tr>
						<?php
					endforeach;
				endif;
				?>
			</tbody>
		</table>
	<?php endif; ?>

	<hr id="als-adopt" />

	<h2><?php esc_html_e( 'Hand members to the sync', 'agend-loop-sync' ); ?></h2>
	<p>
		<?php esc_html_e( 'The committee sync only manages the Loop memberships it added itself. A member added by hand, or by an earlier version of this sync, is never demoted or removed by it. Hand a member to the sync here to bring their membership under the committee roster: from the next sync it follows their Upbeat role, including losing access if they leave the committee.', 'agend-loop-sync' ); ?>
	</p>
	<p class="description">
		<?php esc_html_e( 'Nothing is handed over until you tick a member and queue them, and nobody is handed over automatically. Hand-over needs the loop.committees.update scope on the API key (on top of the scopes the sync already uses) and a gateway that supports it. Only a member that nothing manages can be chosen.', 'agend-loop-sync' ); ?>
	</p>

	<?php
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$als_adopt_state = isset( $_GET['adopt'] ) ? sanitize_key( wp_unslash( $_GET['adopt'] ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$als_adopt_selected = isset( $_GET['selected'] ) ? (int) $_GET['selected'] : 0;
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$als_adopt_queued_now = isset( $_GET['queued'] ) ? (int) $_GET['queued'] : 0;

	if ( 'queued' === $als_adopt_state ) :
		?>
		<div class="notice notice-success inline">
			<p>
				<?php
				printf(
					/* translators: 1: members ticked, 2: members newly added to the queue. */
					esc_html__( 'Hand-over queued. Members chosen: %1$d. Newly queued: %2$d. The next committee sync that reaches this committee hands them over.', 'agend-loop-sync' ),
					(int) $als_adopt_selected,
					(int) $als_adopt_queued_now
				);
				?>
			</p>
		</div>
	<?php elseif ( 'cleared' === $als_adopt_state ) : ?>
		<div class="notice notice-success inline"><p><?php esc_html_e( 'Queue cleared for that committee.', 'agend-loop-sync' ); ?></p></div>
	<?php elseif ( 'none_selected' === $als_adopt_state ) : ?>
		<div class="notice notice-warning inline"><p><?php esc_html_e( 'No member was queued. Tick at least one member the list allows.', 'agend-loop-sync' ); ?></p></div>
	<?php elseif ( 'expired' === $als_adopt_state ) : ?>
		<div class="notice notice-warning inline"><p><?php esc_html_e( 'The member list has expired. Load the members again, then queue.', 'agend-loop-sync' ); ?></p></div>
	<?php elseif ( 'unknown_committee' === $als_adopt_state ) : ?>
		<div class="notice notice-warning inline"><p><?php esc_html_e( 'That committee is not in the loaded list. Load the committees again.', 'agend-loop-sync' ); ?></p></div>
	<?php endif; ?>

	<?php
	$als_adopt_loaded = get_transient( 'agend_loop_sync_adopt_committees' );
	$als_adopt_loaded = is_array( $als_adopt_loaded ) ? $als_adopt_loaded : null;
	$als_adopt_names  = array();
	if ( null !== $als_adopt_loaded ) {
		foreach ( (array) ( $als_adopt_loaded['committees'] ?? array() ) as $als_adopt_committee ) {
			$als_adopt_names[ (string) $als_adopt_committee['unique_id'] ] = (string) $als_adopt_committee['name'];
		}
	}

	if ( ! function_exists( 'agend_apps_loop_get_committee_members' ) ) :
		?>
		<div class="notice notice-warning inline">
			<p><?php esc_html_e( 'Choosing members needs Agend Apps Core 1.42.0 or later, which can read a committee channel from Loop. Update Agend Apps Core to use this section.', 'agend-loop-sync' ); ?></p>
		</div>
	<?php else : ?>
		<?php if ( null !== $als_adopt_loaded && '' !== (string) ( $als_adopt_loaded['error'] ?? '' ) ) : ?>
			<div class="notice notice-warning inline"><p><?php echo esc_html( (string) $als_adopt_loaded['error'] ); ?></p></div>
		<?php endif; ?>

		<?php if ( null === $als_adopt_loaded || '' !== (string) ( $als_adopt_loaded['error'] ?? '' ) ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="agend_loop_sync_adopt_load_committees" />
				<?php wp_nonce_field( 'agend_loop_sync_adopt_load_committees' ); ?>
				<?php submit_button( __( 'Load committees', 'agend-loop-sync' ), 'secondary', 'submit', false ); ?>
				<span class="description"><?php esc_html_e( 'Reads the committee list from Upbeat, which can take a moment.', 'agend-loop-sync' ); ?></span>
			</form>
		<?php elseif ( array() === $als_adopt_names ) : ?>
			<p><?php esc_html_e( 'No committees are covered by the sync. Check the committee filter above.', 'agend-loop-sync' ); ?></p>
		<?php else : ?>
			<?php
			$als_adopt_picker = get_transient( 'agend_loop_sync_adopt_picker' );
			$als_adopt_picker = is_array( $als_adopt_picker ) ? $als_adopt_picker : null;
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
				<input type="hidden" name="action" value="agend_loop_sync_adopt_load_members" />
				<?php wp_nonce_field( 'agend_loop_sync_adopt_load_members' ); ?>
				<label for="als-adopt-committee"><strong><?php esc_html_e( 'Committee', 'agend-loop-sync' ); ?></strong></label>
				<select id="als-adopt-committee" name="unique_id">
					<?php foreach ( $als_adopt_names as $als_adopt_uid => $als_adopt_name ) : ?>
						<option value="<?php echo esc_attr( (string) $als_adopt_uid ); ?>" <?php selected( (string) ( $als_adopt_picker['unique_id'] ?? '' ), (string) $als_adopt_uid ); ?>>
							<?php
							/* translators: 1: committee name, 2: Upbeat committee unique id. */
							echo esc_html( sprintf( __( '%1$s (%2$s)', 'agend-loop-sync' ), $als_adopt_name, (string) $als_adopt_uid ) );
							?>
						</option>
					<?php endforeach; ?>
				</select>
				<?php submit_button( __( 'Load members', 'agend-loop-sync' ), 'secondary', 'submit', false ); ?>
			</form>
			<p class="description">
				<?php esc_html_e( 'Reads each local member\'s committee activities from Upbeat and the committee channel from Loop, so it can take a moment. The committee must already have a channel in Loop.', 'agend-loop-sync' ); ?>
			</p>

			<?php if ( null !== $als_adopt_picker ) : ?>
				<?php
				$als_adopt_queue_now = Agend_Loop_Sync_Settings::get_adopt_queue_for( (string) ( $als_adopt_picker['unique_id'] ?? '' ) );
				$als_adopt_reasons   = array(
					'already_managed'    => __( 'Already managed by this sync.', 'agend-loop-sync' ),
					'held'               => __( 'Held by a Loop administrator.', 'agend-loop-sync' ),
					'managed_by_group'   => __( 'Managed by a CRM group.', 'agend-loop-sync' ),
					'managed_by_upbeat'  => __( 'Managed by the Upbeat committee sync.', 'agend-loop-sync' ),
					'managed_elsewhere'  => __( 'Managed by another source.', 'agend-loop-sync' ),
					'no_loop_membership' => __( 'Not in the Loop channel. The sync adds them itself once they have a Loop account.', 'agend-loop-sync' ),
					'number_unusable'    => __( 'The membership number is not a plain number, so the sync cannot name this member.', 'agend-loop-sync' ),
				);
				$als_adopt_managers  = array(
					'none'           => __( 'Nothing', 'agend-loop-sync' ),
					'wordpress_sync' => __( 'This sync', 'agend-loop-sync' ),
					'group'          => __( 'CRM group', 'agend-loop-sync' ),
					'upbeat'         => __( 'Upbeat committee sync', 'agend-loop-sync' ),
				);
				$als_adopt_rows      = (array) ( $als_adopt_picker['members'] ?? array() );
				$als_adopt_has_holds = array() !== array_filter( array_column( $als_adopt_rows, 'hold' ) );
				?>
				<h3>
					<?php
					/* translators: %s: committee name. */
					echo esc_html( sprintf( __( 'Members of %s', 'agend-loop-sync' ), (string) ( $als_adopt_picker['committee'] ?? '' ) ) );
					?>
				</h3>

				<?php if ( '' !== (string) ( $als_adopt_picker['error'] ?? '' ) ) : ?>
					<div class="notice notice-warning inline"><p><?php echo esc_html( (string) $als_adopt_picker['error'] ); ?></p></div>
				<?php elseif ( array() === $als_adopt_rows ) : ?>
					<p><?php esc_html_e( 'No local member with a membership number is on this committee.', 'agend-loop-sync' ); ?></p>
				<?php else : ?>
					<?php if ( (int) ( $als_adopt_picker['unlinked_count'] ?? 0 ) > 0 ) : ?>
						<p class="description">
							<?php
							printf(
								/* translators: %d: number of Loop channel members. */
								esc_html__( '%d member(s) of the Loop channel have no identity on this site\'s SSO connection. They are not listed and cannot be handed over.', 'agend-loop-sync' ),
								(int) $als_adopt_picker['unlinked_count']
							);
							?>
						</p>
					<?php endif; ?>
					<p class="description">
						<?php esc_html_e( 'A member flagged as a demotion holds a higher role in Loop than the roster gives them. Once handed over they are moved to the roster role at the next sync (the last owner is never demoted).', 'agend-loop-sync' ); ?>
					</p>
					<?php if ( $als_adopt_has_holds ) : ?>
						<p class="description">
							<?php esc_html_e( 'A member held by a Loop administrator was removed from the channel, or had their role capped, in Loop. The sync keeps them out, or at or below the cap, until a Loop administrator lifts the hold in Loop, so they cannot be handed over.', 'agend-loop-sync' ); ?>
						</p>
					<?php endif; ?>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="agend_loop_sync_adopt_queue" />
						<input type="hidden" name="unique_id" value="<?php echo esc_attr( (string) $als_adopt_picker['unique_id'] ); ?>" />
						<?php wp_nonce_field( 'agend_loop_sync_adopt_queue' ); ?>
						<table class="widefat striped">
							<thead>
								<tr>
									<th><span class="screen-reader-text"><?php esc_html_e( 'Hand over', 'agend-loop-sync' ); ?></span></th>
									<th><?php esc_html_e( 'Member', 'agend-loop-sync' ); ?></th>
									<th><?php esc_html_e( 'Membership #', 'agend-loop-sync' ); ?></th>
									<th><?php esc_html_e( 'Committee role', 'agend-loop-sync' ); ?></th>
									<th><?php esc_html_e( 'Roster channel role', 'agend-loop-sync' ); ?></th>
									<th><?php esc_html_e( 'Loop role now', 'agend-loop-sync' ); ?></th>
									<th><?php esc_html_e( 'Managed by', 'agend-loop-sync' ); ?></th>
									<th><?php esc_html_e( 'Note', 'agend-loop-sync' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php
								foreach ( $als_adopt_rows as $als_adopt_row ) :
									$als_adopt_queued = ! empty( $als_adopt_row['selectable'] ) && in_array( (int) $als_adopt_row['external_id'], $als_adopt_queue_now, true );
									?>
									<tr>
										<td>
											<input
												type="checkbox"
												name="external_ids[]"
												value="<?php echo esc_attr( isset( $als_adopt_row['external_id'] ) ? (string) (int) $als_adopt_row['external_id'] : '' ); ?>"
												<?php disabled( empty( $als_adopt_row['selectable'] ) || $als_adopt_queued ); ?>
												<?php checked( $als_adopt_queued ); ?>
											/>
										</td>
										<td><?php echo esc_html( (string) $als_adopt_row['name'] ); ?></td>
										<td><?php echo esc_html( (string) $als_adopt_row['membership_number'] ); ?></td>
										<td><?php echo esc_html( (string) $als_adopt_row['committee_role'] ); ?></td>
										<td><?php echo esc_html( (string) $als_adopt_row['channel_role'] ); ?></td>
										<td><?php echo '' === (string) $als_adopt_row['loop_role'] ? esc_html__( 'None', 'agend-loop-sync' ) : esc_html( (string) $als_adopt_row['loop_role'] ); ?></td>
										<td>
											<?php
											echo '' === (string) $als_adopt_row['managed_by']
												? esc_html__( 'Not applicable', 'agend-loop-sync' )
												: esc_html( $als_adopt_managers[ $als_adopt_row['managed_by'] ] ?? $als_adopt_row['managed_by'] );
											?>
										</td>
										<td>
											<?php if ( $als_adopt_queued ) : ?>
												<?php esc_html_e( 'Queued for the next sync.', 'agend-loop-sync' ); ?>
											<?php elseif ( empty( $als_adopt_row['selectable'] ) ) : ?>
												<?php echo esc_html( $als_adopt_reasons[ $als_adopt_row['reason'] ] ?? $als_adopt_reasons['managed_elsewhere'] ); ?>
											<?php endif; ?>
											<?php if ( ! empty( $als_adopt_row['hold'] ) ) : ?>
												<?php echo esc_html( $als_describe_hold( (array) $als_adopt_row['hold'] ) ); ?>
											<?php endif; ?>
											<?php if ( ! empty( $als_adopt_row['demotion'] ) ) : ?>
												<strong>
													<?php
													printf(
														/* translators: 1: current Loop role, 2: role the roster gives them, for example "owner to member". */
														esc_html__( 'Demotion: %1$s to %2$s.', 'agend-loop-sync' ),
														esc_html( (string) $als_adopt_row['loop_role'] ),
														esc_html( (string) $als_adopt_row['channel_role'] )
													);
													?>
												</strong>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
						<p>
							<?php submit_button( __( 'Queue hand-over', 'agend-loop-sync' ), 'primary', 'submit', false ); ?>
						</p>
					</form>
				<?php endif; ?>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="agend_loop_sync_adopt_load_committees" />
				<?php wp_nonce_field( 'agend_loop_sync_adopt_load_committees' ); ?>
				<?php submit_button( __( 'Reload committees', 'agend-loop-sync' ), 'link', 'submit', false ); ?>
			</form>
		<?php endif; ?>
	<?php endif; ?>

	<?php else : ?>
	<h2><?php esc_html_e( 'Committee sync', 'agend-loop-sync' ); ?></h2>
	<p><?php esc_html_e( 'Committee sync is off. Turn it on above to create a private Loop channel for each Upbeat committee, with its own schedule, role mapping, diagnostics and hand-over tools.', 'agend-loop-sync' ); ?></p>
	<?php endif; ?>

	<?php $als_adopt_queue_all = Agend_Loop_Sync_Settings::get_adopt_queue(); ?>
	<?php if ( $als_committees_on || array() !== $als_adopt_queue_all ) : ?>
	<h3><?php esc_html_e( 'Queued for the next sync', 'agend-loop-sync' ); ?></h3>
	<?php if ( ! $als_committees_on ) : ?>
		<div class="notice notice-warning inline"><p><?php esc_html_e( 'Committee sync is off. These hand-overs are applied when it is turned back on, unless you clear them.', 'agend-loop-sync' ); ?></p></div>
	<?php endif; ?>
	<?php if ( array() === $als_adopt_queue_all ) : ?>
		<p><?php esc_html_e( 'Nothing is queued.', 'agend-loop-sync' ); ?></p>
	<?php else : ?>
		<?php if ( Agend_Loop_Sync_Settings::is_dry_run() ) : ?>
			<div class="notice notice-info inline"><p><?php esc_html_e( 'Dry run is on, so the next committee sync only logs what it would hand over and keeps this queue.', 'agend-loop-sync' ); ?></p></div>
		<?php endif; ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Committee', 'agend-loop-sync' ); ?></th>
					<th><?php esc_html_e( 'Unique ID', 'agend-loop-sync' ); ?></th>
					<th><?php esc_html_e( 'Members', 'agend-loop-sync' ); ?></th>
					<th><?php esc_html_e( 'Membership numbers', 'agend-loop-sync' ); ?></th>
					<th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'agend-loop-sync' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $als_adopt_queue_all as $als_adopt_uid => $als_adopt_ids ) : ?>
					<tr>
						<td><?php echo esc_html( $als_adopt_names[ (string) $als_adopt_uid ] ?? '' ); ?></td>
						<td><?php echo esc_html( (string) $als_adopt_uid ); ?></td>
						<td><?php echo (int) count( $als_adopt_ids ); ?></td>
						<td>
							<?php
							echo esc_html( implode( ', ', array_slice( $als_adopt_ids, 0, 50 ) ) );
							if ( count( $als_adopt_ids ) > 50 ) {
								echo esc_html(
									sprintf(
										/* translators: %d: number of membership numbers not listed. */
										__( ' and %d more', 'agend-loop-sync' ),
										count( $als_adopt_ids ) - 50
									)
								);
							}
							?>
						</td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="agend_loop_sync_adopt_clear" />
								<input type="hidden" name="unique_id" value="<?php echo esc_attr( (string) $als_adopt_uid ); ?>" />
								<?php wp_nonce_field( 'agend_loop_sync_adopt_clear' ); ?>
								<?php submit_button( __( 'Clear', 'agend-loop-sync' ), 'secondary small', 'submit', false ); ?>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
	<?php endif; ?>

	<hr id="als-rules" />

	<h2><?php esc_html_e( 'Community rules', 'agend-loop-sync' ); ?></h2>
	<?php if ( ! $als_rules_on ) : ?>
		<p><?php esc_html_e( 'Community rules are off. Turn them on above to keep criteria-based private Loop channels, such as "All Fellows", in step with membership.', 'agend-loop-sync' ); ?></p>
	<?php else : ?>
		<?php
		$als_edit_slug = '';
		if ( isset( $_GET['edit_rule'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$als_edit_slug = Agend_Loop_Sync_Settings::sanitize_rule_slug( sanitize_text_field( wp_unslash( $_GET['edit_rule'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$als_editing = '' !== $als_edit_slug ? Agend_Loop_Sync_Settings::get_rule( $als_edit_slug ) : null;
		$als_form    = null !== $als_editing ? $als_editing : Agend_Loop_Sync_Settings::sanitize_rule( array( 'enabled' => true ) );

		$als_always_text = '';
		foreach ( $als_form['always_include'] as $als_always ) {
			$als_always_text .= $als_always['membership_number'] . ' = ' . $als_always['role'] . "\n";
		}

		$als_rule_outcomes = array(
			'synced'         => __( 'synced', 'agend-loop-sync' ),
			'dry_run'        => __( 'dry run, nothing sent', 'agend-loop-sync' ),
			'failed'         => __( 'failed', 'agend-loop-sync' ),
			'emptied'        => __( 'nobody matches, sync-added members cleared', 'agend-loop-sync' ),
			'empty_skipped'  => __( 'nobody matches, nothing sent (merge mode)', 'agend-loop-sync' ),
			'too_large'      => __( 'too many members, not synced', 'agend-loop-sync' ),
		);
		?>
		<?php if ( ! Agend_Loop_Sync_Settings::is_enabled() ) : ?>
			<p><strong><?php esc_html_e( 'Loop Sync is turned off (Enable plugin, above), so rules will not run until it is turned on.', 'agend-loop-sync' ); ?></strong></p>
		<?php endif; ?>
		<p><?php esc_html_e( 'Each enabled rule keeps one private Loop channel (rule:<slug>) in step with membership: members who meet the criteria are added and members who stop meeting them are removed. A member with no Loop account yet is skipped until they have one: their first sign-in to Loop creates it, or, with "Create Loop accounts" on, the next member sync. Honours dry run.', 'agend-loop-sync' ); ?></p>

		<?php if ( $als_last_rule_sync > 0 ) : ?>
			<p>
				<?php
				/* translators: %s: formatted date and time of the last rule run. */
				printf( esc_html__( 'Last run: %s', 'agend-loop-sync' ), esc_html( wp_date( 'Y-m-d H:i', $als_last_rule_sync ) ) );
				if ( ! empty( $als_rule_summary ) ) {
					echo ' ';
					printf(
						/* translators: 1: members scanned, 2: mode (replace or merge), 3: channels archived. */
						esc_html__( '%1$d members scanned, %2$s mode, %3$d channels archived.', 'agend-loop-sync' ),
						(int) ( $als_rule_summary['members_scanned'] ?? 0 ),
						esc_html( (string) ( $als_rule_summary['mode'] ?? 'replace' ) ),
						(int) ( $als_rule_summary['archived'] ?? 0 )
					);
				}
				?>
				<?php if ( ! empty( $als_rule_summary['dry_run'] ) ) : ?>
					<em><?php esc_html_e( '(dry run: nothing was sent)', 'agend-loop-sync' ); ?></em>
				<?php endif; ?>
			</p>
		<?php endif; ?>

		<?php if ( 'merge' === ( $als_rule_summary['mode'] ?? '' ) ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'The last run could not read every member from Upbeat, so it only added and updated members. Nobody was removed and no channel was archived.', 'agend-loop-sync' ); ?></p></div>
		<?php endif; ?>

		<?php foreach ( (array) ( $als_rule_summary['errors'] ?? array() ) as $als_rule_error ) : ?>
			<div class="notice notice-error inline"><p><?php echo esc_html( (string) $als_rule_error ); ?></p></div>
		<?php endforeach; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="agend_loop_sync_run_rules" />
			<?php wp_nonce_field( 'agend_loop_sync_run_rules' ); ?>
			<?php submit_button( __( 'Run rules now', 'agend-loop-sync' ), 'secondary', 'submit', false ); ?>
		</form>

		<h3><?php esc_html_e( 'Rules', 'agend-loop-sync' ); ?></h3>
		<?php if ( array() === $als_rules ) : ?>
			<p><?php esc_html_e( 'No rules yet. Add one below.', 'agend-loop-sync' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'agend-loop-sync' ); ?></th>
						<th><?php esc_html_e( 'Slug', 'agend-loop-sync' ); ?></th>
						<th><?php esc_html_e( 'Enabled', 'agend-loop-sync' ); ?></th>
						<th><?php esc_html_e( 'Criteria', 'agend-loop-sync' ); ?></th>
						<th><?php esc_html_e( 'Last run', 'agend-loop-sync' ); ?></th>
						<th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'agend-loop-sync' ); ?></span></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $als_rules as $als_rule ) : ?>
						<?php
						$als_criteria = array();
						if ( array() !== $als_rule['membership_types'] ) {
							/* translators: %s: comma separated membership types. */
							$als_criteria[] = sprintf( __( 'Type: %s', 'agend-loop-sync' ), implode( ', ', $als_rule['membership_types'] ) );
						}
						if ( array() !== $als_rule['membership_levels'] ) {
							/* translators: %s: comma separated membership levels. */
							$als_criteria[] = sprintf( __( 'Level: %s', 'agend-loop-sync' ), implode( ', ', $als_rule['membership_levels'] ) );
						}
						if ( array() !== $als_rule['event_ids'] ) {
							/* translators: %s: comma separated event ids. */
							$als_criteria[] = sprintf( __( 'Registered for event: %s', 'agend-loop-sync' ), implode( ', ', $als_rule['event_ids'] ) );
						}
						$als_criteria_text = array() === $als_criteria ? __( 'Every member', 'agend-loop-sync' ) : implode( 'any' === $als_rule['match'] ? __( ' or ', 'agend-loop-sync' ) : __( ' and ', 'agend-loop-sync' ), $als_criteria );
						$als_stats         = is_array( $als_rule_summary['rules'][ $als_rule['slug'] ] ?? null ) ? $als_rule_summary['rules'][ $als_rule['slug'] ] : null;
						?>
						<tr>
							<td><?php echo esc_html( $als_rule['name'] ); ?></td>
							<td><code><?php echo esc_html( Agend_Loop_Sync_Gateway::RULE_PREFIX . $als_rule['slug'] ); ?></code></td>
							<td><?php echo $als_rule['enabled'] ? esc_html__( 'Yes', 'agend-loop-sync' ) : esc_html__( 'No', 'agend-loop-sync' ); ?></td>
							<td>
								<?php echo esc_html( $als_criteria_text ); ?>
								<?php if ( $als_rule['apply_access_statuses'] ) : ?>
									<br /><em><?php esc_html_e( 'Lapse rule applies', 'agend-loop-sync' ); ?></em>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( null === $als_stats ) : ?>
									<?php esc_html_e( 'Not run yet', 'agend-loop-sync' ); ?>
								<?php else : ?>
									<?php
									printf(
										/* translators: 1: members matched, 2: applied, 3: removed, 4: skipped (no Loop account yet), 5: held by a Loop administrator. */
										esc_html__( '%1$d matched, %2$d applied, %3$d removed, %4$d skipped (no Loop account yet), %5$d held', 'agend-loop-sync' ),
										(int) ( $als_stats['matched'] ?? 0 ),
										(int) ( $als_stats['applied'] ?? 0 ),
										(int) ( $als_stats['removed'] ?? 0 ),
										(int) ( $als_stats['skipped'] ?? 0 ),
										(int) ( $als_stats['held'] ?? 0 )
									);
									?>
									<br /><em><?php echo esc_html( $als_rule_outcomes[ (string) ( $als_stats['outcome'] ?? '' ) ] ?? '' ); ?></em>
								<?php endif; ?>
							</td>
							<td>
								<a class="button button-small" href="<?php echo esc_url( add_query_arg( array( 'page' => 'agend-loop-sync', 'edit_rule' => $als_rule['slug'] ), admin_url( 'options-general.php' ) ) . '#als-rule-form' ); ?>"><?php esc_html_e( 'Edit', 'agend-loop-sync' ); ?></a>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
									<input type="hidden" name="action" value="agend_loop_sync_delete_rule" />
									<input type="hidden" name="slug" value="<?php echo esc_attr( $als_rule['slug'] ); ?>" />
									<?php wp_nonce_field( 'agend_loop_sync_delete_rule' ); ?>
									<?php submit_button( __( 'Delete', 'agend-loop-sync' ), 'secondary small', 'submit', false ); ?>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<h3 id="als-rule-form">
			<?php echo null !== $als_editing ? esc_html__( 'Edit rule', 'agend-loop-sync' ) : esc_html__( 'Add a rule', 'agend-loop-sync' ); ?>
		</h3>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="agend_loop_sync_save_rule" />
			<input type="hidden" name="original_slug" value="<?php echo esc_attr( null !== $als_editing ? $als_editing['slug'] : '' ); ?>" />
			<?php wp_nonce_field( 'agend_loop_sync_save_rule' ); ?>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="als-rule-name"><?php esc_html_e( 'Channel name', 'agend-loop-sync' ); ?></label></th>
						<td><input type="text" id="als-rule-name" name="rule[name]" value="<?php echo esc_attr( $als_form['name'] ); ?>" class="regular-text" maxlength="<?php echo (int) Agend_Loop_Sync_Settings::RULE_NAME_MAX; ?>" required /></td>
					</tr>
					<tr>
						<th scope="row"><label for="als-rule-slug"><?php esc_html_e( 'Slug', 'agend-loop-sync' ); ?></label></th>
						<td>
							<input type="text" id="als-rule-slug" name="rule[slug]" value="<?php echo esc_attr( $als_form['slug'] ); ?>" class="regular-text code" maxlength="<?php echo (int) Agend_Loop_Sync_Settings::RULE_SLUG_MAX; ?>" <?php echo null !== $als_editing ? 'readonly' : ''; ?> />
							<p class="description">
								<?php
								echo null !== $als_editing
									? esc_html__( 'The slug cannot change once a rule is saved: it is the key of the rule\'s Loop channel.', 'agend-loop-sync' )
									: esc_html__( 'Lower case letters, numbers and hyphens. Leave blank to use the name. It cannot be changed later.', 'agend-loop-sync' );
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Enabled', 'agend-loop-sync' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="rule[enabled]" value="1" <?php checked( $als_form['enabled'] ); ?> />
								<?php esc_html_e( 'Keep this rule\'s channel in step. Disabling a rule archives its channel on the next complete run.', 'agend-loop-sync' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="als-rule-types"><?php esc_html_e( 'Membership types', 'agend-loop-sync' ); ?></label></th>
						<td>
							<textarea id="als-rule-types" name="rule[membership_types]" rows="3" cols="50" class="large-text code"><?php echo esc_textarea( implode( "\n", $als_form['membership_types'] ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One per line, for example Fellow. Not case sensitive. Leave empty for any type.', 'agend-loop-sync' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="als-rule-levels"><?php esc_html_e( 'Membership levels', 'agend-loop-sync' ); ?></label></th>
						<td>
							<textarea id="als-rule-levels" name="rule[membership_levels]" rows="3" cols="50" class="large-text code"><?php echo esc_textarea( implode( "\n", $als_form['membership_levels'] ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One per line. Not case sensitive. Leave empty for any level.', 'agend-loop-sync' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="als-rule-events"><?php esc_html_e( 'Registered for events', 'agend-loop-sync' ); ?></label></th>
						<td>
							<textarea id="als-rule-events" name="rule[event_ids]" rows="3" cols="50" class="large-text code"><?php echo esc_textarea( implode( "\n", $als_form['event_ids'] ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One Upbeat event ID per line. A member matches when they have a registration for any of them that is not cancelled, voided or refunded. Leave empty to ignore events.', 'agend-loop-sync' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="als-rule-match"><?php esc_html_e( 'Combine criteria', 'agend-loop-sync' ); ?></label></th>
						<td>
							<select id="als-rule-match" name="rule[match]">
								<option value="all" <?php selected( $als_form['match'], 'all' ); ?>><?php esc_html_e( 'All of them must pass', 'agend-loop-sync' ); ?></option>
								<option value="any" <?php selected( $als_form['match'], 'any' ); ?>><?php esc_html_e( 'Any one passing is enough', 'agend-loop-sync' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Applies to the type, level and event criteria that are filled in. The lapse rule below always applies on top.', 'agend-loop-sync' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Lapse rule', 'agend-loop-sync' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="rule[apply_access_statuses]" value="1" <?php checked( $als_form['apply_access_statuses'] ); ?> />
								<?php esc_html_e( 'Only members whose status keeps access (see Statuses that keep access above) are in this channel.', 'agend-loop-sync' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="als-rule-always"><?php esc_html_e( 'Always include', 'agend-loop-sync' ); ?></label></th>
						<td>
							<textarea id="als-rule-always" name="rule[always_include]" rows="3" cols="50" class="large-text code"><?php echo esc_textarea( $als_always_text ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One membership number per line, optionally followed by = owner, moderator or member (default member). These members are in the channel whatever the criteria and lapse rule say.', 'agend-loop-sync' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Channel options', 'agend-loop-sync' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="rule[member_can_leave]" value="1" <?php checked( $als_form['member_can_leave'] ); ?> />
								<?php esc_html_e( 'Members can leave the channel', 'agend-loop-sync' ); ?>
							</label><br />
							<label>
								<input type="checkbox" name="rule[allow_manual_members]" value="1" <?php checked( $als_form['allow_manual_members'] ); ?> />
								<?php esc_html_e( 'Staff can add members by hand in Loop', 'agend-loop-sync' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Sent to Loop with every run. Members added by hand in Loop are never removed by the sync.', 'agend-loop-sync' ); ?></p>
						</td>
					</tr>
				</tbody>
			</table>
			<?php submit_button( null !== $als_editing ? __( 'Save rule', 'agend-loop-sync' ) : __( 'Add rule', 'agend-loop-sync' ), 'primary', 'submit', false ); ?>
			<?php if ( null !== $als_editing ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'agend-loop-sync' ), admin_url( 'options-general.php' ) ) . '#als-rules' ); ?>"><?php esc_html_e( 'Cancel', 'agend-loop-sync' ); ?></a>
			<?php endif; ?>
		</form>

		<h3><?php esc_html_e( 'Recent rule activity', 'agend-loop-sync' ); ?></h3>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Time', 'agend-loop-sync' ); ?></th>
					<th><?php esc_html_e( 'Level', 'agend-loop-sync' ); ?></th>
					<th><?php esc_html_e( 'Message', 'agend-loop-sync' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				$als_rule_log = array();
				foreach ( Agend_Loop_Sync_Logger::recent( 200 ) as $als_rule_entry ) {
					if ( false !== stripos( (string) $als_rule_entry['message'], 'rule' ) ) {
						$als_rule_log[] = $als_rule_entry;
					}
					if ( count( $als_rule_log ) >= 15 ) {
						break;
					}
				}
				if ( array() === $als_rule_log ) :
					?>
					<tr><td colspan="3"><?php esc_html_e( 'No rule activity yet.', 'agend-loop-sync' ); ?></td></tr>
					<?php
				else :
					foreach ( $als_rule_log as $als_rule_entry ) :
						?>
						<tr>
							<td><?php echo esc_html( wp_date( 'Y-m-d H:i', (int) $als_rule_entry['time'] ) ); ?></td>
							<td><?php echo esc_html( (string) $als_rule_entry['level'] ); ?></td>
							<td><?php echo esc_html( (string) $als_rule_entry['message'] ); ?></td>
						</tr>
						<?php
					endforeach;
				endif;
				?>
			</tbody>
		</table>
	<?php endif; ?>

	<hr />

	<h2><?php esc_html_e( 'Recent activity', 'agend-loop-sync' ); ?></h2>
	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Time', 'agend-loop-sync' ); ?></th>
				<th><?php esc_html_e( 'Level', 'agend-loop-sync' ); ?></th>
				<th><?php esc_html_e( 'Message', 'agend-loop-sync' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php
			$als_log = Agend_Loop_Sync_Logger::recent( 30 );
			if ( array() === $als_log ) :
				?>
				<tr><td colspan="3"><?php esc_html_e( 'No activity yet.', 'agend-loop-sync' ); ?></td></tr>
				<?php
			else :
				foreach ( $als_log as $als_entry ) :
					?>
					<tr>
						<td><?php echo esc_html( wp_date( 'Y-m-d H:i', (int) $als_entry['time'] ) ); ?></td>
						<td><?php echo esc_html( (string) $als_entry['level'] ); ?></td>
						<td><?php echo esc_html( (string) $als_entry['message'] ); ?></td>
					</tr>
					<?php
				endforeach;
			endif;
			?>
		</tbody>
	</table>
</div>
