<?php
/**
 * Content-settings schema for the Agend Map (directory map) surface.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Map height choices, in pixels.
 *
 * @return array<string, string>
 */
function agend_apps_records_directory_map_height_options(): array {
	$options = array();
	foreach ( array( 280, 320, 360, 420, 480, 560, 640, 720, 800 ) as $height ) {
		$options[ (string) $height ] = $height . 'px';
	}
	return $options;
}

/**
 * The Agend Map widget's content-settings schema.
 *
 * @return array
 */
function agend_apps_records_schema_directory_map(): array {
	return array(
		'sections' => array(
			array(
				'id'     => 'section_map',
				'label'  => __( 'Map', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'    => 'map_note',
						'type'    => 'note',
						'content' => __( 'Pins every listing the Directory Catalogue on this page is showing, and follows its filters. With no catalogue on the page it pins every listing that has a map location.', 'agend-apps-core' ),
					),
					array(
						'name'    => 'height',
						'label'   => __( 'Height', 'agend-apps-core' ),
						'type'    => 'select',
						'default' => '480',
						'options' => 'agend_apps_records_directory_map_height_options',
					),
					array(
						'name'    => 'height_mobile',
						'label'   => __( 'Height on phones', 'agend-apps-core' ),
						'type'    => 'select',
						'default' => '360',
						'options' => 'agend_apps_records_directory_map_height_options',
					),
					array(
						'name'        => 'follow_view',
						'label'       => __( 'Show only in Map view', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => false,
						'description' => __( 'Hides the map until a visitor picks Map on the catalogue\'s List / Map switch, and hides the results list while it shows. Place the map just below the catalogue. Leave off to show the map beside or below the list all the time.', 'agend-apps-core' ),
					),
					array(
						'name'    => 'cluster',
						'label'   => __( 'Group nearby pins', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'        => 'update_on_move',
						'label'       => __( 'Update results as the map moves', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => false,
						'description' => __( 'Dragging or zooming the map narrows the catalogue to the area shown. Not applied during a location search, which already sets the area.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'scroll_zoom',
						'label'       => __( 'Zoom with the mouse wheel', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => false,
						'description' => __( 'Off by default so scrolling the page never gets caught by the map. The zoom buttons always work.', 'agend-apps-core' ),
					),
					array(
						'name'    => 'show_radius',
						'label'   => __( 'Show the search radius', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'        => 'marker_limit',
						'label'       => __( 'Most pins', 'agend-apps-core' ),
						'type'        => 'select',
						'default'     => '500',
						'options'     => array(
							'250'  => '250',
							'500'  => '500',
							'1000' => '1000',
							'2000' => '2000',
						),
						'description' => __( 'When more listings match, the map says so and asks the visitor to zoom in or filter.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'empty_text',
						'label'       => __( 'No results text', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => '',
						'placeholder' => __( 'No listings with a map location match these filters.', 'agend-apps-core' ),
					),
				),
			),
			array(
				'id'     => 'section_popup',
				'label'  => __( 'Pin details', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'    => 'popup_logo',
						'label'   => __( 'Show logo', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'    => 'popup_category',
						'label'   => __( 'Show category', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'    => 'popup_address',
						'label'   => __( 'Show address', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'    => 'popup_distance',
						'label'   => __( 'Show distance', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'    => 'popup_rating',
						'label'   => __( 'Show rating', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => false,
					),
					array(
						'name'        => 'popup_link_text',
						'label'       => __( 'Link text', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => '',
						'placeholder' => __( 'View details', 'agend-apps-core' ),
					),
				),
			),
			array(
				'id'     => 'section_style_map',
				'label'  => __( 'Map', 'agend-apps-core' ),
				'tab'    => 'style',
				'fields' => array(
					array(
						'name'    => 'style_note',
						'type'    => 'note',
						'content' => __( 'Anything left empty uses the catalogue\'s accent colour, or the map\'s own default.', 'agend-apps-core' ),
					),
					array(
						'name'    => 'pin_colour',
						'label'   => __( 'Pin colour', 'agend-apps-core' ),
						'type'    => 'colour',
						'default' => '',
					),
					array(
						'name'    => 'cluster_colour',
						'label'   => __( 'Group colour', 'agend-apps-core' ),
						'type'    => 'colour',
						'default' => '',
					),
					array(
						'name'    => 'cluster_text_colour',
						'label'   => __( 'Group number colour', 'agend-apps-core' ),
						'type'    => 'colour',
						'default' => '',
					),
					array(
						'name'    => 'radius_colour',
						'label'   => __( 'Search radius colour', 'agend-apps-core' ),
						'type'    => 'colour',
						'default' => '',
					),
					array(
						'name'    => 'link_colour',
						'label'   => __( 'Pin details link colour', 'agend-apps-core' ),
						'type'    => 'colour',
						'default' => '',
					),
					array(
						'name'    => 'border_colour',
						'label'   => __( 'Border colour', 'agend-apps-core' ),
						'type'    => 'colour',
						'default' => '',
					),
					array(
						'name'    => 'border_radius',
						'label'   => __( 'Corner radius', 'agend-apps-core' ),
						'type'    => 'select',
						'default' => '',
						'options' => 'agend_apps_records_filter_radius_options',
					),
				),
			),
		),
	);
}
