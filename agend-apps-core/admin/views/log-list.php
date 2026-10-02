<?php
/**
 * API log list view.
 *
 * @package Agend_Apps_Core
 *
 * @var array<string, mixed> $filters   Active filters.
 * @var array                $result    `rows` and `has_more` from the store.
 * @var string[]             $templates Recent path templates.
 * @var int                  $page      Current page.
 * @var bool                 $enabled   Whether logging is on.
 * @var int                  $retention Retention in days.
 * @var bool                 $locked    Whether AGEND_APPS_API_LOG overrides the setting.
 * @var string               $page_url  This screen's URL.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen state.
$agend_apps_field = static fn( string $key ): string => isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) ) : '';
$agend_apps_query = array_filter(
	array(
		'status_class'       => $agend_apps_field( 'status_class' ),
		'outcome'            => $agend_apps_field( 'outcome' ),
		'method'             => $agend_apps_field( 'method' ),
		'direction'          => $agend_apps_field( 'direction' ),
		'source'             => $agend_apps_field( 'source' ),
		'path'               => $agend_apps_field( 'path' ),
		'request_id'         => $agend_apps_field( 'request_id' ),
		'gateway_request_id' => $agend_apps_field( 'gateway_request_id' ),
		'event_type'         => $agend_apps_field( 'event_type' ),
		'since'              => $agend_apps_field( 'since' ),
		'until'              => $agend_apps_field( 'until' ),
		'user'               => $agend_apps_field( 'user' ),
		'user_hash'          => $agend_apps_field( 'user_hash' ),
	),
	static fn( string $value ): bool => '' !== $value
);
$agend_apps_updated = $agend_apps_field( 'updated' );
// add_query_arg() does not encode values; a path filter with `+` or `&`
// would change meaning on the next page.
$agend_apps_query_encoded = array_map( 'rawurlencode', $agend_apps_query );
// phpcs:enable

$agend_apps_select = static function ( string $name, array $options, string $current ): void {
	printf( '<select name="%s" id="agend-log-%s">', esc_attr( $name ), esc_attr( $name ) );

	foreach ( $options as $value => $label ) {
		printf( '<option value="%s"%s>%s</option>', esc_attr( (string) $value ), selected( $current, (string) $value, false ), esc_html( $label ) );
	}

	echo '</select>';
};

$agend_apps_outcomes = array(
	''                  => __( 'Any outcome', 'agend-apps-core' ),
	'success'           => 'success',
	'http_error'        => 'http_error',
	'transport_error'   => 'transport_error',
	'invalid_response'  => 'invalid_response',
	'rate_limited'      => 'rate_limited',
	'retried'           => 'retried',
	'signature_invalid' => 'signature_invalid',
	'duplicate'         => 'duplicate',
	'invalid_payload'   => 'invalid_payload',
	'not_configured'    => 'not_configured',
);
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Agend API Log', 'agend-apps-core' ); ?></h1>

	<?php if ( 'settings' === $agend_apps_updated ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Log settings saved.', 'agend-apps-core' ); ?></p></div>
	<?php elseif ( 'purged' === $agend_apps_updated ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'The API log was purged.', 'agend-apps-core' ); ?></p></div>
	<?php endif; ?>

	<p>
		<?php esc_html_e( 'Every call between this site and the Agend gateway, and every webhook the gateway delivers here. Personal data (names, emails, phone numbers, addresses, dates of birth, identifiers, custom fields) and credentials are redacted before anything is stored. Times are UTC.', 'agend-apps-core' ); ?>
	</p>

	<?php if ( ! $enabled ) : ?>
		<div class="notice notice-warning inline"><p><?php esc_html_e( 'Logging is switched off. Nothing new is being recorded.', 'agend-apps-core' ); ?></p></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="agend-log-filters">
		<input type="hidden" name="action" value="agend_apps_api_log_filter" />
		<?php wp_nonce_field( 'agend_apps_api_log_filter' ); ?>
		<p class="search-box" style="float:none;display:flex;flex-wrap:wrap;gap:6px;align-items:center;">
			<label class="screen-reader-text" for="agend-log-since"><?php esc_html_e( 'From', 'agend-apps-core' ); ?></label>
			<input type="date" id="agend-log-since" name="since" value="<?php echo esc_attr( $agend_apps_query['since'] ?? '' ); ?>" />
			<label class="screen-reader-text" for="agend-log-until"><?php esc_html_e( 'To', 'agend-apps-core' ); ?></label>
			<input type="date" id="agend-log-until" name="until" value="<?php echo esc_attr( $agend_apps_query['until'] ?? '' ); ?>" />
			<?php
			$agend_apps_select(
				'status_class',
				array(
					''     => __( 'Any status', 'agend-apps-core' ),
					'2xx'  => '2xx',
					'3xx'  => '3xx',
					'4xx'  => '4xx',
					'5xx'  => '5xx',
					'none' => __( 'No response', 'agend-apps-core' ),
				),
				$agend_apps_query['status_class'] ?? ''
			);
			$agend_apps_select( 'outcome', $agend_apps_outcomes, $agend_apps_query['outcome'] ?? '' );
			$agend_apps_select(
				'method',
				array(
					''       => __( 'Any method', 'agend-apps-core' ),
					'GET'    => 'GET',
					'POST'   => 'POST',
					'PUT'    => 'PUT',
					'PATCH'  => 'PATCH',
					'DELETE' => 'DELETE',
				),
				strtoupper( $agend_apps_query['method'] ?? '' )
			);
			$agend_apps_select(
				'direction',
				array(
					''         => __( 'Both directions', 'agend-apps-core' ),
					'outbound' => __( 'Outbound', 'agend-apps-core' ),
					'inbound'  => __( 'Inbound webhooks', 'agend-apps-core' ),
				),
				$agend_apps_query['direction'] ?? ''
			);
			?>
			<label class="screen-reader-text" for="agend-log-path"><?php esc_html_e( 'Path', 'agend-apps-core' ); ?></label>
			<input type="text" id="agend-log-path" name="path" list="agend-log-paths" placeholder="<?php esc_attr_e( 'Path, e.g. /v1/crm', 'agend-apps-core' ); ?>" value="<?php echo esc_attr( $agend_apps_query['path'] ?? '' ); ?>" />
			<datalist id="agend-log-paths">
				<?php foreach ( $templates as $agend_apps_template ) : ?>
					<option value="<?php echo esc_attr( $agend_apps_template ); ?>"></option>
				<?php endforeach; ?>
			</datalist>
			<label class="screen-reader-text" for="agend-log-user"><?php esc_html_e( 'User', 'agend-apps-core' ); ?></label>
			<input type="text" id="agend-log-user" name="user" placeholder="<?php echo esc_attr( isset( $agend_apps_query['user_hash'] ) ? __( 'Filtered by email', 'agend-apps-core' ) : __( 'User ID or email', 'agend-apps-core' ) ); ?>" value="<?php echo esc_attr( $agend_apps_query['user'] ?? '' ); ?>" />
			<label class="screen-reader-text" for="agend-log-gateway_request_id"><?php esc_html_e( 'Gateway request or event ID', 'agend-apps-core' ); ?></label>
			<input type="text" id="agend-log-gateway_request_id" name="gateway_request_id" placeholder="<?php esc_attr_e( 'Gateway request / event ID', 'agend-apps-core' ); ?>" value="<?php echo esc_attr( $agend_apps_query['gateway_request_id'] ?? '' ); ?>" />
			<label class="screen-reader-text" for="agend-log-request_id"><?php esc_html_e( 'Page request ID', 'agend-apps-core' ); ?></label>
			<input type="text" id="agend-log-request_id" name="request_id" placeholder="<?php esc_attr_e( 'Page request ID', 'agend-apps-core' ); ?>" value="<?php echo esc_attr( $agend_apps_query['request_id'] ?? '' ); ?>" />
			<?php submit_button( __( 'Filter', 'agend-apps-core' ), 'secondary', '', false ); ?>
			<a class="button-link" href="<?php echo esc_url( $page_url ); ?>"><?php esc_html_e( 'Clear', 'agend-apps-core' ); ?></a>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'agend_apps_api_log_export' ), $agend_apps_query_encoded ), admin_url( 'admin-post.php' ) ), 'agend_apps_api_log_export' ) ); ?>"><?php esc_html_e( 'Export CSV', 'agend-apps-core' ); ?></a>
		</p>
	</form>

	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Time (UTC)', 'agend-apps-core' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Direction', 'agend-apps-core' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Request', 'agend-apps-core' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Status', 'agend-apps-core' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Outcome', 'agend-apps-core' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Time (ms)', 'agend-apps-core' ); ?></th>
				<th scope="col"><?php esc_html_e( 'User', 'agend-apps-core' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Gateway ID', 'agend-apps-core' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $result['rows'] ) ) : ?>
				<tr><td colspan="8"><?php esc_html_e( 'No entries match.', 'agend-apps-core' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $result['rows'] as $agend_apps_row ) : ?>
				<?php
				$agend_apps_status = null === $agend_apps_row['status'] ? '' : (string) $agend_apps_row['status'];
				$agend_apps_bad    = 'success' !== $agend_apps_row['outcome'] && 'duplicate' !== $agend_apps_row['outcome'];
				?>
				<tr>
					<td><a href="<?php echo esc_url( add_query_arg( 'log_id', (int) $agend_apps_row['id'], $page_url ) ); ?>"><?php echo esc_html( (string) $agend_apps_row['created_at'] ); ?></a></td>
					<td><?php echo esc_html( 'inbound' === $agend_apps_row['direction'] ? __( 'Inbound', 'agend-apps-core' ) : __( 'Outbound', 'agend-apps-core' ) ); ?></td>
					<td>
						<code><?php echo esc_html( $agend_apps_row['method'] . ' ' . $agend_apps_row['path'] ); ?></code>
						<?php if ( '' !== (string) $agend_apps_row['event_type'] ) : ?>
							<br /><small><?php echo esc_html( (string) $agend_apps_row['event_type'] ); ?></small>
						<?php endif; ?>
						<?php if ( (int) $agend_apps_row['attempt'] > 1 ) : ?>
							<br /><small><?php echo esc_html( sprintf( /* translators: %d: attempt number. */ __( 'Attempt %d', 'agend-apps-core' ), (int) $agend_apps_row['attempt'] ) ); ?></small>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( '' === $agend_apps_status ? '-' : $agend_apps_status ); ?></td>
					<td><?php echo $agend_apps_bad ? '<strong>' . esc_html( (string) $agend_apps_row['outcome'] ) . '</strong>' : esc_html( (string) $agend_apps_row['outcome'] ); ?></td>
					<td><?php echo esc_html( (string) $agend_apps_row['duration_ms'] ); ?></td>
					<td><?php echo (int) $agend_apps_row['user_id'] > 0 ? esc_html( '#' . (int) $agend_apps_row['user_id'] ) : '&ndash;'; ?></td>
					<td><code><?php echo esc_html( (string) $agend_apps_row['gateway_request_id'] ); ?></code></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<div class="tablenav bottom">
		<div class="tablenav-pages">
			<?php if ( $page > 1 ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( array_merge( $agend_apps_query_encoded, array( 'paged' => $page - 1 ) ), $page_url ) ); ?>">&lsaquo; <?php esc_html_e( 'Newer', 'agend-apps-core' ); ?></a>
			<?php endif; ?>
			<?php if ( $result['has_more'] ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( array_merge( $agend_apps_query_encoded, array( 'paged' => $page + 1 ) ), $page_url ) ); ?>"><?php esc_html_e( 'Older', 'agend-apps-core' ); ?> &rsaquo;</a>
			<?php endif; ?>
		</div>
	</div>

	<h2><?php esc_html_e( 'Log settings', 'agend-apps-core' ); ?></h2>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="agend_apps_api_log_settings" />
		<?php wp_nonce_field( 'agend_apps_api_log_settings' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Record API calls', 'agend-apps-core' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="enabled" value="1" <?php checked( $enabled ); ?> <?php disabled( $locked ); ?> />
						<?php esc_html_e( 'Keep a redacted record of every gateway call and webhook delivery', 'agend-apps-core' ); ?>
					</label>
					<?php if ( $locked ) : ?>
						<p class="description"><?php esc_html_e( 'Set by the AGEND_APPS_API_LOG constant in wp-config.php.', 'agend-apps-core' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="agend-log-retention"><?php esc_html_e( 'Keep entries for', 'agend-apps-core' ); ?></label></th>
				<td>
					<input type="number" min="1" max="365" id="agend-log-retention" name="retention_days" value="<?php echo esc_attr( (string) $retention ); ?>" class="small-text" />
					<?php esc_html_e( 'days', 'agend-apps-core' ); ?>
					<p class="description"><?php esc_html_e( 'Older entries are deleted once a day.', 'agend-apps-core' ); ?></p>
				</td>
			</tr>
		</table>
		<p class="description">
			<?php esc_html_e( 'Request and response bodies are never stored for sign-in, registration, password, token refresh, MFA, session hand-off and SSO token calls. Successful calls keep up to 5,000 characters of each redacted body; failed calls keep the whole redacted body, up to 1 MB.', 'agend-apps-core' ); ?>
		</p>
		<?php submit_button( __( 'Save log settings', 'agend-apps-core' ) ); ?>
	</form>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="agend_apps_api_log_purge" />
		<?php wp_nonce_field( 'agend_apps_api_log_purge' ); ?>
		<p>
			<label>
				<input type="checkbox" required />
				<?php esc_html_e( 'I understand this deletes every entry and cannot be undone.', 'agend-apps-core' ); ?>
			</label>
		</p>
		<?php submit_button( __( 'Purge the API log', 'agend-apps-core' ), 'delete', 'submit', false ); ?>
	</form>
</div>
