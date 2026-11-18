<?php
/**
 * Server render of the Agend Directory Count surface: how many listings the
 * connected directory holds.
 *
 * The total is read on the server from the gateway's own pagination total
 * (through the cached directory search), so the number is in the page whether
 * or not a Directory Catalogue is on it. With "follow the catalogue" on,
 * assets/js/directory-count.js replaces it with the catalogue's filtered
 * total as visitors filter.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Every renderer asks the surface gate before it fetches or draws anything;
// callers that load one renderer on its own (the unit tests) need it present.
require_once __DIR__ . '/../surface-gate.php';
require_once __DIR__ . '/../query.php';

/**
 * The number of listings in the directory, or null when it cannot be read.
 *
 * Asks for a single listing and reads the total from the pagination meta. The
 * request goes through agend_apps_directory_search(), so it is cached for the
 * directory search lifetime like every other search, and a visitor who is
 * signed in is counted with what their entitlements show.
 *
 * @return int|null
 */
function agend_apps_records_directory_count_total(): ?int {
	if ( ! function_exists( 'agend_apps_directory_search' ) ) {
		return null;
	}

	$list = agend_apps_records_unwrap_list(
		agend_apps_directory_search(
			'',
			array(
				'page'  => 1,
				'limit' => 1,
			)
		)
	);
	if ( $list['error'] || ! is_array( $list['pagination'] ) || ! is_numeric( $list['pagination']['total'] ?? null ) ) {
		return null;
	}

	return max( 0, (int) $list['pagination']['total'] );
}

/**
 * The label that goes with a total: the singular for exactly one, else the
 * plural.
 *
 * @param int   $total Listings counted.
 * @param array $s     Surface settings.
 * @return string
 */
function agend_apps_records_directory_count_label( int $total, array $s ): string {
	$plural   = trim( (string) ( $s['label'] ?? __( 'listings', 'agend-apps-core' ) ) );
	$singular = trim( (string) ( $s['label_singular'] ?? __( 'listing', 'agend-apps-core' ) ) );

	return 1 === $total && '' !== $singular ? $singular : $plural;
}

/**
 * The client-side config from the surface settings.
 *
 * @param array $s Surface settings (Elementor-shaped values: toggles are 'yes'/'').
 * @return array
 */
function agend_apps_records_directory_count_build_config( array $s ): array {
	$plural = trim( (string) ( $s['label'] ?? __( 'listings', 'agend-apps-core' ) ) );

	return array(
		'follow' => 'yes' === ( $s['follow_catalogue'] ?? '' ),
		'one'    => agend_apps_records_directory_count_label( 1, $s ),
		'other'  => $plural,
	);
}

/**
 * Renders the directory count surface.
 *
 * @param array $settings Surface settings (see agend_apps_records_surface_schema( 'directory-count' )).
 * @param array $opts     'preview' (bool, default false): an editor preview,
 *                        which shows a sample number when the gateway cannot
 *                        be read, so a designer can still style the widget.
 * @return string The rendered markup, or '' when there is no number to show.
 */
function agend_apps_records_render_directory_count( array $settings, array $opts = array() ): string {
	$gate = agend_apps_records_surface_gate( 'directory-count', false );
	if ( null !== $gate ) {
		return $gate;
	}

	$total = agend_apps_records_directory_count_total();
	if ( null === $total ) {
		if ( empty( $opts['preview'] ) ) {
			return '';
		}
		$total = 1234;
	}

	$layout = 'inline' === ( $settings['layout'] ?? 'stacked' ) ? 'inline' : 'stacked';
	$config = agend_apps_records_directory_count_build_config( $settings );

	return '<div class="agend-dc agend-dc--' . esc_attr( $layout ) . '" data-agend-dc-config="' . esc_attr( (string) wp_json_encode( $config ) ) . '">'
		. '<span class="agend-dc__number">' . esc_html( number_format_i18n( $total ) ) . '</span> '
		. '<span class="agend-dc__label">' . esc_html( agend_apps_records_directory_count_label( $total, $settings ) ) . '</span>'
		. '</div>';
}
