<?php
/**
 * Content-settings schema for the Agend Memberships surface.
 *
 * Transcribed from the Agend Memberships widget's register_content_controls().
 * `tier_mode_overrides` (REPEATER) and `success_url` (URL) are controls the
 * shared vocabulary cannot describe, so they stay `adapter` fields; see
 * the widget's register_adapter_control().
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Agend Memberships widget's content-settings schema.
 *
 * @return array
 */
function agend_apps_records_schema_memberships_catalogue(): array {
	return array(
		'sections' => array(
			array(
				'id'     => 'section_heading',
				'label'  => __( 'Heading', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'    => 'heading_text',
						'label'   => __( 'Heading', 'agend-apps-core' ),
						'type'    => 'text',
						'default' => __( 'Membership Options', 'agend-apps-core' ),
					),
					array(
						'name'        => 'membership_type',
						'label'       => __( 'Membership types to show', 'agend-apps-core' ),
						'type'        => 'select',
						'options'     => array(
							''           => __( 'Individual & corporate', 'agend-apps-core' ),
							'individual' => __( 'Individual only', 'agend-apps-core' ),
							'corporate'  => __( 'Corporate only', 'agend-apps-core' ),
						),
						'default'     => '',
						'description' => __( 'Filters the tiers server-side by type.', 'agend-apps-core' ),
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
				'id'     => 'section_card_fields',
				'label'  => __( 'Card Fields', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'    => 'show_description',
						'label'   => __( 'Show description', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'    => 'show_benefits',
						'label'   => __( 'Show benefits', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'    => 'show_price',
						'label'   => __( 'Show price and billing period', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
				),
			),
			array(
				'id'     => 'section_signup_mode',
				'label'  => __( 'Signup Mode', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'        => 'global_signup_mode',
						'label'       => __( 'Default signup mode', 'agend-apps-core' ),
						'type'        => 'select',
						'options'     => array(
							'application' => __( 'Application (captures contact)', 'agend-apps-core' ),
							'direct'      => __( 'Direct purchase (requires payment)', 'agend-apps-core' ),
						),
						'default'     => 'application',
						'description' => __( 'The signup mode for each tier. Can be overridden per tier below.', 'agend-apps-core' ),
					),
					array(
						'name'  => 'tier_mode_overrides',
						'label' => __( 'Tier-specific overrides', 'agend-apps-core' ),
						'type'  => 'adapter',
					),
				),
			),
			array(
				'id'     => 'section_redirect',
				'label'  => __( 'Post-Signup Redirect', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'        => 'success_url',
						'label'       => __( 'Success page URL (optional)', 'agend-apps-core' ),
						'type'        => 'adapter',
						'description' => __( 'URL to redirect to after successful signup. Defaults to the current page.', 'agend-apps-core' ),
					),
				),
			),
			// Deviates from the other three catalogues' style-colours section:
			// this widget never had an inherit_colours switch or button colour
			// controls, so none are added here. See US-1.2 spec-vs-code finding.
			// This surface never had an `inherit_colours` switch or button colour
			// controls (US-1.2 spec-vs-code finding), and its accent default and
			// wording are its own, so it passes all three as options rather than
			// being forced into the shared shape.
			agend_apps_records_schema_colour_fields(
				array( 'accent', 'heading', 'body' ),
				array(
					'inherit'      => null,
					'labels'       => array(
						'accent' => __( 'Accent colour', 'agend-apps-core' ),
					),
					'descriptions' => array(
						'accent' => __( 'Used for buttons, selected card borders, and highlights.', 'agend-apps-core' ),
					),
					'defaults'     => array(
						'accent' => '#F76B4F',
					),
				)
			),
		),
	);
}
