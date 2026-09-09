<?php
/**
 * Content-settings schema for the Agend Panel surface.
 *
 * A panel is a composite: several values, their own headings, their own
 * layout, sometimes their own live data (a ticket list, a review list). The
 * single-value blocks this surface used to also offer (about, categories,
 * tags, business hours, custom fields) are FIELDS, and they live on the Agend
 * Field and Agend Pills widgets, which can label, format and truncate them.
 * Offering the same value in two places taught authors to reach for a
 * whole-panel widget to print one line of text.
 *
 * The retired keys still render {@see Agend_Elementor_Record_Block::blocks()}:
 * a template saved against `listing_about` keeps working, it just cannot be
 * chosen again. See agend_apps_records_retired_block_field() for the field
 * each one became.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Agend Panel's content-settings schema.
 *
 * There is no `record_type` control: a panel key names its own record type
 * (`event_tickets` can only be an event), so a second control could only ever
 * contradict it.
 *
 * @return array
 */
function agend_apps_records_schema_record_block(): array {
	return array(
		'sections' => array(
			array(
				'id'     => 'section_block',
				'label'  => __( 'Panel', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'        => 'block',
						'label'       => __( 'Panel', 'agend-apps-core' ),
						'type'        => 'select',
						'default'     => 'event_facts',
						'groups'      => 'agend_apps_records_block_options',
						'label_block' => true,
						'description' => __( 'Intended for detail templates. The tickets panel loads the ticket list per event, so avoid it on cards. For a single value (a description, categories, opening hours, one custom field) use Agend Field or Agend Pills instead.', 'agend-apps-core' ),
					),
				),
			),
		),
	);
}

/**
 * The panels each record type offers, grouped for the editor picker.
 *
 * @return array<int, array{label: string, options: array<string, string>}>
 */
function agend_apps_records_block_options(): array {
	return array(
		array(
			'label'   => __( 'Event', 'agend-apps-core' ),
			'options' => array(
				'event_facts'        => __( 'Event facts (date, location, format)', 'agend-apps-core' ),
				'event_registration' => __( 'Event registration panel', 'agend-apps-core' ),
				'event_tickets'      => __( 'Event tickets and pricing', 'agend-apps-core' ),
				'event_sponsors'     => __( 'Event sponsors', 'agend-apps-core' ),
			),
		),
		array(
			'label'   => __( 'Course', 'agend-apps-core' ),
			'options' => array(
				'course_meta'      => __( 'Course details (level, format, duration)', 'agend-apps-core' ),
				'course_outcomes'  => __( 'Course learning outcomes', 'agend-apps-core' ),
				'course_enrolment' => __( 'Course pricing and enrolment', 'agend-apps-core' ),
			),
		),
		array(
			'label'   => __( 'Directory listing', 'agend-apps-core' ),
			'options' => array(
				'listing_contact'      => __( 'Listing contact and links', 'agend-apps-core' ),
				'listing_gallery'      => __( 'Listing gallery', 'agend-apps-core' ),
				'listing_locations'    => __( 'Listing locations', 'agend-apps-core' ),
				'listing_achievements' => __( 'Listing badges and credentials', 'agend-apps-core' ),
				'listing_reviews'      => __( 'Listing reviews', 'agend-apps-core' ),
			),
		),
	);
}

/**
 * The field key a retired panel key became, or '' for a key that is still a
 * panel.
 *
 * Used to tell an author editing an old template where the value moved to,
 * rather than leaving them with a panel the picker no longer lists.
 *
 * @param string $block Panel key.
 * @return string A field key for Agend Field or Agend Pills, or ''.
 */
function agend_apps_records_retired_block_field( string $block ): string {
	$retired = array(
		'listing_about'         => 'listing:description',
		'listing_categories'    => 'listing:categories',
		'listing_tags'          => 'listing:tags',
		'listing_hours'         => 'listing:hours',
		'listing_custom_fields' => 'common:custom_field',
	);

	return $retired[ $block ] ?? '';
}
