<?php
/**
 * Plain-text helpers for the Community surfaces: turning a member's Loop post
 * into a title and a short excerpt that is safe to show to an anonymous
 * visitor, and turning an author's display name into the form a site chose to
 * show.
 *
 * Everything here is a pure function of its arguments, so the privacy rules
 * (what is scrubbed, what is cut) are pinned by unit tests rather than read
 * out of a renderer.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What a mention becomes on a public page.
 *
 * @return string
 */
function agend_apps_community_member_word(): string {
	return __( 'a member', 'agend-apps-core' );
}

/**
 * Replaces every mention in a post's text with "a member".
 *
 * Loop stores a picked mention as `@[uuid]`, and a person can also type
 * `@handle` by hand. Both name a member, and a post on a public page must not.
 * A mention of everyone in the channel (`@everyone`, `@channel`, `@here`,
 * `@all`) names nobody and reads as "everyone".
 *
 * Names written out in the prose of a post cannot be found reliably and are
 * left alone: that is what the channel allowlist is the consent step for.
 *
 * @param string $text A post's markdown.
 * @return string
 */
function agend_apps_community_scrub_mentions( string $text ): string {
	$text = (string) preg_replace( '/@\[[0-9a-f-]{36}\]/i', agend_apps_community_member_word(), $text );
	$text = (string) preg_replace( '/(?<![\p{L}\p{N}_.@])@(?:everyone|channel|here|all)(?![\p{L}\p{N}_])/iu', __( 'everyone', 'agend-apps-core' ), $text );

	// A handle is letters, numbers and ._-; the lookbehind keeps an email
	// address (name@example.com) out of it.
	return (string) preg_replace( '/(?<![\p{L}\p{N}_.@])@[\p{L}\p{N}_](?:[\p{L}\p{N}._-]*[\p{L}\p{N}_])?/u', agend_apps_community_member_word(), $text );
}

/**
 * A member's markdown as one line of plain text.
 *
 * No markdown, no HTML, no links and no web addresses survive: a visitor's
 * page shows words, never markup a member wrote.
 *
 * @param string $markdown Markdown or HTML.
 * @return string
 */
function agend_apps_community_plain( string $markdown ): string {
	$text = (string) preg_replace( '/```.*?```/s', ' ', $markdown );
	$text = (string) wp_strip_all_tags( $text );
	$text = (string) preg_replace( '/!\[[^\]]*\]\([^)]*\)/', '', $text );
	$text = (string) preg_replace( '/\[([^\]]*)\]\([^)]*\)/', '$1', $text );
	$text = (string) preg_replace( '#\b(?:https?://|www\.)\S+#i', '', $text );
	$text = (string) preg_replace( '/^\s{0,3}(?:#{1,6}|>|[-*+]|\d+[.)])\s+/m', '', $text );
	$text = str_replace( array( '**', '__', '`', '~~' ), '', $text );
	$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$text = (string) wp_strip_all_tags( $text );

	return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
}

/**
 * Text cut to a length at a whole word, with an ellipsis.
 *
 * @param string $text   Plain text.
 * @param int    $length Most characters, not counting the ellipsis.
 * @return string
 */
function agend_apps_community_cut( string $text, int $length ): string {
	$length = max( 1, $length );
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
 * A post's title. Loop messages have no title column: the title is the first
 * markdown heading, or else the first sentence.
 *
 * @param string $markdown The post's markdown, mentions already scrubbed.
 * @param int    $length   Most characters.
 * @return string Plain text, or '' for a post with no words in it.
 */
function agend_apps_community_title( string $markdown, int $length = 140 ): string {
	if ( 1 === preg_match( '/^\s{0,3}#{1,6}\s+(.+)$/m', $markdown, $match ) ) {
		$title = agend_apps_community_plain( $match[1] );

		if ( '' !== $title ) {
			return agend_apps_community_cut( $title, $length );
		}
	}

	$plain = agend_apps_community_plain( $markdown );

	if ( 1 === preg_match( '/^(.{12,}?[.!?])(?:\s|$)/u', $plain, $match ) ) {
		return agend_apps_community_cut( $match[1], $length );
	}

	return agend_apps_community_cut( $plain, $length );
}

/**
 * The body of a post, without its heading, as plain text.
 *
 * Posts with no heading give their whole text, which then starts with the
 * sentence the title was taken from; the title is left out of the excerpt.
 *
 * @param string $markdown The post's markdown, mentions already scrubbed.
 * @param string $title    The title taken from it.
 * @param int    $length   Most characters.
 * @return string
 */
function agend_apps_community_excerpt( string $markdown, string $title, int $length = 400 ): string {
	$has_heading = 1 === preg_match( '/^\s{0,3}#{1,6}\s+.+$/m', $markdown );
	$body        = $has_heading ? (string) preg_replace( '/^\s{0,3}#{1,6}\s+.+$/m', '', $markdown, 1 ) : $markdown;
	$plain       = agend_apps_community_plain( $body );

	if ( ! $has_heading && '' !== $title ) {
		$stem = rtrim( $title, '…' );

		if ( '' !== $stem && 0 === strpos( $plain, $stem ) ) {
			$plain = ltrim( substr( $plain, strlen( $stem ) ) );
		}
	}

	return agend_apps_community_cut( $plain, $length );
}

/**
 * A display name as people write names: "ben.thompson" becomes "Ben Thompson".
 *
 * Loop display names are often a handle that is somebody's real name. A name
 * that already has capitals or spaces is left as it is, and an email address
 * is reduced to the part before the @ rather than printed.
 *
 * @param string $name Display name or handle.
 * @return string '' when there is no name.
 */
function agend_apps_community_pretty_name( string $name ): string {
	$name = trim( $name );

	if ( false !== strpos( $name, '@' ) ) {
		$name = (string) strstr( $name, '@', true );
	}

	if ( '' === $name ) {
		return '';
	}

	if ( 1 === preg_match( '/^[\p{L}\p{N}]+(?:[._-][\p{L}\p{N}]+)*$/u', $name ) ) {
		$name = (string) preg_replace( '/[._-]+/', ' ', $name );
	}

	$upper  = function_exists( 'mb_strtoupper' ) ? 'mb_strtoupper' : 'strtoupper';
	$substr = function_exists( 'mb_substr' ) ? 'mb_substr' : 'substr';
	$parts  = array();

	foreach ( preg_split( '/\s+/u', $name ) ?: array() as $part ) {
		if ( '' === $part ) {
			continue;
		}

		// Only an all-lowercase word is capitalised: "McDonald" stays.
		if ( $part === ( function_exists( 'mb_strtolower' ) ? mb_strtolower( $part ) : strtolower( $part ) ) ) {
			$part = $upper( $substr( $part, 0, 1 ) ) . $substr( $part, 1 );
		}

		$parts[] = $part;
	}

	return implode( ' ', $parts );
}

/**
 * The author display modes, in the order an editor sees them.
 *
 * @return array<string, string> Mode => label.
 */
function agend_apps_community_author_modes(): array {
	return array(
		'none'               => __( 'Do not show the author', 'agend-apps-core' ),
		'initials'           => __( 'Initials only, such as BT', 'agend-apps-core' ),
		'first_name'         => __( 'First name only, such as Ben', 'agend-apps-core' ),
		'first_name_initial' => __( 'First name and last initial, such as Ben T.', 'agend-apps-core' ),
		'full'               => __( 'Full name, such as Ben Thompson', 'agend-apps-core' ),
	);
}

/**
 * An author's name in the form a site chose to show.
 *
 * @param string $name Display name from the gateway.
 * @param string $mode One of {@see agend_apps_community_author_modes()}.
 * @return string '' for the `none` mode and for an unknown mode (fail closed);
 *                "a member" when the name is empty.
 */
function agend_apps_community_author_label( string $name, string $mode ): string {
	if ( ! isset( agend_apps_community_author_modes()[ $mode ] ) || 'none' === $mode ) {
		return '';
	}

	$pretty = agend_apps_community_pretty_name( $name );

	if ( '' === $pretty ) {
		return ucfirst( agend_apps_community_member_word() );
	}

	$parts = preg_split( '/\s+/u', $pretty ) ?: array( $pretty );

	if ( 'initials' === $mode ) {
		return agend_apps_community_initials( $pretty );
	}

	if ( 'first_name' === $mode ) {
		return $parts[0];
	}

	if ( 'first_name_initial' === $mode ) {
		if ( count( $parts ) < 2 ) {
			return $parts[0];
		}

		$last = (string) end( $parts );

		return $parts[0] . ' ' . ( function_exists( 'mb_strtoupper' ) ? mb_strtoupper( mb_substr( $last, 0, 1 ) ) : strtoupper( substr( $last, 0, 1 ) ) ) . '.';
	}

	return $pretty;
}

/**
 * Up to two capital letters for a name: the first letters of its first and last
 * words.
 *
 * @param string $name A name.
 * @return string
 */
function agend_apps_community_initials( string $name ): string {
	$parts = array_values( array_filter( preg_split( '/\s+/u', trim( $name ) ) ?: array(), 'strlen' ) );

	if ( array() === $parts ) {
		return '';
	}

	$first = function_exists( 'mb_substr' ) ? mb_substr( $parts[0], 0, 1 ) : substr( $parts[0], 0, 1 );
	$last  = count( $parts ) > 1 ? ( function_exists( 'mb_substr' ) ? mb_substr( (string) end( $parts ), 0, 1 ) : substr( (string) end( $parts ), 0, 1 ) ) : '';
	$both  = $first . $last;

	return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $both ) : strtoupper( $both );
}

/**
 * A post's date as a visitor reads it: relative for the last month ("3 days
 * ago"), then the date, so a page kept in a page cache never says "2 hours ago"
 * about something from last week.
 *
 * @param string $iso   ISO 8601 timestamp from the gateway.
 * @param int    $now   Now, as a Unix time (for tests); 0 for the current time.
 * @return array{iso: string, label: string, title: string} Empty strings when
 *         the timestamp cannot be read.
 */
function agend_apps_community_when( string $iso, int $now = 0 ): array {
	$time = '' === $iso ? false : strtotime( $iso );

	if ( false === $time ) {
		return array(
			'iso'   => '',
			'label' => '',
			'title' => '',
		);
	}

	$now      = 0 === $now ? time() : $now;
	$absolute = (string) wp_date( 'j M Y', $time );

	if ( $now - $time >= 0 && $now - $time < 30 * DAY_IN_SECONDS ) {
		$label = sprintf(
			/* translators: %s: a length of time such as "3 days". */
			__( '%s ago', 'agend-apps-core' ),
			human_time_diff( $time, $now )
		);
	} else {
		$label = $absolute;
	}

	return array(
		'iso'   => gmdate( 'c', $time ),
		'label' => $label,
		'title' => $absolute,
	);
}
