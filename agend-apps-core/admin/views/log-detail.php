<?php
/**
 * API log entry detail view.
 *
 * @package Agend_Apps_Core
 *
 * @var array<string, mixed>|null $row The entry, or null when it no longer exists.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$agend_apps_back = admin_url( 'tools.php?page=' . Agend_Apps_Log_Admin::PAGE_SLUG );

$agend_apps_pretty = static function ( string $value ): string {
	$decoded = json_decode( $value, true );

	return null === $decoded ? $value : (string) wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
};
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Agend API Log entry', 'agend-apps-core' ); ?></h1>
	<p><a href="<?php echo esc_url( $agend_apps_back ); ?>">&larr; <?php esc_html_e( 'Back to the log', 'agend-apps-core' ); ?></a></p>

	<?php if ( null === $row ) : ?>
		<p><?php esc_html_e( 'This entry no longer exists. It may have been pruned.', 'agend-apps-core' ); ?></p>
	<?php else : ?>
		<table class="widefat striped" style="max-width:1100px">
			<tbody>
				<?php foreach ( $row as $agend_apps_column => $agend_apps_value ) : ?>
					<?php
					if ( in_array( $agend_apps_column, array( 'request_body', 'response_body', 'response_headers' ), true ) ) {
						continue;
					}
					?>
					<tr>
						<th scope="row" style="width:200px"><code><?php echo esc_html( (string) $agend_apps_column ); ?></code></th>
						<td>
							<?php
							if ( 'request_id' === $agend_apps_column && '' !== (string) $agend_apps_value ) {
								printf(
									'<a href="%s"><code>%s</code></a>',
									esc_url( add_query_arg( 'request_id', (string) $agend_apps_value, $agend_apps_back ) ),
									esc_html( (string) $agend_apps_value )
								);
							} else {
								echo '<code>' . esc_html( null === $agend_apps_value ? 'NULL' : (string) $agend_apps_value ) . '</code>';
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php foreach ( array( 'request_body' => __( 'Request body', 'agend-apps-core' ), 'response_body' => __( 'Response body', 'agend-apps-core' ), 'response_headers' => __( 'Response headers', 'agend-apps-core' ) ) as $agend_apps_column => $agend_apps_label ) : ?>
			<h2><?php echo esc_html( $agend_apps_label ); ?></h2>
			<?php if ( '' === (string) ( $row[ $agend_apps_column ] ?? '' ) ) : ?>
				<p><em><?php esc_html_e( 'None.', 'agend-apps-core' ); ?></em></p>
			<?php else : ?>
				<pre style="max-width:1100px;max-height:480px;overflow:auto;background:#f6f7f7;padding:12px;white-space:pre-wrap;word-break:break-word"><?php echo esc_html( $agend_apps_pretty( (string) $row[ $agend_apps_column ] ) ); ?></pre>
			<?php endif; ?>
		<?php endforeach; ?>

		<?php if ( ! empty( $row['truncated'] ) ) : ?>
			<p class="description"><?php esc_html_e( 'Bodies on this successful call were cut to 5,000 characters after redaction.', 'agend-apps-core' ); ?></p>
		<?php endif; ?>
	<?php endif; ?>
</div>
