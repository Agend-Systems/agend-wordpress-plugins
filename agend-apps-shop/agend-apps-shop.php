<?php
/**
 * Plugin Name:       Agend Apps Shop
 * Plugin URI:        https://agend.com.au
 * Update URI:        https://agend-systems.github.io/agend-wordpress-plugins/agend-apps-shop
 * Description:       Extends Agend Apps Core with Elementor cart widgets for end-user checkout flows.
 * Version:           1.1.1
 * Author:            Agend
 * Author URI:        https://agend.com.au
 * Text Domain:       agend-apps-shop
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Tested up to:      7.1
 * Requires PHP:      7.4
 *
 * @package Agend_Apps_Shop
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin version.
 *
 * @var string
 */
define( 'AGEND_APPS_SHOP_VERSION', '1.1.1' );

/**
 * Absolute path to the plugin directory, with trailing slash.
 *
 * @var string
 */
define( 'AGEND_APPS_SHOP_DIR', plugin_dir_path( __FILE__ ) );

/**
 * URL to the plugin directory, with trailing slash.
 *
 * @var string
 */
define( 'AGEND_APPS_SHOP_URL', plugin_dir_url( __FILE__ ) );

/**
 * Checks that required plugins are active and loads the plugin's components.
 *
 * Displays an admin notice and exits early if `agend-apps-core` is not active.
 * Hooked on `plugins_loaded` so all WordPress APIs are available.
 */
function agend_apps_shop_bootstrap(): void {
	if ( ! function_exists( 'agend_apps_api' ) ) {
		add_action( 'admin_notices', 'agend_apps_shop_missing_core_notice' );
		return;
	}

	require_once AGEND_APPS_SHOP_DIR . 'includes/records/schema.php';
	require_once AGEND_APPS_SHOP_DIR . 'includes/records/render.php';
	require_once AGEND_APPS_SHOP_DIR . 'includes/records/blocks.php';
	require_once AGEND_APPS_SHOP_DIR . 'includes/class-agend-apps-shop-elementor.php';

	if ( is_admin() ) {
		require_once AGEND_APPS_SHOP_DIR . 'admin/class-agend-apps-shop-admin.php';
		new Agend_Apps_Shop_Admin();
	}
}
add_action( 'plugins_loaded', 'agend_apps_shop_bootstrap' );

/**
 * Displays an admin notice when agend-apps-core is not active.
 */
function agend_apps_shop_missing_core_notice(): void {
	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'Agend Apps Shop requires the Agend Apps Core plugin to be installed and active.', 'agend-apps-shop' );
	echo '</p></div>';
}

/**
 * Registers every `agend-apps-shop-*` front-end handle, ungated by Elementor.
 *
 * Registered on init (not wp_enqueue_scripts, and not gated on
 * did_action( 'elementor/loaded' )) so a block's viewScript/style can name
 * these handles directly: WordPress only actually enqueues a registered
 * handle when the block that names it is present on the page, the same
 * mechanism agend-apps-core's shared assets rely on (see
 * agend-apps-core/includes/records/assets.php's own init-priority-5
 * registration and its docblock).
 *
 * Before this, assets were enqueued unconditionally on every front-end page
 * of an Elementor site (gated only on Elementor being active at all, not on
 * a cart widget actually being present), and never loaded at all on a
 * Gutenberg-only site -- a block would have rendered dead markup. Each
 * Elementor widget now declares its own get_script_depends()/
 * get_style_depends() so Elementor enqueues only what a placed widget
 * actually needs, matching a block's own per-page enqueue. This is a
 * deliberate behaviour change: a site with custom JS or CSS that assumed
 * these handles were always present on every page should verify it still
 * runs once only the pages that use a cart surface load them.
 *
 * The cart-session handle is registered for every request regardless,
 * Elementor or not: the Agend Apps Core catalogue scripts depend on it
 * whenever the shop is active, and a dependency that is never registered
 * makes WordPress drop the dependent script silently.
 */
function agend_apps_shop_register_assets(): void {
	wp_register_script(
		'agend-apps-shop-cart-session',
		AGEND_APPS_SHOP_URL . 'assets/js/agend-apps-shop-cart-session.js',
		array(),
		AGEND_APPS_SHOP_VERSION,
		true
	);

	wp_register_script(
		'agend-apps-shop-add-to-cart',
		AGEND_APPS_SHOP_URL . 'assets/js/agend-apps-shop-add-to-cart.js',
		array( 'agend-apps-shop-cart-session' ),
		AGEND_APPS_SHOP_VERSION,
		true
	);
	wp_register_style(
		'agend-apps-shop-add-to-cart',
		AGEND_APPS_SHOP_URL . 'assets/css/agend-apps-shop-add-to-cart.css',
		array(),
		AGEND_APPS_SHOP_VERSION
	);

	wp_register_script(
		'agend-apps-shop-cart-header',
		AGEND_APPS_SHOP_URL . 'assets/js/agend-apps-shop-cart-header.js',
		array( 'agend-apps-shop-cart-session' ),
		AGEND_APPS_SHOP_VERSION,
		true
	);
	wp_register_style(
		'agend-apps-shop-cart-header',
		AGEND_APPS_SHOP_URL . 'assets/css/agend-apps-shop-cart-header.css',
		array(),
		AGEND_APPS_SHOP_VERSION
	);

	wp_register_script(
		'agend-apps-shop-cart-view',
		AGEND_APPS_SHOP_URL . 'assets/js/agend-apps-shop-cart-view.js',
		array( 'agend-apps-shop-cart-session' ),
		AGEND_APPS_SHOP_VERSION,
		true
	);
	wp_register_style(
		'agend-apps-shop-cart-view',
		AGEND_APPS_SHOP_URL . 'assets/css/agend-apps-shop-cart-view.css',
		array(),
		AGEND_APPS_SHOP_VERSION
	);

	$localized = array(
		'cartPageUrl'        => esc_url( get_option( 'agend_apps_shop_cart_page_url', '' ) ),
		'checkoutSuccessUrl' => esc_url( get_option( 'agend_apps_shop_checkout_success_url', '' ) ),
		'checkoutCancelUrl'  => esc_url( get_option( 'agend_apps_shop_checkout_cancel_url', '' ) ),
	);
	// wp_localize_script() only needs the handle registered, not enqueued: the
	// data is attached now and printed alongside the script if and when it is
	// actually enqueued, so this is safe to call unconditionally here.
	wp_localize_script( 'agend-apps-shop-cart-view', 'agendAppsShop', $localized );
	wp_localize_script( 'agend-apps-shop-cart-header', 'agendAppsShop', $localized );
}
add_action( 'init', 'agend_apps_shop_register_assets', 5 );

/**
 * Seeds default option values on plugin activation.
 *
 * Uses `add_option()` so existing settings are never overwritten on re-activation.
 */
function agend_apps_shop_activate(): void {
	add_option( 'agend_apps_shop_cart_page_url', '' );
	add_option( 'agend_apps_shop_checkout_success_url', '' );
	add_option( 'agend_apps_shop_checkout_cancel_url', '' );
}
register_activation_hook( __FILE__, 'agend_apps_shop_activate' );
