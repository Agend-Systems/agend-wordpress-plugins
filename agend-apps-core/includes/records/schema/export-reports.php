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
 * Scope the API key must hold, alongside `directory.export_reports.browse`,
 * for the gateway to accept `scope=all` on the export report listing.
 */
const AGEND_APPS_RECORDS_EXPORT_REPORTS_AUTHORING_SCOPE = 'directory.listings.manage';

/**
 * Report options for the editor's Report field.
 *
 * The visitor-facing listing the download path uses is audience-filtered by
 * the gateway: a key-only caller sees `anonymous` reports, a member sees
 * what they may run. That is right for a visitor and wrong for a designer
 * choosing which report a page offers, who needs every published report.
 * So the editor asks in authoring mode, `GET /v1/directory/export-reports
 * ?scope=all`, which the gateway grants when the key also holds
 * directory.listings.manage and which returns every published report with
 * its `audience`. The audience is folded into the option label so the
 * designer can see who a report is for before offering it.
 *
 * Falls back to the default (audience-filtered) listing when the key is not
 * known to hold the manage scope, or when the gateway answers 403 (scope not
 * granted) or 422 (a gateway predating `scope`), so an older gateway keeps
 * the behaviour it had. The download path never goes through here.
 *
 * @return array<string, string> Report id => label, plus the "select" placeholder.
 */
function agend_apps_records_export_reports_report_options(): array {
	$reports = array();

	if ( function_exists( 'agend_apps_directory_get_export_reports' ) ) {
		$response = agend_apps_records_export_reports_authoring_listing();

		if ( ! is_wp_error( $response ) && ! empty( $response['data'] ) && is_array( $response['data'] ) ) {
			foreach ( $response['data'] as $report ) {
				if ( ! empty( $report['id'] ) && ! empty( $report['name'] ) ) {
					$reports[ (string) $report['id'] ] = agend_apps_records_export_reports_option_label(
						(string) $report['name'],
						isset( $report['audience'] ) ? (string) $report['audience'] : ''
					);
				}
			}
		}
	}

	return array( '' => __( 'Select a report', 'agend-apps-core' ) ) + $reports;
}

/**
 * The export report listing for the editor: authoring mode when the key can
 * use it, the default listing otherwise. See
 * agend_apps_records_export_reports_report_options() for the reasoning.
 *
 * Authoring mode is requested through the existing
 * `agend_apps_directory_get_export_reports_args` filter for the duration of
 * one call, so the API function stays the single place that knows the path,
 * and its cache key follows the query (a separate transient from the
 * visitor listing).
 *
 * @return array|WP_Error Decoded response body, or the gateway error.
 */
function agend_apps_records_export_reports_authoring_listing() {
	if ( ! agend_apps_records_export_reports_authoring_scope_held() ) {
		return agend_apps_directory_get_export_reports();
	}

	$inject = static function ( $args ): array {
		$args          = is_array( $args ) ? $args : array();
		$args['query'] = array_merge( isset( $args['query'] ) && is_array( $args['query'] ) ? $args['query'] : array(), array( 'scope' => 'all' ) );
		return $args;
	};

	add_filter( 'agend_apps_directory_get_export_reports_args', $inject, 20 );
	try {
		$response = agend_apps_directory_get_export_reports();
	} finally {
		remove_filter( 'agend_apps_directory_get_export_reports_args', $inject, 20 );
	}

	if ( is_wp_error( $response ) ) {
		$status = (int) ( $response->get_error_data()['status_code'] ?? 0 );

		// 403: the gateway disagrees with our cached scope list about the
		// manage scope. 422: the gateway does not know `scope` yet. Either way
		// the default listing is the answer an older setup always gave.
		if ( 403 === $status || 422 === $status ) {
			return agend_apps_directory_get_export_reports();
		}
	}

	return $response;
}

/**
 * Whether the connected key is confirmed to hold the authoring scope. An
 * unknown scope list counts as not held, so a fresh install never sends a
 * request the gateway will refuse.
 */
function agend_apps_records_export_reports_authoring_scope_held(): bool {
	return class_exists( 'Agend_Apps_Key_Scopes' )
		&& Agend_Apps_Key_Scopes::known()
		&& Agend_Apps_Key_Scopes::has( AGEND_APPS_RECORDS_EXPORT_REPORTS_AUTHORING_SCOPE );
}

/**
 * A report's option label: its name, with the audience appended for a
 * report not open to everyone so the designer can tell them apart. A
 * listing without an `audience` field (the default listing, or an older
 * gateway) leaves the name bare.
 *
 * @param string $name     Report name.
 * @param string $audience `anonymous`, `members_only`, `restricted`, or ''.
 */
function agend_apps_records_export_reports_option_label( string $name, string $audience ): string {
	switch ( $audience ) {
		case 'members_only':
			/* translators: %s: report name. */
			return sprintf( __( '%s (members only)', 'agend-apps-core' ), $name );
		case 'restricted':
			/* translators: %s: report name. */
			return sprintf( __( '%s (restricted)', 'agend-apps-core' ), $name );
	}

	return $name;
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
