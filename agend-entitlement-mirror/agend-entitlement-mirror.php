<?php
/**
 * Plugin Name:     Agend Entitlement Mirror
 * Plugin URI:      https://www.agend.com.au
 * Update URI:      https://agend-systems.github.io/agend-wordpress-plugins/agend-entitlement-mirror
 * Description:     Mirrors Upbeat entitlements into Agend CRM entitlement grants, so directory/content gating segments react to standing granted or revoked in Upbeat.
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

define( 'AGEND_ENTITLEMENT_MIRROR_DIR', rtrim( plugin_dir_path( __FILE__ ), '/' ) );
define( 'AGEND_ENTITLEMENT_MIRROR_URL', esc_url( rtrim( plugin_dir_url( __FILE__ ), '/' ) ) );

/**
 * Loads includes and registers hooks. Hooked on `plugins_loaded`, matching
 * agend-apps-core's and agend-loop-sync's own bootstrap (and
 * agend-directory-sync's, after the same conversion). There is deliberately
 * no plugin class extending iugo-membership-kiosk's Iugo_Membership_Kiosk_Plugin
 * here: that base class's run()/check_requirements() only loaded a plugin's
 * own files once the kiosk plugin was ACTIVE, which meant this plugin's
 * admin page, settings, and CLI command could never register at all on a
 * site without the kiosk active -- regardless of which entitlement data
 * source is configured. Only the Upbeat source
 * (Agend_Entitlement_Mirror_Upbeat_Source::is_available()) gates on the
 * kiosk now, at the point it is actually asked for data.
 *
 * Extracted from agend-apps-core (operator decision 2026-08-04): agend-apps-core
 * carries connection details and generic gateway API bindings only;
 * Upbeat/kiosk-coupled (Pro-client) logic lives in this standalone plugin so
 * non-Pro installs never carry it.
 */
function agend_entitlement_mirror_bootstrap() {
	// Settings first: the collector/resolver/sync/CLI classes below all call
	// Agend_Entitlement_Mirror_Settings:: at runtime.
	require_once AGEND_ENTITLEMENT_MIRROR_DIR . '/includes/class-entitlement-mirror-settings.php';
	require_once AGEND_ENTITLEMENT_MIRROR_DIR . '/includes/interface-source.php';
	require_once AGEND_ENTITLEMENT_MIRROR_DIR . '/includes/class-upbeat-source.php';
	require_once AGEND_ENTITLEMENT_MIRROR_DIR . '/includes/class-http-api-source.php';
	require_once AGEND_ENTITLEMENT_MIRROR_DIR . '/includes/class-source-registry.php';
	require_once AGEND_ENTITLEMENT_MIRROR_DIR . '/includes/class-entitlement-collector.php';
	require_once AGEND_ENTITLEMENT_MIRROR_DIR . '/includes/class-entitlement-sync.php';
	require_once AGEND_ENTITLEMENT_MIRROR_DIR . '/includes/class-admin-page.php';

	// The generic HTTP API source ships with this plugin, but registers
	// through the same `agend_entitlement_mirror_sources` filter a client
	// source would use, rather than a second hard-coded entry in
	// Agend_Entitlement_Mirror_Source_Registry::register_defaults().
	add_filter(
		'agend_entitlement_mirror_sources',
		static function ( array $sources ): array {
			$http_api                        = new Agend_Entitlement_Mirror_Http_Api_Source();
			$sources[ $http_api->get_key() ] = $http_api;
			return $sources;
		}
	);

	Agend_Entitlement_Mirror_Source_Registry::register_defaults();

	Agend_Entitlement_Mirror_Admin_Page::setup_hooks();

	// Subscribes to the kiosk's existing webhook actions and wp_login. No-ops
	// silently when the module is disabled (the enable option is read here,
	// once, at boot time) -- turning the option on within the same request
	// does NOT retroactively register the listeners; the change takes effect
	// from the next request onward (known caveat, carried over unchanged
	// from the agend-apps-core module).
	Agend_Entitlement_Sync::register();

	// WP-CLI entitlement mirror sweep (US-2.5): loaded only under WP-CLI so
	// the command class is never defined in a web request.
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		require_once AGEND_ENTITLEMENT_MIRROR_DIR . '/includes/class-cli-command.php';
	}
}
add_action( 'plugins_loaded', 'agend_entitlement_mirror_bootstrap' );
