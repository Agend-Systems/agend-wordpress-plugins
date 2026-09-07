<?php
/**
 * Content-settings schema for the Agend Field surface.
 *
 * Transcribed from the Agend Field widget's register_controls()'s two
 * Content-tab sections ("Field" and "Formatting"). The Style-tab ("Text")
 * section is untouched and stays hand-declared in the widget.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Agend Field widget's content-settings schema.
 *
 * @return array
 */
function agend_apps_records_schema_record_field(): array {
	return array(
		'sections' => array(
			array(
				'id'     => 'section_field',
				'label'  => __( 'Field', 'agend-apps-core' ),
				'fields' => array(
					agend_apps_records_schema_record_type_field(),
					array(
						'name'        => 'field',
						'label'       => __( 'Field', 'agend-apps-core' ),
						'type'        => 'select',
						'default'     => 'common:title',
						'groups'      => 'agend_apps_records_field_options',
						'label_block' => true,
						'description' => __( 'Common fields work in both event and course templates. Event and Course fields render only inside a template of that type.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'custom_field_key',
						'label'       => __( 'Custom field key', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => '',
						'description' => __( 'The key as configured in Agend, for example education_level. Which custom fields a visitor receives depends on their entitlements, so this renders empty for a visitor who is not entitled to it.', 'agend-apps-core' ),
						'condition'   => array( 'field' => array( 'common:custom_field', 'common:custom_field_label' ) ),
					),
					array(
						'name'    => 'html_tag',
						'label'   => __( 'HTML tag', 'agend-apps-core' ),
						'type'    => 'select',
						'default' => 'div',
						'options' => array(
							'div'  => 'div',
							'span' => 'span',
							'p'    => 'p',
							'h1'   => 'H1',
							'h2'   => 'H2',
							'h3'   => 'H3',
							'h4'   => 'H4',
							'h5'   => 'H5',
							'h6'   => 'H6',
						),
					),
					array(
						'name'        => 'before_text',
						'label'       => __( 'Text before', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => '',
						'description' => __( 'Shown before the value, for example "From " ahead of a price.', 'agend-apps-core' ),
					),
					array(
						'name'    => 'after_text',
						'label'   => __( 'Text after', 'agend-apps-core' ),
						'type'    => 'text',
						'default' => '',
					),
					array(
						'name'        => 'fallback_text',
						'label'       => __( 'Fallback text', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => '',
						'description' => __( 'Shown when the record has no value for this field. Leave empty to render nothing.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'link_to_detail',
						'label'       => __( 'Link to detail page', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => false,
						'description' => __( 'Ignored when the whole card is already a link.', 'agend-apps-core' ),
					),
				),
			),
			array(
				'id'     => 'section_format',
				'label'  => __( 'Formatting', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'        => 'date_format',
						'label'       => __( 'Date format', 'agend-apps-core' ),
						'type'        => 'select',
						'default'     => '',
						'options'     => array(
							''         => __( 'Site default', 'agend-apps-core' ),
							'j M Y'    => '6 Jul 2026',
							'D, j M Y' => 'Mon, 6 Jul 2026',
							'l, j F Y' => 'Monday, 6 July 2026',
							'j M'      => '6 Jul',
							'j'        => '6',
							'M'        => 'Jul',
							'g:i a'    => '9:00 am',
							'custom'   => __( 'Custom', 'agend-apps-core' ),
						),
						'description' => __( 'Applies to date fields. Date range and date-and-time fields use their fixed format.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'date_format_custom',
						'label'       => __( 'Custom date format', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => 'j M Y',
						'description' => __( 'A PHP date format string.', 'agend-apps-core' ),
						'condition'   => array( 'date_format' => 'custom' ),
					),
					array(
						'name'        => 'price_free_label',
						'label'       => __( 'Free label', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => __( 'Free', 'agend-apps-core' ),
						'description' => __( 'Applies to price fields when the price is zero.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'list_separator',
						'label'       => __( 'List separator', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => ', ',
						'description' => __( 'Applies to list fields such as categories and learning outcomes.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'list_max',
						'label'       => __( 'Maximum list items', 'agend-apps-core' ),
						'type'        => 'number',
						'default'     => 0,
						'min'         => 0,
						'description' => __( '0 shows every item.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'bool_true',
						'label'       => __( 'Text when true', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => __( 'Yes', 'agend-apps-core' ),
						'description' => __( 'Applies to yes/no fields such as Sold out.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'bool_false',
						'label'       => __( 'Text when false', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => '',
						'description' => __( 'Leave empty to render nothing when false.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'number_suffix',
						'label'       => __( 'Number suffix', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => '',
						'description' => __( 'Applies to number fields, for example " lessons" or "%".', 'agend-apps-core' ),
					),
					array(
						'name'        => 'truncate_chars',
						'label'       => __( 'Truncate to characters', 'agend-apps-core' ),
						'type'        => 'number',
						'default'     => 0,
						'min'         => 0,
						'description' => __( 'Applies to text and HTML fields. HTML fields are reduced to plain text when truncated. 0 keeps the full value.', 'agend-apps-core' ),
					),
				),
			),
		),
	);
}
