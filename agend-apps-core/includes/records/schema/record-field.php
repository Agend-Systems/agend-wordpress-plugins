<?php
/**
 * Content-settings schema for the Agend Field surface.
 *
 * The Style-tab ("Text") section is untouched and stays hand-declared in the
 * widget.
 *
 * There is deliberately no `record_type` control here. The chosen field names
 * the record type on its own (`listing:name` can only be a listing), so a
 * second control could only ever contradict it: it left the field list
 * offering event fields to a widget an author had already set to Directory
 * listing. `common:` fields follow whatever the surrounding template renders.
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
					array(
						'name'        => 'field',
						'label'       => __( 'Field', 'agend-apps-core' ),
						'type'        => 'select',
						'default'     => 'common:title',
						'groups'      => 'agend_apps_records_field_options',
						'label_block' => true,
						'description' => __( 'Common fields work in any template, resolving to that record type\'s equivalent. A field from one record type renders only inside a template of that type.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'custom_field_key_choice',
						'label'       => __( 'Custom field', 'agend-apps-core' ),
						'type'        => 'select',
						'default'     => '',
						'options'     => 'agend_apps_records_custom_field_key_options',
						'label_block' => true,
						'description' => __( 'The custom fields this site can read. Which of them a visitor receives depends on their entitlements, so a field renders empty for a visitor who is not entitled to it.', 'agend-apps-core' ),
						'condition'   => array( 'field' => array( 'common:custom_field', 'common:custom_field_label' ) ),
					),
					array(
						'name'        => 'custom_field_key',
						'label'       => __( 'Custom field key', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => '',
						'description' => __( 'The key as configured in Agend, for example education_level. Use this for a field that does not exist yet, or that this site\'s key cannot enumerate.', 'agend-apps-core' ),
						'condition'   => array(
							'field'                   => array( 'common:custom_field', 'common:custom_field_label' ),
							'custom_field_key_choice' => '',
						),
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
						'description' => __( 'Puts the field\'s own name before the value. A custom field uses the label configured in Agend.', 'agend-apps-core' ),
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
						'name'        => 'label_separator',
						'label'       => __( 'After the label', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => ': ',
						'description' => __( 'Printed between the label and the value.', 'agend-apps-core' ),
						'condition'   => array( 'show_label' => 'yes' ),
					),
					array(
						'name'        => 'label_block_display',
						'label'       => __( 'Label on its own line', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => false,
						'condition'   => array( 'show_label' => 'yes' ),
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

/**
 * The custom field keys this site can offer an author, as key => label.
 *
 * The gateway has no endpoint enumerating custom field DEFINITIONS: it
 * returns them per record, as `{key,label,type,value}` on the record's
 * `custom_fields`. So the list is read off a real listing, which is
 * entitlement-scoped exactly like everything else the editor sees, and the
 * schema pairs it with a free-text control for a field that this site's key
 * cannot enumerate or that does not exist yet.
 *
 * Sourced from the editor preview record, so opening a panel costs no gateway
 * call the editor was not already making.
 *
 * @return array<string, string> '' => the "not listed" placeholder, then
 *                                key => label.
 */
function agend_apps_records_custom_field_key_options(): array {
	$options = array( '' => __( 'Not listed (enter a key below)', 'agend-apps-core' ) );

	if ( ! function_exists( 'agend_apps_records_preview_record' ) ) {
		return $options;
	}

	$record = agend_apps_records_preview_record( 'listing' );
	$fields = isset( $record['custom_fields'] ) && is_array( $record['custom_fields'] ) ? $record['custom_fields'] : array();

	foreach ( $fields as $field ) {
		if ( ! is_array( $field ) || empty( $field['key'] ) ) {
			continue;
		}
		$key             = (string) $field['key'];
		$options[ $key ] = ! empty( $field['label'] ) ? (string) $field['label'] : $key;
	}

	return $options;
}
