<?php
/**
 * Content-settings schema for the Agend Filter surface.
 *
 * Transcribed from the Agend Filter widget's register_controls()'s two
 * Content-tab sections ("Filter" and "Values"). The `choices` REPEATER field
 * is a control the shared vocabulary cannot describe, so it stays an
 * `adapter` field; see the widget's register_adapter_control().
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Agend Filter widget's content-settings schema.
 *
 * @return array
 */
function agend_apps_records_schema_filter(): array {
	return array(
		'sections' => array(
			array(
				'id'     => 'section_filter',
				'label'  => __( 'Filter', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'        => 'filter',
						'label'       => __( 'Filter', 'agend-apps-core' ),
						'type'        => 'select',
						'default'     => 'event:search',
						'groups'      => 'agend_apps_records_filter_options',
						'label_block' => true,
						'description' => __( 'Choose the filter for the catalogue this template belongs to. A filter from another catalogue renders nothing.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'custom_field_key',
						'label'       => __( 'Custom field key', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => '',
						'description' => __( 'The key as configured in Agend, for example education_level. The field must be configured as a search filter on the account, and a visitor who is not entitled to read it never sees this control.', 'agend-apps-core' ),
						'condition'   => array( 'filter' => 'listing:custom_field' ),
					),
					array(
						'name'        => 'control',
						'label'       => __( 'Presentation', 'agend-apps-core' ),
						'type'        => 'select',
						'default'     => '',
						'options'     => array(
							''           => __( 'Default for this filter', 'agend-apps-core' ),
							'search'     => __( 'Search box', 'agend-apps-core' ),
							'select'     => __( 'Dropdown', 'agend-apps-core' ),
							'checkboxes' => __( 'Checkboxes', 'agend-apps-core' ),
							'buttons'    => __( 'Buttons', 'agend-apps-core' ),
							'date'       => __( 'Date', 'agend-apps-core' ),
							'range'      => __( 'Number range', 'agend-apps-core' ),
							'reset'      => __( 'Clear button', 'agend-apps-core' ),
						),
						'description' => __( 'Presentations the chosen filter does not support fall back to its default.', 'agend-apps-core' ),
					),
					array(
						'name'    => 'show_label',
						'label'   => __( 'Show label', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'        => 'label',
						'label'       => __( 'Label', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => '',
						'description' => __( 'Leave empty for the filter\'s own name.', 'agend-apps-core' ),
					),
					array(
						'name'      => 'placeholder',
						'label'     => __( 'Placeholder', 'agend-apps-core' ),
						'type'      => 'text',
						'default'   => '',
						'condition' => array( 'control' => array( '', 'search', 'date', 'reset' ) ),
					),
					array(
						'name'        => 'any_label',
						'label'       => __( '"Any" option label', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => '',
						'description' => __( 'The option that clears this filter, for example "All Categories".', 'agend-apps-core' ),
						'condition'   => array( 'control!' => array( 'search', 'date' ) ),
					),
				),
			),
			array(
				'id'        => 'section_values',
				'label'     => __( 'Values', 'agend-apps-core' ),
				'condition' => array( 'control!' => array( 'search', 'date', 'reset', 'range' ) ),
				'fields'    => array(
					array(
						'name'        => 'values_mode',
						'label'       => __( 'Values', 'agend-apps-core' ),
						'type'        => 'select',
						'default'     => 'all',
						'options'     => array(
							'all'     => __( 'Every value that exists', 'agend-apps-core' ),
							'choices' => __( 'Choices I define', 'agend-apps-core' ),
						),
						'description' => __( 'Defined choices each send a fixed selection, so one choice can stand for several values, for example "All States". Tag and badge filters always use defined choices, because the API cannot list their values yet.', 'agend-apps-core' ),
					),
					array(
						'name'  => 'choices',
						'label' => __( 'Choices', 'agend-apps-core' ),
						'type'  => 'adapter',
					),
				),
			),
		),
	);
}
