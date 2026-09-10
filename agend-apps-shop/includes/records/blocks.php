<?php
/**
 * Registers the Agend Apps Shop cart surfaces' blocks from this plugin's own
 * build directory.
 *
 * agend_apps_records_register_surface_blocks() lives in agend-apps-core
 * specifically so a sibling plugin can register its own surfaces' blocks
 * without core knowing about them (see that function's docblock); guarded
 * with function_exists() here so an older, already-installed core plugin
 * without it leaves the rest of this plugin working, just without the block
 * editor surfaces until core is updated.
 *
 * @package Agend_Apps_Shop
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Surface ids that ship as Agend Apps Shop blocks, in inserter order. */
const AGEND_APPS_SHOP_BLOCK_SURFACES = array( 'add-to-cart', 'cart-header', 'cart-view' );

/**
 * Registers every built block this plugin owns. The compiled block
 * directories live under build/blocks/, produced by `npm run build` (via the
 * `build:shop` script) and committed, so a deploy that only copies files
 * still has them.
 */
function agend_apps_shop_register_blocks(): void {
	if ( ! function_exists( 'agend_apps_records_register_surface_blocks' ) ) {
		return;
	}

	agend_apps_records_register_surface_blocks( AGEND_APPS_SHOP_DIR . 'build/blocks/', AGEND_APPS_SHOP_BLOCK_SURFACES );
}
add_action( 'init', 'agend_apps_shop_register_blocks' );
