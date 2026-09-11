<?php
/**
 * Content-settings schema for the Agend Export Report surface.
 *
 * Transcribed from the Agend Export Report widget's register_controls()'s two
 * Content-tab sections ("Report" and "Parameters"). `reports_unavailable` is
 * conditionally registered only when the account has no export reports,
 * which the vocabulary's `condition` key (a field VALUE condition) cannot
 * express, so it stays an `adapter` field whose `register_adapter_control()`
 * reproduces the same runtime check. `parameters_note` carries a
 * `content_classes` key the `note` type's `control_args()` branch does not
 * pass through, so it also stays an `adapter` field rather than a `note` one.
 * See the widget's register_adapter_control().
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Agend Export Report widget's content-settings schema.
 *
 * @return array
 */
function agend_apps_records_schema_export_reports(): array {
	return array(
		'sections' => array(
			array(
				'id'     => 'section_report',
				'label'  => __( 'Report', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'    => 'mode',
						'label'   => __( 'Mode', 'agend-apps-core' ),
						'type'    => 'select',
						'default' => 'button',
						'options' => array(
							'button'   => __( 'Button for one report', 'agend-apps-core' ),
							'dropdown' => __( 'Menu of several reports', 'agend-apps-core' ),
						),
					),
					array(
						'name'        => 'report',
						'label'       => __( 'Report', 'agend-apps-core' ),
						'type'        => 'select',
						'default'     => '',
						'options'     => 'agend_apps_records_export_reports_report_options',
						'label_block' => true,
						'condition'   => array( 'mode' => 'button' ),
					),
					array(
						'name'      => 'reports',
						'label'     => __( 'Reports in the menu', 'agend-apps-core' ),
						'type'      => 'repeater',
						'row_label' => 'report_label',
						'default'   => array(),
						'condition' => array( 'mode' => 'dropdown' ),
						'fields'    => array(
							array(
								'name'        => 'report_id',
								'label'       => __( 'Report', 'agend-apps-core' ),
								'type'        => 'select',
								'default'     => '',
								'options'     => 'agend_apps_records_export_reports_report_options',
								'label_block' => true,
							),
							array(
								'name'        => 'report_label',
								'label'       => __( 'Label', 'agend-apps-core' ),
								'type'        => 'text',
								'default'     => '',
								'description' => __( 'Leave empty to use the report\'s own name.', 'agend-apps-core' ),
							),
						),
					),
					array(
						'name' => 'reports_unavailable',
						'type' => 'adapter',
					),
					array(
						'name'        => 'button_text',
						'label'       => __( 'Button text', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => __( 'Export', 'agend-apps-core' ),
						'description' => __( 'In menu mode this is the trigger that opens the list.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'format',
						'label'       => __( 'Format', 'agend-apps-core' ),
						'type'        => 'select',
						'default'     => 'csv',
						'options'     => array(
							'csv'  => __( 'CSV', 'agend-apps-core' ),
							'xlsx' => __( 'Excel', 'agend-apps-core' ),
						),
						'description' => __( 'Every download from this widget uses this format.', 'agend-apps-core' ),
					),
				),
			),
			array(
				'id'     => 'section_parameters',
				'label'  => __( 'Parameters', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name' => 'parameters_note',
						'type' => 'adapter',
					),
					array(
						'name'      => 'parameters',
						'label'     => __( 'Parameter mapping', 'agend-apps-core' ),
						'type'      => 'repeater',
						'row_label' => 'param_field',
						'default'   => array(),
						'fields'    => array(
							array(
								'name'        => 'param_field',
								'label'       => __( 'Parameter field', 'agend-apps-core' ),
								'type'        => 'text',
								'default'     => '',
								'placeholder' => 'keyword',
								'description' => __( 'The field the report parameter filters on.', 'agend-apps-core' ),
							),
							array(
								'name'    => 'param_source',
								'label'   => __( 'Value from', 'agend-apps-core' ),
								'type'    => 'select',
								'default' => 'manual',
								'options' => array(
									'manual'    => __( 'A value I set here', 'agend-apps-core' ),
									'catalogue' => __( 'The Directory Catalogue on this page', 'agend-apps-core' ),
								),
							),
							array(
								'name'      => 'param_value',
								'label'     => __( 'Value', 'agend-apps-core' ),
								'type'      => 'text',
								'default'   => '',
								'condition' => array( 'param_source' => 'manual' ),
							),
							array(
								'name'        => 'param_catalogue_filter',
								'label'       => __( 'Read from filter', 'agend-apps-core' ),
								'type'        => 'select',
								'default'     => '',
								'options'     => 'agend_apps_records_export_reports_catalogue_source_options',
								'description' => __( 'Takes whatever the visitor has this filter set to when they press the button.', 'agend-apps-core' ),
								'condition'   => array( 'param_source' => 'catalogue' ),
							),
							array(
								'name'        => 'param_custom_key',
								'label'       => __( 'Custom field key', 'agend-apps-core' ),
								'type'        => 'text',
								'default'     => '',
								'condition'   => array(
									'param_source'           => 'catalogue',
									'param_catalogue_filter' => 'custom_field',
								),
								'description' => __( 'Which custom field the catalogue filter targets.', 'agend-apps-core' ),
							),
						),
					),
				),
			),
		),
	);
}

/**
 * Report options for the editor's Report field.
 *
 * Moved from the widget's report_options() method verbatim.
 *
 * @return array<string, string> Report id => name, plus the "select" placeholder.
 */
function agend_apps_records_export_reports_report_options(): array {
	$reports = array();

	if ( function_exists( 'agend_apps_directory_get_export_reports' ) ) {
		$response = agend_apps_directory_get_export_reports();

		if ( ! is_wp_error( $response ) && ! empty( $response['data'] ) && is_array( $response['data'] ) ) {
			foreach ( $response['data'] as $report ) {
				if ( ! empty( $report['id'] ) && ! empty( $report['name'] ) ) {
					$reports[ (string) $report['id'] ] = (string) $report['name'];
				}
			}
		}
	}

	return array( '' => __( 'Select a report', 'agend-apps-core' ) ) + $reports;
}

/**
 * The Directory Catalogue filters a mapping row can read a value from.
 *
 * Moved from the widget's catalogue_source_options() method verbatim.
 *
 * @return array<string, string>
 */
function agend_apps_records_export_reports_catalogue_source_options(): array {
	$options = array( '' => __( 'Select a filter', 'agend-apps-core' ) );

	if ( function_exists( 'agend_apps_records_filter_registry' ) ) {
		$registry = agend_apps_records_filter_registry();
		foreach ( $registry['listing'] ?? array() as $key => $descriptor ) {
			if ( in_array( $key, array( 'reset', 'sort' ), true ) ) {
				continue;
			}
			$options[ $key ] = (string) $descriptor['label'];
		}
	}

	return $options;
}
