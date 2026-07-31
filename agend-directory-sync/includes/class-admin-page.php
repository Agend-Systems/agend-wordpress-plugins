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
 * Preview and Send both delegate to Agend_Directory_Sync_Runner so the manual
 * and the scheduled (WP-CLI / server cron) paths share one pipeline.
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

			$external_source = isset( $_POST['agend_external_source'] ) ? sanitize_text_field( wp_unslash( $_POST['agend_external_source'] ) ) : '';
			$auto_publish    = ! empty( $_POST['agend_auto_publish_approved'] ) ? '1' : '0';
			$upbeat_endpoint = isset( $_POST['agend_upbeat_endpoint'] ) ? sanitize_text_field( wp_unslash( $_POST['agend_upbeat_endpoint'] ) ) : '';
			$posted_source   = isset( $_POST['agend_directory_sync_source'] ) ? sanitize_key( wp_unslash( $_POST['agend_directory_sync_source'] ) ) : '';
			$raw_http_api    = isset( $_POST['agend_http_api'] ) && is_array( $_POST['agend_http_api'] )
				? wp_unslash( $_POST['agend_http_api'] )
				: array();

			// external_source is the upsert identity key and is never
			// defaulted per source: changing the data source never touches
			// it (SPEC-DIR-20260731 US-1.2 business rule).
			update_option( Agend_Directory_Sync::OPTION_EXTERNAL_SOURCE, $external_source );
			update_option( Agend_Directory_Sync::OPTION_AUTO_PUBLISH_APPROVED, $auto_publish );

			// Only persist a source key that is actually registered; an
			// unknown or blank posted value is dropped so the registry's
			// unset/unknown fallback (`upbeat`) applies (US-1.1 criterion 2).
			$registered_sources = Agend_Directory_Sync_Source_Registry::all();
			if ( isset( $registered_sources[ $posted_source ] ) ) {
				update_option( Agend_Directory_Sync::OPTION_SOURCE, $posted_source );
			}

			// Gateway URL and API key are owned by agend-apps-core, not stored here.
			update_option( Agend_Directory_Sync::OPTION_UPBEAT_ENDPOINT, $upbeat_endpoint );

			// Custom HTTP API settings are sanitised as a single unit by the
			// source itself (SPEC-DIR-20260731 US-2.4 criterion 2); secrets
			// are never part of this array (Decision 2.4).
			update_option(
				Agend_Directory_Sync::OPTION_HTTP_API,
				Agend_Directory_Sync_Http_Api_Source::sanitize_settings( $raw_http_api )
			);

			// Persist the configurable field mapping. The core map arrives as an
			// array of source-field names keyed by Agend target; the custom
			// fields arrive as a `target = source` textarea; the location slots
			// arrive as an array of slot => field => source.
			$raw_core      = isset( $_POST['agend_field_map'] ) && is_array( $_POST['agend_field_map'] )
				? wp_unslash( $_POST['agend_field_map'] )
				: array();
			$raw_custom    = isset( $_POST['agend_custom_field_map'] )
				? wp_unslash( $_POST['agend_custom_field_map'] )
				: '';
			$raw_locations = isset( $_POST['agend_location_map'] ) && is_array( $_POST['agend_location_map'] )
				? wp_unslash( $_POST['agend_location_map'] )
				: array();
			Agend_Directory_Sync_Field_Map::save( $raw_core, $raw_custom, $raw_locations );

			wp_safe_redirect( self::redirect_url( array( 'saved' => '1' ) ) );
			exit;
		}

		/**
		 * Fetch from the active source and stash a preview of the raw
		 * response. Works against whichever source is selected
		 * (SPEC-DIR-20260731 US-1.2 criterion 4).
		 */
		public static function handle_run_fetch(): void {
			self::assert_can();
			check_admin_referer( self::NONCE_ACTION_FETCH );

			$user_id = get_current_user_id();

			try {
				$source = Agend_Directory_Sync_Source_Registry::active();

				if ( ! $source->is_available() ) {
					throw new RuntimeException( $source->get_unavailable_reason() );
				}

				// The Custom HTTP API source gets a dedicated first-page-only
				// response-model preview (raw envelope + resolved path) rather
				// than the generic fetch-everything preview below (US-2.4):
				// iterating a response data path against a live, possibly
				// multi-hundred-page API should not require a full fetch_all()
				// on every attempt.
				if ( $source instanceof Agend_Directory_Sync_Http_Api_Source ) {
					$preview = $source->preview_first_page();

					self::set_result(
						$user_id,
						array_merge(
							array(
								'kind'   => 'fetch',
								'status' => 'ok',
								'source' => $source->get_key(),
							),
							$preview
						)
					);
				} else {
					$results = $source->fetch_all();

					self::set_result(
						$user_id,
						array(
							'kind'    => 'fetch',
							'status'  => 'ok',
							'source'  => $source->get_key(),
							'fetched' => count( $results ),
							'preview' => array_slice( $results, 0, self::FETCH_PREVIEW_LIMIT ),
						)
					);
				}
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
		 * Fetch + transform + render. Does NOT POST anything. Delegates to the
		 * shared runner in dry-run mode.
		 */
		public static function handle_preview_transform(): void {
			self::assert_can();
			check_admin_referer( self::NONCE_ACTION_PREVIEW );

			$user_id     = get_current_user_id();
			$max_records = self::read_max_records();

			try {
				$result = Agend_Directory_Sync_Runner::run( $max_records, true );

				// Keep the transient small: store only a sample, not the full
				// transformed payload.
				$listings           = is_array( $result['listings'] ?? null ) ? $result['listings'] : array();
				$result['preview']  = array_slice( $listings, 0, self::TRANSFORM_PREVIEW_LIMIT );
				unset( $result['listings'] );

				self::set_result( $user_id, $result );
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
		 * counts across all batches. Delegates to the shared runner.
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

			$user_id     = get_current_user_id();
			$max_records = self::read_max_records();

			try {
				$result = Agend_Directory_Sync_Runner::run( $max_records, false );

				// The full transformed payload is not needed for rendering.
				unset( $result['listings'] );

				self::set_result( $user_id, $result );
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

			$external_source      = Agend_Directory_Sync_Runner::resolve_external_source();
			$auto_publish         = Agend_Directory_Sync_Runner::resolve_auto_publish_approved();
			$upbeat_endpoint      = (string) get_option( Agend_Directory_Sync::OPTION_UPBEAT_ENDPOINT, '' );
			$http_api             = Agend_Directory_Sync_Http_Api_Source::resolve_settings();
			$http_token_defined   = defined( 'AGEND_DIRECTORY_SYNC_HTTP_TOKEN' );
			$oauth_secret_defined = defined( 'AGEND_DIRECTORY_SYNC_OAUTH_CLIENT_SECRET' );
			$field_map            = Agend_Directory_Sync_Field_Map::resolve();

			$registered_sources = Agend_Directory_Sync_Source_Registry::all();
			$active_source      = Agend_Directory_Sync_Source_Registry::active();
			$active_source_key  = $active_source->get_key();
			$source_unavailable = ! $active_source->is_available();

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
								<td colspan="2">
									<p class="description">
										<?php esc_html_e( 'Gateway connection (base URL and API key) is configured in the Agend Apps Core plugin, under Settings > Agend Apps. This plugin uses that connection.', 'agend-directory-sync' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="agend_directory_sync_source"><?php esc_html_e( 'Data source', 'agend-directory-sync' ); ?></label>
								</th>
								<td>
									<select name="agend_directory_sync_source" id="agend_directory_sync_source">
										<?php foreach ( $registered_sources as $source_key => $source ) : ?>
											<option value="<?php echo esc_attr( $source_key ); ?>" <?php selected( $active_source_key, $source_key ); ?>>
												<?php echo esc_html( $source->get_label() ); ?>
											</option>
										<?php endforeach; ?>
									</select>
									<p class="description">
										<?php esc_html_e( 'Which system the sync fetches directory contacts from. Settings below apply only to the selected source.', 'agend-directory-sync' ); ?>
									</p>
									<?php if ( $source_unavailable ) : ?>
										<p class="description" style="color:#b32d2e;">
											<?php
											printf(
												/* translators: %s is the reason the source is unavailable. */
												esc_html__( 'Unavailable: %s Fix this before running a sync — the "Run source fetch" and "Send to Agend" actions are disabled until then.', 'agend-directory-sync' ),
												esc_html( $active_source->get_unavailable_reason() )
											);
											?>
										</p>
									<?php endif; ?>
								</td>
							</tr>
						</tbody>
					</table>

					<?php foreach ( $registered_sources as $source_key => $source ) : ?>
						<div
							class="agend-directory-sync-source-section"
							data-agend-source-key="<?php echo esc_attr( $source_key ); ?>"
							style="<?php echo esc_attr( $source_key === $active_source_key ? '' : 'display:none;' ); ?>"
						>
							<h2><?php echo esc_html( $source->get_label() ); ?></h2>

							<?php if ( ! $source->is_available() ) : ?>
								<div class="notice notice-warning inline">
									<p><?php echo esc_html( $source->get_unavailable_reason() ); ?></p>
								</div>
							<?php endif; ?>

							<?php if ( Agend_Directory_Sync_Upbeat_Client::SOURCE_KEY === $source_key ) : ?>
								<table class="form-table" role="presentation">
									<tbody>
										<tr>
											<th scope="row">
												<label for="agend_upbeat_endpoint"><?php esc_html_e( 'Upbeat directory endpoint', 'agend-directory-sync' ); ?></label>
											</th>
											<td>
												<input
													name="agend_upbeat_endpoint"
													id="agend_upbeat_endpoint"
													type="text"
													class="regular-text"
													value="<?php echo esc_attr( $upbeat_endpoint ); ?>"
													placeholder="membershipDirectoryContacts"
													autocomplete="off"
												/>
												<p class="description">
													<?php esc_html_e( 'Upbeat endpoint path for the member directory. Varies per client. Leave blank to use the default "membershipDirectoryContacts".', 'agend-directory-sync' ); ?>
												</p>
											</td>
										</tr>
									</tbody>
								</table>
							<?php elseif ( Agend_Directory_Sync_Http_Api_Source::SOURCE_KEY === $source_key ) : ?>
								<table class="form-table" role="presentation">
									<tbody>
										<tr>
											<th scope="row">
												<label for="agend_http_api_url"><?php esc_html_e( 'URL', 'agend-directory-sync' ); ?></label>
											</th>
											<td>
												<input
													name="agend_http_api[url]"
													id="agend_http_api_url"
													type="text"
													class="regular-text code"
													value="<?php echo esc_attr( $http_api['url'] ); ?>"
													placeholder="https://api.example.com/v1/directory"
													autocomplete="off"
												/>
												<p class="description">
													<?php esc_html_e( 'Absolute HTTPS URL fetched with wp_remote_get(). HTTP (non-TLS) URLs are rejected on save unless this site\'s environment type is local or development.', 'agend-directory-sync' ); ?>
												</p>
											</td>
										</tr>
										<tr>
											<th scope="row">
												<label for="agend_http_api_timeout"><?php esc_html_e( 'Request timeout (seconds)', 'agend-directory-sync' ); ?></label>
											</th>
											<td>
												<input
													name="agend_http_api[timeout]"
													id="agend_http_api_timeout"
													type="number"
													min="<?php echo esc_attr( (string) Agend_Directory_Sync_Http_Api_Source::MIN_TIMEOUT ); ?>"
													max="<?php echo esc_attr( (string) Agend_Directory_Sync_Http_Api_Source::MAX_TIMEOUT ); ?>"
													step="1"
													class="small-text"
													value="<?php echo esc_attr( (string) $http_api['timeout'] ); ?>"
												/>
												<p class="description">
													<?php
													printf(
														/* translators: 1: minimum allowed timeout, 2: maximum allowed timeout, 3: default timeout. */
														esc_html__( 'How long to wait for each request before failing. Clamped between %1$d and %2$d seconds. Defaults to %3$d.', 'agend-directory-sync' ),
														(int) Agend_Directory_Sync_Http_Api_Source::MIN_TIMEOUT,
														(int) Agend_Directory_Sync_Http_Api_Source::MAX_TIMEOUT,
														(int) Agend_Directory_Sync_Http_Api_Source::DEFAULT_TIMEOUT
													);
													?>
												</p>
											</td>
										</tr>
										<tr>
											<th scope="row">
												<label for="agend_http_api_data_path"><?php esc_html_e( 'Response data path', 'agend-directory-sync' ); ?></label>
											</th>
											<td>
												<input
													name="agend_http_api[data_path]"
													id="agend_http_api_data_path"
													type="text"
													class="regular-text code"
													value="<?php echo esc_attr( $http_api['data_path'] ); ?>"
													placeholder="data.results"
													autocomplete="off"
												/>
												<p class="description">
													<?php esc_html_e( 'Dot-path to the records array within each page\'s decoded JSON response, e.g. "data.results". Leave blank when the response root itself is the array. Use the raw fetch below to check this against the live API before running a sync.', 'agend-directory-sync' ); ?>
												</p>
											</td>
										</tr>
										<tr>
											<th scope="row">
												<label for="agend_http_api_auth_mode"><?php esc_html_e( 'Authentication', 'agend-directory-sync' ); ?></label>
											</th>
											<td>
												<select name="agend_http_api[auth_mode]" id="agend_http_api_auth_mode">
													<option value="<?php echo esc_attr( Agend_Directory_Sync_Http_Api_Source::AUTH_NONE ); ?>" <?php selected( $http_api['auth_mode'], Agend_Directory_Sync_Http_Api_Source::AUTH_NONE ); ?>><?php esc_html_e( 'None', 'agend-directory-sync' ); ?></option>
													<option value="<?php echo esc_attr( Agend_Directory_Sync_Http_Api_Source::AUTH_TOKEN ); ?>" <?php selected( $http_api['auth_mode'], Agend_Directory_Sync_Http_Api_Source::AUTH_TOKEN ); ?>><?php esc_html_e( 'Static token header', 'agend-directory-sync' ); ?></option>
													<option value="<?php echo esc_attr( Agend_Directory_Sync_Http_Api_Source::AUTH_OAUTH ); ?>" <?php selected( $http_api['auth_mode'], Agend_Directory_Sync_Http_Api_Source::AUTH_OAUTH ); ?>><?php esc_html_e( 'OAuth 2.0 client credentials', 'agend-directory-sync' ); ?></option>
												</select>
												<p class="description">
													<?php esc_html_e( 'How the sync authenticates to the URL above. Secrets are never stored here — see the wp-config.php constants named below.', 'agend-directory-sync' ); ?>
												</p>
											</td>
										</tr>
										<tr>
											<th scope="row"><?php esc_html_e( 'Static token settings', 'agend-directory-sync' ); ?></th>
											<td>
												<p>
													<label for="agend_http_api_token_header"><?php esc_html_e( 'Header name', 'agend-directory-sync' ); ?></label><br />
													<input
														name="agend_http_api[token_header]"
														id="agend_http_api_token_header"
														type="text"
														class="regular-text"
														value="<?php echo esc_attr( $http_api['token_header'] ); ?>"
														placeholder="Authorization"
														autocomplete="off"
													/>
												</p>
												<p>
													<label for="agend_http_api_token_template"><?php esc_html_e( 'Header value template', 'agend-directory-sync' ); ?></label><br />
													<input
														name="agend_http_api[token_template]"
														id="agend_http_api_token_template"
														type="text"
														class="regular-text code"
														value="<?php echo esc_attr( $http_api['token_template'] ); ?>"
														placeholder="Bearer %s"
														autocomplete="off"
													/>
												</p>
												<p class="description">
													<?php esc_html_e( 'Used only in "Static token header" mode. The "%s" in the header value template above is replaced with the token value. The token value itself comes only from the wp-config.php constant AGEND_DIRECTORY_SYNC_HTTP_TOKEN.', 'agend-directory-sync' ); ?>
												</p>
												<p class="description">
													<?php echo esc_html__( 'AGEND_DIRECTORY_SYNC_HTTP_TOKEN constant:', 'agend-directory-sync' ) . ' '; ?>
													<?php if ( $http_token_defined ) : ?>
														<strong><?php esc_html_e( 'defined', 'agend-directory-sync' ); ?></strong>
													<?php else : ?>
														<strong style="color:#b32d2e;"><?php esc_html_e( 'not defined', 'agend-directory-sync' ); ?></strong>
													<?php endif; ?>
												</p>
											</td>
										</tr>
										<tr>
											<th scope="row"><?php esc_html_e( 'OAuth client credentials settings', 'agend-directory-sync' ); ?></th>
											<td>
												<p>
													<label for="agend_http_api_oauth_token_url"><?php esc_html_e( 'Token endpoint URL', 'agend-directory-sync' ); ?></label><br />
													<input
														name="agend_http_api[oauth_token_url]"
														id="agend_http_api_oauth_token_url"
														type="text"
														class="regular-text code"
														value="<?php echo esc_attr( $http_api['oauth_token_url'] ); ?>"
														placeholder="https://api.example.com/oauth/token"
														autocomplete="off"
													/>
												</p>
												<p>
													<label for="agend_http_api_oauth_client_id"><?php esc_html_e( 'Client ID', 'agend-directory-sync' ); ?></label><br />
													<input
														name="agend_http_api[oauth_client_id]"
														id="agend_http_api_oauth_client_id"
														type="text"
														class="regular-text"
														value="<?php echo esc_attr( $http_api['oauth_client_id'] ); ?>"
														autocomplete="off"
													/>
												</p>
												<p>
													<label for="agend_http_api_oauth_scope"><?php esc_html_e( 'Scope (optional)', 'agend-directory-sync' ); ?></label><br />
													<input
														name="agend_http_api[oauth_scope]"
														id="agend_http_api_oauth_scope"
														type="text"
														class="regular-text"
														value="<?php echo esc_attr( $http_api['oauth_scope'] ); ?>"
														autocomplete="off"
													/>
												</p>
												<p class="description">
													<?php esc_html_e( 'Used only in "OAuth 2.0 client credentials" mode. A client_credentials token request is made with HTTP Basic auth (client ID + secret); scope is sent only when non-blank. The access token is cached until shortly before it expires.', 'agend-directory-sync' ); ?>
												</p>
												<p class="description">
													<?php echo esc_html__( 'AGEND_DIRECTORY_SYNC_OAUTH_CLIENT_SECRET constant:', 'agend-directory-sync' ) . ' '; ?>
													<?php if ( $oauth_secret_defined ) : ?>
														<strong><?php esc_html_e( 'defined', 'agend-directory-sync' ); ?></strong>
													<?php else : ?>
														<strong style="color:#b32d2e;"><?php esc_html_e( 'not defined', 'agend-directory-sync' ); ?></strong>
													<?php endif; ?>
												</p>
											</td>
										</tr>
										<tr>
											<th scope="row">
												<label for="agend_http_api_pagination_mode"><?php esc_html_e( 'Pagination', 'agend-directory-sync' ); ?></label>
											</th>
											<td>
												<select name="agend_http_api[pagination_mode]" id="agend_http_api_pagination_mode">
													<option value="<?php echo esc_attr( Agend_Directory_Sync_Http_Api_Source::PAGINATION_NONE ); ?>" <?php selected( $http_api['pagination_mode'], Agend_Directory_Sync_Http_Api_Source::PAGINATION_NONE ); ?>><?php esc_html_e( 'None (single request)', 'agend-directory-sync' ); ?></option>
													<option value="<?php echo esc_attr( Agend_Directory_Sync_Http_Api_Source::PAGINATION_PAGE ); ?>" <?php selected( $http_api['pagination_mode'], Agend_Directory_Sync_Http_Api_Source::PAGINATION_PAGE ); ?>><?php esc_html_e( 'Page number', 'agend-directory-sync' ); ?></option>
													<option value="<?php echo esc_attr( Agend_Directory_Sync_Http_Api_Source::PAGINATION_OFFSET ); ?>" <?php selected( $http_api['pagination_mode'], Agend_Directory_Sync_Http_Api_Source::PAGINATION_OFFSET ); ?>><?php esc_html_e( 'Offset / limit', 'agend-directory-sync' ); ?></option>
												</select>
												<p class="description">
													<?php
													printf(
														/* translators: %d: the hard page-count safety cap. */
														esc_html__( 'How the API pages results. Fetching stops on an empty page, a short page, or the has-more path below resolving to false. Reaching %d pages without stopping fails the run rather than returning a truncated set.', 'agend-directory-sync' ),
														(int) Agend_Directory_Sync_Http_Api_Source::MAX_PAGES
													);
													?>
												</p>
											</td>
										</tr>
										<tr>
											<th scope="row"><?php esc_html_e( 'Page-number pagination settings', 'agend-directory-sync' ); ?></th>
											<td>
												<p>
													<label for="agend_http_api_page_param"><?php esc_html_e( 'Page parameter name', 'agend-directory-sync' ); ?></label>
													<input name="agend_http_api[page_param]" id="agend_http_api_page_param" type="text" class="small-text" value="<?php echo esc_attr( $http_api['page_param'] ); ?>" placeholder="page" autocomplete="off" />
												</p>
												<p>
													<label for="agend_http_api_page_size_param"><?php esc_html_e( 'Page-size parameter name', 'agend-directory-sync' ); ?></label>
													<input name="agend_http_api[page_size_param]" id="agend_http_api_page_size_param" type="text" class="small-text" value="<?php echo esc_attr( $http_api['page_size_param'] ); ?>" placeholder="per_page" autocomplete="off" />
												</p>
												<p>
													<label for="agend_http_api_page_size"><?php esc_html_e( 'Page size', 'agend-directory-sync' ); ?></label>
													<input name="agend_http_api[page_size]" id="agend_http_api_page_size" type="number" min="<?php echo esc_attr( (string) Agend_Directory_Sync_Http_Api_Source::MIN_PAGE_SIZE ); ?>" max="<?php echo esc_attr( (string) Agend_Directory_Sync_Http_Api_Source::MAX_PAGE_SIZE ); ?>" class="small-text" value="<?php echo esc_attr( (string) $http_api['page_size'] ); ?>" />
												</p>
												<p>
													<label for="agend_http_api_first_page"><?php esc_html_e( 'First page number', 'agend-directory-sync' ); ?></label>
													<input name="agend_http_api[first_page]" id="agend_http_api_first_page" type="number" min="0" class="small-text" value="<?php echo esc_attr( (string) $http_api['first_page'] ); ?>" />
												</p>
												<p class="description"><?php esc_html_e( 'Used only in "Page number" pagination mode.', 'agend-directory-sync' ); ?></p>
											</td>
										</tr>
										<tr>
											<th scope="row"><?php esc_html_e( 'Offset / limit pagination settings', 'agend-directory-sync' ); ?></th>
											<td>
												<p>
													<label for="agend_http_api_offset_param"><?php esc_html_e( 'Offset parameter name', 'agend-directory-sync' ); ?></label>
													<input name="agend_http_api[offset_param]" id="agend_http_api_offset_param" type="text" class="small-text" value="<?php echo esc_attr( $http_api['offset_param'] ); ?>" placeholder="offset" autocomplete="off" />
												</p>
												<p>
													<label for="agend_http_api_limit_param"><?php esc_html_e( 'Limit parameter name', 'agend-directory-sync' ); ?></label>
													<input name="agend_http_api[limit_param]" id="agend_http_api_limit_param" type="text" class="small-text" value="<?php echo esc_attr( $http_api['limit_param'] ); ?>" placeholder="limit" autocomplete="off" />
												</p>
												<p class="description"><?php esc_html_e( 'Used only in "Offset / limit" pagination mode. The same page size setting above is sent as the limit.', 'agend-directory-sync' ); ?></p>
											</td>
										</tr>
										<tr>
											<th scope="row">
												<label for="agend_http_api_has_more_path"><?php esc_html_e( 'Has-more path (optional)', 'agend-directory-sync' ); ?></label>
											</th>
											<td>
												<input
													name="agend_http_api[has_more_path]"
													id="agend_http_api_has_more_path"
													type="text"
													class="regular-text code"
													value="<?php echo esc_attr( $http_api['has_more_path'] ); ?>"
													placeholder="meta.has_more"
													autocomplete="off"
												/>
												<p class="description">
													<?php esc_html_e( 'Optional dot-path resolved against each page\'s response; pagination stops early when this resolves to boolean false. Leave blank if the API has no such flag (the empty/short-page checks still apply).', 'agend-directory-sync' ); ?>
												</p>
											</td>
										</tr>
									</tbody>
								</table>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>

					<table class="form-table" role="presentation">
						<tbody>
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
										<?php
										printf(
											/* translators: %s is the default external_source value. */
											esc_html__( 'Caller identifier sent with each bulk-upsert batch, and part of the upsert key (external_source + external_id). Keep it stable for a given directory. Defaults to "%s" if left blank.', 'agend-directory-sync' ),
											esc_html( Agend_Directory_Sync::DEFAULT_EXTERNAL_SOURCE )
										);
										?>
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
										<?php esc_html_e( 'Approved status is assigned automatically when the member is both eligible AND opted in; otherwise the listing is synced as suspended and stays hidden. When this checkbox is off, even approved listings stay invisible until an admin publishes them manually in the Agend dashboard. Already-published rows are never re-stamped on re-sync.', 'agend-directory-sync' ); ?>
									</p>
								</td>
							</tr>
						</tbody>
					</table>

					<h2><?php esc_html_e( 'Field mapping', 'agend-directory-sync' ); ?></h2>
					<p class="description" style="max-width:760px;">
						<?php esc_html_e( 'Map each Agend listing field to a source field from your environment. Defaults are intentionally blank — configure the mapping for this client. Leave a source blank to omit that field. Use "Preview transform" after changing the mapping to verify before sending.', 'agend-directory-sync' ); ?>
					</p>
					<p class="description" style="max-width:760px;">
						<?php
						esc_html_e(
							'A source field can be a nested path: separate each level with a dot, for example "contact.email" or "addresses.0.suburb" (a purely numeric segment indexes a list item). If your source has a field whose literal name already contains a dot, an exact match on that full name is tried first, so an existing mapping keeps working unchanged.',
							'agend-directory-sync'
						);
						?>
					</p>

					<table class="form-table" role="presentation">
						<tbody>
							<?php foreach ( Agend_Directory_Sync_Field_Map::core_targets() as $target ) : ?>
								<?php
								$key          = $target['key'];
								$input_id     = 'agend_field_map_' . $key;
								$current      = isset( $field_map['core'][ $key ] ) ? (string) $field_map['core'][ $key ] : '';
								$default_core = Agend_Directory_Sync_Field_Map::default_core_map();
								$placeholder  = isset( $default_core[ $key ] ) ? (string) $default_core[ $key ] : '';
								?>
								<tr>
									<th scope="row">
										<label for="<?php echo esc_attr( $input_id ); ?>"><?php echo esc_html( $target['label'] ); ?></label>
									</th>
									<td>
										<input
											name="agend_field_map[<?php echo esc_attr( $key ); ?>]"
											id="<?php echo esc_attr( $input_id ); ?>"
											type="text"
											class="regular-text"
											value="<?php echo esc_attr( $current ); ?>"
											placeholder="<?php echo esc_attr( $placeholder ); ?>"
											autocomplete="off"
										/>
										<p class="description"><?php echo esc_html( $target['description'] ); ?></p>
									</td>
								</tr>
							<?php endforeach; ?>
							<tr>
								<th scope="row">
									<label for="agend_custom_field_map"><?php esc_html_e( 'Custom fields', 'agend-directory-sync' ); ?></label>
								</th>
								<td>
									<textarea
										name="agend_custom_field_map"
										id="agend_custom_field_map"
										rows="9"
										class="large-text code"
										placeholder="membership_number = membershipNumber"
									><?php echo esc_textarea( Agend_Directory_Sync_Field_Map::custom_fields_to_textarea( $field_map['custom_fields'] ) ); ?></textarea>
									<p class="description">
										<?php esc_html_e( 'One mapping per line, in the form custom_field_key = source_field. The key is stored under the listing custom_fields. Lines without an "=" are ignored.', 'agend-directory-sync' ); ?>
									</p>
								</td>
							</tr>
						</tbody>
					</table>

					<h2><?php esc_html_e( 'Address mapping', 'agend-directory-sync' ); ?></h2>
					<p class="description" style="max-width:760px;">
						<?php esc_html_e( 'Map one or more addresses to the listing. Each configured location becomes a listing address; the first with data is the primary. Leave a location blank to omit it.', 'agend-directory-sync' ); ?>
					</p>
					<?php $als_loc_fields = Agend_Directory_Sync_Field_Map::location_field_keys(); ?>
					<?php foreach ( ( $field_map['locations'] ?? array() ) as $loc_index => $loc_slot ) : ?>
						<h3>
							<?php
							printf(
								/* translators: %d is the location slot number. */
								esc_html__( 'Location %d', 'agend-directory-sync' ),
								(int) $loc_index + 1
							);
							?>
							<?php if ( 0 === (int) $loc_index ) : ?>
								<span class="description">(<?php esc_html_e( 'primary', 'agend-directory-sync' ); ?>)</span>
							<?php endif; ?>
						</h3>
						<table class="form-table" role="presentation">
							<tbody>
								<tr>
									<th scope="row"><?php esc_html_e( 'Label', 'agend-directory-sync' ); ?></th>
									<td>
										<input
											name="agend_location_map[<?php echo (int) $loc_index; ?>][label]"
											type="text"
											class="regular-text"
											value="<?php echo esc_attr( (string) ( $loc_slot['label'] ?? '' ) ); ?>"
											placeholder="<?php esc_attr_e( 'e.g. Business, Residential', 'agend-directory-sync' ); ?>"
											autocomplete="off"
										/>
										<p class="description"><?php esc_html_e( 'Static name for this address (stored as the location name). Not a source field.', 'agend-directory-sync' ); ?></p>
									</td>
								</tr>
								<?php foreach ( $als_loc_fields as $loc_field ) : ?>
									<tr>
										<th scope="row"><?php echo esc_html( $loc_field ); ?></th>
										<td>
											<input
												name="agend_location_map[<?php echo (int) $loc_index; ?>][<?php echo esc_attr( $loc_field ); ?>]"
												type="text"
												class="regular-text"
												value="<?php echo esc_attr( (string) ( $loc_slot[ $loc_field ] ?? '' ) ); ?>"
												autocomplete="off"
											/>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endforeach; ?>

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
									<?php esc_html_e( 'Optional cap, applied after fetching from the selected source but before transforming. Leave blank to process all records.', 'agend-directory-sync' ); ?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>

				<?php if ( $source_unavailable ) : ?>
					<div class="notice notice-warning inline">
						<p>
							<?php
							printf(
								/* translators: %s is the reason the active source is unavailable. */
								esc_html__( 'The selected data source is unavailable: %s "Run source fetch" and "Send to Agend" are disabled until this is fixed.', 'agend-directory-sync' ),
								esc_html( $active_source->get_unavailable_reason() )
							);
							?>
						</p>
					</div>
				<?php endif; ?>

				<div id="agend-directory-sync-actions" style="display:flex;flex-wrap:wrap;gap:1em;align-items:flex-start;">
					<form method="post" action="<?php echo $action_url; ?>" style="margin:0;">
						<input type="hidden" name="action" value="agend_directory_sync_run_sync" />
						<?php wp_nonce_field( self::NONCE_ACTION_FETCH ); ?>
						<?php submit_button( __( 'Run source fetch', 'agend-directory-sync' ), 'secondary', 'submit', false, $source_unavailable ? array( 'disabled' => 'disabled' ) : array() ); ?>
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
						<?php submit_button( __( 'Send to Agend', 'agend-directory-sync' ), 'primary', 'submit', false, $source_unavailable ? array( 'disabled' => 'disabled' ) : array() ); ?>
					</form>
				</div>

				<script>
					(function () {
						var input = document.getElementById('agend_max_records');
						var mirrors = [
							document.getElementById('agend_max_records_preview'),
							document.getElementById('agend_max_records_send')
						];
						if (input) {
							function syncMirrors() {
								mirrors.forEach(function (m) { if (m) { m.value = input.value; } });
							}
							input.addEventListener('input', syncMirrors);
							input.addEventListener('change', syncMirrors);
							syncMirrors();
						}

						// Show only the settings section matching the selected data
						// source (US-1.2 criterion 2). The initial section visibility
						// is already correct server-side; this only keeps it in sync
						// when the admin changes the select before saving.
						var sourceSelect = document.getElementById('agend_directory_sync_source');
						var sections = document.querySelectorAll('[data-agend-source-key]');
						if (sourceSelect && sections.length) {
							function syncSections() {
								sections.forEach(function (section) {
									section.style.display = section.getAttribute('data-agend-source-key') === sourceSelect.value ? '' : 'none';
								});
							}
							sourceSelect.addEventListener('change', syncSections);
						}
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
			// The Custom HTTP API first-page preview has a distinct shape
			// (US-2.4): the presence of 'data_path' marks it, set only by
			// Agend_Directory_Sync_Http_Api_Source::preview_first_page().
			if ( array_key_exists( 'data_path', $result ) ) {
				self::render_http_api_preview_result( $result );
				return;
			}

			$fetched = (int) ( $result['fetched'] ?? 0 );
			$preview = is_array( $result['preview'] ?? null ) ? $result['preview'] : array();

			echo '<p>'
				. esc_html(
					sprintf(
						// translators: 1: total fetched count, 2: preview count.
						_n(
							'Fetched %1$d contact from the source. Showing the first %2$d below.',
							'Fetched %1$d contacts from the source. Showing the first %2$d below.',
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

		/**
		 * Render the Custom HTTP API first-page response-model preview
		 * (US-2.4 criterion 3): HTTP status, the raw decoded envelope
		 * (truncated at 100 KB), the configured path, and either the
		 * resolved records (first 5, with a resolved-count line) or, when
		 * the path failed to resolve, the deepest-resolvable-segment key
		 * listing (criterion 4). Never renders secrets or request headers
		 * (criterion 5) — nothing here echoes the auth settings at all.
		 *
		 * @param array<string, mixed> $result
		 */
		private static function render_http_api_preview_result( array $result ): void {
			$status    = (int) ( $result['http_status'] ?? 0 );
			$decoded   = is_array( $result['decoded'] ?? null ) ? $result['decoded'] : array();
			$data_path = (string) ( $result['data_path'] ?? '' );

			echo '<p>'
				. esc_html(
					sprintf(
						/* translators: %d: HTTP status code from the first-page fetch. */
						__( 'HTTP status: %d', 'agend-directory-sync' ),
						$status
					)
				)
				. '</p>';

			echo '<h4>' . esc_html__( 'Raw response envelope', 'agend-directory-sync' ) . '</h4>';
			self::render_json_block_truncated( $decoded );

			echo '<p>'
				. esc_html(
					sprintf(
						/* translators: %s: the configured response data path, or "(root)" when blank. */
						__( 'Configured response data path: %s', 'agend-directory-sync' ),
						'' !== $data_path ? $data_path : __( '(root)', 'agend-directory-sync' )
					)
				)
				. '</p>';

			if ( empty( $result['path_resolved'] ) ) {
				$failed_at      = (string) ( $result['failed_at'] ?? '' );
				$available_keys = is_array( $result['available_keys'] ?? null ) ? $result['available_keys'] : array();

				echo '<div class="notice notice-warning inline"><p>'
					. esc_html(
						sprintf(
							/* translators: 1: deepest path segment that resolved, 2: comma-separated keys available there. */
							__( 'The configured path did not resolve to an array. Resolution failed at "%1$s". Keys available there: %2$s.', 'agend-directory-sync' ),
							$failed_at,
							! empty( $available_keys ) ? implode( ', ', $available_keys ) : __( '(none)', 'agend-directory-sync' )
						)
					)
					. '</p></div>';
				return;
			}

			$resolved_count = (int) ( $result['resolved_count'] ?? 0 );
			$records        = is_array( $result['resolved_records'] ?? null ) ? $result['resolved_records'] : array();
			$skipped        = (int) ( $result['skipped_non_associative'] ?? 0 );

			echo '<p>'
				. esc_html(
					sprintf(
						/* translators: 1: total records resolved, 2: rows rendered below. */
						__( 'Resolved %1$d record(s). Showing the first %2$d.', 'agend-directory-sync' ),
						$resolved_count,
						count( $records )
					)
				)
				. '</p>';

			if ( $skipped > 0 ) {
				echo '<p class="description">'
					. esc_html(
						sprintf(
							/* translators: %d: rows skipped because they were not a JSON object. */
							__( '%d row(s) skipped: not a JSON object.', 'agend-directory-sync' ),
							$skipped
						)
					)
					. '</p>';
			}

			echo '<h4>' . esc_html__( 'Resolved records (first 5)', 'agend-directory-sync' ) . '</h4>';
			self::render_json_block( $records );
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
			self::render_status_counts( $result['status_counts'] ?? array() );
			self::render_dropped_fields(
				$result['dropped_fields'] ?? array(),
				$result['dropped_field_examples'] ?? array()
			);

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
			self::render_status_counts( $result['status_counts'] ?? array() );
			self::render_dropped_fields(
				$result['dropped_fields'] ?? array(),
				$result['dropped_field_examples'] ?? array()
			);

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
		 * Render the per-status counts (e.g. approved vs suspended).
		 * Approved rows will appear on the public directory once the
		 * auto-publish flag has populated their published_at; suspended
		 * rows are preserved in the directory database but hidden until
		 * the source eligibility / opt-in flags flip back on.
		 *
		 * @param mixed $status_counts Map: status string => count.
		 */
		private static function render_status_counts( $status_counts ): void {
			if ( ! is_array( $status_counts ) || empty( $status_counts ) ) {
				return;
			}

			echo '<h4>' . esc_html__( 'Listing status', 'agend-directory-sync' ) . '</h4>';
			echo '<p class="description">'
				. esc_html__( 'Approved rows are visible (subject to auto-publish). Suspended rows are kept in the directory database but hidden from the public site because the source member has lost eligibility or opted out.', 'agend-directory-sync' )
				. '</p>';
			echo '<ul style="list-style:disc;padding-left:1.5em;">';
			foreach ( $status_counts as $status => $count ) {
				echo '<li>'
					. '<code>' . esc_html( (string) $status ) . '</code>: '
					. esc_html( (string) (int) $count )
					. '</li>';
			}
			echo '</ul>';
		}

		/**
		 * Render the dropped-field counters and per-reason example tables.
		 * These are rows that were synced, but with a specific field
		 * omitted because it violated a hard constraint (e.g. phone > 50
		 * chars). The example tables surface external_id + fullname for the
		 * first N affected rows so the operator can locate the source
		 * record.
		 *
		 * @param mixed $dropped_fields  Counter map: reason => count.
		 * @param mixed $dropped_field_examples Map: reason => list of
		 *                                       { external_id, fullname }.
		 */
		private static function render_dropped_fields(
			$dropped_fields,
			$dropped_field_examples = array()
		): void {
			if ( ! is_array( $dropped_fields ) || empty( $dropped_fields ) ) {
				return;
			}

			if ( ! is_array( $dropped_field_examples ) ) {
				$dropped_field_examples = array();
			}

			echo '<h4>' . esc_html__( 'Dropped fields', 'agend-directory-sync' ) . '</h4>';
			echo '<p class="description">'
				. esc_html__( 'These rows were still synced, but the named field was omitted because it failed a hard constraint.', 'agend-directory-sync' )
				. '</p>';
			echo '<ul style="list-style:disc;padding-left:1.5em;">';
			foreach ( $dropped_fields as $reason => $count ) {
				$reason_str = (string) $reason;
				$count_int  = (int) $count;
				echo '<li>'
					. '<code>' . esc_html( $reason_str ) . '</code>: '
					. esc_html( (string) $count_int )
					. '</li>';
			}
			echo '</ul>';

			foreach ( $dropped_fields as $reason => $count ) {
				$reason_str = (string) $reason;
				$count_int  = (int) $count;
				$examples   = isset( $dropped_field_examples[ $reason_str ] ) && is_array( $dropped_field_examples[ $reason_str ] )
					? $dropped_field_examples[ $reason_str ]
					: array();

				if ( empty( $examples ) ) {
					continue;
				}

				$shown      = count( $examples );
				$summary    = $shown < $count_int
					? sprintf(
						// translators: 1: count shown, 2: total dropped, 3: drop reason code.
						__( 'Affected rows for %3$s (showing %1$d of %2$d)', 'agend-directory-sync' ),
						$shown,
						$count_int,
						$reason_str
					)
					: sprintf(
						// translators: 1: total affected, 2: drop reason code.
						__( 'Affected rows for %2$s (%1$d)', 'agend-directory-sync' ),
						$count_int,
						$reason_str
					);

				echo '<details style="margin:0 0 1em 1.5em;">';
				echo '<summary style="cursor:pointer;font-weight:600;">'
					. esc_html( $summary )
					. '</summary>';
				echo '<table class="widefat striped" style="margin-top:0.5em;max-width:720px;"><thead><tr>'
					. '<th>' . esc_html__( 'external_id', 'agend-directory-sync' ) . '</th>'
					. '<th>' . esc_html__( 'fullname', 'agend-directory-sync' ) . '</th>'
					. '</tr></thead><tbody>';
				foreach ( $examples as $example ) {
					if ( ! is_array( $example ) ) {
						continue;
					}
					$ext_id   = (string) ( $example['external_id'] ?? '' );
					$fullname = (string) ( $example['fullname'] ?? '' );
					echo '<tr>'
						. '<td><code>' . esc_html( $ext_id ) . '</code></td>'
						. '<td>' . ( '' !== $fullname ? esc_html( $fullname ) : '<em>' . esc_html__( '(no fullname)', 'agend-directory-sync' ) . '</em>' ) . '</td>'
						. '</tr>';
				}
				echo '</tbody></table>';
				echo '</details>';
			}
		}

		private static function render_json_block( $value ): void {
			$json = wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			echo '<pre style="background:#f6f7f7;border:1px solid #c3c4c7;padding:1em;max-height:600px;overflow:auto;">'
				. esc_html( (string) $json )
				. '</pre>';
		}

		/**
		 * Maximum bytes of pretty-printed JSON rendered by
		 * render_json_block_truncated() before truncation (US-2.4 criterion
		 * 3): the raw response envelope in the Custom HTTP API preview can
		 * be arbitrarily large.
		 */
		private const JSON_BLOCK_TRUNCATE_BYTES = 102400;

		/**
		 * Like render_json_block(), but truncates at 100 KB with a
		 * truncation notice (US-2.4 criterion 3), for rendering an
		 * arbitrarily large raw API response.
		 *
		 * @param mixed $value
		 */
		private static function render_json_block_truncated( $value ): void {
			$json = (string) wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

			if ( strlen( $json ) > self::JSON_BLOCK_TRUNCATE_BYTES ) {
				$json = substr( $json, 0, self::JSON_BLOCK_TRUNCATE_BYTES );
				echo '<p class="description">' . esc_html__( 'Truncated at 100 KB.', 'agend-directory-sync' ) . '</p>';
			}

			echo '<pre style="background:#f6f7f7;border:1px solid #c3c4c7;padding:1em;max-height:600px;overflow:auto;">'
				. esc_html( $json )
				. '</pre>';
		}

		private static function result_heading( string $kind ): string {
			switch ( $kind ) {
				case 'fetch':
					return __( 'Last source fetch', 'agend-directory-sync' );
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
