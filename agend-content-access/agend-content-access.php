<?php
/**
 * Plugin Name:       Agend Content Access
 * Plugin URI:        https://agend.com.au
 * Description:       Restricts WordPress content to Agend members and membership plans. Agend authorises every protected response; WordPress only declares the intended audience.
 * Version:           0.1.0
 * Author:            Agend
 * Author URI:        https://agend.com.au
 * Text Domain:       agend-content-access
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 *
 * @package Agend_Content_Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin version.
 *
 * @var string
 */
define( 'AGEND_CONTENT_ACCESS_VERSION', '0.1.0' );

/**
 * Absolute path to the plugin directory, with trailing slash.
 *
 * @var string
 */
define( 'AGEND_CONTENT_ACCESS_DIR', plugin_dir_path( __FILE__ ) );

/**
 * URL to the plugin directory, with trailing slash.
 *
 * @var string
 */
define( 'AGEND_CONTENT_ACCESS_URL', plugin_dir_url( __FILE__ ) );

/**
 * Loads the plugin once its dependencies are satisfied.
 *
 * Agend Apps Core is REQUIRED: this plugin owns no transport, no API key
 * storage and no token minting, and consumes Core's published interfaces
 * instead (SPEC-CMS-20260727 Decision 2.10). Without Core it registers nothing
 * at all rather than half-registering and failing at render time, because a
 * half-registered access-control plugin is indistinguishable from one that has
 * decided the visitor may proceed.
 *
 * Elementor is OPTIONAL. Without it, native post and page policies still work;
 * fragment policies simply have nowhere to attach. Every Elementor integration
 * point is guarded so a site can add or remove Elementor without breaking.
 *
 * Hooked late on `plugins_loaded` (priority 20) so Core, which bootstraps at
 * the default priority, has already declared its functions.
 */
function agend_content_access_bootstrap(): void {
	require_once AGEND_CONTENT_ACCESS_DIR . 'includes/class-agend-content-access-dependencies.php';

	if ( ! Agend_Content_Access_Dependencies::are_met() ) {
		add_action(
			'admin_notices',
			array( 'Agend_Content_Access_Dependencies', 'render_notice' )
		);
		return;
	}

	require_once AGEND_CONTENT_ACCESS_DIR . 'includes/class-agend-content-access-catalogue.php';
	require_once AGEND_CONTENT_ACCESS_DIR . 'includes/class-agend-content-access-policy.php';

	add_action( 'rest_api_init', 'agend_content_access_bootstrap_rest' );

	if ( is_admin() ) {
		require_once AGEND_CONTENT_ACCESS_DIR . 'includes/class-agend-content-access-meta-box.php';
		new Agend_Content_Access_Meta_Box();

		add_action( 'admin_enqueue_scripts', 'agend_content_access_enqueue_admin_assets' );
	}

	/**
	 * Fires once Agend Content Access has confirmed its dependencies and is
	 * about to register its own components.
	 *
	 * @since 0.1.0
	 */
	do_action( 'agend_content_access_loaded' );
}
add_action( 'plugins_loaded', 'agend_content_access_bootstrap', 20 );

/**
 * Enqueues the policy panel's assets, on the post editor only.
 *
 * @param string $hook Current admin page.
 */
function agend_content_access_enqueue_admin_assets( string $hook ): void {
	if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
		return;
	}

	wp_enqueue_style(
		'agend-content-access-policy-panel',
		AGEND_CONTENT_ACCESS_URL . 'assets/css/policy-panel.css',
		array(),
		AGEND_CONTENT_ACCESS_VERSION
	);

	wp_enqueue_script(
		'agend-content-access-policy-panel',
		AGEND_CONTENT_ACCESS_URL . 'assets/js/policy-panel.js',
		array(),
		AGEND_CONTENT_ACCESS_VERSION,
		true
	);
}

/**
 * Registers this plugin's REST routes.
 *
 * Loaded on `rest_api_init` rather than at bootstrap so the route files are
 * only read on REST requests.
 */
function agend_content_access_bootstrap_rest(): void {
	require_once AGEND_CONTENT_ACCESS_DIR . 'includes/rest/catalogue-routes.php';

	agend_content_access_register_catalogue_routes();
}

/**
 * Whether Elementor is loaded and safe to integrate with.
 *
 * Every Elementor integration point calls this first. `elementor/loaded` is the
 * action Elementor fires once its own bootstrap has run, so checking the class
 * alone would be true too early during plugin load.
 *
 * @return bool True when Elementor has finished loading.
 */
function agend_content_access_has_elementor(): bool {
	return did_action( 'elementor/loaded' ) > 0;
}
