<?php
/**
 * Contract for a framework-specific template picker source.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists the eligible templates one page builder can offer a Card/Detail
 * Template picker.
 *
 * Framework-agnostic code depends on this contract, never on the concrete
 * page-builder plugin that implements it (see {@see Agend_Apps_Templates}).
 *
 * A source returns its templates WITHOUT a placeholder entry. More than one
 * source can be registered at once (the block editor ships in Agend Apps Core
 * while Elementor is active alongside it), so the registry owns composing the
 * single placeholder and disambiguating titles across builders; a source that
 * prepended its own would produce one placeholder per builder.
 */
interface Agend_Apps_Template_Source {

	/**
	 * The builder's name, shown to disambiguate titles when more than one
	 * source is registered. Keep it short: it is appended in brackets after a
	 * template title, e.g. "Event card (Elementor)".
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * The eligible templates this builder offers, ordered for display.
	 *
	 * @return array<string, string> Template ids => titles. No placeholder entry.
	 */
	public function templates(): array;

	/**
	 * Whether the page contains this builder's surface of the given kind.
	 * `null` means this builder cannot tell (it does not own the page's content),
	 * so an advisory that depends on the answer simply does not render.
	 *
	 * @param int    $page_id The page to inspect.
	 * @param string $surface Agnostic surface kind: 'events-catalogue', 'courses-catalogue' or 'directory-catalogue'.
	 * @return bool|null
	 */
	public function page_contains_surface( int $page_id, string $surface ): ?bool;
}
