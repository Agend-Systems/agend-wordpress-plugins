<?php
/**
 * Content-settings schema for the resource search surface.
 *
 * One declaration for both editors: the Elementor widget and the block render
 * their controls from it (see schema.php for the vocabulary).
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Most suggested searches the surface shows under the box.
 *
 * @var int
 */
const AGEND_APPS_RECORDS_RESOURCES_SEARCH_SUGGESTIONS_MAX = 6;

/**
 * Most results the surface shows a member while they type.
 *
 * @var int
 */
const AGEND_APPS_RECORDS_RESOURCES_SEARCH_RESULTS_MAX = 10;

/**
 * The resource search surface's content-settings schema.
 *
 * Setting names are persisted in every saved page that uses the surface:
 * never rename one once shipped.
 *
 * @return array
 */
function agend_apps_records_schema_resources_search(): array {
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
						'default'   => __( 'Search our resources', 'agend-apps-core' ),
						'condition' => array( 'show_heading' => 'yes' ),
					),
					array(
						'name'      => 'subheading_text',
						'label'     => __( 'Subheading', 'agend-apps-core' ),
						'type'      => 'textarea',
						'default'   => __( 'Find a guide, policy or how-to by typing what you need.', 'agend-apps-core' ),
						'condition' => array( 'show_heading' => 'yes' ),
					),
				),
			),
			array(
				'id'     => 'section_box',
				'label'  => __( 'Search Box', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'        => 'placeholder',
						'label'       => __( 'Placeholder', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => __( 'Search guides, policies and how-tos', 'agend-apps-core' ),
						'label_block' => true,
					),
					array(
						'name'    => 'button_label',
						'label'   => __( 'Button label', 'agend-apps-core' ),
						'type'    => 'text',
						'default' => __( 'Search', 'agend-apps-core' ),
					),
					array(
						'name'    => 'show_suggestions',
						'label'   => __( 'Show suggested searches', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => false,
					),
					array(
						'name'        => 'suggestions',
						'label'       => __( 'Suggested searches', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => '',
						'label_block' => true,
						'placeholder' => __( 'Code of conduct, Complaints, Membership fees', 'agend-apps-core' ),
						/* translators: %d: the most suggestions shown. */
						'description' => sprintf( __( 'Separate them with commas. Up to %d are shown under the box.', 'agend-apps-core' ), AGEND_APPS_RECORDS_RESOURCES_SEARCH_SUGGESTIONS_MAX ),
						'condition'   => array( 'show_suggestions' => 'yes' ),
					),
				),
			),
			array(
				'id'     => 'section_results',
				'label'  => __( 'Results for Members', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'        => 'max_results',
						'label'       => __( 'Number of results', 'agend-apps-core' ),
						'type'        => 'number',
						'default'     => 6,
						'min'         => 1,
						'max'         => AGEND_APPS_RECORDS_RESOURCES_SEARCH_RESULTS_MAX,
						/* translators: %d: the most results shown. */
						'description' => sprintf( __( 'Signed-in members see results as they type. Shows up to %d.', 'agend-apps-core' ), AGEND_APPS_RECORDS_RESOURCES_SEARCH_RESULTS_MAX ),
					),
					array(
						'name'    => 'show_excerpt',
						'label'   => __( 'Show a short extract with each result', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'    => 'see_all_label',
						'label'   => __( 'Link to all results', 'agend-apps-core' ),
						'type'    => 'text',
						'default' => __( 'See all results', 'agend-apps-core' ),
					),
					array(
						'name'    => 'show_members_note',
						'label'   => __( 'Show a note about members', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'        => 'members_note',
						'label'       => __( 'Note text', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => __( 'Members see results from the whole library as they type.', 'agend-apps-core' ),
						'label_block' => true,
						'description' => __( 'Shown to visitors who are not signed in. Members do not see it.', 'agend-apps-core' ),
						'condition'   => array( 'show_members_note' => 'yes' ),
					),
				),
			),
			array(
				'id'     => 'section_visitors',
				'label'  => __( 'Visitors', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'        => 'visitor_mode',
						'label'       => __( 'What visitors get', 'agend-apps-core' ),
						'type'        => 'select',
						'options'     => array(
							'link_out' => __( 'A search that opens your public search page', 'agend-apps-core' ),
							'live'     => __( 'Results as they type', 'agend-apps-core' ),
						),
						'default'     => 'link_out',
						'description' => __( 'Opening your public search page is free. Results as they type use your association\'s AI search allowance every time someone types, so turn it on only if you are happy to pay for that.', 'agend-apps-core' ),
					),
				),
			),
			array(
				'id'     => 'section_states',
				'label'  => __( 'Messages', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'        => 'no_results_text',
						'label'       => __( 'When nothing matches', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => __( 'Nothing matched your search. Try different words.', 'agend-apps-core' ),
						'label_block' => true,
					),
					array(
						'name'        => 'error_text',
						'label'       => __( 'When the search is not working', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => __( 'Search is not available right now. Please try again in a moment.', 'agend-apps-core' ),
						'label_block' => true,
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
						'description' => __( 'Used for the focus outline and to highlight the words that matched.', 'agend-apps-core' ),
						'condition'   => array( 'inherit_colours!' => 'yes' ),
					),
					array(
						'name'        => 'card_radius',
						'label'       => __( 'Corner Radius (px)', 'agend-apps-core' ),
						'type'        => 'number',
						'default'     => 10,
						'min'         => 0,
						'max'         => 48,
						'description' => __( 'For the search box and the results.', 'agend-apps-core' ),
					),
				),
			),
		),
	);
}
