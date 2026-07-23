<?php
/**
 * Plugin Name:       Agend Elementor Widgets
 * Plugin URI:        https://agend.com.au
 * Description:       Elementor widgets that surface Agend Events, Learning, and Directory data natively inside WordPress pages, powered by the Agend gateway via Agend Apps Core.
 * Version:           0.9.4
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
define( 'AGEND_ELEMENTOR_VERSION', '0.9.4' );

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
define( 'AGEND_ELEMENTOR_REWRITE_VERSION', '20260723-1' );

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
 * Determines whether the events widgets should add tickets to the shop cart
 * instead of registering and paying immediately.
 *
 * Returns true when the Agend Apps Shop plugin is active (its version constant
 * is defined). The `agend_elementor_cart_mode` filter allows a site to override
 * the detected value, so the direct register/pay flow can be forced back on
 * even when the shop is present, or vice versa.
 *
 * @return bool True when cart mode is enabled, false otherwise.
 */
function agend_elementor_shop_cart_enabled(): bool {
	$enabled = defined( 'AGEND_APPS_SHOP_VERSION' );

	/**
	 * Filters whether the Agend events widgets use the shop cart flow.
	 *
	 * @param bool $enabled Whether cart mode is enabled (the shop plugin is active).
	 */
	return (bool) apply_filters( 'agend_elementor_cart_mode', $enabled );
}

/**
 * Returns the configured shop cart page URL, or an empty string.
 *
 * Only meaningful when the Agend Apps Shop plugin is active; the option is
 * seeded and managed by that plugin. Surfaced to the events widgets so the
 * post-add confirmation can link the visitor to their cart.
 *
 * @return string Escaped cart page URL, or empty string when unavailable.
 */
function agend_elementor_shop_cart_page_url(): string {
	if ( ! agend_elementor_shop_cart_enabled() ) {
		return '';
	}

	return esc_url_raw( (string) get_option( 'agend_apps_shop_cart_page_url', '' ) );
}

/**
 * Registers the vendored DOMPurify script (Cure53), once.
 *
 * The client-side HTML sanitiser used by the catalogue scripts as the final
 * defence-in-depth layer before any gateway-supplied rich text (course/event
 * descriptions) is written to the DOM. Shared by the global frontend enqueue
 * and the SSR detail enqueue so both declare the same handle, version, and
 * vendor path, and the scripts that render HTML can always depend on it.
 */
function agend_elementor_register_dompurify(): void {
	if ( wp_script_is( 'agend-elementor-dompurify', 'registered' ) ) {
		return;
	}
	wp_register_script(
		'agend-elementor-dompurify',
		AGEND_ELEMENTOR_URL . 'assets/js/vendor/purify.min.js',
		array(),
		'3.3.1',
		true
	);
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

	agend_elementor_register_dompurify();

	wp_enqueue_style(
		'agend-elementor-events-catalogue',
		AGEND_ELEMENTOR_URL . 'assets/css/events-catalogue.css',
		array(),
		AGEND_ELEMENTOR_VERSION
	);

	// When the shop is active, the events registration flow adds tickets to the
	// cart via the shop's AgendCartSession helper (guest cart cookie + REST
	// headers), so depend on its handle. The dependency is added only when the
	// shop is active, otherwise the handle is unregistered and WordPress would
	// silently drop the events script.
	$events_deps = array( 'agend-elementor-dompurify' );
	if ( agend_elementor_shop_cart_enabled() ) {
		$events_deps[] = 'agend-apps-shop-cart-session';
	}

	wp_enqueue_script(
		'agend-elementor-events-catalogue',
		AGEND_ELEMENTOR_URL . 'assets/js/events-catalogue.js',
		$events_deps,
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

	wp_enqueue_style(
		'agend-elementor-member-login',
		AGEND_ELEMENTOR_URL . 'assets/css/member-login.css',
		array(),
		AGEND_ELEMENTOR_VERSION
	);

	wp_enqueue_script(
		'agend-elementor-member-login',
		AGEND_ELEMENTOR_URL . 'assets/js/member-login.js',
		array(),
		AGEND_ELEMENTOR_VERSION,
		true
	);

	wp_enqueue_style(
		'agend-elementor-memberships-catalogue',
		AGEND_ELEMENTOR_URL . 'assets/css/memberships-catalogue.css',
		array(),
		AGEND_ELEMENTOR_VERSION
	);

	wp_enqueue_script(
		'agend-elementor-memberships-catalogue',
		AGEND_ELEMENTOR_URL . 'assets/js/memberships-catalogue.js',
		array( 'agend-elementor-dompurify' ),
		AGEND_ELEMENTOR_VERSION,
		true
	);

	wp_enqueue_style(
		'agend-elementor-header-auth',
		AGEND_ELEMENTOR_URL . 'assets/css/header-auth.css',
		array(),
		AGEND_ELEMENTOR_VERSION
	);

	wp_enqueue_script(
		'agend-elementor-header-auth',
		AGEND_ELEMENTOR_URL . 'assets/js/header-auth.js',
		array(),
		AGEND_ELEMENTOR_VERSION,
		true
	);
}
add_action( 'wp_enqueue_scripts', 'agend_elementor_enqueue_scripts', 20 );
