<?php
/**
 * Plugin Name:     Agend Directory Sync
 * Plugin URI:      https://www.agend.com.au
 * Description:     Sync Upbeat membership directory contacts to the Agend directory via the bulk-upsert API.
 * Author:          Iugo Pty Ltd
 * Author URI:      https://www.iugo.com.au
 * Text Domain:     agend-directory-sync
 * Version:         0.2.0
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
		 * Option key for the Agend gateway base URL (e.g. http://localhost:3072).
		 */
		public const OPTION_AGEND_GATEWAY_URL = 'agend_directory_sync_gateway_url';

		/**
		 * Option key for the Agend public API key. Stored as plain text for MVP.
		 */
		public const OPTION_AGEND_API_KEY = 'agend_directory_sync_api_key';

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

			$this->define_constants();

			$this->include( 'includes/class-field-map.php' );
			$this->include( 'includes/class-upbeat-client.php' );
			$this->include( 'includes/class-listing-transformer.php' );
			$this->include( 'includes/class-agend-client.php' );
			$this->include( 'includes/class-sync-runner.php' );
			$this->include( 'includes/class-admin-page.php' );

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
