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
							'location'   => __( 'Location search', 'agend-apps-core' ),
							'view'       => __( 'List / Map switch', 'agend-apps-core' ),
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
						'condition' => array( 'control' => array( '', 'search', 'date', 'reset', 'location' ) ),
					),
					array(
						'name'        => 'any_label',
						'label'       => __( '"Any" option label', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => '',
						'description' => __( 'The option that clears this filter, for example "All Categories".', 'agend-apps-core' ),
						'condition'   => array(
							'control!' => array( 'search', 'date' ),
							'filter!'  => array( 'listing:location', 'listing:view' ),
						),
					),
					array(
						'name'      => 'location_heading',
						'label'     => __( 'Location search', 'agend-apps-core' ),
						'type'      => 'heading',
						'separator' => 'before',
						'condition' => array( 'filter' => 'listing:location' ),
					),
					array(
						'name'        => 'location_show_search',
						'label'       => __( 'Place search box', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => true,
						'description' => __( 'A box for an address, suburb or postcode. It is hidden on the live page when the account does not include place search.', 'agend-apps-core' ),
						'condition'   => array( 'filter' => 'listing:location' ),
					),
					array(
						'name'        => 'location_button_text',
						'label'       => __( 'Search button text', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => '',
						'placeholder' => __( 'Search', 'agend-apps-core' ),
						'condition'   => array(
							'filter'               => 'listing:location',
							'location_show_search' => 'yes',
						),
					),
					array(
						'name'        => 'location_region',
						'label'       => __( 'Region', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => '',
						'placeholder' => __( 'Australia', 'agend-apps-core' ),
						'description' => __( 'Added to every place search, so a suburb that exists in several countries resolves to the right one. Leave empty to search worldwide.', 'agend-apps-core' ),
						'condition'   => array(
							'filter'               => 'listing:location',
							'location_show_search' => 'yes',
						),
					),
					array(
						'name'        => 'location_show_locate',
						'label'       => __( '"Use my location" button', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => true,
						'description' => __( 'Asks the visitor\'s browser for their position. Always shown when the place search box is off.', 'agend-apps-core' ),
						'condition'   => array( 'filter' => 'listing:location' ),
					),
					array(
						'name'        => 'location_locate_text',
						'label'       => __( '"Use my location" text', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => '',
						'placeholder' => __( 'Use my location', 'agend-apps-core' ),
						'condition'   => array( 'filter' => 'listing:location' ),
					),
					array(
						'name'        => 'location_radius_choices',
						'label'       => __( 'Radius choices (km)', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => '5,10,15,20,25,50,100',
						'description' => __( 'Separated by commas. A single value fixes the radius and hides the dropdown.', 'agend-apps-core' ),
						'condition'   => array( 'filter' => 'listing:location' ),
					),
					array(
						'name'      => 'location_radius_default',
						'label'     => __( 'Default radius (km)', 'agend-apps-core' ),
						'type'      => 'number',
						'default'   => 15,
						'min'       => 1,
						'max'       => 1000,
						'step'      => 1,
						'condition' => array( 'filter' => 'listing:location' ),
					),
					array(
						'name'      => 'view_heading',
						'label'     => __( 'List / Map switch', 'agend-apps-core' ),
						'type'      => 'heading',
						'separator' => 'before',
						'condition' => array( 'filter' => 'listing:view' ),
					),
					array(
						'name'        => 'view_default',
						'label'       => __( 'Starts on', 'agend-apps-core' ),
						'type'        => 'select',
						'default'     => 'list',
						'options'     => array(
							'list' => __( 'List', 'agend-apps-core' ),
							'map'  => __( 'Map', 'agend-apps-core' ),
						),
						'description' => __( 'Pair this with an Agend Map widget set to "Show only in Map view". Without one, Map shows nothing.', 'agend-apps-core' ),
						'condition'   => array( 'filter' => 'listing:view' ),
					),
					array(
						'name'        => 'view_layout',
						'label'       => __( 'Layout', 'agend-apps-core' ),
						'type'        => 'select',
						'default'     => 'separate',
						'options'     => array(
							'separate' => __( 'Separate buttons', 'agend-apps-core' ),
							'joined'   => __( 'Joined (one control)', 'agend-apps-core' ),
						),
						'description' => __( 'Joined puts the two buttons side by side inside one outline, the corner radius rounding only the outer corners.', 'agend-apps-core' ),
						'condition'   => array( 'filter' => 'listing:view' ),
					),
					array(
						'name'        => 'view_list_label',
						'label'       => __( 'List button text', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => '',
						'placeholder' => __( 'List', 'agend-apps-core' ),
						'condition'   => array( 'filter' => 'listing:view' ),
					),
					array(
						'name'        => 'view_map_label',
						'label'       => __( 'Map button text', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => '',
						'placeholder' => __( 'Map', 'agend-apps-core' ),
						'condition'   => array( 'filter' => 'listing:view' ),
					),
				),
			),
			array(
				'id'        => 'section_values',
				'label'     => __( 'Values', 'agend-apps-core' ),
				'condition' => array(
					'control!' => array( 'search', 'date', 'reset', 'range', 'location', 'view' ),
					'filter!'  => array( 'listing:location', 'listing:view' ),
				),
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
						'name'        => 'field_padding',
						'label'       => __( 'Horizontal padding', 'agend-apps-core' ),
						'type'        => 'select',
						'default'     => '',
						'options'     => 'agend_apps_records_filter_padding_options',
						'description' => __( 'Space between the field\'s edge and its text.', 'agend-apps-core' ),
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
						'name'        => 'button_background',
						'label'       => __( 'Button background', 'agend-apps-core' ),
						'type'        => 'colour',
						'default'     => '',
						'description' => __( 'The fill of a button that is not selected. Leave empty for none.', 'agend-apps-core' ),
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
						'name'    => 'button_align',
						'label'   => __( 'Align buttons', 'agend-apps-core' ),
						'type'    => 'select',
						'default' => '',
						'options' => array(
							''       => __( 'Start', 'agend-apps-core' ),
							'center' => __( 'Centre', 'agend-apps-core' ),
							'end'    => __( 'End', 'agend-apps-core' ),
						),
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

