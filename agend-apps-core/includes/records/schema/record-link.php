<?php
/**
 * Content-settings schema for the Agend Link / Button surface.
 *
 * Transcribed from the Agend Link / Button widget's register_controls()'s
 * Content-tab section ("Link"). The Style-tab ("Button") section is
 * untouched and stays hand-declared in the widget.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Agend Link / Button widget's content-settings schema.
 *
 * @return array
 */
function agend_apps_records_schema_record_link(): array {
	return array(
		'sections' => array(
			array(
				'id'     => 'section_link',
				'label'  => __( 'Link', 'agend-apps-core' ),
				'fields' => array(
					agend_apps_records_schema_record_type_field(),
					array(
						'name'    => 'action',
						'label'   => __( 'Action', 'agend-apps-core' ),
						'type'    => 'select',
						'default' => 'detail',
						'options' => 'agend_apps_records_record_link_action_options',
					),
					array(
						'name'        => 'custom_url',
						'label'       => __( 'URL', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => '',
						'label_block' => true,
						'description' => __( 'Use {slug} and {title} as placeholders for the record values.', 'agend-apps-core' ),
						'condition'   => array( 'action' => 'custom' ),
					),
					array(
						'name'        => 'text',
						'label'       => __( 'Text', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => '',
						'description' => __( 'Leave empty for the default label of the chosen action.', 'agend-apps-core' ),
					),
					array(
						'name'    => 'style_as',
						'label'   => __( 'Appearance', 'agend-apps-core' ),
						'type'    => 'select',
						'default' => 'button',
						'options' => array(
							'button' => __( 'Button', 'agend-apps-core' ),
							'link'   => __( 'Text link', 'agend-apps-core' ),
						),
					),
					array(
						'name'      => 'full_width',
						'label'     => __( 'Full width', 'agend-apps-core' ),
						'type'      => 'toggle',
						'default'   => false,
						'condition' => array( 'style_as' => 'button' ),
					),
					array(
						'name'      => 'new_tab',
						'label'     => __( 'Open in new tab', 'agend-apps-core' ),
						'type'      => 'toggle',
						'default'   => false,
						'condition' => array( 'action' => array( 'custom', 'ical' ) ),
					),
					array(
						'name'      => 'disabled_when_sold_out',
						'label'     => __( 'Disable when sold out', 'agend-apps-core' ),
						'type'      => 'toggle',
						'default'   => true,
						'condition' => array( 'action' => 'register' ),
					),
					array(
						'name'      => 'hide_when_registered',
						'label'     => __( 'Hide when the viewer is already registered', 'agend-apps-core' ),
						'type'      => 'toggle',
						'default'   => false,
						'condition' => array( 'action' => 'register' ),
					),
					array(
						'name'      => 'hide_when_enrolled',
						'label'     => __( 'Hide when the viewer is already enrolled', 'agend-apps-core' ),
						'type'      => 'toggle',
						'default'   => true,
						'condition' => array( 'action' => 'enrol' ),
					),
				),
			),
		),
	);
}

/**
 * Action options for the Agend Link / Button widget.
 *
 * Moved from the widget's action_options() method verbatim.
 *
 * @return array<string, string>
 */
function agend_apps_records_record_link_action_options(): array {
	return array(
		'detail'    => __( 'Open detail page', 'agend-apps-core' ),
		'catalogue' => __( 'Back to catalogue', 'agend-apps-core' ),
		'register'  => __( 'Register (events)', 'agend-apps-core' ),
		'enrol'     => __( 'Enrol (courses)', 'agend-apps-core' ),
		'ical'      => __( 'Add to calendar (events)', 'agend-apps-core' ),
		'custom'    => __( 'Custom URL', 'agend-apps-core' ),
	);
}
