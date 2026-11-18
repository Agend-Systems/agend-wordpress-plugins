<?php
/**
 * Content-settings schema for the resource Ask surface.
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
 * Most suggested questions the surface shows under the box.
 *
 * @var int
 */
const AGEND_APPS_RECORDS_RESOURCES_ASK_SUGGESTIONS_MAX = 4;

/**
 * The resource Ask surface's content-settings schema.
 *
 * Setting names are persisted in every saved page that uses the surface:
 * never rename one once shipped.
 *
 * @return array
 */
function agend_apps_records_schema_resources_ask(): array {
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
						'default'   => __( 'Ask a question', 'agend-apps-core' ),
						'condition' => array( 'show_heading' => 'yes' ),
					),
					array(
						'name'      => 'subheading_text',
						'label'     => __( 'Subheading', 'agend-apps-core' ),
						'type'      => 'textarea',
						'default'   => __( 'Get an answer drawn from our own guides and policies, with the pages it came from.', 'agend-apps-core' ),
						'condition' => array( 'show_heading' => 'yes' ),
					),
				),
			),
			array(
				'id'     => 'section_box',
				'label'  => __( 'Question Box', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'        => 'placeholder',
						'label'       => __( 'Placeholder', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => __( 'For example: How do I make a complaint?', 'agend-apps-core' ),
						'label_block' => true,
					),
					array(
						'name'    => 'button_label',
						'label'   => __( 'Button label', 'agend-apps-core' ),
						'type'    => 'text',
						'default' => __( 'Ask', 'agend-apps-core' ),
					),
					array(
						'name'    => 'show_suggestions',
						'label'   => __( 'Show suggested questions', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => false,
					),
					array(
						'name'        => 'suggestions',
						'label'       => __( 'Suggested questions', 'agend-apps-core' ),
						'type'        => 'textarea',
						'default'     => '',
						'label_block' => true,
						/* translators: %d: the most suggestions shown. */
						'description' => sprintf( __( 'One question per line. Up to %d are shown under the box.', 'agend-apps-core' ), AGEND_APPS_RECORDS_RESOURCES_ASK_SUGGESTIONS_MAX ),
						'condition'   => array( 'show_suggestions' => 'yes' ),
					),
					array(
						'name'    => 'show_sources',
						'label'   => __( 'Show the pages each answer came from', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
				),
			),
			array(
				'id'     => 'section_signed_out',
				'label'  => __( 'Visitors Who Are Not Signed In', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'        => 'signed_out_behaviour',
						'label'       => __( 'What they see', 'agend-apps-core' ),
						'type'        => 'select',
						'options'     => array(
							'prompt' => __( 'A prompt to sign in', 'agend-apps-core' ),
							'hide'   => __( 'Nothing', 'agend-apps-core' ),
						),
						'default'     => 'prompt',
						'description' => __( 'Only signed-in members can ask a question. Each question uses part of your association\'s monthly AI allowance, so the box is never shown to visitors.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'signed_out_message',
						'label'       => __( 'Message', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => __( 'Sign in to ask a question.', 'agend-apps-core' ),
						'label_block' => true,
						'condition'   => array( 'signed_out_behaviour' => 'prompt' ),
					),
					array(
						'name'      => 'sign_in_label',
						'label'     => __( 'Button label', 'agend-apps-core' ),
						'type'      => 'text',
						'default'   => __( 'Sign in', 'agend-apps-core' ),
						'condition' => array( 'signed_out_behaviour' => 'prompt' ),
					),
					array(
						'name'          => 'sign_in_url',
						'label'         => __( 'Button link', 'agend-apps-core' ),
						'type'          => 'url',
						'placeholder'   => 'https://',
						'show_external' => false,
						'default'       => '',
						'description'   => __( 'Leave empty to use your site\'s sign-in page, which brings people back to this page afterwards.', 'agend-apps-core' ),
						'condition'     => array( 'signed_out_behaviour' => 'prompt' ),
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
						'description' => __( 'Used for the numbers on the sources and the focus outline.', 'agend-apps-core' ),
						'condition'   => array( 'inherit_colours!' => 'yes' ),
					),
					array(
						'name'    => 'card_radius',
						'label'   => __( 'Corner Radius (px)', 'agend-apps-core' ),
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
