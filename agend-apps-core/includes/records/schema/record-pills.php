<?php
/**
 * Content-settings schema for the Agend Pills surface.
 *
 * Transcribed from the Content-tab controls that used to be hand-declared in
 * the Agend Pills widget's register_controls(). The Style-tab
 * ("Pills") section is untouched and stays hand-declared in the widget.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Agend Pills widget's content-settings schema.
 *
 * @return array
 */
function agend_apps_records_schema_record_pills(): array {
	return array(
		'sections' => array(
			array(
				'id'     => 'section_pills',
				'label'  => __( 'Pills', 'agend-apps-core' ),
				'fields' => array(
					agend_apps_records_schema_record_type_field(),
					array(
						'name'        => 'field',
						'label'       => __( 'Terms', 'agend-apps-core' ),
						'type'        => 'select',
						'default'     => 'common:category',
						'groups'      => 'agend_apps_records_pill_field_options',
						'label_block' => true,
						'description' => __( 'A list field renders one pill per term. A single-value field renders one pill.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'max_items',
						'label'       => __( 'Maximum pills', 'agend-apps-core' ),
						'type'        => 'number',
						'default'     => 0,
						'min'         => 0,
						'description' => __( '0 shows every term.', 'agend-apps-core' ),
					),
					array(
						'name'    => 'link_to_detail',
						'label'   => __( 'Link pills to the detail page', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => false,
					),
				),
			),
		),
	);
}
