<?php
/**
 * Content-settings schema for the Agend Export Report surface.
 *
 * Transcribed from the Agend Export Report widget's register_controls()'s two
 * Content-tab sections ("Report" and "Parameters"). `reports` and
 * `parameters` are REPEATER controls the shared vocabulary cannot describe.
 * `reports_unavailable` is conditionally registered only when the account has
 * no export reports, which the vocabulary's `condition` key (a field VALUE
 * condition) cannot express either, so it is also an `adapter` field whose
 * `register_adapter_control()` reproduces the same runtime check.
 * `parameters_note` carries a `content_classes` key the `note` type's
 * `control_args()` branch does not pass through, so it is an `adapter` field
 * too rather than a `note` one. See
 * the widget's register_adapter_control().
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
						'name'  => 'reports',
						'label' => __( 'Reports in the menu', 'agend-apps-core' ),
						'type'  => 'adapter',
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
						'name'  => 'parameters',
						'label' => __( 'Parameter mapping', 'agend-apps-core' ),
						'type'  => 'adapter',
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
