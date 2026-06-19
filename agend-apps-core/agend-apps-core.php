<?php
/**
 * Plugin Name:       Agend Apps Core
 * Plugin URI:        https://agend.dev
 * Description:       Foundational plugin for the Agend Apps ecosystem. Provides the API client, REST proxy endpoints, and admin configuration for all Agend sibling plugins.
 * Version:           1.0.0
 * Author:            Agend
 * Author URI:        https://agend.dev
 * Text Domain:       agend-apps-core
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin version.
 *
 * @var string
 */
define( 'AGEND_APPS_CORE_VERSION', '1.0.0' );

/**
 * Absolute path to the plugin directory, with trailing slash.
 *
 * @var string
 */
define( 'AGEND_APPS_CORE_DIR', plugin_dir_path( __FILE__ ) );

/**
 * URL to the plugin directory, with trailing slash.
 *
 * @var string
 */
define( 'AGEND_APPS_CORE_URL', plugin_dir_url( __FILE__ ) );

/**
 * Production Agend Gateway API root (unversioned).
 *
 * @var string
 */
define( 'AGEND_APPS_API_PRODUCTION_URL', 'https://api.agend.dev' );

/**
 * Local development Agend Gateway API root (unversioned).
 *
 * @var string
 */
define( 'AGEND_APPS_API_LOCAL_URL', 'http://localhost:3072' );

/**
 * Loads all plugin includes and initialises the admin controller.
 *
 * Hooked on `plugins_loaded` so all WordPress APIs are available before
 * any include is executed.
 */
function agend_apps_core_bootstrap() {
	require_once AGEND_APPS_CORE_DIR . 'includes/class-agend-apps-settings.php';
	require_once AGEND_APPS_CORE_DIR . 'includes/class-agend-apps-cache.php';
	require_once AGEND_APPS_CORE_DIR . 'includes/class-agend-apps-api.php';
	require_once AGEND_APPS_CORE_DIR . 'includes/api/health.php';
	require_once AGEND_APPS_CORE_DIR . 'includes/api/cart.php';
	require_once AGEND_APPS_CORE_DIR . 'includes/api/directory.php';
	require_once AGEND_APPS_CORE_DIR . 'includes/api/loop-integration.php';
	require_once AGEND_APPS_CORE_DIR . 'includes/api/auth.php';
	require_once AGEND_APPS_CORE_DIR . 'includes/api/crm.php';
	require_once AGEND_APPS_CORE_DIR . 'includes/api/events.php';
	require_once AGEND_APPS_CORE_DIR . 'includes/api/lms.php';
	require_once AGEND_APPS_CORE_DIR . 'includes/api/cms.php';
	require_once AGEND_APPS_CORE_DIR . 'includes/api/jobs.php';
	require_once AGEND_APPS_CORE_DIR . 'includes/api/sso.php';
	require_once AGEND_APPS_CORE_DIR . 'includes/api/support.php';
	require_once AGEND_APPS_CORE_DIR . 'includes/api/webhooks.php';
	require_once AGEND_APPS_CORE_DIR . 'includes/rest/class-agend-apps-rest-controller.php';
	require_once AGEND_APPS_CORE_DIR . 'includes/rest/health-routes.php';
	require_once AGEND_APPS_CORE_DIR . 'includes/rest/cart-routes.php';
	require_once AGEND_APPS_CORE_DIR . 'includes/rest/directory-routes.php';
	require_once AGEND_APPS_CORE_DIR . 'includes/rest/cms-routes.php';
	require_once AGEND_APPS_CORE_DIR . 'includes/rest/jobs-routes.php';
	require_once AGEND_APPS_CORE_DIR . 'includes/rest/events-routes.php';
	require_once AGEND_APPS_CORE_DIR . 'includes/rest/lms-routes.php';
	require_once AGEND_APPS_CORE_DIR . 'includes/rest/crm-routes.php';

	if ( is_admin() ) {
		require_once AGEND_APPS_CORE_DIR . 'admin/class-agend-apps-admin.php';
		new Agend_Apps_Admin();
	}
}
add_action( 'plugins_loaded', 'agend_apps_core_bootstrap' );

/**
 * Registers all Agend Apps REST API routes.
 *
 * Individual route files expose their own registration functions which are
 * called here from `rest_api_init`.
 */
function agend_apps_core_register_rest_routes() {
	agend_apps_register_health_routes();
	agend_apps_register_cart_routes();
	agend_apps_register_directory_routes();
	agend_apps_register_cms_routes();
	agend_apps_register_jobs_routes();
	agend_apps_register_events_routes();
	agend_apps_register_lms_routes();
	agend_apps_register_crm_routes();
}
add_action( 'rest_api_init', 'agend_apps_core_register_rest_routes' );

/**
 * Outputs the REST URL and WP nonce as a JS global for frontend consumers.
 *
 * Exposes `window.agendApps.restUrl` and `window.agendApps.nonce` so that
 * JS/Gutenberg blocks in sibling plugins can authenticate REST requests.
 */
function agend_apps_output_config_js() {
	$data = wp_json_encode(
		array(
			'restUrl' => rest_url( 'agend-apps/v1/' ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
		)
	);
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	printf( '<script id="agend-apps-config">/* <![CDATA[ */window.agendApps = %s;/* ]]> */</script>' . "\n", $data );
}
add_action( 'wp_head', 'agend_apps_output_config_js', 1 );
add_action( 'admin_head', 'agend_apps_output_config_js', 1 );

/**
 * Seeds default option values on plugin activation.
 *
 * Uses `add_option()` so existing settings are never overwritten on re-activation.
 */
function agend_apps_core_activate() {
	add_option( 'agend_apps_environment', 'production' );
	add_option( 'agend_apps_custom_url', '' );
	add_option( 'agend_apps_api_key', '' );

	// Ensure cache class is available during activation.
	require_once AGEND_APPS_CORE_DIR . 'includes/class-agend-apps-cache.php';

	foreach ( Agend_Apps_Cache::get_all_keys() as $key => $config ) {
		add_option( 'agend_apps_cache_' . $key, $config['default_ttl'] );
	}
}
register_activation_hook( __FILE__, 'agend_apps_core_activate' );

/**
 * Deactivation hook.
 *
 * Intentionally empty — settings persist across deactivation so that
 * re-activating the plugin does not require reconfiguration.
 */
function agend_apps_core_deactivate() {}
register_deactivation_hook( __FILE__, 'agend_apps_core_deactivate' );
