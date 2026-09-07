<?php
/**
 * Registry for the active template renderers and template sources.
 *
 * The framework-agnostic record layer calls only the static passthroughs on
 * this class, never a page-builder plugin's own classes directly. That
 * inversion is what lets more than one presentation adapter reuse the same
 * agnostic code: each registers its own {@see Agend_Apps_Template_Renderer}
 * and {@see Agend_Apps_Template_Source} implementation here.
 *
 * Several adapters are registered AT ONCE, not one at a time. The block
 * editor surface ships inside this plugin and is always present, while
 * Elementor is active alongside it on most sites, so a single-slot registry
 * would let whichever registered last silently displace the other.
 *
 * A template is addressed by a bare post id, and ownership is resolved by
 * asking each renderer in turn whether the id is one of its own
 * ({@see self::renderer_for()}). That works because WordPress post ids are
 * unique across the whole `wp_posts` table: a given id is an
 * `elementor_library` post or a `wp_block` post, never both. It is also why
 * the stored setting stays a plain integer and needs no migration when a
 * second adapter appears.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Holds the registered renderers and sources, in registration order.
 */
final class Agend_Apps_Templates {

	/** @var Agend_Apps_Template_Renderer[] */
	private static array $renderers = array();

	/** @var Agend_Apps_Template_Source[] */
	private static array $sources = array();

	/**
	 * Registers a template renderer. Registration order is resolution order,
	 * so the first adapter claiming a template id renders it.
	 *
	 * @param Agend_Apps_Template_Renderer $renderer Renderer implementation.
	 * @return void
	 */
	public static function register_renderer( Agend_Apps_Template_Renderer $renderer ): void {
		self::$renderers[] = $renderer;
	}

	/**
	 * Registers a template picker source.
	 *
	 * @param Agend_Apps_Template_Source $source Source implementation.
	 * @return void
	 */
	public static function register_source( Agend_Apps_Template_Source $source ): void {
		self::$sources[] = $source;
	}

	/**
	 * The renderer owning a template id, or null when no adapter claims it.
	 *
	 * @param int $template_id The template's post id.
	 * @return Agend_Apps_Template_Renderer|null
	 */
	public static function renderer_for( int $template_id ): ?Agend_Apps_Template_Renderer {
		foreach ( self::$renderers as $renderer ) {
			if ( $renderer->is_valid_template( $template_id ) ) {
				return $renderer;
			}
		}

		return null;
	}

	/**
	 * All registered renderers, in registration order.
	 *
	 * @return Agend_Apps_Template_Renderer[]
	 */
	public static function renderers(): array {
		return self::$renderers;
	}

	/**
	 * All registered sources, in registration order.
	 *
	 * @return Agend_Apps_Template_Source[]
	 */
	public static function sources(): array {
		return self::$sources;
	}

	/**
	 * Whether any renderer is registered.
	 *
	 * @return bool
	 */
	public static function has_renderer(): bool {
		return array() !== self::$renderers;
	}

	/**
	 * Whether any source is registered.
	 *
	 * @return bool
	 */
	public static function has_source(): bool {
		return array() !== self::$sources;
	}

	/**
	 * Clears every registered renderer and source. Exists for tests, so
	 * registry state cannot leak between them.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$renderers = array();
		self::$sources   = array();
	}

	/**
	 * Whether any registered adapter recognises the template id.
	 *
	 * @param int $template_id The template's post id.
	 * @return bool
	 */
	public static function is_valid_template( int $template_id ): bool {
		return null !== self::renderer_for( $template_id );
	}

	/**
	 * Ensures the template's styles are enqueued. No-op when no adapter
	 * claims the id.
	 *
	 * @param int $template_id The template's post id.
	 * @return void
	 */
	public static function ensure_styles( int $template_id ): void {
		$renderer = self::renderer_for( $template_id );

		if ( null === $renderer ) {
			return;
		}

		$renderer->ensure_styles( $template_id );
	}

	/**
	 * Renders a template against a single record. '' when no adapter claims
	 * the id, so a site whose page-builder plugin was deactivated falls back
	 * to the built-in layout instead of fataling.
	 *
	 * @param int    $template_id The template's post id.
	 * @param string $type        Record type, e.g. 'event' or 'course'.
	 * @param array  $record      The record data.
	 * @param array  $extra       Extra context.
	 * @param bool   $with_css    Whether to inline the template's CSS.
	 * @return string
	 */
	public static function render( int $template_id, string $type, array $record, array $extra = array(), bool $with_css = false ): string {
		$renderer = self::renderer_for( $template_id );

		if ( null === $renderer ) {
			return '';
		}

		return $renderer->render( $template_id, $type, $record, $extra, $with_css );
	}

	/**
	 * Renders a template with no record in scope. '' when no adapter claims
	 * the id.
	 *
	 * @param int  $template_id The template's post id.
	 * @param bool $with_css    Whether to inline the template's CSS.
	 * @return string
	 */
	public static function render_plain( int $template_id, bool $with_css = false ): string {
		$renderer = self::renderer_for( $template_id );

		if ( null === $renderer ) {
			return '';
		}

		return $renderer->render_plain( $template_id, $with_css );
	}

	/**
	 * Whether the page contains any registered source's surface of the given
	 * kind. `true` if any source says so; else `false` if any source said
	 * `false`; else `null` when no source could tell (or none is registered).
	 *
	 * @param int    $page_id The page to inspect.
	 * @param string $surface Agnostic surface kind, e.g. 'events-catalogue'.
	 * @return bool|null
	 */
	public static function page_contains_surface( int $page_id, string $surface ): ?bool {
		$found_false = false;

		foreach ( self::$sources as $source ) {
			$result = $source->page_contains_surface( $page_id, $surface );

			if ( true === $result ) {
				return true;
			}

			if ( false === $result ) {
				$found_false = true;
			}
		}

		return $found_false ? false : null;
	}

	/**
	 * The full SELECT options array: one placeholder, then every registered
	 * source's templates.
	 *
	 * Titles are suffixed with the builder's label only when more than one
	 * source is registered, so a site running the block editor alone reads
	 * "Event card" while a site also running Elementor can tell two
	 * identically named templates apart.
	 *
	 * @param string $placeholder Placeholder label.
	 * @return array<string, string> Keyed '' => placeholder, then ids => titles.
	 */
	public static function options( string $placeholder = '' ): array {
		$options   = array( '' => $placeholder );
		$qualified = count( self::$sources ) > 1;

		foreach ( self::$sources as $source ) {
			$label = $source->label();

			foreach ( $source->templates() as $id => $title ) {
				$options[ (string) $id ] = $qualified
					? sprintf( '%s (%s)', $title, $label )
					: (string) $title;
			}
		}

		return $options;
	}
}
