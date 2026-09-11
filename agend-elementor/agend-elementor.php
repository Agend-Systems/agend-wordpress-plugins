<?php
/**
 * Plugin Name:       Agend Elementor Widgets
 * Plugin URI:        https://agend.com.au
 * Update URI:        https://agend-systems.github.io/agend-wordpress-plugins/agend-elementor
 * Description:       Elementor widgets that surface Agend Events, Learning, and Directory data natively inside WordPress pages, powered by the Agend gateway via Agend Apps Core.
 * Version:           0.24.0
 * Author:            Agend
 * Author URI:        https://agend.com.au
 * Text Domain:       agend-elementor
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Tested up to:      7.1
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
define( 'AGEND_ELEMENTOR_VERSION', '0.24.0' );

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

// Elementor element-cache guard. Loaded unconditionally so a degraded boot
// (missing dependency) can be recorded even when the bootstrap bails below.
require_once AGEND_ELEMENTOR_DIR . 'includes/class-agend-elementor-cache-guard.php';

/**
 * Checks required dependencies and loads the plugin's components.
 *
 * Requires the Agend Apps Core plugin (for the API client, REST proxy, and the
 * record layer it now hosts) at a version carrying that layer, and Elementor.
 * Displays an admin notice and exits early if any is missing. Hooked on
 * `plugins_loaded` so all WordPress APIs are available.
 */
function agend_elementor_bootstrap(): void {
	if ( ! function_exists( 'agend_apps_api' ) ) {
		Agend_Elementor_Cache_Guard::flag_degraded();
		add_action( 'admin_notices', 'agend_elementor_missing_core_notice' );
		return;
	}

	// The record layer (field registry, cards, SSR detail, settings, pages) now
	// loads from Agend Apps Core 1.8.0 and newer. An Agend Apps
	// Core predating that move has no such function, so bail rather than fatal
	// on the classes this bootstrap assumes are already loaded.
	if ( ! function_exists( 'agend_apps_records_ssr_detail_enabled' ) ) {
		Agend_Elementor_Cache_Guard::flag_degraded();
		add_action( 'admin_notices', 'agend_elementor_outdated_core_notice' );
		return;
	}

	if ( ! did_action( 'elementor/loaded' ) ) {
		Agend_Elementor_Cache_Guard::flag_degraded();
		add_action( 'admin_notices', 'agend_elementor_missing_elementor_notice' );
		return;
	}

	// Widgets will register on this request: flush any element cache built
	// while they were not registered (see the cache guard's class docblock).
	Agend_Elementor_Cache_Guard::watch();

	require_once AGEND_ELEMENTOR_DIR . 'includes/class-agend-elementor.php';

	require_once AGEND_ELEMENTOR_DIR . 'includes/class-agend-elementor-templates.php';
	require_once AGEND_ELEMENTOR_DIR . 'includes/class-agend-elementor-template-renderer.php';

	// Register this plugin's implementation of the Agend Apps Core template
	// contracts so the framework-agnostic call sites (cards, SSR detail,
	// settings, fragments REST) never call Elementor's classes directly.
	// Guarded so an older Agend Apps Core without the registry cannot fatal
	// the site; the two plugins are updated independently on live sites.
	if ( class_exists( 'Agend_Apps_Templates' ) ) {
		require_once AGEND_ELEMENTOR_DIR . 'includes/adapters/class-agend-elementor-template-renderer-adapter.php';
		require_once AGEND_ELEMENTOR_DIR . 'includes/adapters/class-agend-elementor-template-source-adapter.php';
		Agend_Apps_Templates::register_renderer( new Agend_Elementor_Template_Renderer_Adapter() );
		Agend_Apps_Templates::register_source( new Agend_Elementor_Template_Source_Adapter() );
	}

	// Elementor-only: labels the live detail templates in the Saved Templates
	// list. Independent of the registry, so it is not inside the guard above.
	require_once AGEND_ELEMENTOR_DIR . 'includes/adapters/template-post-states.php';

	require_once AGEND_ELEMENTOR_DIR . 'includes/class-agend-elementor-preview-type.php';
	require_once AGEND_ELEMENTOR_DIR . 'includes/class-agend-elementor-field-widget-trait.php';
	require_once AGEND_ELEMENTOR_DIR . 'includes/class-agend-elementor-schema-controls.php';

	// The usermeta display conditions that used to load here are RETIRED
	// (SPEC-CMS-20260727 US-1.1). They were a second entitlement authority that
	// read arbitrary user metadata and failed OPEN, so an enabled condition
	// with no rules rendered the element to everybody. Typed, fail-closed
	// policies live in Agend Content Access instead.
	//
	// What remains is the upgrade notice: removing the class is silent, so any
	// element that WAS conditioned now renders for every visitor, and an
	// administrator needs to be told which pages those are.
	require_once AGEND_ELEMENTOR_DIR . 'includes/class-agend-elementor-retired-conditions-notice.php';
	Agend_Elementor_Retired_Conditions_Notice::init();
}
// Priority 20: the record layer this bootstrap depends on loads from Agend
// Apps Core at the default `plugins_loaded` priority (10), and alphabetical
// plugin load order must not be what makes that ordering hold.
add_action( 'plugins_loaded', 'agend_elementor_bootstrap', 20 );

/**
 * Displays an admin notice when Agend Apps Core is not active.
 */
function agend_elementor_missing_core_notice(): void {
	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'Agend Elementor Widgets requires the Agend Apps Core plugin to be installed and active.', 'agend-elementor' );
	echo '</p></div>';
}

/**
 * Displays an admin notice when Agend Apps Core is active but predates the
 * record layer this plugin now depends on.
 */
function agend_elementor_outdated_core_notice(): void {
	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'Agend Elementor Widgets requires Agend Apps Core 1.8.0 or newer.', 'agend-elementor' );
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

	// This function is hooked at top level and runs even when the bootstrap
	// above bailed (missing/outdated core, missing Elementor), so guard
	// against calling a handle registrar that was never loaded.
	if ( ! function_exists( 'agend_apps_records_register_assets' ) ) {
		return;
	}

	// Template widgets (Agend Field / Pills / Image / Link / Panel). Enqueued
	// unconditionally like the catalogue assets: REST-rendered card fragments
	// arrive after the page has loaded, so the host page must already carry
	// these. Now enqueued by handle alone: these stopped being adapter assets
	// when those surfaces moved their render into core, and they are hosted
	// there so the block editor's own record blocks can name the same handle.
	wp_enqueue_style( 'agend-apps-records-record-fields' );
	wp_enqueue_script( 'agend-apps-records-record-fields' );

	// Filter controls for Agend Filter widgets placed in a filter template.
	wp_enqueue_style( 'agend-apps-records-filters' );
	wp_enqueue_script( 'agend-apps-records-filters' );

	// Directory export reports: the list is per-visitor, so it is fetched at
	// view time rather than rendered into a cacheable page.
	wp_enqueue_style( 'agend-apps-records-export-reports' );
	wp_enqueue_script( 'agend-apps-records-export-reports' );

	wp_enqueue_style( 'agend-apps-records-events-catalogue' );
	wp_enqueue_script( 'agend-apps-records-events-catalogue' );

	wp_enqueue_style( 'agend-apps-records-courses-catalogue' );
	wp_enqueue_script( 'agend-apps-records-courses-catalogue' );

	wp_enqueue_style( 'agend-apps-records-directory-catalogue' );
	wp_enqueue_script( 'agend-apps-records-directory-catalogue' );

	wp_enqueue_style( 'agend-apps-records-account-link' );
	wp_enqueue_script( 'agend-apps-records-account-link' );

	wp_enqueue_style( 'agend-apps-records-member-login' );
	wp_enqueue_script( 'agend-apps-records-member-login' );

	wp_enqueue_style( 'agend-apps-records-memberships-catalogue' );
	wp_enqueue_script( 'agend-apps-records-memberships-catalogue' );

	wp_enqueue_style( 'agend-apps-records-header-auth' );
	wp_enqueue_script( 'agend-apps-records-header-auth' );
}
add_action( 'wp_enqueue_scripts', 'agend_elementor_enqueue_scripts', 20 );
