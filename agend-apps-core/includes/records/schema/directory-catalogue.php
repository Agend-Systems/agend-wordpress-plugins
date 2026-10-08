<?php
/**
 * Content-settings schema for the directory catalogue surface.
 *
 * Transcribed from the Content-tab controls that used to be hand-declared in
 * the directory catalogue widget's register_content_controls(), including
 * the "Visitor Filter Bar" section's HEADING sub-group labels ("Search",
 * "Category", "Rating") as `heading` fields.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The option list below makes no gateway call for a key the surface gate has
// already ruled out.
require_once __DIR__ . '/../surface-gate.php';

/**
 * Filter column width choices: the share of the catalogue's width a side
 * filter column takes, '' leaving the stylesheet's 260px.
 *
 * The keys carry their unit rather than being bare numbers. PHP turns a bare
 * numeric key into an integer, and an editor reading the list back as a
 * JavaScript object puts integer-like keys ahead of the rest, which would
 * send the "Default" entry to the bottom of the dropdown. A saved value is
 * also then the CSS length itself.
 *
 * @return array<string, string>
 */
function agend_apps_records_directory_catalogue_filter_column_options(): array {
	return array(
		''    => __( 'Default (260px)', 'agend-apps-core' ),
		'25%' => '25%',
		'33%' => __( 'One third (33%)', 'agend-apps-core' ),
		'40%' => '40%',
		'50%' => __( 'Half (50%)', 'agend-apps-core' ),
		'60%' => '60%',
		'67%' => __( 'Two thirds (67%)', 'agend-apps-core' ),
		'70%' => '70%',
		'75%' => '75%',
	);
}

/**
 * Results list height choices, in pixels, '' for no limit. Keyed with the
 * unit for the reason given on the filter column choices above.
 *
 * @return array<string, string>
 */
function agend_apps_records_directory_catalogue_results_height_options(): array {
	$options = array( '' => __( 'No limit', 'agend-apps-core' ) );
	foreach ( array( 480, 560, 640, 720, 800, 880, 960, 1040 ) as $height ) {
		$options[ $height . 'px' ] = $height . 'px';
	}
	return $options;
}

/**
 * The directory catalogue's content-settings schema.
 *
 * @return array
 */
function agend_apps_records_schema_directory_catalogue(): array {
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
						'default'   => __( 'Business Directory', 'agend-apps-core' ),
						'condition' => array( 'show_heading' => 'yes' ),
					),
					array(
						'name'      => 'subheading_text',
						'label'     => __( 'Subheading', 'agend-apps-core' ),
						'type'      => 'textarea',
						'default'   => __( 'Find member businesses across our community.', 'agend-apps-core' ),
						'condition' => array( 'show_heading' => 'yes' ),
					),
				),
			),
			array(
				'id'     => 'section_layout',
				'label'  => __( 'Layout', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'    => 'layout_style',
						'label'   => __( 'Layout style', 'agend-apps-core' ),
						'type'    => 'select',
						'options' => array(
							'grid' => __( 'Grid', 'agend-apps-core' ),
							'list' => __( 'List', 'agend-apps-core' ),
						),
						'default' => 'grid',
					),
					array(
						'name'      => 'columns_desktop',
						'label'     => __( 'Columns (Desktop)', 'agend-apps-core' ),
						'type'      => 'select',
						'options'   => array(
							'2' => '2',
							'3' => '3',
							'4' => '4',
						),
						'default'   => '3',
						'condition' => array( 'layout_style' => 'grid' ),
					),
					array(
						'name'      => 'columns_tablet',
						'label'     => __( 'Columns (Tablet)', 'agend-apps-core' ),
						'type'      => 'select',
						'options'   => array(
							'1' => '1',
							'2' => '2',
						),
						'default'   => '2',
						'condition' => array( 'layout_style' => 'grid' ),
					),
					array(
						'name'      => 'columns_mobile',
						'label'     => __( 'Columns (Mobile)', 'agend-apps-core' ),
						'type'      => 'select',
						'options'   => array(
							'1' => '1',
							'2' => '2',
						),
						'default'   => '1',
						'condition' => array( 'layout_style' => 'grid' ),
					),
					array(
						'name'    => 'card_radius',
						'label'   => __( 'Card Corner Radius (px)', 'agend-apps-core' ),
						'type'    => 'number',
						'default' => 10,
						'min'     => 0,
						'max'     => 48,
					),
					array(
						'name'        => 'results_max_height',
						'label'       => __( 'Results list height', 'agend-apps-core' ),
						'type'        => 'select',
						'options'     => 'agend_apps_records_directory_catalogue_results_height_options',
						'default'     => '',
						'description' => __( 'Cap the height of the results list so it scrolls inside itself, for example to sit level with an Agend Map. Applies from tablet width up; on a phone the list runs at full height.', 'agend-apps-core' ),
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
						'description' => __( 'A saved template (Templates > Saved Templates) rendered once per listing. Build it from the Agend Field, Agend Image, Agend Pills and Agend Link widgets. The built-in card options below apply only when no template is chosen.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'card_link_whole',
						'label'       => __( 'Whole card links to the listing', 'agend-apps-core' ),
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
						'default'     => 'top',
						'options'     => array(
							'top'   => __( 'Across the top', 'agend-apps-core' ),
							'left'  => __( 'Down the left', 'agend-apps-core' ),
							'right' => __( 'Down the right', 'agend-apps-core' ),
						),
						'description' => __( 'A side position puts the filters in their own column beside the results. Set the width of that column with Filter column width below.', 'agend-apps-core' ),
						'condition'   => array( 'filter_template!' => '' ),
					),
					array(
						'name'        => 'filter_column_width',
						'label'       => __( 'Filter column width', 'agend-apps-core' ),
						'type'        => 'select',
						'options'     => 'agend_apps_records_directory_catalogue_filter_column_options',
						'default'     => '',
						'description' => __( 'How much of the catalogue\'s width the filter column takes. The results list takes the rest.', 'agend-apps-core' ),
						'condition'   => array(
							'filter_template!' => '',
							'filter_position'  => array( 'left', 'right' ),
						),
					),
				),
			),
			array(
				'id'        => 'section_card_fields',
				'label'     => __( 'Card Fields', 'agend-apps-core' ),
				'condition' => array( 'card_template' => '' ),
				'fields'    => array(
					array(
						'name'    => 'show_logo',
						'label'   => __( 'Show logo', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'    => 'show_rating',
						'label'   => __( 'Show rating summary', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'    => 'show_category',
						'label'   => __( 'Show primary category', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'    => 'show_location',
						'label'   => __( 'Show location (city, state)', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'        => 'show_distance',
						'label'       => __( 'Show distance', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => true,
						'description' => __( 'How far each listing is from the place a visitor searched with a Location filter. Nothing shows until they search.', 'agend-apps-core' ),
					),
					array(
						'name'    => 'show_badges',
						'label'   => __( 'Show badges', 'agend-apps-core' ),
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
				),
			),
			array(
				'id'        => 'section_filters',
				'label'     => __( 'Visitor Filter Bar', 'agend-apps-core' ),
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
						'default'   => 'no',
						'condition' => array( 'show_category_filter' => 'yes' ),
					),
					array(
						'name'      => 'heading_filter_rating',
						'label'     => __( 'Rating', 'agend-apps-core' ),
						'type'      => 'heading',
						'separator' => 'before',
					),
					array(
						'name'    => 'show_rating_filter',
						'label'   => __( 'Show minimum-rating filter', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
				),
			),
			array(
				'id'     => 'section_exclusions',
				'label'  => __( 'Exclusions', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'        => 'featured_only',
						'label'       => __( 'Featured listings only', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => 'no',
						'description' => __( 'Limit this widget to featured listings.', 'agend-apps-core' ),
					),
					array(
						'name'    => 'exclusions_note',
						'type'    => 'note',
						'content' => __( 'Excluded categories never appear in this widget, and are hidden from the visitor category filter.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'exclude_categories',
						'label'       => __( 'Exclude categories', 'agend-apps-core' ),
						'type'        => 'multiselect',
						'label_block' => true,
						'options'     => 'agend_apps_records_directory_catalogue_category_options',
					),
				),
			),
			array(
				'id'     => 'section_detail',
				'label'  => __( 'Detail View', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'        => 'show_reviews',
						'label'       => __( 'Show reviews', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => true,
						'description' => __( 'Show the rating summary and approved reviews on the listing detail view.', 'agend-apps-core' ),
					),
					array(
						'name'             => 'show_review_form',
						'label'            => __( 'Show review submission form', 'agend-apps-core' ),
						'type'             => 'toggle',
						'default'          => true,
						'description'      => __( 'Let visitors submit a review. New reviews are held for moderation before publishing.', 'agend-apps-core' ),
						'condition'        => array( 'show_reviews' => 'yes' ),
						// Needs the directory.reviews.manage scope; an adapter
						// renders a missing-scope notice in place of this
						// control when the connected key does not hold it
						// (SPEC-CORE-20260908 scope-gated features).
						'requires_feature' => 'directory_review_form',
					),
					array(
						'name'        => 'require_login_for_detail',
						'label'       => __( 'Require sign-in to view details', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => false,
						'description' => __( 'Visitors who are not signed in are sent to the sign-in page when they open a listing, or follow a link to one, and return to the listing afterwards. The cards themselves stay visible. Only detected when this catalogue is placed on the page itself, not inside a saved or theme-builder template.', 'agend-apps-core' ),
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
						'label'   => __( 'Listings per page', 'agend-apps-core' ),
						'type'    => 'number',
						'default' => 12,
						'min'     => 1,
						'max'     => 100,
					),
					array(
						'name'        => 'show_result_count',
						'label'       => __( 'Show result count', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => false,
						'description' => __( 'A line above the results saying how many listings match the current filters.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'result_count_text',
						'label'       => __( 'Result count text', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => __( 'Showing {from}–{to} of {total}', 'agend-apps-core' ),
						'label_block' => true,
						'description' => __( '{from}, {to} and {total} are replaced with the numbers, for example "Showing {from}–{to} of {total} members".', 'agend-apps-core' ),
						'condition'   => array( 'show_result_count' => 'yes' ),
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
						'description' => __( 'Drives rating stars, active filter state, and the featured ribbon.', 'agend-apps-core' ),
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
 * Category exclusion options for the directory catalogue.
 *
 * Moved from the widget's category_options() method verbatim.
 *
 * @return array Options keyed by category id.
 */
function agend_apps_records_directory_catalogue_category_options(): array {
	if ( ! function_exists( 'agend_apps_directory_get_categories' ) || agend_apps_records_surface_gated( 'directory-catalogue' ) ) {
		return array();
	}

	$response = agend_apps_directory_get_categories();

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
