<?php
/**
 * Registry for the active template renderer and template source.
 *
 * The framework-agnostic layer of sibling plugins (e.g. agend-elementor's
 * card rendering) calls only the static passthroughs on this class, never a
 * page-builder plugin's own classes directly. That inversion is what lets a
 * second presentation adapter (e.g. a future Gutenberg block plugin) reuse
 * the same agnostic code: it registers its own {@see Agend_Apps_Template_Renderer}
 * and {@see Agend_Apps_Template_Source} implementations here instead.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Holds one registered renderer and one registered source.
 */
final class Agend_Apps_Templates {

	/** @var Agend_Apps_Template_Renderer|null */
	private static ?Agend_Apps_Template_Renderer $renderer = null;

	/** @var Agend_Apps_Template_Source|null */
	private static ?Agend_Apps_Template_Source $source = null;

	/**
	 * Registers the active template renderer.
	 *
	 * @param Agend_Apps_Template_Renderer $renderer Renderer implementation.
	 * @return void
	 */
	public static function set_renderer( Agend_Apps_Template_Renderer $renderer ): void {
		self::$renderer = $renderer;
	}

	/**
	 * The registered renderer, or null when none is registered.
	 *
	 * @return Agend_Apps_Template_Renderer|null
	 */
	public static function renderer(): ?Agend_Apps_Template_Renderer {
		return self::$renderer;
	}

	/**
	 * Whether a renderer is registered.
	 *
	 * @return bool
	 */
	public static function has_renderer(): bool {
		return null !== self::$renderer;
	}

	/**
	 * Registers the active template source.
	 *
	 * @param Agend_Apps_Template_Source $source Source implementation.
	 * @return void
	 */
	public static function set_source( Agend_Apps_Template_Source $source ): void {
		self::$source = $source;
	}

	/**
	 * The registered source, or null when none is registered.
	 *
	 * @return Agend_Apps_Template_Source|null
	 */
	public static function source(): ?Agend_Apps_Template_Source {
		return self::$source;
	}

	/**
	 * Whether a source is registered.
	 *
	 * @return bool
	 */
	public static function has_source(): bool {
		return null !== self::$source;
	}

	/**
	 * Clears both the renderer and the source. Exists for tests, so registry
	 * state cannot leak between them.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$renderer = null;
		self::$source   = null;
	}

	/**
	 * Whether a template id is usable. False with no renderer registered.
	 *
	 * @param int $template_id The template's id.
	 * @return bool
	 */
	public static function is_valid_template( int $template_id ): bool {
		if ( null === self::$renderer ) {
			return false;
		}

		return self::$renderer->is_valid_template( $template_id );
	}

	/**
	 * Ensures the template's styles are enqueued. No-op with no renderer registered.
	 *
	 * @param int $template_id The template's id.
	 * @return void
	 */
	public static function ensure_styles( int $template_id ): void {
		if ( null === self::$renderer ) {
			return;
		}

		self::$renderer->ensure_styles( $template_id );
	}

	/**
	 * Renders a template against a single record. '' with no renderer registered.
	 *
	 * @param int    $template_id The template's id.
	 * @param string $type        Record type, e.g. 'event' or 'course'.
	 * @param array  $record      The record data.
	 * @param array  $extra       Extra context.
	 * @param bool   $with_css    Whether to inline the template's CSS.
	 * @return string
	 */
	public static function render( int $template_id, string $type, array $record, array $extra = array(), bool $with_css = false ): string {
		if ( null === self::$renderer ) {
			return '';
		}

		return self::$renderer->render( $template_id, $type, $record, $extra, $with_css );
	}

	/**
	 * Renders a template with no record in scope. '' with no renderer registered.
	 *
	 * @param int  $template_id The template's id.
	 * @param bool $with_css    Whether to inline the template's CSS.
	 * @return string
	 */
	public static function render_plain( int $template_id, bool $with_css = false ): string {
		if ( null === self::$renderer ) {
			return '';
		}

		return self::$renderer->render_plain( $template_id, $with_css );
	}

	/**
	 * The full SELECT options array. `array( '' => $placeholder )` with no
	 * source registered, so a picker degrades to just its placeholder rather
	 * than fataling when the adapter plugin is deactivated.
	 *
	 * @param string $placeholder Placeholder label.
	 * @return array<string, string>
	 */
	public static function options( string $placeholder = '' ): array {
		if ( null === self::$source ) {
			return array( '' => $placeholder );
		}

		return self::$source->options( $placeholder );
	}
}
