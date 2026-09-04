<?php
/**
 * Content-settings schema for the Agend Member Login surface.
 *
 * Transcribed from the Agend Member Login widget's register_controls()'s
 * Content-tab section.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Agend Member Login widget's content-settings schema.
 *
 * @return array
 */
function agend_apps_records_schema_member_login(): array {
	return array(
		'sections' => array(
			array(
				'id'     => 'section_content',
				'label'  => __( 'Content', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'    => 'heading_text',
						'label'   => __( 'Heading', 'agend-apps-core' ),
						'type'    => 'text',
						'default' => __( 'Sign in', 'agend-apps-core' ),
					),
					array(
						'name'    => 'intro_text',
						'label'   => __( 'Intro text', 'agend-apps-core' ),
						'type'    => 'textarea',
						'default' => __( 'Sign in with your Agend member account.', 'agend-apps-core' ),
					),
					array(
						'name'    => 'submit_label',
						'label'   => __( 'Sign-in button label', 'agend-apps-core' ),
						'type'    => 'text',
						'default' => __( 'Sign in', 'agend-apps-core' ),
					),
					array(
						'name'        => 'forgot_label',
						'label'       => __( 'Forgot-password link text', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => __( 'Forgot your password?', 'agend-apps-core' ),
						'description' => __( 'Leave empty to hide the password-recovery link.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'back_to_sign_in_label',
						'label'       => __( 'Return link text', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => __( 'Back to sign in', 'agend-apps-core' ),
						'description' => __( 'Shown in the password-recovery and reset views to return to the sign-in form.', 'agend-apps-core' ),
					),
					array(
						'name'    => 'signed_in_message',
						'label'   => __( 'Signed-in message', 'agend-apps-core' ),
						'type'    => 'textarea',
						'default' => __( 'You are signed in to your Agend account.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'portal_button_label',
						'label'       => __( 'Portal button label', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => __( 'Go to my account', 'agend-apps-core' ),
						'description' => __( 'Opens the member portal, already signed in. Leave empty to hide.', 'agend-apps-core' ),
					),
					array(
						'name'    => 'sign_out_label',
						'label'   => __( 'Sign-out label', 'agend-apps-core' ),
						'type'    => 'text',
						'default' => __( 'Sign out', 'agend-apps-core' ),
					),
				),
			),
		),
	);
}
