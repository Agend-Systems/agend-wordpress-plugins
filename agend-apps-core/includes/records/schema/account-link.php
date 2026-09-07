<?php
/**
 * Content-settings schema for the Agend Account Link surface.
 *
 * Transcribed from the Agend Account Link widget's register_content_controls().
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Agend Account Link widget's content-settings schema.
 *
 * @return array
 */
function agend_apps_records_schema_account_link(): array {
	return array(
		'sections' => array(
			array(
				'id'     => 'section_content',
				'label'  => __( 'Content', 'agend-apps-core' ),
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
						'default'   => __( 'Your Agend Account', 'agend-apps-core' ),
						'condition' => array( 'show_heading' => 'yes' ),
					),
					array(
						'name'    => 'linked_message',
						'label'   => __( 'Linked message', 'agend-apps-core' ),
						'type'    => 'textarea',
						'default' => __( 'Your account is linked. You have full access to member content.', 'agend-apps-core' ),
					),
					array(
						'name'    => 'unlinked_message',
						'label'   => __( 'Not-linked message', 'agend-apps-core' ),
						'type'    => 'textarea',
						'default' => __( 'Link your account to unlock member content, courses, and event pricing.', 'agend-apps-core' ),
					),
					array(
						'name'    => 'button_label',
						'label'   => __( 'Link button label', 'agend-apps-core' ),
						'type'    => 'text',
						'default' => __( 'Link my account', 'agend-apps-core' ),
					),
					array(
						'name'        => 'portal_link_label',
						'label'       => __( 'Portal link label', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => __( 'Review your Agend details', 'agend-apps-core' ),
						'description' => __( 'Shown to linked members as a link to the member portal. Leave empty to hide the link.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'logged_out_message',
						'label'       => __( 'Logged-out message', 'agend-apps-core' ),
						'type'        => 'textarea',
						'default'     => __( 'Log in to link your account with Agend.', 'agend-apps-core' ),
						'description' => __( 'Shown to visitors who are not logged in to WordPress. Leave empty to hide the widget for logged-out visitors.', 'agend-apps-core' ),
					),
				),
			),
		),
	);
}
