<?php
/**
 * Content-settings schema for the events catalogue surface.
 *
 * Transcribed from the Content-tab controls that used to be hand-declared in
 * the events catalogue widget's register_content_controls(), including the
 * "Visitor Filter Bar" section's HEADING sub-group labels ("Search",
 * "Category", "Type", "City", "Date") as `heading` fields.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The events catalogue's content-settings schema.
 *
 * @return array
 */
function agend_apps_records_schema_events_catalogue(): array {
	return array(
		'sections' => array(
			array(
				'id'     => 'section_heading',
				'label'  => __( 'Heading', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'    => 'show_heading',
						'label'   => __( 'Show heading block', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'      => 'heading_text',
						'label'     => __( 'Heading', 'agend-apps-core' ),
						'type'      => 'text',
						'default'   => __( 'Upcoming Events', 'agend-apps-core' ),
						'condition' => array( 'show_heading' => 'yes' ),
					),
					array(
						'name'      => 'subheading_text',
						'label'     => __( 'Subheading', 'agend-apps-core' ),
						'type'      => 'textarea',
						'default'   => __( 'Conferences, workshops, and networking events across Australia.', 'agend-apps-core' ),
						'condition' => array( 'show_heading' => 'yes' ),
					),
				),
			),
			array(
				'id'     => 'section_layout',
				'label'  => __( 'Layout', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'    => 'columns_desktop',
						'label'   => __( 'Columns (Desktop)', 'agend-apps-core' ),
						'type'    => 'select',
						'options' => array(
							'2' => '2',
							'3' => '3',
							'4' => '4',
						),
						'default' => '3',
					),
					array(
						'name'    => 'columns_tablet',
						'label'   => __( 'Columns (Tablet)', 'agend-apps-core' ),
						'type'    => 'select',
						'options' => array(
							'1' => '1',
							'2' => '2',
						),
						'default' => '2',
					),
					array(
						'name'    => 'columns_mobile',
						'label'   => __( 'Columns (Mobile)', 'agend-apps-core' ),
						'type'    => 'select',
						'options' => array(
							'1' => '1',
							'2' => '2',
						),
						'default' => '1',
					),
					array(
						'name'    => 'card_radius',
						'label'   => __( 'Card Corner Radius (px)', 'agend-apps-core' ),
						'type'    => 'number',
						'default' => 10,
						'min'     => 0,
						'max'     => 48,
					),
				),
			),
			array(
				'id'     => 'section_card_template',
				'label'  => __( 'Card Template', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'        => 'card_template',
						'label'       => __( 'Card template', 'agend-apps-core' ),
						'type'        => 'template',
						'default'     => '',
						'placeholder' => __( 'Built-in card', 'agend-apps-core' ),
						'label_block' => true,
						'description' => __( 'A saved template (Templates > Saved Templates) rendered once per event. Build it from the Agend Field, Agend Image and Agend Link widgets. The built-in card options below apply only when no template is chosen.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'card_link_whole',
						'label'       => __( 'Whole card links to the event', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => true,
						'description' => __( 'Off: only Agend Link widgets inside the template navigate.', 'agend-apps-core' ),
						'condition'   => array( 'card_template!' => '' ),
					),
					array(
						'name'        => 'filter_template',
						'label'       => __( 'Filter template', 'agend-apps-core' ),
						'type'        => 'template',
						'default'     => '',
						'placeholder' => __( 'Built-in filter bar', 'agend-apps-core' ),
						'label_block' => true,
						'description' => __( 'A saved template built from Agend Filter widgets. Chosen here, it replaces the built-in filter bar and keeps working across the listing and detail views.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'filter_position',
						'label'       => __( 'Filter position', 'agend-apps-core' ),
						'type'        => 'select',
						'options'     => array(
							'top'   => __( 'Across the top', 'agend-apps-core' ),
							'left'  => __( 'Down the left', 'agend-apps-core' ),
							'right' => __( 'Down the right', 'agend-apps-core' ),
						),
						'default'     => 'top',
						'description' => __( 'A side position puts the filters in their own column beside the results. Set the column width on the filter template itself.', 'agend-apps-core' ),
						'condition'   => array( 'filter_template!' => '' ),
					),
				),
			),
			array(
				'id'        => 'section_card_fields',
				'label'     => __( 'Card Fields', 'agend-apps-core' ),
				'condition' => array( 'card_template' => '' ),
				'fields'    => array(
					array(
						'name'    => 'show_image',
						'label'   => __( 'Show event image', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'    => 'show_date_badge',
						'label'   => __( 'Show date badge', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'    => 'show_pills',
						'label'   => __( 'Show category / type pills', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'    => 'show_description',
						'label'   => __( 'Show description excerpt', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'      => 'excerpt_length',
						'label'     => __( 'Excerpt length (characters)', 'agend-apps-core' ),
						'type'      => 'number',
						'default'   => 110,
						'min'       => 20,
						'max'       => 400,
						'condition' => array( 'show_description' => 'yes' ),
					),
					array(
						'name'    => 'show_pricing',
						'label'   => __( 'Show Member / Non-Member pricing', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
				),
			),
			array(
				'id'        => 'section_filters',
				'label'     => __( 'Visitor Filter Bar', 'agend-apps-core' ),
				// Inert once a filter template drives the filters, so it only
				// appears while the built-in bar is what renders.
				'condition' => array( 'filter_template' => '' ),
				'fields'    => array(
					array(
						'name'  => 'heading_filter_search',
						'label' => __( 'Search', 'agend-apps-core' ),
						'type'  => 'heading',
					),
					array(
						'name'    => 'show_search',
						'label'   => __( 'Show search box', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'      => 'heading_filter_category',
						'label'     => __( 'Category', 'agend-apps-core' ),
						'type'      => 'heading',
						'separator' => 'before',
					),
					array(
						'name'    => 'show_category_filter',
						'label'   => __( 'Show category filter', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'      => 'multi_category_filter',
						'label'     => __( 'Allow multiple categories', 'agend-apps-core' ),
						'type'      => 'toggle',
						// Literal Elementor default from the original hand-declared
						// control, not the boolean convention every other toggle in
						// this schema uses.
						'default'   => 'no',
						'condition' => array( 'show_category_filter' => 'yes' ),
					),
					array(
						'name'      => 'category_match_mode',
						'label'     => __( 'Multiple categories match', 'agend-apps-core' ),
						'type'      => 'select',
						'options'   => array(
							'any' => __( 'Any (in any selected category)', 'agend-apps-core' ),
							'all' => __( 'All (in every selected category)', 'agend-apps-core' ),
						),
						'default'   => 'any',
						'condition' => array(
							'show_category_filter'  => 'yes',
							'multi_category_filter' => 'yes',
						),
					),
					array(
						'name'      => 'heading_filter_type',
						'label'     => __( 'Type', 'agend-apps-core' ),
						'type'      => 'heading',
						'separator' => 'before',
					),
					array(
						'name'    => 'show_type_filter',
						'label'   => __( 'Show type filter', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'      => 'multi_type_filter',
						'label'     => __( 'Allow multiple types', 'agend-apps-core' ),
						'type'      => 'toggle',
						'default'   => 'no',
						'condition' => array( 'show_type_filter' => 'yes' ),
					),
					array(
						'name'      => 'heading_filter_city',
						'label'     => __( 'City', 'agend-apps-core' ),
						'type'      => 'heading',
						'separator' => 'before',
					),
					array(
						'name'    => 'show_city_filter',
						'label'   => __( 'Show city filter', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'      => 'multi_city_filter',
						'label'     => __( 'Allow multiple cities', 'agend-apps-core' ),
						'type'      => 'toggle',
						'default'   => 'no',
						'condition' => array( 'show_city_filter' => 'yes' ),
					),
					array(
						'name'      => 'heading_filter_date',
						'label'     => __( 'Date', 'agend-apps-core' ),
						'type'      => 'heading',
						'separator' => 'before',
					),
					array(
						'name'        => 'show_date_filter',
						'label'       => __( 'Show date filter', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => true,
						'description' => __( 'A visitor dropdown to filter by event start date (starting after / before).', 'agend-apps-core' ),
					),
				),
			),
			array(
				'id'     => 'section_exclusions',
				'label'  => __( 'Exclusions', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'        => 'event_timeframe',
						'label'       => __( 'Events to show', 'agend-apps-core' ),
						'type'        => 'select',
						'options'     => array(
							'upcoming' => __( 'Upcoming', 'agend-apps-core' ),
							'past'     => __( 'Past', 'agend-apps-core' ),
							'all'      => __( 'All', 'agend-apps-core' ),
						),
						'default'     => 'upcoming',
						'description' => __( 'Which events this widget lists, by time. Defaults to Upcoming.', 'agend-apps-core' ),
					),
					array(
						'name'    => 'exclusions_note',
						'type'    => 'note',
						'content' => __( 'Excluded items never appear in this widget, and excluded categories are hidden from the visitor category filter.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'exclude_categories',
						'label'       => __( 'Exclude categories', 'agend-apps-core' ),
						'type'        => 'multiselect',
						'label_block' => true,
						'options'     => 'agend_apps_records_event_category_options',
					),
					array(
						'name'    => 'exclude_category_match_mode',
						'label'   => __( 'Category exclusion match', 'agend-apps-core' ),
						'type'    => 'select',
						'options' => array(
							'any' => __( 'Any (exclude if in any selected)', 'agend-apps-core' ),
							'all' => __( 'All (exclude only if in every selected)', 'agend-apps-core' ),
						),
						'default' => 'any',
					),
					array(
						'name'        => 'exclude_venue_types',
						'label'       => __( 'Exclude event types', 'agend-apps-core' ),
						'type'        => 'multiselect',
						'label_block' => true,
						'options'     => array(
							'physical' => __( 'In-Person', 'agend-apps-core' ),
							'virtual'  => __( 'Online', 'agend-apps-core' ),
							'hybrid'   => __( 'Hybrid', 'agend-apps-core' ),
						),
					),
					array(
						'name'        => 'exclude_cities',
						'label'       => __( 'Exclude cities', 'agend-apps-core' ),
						'type'        => 'multiselect',
						'label_block' => true,
						'options'     => 'agend_apps_records_event_city_options',
					),
				),
			),
			array(
				'id'     => 'section_pagination',
				'label'  => __( 'Pagination', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'    => 'pagination_style',
						'label'   => __( 'Pagination style', 'agend-apps-core' ),
						'type'    => 'select',
						'options' => array(
							'numbered'  => __( 'Numbered pages', 'agend-apps-core' ),
							'load_more' => __( 'Load more button', 'agend-apps-core' ),
							'none'      => __( 'None (show all fetched)', 'agend-apps-core' ),
						),
						'default' => 'numbered',
					),
					array(
						'name'    => 'per_page',
						'label'   => __( 'Events per page', 'agend-apps-core' ),
						'type'    => 'number',
						'default' => 9,
						'min'     => 1,
						'max'     => 100,
					),
				),
			),
			array(
				'id'     => 'section_style_colours',
				'label'  => __( 'Colours', 'agend-apps-core' ),
				'tab'    => 'style',
				'fields' => array(
					array(
						'name'        => 'inherit_colours',
						'label'       => __( 'Inherit theme colours', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => true,
						'description' => __( 'Use the site\'s theme colours when the theme sets them, otherwise the connected Agend account\'s colours. Turn off to set them manually below.', 'agend-apps-core' ),
					),
					array(
						'name'      => 'heading_colour',
						'label'     => __( 'Heading colour', 'agend-apps-core' ),
						'type'      => 'colour',
						'default'   => '#1E2A4A',
						'condition' => array( 'inherit_colours!' => 'yes' ),
					),
					array(
						'name'      => 'body_colour',
						'label'     => __( 'Body text colour', 'agend-apps-core' ),
						'type'      => 'colour',
						'default'   => '#26304D',
						'condition' => array( 'inherit_colours!' => 'yes' ),
					),
					array(
						'name'        => 'accent_colour',
						'label'       => __( 'Highlight / accent colour', 'agend-apps-core' ),
						'type'        => 'colour',
						'default'     => '#FF6B55',
						'description' => __( 'Drives FREE price text, active filter state, and the date badge month label.', 'agend-apps-core' ),
						'condition'   => array( 'inherit_colours!' => 'yes' ),
					),
					array(
						'name'      => 'button_colour',
						'label'     => __( 'Button colour', 'agend-apps-core' ),
						'type'      => 'colour',
						'default'   => '#FF6B55',
						'condition' => array( 'inherit_colours!' => 'yes' ),
					),
					array(
						'name'      => 'button_text_colour',
						'label'     => __( 'Button text colour', 'agend-apps-core' ),
						'type'      => 'colour',
						'default'   => '#FFFFFF',
						'condition' => array( 'inherit_colours!' => 'yes' ),
					),
				),
			),
		),
	);
}

/**
 * Category exclusion options for the events catalogue, built from the live
 * catalogue via the cached core wrapper so editor loads do not hammer the
 * gateway. Empty when the API is unreachable — the control still renders, it
 * just has nothing to offer.
 *
 * Moved from the widget's category_options() method verbatim.
 *
 * @return array Options keyed by category id.
 */
function agend_apps_records_event_category_options(): array {
	if ( ! function_exists( 'agend_apps_events_get_categories' ) ) {
		return array();
	}

	$response = agend_apps_events_get_categories();

	if ( is_wp_error( $response ) || empty( $response['data'] ) || ! is_array( $response['data'] ) ) {
		return array();
	}

	$options = array();

	foreach ( $response['data'] as $category ) {
		if ( ! empty( $category['id'] ) && ! empty( $category['name'] ) ) {
			$options[ (string) $category['id'] ] = (string) $category['name'];
		}
	}

	return $options;
}

/**
 * City exclusion options for the events catalogue, built from the venues
 * catalogue.
 *
 * Moved from the widget's city_options() method verbatim.
 *
 * @return array Options keyed by city name (the gateway filters on the city
 *               string, not a venue id).
 */
function agend_apps_records_event_city_options(): array {
	if ( ! function_exists( 'agend_apps_events_get_venues' ) ) {
		return array();
	}

	$response = agend_apps_events_get_venues( array( 'limit' => 100 ) );

	if ( is_wp_error( $response ) || empty( $response['data'] ) || ! is_array( $response['data'] ) ) {
		return array();
	}

	$options = array();

	foreach ( $response['data'] as $venue ) {
		$city = '';
		if ( ! empty( $venue['city'] ) ) {
			$city = (string) $venue['city'];
		} elseif ( ! empty( $venue['venue_city'] ) ) {
			$city = (string) $venue['venue_city'];
		}
		if ( '' !== $city ) {
			$options[ $city ] = $city;
		}
	}

	return $options;
}
