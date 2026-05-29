<?php
/**
 * Admin settings, preview and send page for Agend Directory Sync.
 *
 * Three actions:
 * - "Run Upbeat fetch"     - call Upbeat and dump the raw first N results.
 *                            Useful for verifying the source shape.
 * - "Preview transform"    - fetch + filter + transform, render the first
 *                            batch (capped at 100 rows) without POSTing.
 * - "Send to Agend"        - fetch + filter + transform + POST to the Agend
 *                            gateway in batches of 100, then render the
 *                            aggregated result.
 *
 * A "Max records this run" cap is exposed so the operator can test with a
 * small slice before pushing the full directory.
 *
 * @package Agend_Directory_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Agend_Directory_Sync_Admin_Page' ) ) :
	final class Agend_Directory_Sync_Admin_Page {

		public const MENU_SLUG = 'agend-directory-sync';

		public const NONCE_ACTION_SETTINGS = 'agend_directory_sync_save_settings';
		public const NONCE_ACTION_FETCH    = 'agend_directory_sync_run_sync';
		public const NONCE_ACTION_PREVIEW  = 'agend_directory_sync_preview_transform';
		public const NONCE_ACTION_SEND     = 'agend_directory_sync_send_to_agend';

		public const CAPABILITY = 'manage_options';

		/**
		 * Maximum number of upstream rows to dump verbatim into the page on
		 * an Upbeat fetch. The full set is fetched but rendering thousands
		 * of rows in the browser is unhelpful.
		 */
		public const FETCH_PREVIEW_LIMIT = 10;

		/**
		 * Maximum number of transformed listings to render inline when
		 * previewing the transform.
		 */
		public const TRANSFORM_PREVIEW_LIMIT = 5;

		/**
		 * Register WP hooks. Called from the plugin's post_include_files().
		 */
		public static function setup_hooks(): void {
			add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
			add_action( 'admin_post_agend_directory_sync_save_settings', array( __CLASS__, 'handle_save_settings' ) );
			add_action( 'admin_post_agend_directory_sync_run_sync', array( __CLASS__, 'handle_run_fetch' ) );
			add_action( 'admin_post_agend_directory_sync_preview_transform', array( __CLASS__, 'handle_preview_transform' ) );
			add_action( 'admin_post_agend_directory_sync_send_to_agend', array( __CLASS__, 'handle_send_to_agend' ) );
		}

		public static function register_menu(): void {
			add_submenu_page(
				'tools.php',
				__( 'Agend Directory Sync', 'agend-directory-sync' ),
				__( 'Agend Directory Sync', 'agend-directory-sync' ),
				self::CAPABILITY,
				self::MENU_SLUG,
				array( __CLASS__, 'render_page' )
			);
		}

		public static function handle_save_settings(): void {
			self::assert_can();
			check_admin_referer( self::NONCE_ACTION_SETTINGS );

			$gateway_url     = isset( $_POST['agend_gateway_url'] ) ? esc_url_raw( wp_unslash( $_POST['agend_gateway_url'] ) ) : '';
			$api_key         = isset( $_POST['agend_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['agend_api_key'] ) ) : '';
			$external_source = isset( $_POST['agend_external_source'] ) ? sanitize_text_field( wp_unslash( $_POST['agend_external_source'] ) ) : '';
			$auto_publish    = ! empty( $_POST['agend_auto_publish_approved'] ) ? '1' : '0';

			update_option( Agend_Directory_Sync::OPTION_AGEND_GATEWAY_URL, untrailingslashit( $gateway_url ) );
			update_option( Agend_Directory_Sync::OPTION_AGEND_API_KEY, $api_key );
			update_option( Agend_Directory_Sync::OPTION_EXTERNAL_SOURCE, $external_source );
			update_option( Agend_Directory_Sync::OPTION_AUTO_PUBLISH_APPROVED, $auto_publish );

			wp_safe_redirect( self::redirect_url( array( 'saved' => '1' ) ) );
			exit;
		}

		/**
		 * Fetch from Upbeat and stash a preview of the raw response.
		 */
		public static function handle_run_fetch(): void {
			self::assert_can();
			check_admin_referer( self::NONCE_ACTION_FETCH );

			$user_id = get_current_user_id();

			try {
				$client  = new Agend_Directory_Sync_Upbeat_Client();
				$results = $client->fetch_all();

				self::set_result(
					$user_id,
					array(
						'kind'    => 'fetch',
						'status'  => 'ok',
						'fetched' => count( $results ),
						'preview' => array_slice( $results, 0, self::FETCH_PREVIEW_LIMIT ),
					)
				);
			} catch ( Throwable $e ) {
				self::set_result(
					$user_id,
					array(
						'kind'    => 'fetch',
						'status'  => 'error',
						'message' => $e->getMessage(),
					)
				);
			}

			wp_safe_redirect( self::redirect_url() );
			exit;
		}

		/**
		 * Fetch + transform + render. Does NOT POST anything.
		 */
		public static function handle_preview_transform(): void {
			self::assert_can();
			check_admin_referer( self::NONCE_ACTION_PREVIEW );

			$user_id     = get_current_user_id();
			$max_records = self::read_max_records();

			try {
				$client   = new Agend_Directory_Sync_Upbeat_Client();
				$contacts = self::cap( $client->fetch_all(), $max_records );

				$transformed = Agend_Directory_Sync_Listing_Transformer::transform_all( $contacts );

				self::set_result(
					$user_id,
					array(
						'kind'                   => 'preview',
						'status'                 => 'ok',
						'fetched'                => count( $contacts ),
						'transformed'            => count( $transformed['listings'] ),
						'skipped'                => $transformed['skipped'],
						'skip_reasons'           => $transformed['skip_reasons'],
						'duplicate_external_ids' => $transformed['duplicate_external_ids'],
						'dropped_fields'         => $transformed['dropped_fields'] ?? array(),
						'preview'                => array_slice( $transformed['listings'], 0, self::TRANSFORM_PREVIEW_LIMIT ),
						'max_records'            => $max_records,
					)
				);
			} catch ( Throwable $e ) {
				self::set_result(
					$user_id,
					array(
						'kind'    => 'preview',
						'status'  => 'error',
						'message' => $e->getMessage(),
					)
				);
			}

			wp_safe_redirect( self::redirect_url() );
			exit;
		}

		/**
		 * Fetch + transform + POST to Agend. Aggregates created/updated/error
		 * counts across all batches.
		 */
		public static function handle_send_to_agend(): void {
			self::assert_can();
			check_admin_referer( self::NONCE_ACTION_SEND );

			// Long-running operation; large directories can require ~30+
			// sequential batches at ~2s each.
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 0 );
			}
			ignore_user_abort( true );

			$user_id          = get_current_user_id();
			$max_records      = self::read_max_records();
			$external_source  = self::resolve_external_source();
			$auto_publish     = self::resolve_auto_publish_approved();

			try {
				$upbeat   = new Agend_Directory_Sync_Upbeat_Client();
				$contacts = self::cap( $upbeat->fetch_all(), $max_records );

				$transformed = Agend_Directory_Sync_Listing_Transformer::transform_all( $contacts );
				$listings    = $transformed['listings'];

				$send_summary = array();
				if ( ! empty( $listings ) ) {
					$agend        = new Agend_Directory_Sync_Agend_Client();
					$send_summary = $agend->send_listings( $listings, $external_source, $auto_publish );
				}

				self::set_result(
					$user_id,
					array(
						'kind'                   => 'send',
						'status'                 => 'ok',
						'fetched'                => count( $contacts ),
						'transformed'            => count( $listings ),
						'skipped'                => $transformed['skipped'],
						'skip_reasons'           => $transformed['skip_reasons'],
						'duplicate_external_ids' => $transformed['duplicate_external_ids'],
						'dropped_fields'         => $transformed['dropped_fields'] ?? array(),
						'external_source'        => $external_source,
						'auto_publish_approved'  => $auto_publish,
						'max_records'            => $max_records,
						'send'                   => $send_summary,
					)
				);
			} catch ( Throwable $e ) {
				self::set_result(
					$user_id,
					array(
						'kind'    => 'send',
						'status'  => 'error',
						'message' => $e->getMessage(),
					)
				);
			}

			wp_safe_redirect( self::redirect_url() );
			exit;
		}

		public static function render_page(): void {
			self::assert_can();

			$gateway_url     = (string) get_option( Agend_Directory_Sync::OPTION_AGEND_GATEWAY_URL, '' );
			$api_key         = (string) get_option( Agend_Directory_Sync::OPTION_AGEND_API_KEY, '' );
			$external_source = self::resolve_external_source();
			$auto_publish    = self::resolve_auto_publish_approved();

			$action_url = esc_url( admin_url( 'admin-post.php' ) );
			$last       = get_transient( self::transient_key( get_current_user_id() ) );
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Agend Directory Sync', 'agend-directory-sync' ); ?></h1>

				<?php if ( isset( $_GET['saved'] ) ) : ?>
					<div class="notice notice-success is-dismissible">
						<p><?php esc_html_e( 'Settings saved.', 'agend-directory-sync' ); ?></p>
					</div>
				<?php endif; ?>

				<h2><?php esc_html_e( 'Settings', 'agend-directory-sync' ); ?></h2>
				<form method="post" action="<?php echo $action_url; ?>">
					<input type="hidden" name="action" value="agend_directory_sync_save_settings" />
					<?php wp_nonce_field( self::NONCE_ACTION_SETTINGS ); ?>

					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row">
									<label for="agend_gateway_url"><?php esc_html_e( 'Agend gateway base URL', 'agend-directory-sync' ); ?></label>
								</th>
								<td>
									<input
										name="agend_gateway_url"
										id="agend_gateway_url"
										type="url"
										class="regular-text"
										value="<?php echo esc_attr( $gateway_url ); ?>"
										placeholder="http://localhost:3072"
									/>
									<p class="description">
										<?php esc_html_e( 'Base URL of the Agend public API gateway. No trailing slash. e.g. http://localhost:3072 for local dev.', 'agend-directory-sync' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="agend_api_key"><?php esc_html_e( 'Agend API key', 'agend-directory-sync' ); ?></label>
								</th>
								<td>
									<input
										name="agend_api_key"
										id="agend_api_key"
										type="text"
										class="regular-text"
										value="<?php echo esc_attr( $api_key ); ?>"
										autocomplete="off"
									/>
									<p class="description">
										<?php esc_html_e( 'Public API key with the directory.listings.bulk_upsert scope. Stored as plain text for MVP.', 'agend-directory-sync' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="agend_external_source"><?php esc_html_e( 'external_source', 'agend-directory-sync' ); ?></label>
								</th>
								<td>
									<input
										name="agend_external_source"
										id="agend_external_source"
										type="text"
										class="regular-text"
										value="<?php echo esc_attr( $external_source ); ?>"
										placeholder="<?php echo esc_attr( Agend_Directory_Sync::DEFAULT_EXTERNAL_SOURCE ); ?>"
									/>
									<p class="description">
										<?php esc_html_e( 'Caller identifier sent with each bulk-upsert batch. Defaults to "aiqs-upbeat" if left blank.', 'agend-directory-sync' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<?php esc_html_e( 'Auto-publish approved listings', 'agend-directory-sync' ); ?>
								</th>
								<td>
									<label for="agend_auto_publish_approved">
										<input
											name="agend_auto_publish_approved"
											id="agend_auto_publish_approved"
											type="checkbox"
											value="1"
											<?php checked( $auto_publish ); ?>
										/>
										<?php esc_html_e( 'Set published_at on approved listings so they appear on the public directory immediately.', 'agend-directory-sync' ); ?>
									</label>
									<p class="description">
										<?php esc_html_e( 'When off, listings sync with status=approved but stay hidden until an admin publishes them in the Agend dashboard. Existing already-published rows are never re-stamped on re-sync.', 'agend-directory-sync' ); ?>
									</p>
								</td>
							</tr>
						</tbody>
					</table>

					<?php submit_button( __( 'Save settings', 'agend-directory-sync' ) ); ?>
				</form>

				<hr />

				<h2><?php esc_html_e( 'Manual sync', 'agend-directory-sync' ); ?></h2>
				<p>
					<?php esc_html_e( 'Use "Preview transform" to inspect the mapped payload before pushing live data. Use the "Max records" field to start small while verifying.', 'agend-directory-sync' ); ?>
				</p>

				<table class="form-table" role="presentation" style="max-width:600px;">
					<tbody>
						<tr>
							<th scope="row">
								<label for="agend_max_records"><?php esc_html_e( 'Max records this run', 'agend-directory-sync' ); ?></label>
							</th>
							<td>
								<input
									name="agend_max_records"
									id="agend_max_records"
									type="number"
									min="0"
									step="1"
									class="small-text"
									form="agend-directory-sync-actions"
									value=""
									placeholder="all"
								/>
								<p class="description">
									<?php esc_html_e( 'Optional cap, applied after fetching from Upbeat but before transforming. Leave blank to process all records.', 'agend-directory-sync' ); ?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>

				<div id="agend-directory-sync-actions" style="display:flex;flex-wrap:wrap;gap:1em;align-items:flex-start;">
					<form method="post" action="<?php echo $action_url; ?>" style="margin:0;">
						<input type="hidden" name="action" value="agend_directory_sync_run_sync" />
						<?php wp_nonce_field( self::NONCE_ACTION_FETCH ); ?>
						<?php submit_button( __( 'Run Upbeat fetch', 'agend-directory-sync' ), 'secondary', 'submit', false ); ?>
					</form>

					<form method="post" action="<?php echo $action_url; ?>" style="margin:0;">
						<input type="hidden" name="action" value="agend_directory_sync_preview_transform" />
						<input type="hidden" name="agend_max_records" id="agend_max_records_preview" value="" />
						<?php wp_nonce_field( self::NONCE_ACTION_PREVIEW ); ?>
						<?php submit_button( __( 'Preview transform', 'agend-directory-sync' ), 'secondary', 'submit', false ); ?>
					</form>

					<form method="post" action="<?php echo $action_url; ?>" style="margin:0;" onsubmit="return confirm('<?php echo esc_js( __( 'This will POST to the configured Agend gateway. Continue?', 'agend-directory-sync' ) ); ?>');">
						<input type="hidden" name="action" value="agend_directory_sync_send_to_agend" />
						<input type="hidden" name="agend_max_records" id="agend_max_records_send" value="" />
						<?php wp_nonce_field( self::NONCE_ACTION_SEND ); ?>
						<?php submit_button( __( 'Send to Agend', 'agend-directory-sync' ), 'primary', 'submit', false ); ?>
					</form>
				</div>

				<script>
					(function () {
						var input = document.getElementById('agend_max_records');
						var mirrors = [
							document.getElementById('agend_max_records_preview'),
							document.getElementById('agend_max_records_send')
						];
						if (!input) return;
						function syncMirrors() {
							mirrors.forEach(function (m) { if (m) { m.value = input.value; } });
						}
						input.addEventListener('input', syncMirrors);
						input.addEventListener('change', syncMirrors);
						syncMirrors();
					})();
				</script>

				<?php self::render_result( $last ); ?>
			</div>
			<?php
		}

		/**
		 * Render whichever result was stashed from the most recent action.
		 *
		 * @param mixed $result
		 */
		private static function render_result( $result ): void {
			if ( ! is_array( $result ) ) {
				return;
			}

			$kind = (string) ( $result['kind'] ?? '' );

			echo '<h3>' . esc_html( self::result_heading( $kind ) ) . '</h3>';

			if ( 'error' === ( $result['status'] ?? '' ) ) {
				echo '<div class="notice notice-error"><p>'
					. esc_html(
						sprintf(
							// translators: %s is the error message.
							__( 'Failed: %s', 'agend-directory-sync' ),
							(string) ( $result['message'] ?? __( 'unknown error', 'agend-directory-sync' ) )
						)
					)
					. '</p></div>';
				return;
			}

			switch ( $kind ) {
				case 'fetch':
					self::render_fetch_result( $result );
					break;
				case 'preview':
					self::render_preview_result( $result );
					break;
				case 'send':
					self::render_send_result( $result );
					break;
			}
		}

		private static function render_fetch_result( array $result ): void {
			$fetched = (int) ( $result['fetched'] ?? 0 );
			$preview = is_array( $result['preview'] ?? null ) ? $result['preview'] : array();

			echo '<p>'
				. esc_html(
					sprintf(
						// translators: 1: total fetched count, 2: preview count.
						_n(
							'Fetched %1$d contact from Upbeat. Showing the first %2$d below.',
							'Fetched %1$d contacts from Upbeat. Showing the first %2$d below.',
							$fetched,
							'agend-directory-sync'
						),
						$fetched,
						count( $preview )
					)
				)
				. '</p>';

			self::render_json_block( $preview );
		}

		private static function render_preview_result( array $result ): void {
			$fetched     = (int) ( $result['fetched'] ?? 0 );
			$transformed = (int) ( $result['transformed'] ?? 0 );
			$skipped     = (int) ( $result['skipped'] ?? 0 );
			$dupes       = (int) ( $result['duplicate_external_ids'] ?? 0 );
			$preview     = is_array( $result['preview'] ?? null ) ? $result['preview'] : array();

			echo '<ul style="list-style:disc;padding-left:1.5em;">';
			echo '<li>' . esc_html( sprintf( __( 'Fetched: %d', 'agend-directory-sync' ), $fetched ) ) . '</li>';
			echo '<li>' . esc_html( sprintf( __( 'Transformed: %d', 'agend-directory-sync' ), $transformed ) ) . '</li>';
			echo '<li>' . esc_html( sprintf( __( 'Skipped: %d', 'agend-directory-sync' ), $skipped ) ) . '</li>';
			if ( $dupes > 0 ) {
				echo '<li>' . esc_html( sprintf( __( 'Duplicate external_ids collapsed: %d', 'agend-directory-sync' ), $dupes ) ) . '</li>';
			}
			echo '</ul>';

			self::render_skip_reasons( $result['skip_reasons'] ?? array() );
			self::render_dropped_fields( $result['dropped_fields'] ?? array() );

			echo '<h4>' . esc_html__( 'Sample of transformed listings', 'agend-directory-sync' ) . '</h4>';
			self::render_json_block( $preview );
		}

		private static function render_send_result( array $result ): void {
			$fetched     = (int) ( $result['fetched'] ?? 0 );
			$transformed = (int) ( $result['transformed'] ?? 0 );
			$skipped     = (int) ( $result['skipped'] ?? 0 );
			$dupes       = (int) ( $result['duplicate_external_ids'] ?? 0 );
			$source      = (string) ( $result['external_source'] ?? '' );
			$send        = is_array( $result['send'] ?? null ) ? $result['send'] : array();

			echo '<ul style="list-style:disc;padding-left:1.5em;">';
			echo '<li>' . esc_html( sprintf( __( 'Fetched: %d', 'agend-directory-sync' ), $fetched ) ) . '</li>';
			echo '<li>' . esc_html( sprintf( __( 'Transformed: %d', 'agend-directory-sync' ), $transformed ) ) . '</li>';
			echo '<li>' . esc_html( sprintf( __( 'Skipped: %d', 'agend-directory-sync' ), $skipped ) ) . '</li>';
			if ( $dupes > 0 ) {
				echo '<li>' . esc_html( sprintf( __( 'Duplicate external_ids collapsed: %d', 'agend-directory-sync' ), $dupes ) ) . '</li>';
			}
			echo '<li>' . esc_html( sprintf( __( 'external_source: %s', 'agend-directory-sync' ), $source ) ) . '</li>';
			$auto_publish_label = ! empty( $result['auto_publish_approved'] )
				? __( 'on', 'agend-directory-sync' )
				: __( 'off', 'agend-directory-sync' );
			echo '<li>' . esc_html( sprintf( __( 'auto_publish_approved: %s', 'agend-directory-sync' ), $auto_publish_label ) ) . '</li>';
			echo '</ul>';

			self::render_skip_reasons( $result['skip_reasons'] ?? array() );
			self::render_dropped_fields( $result['dropped_fields'] ?? array() );

			if ( empty( $send ) ) {
				echo '<div class="notice notice-warning inline"><p>'
					. esc_html__( 'No listings were sent to Agend (nothing to upload after filtering).', 'agend-directory-sync' )
					. '</p></div>';
				return;
			}

			$created     = (int) ( $send['created'] ?? 0 );
			$updated     = (int) ( $send['updated'] ?? 0 );
			$errored     = (int) ( $send['errored'] ?? 0 );
			$batches     = (int) ( $send['batches'] ?? 0 );
			$url         = (string) ( $send['url'] ?? '' );
			$examples    = is_array( $send['error_examples'] ?? null ) ? $send['error_examples'] : array();
			$http_errors = is_array( $send['http_errors'] ?? null ) ? $send['http_errors'] : array();

			echo '<h4>' . esc_html__( 'Agend bulk-upsert result', 'agend-directory-sync' ) . '</h4>';
			echo '<p><code>' . esc_html( $url ) . '</code></p>';
			echo '<ul style="list-style:disc;padding-left:1.5em;">';
			echo '<li>' . esc_html( sprintf( __( 'Batches: %d', 'agend-directory-sync' ), $batches ) ) . '</li>';
			echo '<li>' . esc_html( sprintf( __( 'Created: %d', 'agend-directory-sync' ), $created ) ) . '</li>';
			echo '<li>' . esc_html( sprintf( __( 'Updated: %d', 'agend-directory-sync' ), $updated ) ) . '</li>';
			echo '<li>' . esc_html( sprintf( __( 'Errored: %d', 'agend-directory-sync' ), $errored ) ) . '</li>';
			echo '</ul>';

			if ( ! empty( $examples ) ) {
				echo '<h4>' . esc_html__( 'Per-row error examples', 'agend-directory-sync' ) . '</h4>';
				echo '<table class="widefat striped"><thead><tr>'
					. '<th>' . esc_html__( 'Batch', 'agend-directory-sync' ) . '</th>'
					. '<th>' . esc_html__( 'external_id', 'agend-directory-sync' ) . '</th>'
					. '<th>' . esc_html__( 'code', 'agend-directory-sync' ) . '</th>'
					. '<th>' . esc_html__( 'message', 'agend-directory-sync' ) . '</th>'
					. '</tr></thead><tbody>';
				foreach ( $examples as $row ) {
					echo '<tr>'
						. '<td>' . esc_html( (string) ( $row['batch_index'] ?? '' ) ) . '</td>'
						. '<td>' . esc_html( (string) ( $row['external_id'] ?? '' ) ) . '</td>'
						. '<td>' . esc_html( (string) ( $row['code'] ?? '' ) ) . '</td>'
						. '<td>' . esc_html( (string) ( $row['message'] ?? '' ) ) . '</td>'
						. '</tr>';
				}
				echo '</tbody></table>';
			}

			if ( ! empty( $http_errors ) ) {
				echo '<h4>' . esc_html__( 'Batch-level HTTP errors', 'agend-directory-sync' ) . '</h4>';
				self::render_json_block( $http_errors );
			}
		}

		private static function render_skip_reasons( $skip_reasons ): void {
			if ( ! is_array( $skip_reasons ) || empty( $skip_reasons ) ) {
				return;
			}

			echo '<h4>' . esc_html__( 'Skip reasons', 'agend-directory-sync' ) . '</h4>';
			echo '<ul style="list-style:disc;padding-left:1.5em;">';
			foreach ( $skip_reasons as $reason => $count ) {
				echo '<li>'
					. '<code>' . esc_html( (string) $reason ) . '</code>: '
					. esc_html( (string) (int) $count )
					. '</li>';
			}
			echo '</ul>';
		}

		/**
		 * Render the dropped-field counters. These are rows that were
		 * synced, but with a specific field omitted because it violated
		 * a hard constraint (e.g. phone > 50 chars).
		 */
		private static function render_dropped_fields( $dropped_fields ): void {
			if ( ! is_array( $dropped_fields ) || empty( $dropped_fields ) ) {
				return;
			}

			echo '<h4>' . esc_html__( 'Dropped fields', 'agend-directory-sync' ) . '</h4>';
			echo '<p class="description">'
				. esc_html__( 'These rows were still synced, but the named field was omitted because it failed a hard constraint.', 'agend-directory-sync' )
				. '</p>';
			echo '<ul style="list-style:disc;padding-left:1.5em;">';
			foreach ( $dropped_fields as $reason => $count ) {
				echo '<li>'
					. '<code>' . esc_html( (string) $reason ) . '</code>: '
					. esc_html( (string) (int) $count )
					. '</li>';
			}
			echo '</ul>';
		}

		private static function render_json_block( $value ): void {
			$json = wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			echo '<pre style="background:#f6f7f7;border:1px solid #c3c4c7;padding:1em;max-height:600px;overflow:auto;">'
				. esc_html( (string) $json )
				. '</pre>';
		}

		private static function result_heading( string $kind ): string {
			switch ( $kind ) {
				case 'fetch':
					return __( 'Last Upbeat fetch', 'agend-directory-sync' );
				case 'preview':
					return __( 'Last transform preview', 'agend-directory-sync' );
				case 'send':
					return __( 'Last send to Agend', 'agend-directory-sync' );
			}
			return __( 'Last result', 'agend-directory-sync' );
		}

		/**
		 * Read the max-records cap from POST. 0 or missing means no cap.
		 */
		private static function read_max_records(): int {
			$raw = $_POST['agend_max_records'] ?? '';
			if ( ! is_scalar( $raw ) ) {
				return 0;
			}
			$value = (int) $raw;
			return $value > 0 ? $value : 0;
		}

		/**
		 * @param array<int, array<string, mixed>> $contacts
		 *
		 * @return array<int, array<string, mixed>>
		 */
		private static function cap( array $contacts, int $max_records ): array {
			if ( $max_records <= 0 ) {
				return $contacts;
			}
			return array_slice( $contacts, 0, $max_records );
		}

		private static function resolve_external_source(): string {
			$value = trim( (string) get_option( Agend_Directory_Sync::OPTION_EXTERNAL_SOURCE, '' ) );
			return '' !== $value ? $value : Agend_Directory_Sync::DEFAULT_EXTERNAL_SOURCE;
		}

		/**
		 * Resolve the auto-publish setting, applying the bootstrap default
		 * if the option has never been saved.
		 */
		private static function resolve_auto_publish_approved(): bool {
			$raw = get_option( Agend_Directory_Sync::OPTION_AUTO_PUBLISH_APPROVED, null );
			if ( null === $raw ) {
				return Agend_Directory_Sync::DEFAULT_AUTO_PUBLISH_APPROVED;
			}
			return '1' === (string) $raw;
		}

		private static function set_result( int $user_id, array $payload ): void {
			$payload['ran_at'] = current_time( 'mysql' );
			set_transient( self::transient_key( $user_id ), $payload, MINUTE_IN_SECONDS * 30 );
		}

		private static function redirect_url( array $args = array() ): string {
			$base = add_query_arg(
				array( 'page' => self::MENU_SLUG ),
				admin_url( 'tools.php' )
			);

			return add_query_arg( $args, $base );
		}

		private static function transient_key( int $user_id ): string {
			return 'agend_directory_sync_last_' . $user_id;
		}

		private static function assert_can(): void {
			if ( ! current_user_can( self::CAPABILITY ) ) {
				wp_die( esc_html__( 'You do not have permission to do this.', 'agend-directory-sync' ) );
			}
		}
	}
endif;
