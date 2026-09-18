<?php
/**
 * Admin "Identity and SSO" diagnostics panel view.
 *
 * docs/PLAN-wordpress-idp-option-b.md section 6, "Diagnostic panel". Renders
 * the member lookup form and the facts
 * `Agend_Apps_Identity_Admin::render_diagnostics_field()` assembled via
 * {@see agend_apps_wp_idp_diagnostics()}. Required from within that method
 * (an instance method of `Agend_Apps_Identity_Admin`), so `$this`, `$lookup`
 * and `$diagnostics` are all in scope here -- the same pattern
 * `render_page()` uses for `identity-settings.php`.
 *
 * `manage_options` has already been checked by `render_page()` before this
 * ever runs (`do_settings_sections()` is only called after that check).
 *
 * @package Agend_Apps_Core
 *
 * @var Agend_Apps_Identity_Admin $this
 * @var array{user_id: int, query: string, not_found: bool} $lookup
 * @var array|null $diagnostics The array shape documented on
 *      {@see agend_apps_wp_idp_diagnostics()}, or null when no user resolved.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<form method="get" style="margin-bottom:12px;">
	<input type="hidden" name="page" value="<?php echo esc_attr( Agend_Apps_Identity_Admin::PAGE_SLUG ); ?>" />
	<?php wp_nonce_field( Agend_Apps_Identity_Admin::DIAGNOSTICS_LOOKUP_ACTION, 'agend_apps_wp_idp_nonce', false ); ?>
	<label for="agend_apps_wp_idp_user"><?php esc_html_e( 'WordPress user id or email', 'agend-apps-core' ); ?></label>
	<input
		type="text"
		id="agend_apps_wp_idp_user"
		name="<?php echo esc_attr( Agend_Apps_Identity_Admin::DIAGNOSTICS_QUERY_FIELD ); ?>"
		value="<?php echo esc_attr( $lookup['query'] ); ?>"
		class="regular-text"
		placeholder="<?php esc_attr_e( 'Defaults to you', 'agend-apps-core' ); ?>"
	/>
	<?php submit_button( __( 'Look up', 'agend-apps-core' ), 'secondary', '', false ); ?>
</form>
<?php if ( $lookup['not_found'] ) : ?>
	<div class="notice notice-error inline"><p>
		<?php
		printf(
			/* translators: %s: the id or email that was searched for. */
			esc_html__( 'No WordPress user found matching "%s".', 'agend-apps-core' ),
			esc_html( $lookup['query'] )
		);
		?>
	</p></div>
<?php endif; ?>

<?php if ( null === $diagnostics ) : ?>
	<p class="description"><?php esc_html_e( 'Nothing to show until a user resolves.', 'agend-apps-core' ); ?></p>
	<?php return; ?>
<?php endif; ?>

<?php if ( ! $diagnostics['wordpress_mode'] ) : ?>
	<div class="notice notice-info inline"><p>
		<?php esc_html_e( 'This site is not in WordPress account sign-in mode. Recorded link state below will always be "Never attempted", since the link step never runs in this mode. The external id, cached token, key scopes and connection entity id below are still meaningful.', 'agend-apps-core' ); ?>
	</p></div>
<?php endif; ?>

<table class="widefat striped" style="max-width:820px;">
	<tbody>
		<tr>
			<th scope="row"><?php esc_html_e( 'Inspecting', 'agend-apps-core' ); ?></th>
			<td>
				<?php
				printf(
					/* translators: 1: WordPress user id, 2: user email (may be empty). */
					esc_html__( 'User #%1$d %2$s', 'agend-apps-core' ),
					(int) $diagnostics['user_id'],
					$diagnostics['user_email'] ? '(' . esc_html( $diagnostics['user_email'] ) . ')' : ''
				);
				?>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'External id', 'agend-apps-core' ); ?></th>
			<td>
				<?php if ( '' === $diagnostics['external_id']['value'] ) : ?>
					<em><?php esc_html_e( 'None resolves for this user.', 'agend-apps-core' ); ?></em>
				<?php else : ?>
					<code><?php echo esc_html( $diagnostics['external_id']['value'] ); ?></code>
					<?php
					$source_labels = array(
						'meta_key' => __( 'the configured user-meta key', 'agend-apps-core' ),
						'minted'   => __( 'the WordPress-minted GUID', 'agend-apps-core' ),
						'filter'   => __( 'the agend_apps_current_user_external_id filter', 'agend-apps-core' ),
					);
					$source        = $diagnostics['external_id']['source'];
					?>
					<span class="description">
						--
						<?php
						printf(
							/* translators: %s: which of the three sources supplied the external id. */
							esc_html__( 'source: %s', 'agend-apps-core' ),
							esc_html( $source_labels[ $source ] ?? $source )
						);
						?>
					</span>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Link state', 'agend-apps-core' ); ?></th>
			<td>
				<?php $guidance = $diagnostics['link_guidance']; ?>
				<strong><?php echo esc_html( $guidance['label'] ); ?></strong>
				<?php if ( '' !== $diagnostics['link']['error_code'] ) : ?>
					<code><?php echo esc_html( $diagnostics['link']['error_code'] ); ?></code>
				<?php endif; ?>
				<?php if ( 0 !== $diagnostics['link']['timestamp'] ) : ?>
					<span class="description">
						<?php
						printf(
							/* translators: %s: human-readable elapsed time, e.g. "3 hours". */
							esc_html__( '(recorded %s ago)', 'agend-apps-core' ),
							esc_html( human_time_diff( $diagnostics['link']['timestamp'], time() ) )
						);
						?>
					</span>
				<?php endif; ?>
				<p class="description"><?php echo esc_html( $guidance['guidance'] ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Recorded Agend ids', 'agend-apps-core' ); ?></th>
			<td>
				<?php
				printf(
					/* translators: 1: Agend user id or "none recorded", 2: CRM contact id or "none recorded". */
					esc_html__( 'User id: %1$s. Contact id: %2$s.', 'agend-apps-core' ),
					esc_html( '' !== $diagnostics['identity_ids']['supabase_user_id'] ? $diagnostics['identity_ids']['supabase_user_id'] : __( 'none recorded', 'agend-apps-core' ) ),
					esc_html( '' !== $diagnostics['identity_ids']['contact_id'] ? $diagnostics['identity_ids']['contact_id'] : __( 'none recorded', 'agend-apps-core' ) )
				);
				?>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Cached SSO token', 'agend-apps-core' ); ?></th>
			<td>
				<?php if ( ! $diagnostics['token']['cached'] ) : ?>
					<?php esc_html_e( 'No token cached.', 'agend-apps-core' ); ?>
				<?php elseif ( $diagnostics['token']['expired'] ) : ?>
					<?php esc_html_e( 'Cached, but expired. A fresh mint is attempted on the member\'s next request.', 'agend-apps-core' ); ?>
				<?php else : ?>
					<?php
					printf(
						/* translators: %s: human-readable time until expiry, e.g. "45 minutes". */
						esc_html__( 'Cached and current, valid for another %s.', 'agend-apps-core' ),
						esc_html( human_time_diff( time(), $diagnostics['token']['expires_at'] ) )
					);
					?>
				<?php endif; ?>
				<?php if ( $diagnostics['token']['negative_cached'] ) : ?>
					<br /><span class="description"><?php esc_html_e( 'A negative cache is currently set: minting was recently tried and failed, and will not be retried immediately.', 'agend-apps-core' ); ?></span>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'API key scopes', 'agend-apps-core' ); ?></th>
			<td>
				<?php
				$scope_rows = array(
					'identity_link' => __( 'Identity link step (sso.identities.create)', 'agend-apps-core' ),
					'account_link'  => __( 'Token worker (sso.identities.read, sso.tokens.create)', 'agend-apps-core' ),
				);
				foreach ( $scope_rows as $scope_key => $scope_label ) :
					$scope_state = $diagnostics['scopes'][ $scope_key ];
					?>
					<div>
						<?php echo esc_html( $scope_label ); ?>:
						<?php if ( ! $scope_state['known'] ) : ?>
							<em><?php esc_html_e( 'not yet known', 'agend-apps-core' ); ?></em>
						<?php elseif ( $scope_state['held'] ) : ?>
							<strong><?php esc_html_e( 'held', 'agend-apps-core' ); ?></strong>
						<?php else : ?>
							<strong><?php esc_html_e( 'missing', 'agend-apps-core' ); ?></strong>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Connection entity id in effect', 'agend-apps-core' ); ?></th>
			<td><code><?php echo esc_html( $diagnostics['idp_entity_id'] ); ?></code></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'SAML link eligibility', 'agend-apps-core' ); ?></th>
			<td>
				<?php $eligibility_guidance = $diagnostics['eligibility_guidance']; ?>
				<strong><?php echo esc_html( $eligibility_guidance['label'] ); ?></strong>
				(<?php echo esc_html( $eligibility_guidance['severity'] ); ?>)
				<p class="description"><?php echo esc_html( $eligibility_guidance['guidance'] ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Connection approval state', 'agend-apps-core' ); ?></th>
			<td>
				<?php if ( '' === $diagnostics['connection']['approval_state'] ) : ?>
					<em><?php esc_html_e( 'No connection recorded.', 'agend-apps-core' ); ?></em>
				<?php elseif ( 'pending' === $diagnostics['connection']['approval_state'] ) : ?>
					<?php esc_html_e( 'Pending Agend approval. This is the normal state right after connecting, not a failure.', 'agend-apps-core' ); ?>
				<?php else : ?>
					<code><?php echo esc_html( $diagnostics['connection']['approval_state'] ); ?></code>
				<?php endif; ?>
				<?php if ( '' !== $diagnostics['connection']['slug'] ) : ?>
					<span class="description">
						<?php
						printf(
							/* translators: %s: the connection slug. */
							esc_html__( '(slug: %s)', 'agend-apps-core' ),
							esc_html( $diagnostics['connection']['slug'] )
						);
						?>
					</span>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Silent link attempts', 'agend-apps-core' ); ?></th>
			<td>
				<?php
				printf(
					/* translators: 1: attempts used, 2: the lifetime cap. */
					esc_html__( '%1$d / %2$d', 'agend-apps-core' ),
					(int) $diagnostics['attempts']['count'],
					(int) $diagnostics['attempts']['cap']
				);
				?>
				<?php if ( $diagnostics['attempts']['capped'] ) : ?>
					<strong><?php esc_html_e( '(capped -- in 24-hour backoff)', 'agend-apps-core' ); ?></strong>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Bearer token source', 'agend-apps-core' ); ?></th>
			<td>
				<?php
				$bearer_source_labels = array(
					'sso_linked'          => __( 'SSO-linked', 'agend-apps-core' ),
					'credentials_session' => __( 'Still on the credentials session fallback', 'agend-apps-core' ),
					'none'                => __( 'No bearer available', 'agend-apps-core' ),
				);
				$bearer_source        = $diagnostics['bearer_source'];
				echo esc_html( $bearer_source_labels[ $bearer_source ] ?? $bearer_source );
				?>
			</td>
		</tr>
		<?php if ( isset( $diagnostics['preflight']['nameid_meta_key'] ) ) : ?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Members with an empty NameID field', 'agend-apps-core' ); ?></th>
				<td>
					<?php
					printf(
						/* translators: 1: count of members with an empty NameID meta value, 2: the NameID meta key. */
						esc_html__( '%1$d, against the %2$s meta key.', 'agend-apps-core' ),
						(int) $diagnostics['preflight']['nameid_empty'],
						esc_html( $diagnostics['preflight']['nameid_meta_key'] )
					);
					?>
				</td>
			</tr>
		<?php endif; ?>
	</tbody>
</table>
