<?php
/**
 * Server render of the Agend Directory Search surface.
 *
 * Page-builder agnostic: an adapter passes the surface's settings (see
 * `agend_apps_records_surface_schema( 'directory-search' )`) and echoes the
 * returned HTML. The form works without script as a plain GET to the
 * directory page carrying `q` and each dropdown's search link parameter;
 * assets/js/directory-search.js takes it over,
 * searching the page's Directory Catalogue in place or building a full search
 * link for the directory page.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Every renderer asks the surface gate before it fetches or draws anything;
// callers that load one renderer on its own (the unit tests) need it present.
require_once __DIR__ . '/../surface-gate.php';
require_once __DIR__ . '/../format.php';
// The dropdown fields name a directory filter and list its values the way a
// Filter Shortcuts grid does. That file requires this one back; require_once
// makes the pair safe whichever loads first.
require_once __DIR__ . '/filter-shortcuts.php';

/**
 * Where a search from this box goes when the page has no catalogue: the
 * widget's own page setting, else the dedicated Directory page, else ''
 * (this page).
 *
 * @param array $s Surface settings.
 * @return string An absolute URL, or ''.
 */
function agend_apps_records_directory_search_target( array $s ): string {
	$own = trim( agend_apps_records_normalise_url_setting( $s['target_url'] ?? '' ) );
	if ( '' !== $own ) {
		return esc_url_raw( $own );
	}

	return class_exists( 'Agend_Apps_Records_Pages' ) ? Agend_Apps_Records_Pages::page_url( 'listing' ) : '';
}

/**
 * The radius a search from this box uses, in kilometres: the setting, held to
 * the gateway's 1 to 1000 km range.
 *
 * @param array $s Surface settings.
 * @return float
 */
function agend_apps_records_directory_search_radius( array $s ): float {
	$radius = is_numeric( $s['radius'] ?? null ) ? (float) $s['radius'] : 15.0;

	return max( 1.0, min( 1000.0, $radius ) );
}

/**
 * The dropdown fields the settings describe, each with the values it offers.
 *
 * A row that names no usable filter is skipped, and so is one with no values
 * to offer, except in an editor preview, where an empty dropdown shows the
 * designer where it will sit. A signed-in visitor's custom field values depend
 * on what they may read, so those are left to the browser (`facet`), as a
 * Filter Shortcuts grid leaves them.
 *
 * @param array $s       Surface settings.
 * @param bool  $preview Whether this is an editor preview.
 * @return array<int, array{filter: array, label: string, any: string, values: array<int, array{label: string, value: string}>, facet: string}>
 */
function agend_apps_records_directory_search_dropdowns( array $s, bool $preview = false ): array {
	$dropdowns = array();
	foreach ( (array) ( $s['dropdowns'] ?? array() ) as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$as_shortcut = array(
			'filter'           => (string) ( $row['dropdown_filter'] ?? 'custom_field' ),
			'custom_field_key' => (string) ( $row['dropdown_custom_field_key'] ?? '' ),
			'top_level_only'   => 'yes',
		);
		$filter      = agend_apps_records_filter_shortcuts_filter( $as_shortcut );
		if ( null === $filter ) {
			continue;
		}

		$values = array();
		$facet  = '';
		if ( agend_apps_records_filter_shortcuts_values_on_server( $filter ) ) {
			foreach ( agend_apps_records_filter_shortcuts_directory_tiles( $filter, $as_shortcut, '' ) as $tile ) {
				$values[] = array(
					'label' => $tile['label'],
					'value' => $tile['value'],
				);
			}
		} else {
			$facet = agend_apps_records_filter_shortcuts_facet( $filter );
		}
		if ( empty( $values ) && '' === $facet && ! $preview ) {
			continue;
		}

		$label = trim( (string) ( $row['dropdown_label'] ?? '' ) );
		$any   = trim( (string) ( $row['dropdown_any_label'] ?? '' ) );
		if ( '' === $label ) {
			$names = agend_apps_records_filter_shortcuts_filter_options();
			$label = '' !== $any ? $any : (string) $names[ $filter['filter'] ];
		}

		$dropdowns[] = array(
			'filter' => $filter,
			'label'  => $label,
			'any'    => '' !== $any ? $any : __( 'Any', 'agend-apps-core' ),
			'values' => $values,
			'facet'  => $facet,
		);
	}

	return $dropdowns;
}

/**
 * A value as a search link parameter carries it: a comma written as %2C and a
 * percent sign as %25, so a comma inside one value is not read as two. Used
 * for an option's value, which a page without script submits as it stands.
 *
 * @param string $value The value.
 * @return string
 */
function agend_apps_records_directory_search_link_value( string $value ): string {
	return str_replace( array( '%', ',' ), array( '%25', '%2C' ), $value );
}

/**
 * The client-side config from the surface settings.
 *
 * @param array $s         Surface settings (Elementor-shaped values: toggles are 'yes'/'').
 * @param array $dropdowns agend_apps_records_directory_search_dropdowns(), in
 *                         the order their fields are drawn.
 * @return array Config passed to the frontend script as JSON.
 */
function agend_apps_records_directory_search_build_config( array $s, array $dropdowns = array() ): array {
	$choices = array();
	foreach ( $dropdowns as $dropdown ) {
		$choices[] = array(
			'state'    => $dropdown['filter']['state'],
			'mode'     => $dropdown['filter']['mode'],
			'fieldKey' => $dropdown['filter']['fieldKey'],
			'facet'    => '' !== $dropdown['facet'] ? $dropdown['facet'] : null,
		);
	}

	return array(
		'target'  => agend_apps_records_directory_search_target( $s ),
		'region'  => trim( (string) ( $s['location_region'] ?? '' ) ),
		'radius'  => agend_apps_records_directory_search_radius( $s ),
		'scroll'  => 'yes' === ( $s['scroll_to_results'] ?? 'yes' ),
		'choices' => $choices,
		'text'   => array(
			'myLocation'  => __( 'My location', 'agend-apps-core' ),
			'empty'       => __( 'Enter a suburb, postcode or address.', 'agend-apps-core' ),
			'notFound'    => __( 'We could not find that place. Try a suburb or postcode.', 'agend-apps-core' ),
			'busy'        => __( 'Place search is busy. Please try again in a moment.', 'agend-apps-core' ),
			'failed'      => __( 'Place search failed. Please try again in a moment.', 'agend-apps-core' ),
			'unsupported' => __( 'Your browser does not support finding your location.', 'agend-apps-core' ),
			'denied'      => __( 'Location permission was denied. Update your browser settings to use this feature.', 'agend-apps-core' ),
			'timeout'     => __( 'Could not get your location. Please try again.', 'agend-apps-core' ),
			'unavailable' => __( 'Your location is unavailable. Check that Location Services are turned on, then try again.', 'agend-apps-core' ),
		),
	);
}

/**
 * One of the box's icons: inline, so it takes the colour the Style tab gives
 * it, and hidden from assistive technology.
 *
 * @param string $name 'search', 'pin' or 'locate'.
 * @return string Trusted markup.
 */
function agend_apps_records_directory_search_icon( string $name ): string {
	$paths = array(
		'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
		'pin'    => '<path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>',
		'locate' => '<circle cx="12" cy="12" r="3.5"/><path d="M12 3v3M12 18v3M3 12h3M18 12h3"/>',
	);

	return '<svg class="agend-ds__svg" aria-hidden="true" focusable="false" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
		. ( $paths[ $name ] ?? '' ) . '</svg>';
}

/**
 * A text field of the box.
 *
 * @param string $kind        'keyword' or 'location'.
 * @param string $label       Screen reader label.
 * @param string $placeholder Placeholder.
 * @param string $icon        Icon name, or '' for none.
 * @return string Escaped markup.
 */
function agend_apps_records_directory_search_field( string $kind, string $label, string $placeholder, string $icon ): string {
	$location = 'location' === $kind;
	$html     = '<label class="agend-ds__field agend-ds__field--' . esc_attr( $kind ) . '"' . ( $location ? ' data-agend-ds-js' : '' ) . '>';
	if ( '' !== $icon ) {
		$html .= '<span class="agend-ds__icon" aria-hidden="true">' . agend_apps_records_directory_search_icon( $icon ) . '</span>';
	}
	$html .= '<span class="agend-ds__label">' . esc_html( $label ) . '</span>';
	// The place box has no name: without script there is no geocoder to turn it
	// into the coordinates a search link carries, so it must not be sent.
	$html .= '<input class="agend-ds__input" type="search" autocomplete="off"'
		. ( $location ? '' : ' name="q"' )
		. ' placeholder="' . esc_attr( $placeholder ) . '"></label>';

	return $html;
}

/**
 * A dropdown field of the box.
 *
 * Named for its search link parameter, so a page without script still sends
 * the choice; each option also carries its plain value for the script.
 *
 * @param array $dropdown One agend_apps_records_directory_search_dropdowns() entry.
 * @param int   $index    Its position among the dropdowns, which indexes the
 *                        config's `choices`.
 * @return string Escaped markup.
 */
function agend_apps_records_directory_search_dropdown_field( array $dropdown, int $index ): string {
	$options = '<option value="">' . esc_html( $dropdown['any'] ) . '</option>';
	foreach ( $dropdown['values'] as $entry ) {
		$options .= '<option value="' . esc_attr( agend_apps_records_directory_search_link_value( $entry['value'] ) ) . '" data-agend-ds-value="' . esc_attr( $entry['value'] ) . '">'
			. esc_html( $entry['label'] ) . '</option>';
	}

	return '<label class="agend-ds__field agend-ds__field--choice" data-agend-ds-choice="' . esc_attr( (string) $index ) . '">'
		. '<span class="agend-ds__label">' . esc_html( $dropdown['label'] ) . '</span>'
		. '<select class="agend-ds__input agend-ds__select is-empty" name="' . esc_attr( $dropdown['filter']['param'] ) . '">' . $options . '</select>'
		. '</label>';
}

/**
 * The hidden inputs that keep a target address's own query (page_id on plain
 * permalinks, a language) through a GET form, which replaces the action's
 * query string with its fields.
 *
 * @param string $target The target address.
 * @return string Escaped markup.
 */
function agend_apps_records_directory_search_target_inputs( string $target ): string {
	$query = (string) wp_parse_url( $target, PHP_URL_QUERY );
	if ( '' === $query ) {
		return '';
	}

	parse_str( $query, $args );
	$html = '';
	foreach ( $args as $name => $value ) {
		if ( is_scalar( $value ) ) {
			$html .= '<input type="hidden" name="' . esc_attr( (string) $name ) . '" value="' . esc_attr( (string) $value ) . '">';
		}
	}

	return $html;
}

/**
 * Renders the directory search surface.
 *
 * @param array $settings Surface settings (see agend_apps_records_surface_schema( 'directory-search' )).
 * @param array $opts     'preview' (bool, default false): an editor preview,
 *                        which shows the place field and the location link
 *                        that only the page script reveals, so a designer can
 *                        see and style them.
 * @return string The rendered markup.
 */
function agend_apps_records_render_directory_search( array $settings, array $opts = array() ): string {
	$gate = agend_apps_records_surface_gate( 'directory-search', false );
	if ( null !== $gate ) {
		return $gate;
	}

	// A search box inside a card template would draw once per card.
	if ( class_exists( 'Agend_Apps_Records_Record_Context' ) && Agend_Apps_Records_Record_Context::has() ) {
		return '';
	}

	$dropdowns = agend_apps_records_directory_search_dropdowns( $settings, ! empty( $opts['preview'] ) );
	$config    = agend_apps_records_directory_search_build_config( $settings, $dropdowns );
	$icons     = 'yes' === ( $settings['show_icons'] ?? 'yes' );
	$target = $config['target'];

	$text = static function ( string $key, string $fallback ) use ( $settings ): string {
		$value = trim( (string) ( $settings[ $key ] ?? '' ) );
		return '' !== $value ? $value : $fallback;
	};

	$fields = '';
	if ( 'yes' === ( $settings['show_keyword'] ?? 'yes' ) ) {
		$fields .= agend_apps_records_directory_search_field(
			'keyword',
			$text( 'keyword_label', __( 'Keyword', 'agend-apps-core' ) ),
			(string) ( $settings['keyword_placeholder'] ?? __( 'Search by name or keyword', 'agend-apps-core' ) ),
			$icons ? 'search' : ''
		);
	}
	foreach ( $dropdowns as $index => $dropdown ) {
		$fields .= agend_apps_records_directory_search_dropdown_field( $dropdown, $index );
	}
	if ( 'yes' === ( $settings['show_location'] ?? 'yes' ) ) {
		$fields .= agend_apps_records_directory_search_field(
			'location',
			$text( 'location_label', __( 'Location', 'agend-apps-core' ) ),
			(string) ( $settings['location_placeholder'] ?? __( 'Suburb or postcode', 'agend-apps-core' ) ),
			$icons ? 'pin' : ''
		);
	}

	$locate = '';
	if ( 'yes' === ( $settings['show_locate'] ?? 'yes' ) ) {
		$locate = '<button type="button" class="agend-ds__locate" data-agend-ds-js>'
			. ( $icons ? '<span class="agend-ds__icon" aria-hidden="true">' . agend_apps_records_directory_search_icon( 'locate' ) . '</span>' : '' )
			. '<span>' . esc_html( $text( 'locate_text', __( 'Use my location', 'agend-apps-core' ) ) ) . '</span></button>';
	}

	$classes = 'agend-ds' . ( $icons ? ' agend-ds--icons' : '' ) . ( empty( $opts['preview'] ) ? '' : ' agend-ds--js' );
	$action  = '' === $target ? '' : strtok( $target, '?' );

	return '<div class="' . esc_attr( $classes ) . '" data-agend-ds-config="' . esc_attr( (string) wp_json_encode( $config ) ) . '">'
		. '<form class="agend-ds__form" role="search" method="get" action="' . esc_url( (string) $action ) . '">'
		. agend_apps_records_directory_search_target_inputs( $target )
		. $fields
		. '<button type="submit" class="agend-ds__button">' . esc_html( $text( 'button_text', __( 'Search', 'agend-apps-core' ) ) ) . '</button>'
		. '</form>'
		. $locate
		. '<p class="agend-ds__status" role="status" aria-live="polite" hidden></p>'
		. '</div>';
}
