<?php
/**
 * Shared front-end assets for the Agend catalogue widgets.
 *
 * The CSS/JS behind the Agend catalogue widgets is page-builder-agnostic (it
 * only talks to the rendered DOM and the REST fragments API), so it is hosted
 * here rather than in the Elementor plugin.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * URL of a shared front-end asset.
 *
 * @param string $relative Asset path relative to the assets directory.
 * @return string Full asset URL.
 */
function agend_apps_records_asset_url( string $relative ): string {
	return AGEND_APPS_CORE_URL . 'assets/' . $relative;
}

/**
 * Cache-busting version to enqueue shared front-end assets with.
 *
 * @return string Asset version string.
 */
function agend_apps_records_asset_version(): string {
	return AGEND_APPS_CORE_VERSION;
}

/**
 * Determines whether the events widgets should add tickets to the shop cart
 * instead of registering and paying immediately.
 *
 * Returns true when the Agend Apps Shop plugin is active (its version constant
 * is defined). The `agend_apps_records_cart_mode` filter allows a site to
 * override the detected value, so the direct register/pay flow can be forced
 * back on even when the shop is present, or vice versa.
 *
 * @return bool True when cart mode is enabled, false otherwise.
 */
function agend_apps_records_shop_cart_enabled(): bool {
	$enabled = defined( 'AGEND_APPS_SHOP_VERSION' );

	/**
	 * Filters whether the Agend events widgets use the shop cart flow.
	 *
	 * @param bool $enabled Whether cart mode is enabled (the shop plugin is active).
	 */
	$enabled = (bool) apply_filters( 'agend_apps_records_cart_mode', $enabled );

	// A site's existing add_filter() on the pre-rename hook name still applies
	// for one release.
	return (bool) apply_filters_deprecated( 'agend_elementor_cart_mode', array( $enabled ), '1.8.0', 'agend_apps_records_cart_mode' );
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
function agend_apps_records_shop_cart_page_url(): string {
	if ( ! agend_apps_records_shop_cart_enabled() ) {
		return '';
	}

	return esc_url_raw( (string) get_option( 'agend_apps_shop_cart_page_url', '' ) );
}

/**
 * Registers the vendored DOMPurify script (Cure53), once.
 *
 * The client-side HTML sanitiser used by the catalogue scripts as the final
 * defence-in-depth layer before any gateway-supplied rich text (course/event
 * descriptions) is written to the DOM. Shared by every enqueue path so they all
 * depend on the same handle, version, and vendor path.
 */
function agend_apps_records_register_dompurify(): void {
	if ( wp_script_is( 'agend-apps-records-dompurify', 'registered' ) ) {
		return;
	}
	wp_register_script(
		'agend-apps-records-dompurify',
		agend_apps_records_asset_url( 'js/vendor/purify.min.js' ),
		array(),
		'3.3.1',
		true
	);
}

/**
 * Registers every shared `agend-apps-records-*` handle so sibling plugins can
 * enqueue them by handle alone.
 *
 * Registered on init so the handles exist for the front-end enqueues (the
 * Elementor plugin's and the SSR detail's, both on wp_enqueue_scripts) and for
 * the block editor alike.
 */
function agend_apps_records_register_assets(): void {
	agend_apps_records_register_dompurify();

	wp_register_style(
		'agend-apps-records-filters',
		agend_apps_records_asset_url( 'css/filters.css' ),
		array(),
		agend_apps_records_asset_version()
	);
	wp_register_script(
		'agend-apps-records-filters',
		agend_apps_records_asset_url( 'js/filters.js' ),
		array(),
		agend_apps_records_asset_version(),
		true
	);

	wp_register_style(
		'agend-apps-records-export-reports',
		agend_apps_records_asset_url( 'css/export-reports.css' ),
		array(),
		agend_apps_records_asset_version()
	);
	wp_register_script(
		'agend-apps-records-export-reports',
		agend_apps_records_asset_url( 'js/export-reports.js' ),
		array(),
		agend_apps_records_asset_version(),
		true
	);

	wp_register_style(
		'agend-apps-records-events-catalogue',
		agend_apps_records_asset_url( 'css/events-catalogue.css' ),
		array(),
		agend_apps_records_asset_version()
	);

	// When the shop is active, the events registration flow adds tickets to the
	// cart via the shop's AgendCartSession helper (guest cart cookie + REST
	// headers), so depend on its handle. The dependency is added only when the
	// shop is active, otherwise the handle is unregistered and WordPress would
	// silently drop the events script.
	$events_deps = array( 'agend-apps-records-dompurify' );
	if ( agend_apps_records_shop_cart_enabled() ) {
		$events_deps[] = 'agend-apps-shop-cart-session';
	}

	wp_register_script(
		'agend-apps-records-events-catalogue',
		agend_apps_records_asset_url( 'js/events-catalogue.js' ),
		$events_deps,
		agend_apps_records_asset_version(),
		true
	);

	wp_register_style(
		'agend-apps-records-courses-catalogue',
		agend_apps_records_asset_url( 'css/courses-catalogue.css' ),
		array(),
		agend_apps_records_asset_version()
	);
	wp_register_script(
		'agend-apps-records-courses-catalogue',
		agend_apps_records_asset_url( 'js/courses-catalogue.js' ),
		array( 'agend-apps-records-dompurify' ),
		agend_apps_records_asset_version(),
		true
	);

	wp_register_style(
		'agend-apps-records-directory-catalogue',
		agend_apps_records_asset_url( 'css/directory-catalogue.css' ),
		array(),
		agend_apps_records_asset_version()
	);
	wp_register_script(
		'agend-apps-records-directory-catalogue',
		agend_apps_records_asset_url( 'js/directory-catalogue.js' ),
		array( 'agend-apps-records-dompurify' ),
		agend_apps_records_asset_version(),
		true
	);
	wp_register_script(
		'agend-apps-records-directory-detail',
		agend_apps_records_asset_url( 'js/directory-detail.js' ),
		array(),
		agend_apps_records_asset_version(),
		true
	);

	wp_register_style(
		'agend-apps-records-account-link',
		agend_apps_records_asset_url( 'css/account-link.css' ),
		array(),
		agend_apps_records_asset_version()
	);
	wp_register_script(
		'agend-apps-records-account-link',
		agend_apps_records_asset_url( 'js/account-link.js' ),
		array(),
		agend_apps_records_asset_version(),
		true
	);

	wp_register_style(
		'agend-apps-records-member-login',
		agend_apps_records_asset_url( 'css/member-login.css' ),
		array(),
		agend_apps_records_asset_version()
	);
	wp_register_script(
		'agend-apps-records-member-login',
		agend_apps_records_asset_url( 'js/member-login.js' ),
		array(),
		agend_apps_records_asset_version(),
		true
	);

	wp_register_style(
		'agend-apps-records-memberships-catalogue',
		agend_apps_records_asset_url( 'css/memberships-catalogue.css' ),
		array(),
		agend_apps_records_asset_version()
	);
	wp_register_script(
		'agend-apps-records-memberships-catalogue',
		agend_apps_records_asset_url( 'js/memberships-catalogue.js' ),
		array( 'agend-apps-records-dompurify' ),
		agend_apps_records_asset_version(),
		true
	);

	wp_register_style(
		'agend-apps-records-header-auth',
		agend_apps_records_asset_url( 'css/header-auth.css' ),
		array(),
		agend_apps_records_asset_version()
	);
	wp_register_script(
		'agend-apps-records-header-auth',
		agend_apps_records_asset_url( 'js/header-auth.js' ),
		array(),
		agend_apps_records_asset_version(),
		true
	);
}
// On init rather than wp_enqueue_scripts: a block's viewScript and style
// name these handles, and the block editor resolves them outside the
// front-end enqueue hook.
add_action( 'init', 'agend_apps_records_register_assets', 5 );
