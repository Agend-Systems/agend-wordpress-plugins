<?php
/**
 * Per-render record context for templated field widgets.
 *
 * A card or detail template is rendered by the registered
 * `Agend_Apps_Template_Renderer` around a specific event/course record; the
 * field widgets nested inside that template (Agend Field, Agend Image, Agend
 * Link) have no other way to know which record they are rendering against,
 * because no page builder passes a per-record argument down to a nested
 * element's own render call. This stack is that channel: the renderer pushes
 * the record before calling into the builder and pops it after, and a nested
 * widget reads the top of the stack.
 *
 * The stack (not a single slot) exists because a card template can itself
 * contain another templated catalogue (Phase 3 renders nothing on the
 * frontend in that case, but the context still needs to nest correctly for
 * the editor notice), and because a detail template's fragments may recurse
 * into the renderer for a related record.
 *
 * Every `push()` MUST be paired with a `pop()` in a try/finally by the
 * renderer, so a template that throws or errors mid-render cannot leave a
 * stale frame on the stack for the next card. This class does not and cannot
 * enforce that pairing itself; it only stores what it is given.
 *
 * Pure PHP over arrays, no WordPress calls, so it runs unmodified in the
 * stub test harness.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static LIFO stack of the record currently being rendered.
 */
final class Agend_Apps_Records_Record_Context {

	/**
	 * @var array<int, array{type: string, record: array<string, mixed>, extra: array<string, mixed>}>
	 */
	private static array $stack = array();

	/**
	 * Pushes a new frame onto the context stack.
	 *
	 * @param string               $type   Record type, e.g. 'event' or 'course'.
	 * @param array<string, mixed> $record The record being rendered.
	 * @param array<string, mixed> $extra  Rendering context: slug, detail_url, index,
	 *                                     tickets, timezone, in_card_link, is_detail.
	 */
	public static function push( string $type, array $record, array $extra = array() ): void {
		self::$stack[] = array(
			'type'   => $type,
			'record' => $record,
			'extra'  => $extra,
		);
	}

	/**
	 * Pops the top frame off the context stack.
	 *
	 * A no-op when the stack is already empty, so a defensive extra `pop()`
	 * call never underflows into a fatal error.
	 */
	public static function pop(): void {
		if ( array() === self::$stack ) {
			return;
		}

		array_pop( self::$stack );
	}

	/** Whether a record is currently in context. */
	public static function has(): bool {
		return array() !== self::$stack;
	}

	/**
	 * The current (top) frame, or null when the stack is empty.
	 *
	 * @return array{type: string, record: array<string, mixed>, extra: array<string, mixed>}|null
	 */
	public static function current(): ?array {
		if ( array() === self::$stack ) {
			return null;
		}

		return self::$stack[ count( self::$stack ) - 1 ];
	}

	/** The current record's type, or '' when the stack is empty. */
	public static function type(): string {
		$current = self::current();

		return null === $current ? '' : $current['type'];
	}

	/**
	 * The current record, or an empty array when the stack is empty.
	 *
	 * @return array<string, mixed>
	 */
	public static function record(): array {
		$current = self::current();

		return null === $current ? array() : $current['record'];
	}

	/**
	 * Reads one key from the current frame's extra context.
	 *
	 * @param string $key     Extra key.
	 * @param mixed  $default Value to return when the stack is empty or the
	 *                        key is not set.
	 * @return mixed
	 */
	public static function extra( string $key, $default = null ) {
		$current = self::current();

		if ( null === $current ) {
			return $default;
		}

		return array_key_exists( $key, $current['extra'] ) ? $current['extra'][ $key ] : $default;
	}

	/** Current stack depth, 0 when empty. */
	public static function depth(): int {
		return count( self::$stack );
	}

	/** Clears the entire stack. */
	public static function reset(): void {
		self::$stack = array();
	}
}
