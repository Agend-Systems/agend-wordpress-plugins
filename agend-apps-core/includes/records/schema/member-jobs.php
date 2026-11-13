<?php
/**
 * Content-settings schema for the Agend Member Jobs surface: a signed-in
 * member's own job submissions, their job credits and the "Post a job" form.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The optional form fields a site can hide or require, keyed as the form
 * names them. `salary` is the salary range, period and "show salary" together;
 * `application` is the application email and link, of which one is needed
 * when it is required.
 *
 * @return array<string, string> Key => label.
 */
function agend_apps_records_member_jobs_optional_fields(): array {
	return array(
		'reference_number'         => __( 'Reference number', 'agend-apps-core' ),
		'experience_level'         => __( 'Experience level', 'agend-apps-core' ),
		'category'                 => __( 'Category', 'agend-apps-core' ),
		'short_description'        => __( 'Short summary', 'agend-apps-core' ),
		'description'              => __( 'Full description', 'agend-apps-core' ),
		'company_name'             => __( 'Employer name', 'agend-apps-core' ),
		'company_website'          => __( 'Employer website', 'agend-apps-core' ),
		'location_city'            => __( 'Suburb or city', 'agend-apps-core' ),
		'location_state'           => __( 'State', 'agend-apps-core' ),
		'location_postcode'        => __( 'Postcode', 'agend-apps-core' ),
		'location_country'         => __( 'Country', 'agend-apps-core' ),
		'salary'                   => __( 'Salary range', 'agend-apps-core' ),
		'application'              => __( 'Application email or link', 'agend-apps-core' ),
		'application_instructions' => __( 'Application instructions', 'agend-apps-core' ),
		'contact_name'             => __( 'Contact name', 'agend-apps-core' ),
		'contact_phone'            => __( 'Contact phone', 'agend-apps-core' ),
	);
}

/**
 * The fields required by default: what an associations' jobs board usually
 * needs before staff can approve a listing.
 *
 * @var string[]
 */
const AGEND_APPS_RECORDS_MEMBER_JOBS_REQUIRED_DEFAULT = array(
	'category',
	'short_description',
	'description',
	'company_name',
	'location_city',
	'location_state',
	'location_postcode',
	'application',
);

/**
 * The Agend Member Jobs surface's content-settings schema.
 *
 * @return array
 */
function agend_apps_records_schema_member_jobs(): array {
	$optional = agend_apps_records_member_jobs_optional_fields();

	return array(
		'sections' => array(
			array(
				'id'     => 'section_content',
				'label'  => __( 'Content', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'    => 'heading',
						'label'   => __( 'Heading', 'agend-apps-core' ),
						'type'    => 'text',
						'default' => __( 'My jobs', 'agend-apps-core' ),
					),
					array(
						'name'        => 'intro',
						'label'       => __( 'Introduction', 'agend-apps-core' ),
						'type'        => 'textarea',
						'default'     => __( 'Post a job to the board for our team to review. Each job uses one job credit and runs for the board\'s listing period once approved.', 'agend-apps-core' ),
						'description' => __( 'Shown above the member\'s jobs. Leave empty to hide.', 'agend-apps-core' ),
					),
					array(
						'name'    => 'post_label',
						'label'   => __( 'Post button', 'agend-apps-core' ),
						'type'    => 'text',
						'default' => __( 'Post a job', 'agend-apps-core' ),
					),
					array(
						'name'        => 'buy_url',
						'label'       => __( 'Buy credits page', 'agend-apps-core' ),
						'type'        => 'url',
						'description' => __( 'Where a member buys job credits, for example the shop product. Leave empty to hide the link.', 'agend-apps-core' ),
					),
					array(
						'name'    => 'buy_label',
						'label'   => __( 'Buy credits link', 'agend-apps-core' ),
						'type'    => 'text',
						'default' => __( 'Buy job credits', 'agend-apps-core' ),
					),
					array(
						'name'    => 'no_credits_message',
						'label'   => __( 'No credits message', 'agend-apps-core' ),
						'type'    => 'textarea',
						'default' => __( 'You have no job credits left. Buy a credit to post a job.', 'agend-apps-core' ),
					),
					array(
						'name'    => 'success_message',
						'label'   => __( 'Submitted message', 'agend-apps-core' ),
						'type'    => 'textarea',
						'default' => __( 'Thanks, your job has been sent for review. We will email you once it has been approved.', 'agend-apps-core' ),
					),
					array(
						'name'    => 'logged_out_message',
						'label'   => __( 'Signed-out message', 'agend-apps-core' ),
						'type'    => 'textarea',
						'default' => __( 'Please sign in to post and manage your jobs.', 'agend-apps-core' ),
					),
				),
			),
			array(
				'id'     => 'section_form',
				'label'  => __( 'Job Form', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'        => 'required_fields',
						'label'       => __( 'Required fields', 'agend-apps-core' ),
						'type'        => 'multiselect',
						'label_block' => true,
						'default'     => AGEND_APPS_RECORDS_MEMBER_JOBS_REQUIRED_DEFAULT,
						'options'     => $optional,
						'description' => __( 'Job title, employment type and work arrangement are always required.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'hidden_fields',
						'label'       => __( 'Hidden fields', 'agend-apps-core' ),
						'type'        => 'multiselect',
						'label_block' => true,
						'default'     => array(),
						'options'     => $optional,
						'description' => __( 'Fields the form leaves out. A hidden field is never required.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'state_options',
						'label'       => __( 'States', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => 'ACT, NSW, NT, QLD, SA, TAS, VIC, WA',
						'description' => __( 'Separate with commas; the State field becomes a dropdown of these. Match the board\'s State filter. Leave empty for a text box.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'tag_groups',
						'label'       => __( 'Tag questions', 'agend-apps-core' ),
						'type'        => 'repeater',
						'row_label'   => 'label',
						'default'     => array(),
						'description' => __( 'Each row asks the member to pick from some of the board\'s tags, for example a "Salary band" dropdown or an "Accreditation required" tick box. Match the board\'s tag filters.', 'agend-apps-core' ),
						'fields'      => array(
							array(
								'name'    => 'label',
								'label'   => __( 'Label', 'agend-apps-core' ),
								'type'    => 'text',
								'default' => '',
							),
							array(
								'name'        => 'tags',
								'label'       => __( 'Tags', 'agend-apps-core' ),
								'type'        => 'multiselect',
								'label_block' => true,
								'options'     => 'agend_apps_records_jobs_catalogue_tag_options',
							),
							array(
								'name'    => 'style',
								'label'   => __( 'Shown as', 'agend-apps-core' ),
								'type'    => 'select',
								'default' => 'select',
								'options' => array(
									'select'     => __( 'Dropdown: pick one of the tags', 'agend-apps-core' ),
									'checkboxes' => __( 'Tick boxes: tick any that apply', 'agend-apps-core' ),
								),
							),
							array(
								'name'        => 'required',
								'label'       => __( 'Required', 'agend-apps-core' ),
								'type'        => 'toggle',
								'default'     => false,
								'description' => __( 'For a dropdown, the member must pick one. Tick boxes are never required.', 'agend-apps-core' ),
							),
						),
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
						'label'     => __( 'Highlight / accent colour', 'agend-apps-core' ),
						'type'      => 'colour',
						'default'   => '#FF6B55',
						'condition' => array( 'inherit_colours!' => 'yes' ),
					),
					array(
						'name'      => 'button_colour',
						'label'     => __( 'Button colour', 'agend-apps-core' ),
						'type'      => 'colour',
						'default'   => '#FF6B55',
						'condition' => array( 'inherit_colours!' => 'yes' ),
					),
					array(
						'name'      => 'button_text_colour',
						'label'     => __( 'Button text colour', 'agend-apps-core' ),
						'type'      => 'colour',
						'default'   => '#FFFFFF',
						'condition' => array( 'inherit_colours!' => 'yes' ),
					),
				),
			),
		),
	);
}
