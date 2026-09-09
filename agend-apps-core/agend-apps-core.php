<?php
/**
 * Plugin Name:       Agend Apps Core
 * Plugin URI:        https://agend.com.au
 * Update URI:        https://agend-systems.github.io/agend-wordpress-plugins/agend-apps-core
 * Description:       Foundational plugin for the Agend Apps ecosystem. Provides the API client, REST proxy endpoints, and admin configuration for all Agend sibling plugins.
 * Version:           1.14.1
 * Author:            Agend
 * Author URI:        https://agend.com.au
 * Text Domain:       agend-apps-core
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Tested up to:      7.1
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
define( 'AGEND_APPS_CORE_VERSION', '1.14.1' );

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
define( 'AGEND_APPS_API_PRODUCTION_URL', 'https://api.agend.com.au' );

/**
 * Staging Agend Gateway API root (unversioned).
 *
 * @var string
 */
define( 'AGEND_APPS_API_STAGING_URL', 'https://api.agend.info' );

/**
 * Local development Agend Gateway API root (unversioned).
 *
 * @var string
 */
define( 'AGEND_APPS_API_LOCAL_URL', 'http://localhost:3072' );

/**
 * Production Agend member portal root.
 *
 * @var string
 */
define( 'AGEND_APPS_PORTAL_PRODUCTION_URL', 'https://portal.agend.com.au' );

/**
 * Staging Agend member portal root.
 *
 * @var string
 */
define( 'AGEND_APPS_PORTAL_STAGING_URL', 'https://portal.agend.info' );

/**
 * Local development Agend member portal root.
 *
 * @var string
 */
define( 'AGEND_APPS_PORTAL_LOCAL_URL', 'http://localhost:3074' );

/**
 * Rewrite ruleset version. Bump whenever the rewrite endpoints registered in
 * includes/records/routing.php change, so the versioned auto-flush
 * regenerates the rules on the next request after an update deploy.
 *
 * @var string
 */
define( 'AGEND_APPS_RECORDS_REWRITE_VERSION', '20260723-1' );

// Detail-URL rewrite endpoints (SPEC-INFRA-20260717 US-1.1). Loaded
// unconditionally so the endpoints register even when a page-builder plugin
// consuming them is temporarily unavailable; the widgets that consume them
// stay gated.
require_once AGEND_APPS_CORE_DIR . 'includes/records/routing.php';

// Settings (server-rendered detail toggle). Loaded unconditionally so the
// accessor is available on the front-end `wp` hook and in the admin.
require_once AGEND_APPS_CORE_DIR . 'includes/records/settings.php';

// Dedicated catalogue pages (fixes the host-page hijack: a catalogue used as
// a homepage CTA no longer turns the homepage into the detail page). Loaded
// unconditionally, like settings, so the wp:4 redirect and the widgets'
// page_url()/detail_url() calls work even before this plugin's own bootstrap
// runs.
require_once AGEND_APPS_CORE_DIR . 'includes/records/pages.php';

// GitHub Releases updater, shared by every Agend plugin (Core is a
// dependency of all of them). Loaded and booted unconditionally, ahead of
// `agend_apps_core_bootstrap()`, because update checks run in admin and
// `wp-cron.php` contexts that do not otherwise run the full bootstrap.
require_once AGEND_APPS_CORE_DIR . 'includes/class-agend-apps-updater.php';
agend_apps_updater_boot();

register_activation_hook( __FILE__, 'agend_apps_records_activate_rewrites' );
register_deactivation_hook( __FILE__, 'agend_apps_records_deactivate_rewrites' );

/**
 * Loads all plugin includes and initialises the admin controller.
 *
 * Hooked on `plugins_loaded` so all WordPress APIs are available before
 * any include is executed.
 */
function agend_apps_core_bootstrap() {
	require_once AGEND_APPS_CORE_DIR . 'includes/templates/interface-agend-apps-template-renderer.php';
	require_once AGEND_APPS_CORE_DIR . 'includes/templates/interface-agend-apps-template-source.php';
	require_once AGEND_APPS_CORE_DIR . 'includes/templates/class-agend-apps-templates.php';

	// Shared front-end assets: the CSS/JS behind the catalogue widgets, and the
	// handful of helpers (shop cart detection, DOMPurify) they depend on.
	require_once AGEND_APPS_CORE_DIR . 'includes/records/assets.php';

	// Server-rendered detail pages (opt-in). The format/fragments helpers are
	// split out so field widgets can reuse them without pulling in the whole
	// SSR detail machinery.
	require_once AGEND_APPS_CORE_DIR . 'includes/records/format.php';
	require_once AGEND_APPS_CORE_DIR . 'includes/records/fragments.php';
	require_once AGEND_APPS_CORE_DIR . 'includes/records/ssr-detail.php';

	// Template-driven cards and detail pages: the record context the field
	// widgets read, the field registry, and the editor preview records.
	require_once AGEND_APPS_CORE_DIR . 'includes/records/record-context.php';
	require_once AGEND_APPS_CORE_DIR . 'includes/records/fields.php';
	require_once AGEND_APPS_CORE_DIR . 'includes/records/preview-records.php';
	require_once AGEND_APPS_CORE_DIR . 'includes/records/filters.php';
	require_once AGEND_APPS_CORE_DIR . 'includes/records/query.php';

	// Content-settings schema: the page-builder-agnostic declaration every
	// presentation adapter (Elementor, later the block editor) renders its
	// controls from, instead of each hand-declaring the same settings.
	require_once AGEND_APPS_CORE_DIR . 'includes/records/schema.php';

	// Site palette resolution (US-1.3): the renderer reads this before the
	// renders below build their config, since colour resolution is a render
	// dependency, not a display concern.
	require_once AGEND_APPS_CORE_DIR . 'includes/records/palette.php';

	// Server renders of the catalogue surfaces, shared by every editor adapter.
	require_once AGEND_APPS_CORE_DIR . 'includes/records/render/events-catalogue.php';
	require_once AGEND_APPS_CORE_DIR . 'includes/records/render/courses-catalogue.php';
	require_once AGEND_APPS_CORE_DIR . 'includes/records/render/directory-catalogue.php';
	require_once AGEND_APPS_CORE_DIR . 'includes/records/render/memberships-catalogue.php';
	require_once AGEND_APPS_CORE_DIR . 'includes/records/render/member-login.php';

	// The block editor surface: attributes from the schemas, output from the renderers.
	require_once AGEND_APPS_CORE_DIR . 'includes/records/blocks.php';

	require_once AGEND_APPS_CORE_DIR . 'includes/records/cards.php';
	require_once AGEND_APPS_CORE_DIR . 'includes/records/rest/fragments-controller.php';

	// Deprecated pre-rename names. Last among the records requires: it aliases
	// classes and constants those files define.
	require_once AGEND_APPS_CORE_DIR . 'includes/records/deprecated.php';

	add_action(
		'rest_api_init',
		static function () {
			( new Agend_Apps_Records_Fragments_Controller() )->register_routes();
		}
	);

	require_once AGEND_APPS_CORE_DIR . 'includes/class-agend-apps-secret-store.php';
	require_once AGEND_APPS_CORE_DIR . 'includes/class-agend-apps-settings.php';
	require_once AGEND_APPS_CORE_DIR . 'includes/class-agend-apps-cache.php';
	require_once AGEND_APPS_CORE_DIR . 'includes/class-agend-apps-api.php';

	// Cached API-key scopes (SPEC-CORE-20260908 scope-gated features): backs
	// the optional-feature registry below, so a feature needing a gateway
	// scope the connected key does not hold can be switched off rather than
	// sent and 403'd.
	require_once AGEND_APPS_CORE_DIR . 'includes/class-agend-apps-key-scopes.php';

	require_once AGEND_APPS_CORE_DIR . 'includes/identity.php';
	require_once AGEND_APPS_CORE_DIR . 'includes/class-agend-apps-token-worker.php';
	require_once AGEND_APPS_CORE_DIR . 'includes/class-agend-apps-member-session.php';
	require_once AGEND_APPS_CORE_DIR . 'includes/account-link-state.php';

	// Member sign-in mode (SPEC-CORE-20260907 US-4.1): in `sso` mode the
	// credential login surface does not exist, not merely stand down per
	// email. `Agend_Apps_Settings` is already loaded above.
	$agend_apps_credential_login_enabled = Agend_Apps_Settings::credential_login_enabled();

	if ( $agend_apps_credential_login_enabled ) {
		require_once AGEND_APPS_CORE_DIR . 'includes/member-identity.php';
		require_once AGEND_APPS_CORE_DIR . 'includes/member-provisioning.php';
	}

	require_once AGEND_APPS_CORE_DIR . 'includes/member-membership-sync.php';
	require_once AGEND_APPS_CORE_DIR . 'includes/sanitize.php';
	require_once AGEND_APPS_CORE_DIR . 'includes/api/health.php';

	// Optional-feature registry: which scope-gated feature needs which scope,
	// and whether it is currently available. Depends on the health API above
	// (Agend_Apps_Key_Scopes::refresh() calls agend_apps_verify_api_key()) and
	// the records settings option constants (loaded unconditionally earlier).
	require_once AGEND_APPS_CORE_DIR . 'includes/records/features.php';

	require_once AGEND_APPS_CORE_DIR . 'includes/api/cart.php';
	require_once AGEND_APPS_CORE_DIR . 'includes/api/directory.php';
	require_once AGEND_APPS_CORE_DIR . 'includes/api/loop-integration.php';
	require_once AGEND_APPS_CORE_DIR . 'includes/api/auth.php';
	require_once AGEND_APPS_CORE_DIR . 'includes/api/crm.php';
	require_once AGEND_APPS_CORE_DIR . 'includes/api/events.php';
	require_once AGEND_APPS_CORE_DIR . 'includes/api/lms.php';
	require_once AGEND_APPS_CORE_DIR . 'includes/api/cms.php';
	require_once AGEND_APPS_CORE_DIR . 'includes/api/jobs.php';
	require_once AGEND_APPS_CORE_DIR . 'includes/api/sites.php';
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
	require_once AGEND_APPS_CORE_DIR . 'includes/rest/sites-routes.php';
	require_once AGEND_APPS_CORE_DIR . 'includes/rest/account-link-routes.php';

	// WooCommerce My Account "Directory" endpoint. Loaded unconditionally like
	// the routes above; every hook it registers checks
	// class_exists( 'WooCommerce' ) itself (see agend_apps_my_account_directory_enabled()).
	require_once AGEND_APPS_CORE_DIR . 'includes/my-account-directory.php';

	if ( $agend_apps_credential_login_enabled ) {
		require_once AGEND_APPS_CORE_DIR . 'includes/rest/auth-routes.php';
	}

	require_once AGEND_APPS_CORE_DIR . 'includes/rest/webhook-receiver-routes.php';

	if ( $agend_apps_credential_login_enabled ) {
		// Agend-first authentication for wp-login.php and wp_signon() callers.
		// Loaded after api/auth.php and rest/auth-routes.php, whose helpers
		// (gateway login, proxy-edge throttle) it reuses at authenticate time.
		require_once AGEND_APPS_CORE_DIR . 'includes/wp-login-bridge.php';
	} else {
		// SPEC-CORE-20260907 US-4.1 AC5: any sibling code that still consults
		// this filter directly (rather than checking credential_login_enabled())
		// also stands down in `sso` mode.
		add_filter( 'agend_apps_wp_login_bridge_enabled', '__return_false' );
	}

	// Bearer identity for outbound gateway calls (addendum E-11): mints and
	// caches the logged-in member's Supabase JWT, served via the
	// `agend_apps_bearer_token` filter.
	new Agend_Apps_Token_Worker();

	// Credential-login session (SPEC-CORE-20260722-wordpress-member-login):
	// serves the access token from a member's stored Agend session ahead of the
	// SSO worker, refreshing it as needed.
	new Agend_Apps_Member_Session();

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
	agend_apps_register_sites_routes();
	agend_apps_register_account_link_routes();

	if ( Agend_Apps_Settings::credential_login_enabled() ) {
		agend_apps_register_auth_routes();
	}

	agend_apps_register_webhook_receiver_routes();
}
add_action( 'rest_api_init', 'agend_apps_core_register_rest_routes' );

/**
 * Outputs the REST URL and WP nonce as a JS global for frontend consumers.
 *
 * Exposes `window.agendApps.restUrl` and `window.agendApps.nonce` so that
 * JS/Gutenberg blocks in sibling plugins can authenticate REST requests.
 */
function agend_apps_output_config_js() {
	$config = array(
		'restUrl'        => rest_url( 'agend-apps/v1/' ),
		'nonce'          => wp_create_nonce( 'wp_rest' ),
		'loggedIn'       => false,
		'memberAuthMode' => Agend_Apps_Settings::get_member_auth_mode(),
	);

	// Expose the Agend member-session state so every widget can render its
	// signed-in view synchronously without a session probe request
	// (SPEC-CORE-20260722 US-2.3/US-2.4/US-2.5/US-2.6). "Logged in" here means
	// a member session with a bearer is available for the current WP user, not
	// merely that some WP user is authenticated.
	$user_id = get_current_user_id();
	if (
		$user_id > 0 &&
		class_exists( 'Agend_Apps_Member_Session' ) &&
		Agend_Apps_Member_Session::has_session( $user_id )
	) {
		$user                = wp_get_current_user();
		$config['loggedIn']  = true;
		$config['member']    = array(
			'name'      => $user->display_name,
			'email'     => $user->user_email,
			'contactId' => (string) get_user_meta( $user_id, '_agend_apps_contact_id', true ),
		);
	}

	$data = wp_json_encode( $config );
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
	add_option( 'agend_apps_vercel_bypass_token', '' );

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
