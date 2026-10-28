<?php
/**
 * Plugin Name:       Agend Content Access
 * Plugin URI:        https://agend.com.au
 * Update URI:        https://agend-systems.github.io/agend-wordpress-plugins/agend-content-access
 * Description:       Restricts WordPress content to Agend members and membership plans. Agend authorises every protected response; WordPress only declares the intended audience.
 * Version:           0.2.0
 * Author:            Agend
 * Author URI:        https://agend.com.au
 * Text Domain:       agend-content-access
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Tested up to:      7.1
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
define( 'AGEND_CONTENT_ACCESS_VERSION', '0.2.0' );

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
	require_once AGEND_CONTENT_ACCESS_DIR . 'includes/class-agend-content-access-fragment-rules.php';
	require_once AGEND_CONTENT_ACCESS_DIR . 'includes/class-agend-content-access-block-attributes.php';
	require_once AGEND_CONTENT_ACCESS_DIR . 'includes/class-agend-content-access-viewer-facts.php';
	require_once AGEND_CONTENT_ACCESS_DIR . 'includes/class-agend-content-access-webhooks.php';

	// The one rule list and the one evaluator, with the condition engine and the
	// providers it runs on: every decision this plugin makes goes through them, so
	// they are loaded before anything that decides.
	require_once AGEND_CONTENT_ACCESS_DIR . 'includes/class-agend-content-access-conditions.php';
	require_once AGEND_CONTENT_ACCESS_DIR . 'includes/class-agend-content-access-condition-providers.php';
	require_once AGEND_CONTENT_ACCESS_DIR . 'includes/class-agend-content-access-condition-runtime.php';
	require_once AGEND_CONTENT_ACCESS_DIR . 'includes/class-agend-content-access-rules.php';
	require_once AGEND_CONTENT_ACCESS_DIR . 'includes/class-agend-content-access-decision.php';
	require_once AGEND_CONTENT_ACCESS_DIR . 'includes/class-agend-content-access-access.php';
	require_once AGEND_CONTENT_ACCESS_DIR . 'includes/class-agend-content-access-meta-box.php';
	require_once AGEND_CONTENT_ACCESS_DIR . 'includes/class-agend-content-access-page-rules.php';
	require_once AGEND_CONTENT_ACCESS_DIR . 'includes/class-agend-content-access-frontend.php';
	require_once AGEND_CONTENT_ACCESS_DIR . 'includes/class-agend-content-access-comments.php';
	require_once AGEND_CONTENT_ACCESS_DIR . 'includes/class-agend-content-access-placeholder.php';
	require_once AGEND_CONTENT_ACCESS_DIR . 'includes/class-agend-content-access-destination.php';

	// Loaded whether or not Elementor is. The class declares nothing that needs
	// Elementor and is only INSTANTIATED when it is present (see
	// `agend_content_access_bootstrap_elementor()`), but the document rules check
	// reads a stored Elementor document with its static `subtree_carries_rules()`,
	// and a site that deactivated Elementor still has those documents.
	require_once AGEND_CONTENT_ACCESS_DIR . 'includes/class-agend-content-access-elementor.php';
	require_once AGEND_CONTENT_ACCESS_DIR . 'includes/class-agend-content-access-blocks.php';
	require_once AGEND_CONTENT_ACCESS_DIR . 'includes/class-agend-content-access-credentials.php';
	require_once AGEND_CONTENT_ACCESS_DIR . 'includes/class-agend-content-access-exporter.php';
	require_once AGEND_CONTENT_ACCESS_DIR . 'includes/interface-agend-content-access-document-parser.php';
	require_once AGEND_CONTENT_ACCESS_DIR . 'includes/class-agend-content-access-elementor-parser.php';
	require_once AGEND_CONTENT_ACCESS_DIR . 'includes/class-agend-content-access-block-parser.php';
	require_once AGEND_CONTENT_ACCESS_DIR . 'includes/class-agend-content-access-block-editor.php';

	// Gating is registered on EVERY request, admin included: the REST filters
	// hang off it and a REST call never reaches template_redirect.
	new Agend_Content_Access_Frontend();

	// The comments of a restricted page: closed on a page the visitor is denied, and
	// kept out of every comment query and the REST comments route. Also on every
	// request, because a REST request never reaches template_redirect.
	new Agend_Content_Access_Comments();

	// The placeholder a denied page shows, its `[agend_access_message]` shortcode
	// and its block pattern. On every request: the shortcode runs wherever content
	// does and the editor, which is a REST request, needs the pattern.
	Agend_Content_Access_Placeholder::init();

	// Webhook invalidation, on EVERY request for the same reason: a delivery from
	// Agend is a REST request, so nothing here may wait for `is_admin()`.
	Agend_Content_Access_Webhooks::init();

	// Block editor enforcement, on every request for the same reason: a REST
	// request renders blocks and never reaches template_redirect.
	new Agend_Content_Access_Blocks();

	// The block editor's `agendAccess` attribute and its panel. Also on every
	// request: the block renderer is a REST request, and it rejects an
	// attribute the block type does not declare.
	new Agend_Content_Access_Block_Editor();

	// A page's rules: the meta, and the REST field the block editor's page panel
	// saves through. Also on every request, because a REST request is not
	// `is_admin()` and the block editor saves through one.
	new Agend_Content_Access_Page_Rules();

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

	// The condition vocabularies the editors offer (US-6.1). The engine itself is
	// loaded above, with the evaluator.
	require_once AGEND_CONTENT_ACCESS_DIR . 'includes/class-agend-content-access-condition-sets.php';

	// Legacy public-original audit (US-5.3) and its CLI surface. The audit is
	// loaded always, so it is unit-testable; the command only under WP-CLI.
	require_once AGEND_CONTENT_ACCESS_DIR . 'includes/class-agend-content-access-originals-audit.php';

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		require_once AGEND_CONTENT_ACCESS_DIR . 'includes/class-agend-content-access-cli.php';
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

		// The classic editor's panel: its assets are queued by the meta box itself.
		new Agend_Content_Access_Meta_Box();
		new Agend_Content_Access_Admin();
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
 * Registers the Elementor fragment-policy integration.
 */
/**
 * Promotes protected files on a saved document into Agend storage.
 *
 * On `save_post` rather than on upload: that is the moment the editor has
 * committed to the choice. Promoting at upload time would move files somebody
 * was only previewing, and doing it lazily on first view would leave the
 * public copy in `wp-content/uploads` for however long that took.
 *
 * A failure is recorded and leaves the file where it is. One unreachable
 * upload must not abort the save of an otherwise-good page.
 *
 * @param int $post_id Post being saved.
 */
function agend_content_access_promote_saved_assets( $post_id ): void {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	if ( wp_is_post_revision( $post_id ) ) {
		return;
	}

	if ( ! function_exists( 'agend_apps_cms_upload_asset' ) ) {
		return;
	}

	$result = Agend_Content_Access_Assets::promote_document(
		(int) $post_id,
		static function ( int $attachment_id ) {
			return Agend_Content_Access_Assets::promote(
				$attachment_id,
				array( 'Agend_Content_Access_Assets', 'attachment_meta' ),
				'agend_apps_cms_upload_asset',
				'wp_delete_attachment'
			);
		}
	);

	if ( array() !== $result['errors'] ) {
		set_transient(
			'agend_content_access_promote_errors_' . (int) $post_id,
			$result['errors'],
			5 * MINUTE_IN_SECONDS
		);
	}
}

function agend_content_access_bootstrap_elementor(): void {
	require_once AGEND_CONTENT_ACCESS_DIR . 'includes/class-agend-content-access-elementor.php';

	// The widget file is required INSIDE the registration callback, not here.
	//
	// `elementor/loaded` fires while Elementor is still booting, before
	// `Elementor\Widget_Base` exists, so requiring a file that extends it at
	// this point is a fatal error on activation. `elementor/widgets/register`
	// is the first moment the base class is guaranteed to be loaded.
	//
	// Found by activating the plugin on a real site. No unit test could catch
	// it: the harness has no Elementor at all, so the class is absent there by
	// definition and the require is never reached.
	add_action(
		'elementor/widgets/register',
		static function ( $widgets_manager ) {
			require_once AGEND_CONTENT_ACCESS_DIR . 'includes/class-agend-content-access-protected-file-widget.php';

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
	require_once AGEND_CONTENT_ACCESS_DIR . 'includes/rest/conditions-routes.php';

	agend_content_access_register_catalogue_routes();
	agend_content_access_register_connector_routes();
	agend_content_access_register_conditions_routes();
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
