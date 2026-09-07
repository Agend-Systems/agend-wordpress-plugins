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
		),
	);
}
