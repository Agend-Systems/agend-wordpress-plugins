<?php
/**
 * Admin Entitlement Mirror view.
 *
 * SPEC-AMS-20260804-upbeat-entitlement-mirror US-2.4. Renders the mirror's
 * settings, the currently-discovered category/type catalogue with its
 * mapped slugs, the last catalogue sync outcome, and the last per-member
 * sync/error, plus the manual "Sync Entitlement Catalogue" action.
 *
 * Included by admin/views/settings.php when the active tab is
 * `entitlement-mirror`.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$kiosk_available   = class_exists( 'Iugo_Membership_Kiosk_API' );
$catalogue_entries = $kiosk_available ? Agend_Entitlement_Sync::discover_catalogue_entries() : array();
$last_catalogue    = get_option( Agend_Entitlement_Sync::LAST_CATALOGUE_SYNC_OPTION, null );
$last_sync         = get_option( Agend_Entitlement_Sync::LAST_SYNC_OPTION, null );
$last_error        = get_option( Agend_Entitlement_Sync::LAST_ERROR_OPTION, null );
$nonce             = wp_create_nonce( 'agend_apps_sync_entitlement_catalogue' );
$ajax_url          = admin_url( 'admin-ajax.php' );
?>
<div class="agend-apps-entitlement-mirror">

	<?php if ( ! $kiosk_available ) : ?>
		<div class="notice notice-warning inline">
			<p><?php esc_html_e( 'The iugo-membership-kiosk plugin is not active. The entitlement mirror is inert until it is.', 'agend-apps-core' ); ?></p>
		</div>
	<?php endif; ?>

	<form method="post" action="options.php">
		<?php
		settings_fields( Agend_Apps_Admin::OPTION_GROUP );
		do_settings_sections( Agend_Apps_Admin::ENTITLEMENT_MIRROR_PAGE );
		submit_button();
		?>
	</form>

	<hr />

	<h2><?php esc_html_e( 'Entitlement Catalogue', 'agend-apps-core' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'The categories and types the collector currently sees from Upbeat, and the slug each maps to (Decision 2.7). Syncing pushes this full list to Agend as the multi_select field options and one audience segment per type.', 'agend-apps-core' ); ?>
	</p>

	<table class="widefat striped agend-apps-entitlement-mirror-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Slug', 'agend-apps-core' ); ?></th>
				<th><?php esc_html_e( 'Label', 'agend-apps-core' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $catalogue_entries ) ) : ?>
				<tr>
					<td colspan="2"><?php esc_html_e( 'No mirrorable entitlement types found.', 'agend-apps-core' ); ?></td>
				</tr>
			<?php else : ?>
				<?php foreach ( $catalogue_entries as $entry ) : ?>
					<tr>
						<td><code><?php echo esc_html( $entry['slug'] ); ?></code></td>
						<td><?php echo esc_html( $entry['label'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>

	<p>
		<button type="button"
		        id="agend-apps-sync-catalogue-btn"
		        class="button button-primary"
		        data-nonce="<?php echo esc_attr( $nonce ); ?>"
		        data-ajax-url="<?php echo esc_attr( $ajax_url ); ?>"
		        <?php disabled( empty( $catalogue_entries ) ); ?>>
			<?php esc_html_e( 'Sync Entitlement Catalogue', 'agend-apps-core' ); ?>
		</button>
	</p>

	<div id="agend-apps-catalogue-sync-result" class="notice" style="display:none;"></div>

	<h3><?php esc_html_e( 'Last Catalogue Sync', 'agend-apps-core' ); ?></h3>
	<?php if ( empty( $last_catalogue ) ) : ?>
		<p><?php esc_html_e( 'Never run.', 'agend-apps-core' ); ?></p>
	<?php else : ?>
		<p>
			<?php
			printf(
				/* translators: 1: ISO timestamp, 2: success/failure. */
				esc_html__( '%1$s -- %2$s', 'agend-apps-core' ),
				esc_html( (string) ( $last_catalogue['at'] ?? '' ) ),
				! empty( $last_catalogue['success'] )
					? esc_html__( 'succeeded', 'agend-apps-core' )
					: esc_html( sprintf( /* translators: %s: error message. */ __( 'failed: %s', 'agend-apps-core' ), (string) ( $last_catalogue['error'] ?? '' ) ) )
			);
			?>
		</p>
		<?php if ( ! empty( $last_catalogue['results'] ) && is_array( $last_catalogue['results'] ) ) : ?>
			<table class="widefat striped agend-apps-entitlement-mirror-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Slug', 'agend-apps-core' ); ?></th>
						<th><?php esc_html_e( 'Label', 'agend-apps-core' ); ?></th>
						<th><?php esc_html_e( 'Status', 'agend-apps-core' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $last_catalogue['results'] as $row ) : ?>
						<tr>
							<td><code><?php echo esc_html( (string) ( $row['slug'] ?? '' ) ); ?></code></td>
							<td><?php echo esc_html( (string) ( $row['label'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $row['status'] ?? '' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	<?php endif; ?>

	<h3><?php esc_html_e( 'Last Member Sync', 'agend-apps-core' ); ?></h3>
	<?php if ( empty( $last_sync ) ) : ?>
		<p><?php esc_html_e( 'None yet.', 'agend-apps-core' ); ?></p>
	<?php else : ?>
		<p>
			<?php
			printf(
				/* translators: 1: member id, 2: ISO timestamp, 3: created/updated. */
				esc_html__( 'Member %1$s at %2$s (%3$s)', 'agend-apps-core' ),
				esc_html( (string) ( $last_sync['member_id'] ?? '' ) ),
				esc_html( (string) ( $last_sync['at'] ?? '' ) ),
				! empty( $last_sync['created'] ) ? esc_html__( 'contact created', 'agend-apps-core' ) : esc_html__( 'contact updated', 'agend-apps-core' )
			);
			?>
		</p>
	<?php endif; ?>

	<h3><?php esc_html_e( 'Last Sync Error', 'agend-apps-core' ); ?></h3>
	<?php if ( empty( $last_error ) ) : ?>
		<p><?php esc_html_e( 'None.', 'agend-apps-core' ); ?></p>
	<?php else : ?>
		<p>
			<?php
			printf(
				/* translators: 1: member id, 2: HTTP status code, 3: ISO timestamp. */
				esc_html__( 'Member %1$s -- HTTP %2$d at %3$s', 'agend-apps-core' ),
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
	var btn = document.getElementById( 'agend-apps-sync-catalogue-btn' );
	if ( ! btn ) {
		return;
	}

	btn.addEventListener( 'click', function() {
		var nonce   = btn.dataset.nonce;
		var ajaxUrl = btn.dataset.ajaxUrl;
		var notice  = document.getElementById( 'agend-apps-catalogue-sync-result' );

		btn.disabled = true;

		var body = new URLSearchParams( {
			action:      'agend_apps_sync_entitlement_catalogue',
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
				? <?php echo wp_json_encode( __( 'Catalogue synced. Reload this page to see the result table.', 'agend-apps-core' ) ); ?>
				: ( data.data && data.data.message ? data.data.message : '' );
			notice.style.display = 'block';
		} )
		.catch( function() {
			notice.className      = 'notice notice-error';
			notice.textContent    = <?php echo wp_json_encode( __( 'An unexpected error occurred.', 'agend-apps-core' ) ); ?>;
			notice.style.display  = 'block';
		} )
		.finally( function() {
			btn.disabled = false;
		} );
	} );
} )();
</script>
