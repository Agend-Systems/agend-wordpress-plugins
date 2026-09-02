<?php
/**
 * Admin Entitlement Mirror view.
 *
 * SPEC-AMS-20260804-upbeat-entitlement-mirror US-2.4 /
 * SPEC-CRM-20260805-member-entitlement-grants US-5.1. Renders the mirror's
 * settings, the currently-discovered category/type declarations with their
 * mapped `gate_key`s, the last types sync outcome, and the last per-member
 * sync/error, plus the manual "Sync Entitlement Types" action.
 *
 * Included by Agend_Entitlement_Mirror_Admin_Page::render_page() (Tools >
 * Agend Entitlement Mirror).
 *
 * @package Agend_Entitlement_Mirror
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$active_source   = Agend_Entitlement_Mirror_Source_Registry::active();
$source_available = $active_source->is_available();
$type_entries    = $source_available ? Agend_Entitlement_Sync::discover_type_entries() : array();
$last_types_sync = get_option( Agend_Entitlement_Sync::LAST_TYPES_SYNC_OPTION, null );
$last_sync       = get_option( Agend_Entitlement_Sync::LAST_SYNC_OPTION, null );
$last_error      = get_option( Agend_Entitlement_Sync::LAST_ERROR_OPTION, null );
$nonce           = wp_create_nonce( 'agend_apps_sync_entitlement_types' );
$ajax_url        = admin_url( 'admin-ajax.php' );
?>
<div class="wrap agend-apps-entitlement-mirror">
	<h1><?php esc_html_e( 'Agend Entitlement Mirror', 'agend-entitlement-mirror' ); ?></h1>

	<?php if ( ! $source_available ) : ?>
		<div class="notice notice-warning inline">
			<p>
				<?php
				printf(
					/* translators: 1: active data source label, 2: reason it is unavailable. */
					esc_html__( 'The configured data source (%1$s) is unavailable: %2$s The entitlement mirror is inert until it is.', 'agend-entitlement-mirror' ),
					esc_html( $active_source->get_label() ),
					esc_html( $active_source->get_unavailable_reason() )
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<form method="post" action="options.php">
		<?php
		settings_fields( Agend_Entitlement_Mirror_Admin_Page::OPTION_GROUP );
		do_settings_sections( Agend_Entitlement_Mirror_Admin_Page::MENU_SLUG );
		submit_button();
		?>
	</form>

	<hr />

	<h2><?php esc_html_e( 'Entitlement Types', 'agend-entitlement-mirror' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'The categories and types the collector currently sees from the configured data source, and the gate_key each maps to (Decision 2.7). Syncing declares this full list to Agend as crm_benefits rows under this install\'s source key.', 'agend-entitlement-mirror' ); ?>
	</p>

	<table class="widefat striped agend-apps-entitlement-mirror-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Gate Key', 'agend-entitlement-mirror' ); ?></th>
				<th><?php esc_html_e( 'Name', 'agend-entitlement-mirror' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $type_entries ) ) : ?>
				<tr>
					<td colspan="2"><?php esc_html_e( 'No mirrorable entitlement types found.', 'agend-entitlement-mirror' ); ?></td>
				</tr>
			<?php else : ?>
				<?php foreach ( $type_entries as $entry ) : ?>
					<tr>
						<td><code><?php echo esc_html( $entry['gate_key'] ); ?></code></td>
						<td><?php echo esc_html( $entry['name'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>

	<p>
		<button type="button"
		        id="agend-apps-sync-types-btn"
		        class="button button-primary"
		        data-nonce="<?php echo esc_attr( $nonce ); ?>"
		        data-ajax-url="<?php echo esc_attr( $ajax_url ); ?>"
		        <?php disabled( empty( $type_entries ) ); ?>>
			<?php esc_html_e( 'Sync Entitlement Types', 'agend-entitlement-mirror' ); ?>
		</button>
	</p>

	<div id="agend-apps-types-sync-result" class="notice" style="display:none;"></div>

	<h3><?php esc_html_e( 'Last Types Sync', 'agend-entitlement-mirror' ); ?></h3>
	<?php if ( empty( $last_types_sync ) ) : ?>
		<p><?php esc_html_e( 'Never run.', 'agend-entitlement-mirror' ); ?></p>
	<?php else : ?>
		<p>
			<?php
			printf(
				/* translators: 1: ISO timestamp, 2: success/failure. */
				esc_html__( '%1$s -- %2$s', 'agend-entitlement-mirror' ),
				esc_html( (string) ( $last_types_sync['at'] ?? '' ) ),
				! empty( $last_types_sync['success'] )
					? esc_html__( 'succeeded', 'agend-entitlement-mirror' )
					: esc_html( sprintf( /* translators: %s: error message. */ __( 'failed: %s', 'agend-entitlement-mirror' ), (string) ( $last_types_sync['error'] ?? '' ) ) )
			);
			?>
		</p>
		<?php if ( ! empty( $last_types_sync['results'] ) && is_array( $last_types_sync['results'] ) ) : ?>
			<table class="widefat striped agend-apps-entitlement-mirror-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Gate Key', 'agend-entitlement-mirror' ); ?></th>
						<th><?php esc_html_e( 'Name', 'agend-entitlement-mirror' ); ?></th>
						<th><?php esc_html_e( 'Status', 'agend-entitlement-mirror' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $last_types_sync['results'] as $row ) : ?>
						<tr>
							<td><code><?php echo esc_html( (string) ( $row['gate_key'] ?? '' ) ); ?></code></td>
							<td><?php echo esc_html( (string) ( $row['name'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $row['status'] ?? '' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	<?php endif; ?>

	<h3><?php esc_html_e( 'Last Member Sync', 'agend-entitlement-mirror' ); ?></h3>
	<?php if ( empty( $last_sync ) ) : ?>
		<p><?php esc_html_e( 'None yet.', 'agend-entitlement-mirror' ); ?></p>
	<?php else : ?>
		<p>
			<?php
			printf(
				/* translators: 1: member id, 2: ISO timestamp, 3: contact created/adopted, 4: granted count, 5: refreshed count, 6: unchanged count, 7: revoked count. */
				esc_html__( 'Member %1$s at %2$s (%3$s) -- granted %4$d, refreshed %5$d, unchanged %6$d, revoked %7$d', 'agend-entitlement-mirror' ),
				esc_html( (string) ( $last_sync['member_id'] ?? '' ) ),
				esc_html( (string) ( $last_sync['at'] ?? '' ) ),
				! empty( $last_sync['contact_created'] ) ? esc_html__( 'contact created', 'agend-entitlement-mirror' ) : esc_html__( 'contact adopted', 'agend-entitlement-mirror' ),
				(int) ( $last_sync['granted'] ?? 0 ),
				(int) ( $last_sync['refreshed'] ?? 0 ),
				(int) ( $last_sync['unchanged'] ?? 0 ),
				(int) ( $last_sync['revoked'] ?? 0 )
			);
			?>
		</p>
	<?php endif; ?>

	<h3><?php esc_html_e( 'Last Sync Error', 'agend-entitlement-mirror' ); ?></h3>
	<?php if ( empty( $last_error ) ) : ?>
		<p><?php esc_html_e( 'None.', 'agend-entitlement-mirror' ); ?></p>
	<?php elseif ( 'configuration' === ( $last_error['kind'] ?? '' ) ) : ?>
		<div class="notice notice-error inline">
			<p>
				<strong><?php esc_html_e( 'Configuration error -- not retried automatically.', 'agend-entitlement-mirror' ); ?></strong>
				<?php
				printf(
					/* translators: 1: member id, 2: HTTP status code, 3: ISO timestamp. */
					esc_html__( ' Member %1$s -- HTTP %2$d at %3$s', 'agend-entitlement-mirror' ),
					esc_html( (string) ( $last_error['member_id'] ?? '' ) ),
					(int) ( $last_error['status_code'] ?? 0 ),
					esc_html( (string) ( $last_error['at'] ?? '' ) )
				);
				?>
			</p>
			<p><?php echo esc_html( (string) ( $last_error['message'] ?? '' ) ); ?></p>
		</div>
	<?php else : ?>
		<p>
			<?php
			printf(
				/* translators: 1: member id, 2: HTTP status code, 3: ISO timestamp. */
				esc_html__( 'Member %1$s -- HTTP %2$d at %3$s', 'agend-entitlement-mirror' ),
				esc_html( (string) ( $last_error['member_id'] ?? '' ) ),
				(int) ( $last_error['status_code'] ?? 0 ),
				esc_html( (string) ( $last_error['at'] ?? '' ) )
			);
			?>
		</p>
	<?php endif; ?>

</div>

<script>
( function() {
	var btn = document.getElementById( 'agend-apps-sync-types-btn' );
	if ( ! btn ) {
		return;
	}

	btn.addEventListener( 'click', function() {
		var nonce   = btn.dataset.nonce;
		var ajaxUrl = btn.dataset.ajaxUrl;
		var notice  = document.getElementById( 'agend-apps-types-sync-result' );

		btn.disabled = true;

		var body = new URLSearchParams( {
			action:      'agend_apps_sync_entitlement_types',
			_ajax_nonce: nonce,
		} );

		fetch( ajaxUrl, {
			method:      'POST',
			credentials: 'same-origin',
			body:        body,
		} )
		.then( function( r ) { return r.json(); } )
		.then( function( data ) {
			notice.className     = 'notice ' + ( data.success ? 'notice-success' : 'notice-error' );
			notice.textContent   = data.success
				? <?php echo wp_json_encode( __( 'Types synced. Reload this page to see the result table.', 'agend-entitlement-mirror' ) ); ?>
				: ( data.data && data.data.message ? data.data.message : '' );
			notice.style.display = 'block';
		} )
		.catch( function() {
			notice.className      = 'notice notice-error';
			notice.textContent    = <?php echo wp_json_encode( __( 'An unexpected error occurred.', 'agend-entitlement-mirror' ) ); ?>;
			notice.style.display  = 'block';
		} )
		.finally( function() {
			btn.disabled = false;
		} );
	} );
} )();
</script>
