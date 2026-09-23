<?php
/**
 * Content-settings schema for the Agend Filter surface.
 *
 * Transcribed from the Agend Filter widget's register_controls()'s two
 * Content-tab sections ("Filter" and "Values").
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
						'name'      => 'choices',
						'label'     => __( 'Choices', 'agend-apps-core' ),
						'type'      => 'repeater',
						'row_label' => 'choice_label',
						'default'   => array(),
						'condition' => array( 'values_mode' => 'choices' ),
						'fields'    => array(
							array(
								'name'    => 'choice_label',
								'label'   => __( 'Label', 'agend-apps-core' ),
								'type'    => 'text',
								'default' => '',
							),
							array(
								'name'        => 'choice_value',
								'label'       => __( 'Sends', 'agend-apps-core' ),
								'type'        => 'text',
								'default'     => '',
								'description' => __( 'One value, or several separated by commas. Several values match any of them.', 'agend-apps-core' ),
							),
						),
					),
				),
			),
			array(
				'id'     => 'section_style_field',
				'label'  => __( 'Field', 'agend-apps-core' ),
				'tab'    => 'style',
				'fields' => array(
					array(
						'name'    => 'field_note',
						'type'    => 'note',
						'content' => __( 'Styles the search box, dropdown, date and number fields. Anything left on its default keeps the theme\'s own form styling.', 'agend-apps-core' ),
					),
					array(
						'name'    => 'field_background',
						'label'   => __( 'Background', 'agend-apps-core' ),
						'type'    => 'colour',
						'default' => '',
					),
					array(
						'name'    => 'field_text_colour',
						'label'   => __( 'Text colour', 'agend-apps-core' ),
						'type'    => 'colour',
						'default' => '',
					),
					array(
						'name'    => 'field_border_colour',
						'label'   => __( 'Border colour', 'agend-apps-core' ),
						'type'    => 'colour',
						'default' => '',
					),
					array(
						'name'    => 'field_border_width',
						'label'   => __( 'Border width', 'agend-apps-core' ),
						'type'    => 'select',
						'default' => '',
						'options' => 'agend_apps_records_filter_border_width_options',
					),
					array(
						'name'      => 'field_border_sides',
						'label'     => __( 'Border sides', 'agend-apps-core' ),
						'type'      => 'select',
						'default'   => 'all',
						'options'   => array(
							'all'    => __( 'All sides', 'agend-apps-core' ),
							'bottom' => __( 'Bottom only', 'agend-apps-core' ),
						),
						'condition' => array( 'field_border_width!' => '' ),
					),
					array(
						'name'    => 'field_radius',
						'label'   => __( 'Corner radius', 'agend-apps-core' ),
						'type'    => 'select',
						'default' => '',
						'options' => 'agend_apps_records_filter_radius_options',
					),
					array(
						'name'    => 'field_height',
						'label'   => __( 'Height', 'agend-apps-core' ),
						'type'    => 'select',
						'default' => '',
						'options' => 'agend_apps_records_filter_height_options',
					),
					array(
						'name'        => 'field_focus_colour',
						'label'       => __( 'Focus colour', 'agend-apps-core' ),
						'type'        => 'colour',
						'default'     => '',
						'description' => __( 'The border and outline a field shows while it has keyboard focus.', 'agend-apps-core' ),
					),
				),
			),
			array(
				'id'     => 'section_style_options',
				'label'  => __( 'Buttons and checkboxes', 'agend-apps-core' ),
				'tab'    => 'style',
				'fields' => array(
					array(
						'name'    => 'button_colour',
						'label'   => __( 'Button colour', 'agend-apps-core' ),
						'type'    => 'colour',
						'default' => '',
					),
					array(
						'name'    => 'button_active_background',
						'label'   => __( 'Selected button background', 'agend-apps-core' ),
						'type'    => 'colour',
						'default' => '',
					),
					array(
						'name'    => 'button_active_text',
						'label'   => __( 'Selected button text', 'agend-apps-core' ),
						'type'    => 'colour',
						'default' => '',
					),
					array(
						'name'    => 'button_radius',
						'label'   => __( 'Button corner radius', 'agend-apps-core' ),
						'type'    => 'select',
						'default' => '',
						'options' => 'agend_apps_records_filter_radius_options',
					),
					array(
						'name'    => 'checkbox_colour',
						'label'   => __( 'Checkbox colour', 'agend-apps-core' ),
						'type'    => 'colour',
						'default' => '',
					),
				),
			),
		),
	);
}

