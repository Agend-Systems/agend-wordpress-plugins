<?php
/**
 * What the three Community surfaces (announcements, channels, popular posts)
 * share on the server: reading the settings, the heading, links, author and
 * date markup, and the empty and error states.
 *
 * Like the learning paths surface they draw everything on the server and ship
 * no script. The posts come from the Community read wrappers
 * (`includes/api/loop-public.php`), which read as an anonymous visitor and drop
 * everything the site has not allowed, so every visitor, member or not, gets
 * the same markup and the page stays cacheable. Only the link target depends on
 * how the site signs members in, and it is fixed per channel.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Every renderer asks the surface gate before it fetches or draws anything;
// callers that load one renderer on its own (the unit tests) need it present.
require_once __DIR__ . '/../surface-gate.php';
require_once __DIR__ . '/../format.php';
require_once __DIR__ . '/../../community.php';
require_once __DIR__ . '/../../community-text.php';

/**
 * Builds the part of a render config every Community surface has.
 *
 * @param array $s Surface settings (Elementor-shaped values: toggles are 'yes'/'').
 * @return array The values the renderers read, typed and bounded.
 */
function agend_apps_records_community_build_config( array $s ): array {
	$colours = agend_apps_records_resolve_colours(
		array( 'inherit_colours' => (string) ( $s['inherit_colours'] ?? 'yes' ) ) + $s,
		array(
			'heading' => AGEND_APPS_RECORDS_COLOUR_DEFAULTS['heading'],
			'body'    => AGEND_APPS_RECORDS_COLOUR_DEFAULTS['body'],
			'accent'  => AGEND_APPS_RECORDS_COLOUR_DEFAULTS['accent'],
		)
	);

	$on = static function ( string $key, string $fallback = 'yes' ) use ( $s ): bool {
		return 'yes' === ( $s[ $key ] ?? $fallback );
	};

	$mode = (string) ( $s['author_display'] ?? 'none' );
	$mode = isset( agend_apps_community_author_modes()[ $mode ] ) ? $mode : 'none';

	$link_mode = (string) ( $s['link_mode'] ?? 'auto' );
	$link_mode = in_array( $link_mode, array( 'auto', 'custom', 'none' ), true ) ? $link_mode : 'auto';

	return array(
		'heading'  => array(
			'show'     => $on( 'show_heading' ),
			'title'    => trim( (string) ( $s['heading_text'] ?? '' ) ),
			'subtitle' => trim( (string) ( $s['subheading_text'] ?? '' ) ),
		),
		'channels' => agend_apps_community_clean_ids( $s['channels'] ?? array() ),
		'radius'   => max( 0, min( 48, (int) ( $s['card_radius'] ?? 10 ) ) ),
		'author'   => array(
			'mode'   => $mode,
			// The photo is a separate switch, and only means anything once an
			// author is shown at all.
			'avatar' => 'none' !== $mode && $on( 'show_avatar', '' ),
		),
		'links'    => array(
			'mode'      => $link_mode,
			'url'       => agend_apps_records_community_clean_url( $s['link_url'] ?? '' ),
			'newTab'    => $on( 'links_new_tab', '' ),
			'cardLabel' => trim( (string) ( $s['card_link_label'] ?? '' ) ),
		),
		'cta'      => array(
			'show'  => $on( 'show_cta' ),
			'label' => trim( (string) ( $s['cta_label'] ?? '' ) ),
			'url'   => agend_apps_records_community_clean_url( $s['cta_url'] ?? '' ),
		),
		'empty'    => array(
			'message' => trim( (string) ( $s['empty_message'] ?? '' ) ),
			'hide'    => $on( 'hide_when_empty', '' ),
		),
		'colours'  => $colours['colours'],
		'theme'    => array(
			'colourSource' => $colours['source'],
		),
	);
}

/**
 * An address an editor typed, kept only when it is one a link may point at.
 *
 * @param mixed $value A URL setting: a string, or Elementor's array shape.
 * @return string '' for anything that is not a web address or a path on this site.
 */
function agend_apps_records_community_clean_url( $value ): string {
	$url = trim( agend_apps_records_normalise_url_setting( $value ) );

	if ( '' === $url || 1 !== preg_match( '#^(?:https?://|/(?!/))#i', $url ) ) {
		return '';
	}

	return esc_url_raw( $url );
}

/**
 * The colour and layout CSS variables the stylesheet reads.
 *
 * @param array $config The surface config.
 * @param array $extra  Further `--name => value` pairs, values already safe.
 * @return string
 */
function agend_apps_records_community_inline_style( array $config, array $extra = array() ): string {
	$style = sprintf(
		'--agend-cm-heading:%1$s;--agend-cm-body:%2$s;--agend-cm-accent:%3$s;--agend-cm-card-radius:%4$dpx;',
		esc_attr( $config['colours']['heading'] ),
		esc_attr( $config['colours']['body'] ),
		esc_attr( $config['colours']['accent'] ),
		(int) $config['radius']
	);

	foreach ( $extra as $name => $value ) {
		$style .= sprintf( '%s:%s;', preg_replace( '/[^a-z0-9-]/', '', (string) $name ), (int) $value );
	}

	return $style;
}

/**
 * The address a post or channel opens, per the surface's link setting.
 *
 * @param array  $config       The surface config.
 * @param string $channel_slug The channel the item belongs to.
 * @return string '' when the item has nowhere to go.
 */
function agend_apps_records_community_item_url( array $config, string $channel_slug ): string {
	switch ( $config['links']['mode'] ) {
		case 'custom':
			return $config['links']['url'];
		case 'none':
			return '';
		default:
			return agend_apps_community_channel_link( $channel_slug );
	}
}

/**
 * The closing link to the community as a whole, or '' when it is switched off
 * or has nowhere to point.
 *
 * @param array $config The surface config.
 * @return array{url: string, label: string}|null
 */
function agend_apps_records_community_cta( array $config ): ?array {
	if ( ! $config['cta']['show'] ) {
		return null;
	}

	$url   = '' !== $config['cta']['url'] ? $config['cta']['url'] : agend_apps_community_home_link();
	$label = '' !== $config['cta']['label'] ? $config['cta']['label'] : __( 'Open the community', 'agend-apps-core' );

	return '' === $url ? null : array(
		'url'   => $url,
		'label' => $label,
	);
}

/**
 * The right-pointing chevron, drawn the same on every row and card.
 *
 * @return string SVG markup.
 */
function agend_apps_records_community_chevron(): string {
	return '<svg class="agend-community__chevron" width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M6 3.5 10.5 8 6 12.5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/></svg>';
}

/**
 * The attributes a link needs when it opens in a new tab.
 *
 * @param bool $new_tab Whether it does.
 * @return string Attributes with a leading space, or ''.
 */
function agend_apps_records_community_target( bool $new_tab ): string {
	return $new_tab ? ' target="_blank" rel="noopener"' : '';
}

/**
 * The words a screen reader hears after a link that opens a new tab.
 *
 * @param bool $new_tab Whether it does.
 * @return string Markup, or ''.
 */
function agend_apps_records_community_new_tab_note( bool $new_tab ): string {
	return $new_tab ? '<span class="agend-community__sr"> ' . esc_html__( '(opens in a new tab)', 'agend-apps-core' ) . '</span>' : '';
}

/**
 * The heading block.
 *
 * @param array $config The surface config.
 * @return string Escaped markup, or ''.
 */
function agend_apps_records_community_heading_html( array $config ): string {
	$heading = $config['heading'];

	if ( empty( $heading['show'] ) || ( '' === $heading['title'] && '' === $heading['subtitle'] ) ) {
		return '';
	}

	$html = '<div class="agend-community__heading">';

	if ( '' !== $heading['title'] ) {
		$html .= '<h2 class="agend-community__title">' . esc_html( $heading['title'] ) . '</h2>';
	}

	if ( '' !== $heading['subtitle'] ) {
		$html .= '<p class="agend-community__subtitle">' . esc_html( $heading['subtitle'] ) . '</p>';
	}

	return $html . '</div>';
}

/**
 * A status message in place of the list: nothing to show, or could not load.
 *
 * @param string $message Plain text.
 * @param bool   $error   Whether this is a failure rather than an empty list.
 * @return string Escaped markup.
 */
function agend_apps_records_community_status_html( string $message, bool $error = false ): string {
	return '<p class="agend-community__status' . ( $error ? ' agend-community__status--error' : '' ) . '" role="status">' . esc_html( $message ) . '</p>';
}

/**
 * The note an editor sees in place of a surface that has nothing it may show,
 * because the site has allowed no channels. Visitors never see it.
 *
 * @return string Escaped markup.
 */
function agend_apps_records_community_editor_hint_html(): string {
	return '<div class="agend-community__hint" role="note"><strong class="agend-community__hint-title">' . esc_html__( 'Nothing to show yet', 'agend-apps-core' ) . '</strong><span>' . esc_html( agend_apps_community_allow_channels_hint() ) . '</span></div>';
}

/**
 * The author's byline for one post, in the form the site chose: initials, a
 * first name, a name and initial, or the full name, with a photo only when the
 * site switched photos on.
 *
 * @param array $author The post's author {@see agend_apps_community_normalise_post()}.
 * @param array $config The surface config.
 * @return string Escaped markup, '' when authors are not shown.
 */
function agend_apps_records_community_author_html( array $author, array $config ): string {
	$mode  = $config['author']['mode'];
	$label = agend_apps_community_author_label( (string) ( $author['name'] ?? '' ), $mode );

	if ( '' === $label ) {
		return '';
	}

	$circle = '';

	if ( $config['author']['avatar'] ) {
		$url = (string) ( $author['avatar'] ?? '' );

		if ( '' !== $url ) {
			$circle = '<img class="agend-community__avatar" src="' . esc_url( $url ) . '" alt="" width="24" height="24" loading="lazy" decoding="async" referrerpolicy="no-referrer">';
		} elseif ( 'initials' !== $mode ) {
			$circle = '<span class="agend-community__avatar agend-community__avatar--letters" aria-hidden="true">' . esc_html( agend_apps_community_initials( agend_apps_community_pretty_name( (string) ( $author['name'] ?? '' ) ) ) ) . '</span>';
		}
	}

	return '<span class="agend-community__author">' . $circle . '<span class="agend-community__author-name">' . esc_html( $label ) . '</span></span>';
}

/**
 * A post's date as a `<time>` element.
 *
 * @param string $iso ISO 8601 timestamp.
 * @return string Escaped markup, '' when the timestamp cannot be read.
 */
function agend_apps_records_community_time_html( string $iso ): string {
	$when = agend_apps_community_when( $iso );

	if ( '' === $when['label'] ) {
		return '';
	}

	return '<time class="agend-community__time" datetime="' . esc_attr( $when['iso'] ) . '" title="' . esc_attr( $when['title'] ) . '">' . esc_html( $when['label'] ) . '</time>';
}

/**
 * Keeps the posts newer than a number of days; 0 keeps everything.
 *
 * @param array $posts       Normalised posts.
 * @param int   $days        Days.
 * @param bool  $keep_pinned Whether a pinned post stays however old it is.
 * @param int   $now         Now as a Unix time (for tests); 0 for the current time.
 * @return array
 */
function agend_apps_records_community_within_days( array $posts, int $days, bool $keep_pinned, int $now = 0 ): array {
	if ( $days <= 0 ) {
		return $posts;
	}

	$limit = ( 0 === $now ? time() : $now ) - $days * DAY_IN_SECONDS;

	return array_values(
		array_filter(
			$posts,
			static function ( array $post ) use ( $limit, $keep_pinned ): bool {
				if ( $keep_pinned && ! empty( $post['pinned'] ) ) {
					return true;
				}

				$time = strtotime( (string) ( $post['created_at'] ?? '' ) );

				return false !== $time && $time >= $limit;
			}
		)
	);
}

/**
 * Keeps the posts of the chosen channels; no choice keeps everything.
 *
 * @param array    $posts       Normalised posts.
 * @param string[] $channel_ids Chosen channels.
 * @return array
 */
function agend_apps_records_community_in_channels( array $posts, array $channel_ids ): array {
	if ( array() === $channel_ids ) {
		return $posts;
	}

	return array_values(
		array_filter(
			$posts,
			static function ( array $post ) use ( $channel_ids ): bool {
				return in_array( (string) ( $post['channel']['id'] ?? '' ), $channel_ids, true );
			}
		)
	);
}

/**
 * Sorts posts newest first; a tie keeps a stable order by id.
 *
 * @param array $a A post.
 * @param array $b A post.
 * @return int
 */
function agend_apps_records_community_newest_first( array $a, array $b ): int {
	return ( (int) strtotime( (string) $b['created_at'] ) <=> (int) strtotime( (string) $a['created_at'] ) ) ?: strcmp( (string) $b['id'], (string) $a['id'] );
}

/**
 * The screen-reader suffix that says where a post or channel link goes.
 *
 * @param string $channel_name The channel's name.
 * @return string Escaped markup.
 */
function agend_apps_records_community_goes_to_html( string $channel_name ): string {
	if ( '' === $channel_name ) {
		return '';
	}

	return '<span class="agend-community__sr"> ' . esc_html(
		sprintf(
			/* translators: %s: a channel name. */
			__( '(opens the %s channel)', 'agend-apps-core' ),
			$channel_name
		)
	) . '</span>';
}

/**
 * The closing link to the community, as markup.
 *
 * @param array $config The surface config.
 * @return string Escaped markup, '' when there is none.
 */
function agend_apps_records_community_cta_html( array $config ): string {
	$cta = agend_apps_records_community_cta( $config );

	if ( null === $cta ) {
		return '';
	}

	return '<p class="agend-community__cta"><a class="agend-community__cta-link exclude-agend-theme-link" href="' . esc_url( $cta['url'] ) . '"' . agend_apps_records_community_target( $config['links']['newTab'] ) . '>' . esc_html( $cta['label'] ) . agend_apps_records_community_new_tab_note( $config['links']['newTab'] ) . agend_apps_records_community_chevron() . '</a></p>';
}

/**
 * Wraps a surface's body in the shared container.
 *
 * @param string $surface Surface name for the modifier class, e.g. 'announcements'.
 * @param array  $config  The surface config.
 * @param string $body    Markup, already escaped.
 * @param array  $extra   Extra CSS variables {@see agend_apps_records_community_inline_style()}.
 * @return string
 */
function agend_apps_records_community_wrap( string $surface, array $config, string $body, array $extra = array() ): string {
	return '<div class="agend-community agend-community--' . esc_attr( $surface ) . '" style="' . esc_attr( agend_apps_records_community_inline_style( $config, $extra ) ) . '">'
		. agend_apps_records_community_heading_html( $config )
		. $body
		. '</div>';
}

/**
 * Draws a surface's body, or the state that stands in for it: nothing at all
 * when it is empty and hidden, the editor's note when no channel is allowed and
 * an editor is looking, a message for an empty list, a notice for a failure.
 *
 * @param string   $surface  Surface name for the modifier class.
 * @param array    $config   The surface config.
 * @param array    $read     {items: array, error: bool}.
 * @param array    $opts     Render options; `preview` marks an editor.
 * @param string   $empty    Default empty message.
 * @param string   $failed   Message when the community could not be loaded.
 * @param callable $draw     Draws the list from the items; returns escaped markup.
 * @param array    $extra    Extra CSS variables.
 * @return string
 */
function agend_apps_records_community_render_state( string $surface, array $config, array $read, array $opts, string $empty, string $failed, callable $draw, array $extra = array() ): string {
	if ( array() !== $read['items'] ) {
		return agend_apps_records_community_wrap( $surface, $config, $draw( $read['items'] ) . agend_apps_records_community_cta_html( $config ), $extra );
	}

	if ( ! $read['error'] ) {
		// Nothing allowed yet: an editor is told where to allow channels, and a
		// visitor gets the ordinary empty state below.
		if ( ! empty( $opts['preview'] ) && array() === agend_apps_community_allowed_ids() ) {
			return agend_apps_records_community_wrap( $surface, $config, agend_apps_records_community_editor_hint_html(), $extra );
		}

		if ( $config['empty']['hide'] ) {
			return '';
		}

		// An empty community still has a way in, so the link to it stays.
		return agend_apps_records_community_wrap( $surface, $config, agend_apps_records_community_status_html( '' !== $config['empty']['message'] ? $config['empty']['message'] : $empty ) . agend_apps_records_community_cta_html( $config ), $extra );
	}

	return agend_apps_records_community_wrap( $surface, $config, agend_apps_records_community_status_html( $failed, true ), $extra );
}
