<?php
/**
 * Contract for a framework-specific template rendering engine.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders a presentation-layer template (a saved page-builder template,
 * a block pattern, ...) against a single record or with no record in scope.
 *
 * Framework-agnostic code depends on this contract, never on the concrete
 * page-builder plugin that implements it (see {@see Agend_Apps_Templates}).
 */
interface Agend_Apps_Template_Renderer {

	/**
	 * Whether a given template id is usable.
	 *
	 * @param int $template_id The template's id.
	 * @return bool True when the id resolves to a real, publishable template.
	 */
	public function is_valid_template( int $template_id ): bool;

	/**
	 * Ensures the template's styles are enqueued for the current request.
	 *
	 * @param int $template_id The template's id.
	 * @return void
	 */
	public function ensure_styles( int $template_id ): void;

	/**
	 * Renders a template against a single record.
	 *
	 * @param int    $template_id The template's id.
	 * @param string $type        Record type, e.g. 'event' or 'course'.
	 * @param array  $record      The record data.
	 * @param array  $extra       Extra context (slug, detail_url, is_detail, ...).
	 * @param bool   $with_css    Whether to inline the template's CSS alongside the markup.
	 * @return string Rendered HTML, or '' when the template is invalid or nothing else renders.
	 */
	public function render( int $template_id, string $type, array $record, array $extra = array(), bool $with_css = false ): string;

	/**
	 * Renders a template with no record in scope.
	 *
	 * @param int  $template_id The template's id.
	 * @param bool $with_css    Whether to inline the template's CSS.
	 * @return string Rendered HTML, or ''.
	 */
	public function render_plain( int $template_id, bool $with_css = false ): string;
}
