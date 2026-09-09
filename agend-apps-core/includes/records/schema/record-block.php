<?php
/**
 * Content-settings schema for the Agend Content Block surface.
 *
 * Transcribed from the Agend Content Block widget's register_controls().
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Agend Content Block's content-settings schema.
 *
 * @return array
 */
function agend_apps_records_schema_record_block(): array {
	return array(
		'sections' => array(
			array(
				'id'     => 'section_block',
				'label'  => __( 'Block', 'agend-apps-core' ),
				'fields' => array(
					agend_apps_records_schema_record_type_field(),
					array(
						'name'        => 'block',
						'label'       => __( 'Block', 'agend-apps-core' ),
						'type'        => 'select',
						'default'     => 'event_facts',
						'options'     => array(
							'event_facts'           => __( 'Event facts (date, location, format)', 'agend-apps-core' ),
							'event_registration'    => __( 'Event registration panel', 'agend-apps-core' ),
							'event_tickets'         => __( 'Event tickets and pricing', 'agend-apps-core' ),
							'event_sponsors'        => __( 'Event sponsors', 'agend-apps-core' ),
							'course_meta'           => __( 'Course details (level, format, duration)', 'agend-apps-core' ),
							'course_outcomes'       => __( 'Course learning outcomes', 'agend-apps-core' ),
							'course_enrolment'      => __( 'Course pricing and enrolment', 'agend-apps-core' ),
							'listing_about'         => __( 'Listing about', 'agend-apps-core' ),
							'listing_contact'       => __( 'Listing contact and links', 'agend-apps-core' ),
							'listing_categories'    => __( 'Listing categories', 'agend-apps-core' ),
							'listing_tags'          => __( 'Listing tags', 'agend-apps-core' ),
							'listing_gallery'       => __( 'Listing gallery', 'agend-apps-core' ),
							'listing_locations'     => __( 'Listing locations', 'agend-apps-core' ),
							'listing_hours'         => __( 'Listing business hours', 'agend-apps-core' ),
							'listing_custom_fields' => __( 'Listing custom fields', 'agend-apps-core' ),
							'listing_achievements'  => __( 'Listing badges and credentials', 'agend-apps-core' ),
							'listing_reviews'       => __( 'Listing reviews', 'agend-apps-core' ),
						),
						'label_block' => true,
						'description' => __( 'Intended for detail templates. The tickets block loads the ticket list per event, so avoid it on cards.', 'agend-apps-core' ),
					),
				),
			),
			// A block renders the built-in fragment markup, which reads the
			// catalogue colour variables. Without these controls the block was
			// stuck emitting the plugin's default palette, so a fragment placed
			// in a template never matched the catalogue widget it came from.
			// `inherit_colours` defaults OFF here, unlike the catalogue
			// surfaces: the field defaults below are the plugin defaults, so an
			// untouched block emits exactly what it emitted before it had any
			// colour controls, and only a control the author actually changes
			// moves it.
			//
			// The field names, types and defaults have to stay byte-identical
			// to the catalogue surfaces' own colour section for
			// AGEND_APPS_RECORDS_COLOUR_SETTING_KEYS to read them. This is the
			// sixth copy of that array in this directory; a shared builder is
			// the obvious follow-up, and would need to carry the memberships
			// surface's deliberate role subset with it.
			// A block renders the built-in fragment markup, which reads the
			// catalogue colour variables. Without these controls the block was
			// stuck emitting the plugin's default palette, so a fragment placed
			// in a template never matched the catalogue widget it came from.
			// `inherit_colours` defaults OFF here, unlike the catalogue
			// surfaces: the field defaults are the plugin defaults, so an
			// untouched block emits exactly what it emitted before it had any
			// colour controls, and only a control the author actually changes
			// moves it.
			agend_apps_records_schema_colour_fields(
				array( 'heading', 'body', 'accent', 'button', 'buttonText' ),
				array(
					'inherit'      => false,
					'inherit_text' => __( 'Use the site\'s theme colours when the theme sets them, otherwise the connected Agend account\'s colours. Leave off to set them manually below.', 'agend-apps-core' ),
					'descriptions' => array(
						'heading' => __( 'Section titles inside the block.', 'agend-apps-core' ),
						'accent'  => __( 'Drives links, category pills and rating stars inside the block.', 'agend-apps-core' ),
					),
				)
			),
		),
	);
}
