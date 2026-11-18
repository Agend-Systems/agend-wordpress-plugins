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
						'label'   => __( 'Show heading', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'      => 'heading_text',
						'label'     => __( 'Heading', 'agend-apps-core' ),
						'type'      => 'text',
						'default'   => __( 'Learning pathways', 'agend-apps-core' ),
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
				'label'  => __( 'Learning Pathways', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'    => 'path_source',
						'label'   => __( 'Which pathways', 'agend-apps-core' ),
						'type'    => 'select',
						'options' => array(
							'all'      => __( 'All published pathways', 'agend-apps-core' ),
							'selected' => __( 'Only the pathways I choose', 'agend-apps-core' ),
						),
						'default' => 'all',
					),
					// The cap is the bound on the page's gateway calls: each path adds one
					// read for its course list. Editors are told the number, not the reason.
					array(
						'name'        => 'max_paths',
						'label'       => __( 'Number of pathways', 'agend-apps-core' ),
						'type'        => 'number',
						'default'     => 6,
						'min'         => 1,
						'max'         => AGEND_APPS_RECORDS_LEARNING_PATHS_MAX,
						/* translators: %d: the most pathways the widget will show. */
						'description' => sprintf( __( 'Newest first. Shows up to %d.', 'agend-apps-core' ), AGEND_APPS_RECORDS_LEARNING_PATHS_MAX ),
						'condition'   => array( 'path_source' => 'all' ),
					),
					array(
						'name'        => 'selected_paths',
						'label'       => __( 'Pathways to show', 'agend-apps-core' ),
						'type'        => 'multiselect',
						'label_block' => true,
						'options'     => 'agend_apps_records_learning_paths_options',
						'description' => __( 'Shown in the order you choose them. A pathway that has been unpublished or deleted is left out.', 'agend-apps-core' ),
						'condition'   => array( 'path_source' => 'selected' ),
					),
				),
			),
			array(
				'id'     => 'section_layout',
				'label'  => __( 'Layout', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'        => 'columns_desktop',
						'label'       => __( 'Columns (Desktop)', 'agend-apps-core' ),
						'type'        => 'select',
						'options'     => array(
							'1' => '1',
							'2' => '2',
							'3' => '3',
						),
						'default'     => '2',
						'description' => __( 'The most columns across. A single pathway, or fewer pathways than columns, fills the full width. Narrow areas use fewer columns.', 'agend-apps-core' ),
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
						'name'        => 'card_layout',
						'label'       => __( 'Card layout', 'agend-apps-core' ),
						'type'        => 'select',
						'options'     => array(
							'auto'    => __( 'Automatic', 'agend-apps-core' ),
							'stacked' => __( 'Always stacked', 'agend-apps-core' ),
						),
						'default'     => 'auto',
						'description' => __( 'Automatic puts a pathway\'s summary beside its courses when the card is wide, such as a single pathway across the full width. Always stacked keeps the summary above the courses.', 'agend-apps-core' ),
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
				'label'  => __( 'Pathway Card', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'    => 'show_image',
						'label'   => __( 'Show pathway image', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'    => 'show_difficulty',
						'label'   => __( 'Show difficulty level', 'agend-apps-core' ),
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
						'description' => __( 'Shown on pathways that ask learners to take the courses in sequence.', 'agend-apps-core' ),
					),
					// Off also drops the per-path course read, one gateway call per path.
					array(
						'name'        => 'show_courses',
						'label'       => __( 'Show the course list', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => true,
						'description' => __( 'The pathway\'s courses, numbered in order. Off: each card shows only the pathway\'s details, without its courses.', 'agend-apps-core' ),
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
						'description' => __( 'Links go to the Courses page chosen under Settings > Agend Widgets > Catalogue Pages. If no Courses page is chosen, courses are shown without links.', 'agend-apps-core' ),
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
						'label'       => __( 'Message when there are no pathways', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => __( 'No learning pathways are available yet.', 'agend-apps-core' ),
						'label_block' => true,
					),
					array(
						'name'        => 'hide_when_empty',
						'label'       => __( 'Hide the widget when there are no pathways', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => false,
						'description' => __( 'Visitors then see nothing at all, not even the heading. If the pathways cannot be loaded, a message is still shown.', 'agend-apps-core' ),
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
						'description' => __( 'Used for the step numbers and the small dot beside the difficulty level.', 'agend-apps-core' ),
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
