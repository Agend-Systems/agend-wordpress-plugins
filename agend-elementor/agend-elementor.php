<?php
/**
 * Plugin Name:       Agend Elementor Widgets
 * Plugin URI:        https://agend.com.au
 * Description:       Elementor widgets that surface Agend Events, Learning, and Directory data natively inside WordPress pages, powered by the Agend gateway via Agend Apps Core.
 * Version:           0.8.2
 * Author:            Agend
 * Author URI:        https://agend.com.au
 * Text Domain:       agend-elementor
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 *
 * @package Agend_Elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin version.
 *
 * @var string
 */
define( 'AGEND_ELEMENTOR_VERSION', '0.8.2' );

/**
 * Absolute path to the plugin directory, with trailing slash.
 *
 * @var string
 */
define( 'AGEND_ELEMENTOR_DIR', plugin_dir_path( __FILE__ ) );

/**
 * URL to the plugin directory, with trailing slash.
 *
 * @var string
 */
define( 'AGEND_ELEMENTOR_URL', plugin_dir_url( __FILE__ ) );

/**
 * Rewrite ruleset version. Bump whenever the rewrite endpoints registered in
 * includes/class-agend-elementor-routing.php change, so the versioned
 * auto-flush regenerates the rules on the next request after an update deploy.
 *
 * @var string
 */
define( 'AGEND_ELEMENTOR_REWRITE_VERSION', '20260717-2' );

// Detail-URL rewrite endpoints (SPEC-INFRA-20260717 US-1.1). Loaded
// unconditionally so the endpoints register even when Elementor or Agend Apps
// Core is temporarily unavailable; the widgets that consume them stay gated.
require_once AGEND_ELEMENTOR_DIR . 'includes/class-agend-elementor-routing.php';

// Settings (server-rendered detail toggle). Loaded unconditionally so the
// accessor is available on the front-end `wp` hook and in the admin.
require_once AGEND_ELEMENTOR_DIR . 'includes/class-agend-elementor-settings.php';

register_activation_hook( __FILE__, 'agend_elementor_activate_rewrites' );
register_deactivation_hook( __FILE__, 'agend_elementor_deactivate_rewrites' );

/**
 * Checks required dependencies and loads the plugin's components.
 *
 * Requires both the Agend Apps Core plugin (for the API client and REST proxy)
 * and Elementor. Displays an admin notice and exits early if either is missing.
 * Hooked on `plugins_loaded` so all WordPress APIs are available.
 */
function agend_elementor_bootstrap(): void {
	if ( ! function_exists( 'agend_apps_api' ) ) {
		add_action( 'admin_notices', 'agend_elementor_missing_core_notice' );
		return;
	}

	if ( ! did_action( 'elementor/loaded' ) ) {
		add_action( 'admin_notices', 'agend_elementor_missing_elementor_notice' );
		return;
	}

	require_once AGEND_ELEMENTOR_DIR . 'includes/class-agend-elementor.php';

	// Server-rendered detail pages (opt-in). Requires the Agend Apps Core REST
	// wrappers, so it loads only once the core dependency check above passes.
	require_once AGEND_ELEMENTOR_DIR . 'includes/class-agend-elementor-ssr-detail.php';
}
add_action( 'plugins_loaded', 'agend_elementor_bootstrap' );

/**
 * Displays an admin notice when Agend Apps Core is not active.
 */
function agend_elementor_missing_core_notice(): void {
	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'Agend Elementor Widgets requires the Agend Apps Core plugin to be installed and active.', 'agend-elementor' );
	echo '</p></div>';
}

/**
 * Displays an admin notice when Elementor is not active.
 */
function agend_elementor_missing_elementor_notice(): void {
	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'Agend Elementor Widgets requires Elementor to be installed and active.', 'agend-elementor' );
	echo '</p></div>';
}

/**
 * Enqueues frontend assets for the Agend Elementor widgets.
 *
 * Registered at priority 20 so `window.agendApps` from agend-apps-core (output
 * on `wp_head` at priority 1) is already available when these scripts run.
 */
function agend_elementor_enqueue_scripts(): void {
	if ( ! did_action( 'elementor/loaded' ) ) {
		return;
	}

	// DOMPurify (vendored, Cure53) — the client-side HTML sanitiser used by the
	// catalogue scripts as the final defence-in-depth layer before any
	// gateway-supplied rich text (course/event descriptions) is written to the
	// DOM. Registered once and declared as a dependency of the widgets that
	// render HTML so it always loads first.
	wp_register_script(
		'agend-elementor-dompurify',
		AGEND_ELEMENTOR_URL . 'assets/js/vendor/purify.min.js',
		array(),
		'3.3.1',
		true
	);

	wp_enqueue_style(
		'agend-elementor-events-catalogue',
		AGEND_ELEMENTOR_URL . 'assets/css/events-catalogue.css',
		array(),
		AGEND_ELEMENTOR_VERSION
	);

	wp_enqueue_script(
		'agend-elementor-events-catalogue',
		AGEND_ELEMENTOR_URL . 'assets/js/events-catalogue.js',
		array( 'agend-elementor-dompurify' ),
		AGEND_ELEMENTOR_VERSION,
		true
	);

	wp_enqueue_style(
		'agend-elementor-courses-catalogue',
		AGEND_ELEMENTOR_URL . 'assets/css/courses-catalogue.css',
		array(),
		AGEND_ELEMENTOR_VERSION
	);

	wp_enqueue_script(
		'agend-elementor-courses-catalogue',
		AGEND_ELEMENTOR_URL . 'assets/js/courses-catalogue.js',
		array( 'agend-elementor-dompurify' ),
		AGEND_ELEMENTOR_VERSION,
		true
	);

	wp_enqueue_style(
		'agend-elementor-directory-catalogue',
		AGEND_ELEMENTOR_URL . 'assets/css/directory-catalogue.css',
		array(),
		AGEND_ELEMENTOR_VERSION
	);

	wp_enqueue_script(
		'agend-elementor-directory-catalogue',
		AGEND_ELEMENTOR_URL . 'assets/js/directory-catalogue.js',
		array( 'agend-elementor-dompurify' ),
		AGEND_ELEMENTOR_VERSION,
		true
	);

	wp_enqueue_style(
		'agend-elementor-account-link',
		AGEND_ELEMENTOR_URL . 'assets/css/account-link.css',
		array(),
		AGEND_ELEMENTOR_VERSION
	);

	wp_enqueue_script(
		'agend-elementor-account-link',
		AGEND_ELEMENTOR_URL . 'assets/js/account-link.js',
		array(),
		AGEND_ELEMENTOR_VERSION,
		true
	);
}
add_action( 'wp_enqueue_scripts', 'agend_elementor_enqueue_scripts', 20 );
