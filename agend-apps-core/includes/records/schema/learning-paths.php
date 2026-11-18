<?php
/**
 * Content-settings schema for the learning paths surface.
 *
 * One declaration for both editors: the Elementor widget and the block render
 * their controls from it (see schema.php for the vocabulary).
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
 * The most paths the surface will show. Each path adds a request for its
 * course list, so the number of paths is also the bound on the page's gateway
 * calls. Declared here, with the setting that exposes it; the renderer holds
 * a chosen list to the same limit.
 *
 * @var int
 */
const AGEND_APPS_RECORDS_LEARNING_PATHS_MAX = 24;

/**
 * The learning paths surface's content-settings schema.
 *
 * Setting names are persisted in every saved page that uses the surface:
 * never rename one once shipped.
 *
 * @return array
 */
function agend_apps_records_schema_learning_paths(): array {
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
						'default'   => __( 'Learning paths', 'agend-apps-core' ),
						'condition' => array( 'show_heading' => 'yes' ),
					),
					array(
						'name'      => 'subheading_text',
						'label'     => __( 'Subheading', 'agend-apps-core' ),
						'type'      => 'textarea',
						'default'   => __( 'Guided sequences of courses, in the order we recommend.', 'agend-apps-core' ),
						'condition' => array( 'show_heading' => 'yes' ),
					),
				),
			),
			array(
				'id'     => 'section_paths',
				'label'  => __( 'Learning Paths', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'    => 'path_source',
						'label'   => __( 'Which paths', 'agend-apps-core' ),
						'type'    => 'select',
						'options' => array(
							'all'      => __( 'All published paths', 'agend-apps-core' ),
							'selected' => __( 'Only the paths I choose', 'agend-apps-core' ),
						),
						'default' => 'all',
					),
					array(
						'name'        => 'max_paths',
						'label'       => __( 'Number of paths', 'agend-apps-core' ),
						'type'        => 'number',
						'default'     => 6,
						'min'         => 1,
						'max'         => AGEND_APPS_RECORDS_LEARNING_PATHS_MAX,
						'description' => __( 'Newest first. Each path adds one request to the course list, so the number is capped.', 'agend-apps-core' ),
						'condition'   => array( 'path_source' => 'all' ),
					),
					array(
						'name'        => 'selected_paths',
						'label'       => __( 'Paths to show', 'agend-apps-core' ),
						'type'        => 'multiselect',
						'label_block' => true,
						'options'     => 'agend_apps_records_learning_paths_options',
						'description' => __( 'Shown in the order you choose them. A path that has since been unpublished or deleted is left out.', 'agend-apps-core' ),
						'condition'   => array( 'path_source' => 'selected' ),
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
							'1' => '1',
							'2' => '2',
							'3' => '3',
						),
						'default' => '2',
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
				'id'     => 'section_card_fields',
				'label'  => __( 'Path Card', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'    => 'show_image',
						'label'   => __( 'Show path image', 'agend-apps-core' ),
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
						'name'    => 'show_duration',
						'label'   => __( 'Show estimated hours', 'agend-apps-core' ),
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
						'default'   => 160,
						'min'       => 20,
						'max'       => 400,
						'condition' => array( 'show_description' => 'yes' ),
					),
					array(
						'name'        => 'show_sequence',
						'label'       => __( 'Show "complete in order" note', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => true,
						'description' => __( 'Shown on paths that ask learners to take the courses in sequence.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'show_courses',
						'label'       => __( 'Show the course list', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => true,
						'description' => __( 'The path\'s courses, numbered in order. Off: the card shows the path alone and the page makes one request fewer per path.', 'agend-apps-core' ),
					),
				),
			),
			array(
				'id'        => 'section_courses',
				'label'     => __( 'Course List', 'agend-apps-core' ),
				'condition' => array( 'show_courses' => 'yes' ),
				'fields'    => array(
					array(
						'name'        => 'course_links',
						'label'       => __( 'Course links', 'agend-apps-core' ),
						'type'        => 'select',
						'options'     => array(
							'detail' => __( 'Link to the course page', 'agend-apps-core' ),
							'none'   => __( 'No links', 'agend-apps-core' ),
						),
						'default'     => 'detail',
						'description' => __( 'The course page is on the Courses page set under Settings > Agend Widgets > Catalogue Pages. With none set, courses are listed without links.', 'agend-apps-core' ),
					),
					array(
						'name'      => 'links_new_tab',
						'label'     => __( 'Open course links in a new tab', 'agend-apps-core' ),
						'type'      => 'toggle',
						'default'   => false,
						'condition' => array( 'course_links' => 'detail' ),
					),
				),
			),
			array(
				'id'     => 'section_empty',
				'label'  => __( 'Empty State', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'        => 'empty_message',
						'label'       => __( 'Message when there are no paths', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => __( 'No learning paths are available yet.', 'agend-apps-core' ),
						'label_block' => true,
					),
					array(
						'name'        => 'hide_when_empty',
						'label'       => __( 'Hide the widget when there are no paths', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => false,
						'description' => __( 'Visitors then see nothing, not even the heading. A failed load still shows its message.', 'agend-apps-core' ),
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
						'description' => __( 'Drives the difficulty badge, the course numbers and the underline on course links.', 'agend-apps-core' ),
						'condition'   => array( 'inherit_colours!' => 'yes' ),
					),
				),
			),
		),
	);
}

/**
 * Path options for the "Paths to show" picker.
 *
 * Paths have no categories or other index, so the list is the first hundred
 * published paths, from the same cached read the widget makes. Empty outside
 * an editor {@see agend_apps_records_is_editor_request()}, and while the surface
 * is gated.
 *
 * @return array Options keyed by path id.
 */
function agend_apps_records_learning_paths_options(): array {
	if ( ! function_exists( 'agend_apps_lms_get_paths' ) || ! agend_apps_records_is_editor_request() || agend_apps_records_surface_gated( 'learning-paths' ) ) {
		return array();
	}

	$response = agend_apps_lms_get_paths( array( 'limit' => 100 ), true );

	if ( is_wp_error( $response ) || empty( $response['data'] ) || ! is_array( $response['data'] ) ) {
		return array();
	}

	$options = array();

	foreach ( $response['data'] as $path ) {
		if ( ! is_array( $path ) || empty( $path['id'] ) || ( isset( $path['published'] ) && ! $path['published'] ) ) {
			continue;
		}

		$options[ (string) $path['id'] ] = '' !== trim( (string) ( $path['title'] ?? '' ) ) ? (string) $path['title'] : (string) $path['id'];
	}

	return $options;
}
