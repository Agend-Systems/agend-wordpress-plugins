<?php
/**
 * Server render of the Community popular posts surface: a compact discussion
 * list of the posts members are joining in, from the allowed channels.
 *
 * Popular follows Loop's own rule for its Trending sidebar: a titled post that
 * is featured or has plenty of replies, featured first and then by replies.
 * The gateway exposes no view counts, so nothing is ranked by activity.
 *
 * Page-builder agnostic: the same markup whichever editor placed the surface.
 * Shared pieces are in community-common.php.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../surface-gate.php';
require_once __DIR__ . '/../schema/community-feed.php';
require_once __DIR__ . '/community-common.php';

/**
 * Builds the render config from the surface settings.
 *
 * @param array $s Surface settings (Elementor-shaped values: toggles are 'yes'/'').
 * @return array
 */
function agend_apps_records_community_feed_build_config( array $s ): array {
	$config = agend_apps_records_community_build_config( $s );

	$on = static function ( string $key, string $fallback = 'yes' ) use ( $s ): bool {
		return 'yes' === ( $s[ $key ] ?? $fallback );
	};

	$config['source'] = array(
		'order'           => 'recent' === ( $s['order'] ?? 'popular' ) ? 'recent' : 'popular',
		'minReplies'      => max( 1, min( 20, (int) ( $s['popular_min_replies'] ?? 5 ) ) ),
		'includeUntitled' => $on( 'include_untitled', '' ),
		'automated'       => $on( 'include_automated', '' ),
		'count'           => max( 1, min( AGEND_APPS_RECORDS_COMMUNITY_FEED_MAX, (int) ( $s['count'] ?? 5 ) ) ),
		'maxAgeDays'      => max( 0, min( 365, (int) ( $s['max_age_days'] ?? 90 ) ) ),
	);
	$config['layout'] = array(
		'mode'    => 'list' === ( $s['layout'] ?? 'cards' ) ? 'list' : 'cards',
		'desktop' => max( 1, min( 4, (int) ( $s['columns_desktop'] ?? 3 ) ) ),
		'tablet'  => max( 1, min( 3, (int) ( $s['columns_tablet'] ?? 2 ) ) ),
		'mobile'  => max( 1, min( 2, (int) ( $s['columns_mobile'] ?? 1 ) ) ),
	);
	$config['card']   = array(
		'replies'       => $on( 'show_reply_count' ),
		'channel'       => $on( 'show_channel' ),
		'date'          => $on( 'show_date' ),
		'featuredBadge' => $on( 'show_featured_badge' ),
		'excerpt'       => $on( 'show_excerpt' ),
		'excerptLength' => max( 20, min( 400, (int) ( $s['excerpt_length'] ?? 120 ) ) ),
	);

	return $config;
}

/**
 * Chooses and orders the posts to show.
 *
 * Popular: titled posts that are featured or have at least the chosen number of
 * replies, featured first, then most replies, then newest. Fewer than the count
 * is fine; the list is never padded with ordinary posts under a "popular"
 * heading. Recent: newest first, titled posts only unless chat is switched on.
 *
 * @param array $posts  Normalised posts of the allowed channels.
 * @param array $config The surface config.
 * @param int   $now    Now as a Unix time (for tests); 0 for the current time.
 * @return array
 */
function agend_apps_records_community_feed_select( array $posts, array $config, int $now = 0 ): array {
	$source  = $config['source'];
	$popular = 'popular' === $source['order'];
	$posts   = agend_apps_records_community_in_channels( $posts, $config['channels'] );

	$posts = array_values(
		array_filter(
			$posts,
			static function ( array $post ) use ( $source, $popular ): bool {
				if ( ! empty( $post['titled'] ) ) {
					return ! $popular || ! empty( $post['featured'] ) || $post['replies'] >= $source['minReplies'];
				}

				return ! $popular && $source['includeUntitled'];
			}
		)
	);

	$posts = agend_apps_records_community_within_days( $posts, $source['maxAgeDays'], false, $now );

	usort(
		$posts,
		static function ( array $a, array $b ) use ( $popular ): int {
			if ( $popular ) {
				if ( $a['featured'] !== $b['featured'] ) {
					return $a['featured'] ? -1 : 1;
				}

				if ( $a['replies'] !== $b['replies'] ) {
					return $b['replies'] <=> $a['replies'];
				}
			}

			return agend_apps_records_community_newest_first( $a, $b );
		}
	);

	return array_slice( $posts, 0, $source['count'] );
}

/**
 * Reads and chooses the posts.
 *
 * @param array $config The surface config.
 * @return array{items: array, error: bool}
 */
function agend_apps_records_community_feed_fetch( array $config ): array {
	if ( ! function_exists( 'agend_apps_loop_get_public_activity' ) ) {
		return array(
			'items' => array(),
			'error' => true,
		);
	}

	$posts = agend_apps_loop_get_public_activity(
		array(
			'channel_ids'       => $config['channels'],
			'include_automated' => $config['source']['automated'],
		)
	);

	if ( is_wp_error( $posts ) ) {
		return array(
			'items' => array(),
			'error' => true,
		);
	}

	return array(
		'items' => agend_apps_records_community_feed_select( $posts, $config ),
		'error' => false,
	);
}

/**
 * Renders the surface on the front end, returned as HTML.
 *
 * @param array $settings Surface settings (Elementor-shaped values).
 * @param array $opts     Optional. `preview` (bool): an editor is looking, and is
 *                        told where to allow channels when none are allowed.
 * @return string
 */
function agend_apps_records_render_community_feed( array $settings, array $opts = array() ): string {
	$gate = agend_apps_records_surface_gate( 'community-feed', false );
	if ( null !== $gate ) {
		return $gate;
	}

	// A list inside a card template would be read once per card.
	if ( Agend_Apps_Records_Record_Context::has() ) {
		return '';
	}

	$config = agend_apps_records_community_feed_build_config( $settings );
	$read   = agend_apps_records_community_feed_fetch( $config );

	return agend_apps_records_community_render_state(
		'feed',
		$config,
		$read,
		$opts,
		'popular' === $config['source']['order'] ? __( 'There are no popular posts right now.', 'agend-apps-core' ) : __( 'There are no posts right now.', 'agend-apps-core' ),
		__( 'Unable to load community posts.', 'agend-apps-core' ),
		static function ( array $posts ) use ( $config ): string {
			$cards = 'cards' === $config['layout']['mode'];
			$html  = '<ul class="' . ( $cards ? 'agend-community-feed__grid' : 'agend-community-feed__list' ) . '" role="list">';

			foreach ( $posts as $post ) {
				$html .= $cards ? agend_apps_records_community_feed_render_card( $post, $config ) : agend_apps_records_community_feed_render_item( $post, $config );
			}

			return $html . '</ul>';
		},
		// Only the cards sit in columns; a list is one column whatever the width.
		'cards' === $config['layout']['mode'] ? array(
			'--agend-cm-cols-desktop' => $config['layout']['desktop'],
			'--agend-cm-cols-tablet'  => $config['layout']['tablet'],
			'--agend-cm-cols-mobile'  => $config['layout']['mobile'],
		) : array()
	);
}

/**
 * A post's link text: the title, as a link to where the site sends the post, or
 * plain text when it goes nowhere.
 *
 * @param array  $post    A normalised post.
 * @param array  $config  The surface config.
 * @param string $url     Where the post goes, '' for nowhere.
 * @return string Escaped markup.
 */
function agend_apps_records_community_feed_title_html( array $post, array $config, string $url ): string {
	$title = esc_html( $post['title'] );

	if ( '' === $url ) {
		return $title;
	}

	$new_tab = $config['links']['newTab'];

	return '<a class="agend-community-feed__link exclude-agend-theme-link" href="' . esc_url( $url ) . '"' . agend_apps_records_community_target( $new_tab ) . '>' . $title . agend_apps_records_community_goes_to_html( $post['channel']['name'] ) . agend_apps_records_community_new_tab_note( $new_tab ) . '</a>';
}

/**
 * One post as a card: the channel and the Featured label, the title, the
 * excerpt, then a footer with who wrote it and when on the left and the replies
 * on the right.
 *
 * Equal height comes from the grid; the footer is pinned to the bottom of the
 * card by the stylesheet, so the footers of a row line up whatever the titles
 * and excerpts above them do. The title's link covers the whole card.
 *
 * @param array $post   A normalised post.
 * @param array $config The surface config.
 * @return string Escaped markup.
 */
function agend_apps_records_community_feed_render_card( array $post, array $config ): string {
	$card    = $config['card'];
	$url     = agend_apps_records_community_item_url( $config, $post['channel']['slug'] );
	$excerpt = $card['excerpt'] ? agend_apps_community_cut( $post['excerpt'], $card['excerptLength'] ) : '';
	$time    = $card['date'] ? agend_apps_records_community_time_html( $post['created_at'] ) : '';
	$author  = agend_apps_records_community_author_html( $post['author'], $config, $time );

	$tags = '';

	if ( $card['channel'] && '' !== $post['channel']['name'] ) {
		$tags .= '<span class="agend-community-feed__tag">' . agend_apps_records_community_icon( 'hash', 14, 1.5 ) . '<span>' . esc_html( $post['channel']['name'] ) . '</span></span>';
	}

	if ( $card['featuredBadge'] && ! empty( $post['featured'] ) ) {
		$tags .= '<span class="agend-community-feed__tag agend-community-feed__tag--flag">' . esc_html__( 'Featured', 'agend-apps-core' ) . '</span>';
	}

	// Who and when. With an author, the date sits under the name; without one the
	// date stands on its own.
	$who = '' !== $author ? $author : $time;

	$replies = '';

	if ( $card['replies'] ) {
		$replies = '<p class="agend-community-feed__replies' . ( 0 === $post['replies'] ? ' agend-community-feed__replies--none' : '' ) . '">'
			. agend_apps_records_community_icon( 'reply', 16, 1.5 )
			. '<span>' . esc_html(
				0 === $post['replies']
					? __( 'No replies yet', 'agend-apps-core' )
					: sprintf(
						/* translators: %s: number of replies. */
						_n( '%s reply', '%s replies', $post['replies'], 'agend-apps-core' ),
						number_format_i18n( $post['replies'] )
					)
			) . '</span></p>';
	}

	$html  = '<li class="agend-community-feed__item">';
	$html .= '<article class="agend-community-feed__card' . ( '' !== $url ? ' agend-community-feed__card--link' : '' ) . '">';

	if ( '' !== $tags ) {
		$html .= '<div class="agend-community-feed__tags">' . $tags . '</div>';
	}

	$html .= '<h3 class="agend-community-feed__headline">' . agend_apps_records_community_feed_title_html( $post, $config, $url ) . '</h3>';

	if ( '' !== $excerpt ) {
		$html .= '<p class="agend-community-feed__excerpt">' . esc_html( $excerpt ) . '</p>';
	}

	if ( '' !== $who || '' !== $replies ) {
		$html .= '<div class="agend-community-feed__foot">' . ( '' !== $who ? '<div class="agend-community-feed__who">' . $who . '</div>' : '' ) . $replies . '</div>';
	}

	return $html . '</article></li>';
}

/**
 * One post.
 *
 * @param array $post   A normalised post.
 * @param array $config The surface config.
 * @return string Escaped markup.
 */
function agend_apps_records_community_feed_render_item( array $post, array $config ): string {
	$card    = $config['card'];
	$url     = agend_apps_records_community_item_url( $config, $post['channel']['slug'] );
	$new_tab = $config['links']['newTab'];
	$excerpt = $card['excerpt'] ? agend_apps_community_cut( $post['excerpt'], $card['excerptLength'] ) : '';
	$author  = agend_apps_records_community_author_html( $post['author'], $config );
	$time    = $card['date'] ? agend_apps_records_community_time_html( $post['created_at'] ) : '';

	$meta = array();

	if ( $card['channel'] && '' !== $post['channel']['name'] ) {
		$meta[] = '<li class="agend-community__meta-item">' . esc_html( $post['channel']['name'] ) . '</li>';
	}

	if ( $card['featuredBadge'] && ! empty( $post['featured'] ) ) {
		$meta[] = '<li class="agend-community__meta-item agend-community__flag">' . esc_html__( 'Featured', 'agend-apps-core' ) . '</li>';
	}

	if ( '' !== $time ) {
		$meta[] = '<li class="agend-community__meta-item">' . $time . '</li>';
	}

	if ( '' !== $author ) {
		$meta[] = '<li class="agend-community__meta-item">' . $author . '</li>';
	}

	$title = esc_html( $post['title'] );

	if ( '' !== $url ) {
		$title = '<a class="agend-community-feed__link exclude-agend-theme-link" href="' . esc_url( $url ) . '"' . agend_apps_records_community_target( $new_tab ) . '>' . $title . agend_apps_records_community_goes_to_html( $post['channel']['name'] ) . agend_apps_records_community_new_tab_note( $new_tab ) . '</a>';
	}

	$html  = '<li class="agend-community-feed__item">';
	$html .= '<article class="agend-community-feed__post' . ( '' !== $url ? ' agend-community-feed__post--link' : '' ) . '">';

	if ( $card['replies'] ) {
		$html .= '<p class="agend-community-feed__replies' . ( 0 === $post['replies'] ? ' agend-community-feed__replies--none' : '' ) . '">';
		$html .= '<span class="agend-community-feed__count">' . esc_html( number_format_i18n( $post['replies'] ) ) . '</span>';
		$html .= '<span class="agend-community-feed__word">' . esc_html( _n( 'reply', 'replies', $post['replies'], 'agend-apps-core' ) ) . '</span>';
		$html .= '</p>';
	}

	$html .= '<div class="agend-community-feed__main">';
	$html .= '<h3 class="agend-community-feed__title">' . $title . '</h3>';

	if ( '' !== $excerpt ) {
		$html .= '<p class="agend-community-feed__excerpt">' . esc_html( $excerpt ) . '</p>';
	}

	if ( array() !== $meta ) {
		$html .= '<ul class="agend-community__meta" role="list">' . implode( '', $meta ) . '</ul>';
	}

	$html .= '</div>';

	if ( '' !== $url ) {
		$html .= agend_apps_records_community_chevron();
	}

	return $html . '</article></li>';
}
