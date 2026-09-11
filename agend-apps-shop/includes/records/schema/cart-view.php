<?php
/**
 * Content-settings schema for the Agend Cart View surface.
 *
 * Transcribed from the Agend Apps Shop Cart View widget's
 * register_controls() (class-agend-apps-shop-cart-view.php), which stays the
 * source of truth for the Elementor editor: this schema only feeds the block
 * editor (see that widget's docblock for why the two are not merged into one
 * declaration).
 *
 * @package Agend_Apps_Shop
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Agend Cart View widget's content-settings schema.
 *
 * The widget's only control is an info-only RAW_HTML notice that stores no
 * value, so this schema has a single `note` field and nothing else: the
 * surface has no configurable settings on either adapter.
 *
 * @return array
 */
function agend_apps_records_schema_cart_view(): array {
	return array(
		'sections' => array(
			array(
				'id'     => 'section_cart_view_info',
				'label'  => __( 'Cart View', 'agend-apps-shop' ),
				'fields' => array(
					array(
						'name'    => 'cart_view_notice',
						'type'    => 'note',
						// Reworded from the widget's own copy to drop an em dash;
						// a note stores no value, so nothing is persisted by
						// this wording differing slightly from the widget's.
						'content' => __( 'This widget renders the full shopping cart and is intended for a dedicated cart page: no configuration is required, cart contents are loaded dynamically from the API.', 'agend-apps-shop' ),
					),
				),
			),
		),
	);
}
