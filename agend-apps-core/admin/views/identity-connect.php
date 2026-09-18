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
 *     sp_urls: array{sp_entity_id: string, sp_acs_url: string, sp_metadata_url: string}
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
