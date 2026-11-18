<?php
/**
 * Content-settings schema for the Agend Filter Shortcuts surface.
 *
 * A grid of tiles that each apply one value of one directory filter.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The filters a shortcut tile can apply, keyed by the registry key of the
 * directory filter it writes.
 *
 * @return array<string, string>
 */
function agend_apps_records_filter_shortcuts_filter_options(): array {
	return array(
		'custom_field' => __( 'Custom field', 'agend-apps-core' ),
		'category'     => __( 'Category', 'agend-apps-core' ),
		'state'        => __( 'State', 'agend-apps-core' ),
		'city'         => __( 'City', 'agend-apps-core' ),
	);
}

/**
 * The Agend Filter Shortcuts widget's content-settings schema.
 *
 * The manual `items` list is an adapter control: each editor builds its own
 * repeater, because a row can carry an icon, which is an editor-specific
 * picker. Rows reach the renderer as `array( label, value, icon_html )`.
 *
 * @return array
 */
function agend_apps_records_schema_filter_shortcuts(): array {
	return array(
		'sections' => array(
			array(
				'id'     => 'section_content',
				'label'  => __( 'Shortcuts', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'        => 'filter',
						'label'       => __( 'Filter', 'agend-apps-core' ),
						'type'        => 'select',
						'default'     => 'custom_field',
						'options'     => 'agend_apps_records_filter_shortcuts_filter_options',
						'description' => __( 'Each tile applies one value of this directory filter. On a page with a Directory Catalogue the tile applies it in place and shows the filter control updated; the catalogue\'s filter template does not need to include the filter.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'custom_field_key',
						'label'       => __( 'Custom field key', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => '',
						'description' => __( 'The key as configured in Agend, for example profession.', 'agend-apps-core' ),
						'condition'   => array( 'filter' => 'custom_field' ),
					),
					array(
						'name'    => 'source',
						'label'   => __( 'Tiles come from', 'agend-apps-core' ),
						'type'    => 'select',
						'default' => 'manual',
						'options' => array(
							'manual' => __( 'A list I write', 'agend-apps-core' ),
							'all'    => __( 'All values from the directory', 'agend-apps-core' ),
						),
					),
					array(
						'name'        => 'items',
						'label'       => __( 'Tiles', 'agend-apps-core' ),
						'type'        => 'adapter',
						'description' => __( 'Label shown, and the value applied: the exact value for a custom field or state, the category id for a category.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'top_level_only',
						'label'       => __( 'Top-level categories only', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => true,
						'description' => __( 'Skips subcategories.', 'agend-apps-core' ),
						'condition'   => array(
							'filter' => 'category',
							'source' => 'all',
						),
					),
					array(
						'name'    => 'columns',
						'label'   => __( 'Columns', 'agend-apps-core' ),
						'type'    => 'number',
						'default' => 4,
						'min'     => 1,
						'max'     => 8,
					),
					array(
						'name'    => 'columns_tablet',
						'label'   => __( 'Columns on tablets', 'agend-apps-core' ),
						'type'    => 'number',
						'default' => 2,
						'min'     => 1,
						'max'     => 8,
					),
					array(
						'name'    => 'columns_mobile',
						'label'   => __( 'Columns on phones', 'agend-apps-core' ),
						'type'    => 'number',
						'default' => 2,
						'min'     => 1,
						'max'     => 4,
					),
					array(
						'name'    => 'show_icons',
						'label'   => __( 'Show icons', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'        => 'all_icon',
						'label'       => __( 'Icon for every tile', 'agend-apps-core' ),
						'type'        => 'adapter',
						'description' => __( 'One icon shared by all tiles when they come from the directory.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'scroll_to_results',
						'label'       => __( 'Scroll to results after a tile is chosen', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => true,
						'description' => __( 'Only on a page with a Directory Catalogue.', 'agend-apps-core' ),
					),
					array(
						'name'          => 'target_url',
						'label'         => __( 'Directory page', 'agend-apps-core' ),
						'type'          => 'url',
						'default'       => '',
						'placeholder'   => __( 'Uses the Directory page set in Agend Apps settings', 'agend-apps-core' ),
						'show_external' => false,
						'description'   => __( 'Where a tile goes on a page with no Directory Catalogue, and what its link points to. Leave empty to use the Directory page set in Agend Apps settings.', 'agend-apps-core' ),
					),
				),
			),
		),
	);
}
