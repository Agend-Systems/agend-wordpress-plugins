<?php
/**
 * Content-settings schema for the Community channels surface.
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
 * The most channels the surface will show.
 *
 * @var int
 */
const AGEND_APPS_RECORDS_COMMUNITY_CHANNELS_MAX = 24;

/**
 * The community channels surface's content-settings schema.
 *
 * Setting names are persisted in every saved page that uses the surface:
 * never rename one once shipped.
 *
 * @return array
 */
function agend_apps_records_schema_community_channels(): array {
	return array(
		'sections' => array(
			agend_apps_records_community_section_heading(
				__( 'Community channels', 'agend-apps-core' ),
				__( 'Spaces for members to talk, share and ask questions.', 'agend-apps-core' )
			),
			array(
				'id'     => 'section_channels',
				'label'  => __( 'Channels', 'agend-apps-core' ),
				'fields' => array(
					agend_apps_records_community_field_channels(),
					array(
						'name'        => 'channel_kind',
						'label'       => __( 'Kinds of channel', 'agend-apps-core' ),
						'type'        => 'select',
						'options'     => array(
							'all'     => __( 'All channels', 'agend-apps-core' ),
							'feed'    => __( 'Feed channels, for posts with a title', 'agend-apps-core' ),
							'channel' => __( 'Chat channels', 'agend-apps-core' ),
						),
						'default'     => 'all',
						'description' => __( 'Feed channels hold announcements, updates and discussions. Chat channels hold quick messages.', 'agend-apps-core' ),
					),
					array(
						'name'    => 'sort',
						'label'   => __( 'Order', 'agend-apps-core' ),
						'type'    => 'select',
						'options' => array(
							'name'    => __( 'By name', 'agend-apps-core' ),
							'members' => __( 'Most members first', 'agend-apps-core' ),
						),
						'default' => 'name',
					),
					array(
						'name'        => 'count',
						'label'       => __( 'Number of channels', 'agend-apps-core' ),
						'type'        => 'number',
						'default'     => 8,
						'min'         => 1,
						'max'         => AGEND_APPS_RECORDS_COMMUNITY_CHANNELS_MAX,
						/* translators: %d: the most channels the widget will show. */
						'description' => sprintf( __( 'Shows up to %d.', 'agend-apps-core' ), AGEND_APPS_RECORDS_COMMUNITY_CHANNELS_MAX ),
					),
				),
			),
			array(
				'id'     => 'section_layout',
				'label'  => __( 'Layout', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'        => 'columns_desktop',
						'label'       => __( 'Columns (Desktop)', 'agend-apps-core' ),
						'type'        => 'select',
						'options'     => array(
							'1' => '1',
							'2' => '2',
							'3' => '3',
							'4' => '4',
						),
						'default'     => '3',
						'description' => __( 'The most columns across. Narrow areas use fewer columns.', 'agend-apps-core' ),
					),
					array(
						'name'    => 'columns_tablet',
						'label'   => __( 'Columns (Tablet)', 'agend-apps-core' ),
						'type'    => 'select',
						'options' => array(
							'1' => '1',
							'2' => '2',
							'3' => '3',
						),
						'default' => '2',
					),
					array(
						'name'    => 'columns_mobile',
						'label'   => __( 'Columns (Mobile)', 'agend-apps-core' ),
						'type'    => 'select',
						'options' => array(
							'1' => '1',
							'2' => '2',
						),
						'default' => '1',
					),
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
				'id'     => 'section_card_fields',
				'label'  => __( 'Channel Card', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'    => 'show_description',
						'label'   => __( 'Show the description', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'    => 'show_members',
						'label'   => __( 'Show the number of members', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
				),
			),
			agend_apps_records_community_section_links( 'channel', true ),
			agend_apps_records_community_section_empty( __( 'There are no community channels to show yet.', 'agend-apps-core' ), __( 'channels', 'agend-apps-core' ) ),
			agend_apps_records_community_section_colours( __( 'Used for the channel letters.', 'agend-apps-core' ) ),
		),
	);
}
