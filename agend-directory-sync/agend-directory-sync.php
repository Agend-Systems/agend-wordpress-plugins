<?php
/**
 * Plugin Name:     Agend Directory Sync
 * Plugin URI:      https://www.agend.com.au
 * Description:     Sync Upbeat membership directory contacts to the Agend directory via the bulk-upsert API.
 * Author:          Iugo Pty Ltd
 * Author URI:      https://www.iugo.com.au
 * Text Domain:     agend-directory-sync
 * Version:         0.4.1
 *
 * @package         Agend_Directory_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Agend_Directory_Sync' ) ) :
	require_once WP_PLUGIN_DIR . '/iugo-membership-kiosk/includes/abstract/class-imk-plugin.php';

	final class Agend_Directory_Sync extends Iugo_Membership_Kiosk_Plugin {

		/**
		 * Option key for the Upbeat directory endpoint path. The path varies per
		 * client, so it is configurable. Blank falls back to the default
		 * `membershipDirectoryContacts`.
		 *
		 * The Agend gateway base URL and API key are NOT stored here: connecting
		 * to the gateway is owned by the agend-apps-core plugin (configured under
		 * Settings > Agend Apps), which this plugin calls via
		 * `agend_apps_directory_bulk_upsert_listings()`.
		 */
		public const OPTION_UPBEAT_ENDPOINT = 'agend_directory_sync_upbeat_endpoint';

		/**
		 * Option key for the active data source key (e.g. `upbeat`,
		 * `http_api`), resolved by Agend_Directory_Sync_Source_Registry. An
		 * unset or unknown value falls back to `upbeat`, so an existing
		 * install never changes source on upgrade (SPEC-DIR-20260731
		 * Decision 2.1).
		 */
		public const OPTION_SOURCE = 'agend_directory_sync_source';

		/**
		 * Option key for the Custom HTTP API source's settings (URL,
		 * timeout, response data path, auth mode + non-secret auth
		 * settings, pagination mode + params). One array option, sanitised
		 * as a whole (SPEC-DIR-20260731 US-2.4 criterion 2). The secrets
		 * themselves are never stored here — see
		 * AGEND_DIRECTORY_SYNC_HTTP_TOKEN and
		 * AGEND_DIRECTORY_SYNC_OAUTH_CLIENT_SECRET (Decision 2.4).
		 */
		public const OPTION_HTTP_API = 'agend_directory_sync_http_api';

		/**
		 * Option key for the external_source string sent with each batch.
		 */
		public const OPTION_EXTERNAL_SOURCE = 'agend_directory_sync_external_source';

		/**
		 * Option key for the boolean "auto-publish approved listings" flag.
		 * When set ('1'), bulk-upsert payloads include
		 * `auto_publish_approved: true` so Agend sets `published_at` on
		 * approved rows and they appear on the public directory.
		 */
		public const OPTION_AUTO_PUBLISH_APPROVED = 'agend_directory_sync_auto_publish_approved';

		/**
		 * Default value for external_source if the option is unset. This is the
		 * upsert key the Agend gateway matches on, alongside external_id, so it
		 * MUST stay stable for a given directory. The default is generic; each
		 * site should set its own value under Tools > Agend Directory Sync.
		 *
		 * Upgrading from a release that defaulted external_source to a fixed
		 * tenant-specific value: pin that previous value in settings BEFORE the
		 * first sync, or already-synced listings will be re-inserted as new
		 * rows instead of updated in place.
		 */
		public const DEFAULT_EXTERNAL_SOURCE = 'upbeat-directory';

		/**
		 * Default for the auto-publish flag when the option has never been
		 * saved. Defaults to true because the directory is otherwise
		 * invisible to the public.
		 */
		public const DEFAULT_AUTO_PUBLISH_APPROVED = true;

		public function __construct() {
			parent::__construct( 'Agend Directory Sync', 'agend-directory-sync' );

			$this->require_plugin( 'Agend Membership', 'iugo-membership-kiosk/membership-integration.php' );
			$this->require_plugin( 'Agend Apps Core', 'agend-apps-core/agend-apps-core.php' );

			$this->define_constants();

			$this->include( 'includes/class-field-map.php' );
			$this->include( 'includes/interface-source.php' );
			$this->include( 'includes/class-path-resolver.php' );
			$this->include( 'includes/class-upbeat-client.php' );
			$this->include( 'includes/class-oauth-token-manager.php' );
			$this->include( 'includes/class-http-api-source.php' );
			$this->include( 'includes/class-listing-transformer.php' );
			$this->include( 'includes/class-source-registry.php' );
			$this->include( 'includes/class-agend-client.php' );
			$this->include( 'includes/class-sync-runner.php' );
			$this->include( 'includes/class-admin-page.php' );

			// The generic HTTP API source ships with this plugin, but
			// registers through the same `agend_directory_sync_sources`
			// filter a client source would use (SPEC-DIR-20260731 US-2.1),
			// rather than a second hard-coded entry in
			// Agend_Directory_Sync_Source_Registry::register_defaults() —
			// that file is untouched by this story. add_filter() here runs
			// synchronously in the constructor, well before
			// register_defaults() applies the filter from
			// post_include_files().
			add_filter(
				'agend_directory_sync_sources',
				static function ( array $sources ): array {
					$http_api                        = new Agend_Directory_Sync_Http_Api_Source();
					$sources[ $http_api->get_key() ] = $http_api;
					return $sources;
				}
			);

			/**
			 * Hook in after the kiosk plugin has loaded so its API class is available.
			 *
			 * @see Iugo_Membership_Kiosk_Plugin::run()
			 */
			add_action(
				'setup_theme',
				function () {
					$this->run( 'iugo_membership_kiosk' );
				}
			);
		}

		public function post_include_files(): void {
			// Seed the source registry now: all files are included and every
			// other plugin's add_filter() calls have already run by this
			// point (this fires on the `iugo_membership_kiosk_loaded` action,
			// itself hooked to `setup_theme`), so the
			// `agend_directory_sync_sources` filter sees every registration.
			Agend_Directory_Sync_Source_Registry::register_defaults();

			Agend_Directory_Sync_Admin_Page::setup_hooks();

			// Register the WP-CLI command for unattended / server-cron runs.
			// Loaded only under WP-CLI so the command class never exists in a
			// web request. The file calls WP_CLI::add_command() on load.
			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				require_once AGEND_DIRECTORY_SYNC_DIR . '/includes/class-cli-command.php';
			}
		}

		private function define_constants(): void {
			define( 'AGEND_DIRECTORY_SYNC_DIR', $this->get_plugin_directory() );
			define( 'AGEND_DIRECTORY_SYNC_URL', esc_url( rtrim( plugin_dir_url( __FILE__ ), '/' ) ) );
		}
	}

endif;

$GLOBALS['Agend_Directory_Sync'] = Agend_Directory_Sync::instance();
