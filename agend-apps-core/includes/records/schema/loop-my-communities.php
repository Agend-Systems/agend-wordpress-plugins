<?php
/**
 * Content-settings schema for the My Communities surface.
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
 * The My Communities surface's content-settings schema.
 *
 * Setting names are persisted in every saved page that uses the surface: never
 * rename one once shipped.
 *
 * @return array
 */
function agend_apps_records_schema_loop_my_communities(): array {
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
						'default'   => __( 'My communities', 'agend-apps-core' ),
						'condition' => array( 'show_heading' => 'yes' ),
					),
					array(
						'name'        => 'show_description',
						'label'       => __( 'Show each community\'s description', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => true,
					),
				),
			),
			array(
				'id'     => 'section_labels',
				'label'  => __( 'Words', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'    => 'note_labels',
						'label'   => __( 'About this widget', 'agend-apps-core' ),
						'type'    => 'note',
						'content' => __( 'Members see the communities they belong to here, each with a button that signs them in and opens it. Place it on a page members sign in to.', 'agend-apps-core' ),
					),
					array(
						'name'    => 'button_label',
						'label'   => __( 'Button', 'agend-apps-core' ),
						'type'    => 'text',
						'default' => __( 'Access community', 'agend-apps-core' ),
					),
					array(
						'name'        => 'empty_message',
						'label'       => __( 'When the member is in no communities', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => __( 'You are not in any communities yet.', 'agend-apps-core' ),
						'label_block' => true,
					),
					array(
						'name'        => 'unavailable_message',
						'label'       => __( 'When communities cannot be loaded', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => __( 'Your communities are not available right now. Please try again later.', 'agend-apps-core' ),
						'label_block' => true,
					),
				),
			),
			array(
				'id'     => 'section_signed_out',
				'label'  => __( 'Signed out', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'        => 'signed_out_message',
						'label'       => __( 'Message for visitors who are not signed in', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => __( 'Sign in to see the communities you belong to.', 'agend-apps-core' ),
						'label_block' => true,
					),
					array(
						'name'    => 'sign_in_label',
						'label'   => __( 'Sign in button', 'agend-apps-core' ),
						'type'    => 'text',
						'default' => __( 'Sign in', 'agend-apps-core' ),
					),
					array(
						'name'        => 'hide_when_signed_out',
						'label'       => __( 'Hide the widget when no one is signed in', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => false,
						'description' => __( 'Visitors who are not signed in then see nothing at all, not even the heading.', 'agend-apps-core' ),
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
						'name'      => 'accent_colour',
						'label'     => __( 'Highlight colour', 'agend-apps-core' ),
						'type'      => 'colour',
						'default'   => '#FF6B55',
						'condition' => array( 'inherit_colours!' => 'yes' ),
					),
				),
			),
			array(
				'id'     => 'section_layout',
				'label'  => __( 'Layout', 'agend-apps-core' ),
				'tab'    => 'style',
				'fields' => array(
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
		),
	);
}
