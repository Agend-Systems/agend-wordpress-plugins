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
 * Lists the eligible templates for a Card/Detail Template SELECT control.
 *
 * Framework-agnostic code depends on this contract, never on the concrete
 * page-builder plugin that implements it (see {@see Agend_Apps_Templates}).
 */
interface Agend_Apps_Template_Source {

	/**
	 * The full SELECT options array: placeholder first, then eligible templates.
	 *
	 * @param string $placeholder Placeholder label. A blank string falls back to the source's own default.
	 * @return array<string, string> Keyed '' => placeholder, then template ids => titles.
	 */
	public function options( string $placeholder = '' ): array;
}
