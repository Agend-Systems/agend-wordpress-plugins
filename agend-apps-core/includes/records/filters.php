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
 * - `mode`       'array' when the state key holds a list, 'scalar' otherwise.
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
