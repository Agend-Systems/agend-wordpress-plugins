<?php
/**
 * Content-settings schema for the courses catalogue surface.
 *
 * Transcribed from the Content-tab controls that used to be hand-declared in
 * the courses catalogue widget's register_content_controls().
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The courses catalogue's content-settings schema.
 *
 * @return array
 */
function agend_apps_records_schema_courses_catalogue(): array {
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
						'default'   => __( 'Learning Hub', 'agend-apps-core' ),
						'condition' => array( 'show_heading' => 'yes' ),
					),
					array(
						'name'      => 'subheading_text',
						'label'     => __( 'Subheading', 'agend-apps-core' ),
						'type'      => 'textarea',
						'default'   => __( 'Self-paced courses and live workshops to level up your skills.', 'agend-apps-core' ),
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
						'description' => __( 'A saved template (Templates > Saved Templates) rendered once per course. Build it from the Agend Field, Agend Image and Agend Link widgets. The built-in card options below apply only when no template is chosen.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'card_link_whole',
						'label'       => __( 'Whole card links to the course', 'agend-apps-core' ),
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
						'label'   => __( 'Show course image', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'    => 'show_difficulty',
						'label'   => __( 'Show difficulty badge', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'    => 'show_delivery_mode',
						'label'   => __( 'Show delivery mode pill', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'    => 'show_category',
						'label'   => __( 'Show category label', 'agend-apps-core' ),
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
						'name'    => 'show_meta',
						'label'   => __( 'Show duration and module count', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'    => 'show_price',
						'label'   => __( 'Show price tag', 'agend-apps-core' ),
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
						'name'    => 'show_search',
						'label'   => __( 'Show search box', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'    => 'show_category_filter',
						'label'   => __( 'Show category filter', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'    => 'show_difficulty_filter',
						'label'   => __( 'Show difficulty filter', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'    => 'show_delivery_filter',
						'label'   => __( 'Show delivery mode filter', 'agend-apps-core' ),
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
						'name'    => 'exclusions_note',
						'type'    => 'note',
						'content' => __( 'Excluded items never appear in this widget, and excluded values are hidden from the visitor filters.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'exclude_categories',
						'label'       => __( 'Exclude categories', 'agend-apps-core' ),
						'type'        => 'multiselect',
						'label_block' => true,
						'options'     => 'agend_apps_records_courses_catalogue_category_options',
					),
					array(
						'name'        => 'exclude_difficulties',
						'label'       => __( 'Exclude difficulty levels', 'agend-apps-core' ),
						'type'        => 'multiselect',
						'label_block' => true,
						'options'     => array(
							'beginner'     => __( 'Beginner', 'agend-apps-core' ),
							'intermediate' => __( 'Intermediate', 'agend-apps-core' ),
							'advanced'     => __( 'Advanced', 'agend-apps-core' ),
						),
					),
					array(
						'name'        => 'exclude_delivery_modes',
						'label'       => __( 'Exclude delivery modes', 'agend-apps-core' ),
						'type'        => 'multiselect',
						'label_block' => true,
						'options'     => array(
							'self_paced'  => __( 'Self-paced', 'agend-apps-core' ),
							'live_online' => __( 'Live Online', 'agend-apps-core' ),
							'in_person'   => __( 'In-Person', 'agend-apps-core' ),
							'blended'     => __( 'Blended', 'agend-apps-core' ),
						),
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
						'label'   => __( 'Courses per page', 'agend-apps-core' ),
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
						'description' => __( 'Drives the difficulty badge, free price text, and active filter state.', 'agend-apps-core' ),
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
 * Category exclusion options for the courses catalogue.
 *
 * Course categories are free text on the course row (no categories
 * endpoint), so the distinct set is derived from a wide, cached list fetch.
 * Moved from the widget's category_options() method verbatim.
 *
 * @return array Options keyed by category name.
 */
function agend_apps_records_courses_catalogue_category_options(): array {
	if ( ! function_exists( 'agend_apps_lms_get_courses' ) ) {
		return array();
	}

	$response = agend_apps_lms_get_courses( array( 'limit' => 100 ) );

	if ( is_wp_error( $response ) || empty( $response['data'] ) || ! is_array( $response['data'] ) ) {
		return array();
	}

	$options = array();

	foreach ( $response['data'] as $course ) {
		if ( ! empty( $course['category'] ) ) {
			$options[ (string) $course['category'] ] = (string) $course['category'];
		}
	}

	return $options;
}
