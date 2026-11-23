<?php
/**
 * Server render of the Agend Filter Shortcuts surface: a grid of tiles that
 * each apply one value of one directory filter.
 *
 * Page-builder agnostic: an adapter passes the surface's settings (see
 * `agend_apps_records_surface_schema( 'filter-shortcuts' )`) and echoes the
 * returned HTML. Every tile is a real link to the directory page carrying the
 * search link parameter for its value, so it works without script and can be
 * shared; assets/js/filter-shortcuts.js applies it in place instead when the
 * page has a Directory Catalogue.
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
require_once __DIR__ . '/../filters.php';
require_once __DIR__ . '/directory-search.php';

/**
 * The search link parameter each shortcut filter writes. Mirrors the encoder
 * in assets/js/search-link.js.
 *
 * @param string $filter    Registry key: 'custom_field', 'category', 'state' or 'city'.
 * @param string $field_key The custom field key, for 'custom_field'.
 * @return string '' for a filter that has no parameter.
 */
function agend_apps_records_filter_shortcuts_param( string $filter, string $field_key ): string {
	switch ( $filter ) {
		case 'category':
			return 'category_ids';
		case 'state':
			return 'state';
		case 'city':
			return 'city';
		case 'custom_field':
			return '' === $field_key ? '' : 'cf_' . $field_key;
	}

	return '';
}

/**
 * A tile's link: the directory page with the search link parameter for one
 * value, written the way the catalogue writes it, so the catalogue reads it
 * back.
 *
 * @param string $target Directory page address, or '' for this page.
 * @param string $param  Search link parameter name.
 * @param string $value  The value the tile applies.
 * @return string An escaped-for-output-ready URL (not yet HTML-escaped).
 */
function agend_apps_records_filter_shortcuts_href( string $target, string $param, string $value ): string {
	$fragment = '';
	$hash_at  = strpos( $target, '#' );
	if ( false !== $hash_at ) {
		$fragment = substr( $target, $hash_at );
		$target   = substr( $target, 0, $hash_at );
	}

	// A comma separates values in a list parameter, so one inside a value is
	// escaped before the whole is encoded.
	$escaped = agend_apps_records_directory_search_link_value( $value );
	$pair    = rawurlencode( $param ) . '=' . rawurlencode( $escaped );

	return $target . ( false === strpos( $target, '?' ) ? '?' : '&' ) . $pair . $fragment;
}

/**
 * The icon markup a tile may carry: an icon font element or an inline SVG,
 * and nothing else.
 *
 * @return array<string, array<string, bool>>
 */
function agend_apps_records_filter_shortcuts_icon_kses(): array {
	$svg_attrs = array(
		'class'           => true,
		'aria-hidden'     => true,
		'focusable'       => true,
		'role'            => true,
		'xmlns'           => true,
		'viewbox'         => true,
		'width'           => true,
		'height'          => true,
		'fill'            => true,
		'stroke'          => true,
		'stroke-width'    => true,
		'stroke-linecap'  => true,
		'stroke-linejoin' => true,
		'd'               => true,
		'cx'              => true,
		'cy'              => true,
		'r'               => true,
		'x'               => true,
		'y'               => true,
		'rx'              => true,
		'ry'              => true,
		'x1'              => true,
		'x2'              => true,
		'y1'              => true,
		'y2'              => true,
		'points'          => true,
		'transform'       => true,
		'fill-rule'       => true,
		'clip-rule'       => true,
	);

	return array(
		'i'        => array(
			'class'       => true,
			'aria-hidden' => true,
		),
		'span'     => array(
			'class'       => true,
			'aria-hidden' => true,
		),
		'svg'      => $svg_attrs,
		'g'        => $svg_attrs,
		'path'     => $svg_attrs,
		'circle'   => $svg_attrs,
		'ellipse'  => $svg_attrs,
		'rect'     => $svg_attrs,
		'line'     => $svg_attrs,
		'polyline' => $svg_attrs,
		'polygon'  => $svg_attrs,
	);
}

/**
 * An adapter's icon markup, reduced to what a tile may carry.
 *
 * @param mixed $html Icon markup as an adapter rendered it.
 * @return string
 */
function agend_apps_records_filter_shortcuts_icon( $html ): string {
	return is_string( $html ) && '' !== trim( $html )
		? trim( wp_kses( $html, agend_apps_records_filter_shortcuts_icon_kses() ) )
		: '';
}

/**
 * The directory filter a surface's settings name, as the registry describes
 * it, with the custom field key.
 *
 * @param array $s Surface settings.
 * @return array{filter: string, state: string, mode: string, fieldKey: string, param: string}|null Null when the
 *         setting names no usable filter (an unknown one, or a custom field with no key).
 */
function agend_apps_records_filter_shortcuts_filter( array $s ): ?array {
	$filter = (string) ( $s['filter'] ?? 'custom_field' );
	if ( ! array_key_exists( $filter, agend_apps_records_filter_shortcuts_filter_options() ) ) {
		return null;
	}

	$descriptor = agend_apps_records_filter_descriptor( 'listing', $filter );
	if ( null === $descriptor ) {
		return null;
	}

	$field_key = 'custom_field' === $filter ? trim( (string) ( $s['custom_field_key'] ?? '' ) ) : '';
	$param     = agend_apps_records_filter_shortcuts_param( $filter, $field_key );
	if ( '' === $param ) {
		return null;
	}

	return array(
		'filter'   => $filter,
		'state'    => (string) $descriptor['state'],
		'mode'     => (string) $descriptor['mode'],
		'fieldKey' => $field_key,
		'param'    => $param,
	);
}

/**
 * The tiles an author wrote by hand.
 *
 * @param array $s Surface settings.
 * @return array<int, array{label: string, value: string, icon: string}>
 */
function agend_apps_records_filter_shortcuts_manual_tiles( array $s ): array {
	$tiles = array();
	foreach ( (array) ( $s['items'] ?? array() ) as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$label = trim( (string) ( $row['label'] ?? '' ) );
		$value = trim( (string) ( $row['value'] ?? '' ) );
		if ( '' === $value ) {
			$value = $label;
		}
		if ( '' === $value ) {
			continue;
		}
		$tiles[] = array(
			'label' => '' !== $label ? $label : $value,
			'value' => $value,
			'icon'  => agend_apps_records_filter_shortcuts_icon( $row['icon_html'] ?? '' ),
		);
	}

	return $tiles;
}

/**
 * The facet a filter's values come from, or '' for a filter that reads a list
 * endpoint instead.
 *
 * @param array $filter agend_apps_records_filter_shortcuts_filter() result.
 * @return string
 */
function agend_apps_records_filter_shortcuts_facet( array $filter ): string {
	switch ( $filter['filter'] ) {
		case 'custom_field':
			return 'custom.' . $filter['fieldKey'];
		case 'state':
			return 'location.state';
		case 'city':
			return 'location.city';
	}

	return '';
}

/**
 * Whether the values for these settings are the same for every visitor, so a
 * page can carry them. A custom field's values depend on what the visitor is
 * entitled to read, so for a signed-in visitor the browser fetches them.
 *
 * @param array $filter agend_apps_records_filter_shortcuts_filter() result.
 * @return bool
 */
function agend_apps_records_filter_shortcuts_values_on_server( array $filter ): bool {
	$facet = agend_apps_records_filter_shortcuts_facet( $filter );
	if ( '' === $facet || ! is_user_logged_in() ) {
		return true;
	}

	return function_exists( 'agend_apps_directory_facets_viewer_independent' )
		&& agend_apps_directory_facets_viewer_independent( array( $facet ) );
}

/**
 * The tiles a filter's values make, "all values from the directory".
 *
 * @param array $filter agend_apps_records_filter_shortcuts_filter() result.
 * @param array $s      Surface settings.
 * @param string $icon  The icon every tile shares.
 * @return array<int, array{label: string, value: string, icon: string}>
 */
function agend_apps_records_filter_shortcuts_directory_tiles( array $filter, array $s, string $icon ): array {
	$tiles = array();

	if ( 'category' === $filter['filter'] ) {
		if ( ! function_exists( 'agend_apps_directory_get_categories' ) ) {
			return array();
		}
		$list     = agend_apps_records_unwrap_list( agend_apps_directory_get_categories() );
		$top_only = 'yes' === ( $s['top_level_only'] ?? 'yes' );
		foreach ( $list['items'] as $category ) {
			$id   = (string) ( $category['id'] ?? '' );
			$name = trim( (string) ( $category['name'] ?? '' ) );
			if ( '' === $id || '' === $name || ( $top_only && ! empty( $category['parent_id'] ) ) ) {
				continue;
			}
			$tiles[] = array(
				'label' => $name,
				'value' => $id,
				'icon'  => $icon,
			);
		}
		return $tiles;
	}

	$facet = agend_apps_records_filter_shortcuts_facet( $filter );
	if ( '' === $facet || ! function_exists( 'agend_apps_directory_get_facets' ) ) {
		return array();
	}
	$response = agend_apps_directory_get_facets( array( $facet ) );
	$values   = is_array( $response ) && is_array( $response['data'][ $facet ]['values'] ?? null ) ? $response['data'][ $facet ]['values'] : array();
	foreach ( $values as $entry ) {
		if ( ! is_array( $entry ) ) {
			continue;
		}
		// A taxonomy facet carries the id the gateway filters on; a custom
		// field or location facet has none, so its own value is what is sent.
		$value = (string) ( ! empty( $entry['id'] ) ? $entry['id'] : ( $entry['value'] ?? '' ) );
		if ( '' === $value ) {
			continue;
		}
		$label   = trim( (string) ( $entry['label'] ?? '' ) );
		$tiles[] = array(
			'label' => '' !== $label ? $label : (string) ( $entry['value'] ?? $value ),
			'value' => $value,
			'icon'  => $icon,
		);
	}

	return $tiles;
}

/**
 * One column count setting, held to the schema's range.
 *
 * @param mixed $value    The setting.
 * @param int   $fallback Used when it is not a whole number in range.
 * @param int   $max      The most columns.
 * @return int
 */
function agend_apps_records_filter_shortcuts_columns( $value, int $fallback, int $max ): int {
	$columns = is_numeric( $value ) ? (int) $value : $fallback;

	return max( 1, min( $max, $columns ) );
}

/**
 * Renders one tile.
 *
 * @param array  $tile   label, value and icon.
 * @param array  $filter The filter.
 * @param string $target Directory page address.
 * @param bool   $icons  Whether tiles show icons.
 * @return string Escaped markup.
 */
function agend_apps_records_filter_shortcuts_tile( array $tile, array $filter, string $target, bool $icons ): string {
	$icon = $icons && '' !== $tile['icon'] ? '<span class="agend-fs__icon" aria-hidden="true">' . $tile['icon'] . '</span>' : '';

	return '<li class="agend-fs__item"><a class="agend-fs__tile" href="' . esc_url( agend_apps_records_filter_shortcuts_href( $target, $filter['param'], $tile['value'] ) ) . '" data-agend-fs-value="' . esc_attr( $tile['value'] ) . '">'
		. $icon
		. '<span class="agend-fs__label">' . esc_html( $tile['label'] ) . '</span></a></li>';
}

/**
 * Renders the filter shortcuts surface.
 *
 * @param array $settings Surface settings (see agend_apps_records_surface_schema( 'filter-shortcuts' )).
 * @param array $opts     'preview' (bool, default false): an editor preview,
 *                        which says so when there is nothing to show instead
 *                        of drawing nothing.
 * @return string The rendered markup, or '' when there are no tiles.
 */
function agend_apps_records_render_filter_shortcuts( array $settings, array $opts = array() ): string {
	$gate = agend_apps_records_surface_gate( 'filter-shortcuts', false );
	if ( null !== $gate ) {
		return $gate;
	}

	// A grid inside a card template would draw once per card.
	if ( class_exists( 'Agend_Apps_Records_Record_Context' ) && Agend_Apps_Records_Record_Context::has() ) {
		return '';
	}

	$filter = agend_apps_records_filter_shortcuts_filter( $settings );
	$empty  = static function () use ( $opts ): string {
		return empty( $opts['preview'] )
			? ''
			: '<p class="agend-fs agend-fs--empty">' . esc_html__( 'Choose a filter and add the tiles it should offer. Nothing is shown to visitors until there is at least one.', 'agend-apps-core' ) . '</p>';
	};
	if ( null === $filter ) {
		return $empty();
	}

	$icons    = 'yes' === ( $settings['show_icons'] ?? 'yes' );
	$target   = agend_apps_records_directory_search_target( $settings );
	$shared   = agend_apps_records_filter_shortcuts_icon( $settings['all_icon_html'] ?? '' );
	$from_all = 'all' === ( $settings['source'] ?? 'manual' );
	$pending  = false;

	if ( ! $from_all ) {
		$tiles = agend_apps_records_filter_shortcuts_manual_tiles( $settings );
	} elseif ( agend_apps_records_filter_shortcuts_values_on_server( $filter ) ) {
		$tiles = agend_apps_records_filter_shortcuts_directory_tiles( $filter, $settings, $shared );
	} else {
		$tiles   = array();
		$pending = true;
	}

	if ( empty( $tiles ) && ! $pending ) {
		return $empty();
	}

	$config = array(
		'state'    => $filter['state'],
		'mode'     => $filter['mode'],
		'fieldKey' => $filter['fieldKey'],
		'target'   => $target,
		'scroll'   => 'yes' === ( $settings['scroll_to_results'] ?? 'yes' ),
		'pending'  => $pending ? array(
			'facet' => agend_apps_records_filter_shortcuts_facet( $filter ),
			'icon'  => $icons ? $shared : '',
		) : null,
	);

	$items = '';
	foreach ( $tiles as $tile ) {
		$items .= agend_apps_records_filter_shortcuts_tile( $tile, $filter, $target, $icons );
	}

	$style = sprintf(
		'--agend-fs-cols:%1$d;--agend-fs-cols-tablet:%2$d;--agend-fs-cols-mobile:%3$d',
		agend_apps_records_filter_shortcuts_columns( $settings['columns'] ?? 4, 4, 8 ),
		agend_apps_records_filter_shortcuts_columns( $settings['columns_tablet'] ?? 2, 2, 8 ),
		agend_apps_records_filter_shortcuts_columns( $settings['columns_mobile'] ?? 2, 2, 4 )
	);

	return '<div class="agend-fs' . ( $icons ? ' agend-fs--icons' : '' ) . '" style="' . esc_attr( $style ) . '" data-agend-fs-config="' . esc_attr( (string) wp_json_encode( $config ) ) . '">'
		. '<ul class="agend-fs__grid" role="list">' . $items . '</ul></div>';
}
