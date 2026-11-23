<?php
/**
 * Content-settings schema for the Community popular posts surface.
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
 * The most posts the surface will show.
 *
 * @var int
 */
const AGEND_APPS_RECORDS_COMMUNITY_FEED_MAX = 20;

/**
 * The community popular posts surface's content-settings schema.
 *
 * Setting names are persisted in every saved page that uses the surface:
 * never rename one once shipped.
 *
 * @return array
 */
function agend_apps_records_schema_community_feed(): array {
	return array(
		'sections' => array(
			agend_apps_records_community_section_heading(
				__( 'Popular posts', 'agend-apps-core' ),
				__( 'What members are talking about in the community.', 'agend-apps-core' )
			),
			array(
				'id'     => 'section_posts',
				'label'  => __( 'Posts', 'agend-apps-core' ),
				'fields' => array(
					agend_apps_records_community_field_channels(
						/* translators: %d: the most channels posts are read from. */
						sprintf( __( 'Posts are read from up to %d channels, so choose the ones that matter most.', 'agend-apps-core' ), AGEND_APPS_COMMUNITY_MAX_CHANNEL_READS )
					),
					array(
						'name'        => 'order',
						'label'       => __( 'Order', 'agend-apps-core' ),
						'type'        => 'select',
						'options'     => array(
							'popular' => __( 'Most popular first', 'agend-apps-core' ),
							'recent'  => __( 'Newest first', 'agend-apps-core' ),
						),
						'default'     => 'popular',
						'description' => __( 'Popular means featured posts and posts with plenty of replies: featured first, then the most replies. Other posts are left out, so there may be fewer than the number below.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'popular_min_replies',
						'label'       => __( 'Replies needed to count as popular', 'agend-apps-core' ),
						'type'        => 'number',
						'default'     => 5,
						'min'         => 1,
						'max'         => 20,
						'description' => __( 'Featured posts always count.', 'agend-apps-core' ),
						'condition'   => array( 'order' => 'popular' ),
					),
					array(
						'name'        => 'include_untitled',
						'label'       => __( 'Include chat messages', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => false,
						'description' => __( 'Chat messages are short and informal. Leave this off unless the channel is mostly chat and you want it shown.', 'agend-apps-core' ),
						'condition'   => array( 'order' => 'recent' ),
					),
					agend_apps_records_community_field_automated(),
					array(
						'name'        => 'count',
						'label'       => __( 'Number of posts', 'agend-apps-core' ),
						'type'        => 'number',
						'default'     => 5,
						'min'         => 1,
						'max'         => AGEND_APPS_RECORDS_COMMUNITY_FEED_MAX,
						/* translators: %d: the most posts the widget will show. */
						'description' => sprintf( __( 'Shows up to %d.', 'agend-apps-core' ), AGEND_APPS_RECORDS_COMMUNITY_FEED_MAX ),
					),
					array(
						'name'        => 'max_age_days',
						'label'       => __( 'Hide posts older than (days)', 'agend-apps-core' ),
						'type'        => 'number',
						'default'     => 90,
						'min'         => 0,
						'max'         => 365,
						'description' => __( 'Enter 0 to show posts of any age.', 'agend-apps-core' ),
					),
				),
			),
			array(
				'id'     => 'section_layout',
				'label'  => __( 'Layout', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'        => 'layout',
						'label'       => __( 'Layout', 'agend-apps-core' ),
						'type'        => 'select',
						'options'     => array(
							'cards' => __( 'Cards', 'agend-apps-core' ),
							'list'  => __( 'List', 'agend-apps-core' ),
						),
						'default'     => 'cards',
						'description' => __( 'Cards show each post in its own box, side by side. A list shows one post per row.', 'agend-apps-core' ),
					),
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
						'condition'   => array( 'layout' => 'cards' ),
					),
					array(
						'name'      => 'columns_tablet',
						'label'     => __( 'Columns (Tablet)', 'agend-apps-core' ),
						'type'      => 'select',
						'options'   => array(
							'1' => '1',
							'2' => '2',
							'3' => '3',
						),
						'default'   => '2',
						'condition' => array( 'layout' => 'cards' ),
					),
					array(
						'name'      => 'columns_mobile',
						'label'     => __( 'Columns (Mobile)', 'agend-apps-core' ),
						'type'      => 'select',
						'options'   => array(
							'1' => '1',
							'2' => '2',
						),
						'default'   => '1',
						'condition' => array( 'layout' => 'cards' ),
					),
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
				'label'  => __( 'Post', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'    => 'show_reply_count',
						'label'   => __( 'Show the number of replies', 'agend-apps-core' ),
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
						'name'    => 'show_date',
						'label'   => __( 'Show the date', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'    => 'show_featured_badge',
						'label'   => __( 'Show a Featured label', 'agend-apps-core' ),
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
						'default'   => 120,
						'min'       => 20,
						'max'       => 400,
						'condition' => array( 'show_excerpt' => 'yes' ),
					),
				),
			),
			agend_apps_records_community_section_author(),
			agend_apps_records_community_section_links( 'post', false ),
			agend_apps_records_community_section_empty( __( 'There are no popular posts right now.', 'agend-apps-core' ), __( 'popular posts', 'agend-apps-core' ) ),
			agend_apps_records_community_section_colours( __( 'Used for the reply counts and the Featured marker.', 'agend-apps-core' ) ),
		),
	);
}
