<?php
/**
 * Server render of the export report surface: a button that downloads one
 * nominated report, or a menu whose trigger opens a list of reports where
 * choosing one downloads it.
 *
 * Page-builder agnostic: the same markup and client config whichever editor
 * placed the surface. An adapter passes the surface's settings (see
 * `agend_apps_records_surface_schema()`) and echoes the returned HTML.
 *
 * `reports` and `parameters` are repeater-shaped settings. Elementor stores a
 * repeater as a list of associative arrays keyed by each row control's name;
 * a block is expected to store the same shape, so both are read the same way
 * here without a builder-specific branch.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The parameter mapping rows, normalised for the script.
 *
 * Moved from the Elementor widget's parameter_map() method unchanged, other
 * than tolerating a row that is not itself an array (defensive against a
 * builder-supplied shape this surface has not seen yet).
 *
 * @param array $settings Surface settings.
 * @return array<int, array<string, string>>
 */
function agend_apps_records_export_reports_parameter_map( array $settings ): array {
	$rows = array();
	foreach ( (array) ( $settings['parameters'] ?? array() ) as $row ) {
		$row   = is_array( $row ) ? $row : array();
		$field = trim( (string) ( $row['param_field'] ?? '' ) );
		if ( '' === $field ) {
			continue;
		}
		$rows[] = array(
			'field'     => $field,
			'source'    => 'catalogue' === ( $row['param_source'] ?? 'manual' ) ? 'catalogue' : 'manual',
			'value'     => (string) ( $row['param_value'] ?? '' ),
			'filter'    => (string) ( $row['param_catalogue_filter'] ?? '' ),
			'customKey' => trim( (string) ( $row['param_custom_key'] ?? '' ) ),
		);
	}
	return $rows;
}

/**
 * The reports a surface instance offers.
 *
 * Moved from the Elementor widget's offered_reports() method unchanged, other
 * than tolerating a non-array `reports` row the same way
 * agend_apps_records_export_reports_parameter_map() does.
 *
 * @param array $settings Surface settings.
 * @return array<int, array<string, string>>
 */
function agend_apps_records_export_reports_offered_reports( array $settings ): array {
	if ( 'dropdown' !== ( $settings['mode'] ?? 'button' ) ) {
		$id = (string) ( $settings['report'] ?? '' );
		return '' === $id ? array() : array( array( 'id' => $id, 'label' => '' ) );
	}

	$reports = array();
	foreach ( (array) ( $settings['reports'] ?? array() ) as $row ) {
		$row = is_array( $row ) ? $row : array();
		$id  = (string) ( $row['report_id'] ?? '' );
		if ( '' === $id ) {
			continue;
		}
		$reports[] = array( 'id' => $id, 'label' => trim( (string) ( $row['report_label'] ?? '' ) ) );
	}
	return $reports;
}

/**
 * Renders the export report surface.
 *
 * Returns '' when the instance offers no report: a button with nothing
 * chosen, or a menu with an empty list. The Elementor widget shows an
 * editor-only notice for that case itself (an editor concern, not this
 * renderer's); it decides whether to show it from the same
 * agend_apps_records_export_reports_offered_reports() call this function
 * makes, so the two never disagree about whether there is anything to offer.
 *
 * @param array $settings Surface settings (see agend_apps_records_surface_schema( 'export-reports' )).
 * @param array $opts     'id' (string, default ''): a caller-supplied element
 *                        id the trigger and menu share (aria-controls/id), so
 *                        several instances on one page never collide. An
 *                        Elementor widget passes its own get_id().
 * @return string The rendered markup, or '' when the instance offers no report.
 */
function agend_apps_records_render_export_reports( array $settings, array $opts = array() ): string {
	$mode    = 'dropdown' === ( $settings['mode'] ?? 'button' ) ? 'dropdown' : 'button';
	$reports = agend_apps_records_export_reports_offered_reports( $settings );

	if ( empty( $reports ) ) {
		return '';
	}

	// TODO: this surface has no URL-typed setting to normalise today --
	// `restBase` below is server-computed, not a setting, and `reports` /
	// `parameters` are repeaters, already the same list-of-associative-arrays
	// shape whichever builder stores them. If a later report parameter
	// source needs one (e.g. reading a URL from elsewhere on the page),
	// switch it to the shared URL-shape normaliser a concurrent change is
	// adding, rather than adding a second one here.
	$config = array(
		'restBase'   => esc_url_raw( rest_url( 'agend-apps/v1' ) ),
		'mode'       => $mode,
		'reports'    => $reports,
		'format'     => 'xlsx' === ( $settings['format'] ?? 'csv' ) ? 'xlsx' : 'csv',
		'parameters' => agend_apps_records_export_reports_parameter_map( $settings ),
		'labels'     => array(
			'working' => __( 'Preparing…', 'agend-apps-core' ),
			'failed'  => __( 'That report could not be produced. Try again shortly.', 'agend-apps-core' ),
		),
	);

	$classes = 'agend-export-report agend-export-report--' . $mode;
	if ( 'yes' === ( $settings['full_width'] ?? '' ) ) {
		$classes .= ' agend-export-report--full';
	}

	$trigger_text = (string) ( $settings['button_text'] ?? __( 'Export', 'agend-apps-core' ) );

	ob_start();
	echo '<div class="' . esc_attr( $classes ) . '" data-agend-export-config="' . esc_attr( (string) wp_json_encode( $config ) ) . '">';

	if ( 'button' === $mode ) {
		echo '<button type="button" class="agend-export__submit" data-agend-export-submit data-report-id="' . esc_attr( $reports[0]['id'] ) . '">' . esc_html( $trigger_text ) . '</button>';
		echo '</div>';
		return (string) ob_get_clean();
	}

	$menu_id = 'agend-export-menu-' . esc_attr( (string) ( $opts['id'] ?? '' ) );

	echo '<button type="button" class="agend-export__submit agend-export__trigger" data-agend-export-trigger aria-haspopup="true" aria-expanded="false" aria-controls="' . $menu_id . '">';
	echo esc_html( $trigger_text );
	echo '<span class="agend-export__caret" aria-hidden="true"></span>';
	echo '</button>';

	// Choosing a report IS the action, so each entry is a button rather than
	// a value to be confirmed with a second click.
	echo '<ul class="agend-export__menu" id="' . $menu_id . '" data-agend-export-menu hidden>';
	foreach ( $reports as $report ) {
		echo '<li class="agend-export__menu-item">';
		echo '<button type="button" class="agend-export__item" data-agend-export-submit data-report-id="' . esc_attr( $report['id'] ) . '">';
		// A blank label is filled in with the report's current name at view
		// time, so a rename in Agend does not go stale in a saved template.
		echo esc_html( '' !== $report['label'] ? $report['label'] : $report['id'] );
		echo '</button></li>';
	}
	echo '</ul>';
	echo '</div>';

	return (string) ob_get_clean();
}
