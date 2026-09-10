<?php
/**
 * Content-settings schema for the Agend Cart Header surface.
 *
 * Transcribed from the Agend Apps Shop Cart Header widget's
 * register_controls() (class-agend-apps-shop-cart-header.php), which stays
 * the source of truth for the Elementor editor: this schema only feeds the
 * block editor (see that widget's docblock for why the two are not merged
 * into one declaration).
 *
 * @package Agend_Apps_Shop
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Agend Cart Header widget's content-settings schema.
 *
 * `cart_icon` is an Elementor ICONS control, which the shared vocabulary
 * cannot describe, so it stays an `adapter` field: the schema keeps its name
 * and position, but it is not editable from the block inspector (see
 * agend_apps_records_render_cart_header()'s icon-fallback handling for how the
 * renderer copes with that on the block side).
 *
 * `cart_page_url` is a plain URL control, which the vocabulary DOES describe,
 * so it is a first-class `url` field and a block can set it. The widget's own
 * control declaration is hand-written and unchanged either way, since this
 * plugin cannot reach the Elementor schema adapter (see the parity test).
 *
 * @return array
 */
function agend_apps_records_schema_cart_header(): array {
	return array(
		'sections' => array(
			array(
				'id'     => 'section_cart_header',
				'label'  => __( 'Cart Header', 'agend-apps-shop' ),
				'fields' => array(
					array(
						'name'  => 'cart_icon',
						'type'  => 'adapter',
						'label' => __( 'Cart Icon', 'agend-apps-shop' ),
					),
					array(
						'name'        => 'cart_page_url',
						'type'        => 'url',
						'default'     => '',
						'label'       => __( 'Cart Page URL', 'agend-apps-shop' ),
						'description' => __( 'If left blank, falls back to the Cart Page URL set in Agend Apps Shop settings.', 'agend-apps-shop' ),
					),
					array(
						'name'    => 'badge_color',
						'label'   => __( 'Badge Background Color', 'agend-apps-shop' ),
						'type'    => 'colour',
						'default' => '#e74c3c',
					),
					array(
						'name'    => 'badge_text_color',
						'label'   => __( 'Badge Text Color', 'agend-apps-shop' ),
						'type'    => 'colour',
						'default' => '#ffffff',
					),
				),
			),
		),
	);
}
