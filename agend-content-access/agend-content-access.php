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

	require_once AGEND_CONTENT_ACCESS_DIR . 'includes/class-agend-content-access-assets.php';
	require_once AGEND_CONTENT_ACCESS_DIR . 'includes/class-agend-content-access-catalogue.php';
	require_once AGEND_CONTENT_ACCESS_DIR . 'includes/class-agend-content-access-policy.php';
	require_once AGEND_CONTENT_ACCESS_DIR . 'includes/class-agend-content-access-decision.php';
	require_once AGEND_CONTENT_ACCESS_DIR . 'includes/class-agend-content-access-meta-box.php';
	require_once AGEND_CONTENT_ACCESS_DIR . 'includes/class-agend-content-access-frontend.php';
	require_once AGEND_CONTENT_ACCESS_DIR . 'includes/class-agend-content-access-credentials.php';
	require_once AGEND_CONTENT_ACCESS_DIR . 'includes/class-agend-content-access-exporter.php';
	require_once AGEND_CONTENT_ACCESS_DIR . 'includes/class-agend-content-access-elementor-parser.php';

	// Gating is registered on EVERY request, admin included: the REST filters
	// hang off it and a REST call never reaches template_redirect.
	new Agend_Content_Access_Frontend();

	// Fragment policies, only when Elementor is present.
	//
	// This bootstrap runs at plugins_loaded priority 20 and Elementor fires
	// `elementor/loaded` during its own plugins_loaded at the default priority
	// 10, so by the time we get here that action has ALREADY fired. Hooking it
	// unconditionally registered nothing and silently disabled every fragment
	// policy: a restricted section rendered to anonymous visitors. Caught on a
	// real Elementor 4.2.0 page, not by any unit test, because the failure is
	// purely one of hook ordering.
	if ( did_action( 'elementor/loaded' ) ) {
		agend_content_access_bootstrap_elementor();
	} else {
		add_action( 'elementor/loaded', 'agend_content_access_bootstrap_elementor' );
	}

	// Protected downloads (US-5.1, US-5.2).
	require_once AGEND_CONTENT_ACCESS_DIR . 'includes/rest/download-routes.php';
	add_action( 'rest_api_init', 'agend_content_access_register_download_routes' );
	add_action( 'save_post', 'agend_content_access_promote_saved_assets', 20 );

	// Version range and the suppression-chain probe (US-4.4 criteria 9, 10).
	// Loaded unconditionally: it must be able to report that Elementor is
	// ABSENT or too old, which it cannot do from inside an Elementor-gated
	// branch.
	require_once AGEND_CONTENT_ACCESS_DIR . 'includes/class-agend-content-access-compat.php';
	Agend_Content_Access_Compat::init();

	add_action( 'rest_api_init', 'agend_content_access_bootstrap_rest' );

	if ( is_admin() ) {
		require_once AGEND_CONTENT_ACCESS_DIR . 'admin/class-agend-content-access-admin.php';

		new Agend_Content_Access_Meta_Box();
		new Agend_Content_Access_Admin();

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
 * Clears cached page-builder markup when this plugin is activated or updated.
 *
 * Elementor's element cache stores one rendered blob per document in post meta,
 * with a 24 hour TTL and no viewer dimension. A blob built BEFORE this plugin
 * was active contains markup produced with no policy applied, and nothing about
 * activation invalidates it, so restricted regions would keep being served until
 * the TTL expired or an editor saved the page.
 *
 * Runtime protection for policy-bearing elements is handled separately, and more
 * fundamentally, by `Agend_Content_Access_Elementor::policy_is_dynamic_content()`,
 * which forces those elements to re-render per request. This flush only closes
 * the window for blobs that predate the plugin.
 */
function agend_content_access_flush_builder_cache(): void {
	if (
		did_action( 'elementor/loaded' )
		&& isset( \Elementor\Plugin::$instance->files_manager )
	) {
		\Elementor\Plugin::$instance->files_manager->clear_cache();
	}
}
register_activation_hook( __FILE__, 'agend_content_access_flush_builder_cache' );

// An upgrade replaces the files without firing the activation hook, so the same
// stale-blob window opens on update. `upgrader_process_complete` covers it.
add_action( 'upgrader_process_complete', 'agend_content_access_flush_builder_cache' );

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
 * Registers the Elementor fragment-policy integration.
 */
function agend_content_access_bootstrap_elementor(): void {
	require_once AGEND_CONTENT_ACCESS_DIR . 'includes/class-agend-content-access-elementor.php';
	require_once AGEND_CONTENT_ACCESS_DIR . 'includes/class-agend-content-access-protected-file-widget.php';

	add_action(
		'elementor/widgets/register',
		static function ( $widgets_manager ) {
			$widgets_manager->register(
				new Agend_Content_Access_Protected_File_Widget()
			);
		}
	);

	new Agend_Content_Access_Elementor();
}

/**
 * Registers this plugin's REST routes.
 *
 * Loaded on `rest_api_init` rather than at bootstrap so the route files are
 * only read on REST requests.
 */
function agend_content_access_bootstrap_rest(): void {
	require_once AGEND_CONTENT_ACCESS_DIR . 'includes/rest/catalogue-routes.php';
	require_once AGEND_CONTENT_ACCESS_DIR . 'includes/rest/connector-routes.php';

	agend_content_access_register_catalogue_routes();
	agend_content_access_register_connector_routes();
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
