<?php
/**
 * Admin cache management view.
 *
 * Renders the cache tab content within the Agend Apps settings page.
 * Included by admin/views/settings.php when the active tab is `cache`.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$cache_keys    = Agend_Apps_Cache::get_all_keys();
$stored_count  = Agend_Apps_Cache::count_stored();
$nonce         = wp_create_nonce( 'agend_apps_clear_cache' );
$ajax_url      = admin_url( 'admin-ajax.php' );
?>
<div class="agend-apps-cache">

	<p>
		<?php
		printf(
			/* translators: %d: number of cached transients. */
			esc_html( _n(
				'There is currently %d cached API response.',
				'There are currently %d cached API responses.',
				$stored_count,
				'agend-apps-core'
			) ),
			(int) $stored_count
		);
		?>
	</p>

	<table class="widefat striped agend-apps-cache-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Endpoint', 'agend-apps-core' ); ?></th>
				<th><?php esc_html_e( 'Default TTL', 'agend-apps-core' ); ?></th>
				<th><?php esc_html_e( 'Current TTL', 'agend-apps-core' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'agend-apps-core' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $cache_keys as $key => $config ) : ?>
				<tr>
					<td><?php echo esc_html( $config['label'] ); ?></td>
					<td><?php echo (int) $config['default_ttl']; ?>s</td>
					<td><?php echo (int) Agend_Apps_Settings::get_cache_ttl( $key ); ?>s</td>
					<td>
						<button type="button"
						        class="button agend-apps-clear-cache-btn"
						        data-endpoint-key="<?php echo esc_attr( $key ); ?>"
						        data-nonce="<?php echo esc_attr( $nonce ); ?>"
						        data-ajax-url="<?php echo esc_attr( $ajax_url ); ?>">
							<?php esc_html_e( 'Clear', 'agend-apps-core' ); ?>
						</button>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<p class="agend-apps-cache-actions">
		<button type="button"
		        class="button button-secondary agend-apps-clear-cache-btn"
		        data-endpoint-key="all"
		        data-nonce="<?php echo esc_attr( $nonce ); ?>"
		        data-ajax-url="<?php echo esc_attr( $ajax_url ); ?>">
			<?php esc_html_e( 'Clear All Caches', 'agend-apps-core' ); ?>
		</button>
	</p>

	<div id="agend-apps-cache-notice" class="notice" style="display:none;"></div>

</div>

<script>
( function() {
	document.querySelectorAll( '.agend-apps-clear-cache-btn' ).forEach( function( btn ) {
		btn.addEventListener( 'click', function() {
			var endpointKey = btn.dataset.endpointKey;
			var nonce       = btn.dataset.nonce;
			var ajaxUrl     = btn.dataset.ajaxUrl;
			var notice      = document.getElementById( 'agend-apps-cache-notice' );

			btn.disabled = true;

			var body = new URLSearchParams( {
				action:        'agend_apps_clear_cache',
				_ajax_nonce:   nonce,
				endpoint_key:  endpointKey,
			} );

			fetch( ajaxUrl, {
				method:      'POST',
				credentials: 'same-origin',
				body:        body,
			} )
			.then( function( r ) { return r.json(); } )
			.then( function( data ) {
				notice.className  = 'notice ' + ( data.success ? 'notice-success' : 'notice-error' );
				notice.textContent = data.data && data.data.message ? data.data.message : '';
				notice.style.display = 'block';
			} )
			.catch( function() {
				notice.className     = 'notice notice-error';
				notice.textContent   = <?php echo wp_json_encode( __( 'An unexpected error occurred.', 'agend-apps-core' ) ); ?>;
				notice.style.display = 'block';
			} )
			.finally( function() {
				btn.disabled = false;
			} );
		} );
	} );
} )();
</script>
