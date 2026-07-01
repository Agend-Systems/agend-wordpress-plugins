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

$als_last_summary = get_option( Agend_Loop_Sync_Settings::OPT_LAST_SUMMARY, array() );
$als_last_summary = is_array( $als_last_summary ) ? $als_last_summary : array();

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

	<form method="post" action="options.php">
		<?php settings_fields( Agend_Loop_Sync_Settings::GROUP ); ?>

		<table class="form-table" role="presentation">
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
				<th scope="row"><?php esc_html_e( 'Committee sync', 'agend-loop-sync' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( Agend_Loop_Sync_Settings::OPT_COMMITTEE_SYNC ); ?>" value="1" <?php checked( Agend_Loop_Sync_Settings::is_committee_sync_enabled() ); ?> />
						<?php esc_html_e( 'Sync committees to Loop channels on a schedule and on demand.', 'agend-loop-sync' ); ?>
					</label>
				</td>
			</tr>
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
		</table>

		<?php submit_button(); ?>
	</form>

	<hr />

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
			<?php
			$als_unresolved = isset( $als_last_summary['unresolved_external_ids'] ) ? array_values( array_unique( (array) $als_last_summary['unresolved_external_ids'] ) ) : array();
			if ( ! empty( $als_unresolved ) ) :
				?>
				<br />
				<span class="description">
					<?php
					printf(
						/* translators: %s: comma-separated list of membership numbers. */
						esc_html__( 'Members not yet in Loop (they must log in / be backfilled first): %s', 'agend-loop-sync' ),
						esc_html( implode( ', ', array_map( 'strval', $als_unresolved ) ) )
					);
					?>
				</span>
			<?php endif; ?>
		</p>
	<?php endif; ?>

	<p style="display:flex; gap:8px; align-items:flex-start;">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="agend_loop_sync_run_committees" />
			<?php wp_nonce_field( 'agend_loop_sync_run_committees' ); ?>
			<?php submit_button( __( 'Sync committees now', 'agend-loop-sync' ), 'secondary', 'submit', false ); ?>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="agend_loop_sync_backfill_users" />
			<?php wp_nonce_field( 'agend_loop_sync_backfill_users' ); ?>
			<?php submit_button( __( 'Backfill all users', 'agend-loop-sync' ), 'secondary', 'submit', false ); ?>
		</form>
	</p>
	<p class="description">
		<?php esc_html_e( 'Backfill pushes every local member with a membership number to Loop in one pass (batches of 100). Run this once before the first committee sync so members can be matched to Loop users. Honours dry run.', 'agend-loop-sync' ); ?>
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
					<em><?php esc_html_e( '(dry run — nothing was sent)', 'agend-loop-sync' ); ?></em>
				<?php endif; ?>
			</p>
		</div>
	<?php endif; ?>

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
					<tr><td colspan="4"><?php esc_html_e( 'No committees returned.', 'agend-loop-sync' ); ?></td></tr>
					<?php
				else :
					foreach ( $als_committees as $als_c ) :
						?>
						<tr>
							<td><?php echo esc_html( (string) $als_c['name'] ); ?></td>
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
