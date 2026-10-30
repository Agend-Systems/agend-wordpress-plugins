<?php
/**
 * Webhook setup panel markup.
 *
 * Variables from `Agend_Content_Access_Webhook_Panel::render()`:
 *
 * @var Agend_Content_Access_Webhook_Setup $setup          Setup logic.
 * @var array                              $status         `status()` result.
 * @var array|null                         $result         Outcome of the action run on this request.
 * @var bool                               $include_access Whether the entitlement and segment events are wanted.
 * @var string[]                           $wanted         Events a complete subscription carries.
 *
 * The signing secret is never printed here: only whether one is stored.
 *
 * @package Agend_Content_Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$state           = $status['state'];
$subscription    = $status['subscription'];
$secret_stored   = $status['secret_stored'];
$can_act         = in_array( $state, array( 'not_subscribed', 'incomplete', 'subscribed' ), true );
$lifecycle_count = count( Agend_Content_Access_Webhook_Setup::LIFECYCLE_EVENTS );
?>
<h2><?php esc_html_e( 'Member webhooks', 'agend-content-access' ); ?></h2>
<p>
	<?php
	esc_html_e(
		'Agend can tell this site the moment a member\'s membership, seat or access changes, so a lapsed member loses access without waiting for the cache to expire. Agend Apps Core already receives the deliveries; this connects Agend to it.',
		'agend-content-access'
	);
	?>
</p>

<?php if ( is_array( $result ) ) : ?>
	<div class="notice <?php echo ! empty( $result['ok'] ) ? 'notice-success' : 'notice-error'; ?>">
		<p><?php echo esc_html( Agend_Content_Access_Webhook_Panel::message( $result ) ); ?></p>
	</div>
<?php endif; ?>

<table class="form-table" role="presentation">
	<tr>
		<th scope="row"><?php esc_html_e( 'Status', 'agend-content-access' ); ?></th>
		<td>
			<?php if ( 'subscribed' === $state ) : ?>
				<p><strong><?php esc_html_e( 'Connected.', 'agend-content-access' ); ?></strong>
					<?php
					printf(
						/* translators: %d: number of event types. */
						esc_html__( 'Agend sends %d event types to this site.', 'agend-content-access' ),
						(int) count( $subscription['events'] )
					);
					?>
				</p>
			<?php elseif ( 'incomplete' === $state ) : ?>
				<p><strong><?php esc_html_e( 'Connected, but incomplete.', 'agend-content-access' ); ?></strong></p>
				<?php if ( ! $subscription['active'] ) : ?>
					<p><?php esc_html_e( 'The webhook is paused, so Agend is sending nothing to this site.', 'agend-content-access' ); ?></p>
				<?php endif; ?>
				<?php if ( array() !== $status['missing_events'] ) : ?>
					<p><?php esc_html_e( 'It does not yet carry:', 'agend-content-access' ); ?>
						<code><?php echo esc_html( implode( ', ', $status['missing_events'] ) ); ?></code></p>
				<?php endif; ?>
			<?php elseif ( 'not_subscribed' === $state ) : ?>
				<p><strong><?php esc_html_e( 'Not connected.', 'agend-content-access' ); ?></strong>
					<?php esc_html_e( 'Agend is not sending webhooks to this site.', 'agend-content-access' ); ?></p>
			<?php elseif ( 'duplicates' === $state ) : ?>
				<p><strong><?php esc_html_e( 'More than one webhook points at this site.', 'agend-content-access' ); ?></strong></p>
				<p><?php esc_html_e( 'Nothing will be changed until the extras are removed in the Agend dashboard.', 'agend-content-access' ); ?>
					<code><?php echo esc_html( implode( ', ', $status['duplicates'] ) ); ?></code></p>
			<?php else : ?>
				<p><strong><?php esc_html_e( 'Cannot be checked from here.', 'agend-content-access' ); ?></strong></p>
				<p>
					<?php
					echo esc_html(
						Agend_Content_Access_Webhook_Panel::message(
							array(
								'code'    => $state,
								'message' => $status['message'],
							)
						)
					);
					?>
				</p>
			<?php endif; ?>
		</td>
	</tr>
	<tr>
		<th scope="row"><?php esc_html_e( 'Signing secret', 'agend-content-access' ); ?></th>
		<td>
			<p>
				<?php
				echo $secret_stored
					? esc_html__( 'Stored on this site.', 'agend-content-access' )
					: esc_html__( 'Not stored on this site. Deliveries will be refused until it is.', 'agend-content-access' );
				?>
			</p>
		</td>
	</tr>
</table>

<?php if ( $can_act ) : ?>
	<form method="post">
		<?php wp_nonce_field( Agend_Content_Access_Webhook_Panel::NONCE ); ?>

		<?php if ( 'not_subscribed' === $state || 'incomplete' === $state ) : ?>
			<p>
				<label>
					<input type="checkbox" name="agend_content_access_include_access" value="1" <?php checked( $include_access ); ?> />
					<?php
					esc_html_e(
						'Also send entitlement, segment and contact events, which this plugin uses to refresh access sooner.',
						'agend-content-access'
					);
					?>
				</label>
			</p>
		<?php endif; ?>

		<?php if ( 'not_subscribed' === $state && $secret_stored ) : ?>
			<p>
				<label>
					<input type="checkbox" name="agend_content_access_replace_secret" value="1" />
					<?php esc_html_e( 'Replace the stored signing secret. A secret is already stored; only tick this if it is not used by another webhook.', 'agend-content-access' ); ?>
				</label>
			</p>
		<?php endif; ?>

		<p>
			<?php if ( 'not_subscribed' === $state ) : ?>
				<button type="submit" name="<?php echo esc_attr( Agend_Content_Access_Webhook_Panel::FIELD ); ?>" value="subscribe" class="button button-primary">
					<?php esc_html_e( 'Connect Agend to this site', 'agend-content-access' ); ?>
				</button>
			<?php endif; ?>

			<?php if ( 'incomplete' === $state ) : ?>
				<button type="submit" name="<?php echo esc_attr( Agend_Content_Access_Webhook_Panel::FIELD ); ?>" value="complete" class="button button-primary">
					<?php esc_html_e( 'Add missing events', 'agend-content-access' ); ?>
				</button>
			<?php endif; ?>

			<?php if ( 'subscribed' === $state || 'incomplete' === $state ) : ?>
				<button type="submit" name="<?php echo esc_attr( Agend_Content_Access_Webhook_Panel::FIELD ); ?>" value="test" class="button">
					<?php esc_html_e( 'Send a test delivery', 'agend-content-access' ); ?>
				</button>
			<?php endif; ?>
		</p>

		<?php if ( 'subscribed' === $state || 'incomplete' === $state ) : ?>
			<p>
				<label>
					<input type="checkbox" name="agend_content_access_confirm_rotate" value="1" />
					<?php esc_html_e( 'I understand the current signing secret stops working immediately.', 'agend-content-access' ); ?>
				</label>
				<button type="submit" name="<?php echo esc_attr( Agend_Content_Access_Webhook_Panel::FIELD ); ?>" value="rotate" class="button button-link-delete">
					<?php esc_html_e( 'Replace signing secret', 'agend-content-access' ); ?>
				</button>
			</p>
			<?php if ( ! $secret_stored ) : ?>
				<p class="description">
					<?php esc_html_e( 'The signing secret is shown by Agend only once, so a webhook made elsewhere needs its secret replaced here before this site can verify its deliveries.', 'agend-content-access' ); ?>
				</p>
			<?php endif; ?>
		<?php endif; ?>
	</form>
<?php endif; ?>

<details <?php echo $can_act ? '' : 'open'; ?>>
	<summary><?php esc_html_e( 'Set it up by hand', 'agend-content-access' ); ?></summary>
	<ol>
		<li>
			<?php esc_html_e( 'In the Agend dashboard open Settings, then Webhooks, and create a webhook with this URL:', 'agend-content-access' ); ?>
			<p><input type="text" readonly class="large-text code" value="<?php echo esc_attr( $status['receiver_url'] ); ?>" onfocus="this.select()" /></p>
		</li>
		<li>
			<?php
			printf(
				/* translators: %d: number of member lifecycle event types. */
				esc_html__( 'Subscribe it to these events (the first %d are the member lifecycle events; the rest are optional):', 'agend-content-access' ),
				(int) $lifecycle_count
			);
			?>
			<p><code><?php echo esc_html( implode( ', ', $wanted ) ); ?></code></p>
		</li>
		<li>
			<?php esc_html_e( 'Agend shows the signing secret once, when the webhook is created. Copy it into Settings, then Agend Apps, then Webhook Signing Secret.', 'agend-content-access' ); ?>
		</li>
		<li><?php esc_html_e( 'The URL must be reachable over HTTPS from the internet; Agend does not deliver to private addresses.', 'agend-content-access' ); ?></li>
	</ol>
</details>
