<?php
/**
 * Search links: the server's reading of a directory catalogue search carried
 * in the page address.
 *
 * States the same read rules as decode() in assets/js/search-link.js, so the
 * first page the server draws for a search link is the page the browser would
 * ask for. tests/fixtures/directory-search-link-cases.json is loaded by both
 * test suites and pins the two together.
 *
 * Request values arrive slashed (WordPress adds slashes to $_GET); they are
 * unslashed once here, and every value returned is unslashed.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The Location filter's own geocoder limit, applied to `q` and `address`. */
const AGEND_APPS_RECORDS_SEARCH_LINK_TEXT_MAX = 200;

/** The gateway's location name limit. */
const AGEND_APPS_RECORDS_SEARCH_LINK_LOCATION_NAME_MAX = 100;

/** The gateway's limit on one city, state, postcode or country value. */
const AGEND_APPS_RECORDS_SEARCH_LINK_LOCATION_VALUE_MAX = 100;

/** The gateway's limit on values per location component. */
const AGEND_APPS_RECORDS_SEARCH_LINK_LOCATION_VALUES_MAX = 50;

/** Past the last page of any directory; a larger page is read as this one. */
const AGEND_APPS_RECORDS_SEARCH_LINK_PAGE_MAX = 10000;

/** The gateway's largest search radius, in kilometres. */
const AGEND_APPS_RECORDS_SEARCH_LINK_RADIUS_MAX = 1000;

/**
 * The state keys a plain filter writes, each read back from one parameter.
 *
 * @return string[]
 */
function agend_apps_records_search_link_plain_keys(): array {
	return array( 'search', 'categories', 'location_name', 'location_city', 'location_state', 'location_postcode', 'location_country', 'tag_ids', 'badge_ids', 'rating', 'featured', 'sortBy' );
}

/**
 * What a catalogue's filter template lets a search link set, from the configs
 * of the filters it rendered. Mirrors readable() in assets/js/search-link.js.
 *
 * @param array<int, array> $configs Filter configs, as their shells carry them.
 * @return array{keys: array<string, bool>, cf: array<string, bool>, rf: array<string, bool>, near: ?array{radius: float}, view: ?array{default: string}}
 */
function agend_apps_records_search_link_readable( array $configs ): array {
	$out = array(
		'keys' => array(),
		'cf'   => array(),
		'rf'   => array(),
		'near' => null,
		'view' => null,
	);

	foreach ( $configs as $config ) {
		$state = is_array( $config ) && is_string( $config['state'] ?? null ) ? $config['state'] : '';
		if ( '' === $state ) {
			continue;
		}
		$mode    = (string) ( $config['mode'] ?? '' );
		$control = (string) ( $config['control'] ?? '' );

		if ( 'map' === $mode ) {
			$field = is_string( $config['fieldKey'] ?? null ) ? $config['fieldKey'] : '';
			if ( '' !== $field && in_array( $control, array( 'select', 'checkboxes', 'buttons' ), true ) ) {
				$out['cf'][ $field ] = true;
			} elseif ( '' !== $field && in_array( $control, array( 'range', 'range_brackets' ), true ) ) {
				$out['rf'][ $field ] = true;
			}
			continue;
		}
		if ( 'location' === $mode ) {
			if ( null === $out['near'] ) {
				$radius      = is_numeric( $config['location']['radius'] ?? null ) ? (float) $config['location']['radius'] : 0.0;
				$out['near'] = array( 'radius' => $radius > 0 ? $radius : 15.0 );
			}
			continue;
		}
		if ( 'view' === $mode ) {
			if ( null === $out['view'] ) {
				$out['view'] = array( 'default' => 'map' === ( $config['view']['default'] ?? '' ) ? 'map' : 'list' );
			}
			continue;
		}
		if ( in_array( $state, agend_apps_records_search_link_plain_keys(), true ) ) {
			$out['keys'][ $state ] = true;
		}
	}

	return $out;
}

/**
 * ASCII whitespace trimmed, as trimAscii() in the script trims it.
 *
 * @param string $value The value.
 * @return string
 */
function agend_apps_records_search_link_trim( string $value ): string {
	return trim( $value, " \t\n\r\f\v" );
}

/**
 * A text value trimmed and cut to `$max` code points.
 *
 * @param string $value The value.
 * @param int    $max   The most code points kept.
 * @return string
 */
function agend_apps_records_search_link_text( string $value, int $max ): string {
	$value = agend_apps_records_search_link_trim( $value );
	if ( mb_strlen( $value, 'UTF-8' ) > $max ) {
		$value = mb_substr( $value, 0, $max, 'UTF-8' );
	}
	return agend_apps_records_search_link_trim( $value );
}

/**
 * Whether a value is a plain decimal number: digits, an optional minus sign
 * and an optional fraction. No exponent, no thousands separator, no spaces.
 *
 * @param string $value The value.
 * @return bool
 */
function agend_apps_records_search_link_is_number( string $value ): bool {
	return 1 === preg_match( '/^-?\d+(\.\d+)?\z/', $value );
}

/**
 * A list parameter's values: split on commas, `%2C` and `%25` turned back
 * into a comma and a percent sign, empty values dropped.
 *
 * @param string $value The parameter, already URL-decoded once.
 * @return string[]
 */
function agend_apps_records_search_link_values( string $value ): array {
	$values = array();
	foreach ( explode( ',', $value ) as $entry ) {
		$entry = (string) preg_replace_callback(
			'/%(25|2C)/i',
			static function ( array $match ): string {
				return '25' === $match[1] ? '%' : ',';
			},
			$entry
		);
		if ( '' !== $entry ) {
			$values[] = $entry;
		}
	}
	return $values;
}

/**
 * A point from `near`, `radius` and `address`, or null when `near` is not a
 * valid point. A missing or invalid radius is the Location filter's default.
 *
 * @param array $params  Unslashed request values.
 * @param float $default The Location filter's default radius.
 * @return array{lat: float, lng: float, radius: float, label: string, source: string}|null
 */
function agend_apps_records_search_link_near( array $params, float $default ): ?array {
	$raw   = is_string( $params['near'] ?? null ) ? $params['near'] : '';
	$parts = explode( ',', $raw );
	if ( 2 !== count( $parts ) || ! agend_apps_records_search_link_is_number( $parts[0] ) || ! agend_apps_records_search_link_is_number( $parts[1] ) ) {
		return null;
	}
	$lat = (float) $parts[0];
	$lng = (float) $parts[1];
	if ( $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 ) {
		return null;
	}

	$radius_raw = is_string( $params['radius'] ?? null ) ? $params['radius'] : '';
	$radius     = agend_apps_records_search_link_is_number( $radius_raw ) ? (float) $radius_raw : 0.0;
	if ( $radius <= 0 || $radius > AGEND_APPS_RECORDS_SEARCH_LINK_RADIUS_MAX ) {
		$radius = $default;
	}

	$label = is_string( $params['address'] ?? null ) ? agend_apps_records_search_link_text( $params['address'], AGEND_APPS_RECORDS_SEARCH_LINK_TEXT_MAX ) : '';

	return array(
		'lat'    => $lat,
		'lng'    => $lng,
		'radius' => $radius,
		'label'  => '' !== $label ? $label : $raw,
		'source' => 'place',
	);
}

/**
 * The `bbox` as given when it is four valid edges, otherwise ''.
 *
 * @param mixed $raw The request value.
 * @return string
 */
function agend_apps_records_search_link_bbox( $raw ): string {
	$parts = is_string( $raw ) ? explode( ',', $raw ) : array();
	if ( 4 !== count( $parts ) || count( array_filter( $parts, 'agend_apps_records_search_link_is_number' ) ) !== 4 ) {
		return '';
	}
	list( $north, $south, $east, $west ) = array_map( 'floatval', $parts );
	if ( $north > 90 || $south < -90 || $south > $north || $east < -180 || $east > 180 || $west < -180 || $west > 180 ) {
		return '';
	}
	return implode( ',', $parts );
}

/**
 * An `rf_` range as `{min, max}` with only the sides it has, or null.
 *
 * @param mixed $raw The request value.
 * @return array<string, string>|null
 */
function agend_apps_records_search_link_range( $raw ): ?array {
	if ( ! is_string( $raw ) || 1 !== preg_match( '/^([^:]*):([^:]*)\z/', $raw, $match ) ) {
		return null;
	}
	$bounds = array();
	foreach ( array( 'min' => $match[1], 'max' => $match[2] ) as $side => $value ) {
		$value = agend_apps_records_search_link_trim( $value );
		if ( '' === $value ) {
			continue;
		}
		if ( ! agend_apps_records_search_link_is_number( $value ) ) {
			return null;
		}
		$bounds[ $side ] = $value;
	}
	return empty( $bounds ) ? null : $bounds;
}

/**
 * A number written without leading zeros or a trailing fractional zero, so
 * `4.0` and `04` both read as the `4` a rating filter offers.
 *
 * @param string $value A plain decimal number.
 * @return string
 */
function agend_apps_records_search_link_number_text( string $value ): string {
	$value = (string) preg_replace( '/^(-?)0+(?=\d)/', '$1', $value );
	if ( false !== strpos( $value, '.' ) ) {
		$value = rtrim( rtrim( $value, '0' ), '.' );
	}
	return $value;
}

/**
 * Reads a search link into catalogue state, in the shape
 * agend_apps_records_listings_list_args() takes.
 *
 * Only the parameters the catalogue's filter template writes are read
 * (`$readable`), every value is validated, and `custom_fields` is sorted by
 * key, so two links that differ only in parameter order build the same query
 * and share its cache entry.
 *
 * @param array $params   Request values as WordPress provides them, slashed.
 * @param array $readable agend_apps_records_search_link_readable() output.
 * @param bool  $bbox     Whether a map search area is read.
 * @return array{state: array, page: int}
 */
function agend_apps_records_search_link_decode( array $params, array $readable, bool $bbox = true ): array {
	$params = wp_unslash( $params );
	foreach ( $params as $name => $value ) {
		if ( ! is_string( $value ) || ! mb_check_encoding( $value, 'UTF-8' ) ) {
			unset( $params[ $name ] );
		}
	}
	$keys  = (array) ( $readable['keys'] ?? array() );
	$state = array();
	$page  = 1;

	$pg = $params['pg'] ?? '';
	if ( 1 === preg_match( '/^\d+\z/', $pg ) && (int) $pg > 0 ) {
		$page = min( (int) $pg, AGEND_APPS_RECORDS_SEARCH_LINK_PAGE_MAX );
	}

	if ( ! empty( $keys['search'] ) && isset( $params['q'] ) ) {
		$search = agend_apps_records_search_link_text( $params['q'], AGEND_APPS_RECORDS_SEARCH_LINK_TEXT_MAX );
		if ( '' !== $search ) {
			$state['search'] = $search;
		}
	}

	foreach ( array( 'category_ids' => 'categories', 'tag_ids' => 'tag_ids', 'badge_ids' => 'badge_ids' ) as $param => $key ) {
		if ( empty( $keys[ $key ] ) || ! isset( $params[ $param ] ) ) {
			continue;
		}
		$ids = array();
		foreach ( agend_apps_records_search_link_values( $params[ $param ] ) as $id ) {
			if ( 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $id ) ) {
				$ids[] = strtolower( $id );
			}
		}
		if ( ! empty( $ids ) ) {
			$state[ $key ] = $ids;
		}
	}

	$custom = array();
	foreach ( array_keys( (array) ( $readable['cf'] ?? array() ) ) as $field ) {
		$values = isset( $params[ 'cf_' . $field ] ) ? agend_apps_records_search_link_values( $params[ 'cf_' . $field ] ) : array();
		if ( ! empty( $values ) ) {
			$custom[ (string) $field ] = $values;
		}
	}
	foreach ( array_keys( (array) ( $readable['rf'] ?? array() ) ) as $field ) {
		$bounds = agend_apps_records_search_link_range( $params[ 'rf_' . $field ] ?? null );
		if ( null !== $bounds ) {
			$custom[ (string) $field ] = $bounds;
		}
	}
	if ( ! empty( $custom ) ) {
		ksort( $custom, SORT_STRING );
		$state['custom_fields'] = $custom;
	}

	if ( ! empty( $keys['location_name'] ) && isset( $params['location_name'] ) ) {
		$name = agend_apps_records_search_link_text( $params['location_name'], AGEND_APPS_RECORDS_SEARCH_LINK_LOCATION_NAME_MAX );
		if ( '' !== $name ) {
			$state['location_name'] = $name;
		}
	}

	foreach ( array( 'city' => 'location_city', 'state' => 'location_state', 'postcode' => 'location_postcode', 'country' => 'location_country' ) as $param => $key ) {
		if ( empty( $keys[ $key ] ) || ! isset( $params[ $param ] ) ) {
			continue;
		}
		$values = array_values(
			array_filter(
				agend_apps_records_search_link_values( $params[ $param ] ),
				static function ( string $value ): bool {
					return mb_strlen( $value, 'UTF-8' ) <= AGEND_APPS_RECORDS_SEARCH_LINK_LOCATION_VALUE_MAX;
				}
			)
		);
		$values = array_slice( $values, 0, AGEND_APPS_RECORDS_SEARCH_LINK_LOCATION_VALUES_MAX );
		if ( ! empty( $values ) ) {
			$state[ $key ] = $values;
		}
	}

	$rating = $params['rating'] ?? '';
	if ( ! empty( $keys['rating'] ) && agend_apps_records_search_link_is_number( $rating ) && (float) $rating >= 1 && (float) $rating <= 5 ) {
		$state['rating'] = agend_apps_records_search_link_number_text( $rating );
	}

	if ( ! empty( $keys['featured'] ) && 'true' === ( $params['featured_only'] ?? '' ) ) {
		$state['featured'] = '1';
	}

	$sort = $params['sort'] ?? '';
	if ( ! empty( $keys['sortBy'] ) && in_array( $sort, array( 'relevance', 'distance', 'name', 'created_at', 'view_count', 'rating' ), true ) ) {
		$state['sortBy'] = $sort;
	}

	if ( is_array( $readable['near'] ?? null ) ) {
		$near = agend_apps_records_search_link_near( $params, (float) $readable['near']['radius'] );
		if ( null !== $near ) {
			$state['near'] = $near;
		}
	}

	// A point wins over an area, as it does in every query the catalogue asks.
	if ( $bbox && ! isset( $state['near'] ) ) {
		$area = agend_apps_records_search_link_bbox( $params['bbox'] ?? null );
		if ( '' !== $area ) {
			$state['bbox']       = $area;
			$state['bboxOrigin'] = 'visitor';
		}
	}

	$view = $params['view'] ?? '';
	if ( is_array( $readable['view'] ?? null ) && in_array( $view, array( 'list', 'map' ), true ) ) {
		$state['view'] = $view;
	}

	return array(
		'state' => $state,
		'page'  => $page,
	);
}

/**
 * Whether a decoded link asks for anything but the default results. The view
 * changes how they are shown, not which they are.
 *
 * @param array{state: array, page: int} $decoded agend_apps_records_search_link_decode() output.
 * @return bool
 */
function agend_apps_records_search_link_is_search( array $decoded ): bool {
	return $decoded['page'] > 1 || ! empty( array_diff( array_keys( $decoded['state'] ), array( 'view', 'bboxOrigin' ) ) );
}

/**
 * Decoded state in the form agend_apps_records_listings_list_args() takes.
 *
 * That builder expects the Organisation filter's text slashed, as read from a
 * superglobal, and unslashes it itself; the decoder has already unslashed it,
 * so it is slashed again here rather than losing a backslash the visitor typed.
 *
 * @param array $state Decoded state.
 * @return array
 */
function agend_apps_records_search_link_list_state( array $state ): array {
	if ( isset( $state['location_name'] ) ) {
		$state['location_name'] = addslashes( $state['location_name'] );
	}
	return $state;
}
