<?php
/**
 * Content-settings schema for the Agend Directory Search surface.
 *
 * A keyword and place box that searches the directory. The Style-tab controls
 * stay hand-declared in each editor adapter, because they are CSS.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Agend Directory Search widget's content-settings schema.
 *
 * @return array
 */
function agend_apps_records_schema_directory_search(): array {
	return array(
		'sections' => array(
			array(
				'id'     => 'section_content',
				'label'  => __( 'Content', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'    => 'search_note',
						'type'    => 'note',
						'content' => __( 'On a page with a Directory Catalogue the search runs in place and fills in from the catalogue\'s own state, so Clear all empties it too. On any other page it sends the visitor to the directory page.', 'agend-apps-core' ),
					),
					array(
						'name'    => 'show_keyword',
						'label'   => __( 'Show keyword field', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'        => 'keyword_label',
						'label'       => __( 'Keyword label', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => __( 'Keyword', 'agend-apps-core' ),
						'description' => __( 'Read by screen readers; not shown.', 'agend-apps-core' ),
						'condition'   => array( 'show_keyword' => 'yes' ),
					),
					array(
						'name'      => 'keyword_placeholder',
						'label'     => __( 'Keyword placeholder', 'agend-apps-core' ),
						'type'      => 'text',
						'default'   => __( 'Search by name or keyword', 'agend-apps-core' ),
						'condition' => array( 'show_keyword' => 'yes' ),
					),
					array(
						'name'    => 'show_location',
						'label'   => __( 'Show location field', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'        => 'location_label',
						'label'       => __( 'Location label', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => __( 'Location', 'agend-apps-core' ),
						'description' => __( 'Read by screen readers; not shown.', 'agend-apps-core' ),
						'condition'   => array( 'show_location' => 'yes' ),
					),
					array(
						'name'      => 'location_placeholder',
						'label'     => __( 'Location placeholder', 'agend-apps-core' ),
						'type'      => 'text',
						'default'   => __( 'Suburb or postcode', 'agend-apps-core' ),
						'condition' => array( 'show_location' => 'yes' ),
					),
					array(
						'name'    => 'button_text',
						'label'   => __( 'Button text', 'agend-apps-core' ),
						'type'    => 'text',
						'default' => __( 'Search', 'agend-apps-core' ),
					),
					array(
						'name'    => 'show_locate',
						'label'   => __( 'Show "Use my location" link', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'      => 'locate_text',
						'label'     => __( 'Use my location text', 'agend-apps-core' ),
						'type'      => 'text',
						'default'   => __( 'Use my location', 'agend-apps-core' ),
						'condition' => array( 'show_locate' => 'yes' ),
					),
					array(
						'name'    => 'show_icons',
						'label'   => __( 'Show field icons', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'        => 'scroll_to_results',
						'label'       => __( 'Scroll to results after search', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => true,
						'description' => __( 'Only on a page with a Directory Catalogue.', 'agend-apps-core' ),
					),
				),
			),
			array(
				'id'     => 'section_destination',
				'label'  => __( 'Place and destination', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'        => 'location_region',
						'label'       => __( 'Place search region', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => '',
						'description' => __( 'Added to a typed place before it is looked up, for example a country name, so a suburb that exists in several countries finds the right one.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'radius',
						'label'       => __( 'Search radius (km)', 'agend-apps-core' ),
						'type'        => 'number',
						'default'     => 15,
						'min'         => 1,
						'max'         => 1000,
						'description' => __( 'How far from the place results can be. A catalogue that already holds a place keeps its own radius.', 'agend-apps-core' ),
					),
					array(
						'name'          => 'target_url',
						'label'         => __( 'Directory page', 'agend-apps-core' ),
						'type'          => 'url',
						'placeholder'   => __( 'Uses the Directory page set in Agend Apps settings', 'agend-apps-core' ),
						'show_external' => false,
						'description'   => __( 'Where the search goes on a page with no Directory Catalogue. Leave empty to use the Directory page set in Agend Apps settings. That page\'s catalogue reads the search when its filter template has a matching Search or Location filter.', 'agend-apps-core' ),
					),
				),
			),
		),
	);
}
