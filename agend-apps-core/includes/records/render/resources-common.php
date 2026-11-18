<?php
/**
 * Helpers the three Resources surfaces (collections, search, Ask) share.
 *
 * Page-builder agnostic and free of any gateway call: colours, the few icons,
 * the plain-text and highlight helpers, and the editor note. Each surface's own
 * renderer loads this file.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../surface-gate.php';
require_once __DIR__ . '/../palette.php';
require_once __DIR__ . '/../format.php';

/**
 * Resolves the three colours every Resources surface reads.
 *
 * @param array $s Surface settings (Elementor-shaped values).
 * @return array{colours: array<string, string>, source: string}
 */
function agend_apps_records_resources_colours( array $s ): array {
	return agend_apps_records_resolve_colours(
		array( 'inherit_colours' => (string) ( $s['inherit_colours'] ?? 'yes' ) ) + $s,
		array(
			'heading' => AGEND_APPS_RECORDS_COLOUR_DEFAULTS['heading'],
			'body'    => AGEND_APPS_RECORDS_COLOUR_DEFAULTS['body'],
			'accent'  => AGEND_APPS_RECORDS_COLOUR_DEFAULTS['accent'],
		)
	);
}

/**
 * The colour and corner CSS variables the stylesheet reads, for a `style=""`
 * attribute.
 *
 * @param array $colours The `colours` of {@see agend_apps_records_resources_colours()}.
 * @param int   $radius  Card corner radius in pixels.
 * @param string $extra  More declarations, already safe, each ending in `;`.
 * @return string
 */
function agend_apps_records_resources_style( array $colours, int $radius, string $extra = '' ): string {
	return sprintf(
		'--agend-res-heading:%1$s;--agend-res-body:%2$s;--agend-res-accent:%3$s;--agend-res-radius:%4$dpx;%5$s',
		esc_attr( $colours['heading'] ),
		esc_attr( $colours['body'] ),
		esc_attr( $colours['accent'] ),
		max( 0, min( 48, $radius ) ),
		$extra
	);
}

/**
 * Whether a toggle setting is on.
 *
 * @param array  $s        Surface settings.
 * @param string $key      Setting name.
 * @param string $fallback What an absent setting counts as: 'yes' or ''.
 * @return bool
 */
function agend_apps_records_resources_on( array $s, string $key, string $fallback = 'yes' ): bool {
	return 'yes' === ( $s[ $key ] ?? $fallback );
}

/**
 * A setting that holds ids, as a clean list: the multiselect's array, or a
 * comma-separated string; anything but the characters an id is made of is
 * dropped, a repeat keeps its first place, and the list is held to `$max`.
 *
 * @param mixed $value Raw setting value.
 * @param int   $max   Most ids to keep.
 * @return string[]
 */
function agend_apps_records_resources_id_list( $value, int $max ): array {
	if ( is_string( $value ) ) {
		$value = explode( ',', $value );
	}

	if ( ! is_array( $value ) ) {
		return array();
	}

	$ids = array();

	foreach ( $value as $id ) {
		$id = is_scalar( $id ) ? preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $id ) : '';

		if ( '' !== $id && ! in_array( $id, $ids, true ) ) {
			$ids[] = $id;
		}
	}

	return array_slice( $ids, 0, max( 0, $max ) );
}

/**
 * A short list typed into one text setting: split on a separator, trimmed,
 * blanks and repeats dropped, each item held to a length, the list to a count.
 *
 * @param mixed  $value     The setting.
 * @param string $separator `,` or a newline.
 * @param int    $max       Most items.
 * @param int    $length    Most characters in one item.
 * @return string[]
 */
function agend_apps_records_resources_list( $value, string $separator, int $max, int $length = 120 ): array {
	if ( ! is_scalar( $value ) ) {
		return array();
	}

	$substr = function_exists( 'mb_substr' ) ? 'mb_substr' : 'substr';
	$items  = array();

	foreach ( explode( $separator, str_replace( "\r", '', (string) $value ) ) as $item ) {
		$item = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $item ) ) );
		$item = $substr( $item, 0, $length );

		if ( '' !== $item && ! in_array( $item, $items, true ) ) {
			$items[] = $item;
		}
	}

	return array_slice( $items, 0, max( 0, $max ) );
}

/**
 * The names of a collection's topics. The gateway sends topic objects
 * (`id`, `slug`, `name`); a plain string is accepted too.
 *
 * @param mixed $topics The collection's `topics`.
 * @return string[] Non-empty names, repeats dropped.
 */
function agend_apps_records_resources_topic_names( $topics ): array {
	if ( ! is_array( $topics ) ) {
		return array();
	}

	$names = array();

	foreach ( $topics as $topic ) {
		$name = is_array( $topic ) ? ( $topic['name'] ?? '' ) : $topic;
		$name = is_scalar( $name ) ? trim( wp_strip_all_tags( (string) $name ) ) : '';

		if ( '' !== $name && ! in_array( $name, $names, true ) ) {
			$names[] = $name;
		}
	}

	return $names;
}

/**
 * Text as plain text of at most $length characters, cut at a word.
 *
 * Whatever the gateway sends is shown inside markup of ours, so any tag in it
 * is dropped rather than trusted.
 *
 * @param mixed $text   The text.
 * @param int   $length Most characters, not counting the ellipsis.
 * @return string
 */
function agend_apps_records_resources_excerpt( $text, int $length ): string {
	$text = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( is_scalar( $text ) ? (string) $text : '' ) ) );

	$strlen = function_exists( 'mb_strlen' ) ? 'mb_strlen' : 'strlen';
	$substr = function_exists( 'mb_substr' ) ? 'mb_substr' : 'substr';

	if ( $strlen( $text ) <= $length ) {
		return $text;
	}

	$cut = $substr( $text, 0, $length );

	// Back to the last whole word, unless that would throw most of it away.
	if ( ' ' !== $substr( $text, $length, 1 ) ) {
		$whole = preg_replace( '/\s+\S*$/u', '', $cut );

		if ( null !== $whole && $strlen( $whole ) > $length / 2 ) {
			$cut = $whole;
		}
	}

	return rtrim( $cut, " \t\n\r\0\x0B.,;:-" ) . '…';
}

/**
 * Text escaped for HTML, with the words that matched a search wrapped in
 * `<mark>`.
 *
 * The text is escaped piece by piece around the matches, so nothing in it can
 * become markup. Words under two characters are not marked, and at most six
 * words are.
 *
 * @param string $text  Plain text.
 * @param string $query The words searched for.
 * @return string Safe HTML.
 */
function agend_apps_records_resources_highlight( string $text, string $query ): string {
	$strlen = function_exists( 'mb_strlen' ) ? 'mb_strlen' : 'strlen';
	$lower  = function_exists( 'mb_strtolower' ) ? 'mb_strtolower' : 'strtolower';
	$words  = array();

	foreach ( (array) preg_split( '/\s+/u', trim( $query ) ) as $word ) {
		if ( $strlen( (string) $word ) >= 2 ) {
			$words[ $lower( (string) $word ) ] = (string) $word;
		}
	}

	$words = array_slice( array_values( $words ), 0, 6 );

	if ( array() === $words ) {
		return esc_html( $text );
	}

	// Longest first, so a word is marked whole rather than by its own start.
	usort(
		$words,
		static function ( string $a, string $b ) use ( $strlen ): int {
			return $strlen( $b ) <=> $strlen( $a );
		}
	);

	$pattern = '/(' . implode( '|', array_map( static fn( string $word ): string => preg_quote( $word, '/' ), $words ) ) . ')/iu';
	$parts   = preg_split( $pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE );

	if ( false === $parts ) {
		return esc_html( $text );
	}

	$html = '';

	foreach ( $parts as $index => $part ) {
		if ( '' === $part ) {
			continue;
		}

		$html .= 1 === $index % 2 ? '<mark>' . esc_html( $part ) . '</mark>' : esc_html( $part );
	}

	return $html;
}

/**
 * A URL setting as an address a page may link to: absolute http(s), or a path
 * on this site. Anything else (a script or data scheme, a bare word) is not
 * linked.
 *
 * @param mixed $value The setting (a string, or Elementor's URL array).
 * @return string The cleaned URL, or ''.
 */
function agend_apps_records_resources_url( $value ): string {
	$url = trim( agend_apps_records_normalise_url_setting( $value ) );

	if ( 1 !== preg_match( '#^(https?://|/(?!/))#i', $url ) ) {
		return '';
	}

	return esc_url_raw( $url );
}

/**
 * The address a "sign in" button goes to: the editor's own link, else the
 * site's sign-in page returning to this one.
 *
 * @param string $custom The editor's link, or ''.
 * @return string
 */
function agend_apps_records_resources_sign_in_url( string $custom ): string {
	if ( '' !== $custom ) {
		return $custom;
	}

	return wp_login_url( home_url( add_query_arg( array() ) ) );
}

/**
 * One of the few icons the surfaces use, as inline SVG that inherits the text
 * colour and is hidden from assistive technology (the text beside it is the
 * label).
 *
 * @param string $name `stack`, `search`, `ask` or `chevron`.
 * @return string
 */
function agend_apps_records_resources_icon( string $name ): string {
	$paths = array(
		'stack'   => '<path d="M12 3 3 7.5l9 4.5 9-4.5L12 3Z"/><path d="m3 12 9 4.5 9-4.5"/><path d="m3 16.5 9 4.5 9-4.5"/>',
		'search'  => '<circle cx="11" cy="11" r="6.5"/><path d="m16 16 4.5 4.5"/>',
		'ask'     => '<path d="M4 5.5A1.5 1.5 0 0 1 5.5 4h13A1.5 1.5 0 0 1 20 5.5v9a1.5 1.5 0 0 1-1.5 1.5H10l-4.5 4v-4h0A1.5 1.5 0 0 1 4 14.5v-9Z"/><path d="M9 9h6M9 12h3.5"/>',
		'chevron' => '<path d="M6 3.5 10.5 8 6 12.5"/>',
	);

	if ( ! isset( $paths[ $name ] ) ) {
		return '';
	}

	$chevron = 'chevron' === $name;

	return sprintf(
		'<svg class="agend-res__icon agend-res__icon--%1$s" width="%2$d" height="%2$d" viewBox="%3$s" fill="none" stroke="currentColor" stroke-width="%4$s" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%5$s</svg>',
		esc_attr( $name ),
		$chevron ? 16 : 24,
		$chevron ? '0 0 16 16' : '0 0 24 24',
		$chevron ? '1.75' : '1.6',
		$paths[ $name ]
	);
}

/**
 * The heading block shared by the three surfaces.
 *
 * @param bool   $show     Whether the editor wants a heading.
 * @param string $title    Heading text.
 * @param string $subtitle Subheading text.
 * @return string Escaped HTML, or ''.
 */
function agend_apps_records_resources_heading( bool $show, string $title, string $subtitle ): string {
	if ( ! $show || ( '' === $title && '' === $subtitle ) ) {
		return '';
	}

	$html = '<div class="agend-res__heading">';

	if ( '' !== $title ) {
		$html .= '<h2 class="agend-res__title">' . esc_html( $title ) . '</h2>';
	}

	if ( '' !== $subtitle ) {
		$html .= '<p class="agend-res__subtitle">' . esc_html( $subtitle ) . '</p>';
	}

	return $html . '</div>';
}

/**
 * A dashed note only an editor sees, in the page builder's canvas: what a
 * visitor would not see, and why.
 *
 * @param string $text Plain text.
 * @return string Escaped HTML.
 */
function agend_apps_records_resources_editor_note( string $text ): string {
	return '<p class="agend-res__editor-note" role="note">' . esc_html( $text ) . '</p>';
}

/**
 * The proof, kept in a page that offers visitors live search, that the editor
 * switched it on.
 *
 * The visitor search route is open to anyone, and each uncached search costs
 * the association an embedding. So the route answers a visitor only when they
 * send this value, which only a page that was set to live search carries. It
 * is derived from the site's own secret, so it cannot be guessed, and it does
 * not expire, so a page held in a full-page cache keeps working. A site that
 * wants live search off everywhere hooks the filter
 * `agend_apps_resources_visitor_search_enabled`.
 *
 * @return string
 */
function agend_apps_records_resources_visitor_token(): string {
	return substr( hash_hmac( 'sha256', 'agend-resources-visitor-search', wp_salt( 'auth' ) ), 0, 32 );
}
