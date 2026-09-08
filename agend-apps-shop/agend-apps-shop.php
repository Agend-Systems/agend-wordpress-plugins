<?php
/**
 * Plugin Name:       Agend Apps Shop
 * Plugin URI:        https://agend.com.au
 * Update URI:        https://agend-systems.github.io/agend-wordpress-plugins/agend-apps-shop
 * Description:       Extends Agend Apps Core with Elementor cart widgets for end-user checkout flows.
 * Version:           1.0.4
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
define( 'AGEND_APPS_SHOP_VERSION', '1.0.4' );

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
 * Enqueues frontend assets for all Agend Apps Shop widgets.
 *
 * Assets are only loaded on the frontend when Elementor is active. Registered
 * at priority 20 to ensure `window.agendApps` from agend-apps-core is already
 * output by `wp_head`.
 */
function agend_apps_shop_enqueue_scripts(): void {
	if ( ! did_action( 'elementor/loaded' ) ) {
		return;
	}

	wp_enqueue_script( 'agend-apps-shop-cart-session' );

	wp_enqueue_script(
		'agend-apps-shop-add-to-cart',
		AGEND_APPS_SHOP_URL . 'assets/js/agend-apps-shop-add-to-cart.js',
		array( 'agend-apps-shop-cart-session' ),
		AGEND_APPS_SHOP_VERSION,
		true
	);

	wp_enqueue_style(
		'agend-apps-shop-add-to-cart',
		AGEND_APPS_SHOP_URL . 'assets/css/agend-apps-shop-add-to-cart.css',
		array(),
		AGEND_APPS_SHOP_VERSION
	);

	wp_enqueue_script(
		'agend-apps-shop-cart-header',
		AGEND_APPS_SHOP_URL . 'assets/js/agend-apps-shop-cart-header.js',
		array( 'agend-apps-shop-cart-session' ),
		AGEND_APPS_SHOP_VERSION,
		true
	);

	wp_enqueue_style(
		'agend-apps-shop-cart-header',
		AGEND_APPS_SHOP_URL . 'assets/css/agend-apps-shop-cart-header.css',
		array(),
		AGEND_APPS_SHOP_VERSION
	);

	wp_enqueue_script(
		'agend-apps-shop-cart-view',
		AGEND_APPS_SHOP_URL . 'assets/js/agend-apps-shop-cart-view.js',
		array( 'agend-apps-shop-cart-session' ),
		AGEND_APPS_SHOP_VERSION,
		true
	);

	wp_enqueue_style(
		'agend-apps-shop-cart-view',
		AGEND_APPS_SHOP_URL . 'assets/css/agend-apps-shop-cart-view.css',
		array(),
		AGEND_APPS_SHOP_VERSION
	);

	wp_localize_script(
		'agend-apps-shop-cart-view',
		'agendAppsShop',
		array(
			'cartPageUrl'        => esc_url( get_option( 'agend_apps_shop_cart_page_url', '' ) ),
			'checkoutSuccessUrl' => esc_url( get_option( 'agend_apps_shop_checkout_success_url', '' ) ),
			'checkoutCancelUrl'  => esc_url( get_option( 'agend_apps_shop_checkout_cancel_url', '' ) ),
		)
	);

	wp_localize_script(
		'agend-apps-shop-cart-header',
		'agendAppsShop',
		array(
			'cartPageUrl'        => esc_url( get_option( 'agend_apps_shop_cart_page_url', '' ) ),
			'checkoutSuccessUrl' => esc_url( get_option( 'agend_apps_shop_checkout_success_url', '' ) ),
			'checkoutCancelUrl'  => esc_url( get_option( 'agend_apps_shop_checkout_cancel_url', '' ) ),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'agend_apps_shop_enqueue_scripts', 20 );

/**
 * Registers the cart-session helper on every request, Elementor or not.
 *
 * The Agend Apps Core catalogue scripts depend on this handle whenever the
 * shop is active. A dependency that is never registered makes WordPress drop
 * the dependent script silently, which is what happened to the events
 * catalogue block on a site without Elementor while registration lived inside
 * the Elementor-gated enqueue above.
 */
function agend_apps_shop_register_cart_session(): void {
	wp_register_script(
		'agend-apps-shop-cart-session',
		AGEND_APPS_SHOP_URL . 'assets/js/agend-apps-shop-cart-session.js',
		array(),
		AGEND_APPS_SHOP_VERSION,
		true
	);
}
add_action( 'init', 'agend_apps_shop_register_cart_session', 5 );

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
