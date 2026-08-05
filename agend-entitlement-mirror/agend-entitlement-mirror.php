<?php
/**
 * Plugin Name:     Agend Entitlement Mirror
 * Plugin URI:      https://www.agend.com.au
 * Description:     Mirrors Upbeat entitlements into the Agend member_entitlements contact flag, so directory/content gating segments react to standing granted or revoked in Upbeat.
 * Author:          Iugo Pty Ltd
 * Author URI:      https://www.iugo.com.au
 * Text Domain:     agend-entitlement-mirror
 * Version:         0.1.0
 *
 * @package         Agend_Entitlement_Mirror
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Agend_Entitlement_Mirror' ) ) :
	require_once WP_PLUGIN_DIR . '/iugo-membership-kiosk/includes/abstract/class-imk-plugin.php';

	/**
	 * Extracted from agend-apps-core (SPEC-AMS-20260804-upbeat-entitlement-mirror,
	 * operator decision 2026-08-04): agend-apps-core carries connection details
	 * and generic gateway API bindings only; Upbeat/kiosk-coupled (Pro-client)
	 * logic lives in this standalone plugin so non-Pro installs never carry it.
	 */
	final class Agend_Entitlement_Mirror extends Iugo_Membership_Kiosk_Plugin {

		public function __construct() {
			parent::__construct( 'Agend Entitlement Mirror', 'agend-entitlement-mirror' );

			$this->require_plugin( 'Agend Membership', 'iugo-membership-kiosk/membership-integration.php' );
			$this->require_plugin( 'Agend Apps Core', 'agend-apps-core/agend-apps-core.php' );

			$this->define_constants();

			// Settings first: the collector/resolver/sync/CLI classes below all
			// call Agend_Entitlement_Mirror_Settings:: at runtime.
			$this->include( 'includes/class-entitlement-mirror-settings.php' );
			$this->include( 'includes/class-entitlement-collector.php' );
			$this->include( 'includes/class-contact-resolver.php' );
			$this->include( 'includes/class-entitlement-sync.php' );
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
			Agend_Entitlement_Mirror_Admin_Page::setup_hooks();

			// Subscribes to the kiosk's existing webhook actions and wp_login.
			// No-ops silently when the module is disabled (the enable option is
			// read here, once, at boot time) -- turning the option on within the
			// same request does NOT retroactively register the listeners; the
			// change takes effect from the next request onward (known caveat,
			// carried over unchanged from the agend-apps-core module).
			Agend_Entitlement_Sync::register();

			// WP-CLI entitlement mirror sweep (US-2.5): loaded only under WP-CLI
			// so the command class is never defined in a web request.
			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				require_once AGEND_ENTITLEMENT_MIRROR_DIR . '/includes/class-cli-command.php';
			}
		}

		private function define_constants(): void {
			define( 'AGEND_ENTITLEMENT_MIRROR_DIR', $this->get_plugin_directory() );
			define( 'AGEND_ENTITLEMENT_MIRROR_URL', esc_url( rtrim( plugin_dir_url( __FILE__ ), '/' ) ) );
		}
	}

endif;

$GLOBALS['Agend_Entitlement_Mirror'] = Agend_Entitlement_Mirror::instance();
