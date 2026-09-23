<?php
/**
 * Content-settings schema for the Agend Pills surface.
 *
 * The Style-tab ("Pills") section is untouched and stays hand-declared in the
 * widget.
 *
 * There is no `record_type` control: the chosen terms field names the record
 * type on its own {@see agend_apps_records_schema_record_field()}.
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
					array(
						'name'      => 'label_heading',
						'label'     => __( 'Label', 'agend-apps-core' ),
						'type'      => 'heading',
						'separator' => 'before',
					),
					array(
						'name'        => 'show_label',
						'label'       => __( 'Show label', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => false,
						'description' => __( 'Puts the field\'s own name before the pills.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'label_text',
						'label'       => __( 'Label text', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => '',
						'description' => __( 'Leave empty to use the field\'s own name.', 'agend-apps-core' ),
						'condition'   => array( 'show_label' => 'yes' ),
					),
					array(
						'name'      => 'label_block_display',
						'label'     => __( 'Label on its own line', 'agend-apps-core' ),
						'type'      => 'toggle',
						'default'   => false,
						'condition' => array( 'show_label' => 'yes' ),
					),
				),
			),
			array(
				'id'     => 'section_style_colour_rules',
				'label'  => __( 'Colour rules', 'agend-apps-core' ),
				'tab'    => 'style',
				'fields' => array(
					array(
						'name'    => 'colour_rules_note',
						'type'    => 'note',
						'content' => __( 'Give particular terms their own colours, for example one colour per membership level. The first rule that matches a term wins; a term no rule matches keeps the pill style.', 'agend-apps-core' ),
					),
					array(
						'name'      => 'colour_rules',
						'label'     => __( 'Rules', 'agend-apps-core' ),
						'type'      => 'repeater',
						'row_label' => 'rule_match',
						'default'   => array(),
						'fields'    => array(
							array(
								'name'        => 'rule_match',
								'label'       => __( 'Matches', 'agend-apps-core' ),
								'type'        => 'text',
								'default'     => '',
								'description' => __( 'The term exactly as it appears, ignoring case. Several terms can be separated by commas.', 'agend-apps-core' ),
							),
							array(
								'name'    => 'rule_background',
								'label'   => __( 'Background', 'agend-apps-core' ),
								'type'    => 'colour',
								'default' => '',
							),
							array(
								'name'    => 'rule_text',
								'label'   => __( 'Text colour', 'agend-apps-core' ),
								'type'    => 'colour',
								'default' => '',
							),
						),
					),
				),
			),
		),
	);
}
