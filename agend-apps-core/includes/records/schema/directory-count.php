<?php
/**
 * Content-settings schema for the Agend Directory Count surface.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Agend Directory Count widget's content-settings schema.
 *
 * @return array
 */
function agend_apps_records_schema_directory_count(): array {
	return array(
		'sections' => array(
			array(
				'id'     => 'section_content',
				'label'  => __( 'Content', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'    => 'label',
						'label'   => __( 'Label', 'agend-apps-core' ),
						'type'    => 'text',
						'default' => __( 'listings', 'agend-apps-core' ),
					),
					array(
						'name'        => 'label_singular',
						'label'       => __( 'Label for one', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => __( 'listing', 'agend-apps-core' ),
						'description' => __( 'Used when the total is exactly 1. Leave empty to use the label above.', 'agend-apps-core' ),
					),
					array(
						'name'    => 'layout',
						'label'   => __( 'Layout', 'agend-apps-core' ),
						'type'    => 'select',
						'default' => 'stacked',
						'options' => array(
							'stacked' => __( 'Number above label', 'agend-apps-core' ),
							'inline'  => __( 'Number beside label', 'agend-apps-core' ),
						),
					),
					array(
						'name'        => 'follow_catalogue',
						'label'       => __( 'Follow the catalogue\'s filtered total', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => false,
						'description' => __( 'On a page with a Directory Catalogue, the number changes to the count of listings matching the visitor\'s filters. Off, it always shows the whole directory.', 'agend-apps-core' ),
					),
				),
			),
		),
	);
}
