<?php
/**
 * Content-settings schema for the Agend Login / Portal Link surface.
 *
 * Transcribed from the Agend Login / Portal Link widget's register_controls()'s
 * Content-tab section.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Agend Login / Portal Link widget's content-settings schema.
 *
 * @return array
 */
function agend_apps_records_schema_header_auth(): array {
	return array(
		'sections' => array(
			array(
				'id'     => 'section_content',
				'label'  => __( 'Content', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'        => 'logged_out_label',
						'label'       => __( 'Logged-out label', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => __( 'Log In', 'agend-apps-core' ),
						'description' => __( 'Shown to signed-out visitors; links to the login page.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'logged_in_label',
						'label'       => __( 'Logged-in label', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => __( 'My Portal', 'agend-apps-core' ),
						'description' => __( 'Shown to signed-in members; opens the member portal, already signed in.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'sign_out_label',
						'label'       => __( 'Sign-out label', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => __( 'Sign out', 'agend-apps-core' ),
						'description' => __( 'Shown in a dropdown when a signed-in member hovers or focuses the button. Leave empty to hide the dropdown.', 'agend-apps-core' ),
					),
					array(
						'name'          => 'login_url',
						'label'         => __( 'Login page', 'agend-apps-core' ),
						'type'          => 'url',
						'description'   => __( 'Where signed-out visitors go. Leave blank to use the WordPress login page.', 'agend-apps-core' ),
						'placeholder'   => home_url( '/login/' ),
						'show_external' => false,
						'default'       => array(
							'url' => '',
						),
					),
				),
			),
		),
	);
}
