<?php
/**
 * Server render of the Community channels surface: a directory of the
 * association's community spaces, each with a clear way in.
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
require_once __DIR__ . '/../schema/community-channels.php';
require_once __DIR__ . '/community-common.php';

/**
 * Builds the render config from the surface settings.
 *
 * @param array $s Surface settings (Elementor-shaped values: toggles are 'yes'/'').
 * @return array
 */
function agend_apps_records_community_channels_build_config( array $s ): array {
	$config = agend_apps_records_community_build_config( $s );

	$on = static function ( string $key, string $fallback = 'yes' ) use ( $s ): bool {
		return 'yes' === ( $s[ $key ] ?? $fallback );
	};

	$kind = (string) ( $s['channel_kind'] ?? 'all' );
	$sort = (string) ( $s['sort'] ?? 'name' );

	$config['source'] = array(
		'kind'  => in_array( $kind, array( 'feed', 'channel' ), true ) ? $kind : 'all',
		'sort'  => 'members' === $sort ? 'members' : 'name',
		'count' => max( 1, min( AGEND_APPS_RECORDS_COMMUNITY_CHANNELS_MAX, (int) ( $s['count'] ?? 8 ) ) ),
	);
	$config['layout'] = array(
		'desktop' => max( 1, min( 4, (int) ( $s['columns_desktop'] ?? 3 ) ) ),
		'tablet'  => max( 1, min( 3, (int) ( $s['columns_tablet'] ?? 2 ) ) ),
		'mobile'  => max( 1, min( 2, (int) ( $s['columns_mobile'] ?? 1 ) ) ),
	);
	$config['card']   = array(
		'description' => $on( 'show_description' ),
		'members'     => $on( 'show_members' ),
		'tile'        => 'letter' === ( $s['tile_style'] ?? 'icon' ) ? 'letter' : 'icon',
	);

	return $config;
}

/**
 * Chooses and orders the channels to show.
 *
 * @param array $channels Normalised channels of the allowed list.
 * @param array $config   The surface config.
 * @return array
 */
function agend_apps_records_community_channels_select( array $channels, array $config ): array {
	$source = $config['source'];

	$channels = array_values(
		array_filter(
			$channels,
			static function ( array $channel ) use ( $config, $source ): bool {
				if ( array() !== $config['channels'] && ! in_array( $channel['id'], $config['channels'], true ) ) {
					return false;
				}

				return 'all' === $source['kind'] || $source['kind'] === $channel['kind'];
			}
		)
	);

	usort(
		$channels,
		static function ( array $a, array $b ) use ( $source ): int {
			if ( 'members' === $source['sort'] && $a['members'] !== $b['members'] ) {
				return $b['members'] <=> $a['members'];
			}

			return strcasecmp( $a['name'], $b['name'] );
		}
	);

	return array_slice( $channels, 0, $source['count'] );
}

/**
 * Reads and chooses the channels.
 *
 * @param array $config The surface config.
 * @return array{items: array, error: bool}
 */
function agend_apps_records_community_channels_fetch( array $config ): array {
	if ( ! function_exists( 'agend_apps_loop_get_channels' ) ) {
		return array(
			'items' => array(),
			'error' => true,
		);
	}

	$channels = agend_apps_loop_get_channels();

	if ( is_wp_error( $channels ) ) {
		return array(
			'items' => array(),
			'error' => true,
		);
	}

	return array(
		'items' => agend_apps_records_community_channels_select( $channels, $config ),
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
function agend_apps_records_render_community_channels( array $settings, array $opts = array() ): string {
	$gate = agend_apps_records_surface_gate( 'community-channels', false );
	if ( null !== $gate ) {
		return $gate;
	}

	// A list inside a card template would be read once per card.
	if ( Agend_Apps_Records_Record_Context::has() ) {
		return '';
	}

	$config = agend_apps_records_community_channels_build_config( $settings );
	$read   = agend_apps_records_community_channels_fetch( $config );

	return agend_apps_records_community_render_state(
		'channels',
		$config,
		$read,
		$opts,
		__( 'There are no community channels to show yet.', 'agend-apps-core' ),
		__( 'Unable to load community channels.', 'agend-apps-core' ),
		static function ( array $channels ) use ( $config ): string {
			$html = '<ul class="agend-community-channels__grid" role="list">';

			foreach ( $channels as $channel ) {
				$html .= '<li class="agend-community-channels__item">' . agend_apps_records_community_channels_render_card( $channel, $config ) . '</li>';
			}

			return $html . '</ul>';
		},
		array(
			'--agend-cm-cols-desktop' => $config['layout']['desktop'],
			'--agend-cm-cols-tablet'  => $config['layout']['tablet'],
			'--agend-cm-cols-mobile'  => $config['layout']['mobile'],
		)
	);
}

/**
 * The small tile that leads a channel card: an icon for the kind of channel, or
 * the first letter of its name.
 *
 * Loop gives a channel no icon of its own, so the icon says what kind of space
 * it is: a hash sign for a chat channel, stacked layers for a feed channel (the
 * kind that holds posts with titles). Decoration only, so it is hidden from a
 * screen reader: the name beside it says everything.
 *
 * @param array  $channel A normalised channel.
 * @param string $style   `icon` or `letter`.
 * @return string Escaped markup.
 */
function agend_apps_records_community_channels_tile_html( array $channel, string $style ): string {
	if ( 'letter' !== $style ) {
		$icon = agend_apps_records_community_icon( 'feed' === $channel['kind'] ? 'layers' : 'hash', 20 );

		if ( '' !== $icon ) {
			return '<span class="agend-community-channels__tile agend-community-channels__tile--icon" aria-hidden="true">' . $icon . '</span>';
		}
	}

	$letter = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( mb_substr( $channel['name'], 0, 1 ) ) : strtoupper( substr( $channel['name'], 0, 1 ) );

	return '<span class="agend-community-channels__tile" aria-hidden="true">' . esc_html( $letter ) . '</span>';
}

/**
 * One channel's card.
 *
 * @param array $channel A normalised channel.
 * @param array $config  The surface config.
 * @return string Escaped markup.
 */
function agend_apps_records_community_channels_render_card( array $channel, array $config ): string {
	$card    = $config['card'];
	$url     = agend_apps_records_community_item_url( $config, $channel['slug'] );
	$new_tab = $config['links']['newTab'];
	$label   = '' !== $config['links']['cardLabel'] ? $config['links']['cardLabel'] : __( 'Open channel', 'agend-apps-core' );

	$html  = '<article class="agend-community-channels__card' . ( '' !== $url ? ' agend-community-channels__card--link' : '' ) . '">';
	$html .= '<div class="agend-community-channels__head">';
	$html .= agend_apps_records_community_channels_tile_html( $channel, $card['tile'] );
	$html .= '<h3 class="agend-community-channels__name">' . esc_html( $channel['name'] ) . '</h3>';
	$html .= '</div>';

	if ( $card['description'] && '' !== $channel['description'] ) {
		$html .= '<p class="agend-community-channels__description">' . esc_html( $channel['description'] ) . '</p>';
	}

	$foot = '';

	if ( $card['members'] && $channel['members'] > 0 ) {
		$foot .= '<span class="agend-community-channels__members">' . esc_html(
			sprintf(
				/* translators: %s: number of members. */
				_n( '%s member', '%s members', $channel['members'], 'agend-apps-core' ),
				number_format_i18n( $channel['members'] )
			)
		) . '</span>';
	}

	if ( '' !== $url ) {
		$foot .= '<a class="agend-community-channels__open exclude-agend-theme-link" href="' . esc_url( $url ) . '"' . agend_apps_records_community_target( $new_tab ) . '>' . esc_html( $label ) . '<span class="agend-community__sr">: ' . esc_html( $channel['name'] ) . '</span>' . agend_apps_records_community_new_tab_note( $new_tab ) . agend_apps_records_community_chevron() . '</a>';
	}

	if ( '' !== $foot ) {
		$html .= '<div class="agend-community-channels__foot">' . $foot . '</div>';
	}

	return $html . '</article>';
}
