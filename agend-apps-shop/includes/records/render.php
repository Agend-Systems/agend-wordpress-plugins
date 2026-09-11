<?php
/**
 * Loads the Agend Apps Shop cart surfaces' page-builder-agnostic renderers.
 *
 * agend_apps_records_render_block() (agend-apps-core/includes/records/blocks.php)
 * resolves a surface's renderer by a global function-name lookup, so this
 * plugin only has to define agend_apps_records_render_<surface>() here; core
 * needs nothing else to call them from a block. Each Elementor widget also
 * calls its own renderer directly (see class-agend-apps-shop-*.php).
 *
 * @package Agend_Apps_Shop
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/render/add-to-cart.php';
require_once __DIR__ . '/render/cart-header.php';
require_once __DIR__ . '/render/cart-view.php';
