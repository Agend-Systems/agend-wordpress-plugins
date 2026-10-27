<?php
/**
 * Server render of the Agend Map (directory map) surface.
 *
 * Page-builder agnostic, like every surface renderer: an adapter passes the
 * surface's settings (see `agend_apps_records_surface_schema( 'directory-map' )`)
 * and echoes the returned HTML. The map itself is drawn in the browser by
 * assets/js/directory-map.js, which follows the Directory Catalogue on the
 * same page through `window.agendCatalogues.listing` and the
 * `agend:directory-results` / `agend:directory-view` events that catalogue
 * dispatches.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The map tiles an Agend Map draws on.
 *
 * With the site's CARTO basemaps key (Agend Apps settings > Maps), CARTO's
 * light basemap, the same tiles the Agend Directory app uses. CARTO stopped
 * serving tiles without a key in September 2026 (a keyless request gets an
 * "API key required" tile), so without one the map falls back to
 * OpenStreetMap's own tiles, whose usage policy suits light traffic.
 *
 * `maxNativeZoom` is the deepest zoom the provider serves tiles for; past it,
 * up to `maxZoom`, Leaflet enlarges that level's tiles instead of requesting
 * deeper ones.
 *
 * @return array{url: string, attribution: string, maxZoom: int, maxNativeZoom: int}
 */
function agend_apps_records_directory_map_tiles(): array {
	$osm = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';
	$key = defined( 'AGEND_APPS_RECORDS_MAP_TILES_KEY_OPTION' )
		? agend_apps_records_sanitize_map_tiles_key( get_option( AGEND_APPS_RECORDS_MAP_TILES_KEY_OPTION, '' ) )
		: '';

	$tiles = '' !== $key
		? array(
			// {r} asks for CARTO's double-resolution tiles on a high
			// density screen, which would otherwise draw the map blurred.
			'url'           => 'https://basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png?key=' . rawurlencode( $key ),
			'attribution'   => $osm . ' &copy; <a href="https://carto.com/attributions">CARTO</a>',
			'maxZoom'       => 19,
			// CARTO answers a keyed raster tile above zoom 18 with a 403,
			// although its basemaps page lists zoom 0 to 20.
			'maxNativeZoom' => 18,
		)
		: array(
			'url'           => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
			'attribution'   => $osm,
			'maxZoom'       => 19,
			'maxNativeZoom' => 19,
		);

	/**
	 * Filters the tile layer an Agend Map draws on.
	 *
	 * A site with another tile service swaps the URL here. The attribution is
	 * shown on the map as HTML, so it must stay trusted markup.
	 *
	 * @param array $tiles `url` (Leaflet template), `attribution`, `maxZoom`,
	 *                     `maxNativeZoom` (defaults to `maxZoom`).
	 */
	$tiles = (array) apply_filters( 'agend_apps_records_directory_map_tiles', $tiles );

	$max_zoom = max( 1, min( 22, (int) ( $tiles['maxZoom'] ?? 19 ) ) );

	return array(
		'url'           => (string) ( $tiles['url'] ?? '' ),
		'attribution'   => (string) ( $tiles['attribution'] ?? '' ),
		'maxZoom'       => $max_zoom,
		'maxNativeZoom' => max( 1, min( $max_zoom, (int) ( $tiles['maxNativeZoom'] ?? $max_zoom ) ) ),
	);
}

/**
 * The directions link a pin's details offer, as a template with `{lat}` and
 * `{lng}` placeholders. Google Maps by default, which opens the visitor's own
 * Google Maps app where they have one.
 *
 * @return string An https URL template, or '' for no directions link.
 */
function agend_apps_records_directory_map_directions_url(): string {
	/**
	 * Filters the directions link on a map pin's details.
	 *
	 * A site that prefers another maps service returns its URL here, with
	 * `{lat}` and `{lng}` where the listing's coordinates go. Anything but an
	 * https URL drops the link.
	 *
	 * @param string $url URL template.
	 */
	$url = (string) apply_filters( 'agend_apps_records_directory_map_directions_url', 'https://www.google.com/maps/dir/?api=1&destination={lat},{lng}' );

	return 0 === stripos( $url, 'https://' ) ? $url : '';
}

/**
 * A height setting in pixels, or the fallback when it is not one of the
 * schema's choices.
 *
 * @param mixed $value    The setting value.
 * @param int   $fallback Height to use otherwise.
 * @return int
 */
function agend_apps_records_directory_map_height( $value, int $fallback ): int {
	$value = (string) $value;
	return array_key_exists( $value, agend_apps_records_directory_map_height_options() ) ? (int) $value : $fallback;
}

/**
 * Builds the client-side config from the surface settings.
 *
 * @param array $s Surface settings (Elementor-shaped values: toggles are 'yes'/'').
 * @return array Config passed to the map script as JSON.
 */
function agend_apps_records_directory_map_build_config( array $s ): array {
	$limit = (string) ( $s['marker_limit'] ?? '500' );
	$empty = trim( (string) ( $s['empty_text'] ?? '' ) );
	$link  = trim( (string) ( $s['popup_link_text'] ?? '' ) );
	$route = trim( (string) ( $s['popup_directions_text'] ?? '' ) );

	return array(
		'followView'   => 'yes' === ( $s['follow_view'] ?? '' ),
		'cluster'      => 'yes' === ( $s['cluster'] ?? 'yes' ),
		'updateOnMove' => 'yes' === ( $s['update_on_move'] ?? '' ),
		'scrollZoom'   => 'yes' === ( $s['scroll_zoom'] ?? '' ),
		'showRadius'   => 'yes' === ( $s['show_radius'] ?? 'yes' ),
		'fullscreen'   => 'yes' === ( $s['fullscreen_button'] ?? 'yes' ),
		'locate'       => 'yes' === ( $s['locate_button'] ?? 'yes' ),
		'limit'        => in_array( $limit, array( '250', '500', '1000', '2000' ), true ) ? (int) $limit : 500,
		'emptyText'    => '' !== $empty ? $empty : __( 'No listings with a map location match these filters.', 'agend-apps-core' ),
		'popup'        => array(
			'logo'           => 'yes' === ( $s['popup_logo'] ?? 'yes' ),
			'category'       => 'yes' === ( $s['popup_category'] ?? 'yes' ),
			'locationName'   => 'yes' === ( $s['popup_location_name'] ?? '' ),
			'address'        => 'yes' === ( $s['popup_address'] ?? 'yes' ),
			'distance'       => 'yes' === ( $s['popup_distance'] ?? 'yes' ),
			'rating'         => 'yes' === ( $s['popup_rating'] ?? '' ),
			'linkText'       => '' !== $link ? $link : __( 'View details', 'agend-apps-core' ),
			'directions'     => 'yes' === ( $s['popup_directions'] ?? 'yes' ) ? agend_apps_records_directory_map_directions_url() : '',
			'directionsText' => '' !== $route ? $route : __( 'Get directions', 'agend-apps-core' ),
		),
		'tiles'        => agend_apps_records_directory_map_tiles(),
		// A pin's "View details" link, for a map with no catalogue on the
		// page to ask; with one, the catalogue's own links are used.
		'detailBase'   => class_exists( 'Agend_Apps_Records_Pages' ) ? Agend_Apps_Records_Pages::page_url( 'listing' ) : '',
		'prettyLinks'  => (bool) get_option( 'permalink_structure' ),
		'text'         => array(
			'truncated'      => __( 'Showing the first {shown} of {total} listings. Zoom in or filter to see the rest.', 'agend-apps-core' ),
			'failed'         => __( 'The map could not load listings.', 'agend-apps-core' ),
			'retry'          => __( 'Try again', 'agend-apps-core' ),
			'region'         => __( 'Map of directory listings', 'agend-apps-core' ),
			'you'            => __( 'Your search location', 'agend-apps-core' ),
			/* translators: shown over the map when a visitor scrolls the page across it. */
			'scrollHint'     => __( 'Use Ctrl + scroll to zoom the map', 'agend-apps-core' ),
			/* translators: the Mac version of the scroll hint; ⌘ is the Command key. */
			'scrollHintMac'  => __( 'Use ⌘ + scroll to zoom the map', 'agend-apps-core' ),
			'touchHint'      => __( 'Use two fingers to move the map', 'agend-apps-core' ),
			'fullscreen'     => __( 'Full screen', 'agend-apps-core' ),
			'locate'         => __( 'Show my location', 'agend-apps-core' ),
			'here'           => __( 'You are here', 'agend-apps-core' ),
			'locateFailed'   => __( 'Your location is not available.', 'agend-apps-core' ),
		),
	);
}

/**
 * The style hooks a map's Style-tab settings put on its root: each set value
 * becomes a CSS custom property, and assets/css/directory-map.css falls back
 * to the catalogue's accent colour (then the map's own default) for anything
 * left unset. Colours go through agend_apps_records_colour_is_safe(), and the
 * corner radius must be one of the schema's choices.
 *
 * @param array $s Surface settings.
 * @return string Inline style declarations.
 */
function agend_apps_records_directory_map_style( array $s ): string {
	$vars = array(
		'--agend-map-height:' . agend_apps_records_directory_map_height( $s['height'] ?? '480', 480 ) . 'px',
		'--agend-map-height-mobile:' . agend_apps_records_directory_map_height( $s['height_mobile'] ?? '360', 360 ) . 'px',
	);

	$colours = array(
		'pin_colour'          => '--agend-map-pin',
		'cluster_colour'      => '--agend-map-cluster',
		'cluster_text_colour' => '--agend-map-cluster-text',
		'radius_colour'       => '--agend-map-radius-colour',
		'link_colour'         => '--agend-map-link',
		'border_colour'       => '--agend-map-border',
	);
	foreach ( $colours as $setting => $property ) {
		$value = trim( (string) ( $s[ $setting ] ?? '' ) );
		if ( '' !== $value && agend_apps_records_colour_is_safe( $value ) ) {
			$vars[] = $property . ':' . $value;
		}
	}

	$radius = (string) ( $s['border_radius'] ?? '' );
	if ( '' !== $radius && array_key_exists( $radius, agend_apps_records_filter_radius_options() ) ) {
		$vars[] = '--agend-map-corner:' . (int) $radius . 'px';
	}

	return implode( ';', $vars );
}

/**
 * Renders the map surface container.
 *
 * @param array $settings Surface settings (see agend_apps_records_surface_schema( 'directory-map' )).
 * @param array $opts     'preview' (bool, default false): an editor preview,
 *                        which always shows the map even when it is set to
 *                        wait for the List / Map switch, so a designer can
 *                        see and size it.
 * @return string
 */
function agend_apps_records_render_directory_map( array $settings, array $opts = array() ): string {
	// A map inside a card template would draw once per card.
	if ( Agend_Apps_Records_Record_Context::has() ) {
		return '';
	}

	$config  = agend_apps_records_directory_map_build_config( $settings );
	$classes = 'agend-directory-map';
	if ( $config['followView'] && empty( $opts['preview'] ) ) {
		// Hidden until the switch says Map; the script removes the class.
		$classes .= ' agend-directory-map--awaiting-view';
	}

	return sprintf(
		'<div class="%1$s" style="%2$s" data-agend-map-config="%3$s"><div class="agend-map__canvas" role="region" aria-label="%4$s"></div><p class="agend-map__notice" role="status" aria-live="polite" hidden></p></div>',
		esc_attr( $classes ),
		esc_attr( agend_apps_records_directory_map_style( $settings ) ),
		esc_attr( (string) wp_json_encode( $config ) ),
		esc_attr( $config['text']['region'] )
	);
}
