<?php
/**
 * Test fixture for the Community surfaces: gateway answers for the three Loop
 * reads, built the way the gateway sends them, and helpers that allow channels
 * and queue the answers in the order the read wrappers ask for them.
 *
 * The wrappers themselves are the real ones, driven through the HTTP double, so
 * a test says what the gateway sent and checks what the site made of it.
 *
 * @package Agend\Tests
 */

declare( strict_types=1 );

/**
 * A channel as `/loop/channels` sends it.
 *
 * @param string $slug      Channel slug; the id is derived from it.
 * @param array  $overrides Fields to set over the defaults.
 * @return array
 */
function agend_cm_test_channel( string $slug, array $overrides = array() ): array {
	return $overrides + array(
		'id'           => 'chan-' . $slug,
		'slug'         => $slug,
		'name'         => ucwords( str_replace( '-', ' ', $slug ) ),
		'description'  => 'About ' . $slug . '.',
		'privacy'      => 'public',
		'channel_type' => 'channel',
		'is_archived'  => false,
		'member_count' => 10,
	);
}

/**
 * A post as the gateway's public feeds send it. `type` (activity) and
 * `post_type` (announcements) are both set from `$type`, as each route would.
 *
 * @param string $id        Post id.
 * @param array  $channel   A channel row {@see agend_cm_test_channel()}.
 * @param string $content   Markdown.
 * @param array  $overrides Fields to set over the defaults.
 * @param string $type      '' for chat, else a post type: 'announcement', 'update' ...
 * @return array
 */
function agend_cm_test_post( string $id, array $channel, string $content, array $overrides = array(), string $type = 'announcement' ): array {
	return $overrides + array(
		'id'           => $id,
		'type'         => '' === $type ? 'message' : 'feed_' . $type,
		'post_type'    => '' === $type ? null : $type,
		'content'      => $content,
		'content_html' => '<p>' . $content . '</p>',
		'reply_count'  => 0,
		'is_pinned'    => false,
		'is_featured'  => false,
		'created_at'   => gmdate( 'c', time() - 2 * DAY_IN_SECONDS ),
		'channel'      => array(
			'id'   => $channel['id'],
			'slug' => $channel['slug'],
			'name' => $channel['name'],
		),
		'author'       => array(
			'id'           => 'user-secret-1',
			'display_name' => 'ben.thompson',
			'avatar_url'   => 'https://avatars.example.test/ben.png',
		),
	);
}

/**
 * Allows channels on the site, by row.
 *
 * @param array[] $channels Channel rows to allow.
 */
function agend_cm_test_allow( array $channels ): void {
	update_option( AGEND_APPS_COMMUNITY_OPTION_CHANNELS, array_column( $channels, 'id' ) );
}

/**
 * Queues the channel list answer.
 *
 * @param array[] $channels Channel rows.
 */
function agend_cm_test_queue_channels( array $channels ): void {
	Agend_Test_WP::queue_response(
		200,
		array(
			'success' => true,
			'data'    => $channels,
			'meta'    => array( 'pagination' => array( 'page' => 1, 'total' => count( $channels ) ) ),
		)
	);
}

/**
 * Queues the announcements answer.
 *
 * @param array[] $posts Post rows.
 */
function agend_cm_test_queue_announcements( array $posts ): void {
	Agend_Test_WP::queue_response(
		200,
		array(
			'success' => true,
			'data'    => array(
				'announcements' => $posts,
				'channels'      => array(),
			),
		)
	);
}

/**
 * Queues one channel's activity answer.
 *
 * @param array[] $posts Post rows.
 */
function agend_cm_test_queue_activity( array $posts ): void {
	Agend_Test_WP::queue_response(
		200,
		array(
			'success' => true,
			'data'    => array(
				'activity'      => $posts,
				'channel_count' => 1,
				'has_more'      => false,
			),
		)
	);
}

/**
 * The gateway paths asked for so far, without the host and the query.
 *
 * @return string[]
 */
function agend_cm_test_paths(): array {
	return array_map(
		static fn( array $request ): string => (string) preg_replace( '#^https?://[^/]+/v1#', '', (string) strtok( $request['url'], '?' ) ),
		Agend_Test_WP::$requests
	);
}
