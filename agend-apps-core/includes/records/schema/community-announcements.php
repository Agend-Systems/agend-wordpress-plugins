<?php
/**
 * Content-settings schema for the Community announcements surface.
 *
 * One declaration for both editors: the Elementor widget and the block render
 * their controls from it (see schema.php for the vocabulary).
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/community-common.php';

/**
 * The most announcements the surface will show.
 *
 * @var int
 */
const AGEND_APPS_RECORDS_COMMUNITY_ANNOUNCEMENTS_MAX = 20;

/**
 * The community announcements surface's content-settings schema.
 *
 * Setting names are persisted in every saved page that uses the surface:
 * never rename one once shipped.
 *
 * @return array
 */
function agend_apps_records_schema_community_announcements(): array {
	return array(
		'sections' => array(
			agend_apps_records_community_section_heading(
				__( 'Announcements', 'agend-apps-core' ),
				__( 'News and notices from the association.', 'agend-apps-core' )
			),
			array(
				'id'     => 'section_posts',
				'label'  => __( 'Announcements', 'agend-apps-core' ),
				'fields' => array(
					agend_apps_records_community_field_channels(),
					array(
						'name'        => 'post_source',
						'label'       => __( 'Which posts', 'agend-apps-core' ),
						'type'        => 'select',
						'options'     => array(
							'announcements' => __( 'Announcements only', 'agend-apps-core' ),
							'titled'        => __( 'Every post with a title', 'agend-apps-core' ),
						),
						'default'     => 'announcements',
						'description' => __( 'Announcements are posts marked as an announcement in your community. Every post with a title also includes updates, discussions, questions and events. Chat messages are never shown.', 'agend-apps-core' ),
					),
					agend_apps_records_community_field_automated(),
					array(
						'name'    => 'pinned_only',
						'label'   => __( 'Only pinned posts', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => false,
					),
					array(
						'name'    => 'featured_only',
						'label'   => __( 'Only featured posts', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => false,
					),
					array(
						'name'    => 'pinned_first',
						'label'   => __( 'Show pinned posts first', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'        => 'count',
						'label'       => __( 'Number of announcements', 'agend-apps-core' ),
						'type'        => 'number',
						'default'     => 5,
						'min'         => 1,
						'max'         => AGEND_APPS_RECORDS_COMMUNITY_ANNOUNCEMENTS_MAX,
						/* translators: %d: the most announcements the widget will show. */
						'description' => sprintf( __( 'Shows up to %d.', 'agend-apps-core' ), AGEND_APPS_RECORDS_COMMUNITY_ANNOUNCEMENTS_MAX ),
					),
					array(
						'name'        => 'max_age_days',
						'label'       => __( 'Hide posts older than (days)', 'agend-apps-core' ),
						'type'        => 'number',
						'default'     => 90,
						'min'         => 0,
						'max'         => 365,
						'description' => __( 'Enter 0 to show posts of any age. Pinned posts always stay.', 'agend-apps-core' ),
					),
				),
			),
			array(
				'id'     => 'section_layout',
				'label'  => __( 'Layout', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'    => 'card_radius',
						'label'   => __( 'Corner Radius (px)', 'agend-apps-core' ),
						'type'    => 'number',
						'default' => 10,
						'min'     => 0,
						'max'     => 48,
					),
				),
			),
			array(
				'id'     => 'section_card_fields',
				'label'  => __( 'Notice', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'    => 'show_date',
						'label'   => __( 'Show the date', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'    => 'show_channel',
						'label'   => __( 'Show the channel name', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'    => 'show_pinned_badge',
						'label'   => __( 'Show a Pinned label', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'    => 'show_excerpt',
						'label'   => __( 'Show an excerpt', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'      => 'excerpt_length',
						'label'     => __( 'Excerpt length (characters)', 'agend-apps-core' ),
						'type'      => 'number',
						'default'   => 160,
						'min'       => 20,
						'max'       => 400,
						'condition' => array( 'show_excerpt' => 'yes' ),
					),
				),
			),
			agend_apps_records_community_section_author(),
			agend_apps_records_community_section_links( 'post', false ),
			agend_apps_records_community_section_empty( __( 'There are no announcements right now.', 'agend-apps-core' ), __( 'announcements', 'agend-apps-core' ) ),
			agend_apps_records_community_section_colours( __( 'Used for the Pinned marker and the date.', 'agend-apps-core' ) ),
		),
	);
}
