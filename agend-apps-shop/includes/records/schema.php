<?php
/**
 * Loads the Agend Apps Shop cart surfaces' content-settings schemas.
 *
 * agend_apps_records_surface_schema() (agend-apps-core/includes/records/schema.php)
 * resolves a surface by a global function-name lookup, so this plugin only
 * has to define agend_apps_records_schema_<surface>() for its own cart
 * surfaces here; core needs nothing else to find them (see that function's
 * docblock).
 *
 * @package Agend_Apps_Shop
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/schema/add-to-cart.php';
require_once __DIR__ . '/schema/cart-header.php';
require_once __DIR__ . '/schema/cart-view.php';
