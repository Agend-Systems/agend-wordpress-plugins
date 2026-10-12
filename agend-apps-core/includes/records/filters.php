<?php
/**
 * Filter registry for the Agend Filter widget.
 *
 * One place that knows which filters each catalogue type offers, which key of
 * the catalogue script's state a filter writes, and where its values come
 * from. The widget, the editor picker and the tests all read this, so adding
 * a filter is one entry here plus whatever the API needs.
 *
 * A filter's `state` key is the contract with the catalogue scripts: the
 * runtime writes `state[<key>]` and calls reload, and the query builders in
 * query.php already map those keys to gateway
 * parameters. Nothing else couples the widget to a particular catalogue.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The record type whose filters are being rendered.
 *
 * A filter template is rendered by a catalogue widget, which sets this so the
 * filter widgets inside it know which catalogue they belong to without the
 * author having to say so on every widget.
 */
final class Agend_Apps_Records_Filter_Context {

	/**
	 * Current record type, or ''.
	 *
	 * @var string
	 */
	private static $type = '';

	/**
	 * Sets the record type for the template render in progress.
	 *
	 * @param string $type 'event', 'course' or 'listing'.
	 */
	public static function set( string $type ): void {
		self::$type = $type;
	}

	/**
	 * The current record type, or ''.
	 *
	 * @return string
	 */
	public static function type(): string {
		return self::$type;
	}

	/**
	 * Clears the context. Paired with set() in a try/finally by the renderer.
	 */
	public static function reset(): void {
		self::$type = '';
	}
}

/**
 * Static value lists that need no API call.
 *
 * @param string $set The value set name.
 * @return array<string, string> Value => label.
 */
function agend_apps_records_filter_static_values( string $set ): array {
	switch ( $set ) {
		case 'venue_type':
			return array(
				'physical' => __( 'In-Person', 'agend-apps-core' ),
				'virtual'  => __( 'Online', 'agend-apps-core' ),
				'hybrid'   => __( 'Hybrid', 'agend-apps-core' ),
			);
		case 'difficulty':
			return array(
				'beginner'     => __( 'Beginner', 'agend-apps-core' ),
				'intermediate' => __( 'Intermediate', 'agend-apps-core' ),
				'advanced'     => __( 'Advanced', 'agend-apps-core' ),
				'all_levels'   => __( 'All Levels', 'agend-apps-core' ),
			);
		case 'delivery_mode':
			return array(
				'self_paced'  => __( 'Self-paced', 'agend-apps-core' ),
				'live_online' => __( 'Live Online', 'agend-apps-core' ),
				'in_person'   => __( 'In Person', 'agend-apps-core' ),
				'blended'     => __( 'Blended', 'agend-apps-core' ),
			);
		case 'featured':
			return array( '1' => __( 'Featured only', 'agend-apps-core' ) );
		case 'listing_sort':
			return array(
				'relevance'  => __( 'Most relevant', 'agend-apps-core' ),
				'name'       => __( 'Name', 'agend-apps-core' ),
				'rating'     => __( 'Highest rated', 'agend-apps-core' ),
				'created_at' => __( 'Newest', 'agend-apps-core' ),
				'view_count' => __( 'Most viewed', 'agend-apps-core' ),
				// Only meaningful once a Location filter has set a point: without
				// one the catalogue falls back to relevance.
				'distance'   => __( 'Nearest', 'agend-apps-core' ),
			);
		case 'rating':
			return array(
				'4' => __( '4 stars and up', 'agend-apps-core' ),
				'3' => __( '3 stars and up', 'agend-apps-core' ),
				'2' => __( '2 stars and up', 'agend-apps-core' ),
				'1' => __( '1 star and up', 'agend-apps-core' ),
			);
	}
	return array();
}

/**
 * Every filter each catalogue type offers.
 *
 * Descriptor keys:
 * - `label`      default control label.
 * - `state`      the catalogue state key this filter writes.
 * - `mode`       'array' when the state key holds a list, 'scalar' otherwise,
 *                'map' for one key of a map, 'location' for a {lat, lng,
 *                radius, label} point, 'view' for the list/map switch.
 * - `controls`   presentations the filter supports, first is the default.
 * - `source`     where "all values" come from: `null` (no value list, e.g. a
 *                free-text search), `array('static' => <set>)`,
 *                `array('endpoint' => <rest path>, 'value' => k, 'label' => k)`
 *                with optional `distinct` for a payload that repeats values
 *                and optional `parent` naming the key that holds a record's
 *                parent id, which also gives the filter a Category level,
 *                or `array('facet' => <facet name>)` for a value list the
 *                facets endpoint enumerates, entitlement-scoped.
 * - `approximate` true when the source cannot enumerate reliably today.
 *
 * @return array<string, array<string, array<string, mixed>>>
 */
function agend_apps_records_filter_registry(): array {
	static $registry = null;
	if ( null !== $registry ) {
		return $registry;
	}

	$reset = array(
		'label'    => __( 'Clear filters', 'agend-apps-core' ),
		'state'    => '',
		'mode'     => 'scalar',
		'controls' => array( 'reset' ),
		'source'   => null,
	);

	$search = array(
		'label'    => __( 'Search', 'agend-apps-core' ),
		'state'    => 'search',
		'mode'     => 'scalar',
		'controls' => array( 'search' ),
		'source'   => null,
	);

	$registry = array(
		'event'   => array(
			'search'     => $search,
			'reset'      => $reset,
			'category'   => array(
				'label'    => __( 'Category', 'agend-apps-core' ),
				'state'    => 'categories',
				'mode'     => 'array',
				'controls' => array( 'checkboxes', 'select', 'buttons' ),
				'source'   => array( 'endpoint' => '/events/categories', 'value' => 'id', 'label' => 'name' ),
			),
			'venue_type' => array(
				'label'    => __( 'Format', 'agend-apps-core' ),
				'state'    => 'types',
				'mode'     => 'array',
				'controls' => array( 'checkboxes', 'select', 'buttons' ),
				'source'   => array( 'static' => 'venue_type' ),
			),
			'city'       => array(
				'label'    => __( 'City', 'agend-apps-core' ),
				'state'    => 'cities',
				'mode'     => 'array',
				'controls' => array( 'checkboxes', 'select', 'buttons' ),
				'source'   => array( 'endpoint' => '/events/venues', 'value' => 'city', 'label' => 'city', 'distinct' => true ),
			),
			'date_from'  => array(
				'label'    => __( 'Starting from', 'agend-apps-core' ),
				'state'    => 'startAfter',
				'mode'     => 'scalar',
				'controls' => array( 'date' ),
				'source'   => null,
			),
			'date_to'    => array(
				'label'    => __( 'Starting before', 'agend-apps-core' ),
				'state'    => 'startBefore',
				'mode'     => 'scalar',
				'controls' => array( 'date' ),
				'source'   => null,
			),
		),
		'course'  => array(
			'search'        => $search,
			'reset'         => $reset,
			'category'      => array(
				'label'       => __( 'Category', 'agend-apps-core' ),
				'state'       => 'category',
				'mode'        => 'scalar',
				'controls'    => array( 'select', 'buttons' ),
				// Course categories are free text on the course with no list
				// endpoint, so "all values" can only collect the distinct
				// values of one page of courses. A category used beyond that
				// page never appears; use author-defined choices when the
				// catalogue is larger than a page.
				'source'      => array( 'endpoint' => '/lms/courses', 'value' => 'category', 'label' => 'category', 'distinct' => true, 'limit' => 100 ),
				'approximate' => true,
			),
			'difficulty'    => array(
				'label'    => __( 'Level', 'agend-apps-core' ),
				'state'    => 'difficulty',
				'mode'     => 'scalar',
				'controls' => array( 'select', 'buttons' ),
				'source'   => array( 'static' => 'difficulty' ),
			),
			'delivery_mode' => array(
				'label'    => __( 'Delivery mode', 'agend-apps-core' ),
				'state'    => 'deliveryMode',
				'mode'     => 'scalar',
				'controls' => array( 'select', 'buttons' ),
				'source'   => array( 'static' => 'delivery_mode' ),
			),
		),
		'listing' => array(
			'search'   => $search,
			'reset'    => $reset,
			'category' => array(
				'label'    => __( 'Category', 'agend-apps-core' ),
				'state'    => 'categories',
				'mode'     => 'array',
				'controls' => array( 'checkboxes', 'select', 'buttons' ),
				'source'   => array( 'endpoint' => '/directory/categories', 'value' => 'id', 'label' => 'name', 'parent' => 'parent_id' ),
			),
			// The gateway takes these as comma-joined named params; a PHP array
			// value would be bracket-encoded by http_build_query() and 422. There
			// is deliberately no 'suburb' entry: the API treats it as an alias for
			// 'city' and 422s if both arrive.
			'city'     => array(
				'label'    => __( 'City', 'agend-apps-core' ),
				'state'    => 'location_city',
				'mode'     => 'array',
				'controls' => array( 'checkboxes', 'select', 'buttons' ),
				'source'   => array( 'facet' => 'location.city' ),
			),
			'state'    => array(
				'label'    => __( 'State', 'agend-apps-core' ),
				'state'    => 'location_state',
				'mode'     => 'array',
				'controls' => array( 'checkboxes', 'select', 'buttons' ),
				'source'   => array( 'facet' => 'location.state' ),
			),
			'postcode' => array(
				'label'    => __( 'Postcode', 'agend-apps-core' ),
				'state'    => 'location_postcode',
				'mode'     => 'array',
				'controls' => array( 'checkboxes', 'select', 'buttons' ),
				'source'   => array( 'facet' => 'location.postcode' ),
			),
			'country'  => array(
				'label'    => __( 'Country', 'agend-apps-core' ),
				'state'    => 'location_country',
				'mode'     => 'array',
				'controls' => array( 'checkboxes', 'select', 'buttons' ),
				'source'   => array( 'facet' => 'location.country' ),
			),
			'rating'   => array(
				'label'    => __( 'Minimum rating', 'agend-apps-core' ),
				'state'    => 'rating',
				'mode'     => 'scalar',
				'controls' => array( 'select', 'buttons' ),
				'source'   => array( 'static' => 'rating' ),
			),
			'sort'     => array(
				'label'    => __( 'Sort by', 'agend-apps-core' ),
				'state'    => 'sortBy',
				'mode'     => 'scalar',
				'controls' => array( 'select', 'buttons' ),
				'source'   => array( 'static' => 'listing_sort' ),
			),
			'featured' => array(
				'label'    => __( 'Featured', 'agend-apps-core' ),
				'state'    => 'featured',
				'mode'     => 'scalar',
				'controls' => array( 'buttons', 'select' ),
				'source'   => array( 'static' => 'featured' ),
			),
			'tag'      => array(
				'label'    => __( 'Tag', 'agend-apps-core' ),
				'state'    => 'tag_ids',
				'mode'     => 'array',
				'controls' => array( 'checkboxes', 'select', 'buttons' ),
				'source'   => array( 'facet' => 'tags' ),
			),
			'badge'    => array(
				'label'    => __( 'Badge', 'agend-apps-core' ),
				'state'    => 'badge_ids',
				'mode'     => 'array',
				'controls' => array( 'checkboxes', 'select', 'buttons' ),
				'source'   => array( 'facet' => 'badges' ),
			),
			// A point and a radius: typed as a place the gateway geocodes, or
			// taken from the browser. The state is one object, {lat, lng,
			// radius, label}, because the three only mean something together.
			'location'     => array(
				'label'    => __( 'Location', 'agend-apps-core' ),
				'state'    => 'near',
				'mode'     => 'location',
				'controls' => array( 'location' ),
				'source'   => null,
			),
			// Switches the page between the results list and an Agend Map
			// widget set to follow it. Writes the catalogue's view, not a
			// query, so changing it never reloads results.
			'view'         => array(
				'label'    => __( 'View', 'agend-apps-core' ),
				'state'    => 'view',
				'mode'     => 'view',
				'controls' => array( 'view' ),
				'source'   => null,
			),
			// A custom field is addressed by key, so one registry entry backs
			// every field an account has configured as a filter. The facet
			// endpoint decides which keys this viewer may see at all.
			'custom_field' => array(
				'label'      => __( 'Custom field', 'agend-apps-core' ),
				'state'      => 'custom_fields',
				'mode'       => 'map',
				'controls'   => array( 'checkboxes', 'select', 'buttons', 'range', 'range_brackets' ),
				'source'     => array( 'facet' => 'custom' ),
				'needs_key'  => true,
			),
		),
	);

	/**
	 * Filters the catalogue filter registry.
	 *
	 * The hook a site or a later release uses to add a filter once the API can
	 * enumerate or accept it (tags, badges, custom fields).
	 *
	 * @param array $registry Filters keyed by record type then filter key.
	 */
	$registry = (array) apply_filters( 'agend_apps_records_filter_registry', $registry );

	// A site's existing add_filter() on the pre-rename hook name still applies
	// for one release.
	return (array) apply_filters_deprecated( 'agend_elementor_filter_registry', array( $registry ), '1.8.0', 'agend_apps_records_filter_registry' );
}

/**
 * A single filter descriptor, or null when the type does not offer it.
 *
 * @param string $type Record type.
 * @param string $key  Filter key.
 * @return array<string, mixed>|null
 */
function agend_apps_records_filter_descriptor( string $type, string $key ): ?array {
	$registry = agend_apps_records_filter_registry();
	return $registry[ $type ][ $key ] ?? null;
}

/**
 * Filter picker options for an Elementor SELECT, grouped by record type.
 *
 * @return array<int, array{label: string, options: array<string, string>}>
 */
function agend_apps_records_filter_options(): array {
	$labels = array(
		'event'   => __( 'Events', 'agend-apps-core' ),
		'course'  => __( 'Courses', 'agend-apps-core' ),
		'listing' => __( 'Directory', 'agend-apps-core' ),
	);
	$groups = array();
	foreach ( agend_apps_records_filter_registry() as $type => $filters ) {
		$options = array();
		foreach ( $filters as $key => $descriptor ) {
			$options[ $type . ':' . $key ] = (string) $descriptor['label'];
		}
		$groups[] = array( 'label' => $labels[ $type ] ?? $type, 'options' => $options );
	}
	return $groups;
}

/**
 * The runtime config a filter widget hands to the catalogue script.
 *
 * Static value sets are resolved here; endpoint-backed lists are named for the
 * script to fetch, because they are per-account data the editor should not
 * bake into the template.
 *
 * @param string $type     Record type.
 * @param string $key      Filter key.
 * @param array  $settings Widget settings.
 * @return array<string, mixed>|null Config, or null when the filter is unknown.
 */
function agend_apps_records_filter_config( string $type, string $key, array $settings ): ?array {
	$descriptor = agend_apps_records_filter_descriptor( $type, $key );
	if ( null === $descriptor ) {
		return null;
	}

	if ( ! empty( $descriptor['needs_key'] ) && '' === trim( (string) ( $settings['custom_field_key'] ?? '' ) ) ) {
		return null;
	}

	// A filter whose values cannot be enumerated yet only ever carries author
	// defined choices, whatever the widget's own mode says.
	$values_mode = (string) ( $settings['values_mode'] ?? 'all' );
	if ( ! empty( $descriptor['choices_only'] ) ) {
		$values_mode = 'choices';
	}

	$level    = agend_apps_records_filter_category_level( $descriptor, $settings, $values_mode );
	$controls = $descriptor['controls'];
	if ( in_array( $level, array( 'top', 'sub' ), true ) ) {
		// One category at a time: a level derives what it shows from a single
		// selected id, which checkboxes cannot promise.
		$controls = array_values( array_intersect( $controls, array( 'select', 'buttons' ) ) );
	}

	$control = (string) ( $settings['control'] ?? '' );
	if ( ! in_array( $control, $controls, true ) ) {
		$control = $controls[0];
	}

	$config = array(
		'choicesOnly' => ! empty( $descriptor['choices_only'] ),
		'needsKey'    => ! empty( $descriptor['needs_key'] ),
		'fieldKey'    => trim( (string) ( $settings['custom_field_key'] ?? '' ) ),
		'type'        => $type,
		'filter'      => $key,
		'state'       => $descriptor['state'],
		'mode'        => $descriptor['mode'],
		'control'     => $control,
		// An unset Elementor text control is an empty string, not null, so the
		// filter's own name has to be restored explicitly.
		'label'       => '' !== trim( (string) ( $settings['label'] ?? '' ) )
			? trim( (string) $settings['label'] )
			: (string) $descriptor['label'],
		'showLabel'   => 'yes' === ( $settings['show_label'] ?? 'yes' ),
		'placeholder' => (string) ( $settings['placeholder'] ?? '' ),
		'anyLabel'    => (string) ( $settings['any_label'] ?? '' ),
		'values'      => array(),
		'source'      => null,
	);

	if ( '' !== $level ) {
		$config['categoryLevel'] = $level;
	}

	// The location and view controls carry their own settings rather than a
	// value list. Written only for those two, so every other filter's config
	// (and the markup it is pinned in) is unchanged.
	if ( 'location' === $control ) {
		$config['location'] = agend_apps_records_filter_location_config( $settings );
		return $config;
	}
	if ( 'view' === $control ) {
		$config['view'] = agend_apps_records_filter_view_config( $settings );
		return $config;
	}
	// Range brackets write the same {min, max} the Number range control does,
	// from a facet that may carry Agend's own brackets, so the source is named
	// here whatever the widget's Values setting says (a Values choice list has
	// no meaning for a bracket control).
	if ( 'range_brackets' === $control ) {
		$config['rangeBrackets'] = agend_apps_records_filter_range_brackets_config( $settings );
		$config['source']        = array( 'facet' => 'custom.' . $config['fieldKey'] );
		return $config;
	}

	if ( 'choices' === $values_mode ) {
		// Author-defined selections: each choice sends a fixed set of values,
		// which is how "All States" or "Between 50 and 100" are expressed
		// without the API having to describe them.
		foreach ( (array) ( $settings['choices'] ?? array() ) as $choice ) {
			$label = trim( (string) ( $choice['choice_label'] ?? '' ) );
			$value = trim( (string) ( $choice['choice_value'] ?? '' ) );
			if ( '' === $label && '' === $value ) {
				continue;
			}
			$values = array_values( array_filter( array_map( 'trim', explode( ',', $value ) ), 'strlen' ) );
			$config['values'][] = array(
				'label' => '' !== $label ? $label : $value,
				'value' => $values,
			);
		}
		return $config;
	}

	$source = $descriptor['source'];
	if ( is_array( $source ) && isset( $source['facet'] ) ) {
		// 'custom' is a placeholder: the real facet name is custom.<key>, and
		// the key is per widget instance.
		$facet             = 'custom' === $source['facet']
			? 'custom.' . $config['fieldKey']
			: (string) $source['facet'];
		$config['source']  = array( 'facet' => $facet );
		return $config;
	}
	if ( is_array( $source ) && isset( $source['static'] ) ) {
		foreach ( agend_apps_records_filter_static_values( (string) $source['static'] ) as $value => $label ) {
			$config['values'][] = array( 'label' => $label, 'value' => array( (string) $value ) );
		}
		return $config;
	}
	if ( is_array( $source ) && isset( $source['endpoint'] ) ) {
		$config['source'] = array(
			'path'     => (string) $source['endpoint'],
			'value'    => (string) $source['value'],
			'labelKey' => (string) $source['label'],
			'distinct' => ! empty( $source['distinct'] ),
			'limit'    => (int) ( $source['limit'] ?? 0 ),
		);
		if ( isset( $source['parent'] ) ) {
			$config['source']['parentKey'] = (string) $source['parent'];
		}
	}

	return $config;
}

/**
 * The Category level a filter uses: 'all', 'top' or 'sub', or '' for a filter
 * that has no levels.
 *
 * Levels need the category tree, which only the endpoint's own values carry,
 * so author defined choices always offer the choices as written.
 *
 * @param array  $descriptor  The filter descriptor.
 * @param array  $settings    Widget settings.
 * @param string $values_mode 'all' or 'choices'.
 * @return string
 */
function agend_apps_records_filter_category_level( array $descriptor, array $settings, string $values_mode ): string {
	if ( ! isset( $descriptor['source']['parent'] ) ) {
		return '';
	}

	$level = (string) ( $settings['category_level'] ?? 'all' );
	if ( 'choices' === $values_mode || ! in_array( $level, array( 'top', 'sub' ), true ) ) {
		return 'all';
	}

	return $level;
}

/**
 * The radius choices a Location filter offers, in kilometres, from the
 * author's comma-separated list: positive numbers up to the gateway's 1000 km
 * limit, deduplicated and in ascending order.
 *
 * @param mixed $raw The setting value, e.g. "5,10,25,50".
 * @return float[] Falls back to 5, 10, 15, 20, 25, 50 and 100 when nothing usable is given.
 */
function agend_apps_records_filter_radius_choices( $raw ): array {
	$choices = array();
	foreach ( explode( ',', (string) $raw ) as $part ) {
		$part = trim( $part );
		if ( '' === $part || ! is_numeric( $part ) ) {
			continue;
		}
		$value = (float) $part;
		if ( $value > 0 && $value <= 1000 ) {
			$choices[ (string) $value ] = $value;
		}
	}
	if ( empty( $choices ) ) {
		return array( 5.0, 10.0, 15.0, 20.0, 25.0, 50.0, 100.0 );
	}
	sort( $choices );
	return array_values( $choices );
}

/**
 * The Location filter's runtime settings.
 *
 * @param array $settings Widget settings.
 * @return array{search: bool, locate: bool, buttonText: string, locateText: string, region: string, radii: float[], radius: float, layout?: string}
 */
function agend_apps_records_filter_location_config( array $settings ): array {
	$radii   = agend_apps_records_filter_radius_choices( $settings['location_radius_choices'] ?? '' );
	$default = is_numeric( $settings['location_radius_default'] ?? null ) ? (float) $settings['location_radius_default'] : 15.0;
	// A default that is not one of the choices would leave the dropdown
	// showing a value it cannot send, so snap to the nearest choice.
	$radius = $radii[0];
	foreach ( $radii as $choice ) {
		if ( abs( $choice - $default ) < abs( $radius - $default ) ) {
			$radius = $choice;
		}
	}

	$search = 'yes' === ( $settings['location_show_search'] ?? 'yes' );
	$locate = 'yes' === ( $settings['location_show_locate'] ?? 'yes' );

	$config = array(
		'search'     => $search,
		// With the place box turned off the button is the only way in, so
		// there is always at least one.
		'locate'     => $locate || ! $search,
		'buttonText' => trim( (string) ( $settings['location_button_text'] ?? '' ) ),
		'locateText' => trim( (string) ( $settings['location_locate_text'] ?? '' ) ),
		'region'     => trim( (string) ( $settings['location_region'] ?? '' ) ),
		'radii'      => $radii,
		'radius'     => $radius,
	);

	// Written only for the one-row layout, so a stacked filter's config (and
	// the markup it is pinned in) is unchanged.
	if ( 'row' === ( $settings['location_layout'] ?? 'stacked' ) ) {
		$config['layout'] = 'row';
	}

	return $config;
}

/**
 * The List / Map switch's runtime settings.
 *
 * @param array $settings Widget settings.
 * @return array{default: string, layout: string, listLabel: string, mapLabel: string}
 */
function agend_apps_records_filter_view_config( array $settings ): array {
	return array(
		'default'   => 'map' === ( $settings['view_default'] ?? 'list' ) ? 'map' : 'list',
		'layout'    => 'joined' === ( $settings['view_layout'] ?? 'separate' ) ? 'joined' : 'separate',
		'listLabel' => trim( (string) ( $settings['view_list_label'] ?? '' ) ),
		'mapLabel'  => trim( (string) ( $settings['view_map_label'] ?? '' ) ),
	);
}

/**
 * The most brackets an author's own list may carry. Further valid lines are
 * dropped, with a notice in the editor.
 */
const AGEND_APPS_RECORDS_RANGE_BRACKETS_MAX = 12;

/**
 * The largest bound, in either direction, a bracket accepts. Keeps every bound
 * an exact integer or a short decimal in JavaScript's number type too, and
 * well clear of the exponent notation the gateway would not read.
 */
const AGEND_APPS_RECORDS_RANGE_BRACKETS_BOUND_LIMIT = 1.0e15;

/**
 * One bracket bound as a number.
 *
 * Open ends are `null`: an absent value, `null` (the facet contract allows an
 * explicit null) and an empty string all mean "no limit on this side". A bound
 * is otherwise a plain number, as a number or as numeric text. Thousands
 * separators are rejected rather than guessed at, because "1,500" is fifteen
 * hundred in one locale and one and a half in another.
 *
 * @param mixed $value The raw bound.
 * @return int|float|null|false The number, `null` for an open end, or `false`
 *                              when the value is not a usable number.
 */
function agend_apps_records_filter_range_brackets_bound( $value ) {
	if ( null === $value ) {
		return null;
	}
	if ( is_string( $value ) ) {
		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}
		if ( 1 !== preg_match( '/^-?\d+(\.\d+)?$/', $value ) ) {
			return false;
		}
		$value = (float) $value;
	} elseif ( is_int( $value ) ) {
		$value = (float) $value;
	} elseif ( ! is_float( $value ) ) {
		return false;
	}

	if ( ! is_finite( $value ) || abs( $value ) > AGEND_APPS_RECORDS_RANGE_BRACKETS_BOUND_LIMIT ) {
		return false;
	}

	// Whole numbers stay integers so the JSON the script reads is 10000, not 10000.0.
	return floor( $value ) === $value ? (int) $value : $value;
}

/**
 * A bracket's label as plain text: real tags removed, control characters and
 * runs of whitespace collapsed to one space.
 *
 * sanitize_text_field() is deliberately not used. It rewrites a leading "<" to
 * an entity, so a label like "<10,000" would reach the script as "&lt;10,000"
 * and be shown that way. A label is only ever written with textContent in the
 * script and esc_html() here, so what it needs is to be clean text, not
 * pre-escaped.
 *
 * @param mixed $label The raw label.
 * @return string '' when it is not a string or nothing is left.
 */
function agend_apps_records_filter_range_brackets_label( $label ): string {
	if ( ! is_string( $label ) ) {
		return '';
	}
	$label = (string) preg_replace( '/<\/?[a-z][^>]*>/i', '', $label );
	$label = (string) preg_replace( '/[\x00-\x1F\x7F\s]+/', ' ', $label );
	return trim( $label );
}

/**
 * Validates one bracket.
 *
 * The one rule set shared by an author's own lines and by the presets the
 * facets endpoint returns, so a bracket means the same wherever it came from:
 * a label, at least one bound, bounds that are numbers, and a minimum that is
 * not above the maximum. Bounds are inclusive.
 *
 * @param mixed $label The label.
 * @param mixed $min   The minimum, or an open end.
 * @param mixed $max   The maximum, or an open end.
 * @return array{code: string, bracket?: array{label: string, min: int|float|null, max: int|float|null}}
 *               `code` is '' for a valid bracket (carried in `bracket`), else
 *               'label', 'number', 'open' or 'order'.
 */
function agend_apps_records_filter_range_brackets_entry( $label, $min, $max ): array {
	$label = agend_apps_records_filter_range_brackets_label( $label );
	if ( '' === $label ) {
		return array( 'code' => 'label' );
	}

	$min = agend_apps_records_filter_range_brackets_bound( $min );
	$max = agend_apps_records_filter_range_brackets_bound( $max );
	if ( false === $min || false === $max ) {
		return array( 'code' => 'number' );
	}
	if ( null === $min && null === $max ) {
		// Both ends open is every value, which is what "Any" already is.
		return array( 'code' => 'open' );
	}
	if ( null !== $min && null !== $max && $min > $max ) {
		return array( 'code' => 'order' );
	}

	return array(
		'code'    => '',
		'bracket' => array(
			'label' => $label,
			'min'   => $min,
			'max'   => $max,
		),
	);
}

/**
 * Parses an author's bracket list, one bracket per line as `Label | min | max`.
 *
 * A blank bound is an open end: `Under 10,000 | | 9999`. Blank lines are
 * ignored. A line that is not exactly three parts, or that fails the rules in
 * agend_apps_records_filter_range_brackets_entry(), is skipped and reported
 * rather than guessed at: a two-part line such as `Under 10,000 | 9999` would
 * silently read as a minimum, the opposite of what its author meant. Only the
 * first AGEND_APPS_RECORDS_RANGE_BRACKETS_MAX valid lines are kept.
 *
 * @param mixed $raw The textarea value.
 * @return array{brackets: array<int, array{label: string, min: int|float|null, max: int|float|null}>, issues: array<int, array{line: int, code: string}>}
 *               `issues` names each skipped line (1-based) and why: 'format',
 *               'label', 'number', 'open', 'order', or 'limit' for the first
 *               valid line past the cap (every later line is dropped with it).
 */
function agend_apps_records_filter_range_brackets_parse( $raw ): array {
	$brackets = array();
	$issues   = array();
	$lines    = preg_split( '/\r\n|\r|\n/', is_string( $raw ) ? $raw : '' );

	foreach ( (array) $lines as $index => $line ) {
		$number = $index + 1;
		if ( '' === trim( (string) $line ) ) {
			continue;
		}

		$parts = explode( '|', (string) $line );
		if ( 3 !== count( $parts ) ) {
			$issues[] = array( 'line' => $number, 'code' => 'format' );
			continue;
		}

		$entry = agend_apps_records_filter_range_brackets_entry( $parts[0], $parts[1], $parts[2] );
		if ( '' !== $entry['code'] ) {
			$issues[] = array( 'line' => $number, 'code' => $entry['code'] );
			continue;
		}

		if ( count( $brackets ) >= AGEND_APPS_RECORDS_RANGE_BRACKETS_MAX ) {
			$issues[] = array( 'line' => $number, 'code' => 'limit' );
			break;
		}
		$brackets[] = $entry['bracket'];
	}

	return array( 'brackets' => $brackets, 'issues' => $issues );
}

/**
 * The brackets in a facet's `presets` list, validated.
 *
 * The facet contract is `[ { label, min?, max? } ]`, an omitted or null bound
 * being open. A preset that fails the rules is dropped quietly: it is data
 * from the gateway, not something an author can fix in the widget.
 *
 * @param mixed $presets The facet's `presets`, or anything when it has none.
 * @return array<int, array{label: string, min: int|float|null, max: int|float|null}>
 */
function agend_apps_records_filter_range_brackets_presets( $presets ): array {
	$brackets = array();
	if ( ! is_array( $presets ) ) {
		return $brackets;
	}

	foreach ( $presets as $preset ) {
		if ( ! is_array( $preset ) ) {
			continue;
		}
		$entry = agend_apps_records_filter_range_brackets_entry( $preset['label'] ?? '', $preset['min'] ?? null, $preset['max'] ?? null );
		if ( '' === $entry['code'] ) {
			$brackets[] = $entry['bracket'];
		}
	}

	return $brackets;
}

/**
 * Which brackets a Range brackets filter offers.
 *
 * "Agend" uses the facet's presets; "custom" uses the widget's own rows. An
 * "agend" filter whose facet has no presets falls back to the widget's rows
 * when there are any. Custom never falls back to Agend: an author who chose
 * their own brackets and wrote none has an empty list to fix, not a reason to
 * show someone else's. When nothing is left the control has nothing to offer
 * and is not drawn.
 *
 * assets/js/filters.js resolveBrackets() states the same rule for the visitor,
 * where the facet is only known once the page has asked for it.
 *
 * @param string $source  'agend' or 'custom'.
 * @param array  $presets The facet's validated presets (empty when it has none).
 * @param array  $custom  The widget's validated rows.
 * @return array{origin: string, brackets: array} `origin` is 'agend', 'custom' or 'none'.
 */
function agend_apps_records_filter_range_brackets_resolve( string $source, array $presets, array $custom ): array {
	if ( 'custom' !== $source && ! empty( $presets ) ) {
		return array( 'origin' => 'agend', 'brackets' => $presets );
	}
	if ( ! empty( $custom ) ) {
		return array( 'origin' => 'custom', 'brackets' => $custom );
	}
	return array( 'origin' => 'none', 'brackets' => array() );
}

/**
 * The Range brackets filter's runtime settings.
 *
 * Handed to the script as `rangeBrackets`: the source, how to draw the choice,
 * the "Any" label, and the author's own rows already validated, so the script
 * never parses the textarea. Written only for this control, so every other
 * filter's config (and the markup it is pinned in) is unchanged.
 *
 * @param array $settings Widget settings.
 * @return array{source: string, display: string, anyLabel: string, custom: array}
 */
function agend_apps_records_filter_range_brackets_config( array $settings ): array {
	$display = (string) ( $settings['range_brackets_display'] ?? 'select' );
	$any     = trim( (string) ( $settings['range_brackets_any_label'] ?? '' ) );

	return array(
		'source'   => 'custom' === ( $settings['range_brackets_source'] ?? 'agend' ) ? 'custom' : 'agend',
		'display'  => in_array( $display, array( 'buttons', 'radio' ), true ) ? $display : 'select',
		// An unset Elementor text control is an empty string, not its default.
		'anyLabel' => '' !== $any ? $any : __( 'Any', 'agend-apps-core' ),
		'custom'   => agend_apps_records_filter_range_brackets_parse( $settings['range_brackets_custom'] ?? '' )['brackets'],
	);
}

/**
 * Border width choices for the filter field style settings.
 *
 * @return array<string, string>
 */
function agend_apps_records_filter_border_width_options(): array {
	return array(
		''  => __( 'Theme default', 'agend-apps-core' ),
		'0' => __( 'None', 'agend-apps-core' ),
		'1' => '1px',
		'2' => '2px',
		'3' => '3px',
	);
}

/**
 * Corner radius choices for the filter style settings.
 *
 * @return array<string, string>
 */
function agend_apps_records_filter_radius_options(): array {
	return array(
		''    => __( 'Theme default', 'agend-apps-core' ),
		'0'   => __( 'Square', 'agend-apps-core' ),
		'2'   => '2px',
		'4'   => '4px',
		'6'   => '6px',
		'8'   => '8px',
		'12'  => '12px',
		'999' => __( 'Pill', 'agend-apps-core' ),
	);
}

/**
 * Field height choices for the filter field style settings.
 *
 * @return array<string, string>
 */
function agend_apps_records_filter_height_options(): array {
	return array(
		''   => __( 'Theme default', 'agend-apps-core' ),
		'32' => '32px',
		'36' => '36px',
		'40' => '40px',
		'44' => '44px',
		'48' => '48px',
		'56' => '56px',
	);
}

/**
 * Horizontal padding choices for the filter field style settings.
 *
 * @return array<string, string>
 */
function agend_apps_records_filter_padding_options(): array {
	return array(
		''   => __( 'Theme default', 'agend-apps-core' ),
		'4'  => '4px',
		'8'  => '8px',
		'10' => '10px',
		'12' => '12px',
		'16' => '16px',
		'20' => '20px',
	);
}
