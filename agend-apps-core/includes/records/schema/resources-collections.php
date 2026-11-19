<?php
/**
 * Content-settings schema for the resource collections surface.
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
 * The most collections the surface will show. Declared here, with the setting
 * that exposes it; the renderer holds a chosen list to the same limit.
 *
 * @var int
 */
const AGEND_APPS_RECORDS_RESOURCES_COLLECTIONS_MAX = 24;

/**
 * The resource collections surface's content-settings schema.
 *
 * Setting names are persisted in every saved page that uses the surface:
 * never rename one once shipped.
 *
 * @return array
 */
function agend_apps_records_schema_resources_collections(): array {
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
						'default'   => __( 'Resource collections', 'agend-apps-core' ),
						'condition' => array( 'show_heading' => 'yes' ),
					),
					array(
						'name'      => 'subheading_text',
						'label'     => __( 'Subheading', 'agend-apps-core' ),
						'type'      => 'textarea',
						'default'   => __( 'Guides, policies and how-tos, grouped by what you need them for.', 'agend-apps-core' ),
						'condition' => array( 'show_heading' => 'yes' ),
					),
				),
			),
			array(
				'id'     => 'section_collections',
				'label'  => __( 'Collections', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'        => 'collection_ids',
						'label'       => __( 'Collections to show', 'agend-apps-core' ),
						'type'        => 'multiselect',
						'label_block' => true,
						'options'     => 'agend_apps_records_resources_collection_options',
						'description' => __( 'Shown in the order you choose them. Everyone who visits the page can read the title, description and topics of each collection you choose, so choose only collections you are happy to show publicly. Nothing is shown until you choose at least one.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'max_collections',
						'label'       => __( 'Number of collections', 'agend-apps-core' ),
						'type'        => 'number',
						'default'     => 6,
						'min'         => 1,
						'max'         => AGEND_APPS_RECORDS_RESOURCES_COLLECTIONS_MAX,
						/* translators: %d: the most collections the widget will show. */
						'description' => sprintf( __( 'The most collections shown. Up to %d.', 'agend-apps-core' ), AGEND_APPS_RECORDS_RESOURCES_COLLECTIONS_MAX ),
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
							'4' => '4',
						),
						'default'     => '3',
						'description' => __( 'The most columns across. Narrow areas use fewer columns.', 'agend-apps-core' ),
					),
					array(
						'name'    => 'columns_tablet',
						'label'   => __( 'Columns (Tablet)', 'agend-apps-core' ),
						'type'    => 'select',
						'options' => array(
							'1' => '1',
							'2' => '2',
							'3' => '3',
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
				'id'     => 'section_card_fields',
				'label'  => __( 'Collection Card', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'    => 'show_description',
						'label'   => __( 'Show description', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'      => 'excerpt_length',
						'label'     => __( 'Description length (characters)', 'agend-apps-core' ),
						'type'      => 'number',
						'default'   => 140,
						'min'       => 20,
						'max'       => 400,
						'condition' => array( 'show_description' => 'yes' ),
					),
					array(
						'name'    => 'show_topics',
						'label'   => __( 'Show topics', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'        => 'show_members_only',
						'label'       => __( 'Show a Members only label', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => true,
						'description' => __( 'A small lock label at the bottom of each card. Collections can only be opened by signed-in members, so this tells visitors before they try.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'show_page_count',
						'label'       => __( 'Show the number of pages', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => false,
						'description' => __( 'Says how many pages each collection holds. Anyone who visits your website can read it, so leave it off if you would rather not show how much a members-only collection contains.', 'agend-apps-core' ),
					),
				),
			),
			array(
				'id'     => 'section_visitors',
				'label'  => __( 'Visitors', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'        => 'visitor_note',
						'label'       => __( 'Note under the collections', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => __( 'Members can open these collections after signing in.', 'agend-apps-core' ),
						'label_block' => true,
						'description' => __( 'Collections have no page of their own to link to, so the cards are not links. Members do not see this note.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'visitor_cta_label',
						'label'       => __( 'Button label', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => __( 'Become a member', 'agend-apps-core' ),
						'description' => __( 'Shown beside the note when you add a button link below.', 'agend-apps-core' ),
					),
					array(
						'name'          => 'visitor_cta_url',
						'label'         => __( 'Button link', 'agend-apps-core' ),
						'type'          => 'url',
						'placeholder'   => 'https://',
						'show_external' => false,
						'description'   => __( 'Where the button goes, such as your join or sign-in page. Leave empty for a note with no button.', 'agend-apps-core' ),
					),
				),
			),
			array(
				'id'     => 'section_members',
				'label'  => __( 'Signed-in Members', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'        => 'show_member_collections',
						'label'       => __( 'Show members their own collections', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => true,
						'description' => __( 'A signed-in member sees the collections they can open, with the pages inside each one. They appear just after the page loads, so the page itself is the same for everyone.', 'agend-apps-core' ),
					),
					array(
						'name'      => 'member_heading',
						'label'     => __( 'Heading for their collections', 'agend-apps-core' ),
						'type'      => 'text',
						'default'   => __( 'Your collections', 'agend-apps-core' ),
						'condition' => array( 'show_member_collections' => 'yes' ),
					),
					array(
						'name'      => 'member_view',
						'label'     => __( 'Where their collections go', 'agend-apps-core' ),
						'type'      => 'select',
						'options'   => array(
							'additional' => __( 'Below the collections you chose', 'agend-apps-core' ),
							'replace'    => __( 'Instead of the collections you chose', 'agend-apps-core' ),
						),
						'default'   => 'additional',
						'condition' => array( 'show_member_collections' => 'yes' ),
					),
					array(
						'name'        => 'member_pages_shown',
						'label'       => __( 'Pages listed in each collection', 'agend-apps-core' ),
						'type'        => 'number',
						'default'     => 4,
						'min'         => 0,
						'max'         => 12,
						'description' => __( 'Set to 0 to show only the collection names.', 'agend-apps-core' ),
						'condition'   => array( 'show_member_collections' => 'yes' ),
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
						'description' => __( 'Used for the icon on each card and the focus outline.', 'agend-apps-core' ),
						'condition'   => array( 'inherit_colours!' => 'yes' ),
					),
				),
			),
		),
	);
}

/**
 * Collection options for the "Collections to show" picker.
 *
 * The full list, from the same cached read the page makes for the chosen ones.
 * It is loaded here, for the editor, and never printed on a page: the list
 * carries no visibility, so it holds collections that are not meant for
 * visitors. Empty outside an editor
 * {@see agend_apps_records_is_editor_request()}, and while the surface is gated.
 *
 * @return array Options keyed by collection id.
 */
function agend_apps_records_resources_collection_options(): array {
	if ( ! function_exists( 'agend_apps_atlas_get_collections' ) || ! agend_apps_records_is_editor_request() || agend_apps_records_surface_gated( 'resources-collections' ) ) {
		return array();
	}

	$response = agend_apps_atlas_get_collections( array( 'limit' => 100 ) );

	if ( is_wp_error( $response ) || empty( $response['data'] ) || ! is_array( $response['data'] ) ) {
		return array();
	}

	$options = array();

	foreach ( $response['data'] as $collection ) {
		if ( ! is_array( $collection ) || empty( $collection['id'] ) ) {
			continue;
		}

		$options[ (string) $collection['id'] ] = '' !== trim( (string) ( $collection['title'] ?? '' ) ) ? (string) $collection['title'] : (string) $collection['id'];
	}

	return $options;
}
