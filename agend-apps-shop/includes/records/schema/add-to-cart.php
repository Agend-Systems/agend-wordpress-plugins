<?php
/**
 * Content-settings schema for the Agend Add to Cart surface.
 *
 * Transcribed from the Agend Apps Shop Add to Cart widget's
 * register_controls() (class-agend-apps-shop-add-to-cart.php), which stays
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
 * The Agend Add to Cart widget's content-settings schema.
 *
 * @return array
 */
function agend_apps_records_schema_add_to_cart(): array {
	return array(
		'sections' => array(
			array(
				'id'     => 'section_product',
				'label'  => __( 'Product', 'agend-apps-shop' ),
				'fields' => array(
					array(
						'name'    => 'product_type',
						'label'   => __( 'Product Type', 'agend-apps-shop' ),
						'type'    => 'select',
						'options' => array(
							'event_tickets'        => __( 'Event Tickets', 'agend-apps-shop' ),
							'crm_membership_tiers' => __( 'Membership Tiers', 'agend-apps-shop' ),
							'courses'              => __( 'Courses', 'agend-apps-shop' ),
						),
						'default' => 'event_tickets',
					),
					array(
						'name'    => 'product_id',
						'label'   => __( 'Product ID (UUID)', 'agend-apps-shop' ),
						'type'    => 'text',
						'default' => '',
						// The widget control also carries a
						// 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx' placeholder; the
						// schema vocabulary has no placeholder key for a plain
						// text field (only the `template` type carries one), so
						// the block editor's control renders without that hint.
					),
					array(
						'name'    => 'button_label',
						'label'   => __( 'Button Label', 'agend-apps-shop' ),
						'type'    => 'text',
						'default' => __( 'Add to Cart', 'agend-apps-shop' ),
					),
					array(
						'name'    => 'quantity',
						'label'   => __( 'Default Quantity', 'agend-apps-shop' ),
						'type'    => 'number',
						'min'     => 1,
						'max'     => 100,
						'default' => 1,
					),
					array(
						'name'        => 'max_quantity',
						'label'       => __( 'Max Quantity Override', 'agend-apps-shop' ),
						'type'        => 'number',
						'min'         => 0,
						'default'     => 0,
						'description' => __( 'Set to 0 to allow the API to enforce its own limit.', 'agend-apps-shop' ),
					),
				),
			),
		),
	);
}
