<?php
/**
 * Catalogue list query builders shared by the server-rendered first page and
 * the REST fragment endpoint.
 *
 * Both paths must ask the gateway the same question, or page one (rendered
 * by the widget) and page two (rendered over REST) disagree. The key names
 * here are the ones assets/js/events-catalogue.js and courses-catalogue.js
 * already send to the Agend Apps Core proxy, and the allow-lists mirror the
 * proxy controllers in agend-apps-core/includes/rest/.
 *
 * @package Agend_Elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Upper bound on a page size requested over REST.
 */
const AGEND_ELEMENTOR_FRAGMENT_MAX_LIMIT = 100;

/**
 * Query parameters the fragment endpoint forwards to the gateway for a type.
 *
 * Copied from Agend_Apps_Events_REST_Controller::get_events() and
 * Agend_Apps_LMS_REST_Controller::get_courses() in agend-apps-core.
 *
 * @param string $type 'event' or 'course'.
 * @return string[]
 */
function agend_elementor_fragment_allowed_params( string $type ): array {
	if ( 'listing' === $type ) {
		return array( 'q', 'search', 'page', 'limit', 'per_page', 'category', 'tag_ids', 'badge_ids', 'custom_fields', 'sponsor_level', 'lat', 'lng', 'radius', 'rating', 'featured', 'sortBy', 'sortOrder', 'excludeCategories' );
	}
	if ( 'course' === $type ) {
		return array( 'page', 'per_page', 'limit', 'search', 'category', 'difficulty', 'deliveryMode', 'excludeCategories', 'excludeDifficulties', 'excludeDeliveryModes', 'sortBy', 'sortOrder' );
	}
	return array( 'page', 'limit', 'search', 'category', 'type', 'city', 'categories', 'types', 'cities', 'categoriesMatch', 'timeframe', 'startAfter', 'startBefore', 'sortBy', 'sortOrder', 'excludeCategories', 'excludeTags', 'excludeVenueTypes', 'excludeCities', 'excludeCategoriesMatch' );
}

/**
 * Reduces raw request parameters to the gateway query for a type.
 *
 * Drops everything not on the allow-list (our own `template`, `detail_page`
 * and friends, the REST nonce, anything injected), keeps array values as
 * arrays (the wrappers split arrays into repeatable gateway params), drops
 * empty scalars, and clamps `limit`.
 *
 * @param array  $params Raw request parameters.
 * @param string $type   'event' or 'course'.
 * @return array
 */
function agend_elementor_fragment_query_args( array $params, string $type ): array {
	$allowed = agend_elementor_fragment_allowed_params( $type );
	$query   = array();

	foreach ( $params as $key => $value ) {
		if ( ! in_array( (string) $key, $allowed, true ) ) {
			continue;
		}
		if ( 'custom_fields' === $key ) {
			// A map of field key => matching values or bounds, not a list, so
			// it passes through as-is for add_query_arg() to encode as
			// custom_fields[key] and custom_fields[key][min].
			$map = agend_elementor_custom_field_filters( $value );
			if ( ! empty( $map ) ) {
				$query[ $key ] = $map;
			}
			continue;
		}
		if ( is_array( $value ) ) {
			$values = array_values( array_filter( array_map( 'strval', array_filter( $value, 'is_scalar' ) ), 'strlen' ) );
			if ( ! empty( $values ) ) {
				$query[ $key ] = $values;
			}
			continue;
		}
		if ( null === $value || '' === $value ) {
			continue;
		}
		$query[ $key ] = is_scalar( $value ) ? $value : (string) $value;
	}

	foreach ( array( 'limit', 'per_page' ) as $size_key ) {
		if ( isset( $query[ $size_key ] ) ) {
			$query[ $size_key ] = max( 1, min( AGEND_ELEMENTOR_FRAGMENT_MAX_LIMIT, (int) $query[ $size_key ] ) );
		}
	}
	if ( isset( $query['page'] ) ) {
		$query['page'] = max( 1, (int) $query['page'] );
	}

	return $query;
}

/**
 * The events list query for a widget config, matching reloadCatalogue() in
 * assets/js/events-catalogue.js key for key.
 *
 * @param array $config The widget's build_config() output.
 * @param int   $page   Page number.
 * @param array $state  Visitor filter state (search, category, type, city,
 *                      categories, types, cities, startAfter, startBefore).
 * @return array
 */
function agend_elementor_events_list_args( array $config, int $page = 1, array $state = array() ): array {
	$exclusions = isset( $config['exclusions'] ) && is_array( $config['exclusions'] ) ? $config['exclusions'] : array();
	$filters    = isset( $config['filters'] ) && is_array( $config['filters'] ) ? $config['filters'] : array();

	$args = array(
		'page'                   => max( 1, $page ),
		'limit'                  => (int) ( $config['pagination']['perPage'] ?? 9 ),
		'search'                 => (string) ( $state['search'] ?? '' ),
		'category'               => (string) ( $state['category'] ?? '' ),
		'type'                   => (string) ( $state['type'] ?? '' ),
		'city'                   => (string) ( $state['city'] ?? '' ),
		'categories'             => (array) ( $state['categories'] ?? array() ),
		'types'                  => (array) ( $state['types'] ?? array() ),
		'cities'                 => (array) ( $state['cities'] ?? array() ),
		'categoriesMatch'        => (string) ( $filters['categoryMatch'] ?? 'any' ),
		'timeframe'              => (string) ( $config['timeframe'] ?? 'upcoming' ),
		'startAfter'             => (string) ( $state['startAfter'] ?? '' ),
		'startBefore'            => (string) ( $state['startBefore'] ?? '' ),
		'excludeCategories'      => (array) ( $exclusions['categories'] ?? array() ),
		'excludeVenueTypes'      => (array) ( $exclusions['venueTypes'] ?? array() ),
		'excludeCities'          => (array) ( $exclusions['cities'] ?? array() ),
		'excludeCategoriesMatch' => (string) ( $exclusions['categoryMatch'] ?? 'any' ),
	);

	return agend_elementor_fragment_query_args( $args, 'event' );
}

/**
 * The courses list query for a widget config, matching reloadCatalogue() in
 * assets/js/courses-catalogue.js key for key.
 *
 * @param array $config The widget's build_config() output.
 * @param int   $page   Page number.
 * @param array $state  Visitor filter state (search, category, difficulty,
 *                      deliveryMode).
 * @return array
 */
function agend_elementor_courses_list_args( array $config, int $page = 1, array $state = array() ): array {
	$exclusions = isset( $config['exclusions'] ) && is_array( $config['exclusions'] ) ? $config['exclusions'] : array();

	$args = array(
		'page'                 => max( 1, $page ),
		'limit'                => (int) ( $config['pagination']['perPage'] ?? 9 ),
		'search'               => (string) ( $state['search'] ?? '' ),
		'category'             => (string) ( $state['category'] ?? '' ),
		'difficulty'           => (string) ( $state['difficulty'] ?? '' ),
		'deliveryMode'         => (string) ( $state['deliveryMode'] ?? '' ),
		'excludeCategories'    => (array) ( $exclusions['categories'] ?? array() ),
		'excludeDifficulties'  => (array) ( $exclusions['difficulties'] ?? array() ),
		'excludeDeliveryModes' => (array) ( $exclusions['deliveryModes'] ?? array() ),
	);

	return agend_elementor_fragment_query_args( $args, 'course' );
}

/**
 * The listings query for a widget config, matching reloadCatalogue() in
 * assets/js/directory-catalogue.js key for key.
 *
 * The directory takes comma-joined strings where the other catalogues take
 * repeatable arrays, so the exclusion list is joined here rather than passed
 * through as an array.
 *
 * @param array $config The widget's build_config() output.
 * @param int   $page   Page number.
 * @param array $state  Visitor filter state (search, category, categories, rating).
 * @return array
 */
function agend_elementor_listings_list_args( array $config, int $page = 1, array $state = array() ): array {
	$exclusions = isset( $config['exclusions'] ) && is_array( $config['exclusions'] ) ? $config['exclusions'] : array();
	$categories = (array) ( $state['categories'] ?? array() );

	$args = array(
		'page'              => max( 1, $page ),
		'limit'             => (int) ( $config['pagination']['perPage'] ?? 12 ),
		'search'            => (string) ( $state['search'] ?? '' ),
		'category'          => ! empty( $categories ) ? implode( ',', $categories ) : (string) ( $state['category'] ?? '' ),
		'rating'            => (string) ( $state['rating'] ?? '' ),
		// The widget-level "featured only" setting is a floor; a visitor filter
		// can turn it on but never off.
		'featured'          => ( ! empty( $exclusions['featured'] ) || ! empty( $state['featured'] ) ) ? 'true' : '',
		'tag_ids'           => implode( ',', (array) ( $state['tag_ids'] ?? array() ) ),
		'badge_ids'         => implode( ',', (array) ( $state['badge_ids'] ?? array() ) ),
		'custom_fields'     => agend_elementor_custom_field_filters( $state['custom_fields'] ?? array() ),
		'excludeCategories' => implode( ',', (array) ( $exclusions['categories'] ?? array() ) ),
		// Relevance unless a Sort filter says otherwise; name reads better
		// ascending, everything else descending.
		'sortBy'            => '' !== (string) ( $state['sortBy'] ?? '' ) ? (string) $state['sortBy'] : 'relevance',
		'sortOrder'         => 'name' === (string) ( $state['sortBy'] ?? '' ) ? 'asc' : 'desc',
	);

	return agend_elementor_fragment_query_args( $args, 'listing' );
}

/**
 * Fetches a page of records for a catalogue type through the Agend Apps Core
 * wrapper, so the server-rendered first page and the REST fragments call the
 * same thing the browser would.
 *
 * The directory takes its search term as a separate argument and translates
 * `category` to the gateway's canonical `category_ids`, mirroring
 * Agend_Apps_Directory_REST_Controller::search().
 *
 * @param string $type  'event', 'course' or 'listing'.
 * @param array  $query Allow-listed query parameters.
 * @return mixed Wrapper response, WP_Error, or null when unavailable.
 */
function agend_elementor_fetch_list( string $type, array $query ) {
	if ( 'listing' === $type ) {
		if ( ! function_exists( 'agend_apps_directory_search' ) ) {
			return null;
		}
		$term = (string) ( $query['q'] ?? $query['search'] ?? '' );
		unset( $query['q'], $query['search'] );
		if ( isset( $query['category'] ) ) {
			$query['category_ids'] = $query['category'];
			unset( $query['category'] );
		}
		return agend_apps_directory_search( $term, $query );
	}
	if ( 'course' === $type ) {
		return function_exists( 'agend_apps_lms_get_courses' ) ? agend_apps_lms_get_courses( $query ) : null;
	}
	return function_exists( 'agend_apps_events_get_events' ) ? agend_apps_events_get_events( $query ) : null;
}

/**
 * Normalises custom field filter state into the gateway's wire format.
 *
 * A key holding a list becomes a comma-joined string matching values, and a
 * key holding min/max becomes a bounds map. Empty keys are dropped so an
 * untouched filter contributes nothing to the query.
 *
 * @param mixed $state The `custom_fields` slice of the catalogue state.
 * @return array<string, mixed>
 */
function agend_elementor_custom_field_filters( $state ): array {
	if ( ! is_array( $state ) ) {
		return array();
	}
	$out = array();
	foreach ( $state as $key => $value ) {
		$key = trim( (string) $key );
		if ( '' === $key ) {
			continue;
		}
		if ( is_array( $value ) && ( isset( $value['min'] ) || isset( $value['max'] ) ) ) {
			$bounds = array();
			foreach ( array( 'min', 'max' ) as $bound ) {
				if ( isset( $value[ $bound ] ) && '' !== $value[ $bound ] ) {
					$bounds[ $bound ] = (string) $value[ $bound ];
				}
			}
			if ( ! empty( $bounds ) ) {
				$out[ $key ] = $bounds;
			}
			continue;
		}
		$values = array_values( array_filter( array_map( 'trim', array_map( 'strval', (array) $value ) ), 'strlen' ) );
		if ( ! empty( $values ) ) {
			$out[ $key ] = implode( ',', $values );
		}
	}
	return $out;
}

/**
 * Normalises a gateway list response into items plus pagination, the shape
 * unwrapList() in the catalogue scripts produces.
 *
 * @param mixed $response Wrapper response (array) or WP_Error.
 * @return array{items: array, pagination: array|null, error: bool}
 */
function agend_elementor_unwrap_list( $response ): array {
	if ( is_wp_error( $response ) || ! is_array( $response ) ) {
		return array( 'items' => array(), 'pagination' => null, 'error' => true );
	}
	if ( isset( $response['data'] ) && is_array( $response['data'] ) ) {
		$pagination = ( isset( $response['meta']['pagination'] ) && is_array( $response['meta']['pagination'] ) ) ? $response['meta']['pagination'] : null;
		return array( 'items' => array_values( array_filter( $response['data'], 'is_array' ) ), 'pagination' => $pagination, 'error' => false );
	}
	if ( array_is_list( $response ) ) {
		return array( 'items' => array_values( array_filter( $response, 'is_array' ) ), 'pagination' => null, 'error' => false );
	}
	return array( 'items' => array(), 'pagination' => null, 'error' => false );
}
