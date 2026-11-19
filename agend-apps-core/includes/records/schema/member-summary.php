<?php
/**
 * Content-settings schema for the member summary surface.
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
 * The most events, certificates, or courses, the surface will list.
 *
 * Declared here with the settings that expose it; the renderer holds a saved
 * value to the same limit.
 *
 * @var int
 */
const AGEND_APPS_RECORDS_MEMBER_SUMMARY_MAX_ITEMS = 6;

/**
 * The member summary surface's content-settings schema.
 *
 * Setting names are persisted in every saved page that uses the surface: never
 * rename one once shipped.
 *
 * Every label and description is for an association editor, in plain words.
 * What the surface reads from Agend is not mentioned anywhere an editor looks.
 *
 * @return array
 */
function agend_apps_records_schema_member_summary(): array {
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
						'default'   => __( 'My membership', 'agend-apps-core' ),
						'condition' => array( 'show_heading' => 'yes' ),
					),
					array(
						'name'        => 'show_greeting',
						'label'       => __( 'Greet the member by first name', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => true,
						'description' => __( 'The welcome line and the initials in the top band, for example "Welcome back, Priya." Only shown when we have a first name. Their job title and organisation show beneath it when we have them.', 'agend-apps-core' ),
					),
				),
			),
			array(
				'id'     => 'section_parts',
				'label'  => __( 'What to show', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'    => 'note_parts',
						'label'   => __( 'About this widget', 'agend-apps-core' ),
						'type'    => 'note',
						'content' => __( 'Members see their own details here, so place it on a page members sign in to. A part you switch off is not loaded at all.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'show_membership',
						'label'       => __( 'Membership', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => true,
						'description' => __( 'In the top band: the membership type, whether it is active, and when it renews.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'show_cpd',
						'label'       => __( 'CPD points', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => true,
						'description' => __( 'Points earned, shown against the member\'s own target when they have one.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'show_events',
						'label'       => __( 'Upcoming events', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => true,
						'description' => __( 'Events the member is registered for.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'show_courses',
						'label'       => __( 'Courses in progress', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => true,
						'description' => __( 'Courses the member has started, with how far through each one they are.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'show_credentials',
						'label'       => __( 'Certificates and badges', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => true,
					),
					array(
						'name'        => 'show_directory',
						'label'       => __( 'Directory listing', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => true,
						'description' => __( 'The member\'s listing in your directory. Hidden for members who have none.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'show_reading',
						'label'       => __( 'Reading lists', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => false,
						'description' => __( 'The reading lists from your resources library that the member can open. Off by default, because it needs the resources library to be set up.', 'agend-apps-core' ),
					),
				),
			),
			array(
				'id'     => 'section_lists',
				'label'  => __( 'Lists', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'        => 'max_events',
						'label'       => __( 'Events to list', 'agend-apps-core' ),
						'type'        => 'number',
						'default'     => 3,
						'min'         => 1,
						'max'         => AGEND_APPS_RECORDS_MEMBER_SUMMARY_MAX_ITEMS,
						'description' => __( 'The soonest first.', 'agend-apps-core' ),
						'condition'   => array( 'show_events' => 'yes' ),
					),
					array(
						'name'        => 'max_courses',
						'label'       => __( 'Courses to list', 'agend-apps-core' ),
						'type'        => 'number',
						'default'     => 3,
						'min'         => 1,
						'max'         => AGEND_APPS_RECORDS_MEMBER_SUMMARY_MAX_ITEMS,
						'description' => __( 'The ones opened most recently first.', 'agend-apps-core' ),
						'condition'   => array( 'show_courses' => 'yes' ),
					),
					array(
						'name'        => 'max_certificates',
						'label'       => __( 'Certificates to list', 'agend-apps-core' ),
						'type'        => 'number',
						'default'     => 3,
						'min'         => 1,
						'max'         => AGEND_APPS_RECORDS_MEMBER_SUMMARY_MAX_ITEMS,
						'description' => __( 'The newest first.', 'agend-apps-core' ),
						'condition'   => array( 'show_credentials' => 'yes' ),
					),
				),
			),
			array(
				'id'     => 'section_links',
				'label'  => __( 'Links', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'        => 'membership_url',
						'label'       => __( 'Membership page', 'agend-apps-core' ),
						'type'        => 'url',
						'description' => __( 'Where members go to renew or change their membership. Leave empty to use the member portal.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'join_url',
						'label'       => __( 'Join page', 'agend-apps-core' ),
						'type'        => 'url',
						'description' => __( 'Shown to people who are signed in but have no membership. Leave empty for no link.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'courses_url',
						'label'       => __( 'Courses page', 'agend-apps-core' ),
						'type'        => 'url',
						'description' => __( 'Where members go to find and continue courses. Leave empty to use the Courses page set for your site.', 'agend-apps-core' ),
						'condition'   => array( 'show_courses' => 'yes' ),
					),
					array(
						'name'        => 'resources_url',
						'label'       => __( 'Resources page', 'agend-apps-core' ),
						'type'        => 'url',
						'description' => __( 'Where the reading lists link to. Leave empty to use your resources library.', 'agend-apps-core' ),
						'condition'   => array( 'show_reading' => 'yes' ),
					),
					array(
						'name'        => 'credential_check_url',
						'label'       => __( 'Credential check page', 'agend-apps-core' ),
						'type'        => 'url',
						'description' => __( 'The page that holds your Credential Check widget. Each certificate links there, so anyone can confirm it is real. Leave empty for no link.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'my_listing_url',
						'label'       => __( 'Manage listing page', 'agend-apps-core' ),
						'type'        => 'url',
						'description' => __( 'Where members add or update their directory listing. Leave empty for no link.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'support_url',
						'label'       => __( 'Contact page', 'agend-apps-core' ),
						'type'        => 'url',
						'description' => __( 'Shown when a member\'s details cannot be found or loaded. Leave empty for no link.', 'agend-apps-core' ),
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
						'default'     => __( 'Your membership, CPD points, events and certificates appear here once you sign in.', 'agend-apps-core' ),
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
				'id'     => 'section_messages',
				'label'  => __( 'Messages', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'        => 'empty_events_message',
						'label'       => __( 'When there are no upcoming events', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => __( 'You are not registered for any upcoming events.', 'agend-apps-core' ),
						'label_block' => true,
						'condition'   => array( 'show_events' => 'yes' ),
					),
					array(
						'name'        => 'empty_courses_message',
						'label'       => __( 'When there are no courses in progress', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => __( 'You have no courses in progress.', 'agend-apps-core' ),
						'label_block' => true,
						'condition'   => array( 'show_courses' => 'yes' ),
					),
					array(
						'name'        => 'empty_credentials_message',
						'label'       => __( 'When there are no certificates or badges', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => __( 'Certificates and badges you earn will appear here.', 'agend-apps-core' ),
						'label_block' => true,
						'condition'   => array( 'show_credentials' => 'yes' ),
					),
					array(
						'name'        => 'empty_reading_message',
						'label'       => __( 'When there are no reading lists', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => __( 'There are no reading lists for you yet.', 'agend-apps-core' ),
						'label_block' => true,
						'condition'   => array( 'show_reading' => 'yes' ),
					),
					array(
						'name'        => 'no_record_message',
						'label'       => __( 'When no membership can be found', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => __( 'If you are a member, contact us and we will match your account. If not, you can join.', 'agend-apps-core' ),
						'label_block' => true,
					),
					array(
						'name'        => 'unavailable_message',
						'label'       => __( 'When details cannot be loaded', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => __( 'Please try again in a moment.', 'agend-apps-core' ),
						'label_block' => true,
					),
				),
			),
			array(
				'id'     => 'section_layout',
				'label'  => __( 'Layout', 'agend-apps-core' ),
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
						'description' => __( 'Used for the small marks beside statuses and dates.', 'agend-apps-core' ),
						'condition'   => array( 'inherit_colours!' => 'yes' ),
					),
				),
			),
		),
	);
}
