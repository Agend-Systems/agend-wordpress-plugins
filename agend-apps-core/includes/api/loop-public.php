<?php
/**
 * Loop public read API functions: the community as an anonymous visitor may
 * see it.
 *
 * Server-side PHP wrappers for the Agend gateway's read-only Loop endpoints
 * `/v1/loop/channels`, `/v1/loop/public/announcements` and
 * `/v1/loop/public/activity`. Sibling plugins MUST call these helpers rather
 * than building gateway paths or calling `agend_apps_api()` directly.
 *
 * These differ from the other wrappers in three deliberate ways, because what
 * they return is shown to the whole internet:
 *
 * 1. They fail closed. Loop's "public" channel means public to every member of
 *    the association, so each function drops everything that is not in the
 *    channel list the site allowed (`agend_apps_community_allowed_ids()`), and
 *    returns nothing when that list is empty. A channel that was renamed,
 *    archived, made private or turned into a committee since is dropped too.
 * 2. They return a normalised shape, not the gateway's response: the author's
 *    id and the post's HTML are dropped, mentions are scrubbed, and only a
 *    title and a short plain-text excerpt are kept. Nothing else is cached.
 * 3. They read as an anonymous visitor and keep a second, longer lived copy to
 *    serve when the gateway is down or the site's ceiling on uncached calls is
 *    reached, so a homepage strip does not go blank during a hiccup.
 *
 * Every function returns a list, or a WP_Error when nothing could be read and
 * no earlier copy exists.
 *
 * Member-specific reads (a member's own channels) belong in `loop.php`, and are
 * never cached for everyone.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../community-text.php';
require_once __DIR__ . '/../community.php';

/**
 * Bump when the shape of a cached entry changes, so older entries are ignored.
 */
const AGEND_APPS_COMMUNITY_CACHE_VERSION = 1;

/**
 * How long the copy served when a read fails is kept, in seconds.
 */
const AGEND_APPS_COMMUNITY_STALE_TTL = 86400;

/**
 * The post types Loop gives a titled post. A message with none of these is
 * chat, and system and bot messages never carry one.
 */
const AGEND_APPS_COMMUNITY_POST_TYPES = array( 'announcement', 'update', 'discussion', 'question', 'event', 'poll' );

/**
 * The shared system user Loop writes bot messages as.
 */
const AGEND_APPS_COMMUNITY_SYSTEM_USER = '00000000-0000-0000-0000-000000000001';

/**
 * The display name of the per-account workflow bot.
 */
const AGEND_APPS_COMMUNITY_BOT_NAME = 'Agend Workflows Bot';

/**
 * The six plain-text system messages Loop writes into a channel.
 */
const AGEND_APPS_COMMUNITY_SYSTEM_TEXT = array(
	'Members joined the channel.',
	'Members left the channel.',
	'A message was pinned.',
	'Channel created.',
	'Channel archived.',
	'Channel renamed.',
);

/**
 * One gateway channel as a surface uses it, or null for one the site must not
 * publish: not public, archived, or of a type other than a plain channel or a
 * feed (committees and direct messages stay out, and so does any type added
 * later: unknown means no).
 *
 * @param mixed $row A row of the gateway's channel list.
 * @return array{id: string, slug: string, name: string, description: string, kind: string, members: int}|null
 */
function agend_apps_community_normalise_channel( $row ): ?array {
	if ( ! is_array( $row ) ) {
		return null;
	}

	$id   = is_scalar( $row['id'] ?? null ) ? (string) $row['id'] : '';
	$slug = is_scalar( $row['slug'] ?? null ) ? (string) $row['slug'] : '';
	$kind = is_scalar( $row['channel_type'] ?? null ) ? (string) $row['channel_type'] : '';

	if ( '' === $id || '' === $slug || 'public' !== ( $row['privacy'] ?? '' ) || ! empty( $row['is_archived'] ) || ! in_array( $kind, array( 'channel', 'feed' ), true ) ) {
		return null;
	}

	$name = trim( agend_apps_community_plain( is_scalar( $row['name'] ?? null ) ? (string) $row['name'] : '' ) );

	return array(
		'id'          => $id,
		'slug'        => $slug,
		'name'        => '' !== $name ? $name : $slug,
		'description' => agend_apps_community_cut( agend_apps_community_plain( is_scalar( $row['description'] ?? null ) ? (string) $row['description'] : '' ), 300 ),
		'kind'        => $kind,
		'members'     => is_numeric( $row['member_count'] ?? null ) ? max( 0, (int) $row['member_count'] ) : 0,
	);
}

/**
 * One gateway post as a surface uses it, or null for a row that must never be
 * shown: a system message, a bot's post, a post with no words, or one with no
 * channel.
 *
 * A system message (who joined, a pinned notice) is dropped always. A post
 * written by the workflow bot or the shared system user is dropped unless the
 * caller asks for automated posts, and then it carries `automated` true so a
 * surface can tell it apart. The default is to drop, so nothing that calls this
 * without thinking about bots ever publishes one.
 *
 * Kept: a title and a short plain-text excerpt (mentions scrubbed), the counts
 * and flags, the channel, and the author's display name and avatar address,
 * which a surface shows only when the site chose to. Dropped: the author's id
 * and the post's HTML.
 *
 * @param mixed $row               A post from `/loop/public/activity` (`type`) or
 *                                 `/loop/public/announcements` (`post_type`).
 * @param bool  $include_automated Keep a bot's or the system user's post, marked `automated`.
 * @return array{id: string, kind: string, titled: bool, title: string, excerpt: string, replies: int, pinned: bool, featured: bool, automated: bool, created_at: string, channel: array{id: string, slug: string, name: string}, author: array{name: string, avatar: string}}|null
 */
function agend_apps_community_normalise_post( $row, bool $include_automated = false ): ?array {
	if ( ! is_array( $row ) ) {
		return null;
	}

	$id      = is_scalar( $row['id'] ?? null ) ? (string) $row['id'] : '';
	$channel = is_array( $row['channel'] ?? null ) ? $row['channel'] : array();
	$author  = is_array( $row['author'] ?? null ) ? $row['author'] : array();
	$content = is_scalar( $row['content'] ?? null ) ? (string) $row['content'] : '';

	$channel_id   = is_scalar( $channel['id'] ?? null ) ? (string) $channel['id'] : '';
	$channel_slug = is_scalar( $channel['slug'] ?? null ) ? (string) $channel['slug'] : '';
	$author_name  = is_scalar( $author['display_name'] ?? null ) ? trim( (string) $author['display_name'] ) : '';

	if ( '' === $id || '' === $channel_id || '' === $channel_slug ) {
		return null;
	}

	// The system messages come first and are never let through: they say who
	// joined or left, which is about members, not news.
	if ( in_array( trim( $content ), AGEND_APPS_COMMUNITY_SYSTEM_TEXT, true ) ) {
		return null;
	}

	// The workflow bot and API bots write as these. They rarely carry a post
	// type, but a workflow-authored post could, so the rule holds for every
	// kind of post. A site may choose to publish them.
	$automated = AGEND_APPS_COMMUNITY_SYSTEM_USER === ( is_scalar( $author['id'] ?? null ) ? (string) $author['id'] : '' ) || AGEND_APPS_COMMUNITY_BOT_NAME === $author_name;

	if ( $automated && ! $include_automated ) {
		return null;
	}

	$type = is_scalar( $row['post_type'] ?? null ) ? (string) $row['post_type'] : ( is_scalar( $row['type'] ?? null ) ? (string) $row['type'] : '' );
	$type = 0 === strpos( $type, 'feed_' ) ? substr( $type, 5 ) : $type;
	$kind = in_array( $type, AGEND_APPS_COMMUNITY_POST_TYPES, true ) ? $type : '';

	/**
	 * Filters whether mentions in a post's text become "a member". On by
	 * default: a mention names a member, and the page is public.
	 *
	 * @param bool $scrub Whether to scrub.
	 */
	if ( apply_filters( 'agend_apps_community_scrub_mentions', true ) ) {
		$content = agend_apps_community_scrub_mentions( $content );
	}

	$title   = agend_apps_community_title( $content );
	$excerpt = agend_apps_community_excerpt( $content, $title );

	if ( '' === $title ) {
		return null;
	}

	$created = is_scalar( $row['created_at'] ?? null ) ? (string) $row['created_at'] : '';
	$avatar  = is_scalar( $author['avatar_url'] ?? null ) ? trim( (string) $author['avatar_url'] ) : '';

	return array(
		'id'         => $id,
		'kind'       => $kind,
		'titled'     => '' !== $kind,
		'title'      => $title,
		'excerpt'    => $excerpt,
		'replies'    => is_numeric( $row['reply_count'] ?? null ) ? max( 0, (int) $row['reply_count'] ) : 0,
		'pinned'     => ! empty( $row['is_pinned'] ),
		'featured'   => ! empty( $row['is_featured'] ),
		'automated'  => $automated,
		'created_at' => false !== strtotime( $created ) ? $created : '',
		'channel'    => array(
			'id'   => $channel_id,
			'slug' => $channel_slug,
			'name' => trim( agend_apps_community_plain( is_scalar( $channel['name'] ?? null ) ? (string) $channel['name'] : '' ) ),
		),
		'author'     => array(
			'name'   => $author_name,
			'avatar' => 1 === preg_match( '#^https?://#i', $avatar ) ? esc_url_raw( $avatar ) : '',
		),
	);
}

/**
 * Takes up to $wanted uncached gateway calls from the site's per-minute
 * ceiling, and says how many it may make.
 *
 * Every Agend widget on a site shares one API key and one rate limit at the
 * gateway, and a visitor who finds the Community entries expired causes a
 * live call. Bounding those calls here keeps a burst of visitors (or a bot)
 * from spending the key's allowance and stopping the jobs board and events on
 * the same site. Over the ceiling, a surface serves its earlier copy.
 *
 * Transients are not atomic, so concurrent requests can overshoot slightly.
 *
 * @param int $wanted Calls wanted.
 * @return int Calls allowed, from 0 to $wanted.
 */
function agend_apps_community_take_calls( int $wanted ): int {
	/**
	 * Filters the most uncached Loop calls the site makes in a minute.
	 *
	 * The gateway allows a key 60 a minute across every Agend widget. A refresh
	 * of every Community surface is at most 8 calls.
	 *
	 * @param int $ceiling Calls a minute.
	 */
	$ceiling = max( 1, (int) apply_filters( 'agend_apps_community_call_ceiling', 20 ) );
	$now     = time();
	$state   = get_transient( 'agend_apps_community_calls' );

	if ( ! is_array( $state ) || $now - (int) ( $state['start'] ?? 0 ) >= 60 ) {
		$state = array(
			'start' => $now,
			'used'  => 0,
		);
	}

	$take = max( 0, min( $wanted, $ceiling - (int) $state['used'] ) );

	if ( $take > 0 ) {
		$state['used'] = (int) $state['used'] + $take;
		set_transient( 'agend_apps_community_calls', $state, 60 );
	}

	return $take;
}

/**
 * Reads several Loop endpoints as an anonymous visitor, each served from its
 * own stored copy where there is one.
 *
 * Order of preference per read: the fresh copy; a live read, if the ceiling
 * allows (sent together with the other live reads); the longer lived copy,
 * when the live read failed or was not allowed; otherwise the error.
 *
 * @param array<string, array{key: string, path: string, query: array, params: array, normalise: callable}> $reads
 *        Keyed by caller id. `key` is a registered cache key, `params` what
 *        distinguishes the entry (the allowed list is added here), and
 *        `normalise` turns the gateway response into the list to keep, or a
 *        WP_Error for a response that is not what was expected.
 * @return array<string, array|WP_Error> Keyed and ordered as given.
 */
function agend_apps_community_read_many( array $reads ): array {
	$names = array();
	$out   = array();
	$miss  = array();

	foreach ( $reads as $id => $read ) {
		$names[ $id ] = 'agend_apps_' . Agend_Apps_Cache::build_key(
			(string) $read['key'],
			(array) $read['params'] + array(
				'allowed' => agend_apps_community_allowlist_hash(),
				'version' => AGEND_APPS_COMMUNITY_CACHE_VERSION,
			)
		);

		$fresh = get_transient( $names[ $id ] );

		if ( false !== $fresh && is_array( $fresh ) ) {
			$out[ $id ] = $fresh;
		} else {
			$miss[ $id ] = $read;
		}
	}

	$responses = array();

	if ( array() !== $miss ) {
		$go    = array_slice( $miss, 0, agend_apps_community_take_calls( count( $miss ) ), true );
		$batch = array();

		foreach ( $go as $id => $read ) {
			$batch[ $id ] = array(
				'path'      => (string) $read['path'],
				'args'      => array(
					'query'     => (array) $read['query'],
					'anonymous' => true,
				),
				'cache_key' => $names[ $id ],
				// 0: the raw response is never stored. Only the normalised list is,
				// below, and without the author ids and HTML the response carries.
				'ttl'       => 0,
			);
		}

		if ( array() !== $batch ) {
			$responses = agend_apps_api()->get_cached_many( $batch );
		}
	}

	foreach ( $miss as $id => $read ) {
		if ( ! isset( $responses[ $id ] ) ) {
			$result = new WP_Error( 'agend_apps_community_busy', __( 'The community could not be refreshed just now.', 'agend-apps-core' ) );
		} elseif ( is_wp_error( $responses[ $id ] ) ) {
			$result = $responses[ $id ];
		} else {
			$result = ( $read['normalise'] )( $responses[ $id ] );
		}

		if ( ! is_wp_error( $result ) ) {
			$ttl = Agend_Apps_Settings::get_cache_ttl( (string) $read['key'] );

			if ( $ttl > 0 ) {
				set_transient( $names[ $id ], $result, $ttl );
			}

			set_transient( $names[ $id ] . '_stale', $result, AGEND_APPS_COMMUNITY_STALE_TTL );
			$out[ $id ] = $result;
			continue;
		}

		$stale      = get_transient( $names[ $id ] . '_stale' );
		$out[ $id ] = ( false !== $stale && is_array( $stale ) ) ? $stale : $result;
	}

	$ordered = array();

	foreach ( array_keys( $reads ) as $id ) {
		$ordered[ $id ] = $out[ $id ];
	}

	return $ordered;
}

/**
 * Drops the stored Community copies, fresh and longer lived, so the next view
 * reads the community again. The way to take a deleted or moderated post off a
 * site at once: Loop sends nothing when a post is removed.
 *
 * @return void
 */
function agend_apps_community_clear_cache(): void {
	foreach ( array( 'loop_channels', 'loop_public_announcements', 'loop_public_activity' ) as $key ) {
		Agend_Apps_Cache::clear( $key );
	}

	delete_transient( 'agend_apps_community_calls' );
}

/**
 * The channels this site publishes: the allowed ones that still exist, are
 * public and are plain channels or feeds.
 *
 * Fails closed: an empty list when nothing is allowed, and an id that no longer
 * resolves is dropped silently.
 *
 * @return array<int, array{id: string, slug: string, name: string, description: string, kind: string, members: int}>|WP_Error
 */
function agend_apps_loop_get_channels() {
	$allowed = agend_apps_community_allowed_ids();

	if ( array() === $allowed ) {
		return array();
	}

	$read = agend_apps_community_read_many(
		array(
			'channels' => array(
				'key'       => 'loop_channels',
				'path'      => '/loop/channels',
				'query'     => array(
					'limit'   => 100,
					'privacy' => 'public',
				),
				'params'    => array(),
				'normalise' => static function ( $response ) use ( $allowed ) {
					$rows = is_array( $response ) && is_array( $response['data'] ?? null ) ? $response['data'] : null;

					if ( null === $rows ) {
						return new WP_Error( 'agend_apps_community_bad_response', __( 'The community channels could not be read.', 'agend-apps-core' ) );
					}

					$channels = array();

					foreach ( $rows as $row ) {
						$channel = agend_apps_community_normalise_channel( $row );

						if ( null !== $channel && in_array( $channel['id'], $allowed, true ) ) {
							$channels[] = $channel;
						}
					}

					return $channels;
				},
			),
		)
	)['channels'];

	if ( is_wp_error( $read ) ) {
		return $read;
	}

	// Once more on the way out: whatever was stored, only an allowed channel
	// leaves this function.
	return array_values(
		array_filter(
			$read,
			static function ( $channel ) use ( $allowed ): bool {
				return is_array( $channel ) && in_array( (string) ( $channel['id'] ?? '' ), $allowed, true );
			}
		)
	);
}

/**
 * Every public channel an administrator could allow, for the settings screen.
 *
 * Not filtered by the allowed list, because choosing from it is the point, so
 * it is for administrators only and is never stored.
 *
 * @return array<int, array{id: string, slug: string, name: string, description: string, kind: string, members: int}>|WP_Error
 */
function agend_apps_community_admin_channel_choices() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return array();
	}

	$response = agend_apps_api()->get_cached(
		'/loop/channels',
		array(
			'query'     => array(
				'limit'   => 100,
				'privacy' => 'public',
			),
			'anonymous' => true,
		),
		'loop_channels_choices',
		0,
		true
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	if ( ! is_array( $response['data'] ?? null ) ) {
		return new WP_Error( 'agend_apps_community_bad_response', __( 'The community channels could not be read.', 'agend-apps-core' ) );
	}

	$channels = array();

	foreach ( $response['data'] as $row ) {
		$channel = agend_apps_community_normalise_channel( $row );

		if ( null !== $channel ) {
			$channels[] = $channel;
		}
	}

	usort(
		$channels,
		static function ( array $a, array $b ): int {
			return strcasecmp( $a['name'], $b['name'] );
		}
	);

	return $channels;
}

/**
 * Keeps the posts of the channels in $by_id, labelled with the channel's
 * current name and slug, and not on the hide list.
 *
 * An automated post leaves only when the caller asked for them, even if a
 * stored copy somehow holds one: the stored copies are kept apart per mode, and
 * this is the second lock on the same door.
 *
 * @param array $posts             The normalised posts.
 * @param array $by_id             Channels keyed by id.
 * @param bool  $include_automated Whether automated posts may leave.
 * @return array
 */
function agend_apps_community_keep_posts( array $posts, array $by_id, bool $include_automated = false ): array {
	$kept = array();

	foreach ( $posts as $post ) {
		$channel_id = is_array( $post ) ? (string) ( $post['channel']['id'] ?? '' ) : '';

		if ( '' === $channel_id || ! isset( $by_id[ $channel_id ] ) || agend_apps_community_post_hidden( $post ) ) {
			continue;
		}

		if ( ! $include_automated && ! empty( $post['automated'] ) ) {
			continue;
		}

		$post['channel'] = array(
			'id'   => $channel_id,
			'slug' => $by_id[ $channel_id ]['slug'],
			'name' => $by_id[ $channel_id ]['name'],
		);
		$kept[]          = $post;
	}

	return $kept;
}

/**
 * The titled posts of the allowed channels, pinned first and then newest, from
 * one read of the community's announcements.
 *
 * The gateway sends every top-level post of every public channel, pinned first,
 * and accepts no filter, so the choosing is done here. Because pinned posts come
 * first, they are always in the window it returns.
 *
 * @param array $args {
 *     Optional.
 *
 *     @type bool $include_automated Also return posts written by the workflow bot
 *                                   or the shared system user. System messages
 *                                   stay out whatever this says. Default false.
 * }
 * @return array<int, array>|WP_Error Normalised posts {@see agend_apps_community_normalise_post()}.
 */
function agend_apps_loop_get_public_announcements( array $args = array() ) {
	$automated = ! empty( $args['include_automated'] );
	$channels  = agend_apps_loop_get_channels();

	if ( is_wp_error( $channels ) ) {
		return $channels;
	}

	if ( array() === $channels ) {
		return array();
	}

	$by_id = array_column( $channels, null, 'id' );

	$read = agend_apps_community_read_many(
		array(
			'announcements' => array(
				'key'       => 'loop_public_announcements',
				'path'      => '/loop/public/announcements',
				'query'     => array( 'limit' => 100 ),
				// The two modes are kept apart: the default copy never holds an
				// automated post. The key is unchanged for the default mode, so
				// copies stored before this existed stay valid.
				'params'    => $automated ? array( 'automated' => 1 ) : array(),
				'normalise' => static function ( $response ) use ( $by_id, $automated ) {
					$rows = is_array( $response ) && is_array( $response['data']['announcements'] ?? null ) ? $response['data']['announcements'] : null;

					if ( null === $rows ) {
						return new WP_Error( 'agend_apps_community_bad_response', __( 'The community announcements could not be read.', 'agend-apps-core' ) );
					}

					$posts = array();

					foreach ( $rows as $row ) {
						$post = agend_apps_community_normalise_post( $row, $automated );

						// Chat is not an announcement: only titled posts are kept.
						if ( null !== $post && $post['titled'] && isset( $by_id[ $post['channel']['id'] ] ) ) {
							$posts[] = $post;
						}
					}

					return $posts;
				},
			),
		)
	)['announcements'];

	if ( is_wp_error( $read ) ) {
		return $read;
	}

	return agend_apps_community_keep_posts( $read, $by_id, $automated );
}

/**
 * Recent top-level posts from the allowed channels, newest first.
 *
 * One read per channel (at most
 * {@see AGEND_APPS_COMMUNITY_MAX_CHANNEL_READS}), sent together, because one
 * read across every channel returns only the newest 100 posts and a chatty
 * channel would crowd the others out of it.
 *
 * @param array $args {
 *     Optional.
 *
 *     @type string[] $channel_ids Allowed channels to read; empty for all.
 *                                 An id that is not allowed is ignored.
 *     @type int      $limit       Posts per channel, 1 to 100. Default 50.
 *     @type bool     $include_automated Also return posts written by the workflow
 *                                   bot or the shared system user. System
 *                                   messages stay out whatever this says.
 *                                   Default false.
 * }
 * @return array<int, array>|WP_Error Normalised posts, titled or not.
 */
function agend_apps_loop_get_public_activity( array $args = array() ) {
	$channels = agend_apps_loop_get_channels();

	if ( is_wp_error( $channels ) ) {
		return $channels;
	}

	$wanted    = agend_apps_community_clean_ids( $args['channel_ids'] ?? array() );
	$limit     = max( 1, min( 100, (int) ( $args['limit'] ?? 50 ) ) );
	$automated = ! empty( $args['include_automated'] );
	$chosen    = array();

	foreach ( $channels as $channel ) {
		if ( array() === $wanted || in_array( $channel['id'], $wanted, true ) ) {
			$chosen[ $channel['id'] ] = $channel;
		}
	}

	$chosen = array_slice( $chosen, 0, AGEND_APPS_COMMUNITY_MAX_CHANNEL_READS, true );

	if ( array() === $chosen ) {
		return array();
	}

	$reads = array();

	foreach ( $chosen as $id => $channel ) {
		$reads[ $id ] = array(
			'key'       => 'loop_public_activity',
			'path'      => '/loop/public/activity',
			'query'     => array(
				'limit'        => $limit,
				'channel_slug' => $channel['slug'],
			),
			// The two modes are kept apart, as for the announcements.
			'params'    => array(
				'channel' => $id,
				'slug'    => $channel['slug'],
				'limit'   => $limit,
			) + ( $automated ? array( 'automated' => 1 ) : array() ),
			'normalise' => static function ( $response ) use ( $id, $automated ) {
				$rows = is_array( $response ) && is_array( $response['data']['activity'] ?? null ) ? $response['data']['activity'] : null;

				if ( null === $rows ) {
					return new WP_Error( 'agend_apps_community_bad_response', __( 'The community posts could not be read.', 'agend-apps-core' ) );
				}

				$posts = array();

				foreach ( $rows as $row ) {
					$post = agend_apps_community_normalise_post( $row, $automated );

					// Only this channel's posts: the read was for one.
					if ( null !== $post && $post['channel']['id'] === $id ) {
						$posts[] = $post;
					}
				}

				return $posts;
			},
		);
	}

	$results = agend_apps_community_read_many( $reads );
	$posts   = array();
	$failed  = 0;
	$error   = null;

	foreach ( $results as $id => $result ) {
		if ( is_wp_error( $result ) ) {
			++$failed;
			$error = $result;
			continue;
		}

		$posts = array_merge( $posts, agend_apps_community_keep_posts( $result, array( $id => $chosen[ $id ] ), $automated ) );
	}

	// A channel that could not be read leaves a gap; only when none could be
	// read is it an error.
	if ( $failed === count( $results ) && null !== $error ) {
		return $error;
	}

	$seen   = array();
	$unique = array();

	foreach ( $posts as $post ) {
		if ( ! isset( $seen[ $post['id'] ] ) ) {
			$seen[ $post['id'] ] = true;
			$unique[]            = $post;
		}
	}

	usort(
		$unique,
		static function ( array $a, array $b ): int {
			return ( strtotime( $b['created_at'] ) <=> strtotime( $a['created_at'] ) ) ?: strcmp( $b['id'], $a['id'] );
		}
	);

	return $unique;
}
