<?php
/**
 * Admin "Identity and SSO" "Connect this site" view.
 *
 * Required from within `Agend_Apps_Identity_Admin::render_connect_site_field()`
 * (an instance method), so `$this` and `$data` are both in scope here -- the
 * same pattern `render_diagnostics_field()` uses for `identity-diagnostics.php`.
 *
 * `manage_options` has already been checked by `render_page()` before this
 * ever runs (`do_settings_sections()` is only called after that check).
 *
 * @package Agend_Apps_Core
 *
 * @var Agend_Apps_Identity_Admin $this
 * @var array{
 *     can_connect: bool,
 *     blocked_reasons: string[],
 *     stored: array,
 *     last_run: array|null,
 *     sp_urls: array{sp_entity_id: string, sp_acs_url: string, sp_metadata_url: string},
 *     preflight: array{nameid_empty: int, credentials_members: int, nameid_meta_key: string, blocks: bool},
 *     site_moved: bool,
 *     current_site_url: string
 * } $data
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$stored       = $data['stored'];
$has_stored   = ! empty( $stored['id'] ) || ! empty( $stored['idp_entity_id'] );
$display_urls = $has_stored
	? array(
		'sp_entity_id'    => $stored['sp_entity_id'],
		'sp_acs_url'      => $stored['sp_acs_url'],
		'sp_metadata_url' => $stored['sp_metadata_url'],
	)
	: $data['sp_urls'];
?>
<?php if ( ! empty( $data['site_moved'] ) ) : ?>
	<div class="notice notice-error inline"><p>
		<strong><?php esc_html_e( 'This site\'s connection was made under a different address.', 'agend-apps-core' ); ?></strong><br />
		<?php
		printf(
			/* translators: 1: the site_url() this connection was stamped with, 2: this site's current site_url(). */
			esc_html__( 'Stored connection address: %1$s. Current address: %2$s. This can mean the site has genuinely moved (a domain rename, or http to https), or that this database is a clone or restored backup of another site -- in which case it may still carry that other site\'s live Agend API key and SAML signing key. The SAML link flow is standing down until this is resolved.', 'agend-apps-core' ),
			esc_html( $stored['site_url'] ?: __( '(none recorded)', 'agend-apps-core' ) ),
			esc_html( $data['current_site_url'] )
		);
		?>
		<br />
		<?php esc_html_e( 'If this site has genuinely moved, pressing "Reconnect this site" below is safe and is the correct way to re-adopt the connection: it re-stamps the current address. If this is a clone or backup restored onto a different address, do NOT reconnect it here -- instead run "wp agend-apps scrub-secrets" on it to remove the copied credentials.', 'agend-apps-core' ); ?>
	</p></div>
<?php endif; ?>
<table class="widefat striped" style="max-width:820px;margin-bottom:12px;">
	<tbody>
		<tr>
			<th scope="row"><?php esc_html_e( 'SP entity id', 'agend-apps-core' ); ?></th>
			<td><code><?php echo esc_html( $display_urls['sp_entity_id'] ?: __( 'Not yet connected', 'agend-apps-core' ) ); ?></code></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'ACS url', 'agend-apps-core' ); ?></th>
			<td><code><?php echo esc_html( $display_urls['sp_acs_url'] ?: __( 'Not yet connected', 'agend-apps-core' ) ); ?></code></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Metadata url', 'agend-apps-core' ); ?></th>
			<td><code><?php echo esc_html( $display_urls['sp_metadata_url'] ?: __( 'Not yet connected', 'agend-apps-core' ) ); ?></code></td>
		</tr>
		<?php if ( $has_stored ) : ?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Approval state', 'agend-apps-core' ); ?></th>
				<td>
					<?php
					$notice = function_exists( 'agend_apps_connect_approval_notice' )
						? agend_apps_connect_approval_notice( $stored['approval_state'] )
						: '';
					echo esc_html( '' !== $notice ? $notice : $stored['approval_state'] );
					?>
				</td>
			</tr>
		<?php endif; ?>
	</tbody>
</table>

<table class="widefat striped" style="max-width:820px;margin-bottom:12px;">
	<tbody>
		<tr>
			<th scope="row"><?php esc_html_e( 'Members with an empty NameID', 'agend-apps-core' ); ?></th>
			<td>
				<strong><?php echo esc_html( (string) $data['preflight']['nameid_empty'] ); ?></strong>
				<?php
				printf(
					/* translators: %s: the configured NameID user-meta key. */
					esc_html__( 'for meta key "%s". These members would be asserted with an empty NameID, which the gateway rejects, until this key is populated for them.', 'agend-apps-core' ),
					esc_html( $data['preflight']['nameid_meta_key'] )
				);
				?>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Members with Agend credentials (estimate)', 'agend-apps-core' ); ?></th>
			<td>
				<strong><?php echo esc_html( (string) $data['preflight']['credentials_members'] ); ?></strong>
				<?php esc_html_e( 'This is an estimate, not an exact count. These members hold an Agend password and cannot be linked to this connection silently; they will need the OTP-verified link step after this site connects.', 'agend-apps-core' ); ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Administrator owner invitations', 'agend-apps-core' ); ?></th>
			<td>
				<?php esc_html_e( 'WordPress administrators (and the "agend_client_administrator" role) will be offered the Agend owner role by an emailed invitation. Pressing "Connect this site" does not grant anyone owner: each such person is linked and provisioned at the "contact" role first, and only becomes an owner if they accept the invitation email sent to them.', 'agend-apps-core' ); ?>
			</td>
		</tr>
	</tbody>
</table>

<?php if ( ! empty( $data['blocked_reasons'] ) ) : ?>
	<div class="notice notice-warning inline"><p>
		<?php esc_html_e( 'The connect action is not available right now:', 'agend-apps-core' ); ?>
	</p>
	<ul style="list-style:disc;margin-left:20px;">
		<?php foreach ( $data['blocked_reasons'] as $reason ) : ?>
			<li><?php echo esc_html( $reason ); ?></li>
		<?php endforeach; ?>
	</ul>
	</div>
<?php else : ?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="<?php echo esc_attr( Agend_Apps_Identity_Admin::CONNECT_POST_ACTION ); ?>" />
		<?php wp_nonce_field( Agend_Apps_Identity_Admin::CONNECT_ACTION ); ?>
		<?php if ( ! empty( $data['preflight']['blocks'] ) ) : ?>
			<p>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( AGEND_APPS_CONNECT_ACK_FIELD ); ?>" value="1" />
					<?php
					printf(
						/* translators: 1: count of members with an empty NameID, 2: the configured NameID user-meta key. */
						esc_html__( 'I understand that %1$d member(s) have no value for "%2$s" and will fail to link until that key is populated for them.', 'agend-apps-core' ),
						(int) $data['preflight']['nameid_empty'],
						esc_html( $data['preflight']['nameid_meta_key'] )
					);
					?>
				</label>
			</p>
		<?php endif; ?>
		<?php submit_button( $has_stored ? __( 'Reconnect this site', 'agend-apps-core' ) : __( 'Connect this site', 'agend-apps-core' ), 'secondary', '', false ); ?>
	</form>
<?php endif; ?>

<?php if ( null !== $data['last_run'] ) : ?>
	<h4><?php esc_html_e( 'Last run', 'agend-apps-core' ); ?></h4>

	<?php if ( ! empty( $data['last_run']['errors'] ) ) : ?>
		<div class="notice notice-error inline"><p>
			<?php foreach ( $data['last_run']['errors'] as $error ) : ?>
				<?php echo esc_html( $error ); ?><br />
			<?php endforeach; ?>
		</p></div>
	<?php endif; ?>

	<?php if ( ! empty( $data['last_run']['steps'] ) ) : ?>
		<table class="widefat striped" style="max-width:820px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Step', 'agend-apps-core' ); ?></th>
					<th><?php esc_html_e( 'Status', 'agend-apps-core' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $data['last_run']['steps'] as $step ) : ?>
					<tr>
						<td><?php echo esc_html( $step['step'] ); ?></td>
						<td>
							<?php echo esc_html( $step['status'] ); ?>
							<?php if ( ! empty( $step['message'] ) ) : ?>
								-- <?php echo esc_html( $step['message'] ); ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<?php if ( ! empty( $data['last_run']['sp_mismatch'] ) ) : ?>
		<div class="notice notice-warning inline"><p>
			<?php esc_html_e( 'The gateway\'s own SP urls for this connection do not match this site\'s local derivation. The connection was registered with the gateway\'s (authoritative) values, but this mismatch means this site\'s URL derivation has drifted and should be investigated.', 'agend-apps-core' ); ?>
		</p>
		<table class="widefat striped" style="max-width:820px;">
			<thead>
				<tr>
					<th></th>
					<th><?php esc_html_e( 'Authoritative (gateway)', 'agend-apps-core' ); ?></th>
					<th><?php esc_html_e( 'Local derivation', 'agend-apps-core' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( array( 'sp_entity_id', 'sp_acs_url', 'sp_metadata_url' ) as $key ) : ?>
					<tr>
						<td><?php echo esc_html( $key ); ?></td>
						<td><code><?php echo esc_html( $data['last_run']['sp_mismatch']['authoritative'][ $key ] ?? '' ); ?></code></td>
						<td><code><?php echo esc_html( $data['last_run']['sp_mismatch']['local'][ $key ] ?? '' ); ?></code></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		</div>
	<?php endif; ?>
<?php endif; ?>
