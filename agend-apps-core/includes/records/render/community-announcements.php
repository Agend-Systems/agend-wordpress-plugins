<?php
/**
 * Server render of the Community announcements surface: a notice list of the
 * association's announcements from Agend Loop, pinned first.
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
require_once __DIR__ . '/../schema/community-announcements.php';
require_once __DIR__ . '/community-common.php';

/**
 * Builds the render config from the surface settings.
 *
 * @param array $s Surface settings (Elementor-shaped values: toggles are 'yes'/'').
 * @return array
 */
function agend_apps_records_community_announcements_build_config( array $s ): array {
	$config = agend_apps_records_community_build_config( $s );

	$on = static function ( string $key, string $fallback = 'yes' ) use ( $s ): bool {
		return 'yes' === ( $s[ $key ] ?? $fallback );
	};

	$config['source'] = array(
		'posts'        => 'titled' === ( $s['post_source'] ?? 'announcements' ) ? 'titled' : 'announcements',
		'pinnedOnly'   => $on( 'pinned_only', '' ),
		'featuredOnly' => $on( 'featured_only', '' ),
		'pinnedFirst'  => $on( 'pinned_first' ),
		'count'        => max( 1, min( AGEND_APPS_RECORDS_COMMUNITY_ANNOUNCEMENTS_MAX, (int) ( $s['count'] ?? 5 ) ) ),
		'maxAgeDays'   => max( 0, min( 365, (int) ( $s['max_age_days'] ?? 90 ) ) ),
	);
	$config['card']   = array(
		'date'          => $on( 'show_date' ),
		'channel'       => $on( 'show_channel' ),
		'pinnedBadge'   => $on( 'show_pinned_badge' ),
		'excerpt'       => $on( 'show_excerpt' ),
		'excerptLength' => max( 20, min( 400, (int) ( $s['excerpt_length'] ?? 160 ) ) ),
	);

	return $config;
}

/**
 * Chooses and orders the announcements to show from the allowed channels'
 * titled posts.
 *
 * @param array $posts  Normalised posts of the allowed channels.
 * @param array $config The surface config.
 * @param int   $now    Now as a Unix time (for tests); 0 for the current time.
 * @return array
 */
function agend_apps_records_community_announcements_select( array $posts, array $config, int $now = 0 ): array {
	$source = $config['source'];
	$posts  = agend_apps_records_community_in_channels( $posts, $config['channels'] );

	$posts = array_values(
		array_filter(
			$posts,
			static function ( array $post ) use ( $source ): bool {
				if ( 'titled' === $source['posts'] ? empty( $post['titled'] ) : 'announcement' !== $post['kind'] ) {
					return false;
				}

				return ( ! $source['pinnedOnly'] || ! empty( $post['pinned'] ) ) && ( ! $source['featuredOnly'] || ! empty( $post['featured'] ) );
			}
		)
	);

	// A pinned post is pinned to stay: the age limit does not take it down.
	$posts = agend_apps_records_community_within_days( $posts, $source['maxAgeDays'], true, $now );

	usort(
		$posts,
		static function ( array $a, array $b ) use ( $source ): int {
			if ( $source['pinnedFirst'] && $a['pinned'] !== $b['pinned'] ) {
				return $a['pinned'] ? -1 : 1;
			}

			return agend_apps_records_community_newest_first( $a, $b );
		}
	);

	return array_slice( $posts, 0, $source['count'] );
}

/**
 * Reads and chooses the announcements.
 *
 * @param array $config The surface config.
 * @return array{items: array, error: bool}
 */
function agend_apps_records_community_announcements_fetch( array $config ): array {
	if ( ! function_exists( 'agend_apps_loop_get_public_announcements' ) ) {
		return array(
			'items' => array(),
			'error' => true,
		);
	}

	$posts = agend_apps_loop_get_public_announcements();

	if ( is_wp_error( $posts ) ) {
		return array(
			'items' => array(),
			'error' => true,
		);
	}

	return array(
		'items' => agend_apps_records_community_announcements_select( $posts, $config ),
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
function agend_apps_records_render_community_announcements( array $settings, array $opts = array() ): string {
	$gate = agend_apps_records_surface_gate( 'community-announcements', false );
	if ( null !== $gate ) {
		return $gate;
	}

	// A list inside a card template would be read once per card.
	if ( Agend_Apps_Records_Record_Context::has() ) {
		return '';
	}

	$config = agend_apps_records_community_announcements_build_config( $settings );
	$read   = agend_apps_records_community_announcements_fetch( $config );

	return agend_apps_records_community_render_state(
		'announcements',
		$config,
		$read,
		$opts,
		__( 'There are no announcements right now.', 'agend-apps-core' ),
		__( 'Unable to load announcements.', 'agend-apps-core' ),
		static function ( array $posts ) use ( $config ): string {
			$html = '<ul class="agend-community-announcements__list" role="list">';

			foreach ( $posts as $post ) {
				$html .= agend_apps_records_community_announcements_render_item( $post, $config );
			}

			return $html . '</ul>';
		}
	);
}

/**
 * One announcement.
 *
 * @param array $post   A normalised post.
 * @param array $config The surface config.
 * @return string Escaped markup.
 */
function agend_apps_records_community_announcements_render_item( array $post, array $config ): string {
	$card    = $config['card'];
	$url     = agend_apps_records_community_item_url( $config, $post['channel']['slug'] );
	$pinned  = ! empty( $post['pinned'] );
	$excerpt = $card['excerpt'] ? agend_apps_community_cut( $post['excerpt'], $card['excerptLength'] ) : '';
	$when    = $card['date'] ? agend_apps_community_when( $post['created_at'] ) : array( 'label' => '' );
	$author  = agend_apps_records_community_author_html( $post['author'], $config );
	$new_tab = $config['links']['newTab'];
	$classes = 'agend-community-announcements__notice' . ( '' !== $url ? ' agend-community-announcements__notice--link' : '' );

	$meta = array();

	if ( $card['channel'] && '' !== $post['channel']['name'] ) {
		$meta[] = '<li class="agend-community__meta-item">' . esc_html( $post['channel']['name'] ) . '</li>';
	}

	if ( $pinned && $card['pinnedBadge'] ) {
		$meta[] = '<li class="agend-community__meta-item agend-community__flag">' . esc_html__( 'Pinned', 'agend-apps-core' ) . '</li>';
	}

	$title = esc_html( $post['title'] );

	if ( '' !== $url ) {
		$title = '<a class="agend-community-announcements__link exclude-agend-theme-link" href="' . esc_url( $url ) . '"' . agend_apps_records_community_target( $new_tab ) . '>' . $title . agend_apps_records_community_goes_to_html( $post['channel']['name'] ) . agend_apps_records_community_new_tab_note( $new_tab ) . '</a>';
	}

	$html  = '<li class="agend-community-announcements__item' . ( $pinned ? ' agend-community-announcements__item--pinned' : '' ) . '">';
	$html .= '<article class="' . esc_attr( $classes ) . '">';

	if ( '' !== $when['label'] ) {
		$time   = strtotime( $post['created_at'] );
		$year   = (string) wp_date( 'Y', (int) $time );
		$html  .= '<time class="agend-community-announcements__date" datetime="' . esc_attr( $when['iso'] ) . '" title="' . esc_attr( $when['title'] ) . '">';
		$html  .= '<span class="agend-community-announcements__day">' . esc_html( (string) wp_date( 'j', (int) $time ) ) . '</span>';
		$html  .= '<span class="agend-community-announcements__month">' . esc_html( (string) wp_date( 'M', (int) $time ) ) . '</span>';
		$html  .= $year !== (string) wp_date( 'Y' ) ? '<span class="agend-community-announcements__year">' . esc_html( $year ) . '</span>' : '';
		$html  .= '</time>';
	}

	$html .= '<div class="agend-community-announcements__body">';

	if ( array() !== $meta ) {
		$html .= '<ul class="agend-community__meta" role="list">' . implode( '', $meta ) . '</ul>';
	}

	$html .= '<h3 class="agend-community-announcements__headline">' . $title . '</h3>';

	if ( '' !== $excerpt ) {
		$html .= '<p class="agend-community-announcements__excerpt">' . esc_html( $excerpt ) . '</p>';
	}

	if ( '' !== $author ) {
		$html .= '<p class="agend-community-announcements__byline">' . $author . '</p>';
	}

	$html .= '</div>';

	if ( '' !== $url ) {
		$html .= agend_apps_records_community_chevron();
	}

	return $html . '</article></li>';
}
