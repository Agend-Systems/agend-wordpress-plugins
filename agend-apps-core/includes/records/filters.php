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
 *                with optional `distinct` for a payload that repeats values,
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
				'source'   => array( 'endpoint' => '/directory/categories', 'value' => 'id', 'label' => 'name' ),
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
				'controls'   => array( 'checkboxes', 'select', 'buttons', 'range' ),
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

	$control = (string) ( $settings['control'] ?? '' );
	if ( ! in_array( $control, $descriptor['controls'], true ) ) {
		$control = $descriptor['controls'][0];
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

	// A filter whose values cannot be enumerated yet only ever carries author
	// defined choices, whatever the widget's own mode says.
	$values_mode = (string) ( $settings['values_mode'] ?? 'all' );
	if ( ! empty( $descriptor['choices_only'] ) ) {
		$values_mode = 'choices';
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
	}

	return $config;
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
 * @return array{search: bool, locate: bool, buttonText: string, locateText: string, region: string, radii: float[], radius: float}
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

	return array(
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
}

/**
 * The List / Map switch's runtime settings.
 *
 * @param array $settings Widget settings.
 * @return array{default: string, listLabel: string, mapLabel: string}
 */
function agend_apps_records_filter_view_config( array $settings ): array {
	return array(
		'default'   => 'map' === ( $settings['view_default'] ?? 'list' ) ? 'map' : 'list',
		'listLabel' => trim( (string) ( $settings['view_list_label'] ?? '' ) ),
		'mapLabel'  => trim( (string) ( $settings['view_map_label'] ?? '' ) ),
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
