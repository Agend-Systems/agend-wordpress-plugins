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
