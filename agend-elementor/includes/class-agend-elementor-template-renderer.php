<?php
/**
 * Server-side rendering of an Elementor template against a single record.
 *
 * Card and detail templates are ordinary Elementor templates (elementor_library
 * posts) authored once in the builder, then rendered here once per record
 * (per card in a catalogue grid, or once for the detail page). The only
 * mechanism Elementor ships for that is
 * \Elementor\Plugin::$instance->frontend->get_builder_content_for_display(),
 * which was built to render a template once per request, not once per record,
 * so this class exists to make that safe: it disables the per-document element
 * cache for the duration of the render and guards against a template that
 * (directly or via a nested catalogue widget) tries to render itself.
 *
 * The record a template renders against is not passed as a render() argument
 * the widgets inside the template can see; it is pushed onto
 * Agend_Elementor_Record_Context before the render and popped after, and the
 * field/image/link widgets inside the template read it from there.
 *
 * @package Agend_Elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static renderer for card and detail templates.
 */
final class Agend_Elementor_Template_Renderer {

	/**
	 * Maximum nesting depth allowed for a render() call.
	 *
	 * A template that places another Agend catalogue/card widget inside itself
	 * can recurse arbitrarily; this caps it rather than exhausting the
	 * request. 3 is generous for any legitimate nesting (catalogue -> card
	 * template -> a content block referencing another small template) while
	 * still stopping a template that renders itself.
	 */
	const MAX_DEPTH = 3;

	/**
	 * Template ids currently being rendered, keyed by id, valued by how many
	 * times render() is on the call stack for that id.
	 *
	 * @var array<int, int>
	 */
	private static $in_flight = array();

	/**
	 * Template ids whose CSS file has already been enqueued this request.
	 *
	 * @var array<int, bool>
	 */
	private static $styles_ensured = array();

	/**
	 * Whether a given post id is a usable Elementor template.
	 *
	 * @param int $template_id The elementor_library post id.
	 * @return bool True when the id is a published, Elementor-built template.
	 */
	public static function is_valid_template( int $template_id ): bool {
		if ( $template_id <= 0 ) {
			return false;
		}

		if ( 'elementor_library' !== get_post_type( $template_id ) ) {
			return false;
		}

		if ( 'publish' !== get_post_status( $template_id ) ) {
			return false;
		}

		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return false;
		}

		$document = \Elementor\Plugin::$instance->documents->get( $template_id );

		return $document && $document->is_built_with_elementor();
	}

	/**
	 * Enqueues the template's stylesheet, once per template id per request.
	 *
	 * Card templates render as REST fragments (assembled client-side after
	 * the initial page load), so the host page must already carry the
	 * template's CSS before a single fragment reaches the DOM; there is no
	 * later point at which we could enqueue it. Called unconditionally by the
	 * catalogue widget as soon as a card template is configured, even when
	 * the first page of results is empty.
	 *
	 * @param int $template_id The elementor_library post id.
	 * @return void
	 */
	public static function ensure_styles( int $template_id ): void {
		if ( isset( self::$styles_ensured[ $template_id ] ) ) {
			return;
		}

		self::$styles_ensured[ $template_id ] = true;

		\Elementor\Core\Files\CSS\Post::create( $template_id )->enqueue();
	}

	/**
	 * Renders a template against a single record.
	 *
	 * @param int    $template_id The elementor_library post id.
	 * @param string $type        Record type, e.g. 'event' or 'course'.
	 * @param array  $record      The record data, in the shape the type's field
	 *                            registry expects.
	 * @param array  $extra       Extra context (slug, detail_url, is_detail, ...).
	 * @param bool   $with_css    Whether to inline the template's CSS in a
	 *                            <style> tag alongside the markup (needed for
	 *                            a fragment inserted after the initial page
	 *                            load, since no further <link> tag will be
	 *                            picked up by the browser).
	 * @return string Rendered HTML, or '' when the template is invalid, the
	 *                recursion guard trips, or nothing else renders.
	 */
	public static function render( int $template_id, string $type, array $record, array $extra = array(), bool $with_css = false ): string {
		if ( ! self::is_valid_template( $template_id ) ) {
			return '';
		}

		$depth = self::$in_flight[ $template_id ] ?? 0;

		if ( $depth >= self::MAX_DEPTH ) {
			// is_valid_template() above already proved \Elementor\Plugin exists.
			if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
				return '<div class="elementor-alert elementor-alert-danger">' . esc_html__( 'This template renders itself. Choose a different template, or remove the widget causing the loop.', 'agend-elementor' ) . '</div>';
			}

			return '';
		}

		self::$in_flight[ $template_id ] = $depth + 1;

		// The most important line in this file. Elementor's element cache
		// (Document::print_elements(), \Elementor\Core\Base\Document) is keyed
		// by document id only, with no awareness of a "current record": with
		// the cache active, the first render of a template caches its output
		// and every subsequent render of the same template id (record 2, 3,
		// ...) returns that same cached markup regardless of which record we
		// pushed onto Agend_Elementor_Record_Context. Disabling it for the
		// duration of this render is what makes per-record rendering possible
		// at all.
		add_filter( 'pre_option_elementor_element_cache_ttl', array( __CLASS__, 'disable_element_cache' ) );

		self::ensure_styles( $template_id );

		Agend_Elementor_Record_Context::push( $type, $record, $extra );

		try {
			$html = \Elementor\Plugin::$instance->frontend->get_builder_content_for_display( $template_id, $with_css );
		} finally {
			Agend_Elementor_Record_Context::pop();
			remove_filter( 'pre_option_elementor_element_cache_ttl', array( __CLASS__, 'disable_element_cache' ) );

			if ( 1 === self::$in_flight[ $template_id ] ) {
				unset( self::$in_flight[ $template_id ] );
			} else {
				self::$in_flight[ $template_id ]--;
			}
		}

		return (string) $html;
	}

	/**
	 * Renders a template with no record in scope.
	 *
	 * Filter templates describe controls rather than a record, so they get the
	 * same recursion guard and element-cache handling without a context push.
	 *
	 * @param int  $template_id The elementor_library post id.
	 * @param bool $with_css    Whether to inline the template's CSS.
	 * @return string Rendered HTML, or ''.
	 */
	public static function render_plain( int $template_id, bool $with_css = false ): string {
		if ( ! self::is_valid_template( $template_id ) ) {
			return '';
		}

		$depth = self::$in_flight[ $template_id ] ?? 0;
		if ( $depth >= self::MAX_DEPTH ) {
			return '';
		}
		self::$in_flight[ $template_id ] = $depth + 1;

		add_filter( 'pre_option_elementor_element_cache_ttl', array( __CLASS__, 'disable_element_cache' ) );
		self::ensure_styles( $template_id );

		try {
			$html = \Elementor\Plugin::$instance->frontend->get_builder_content_for_display( $template_id, $with_css );
		} finally {
			remove_filter( 'pre_option_elementor_element_cache_ttl', array( __CLASS__, 'disable_element_cache' ) );
			if ( 1 === self::$in_flight[ $template_id ] ) {
				unset( self::$in_flight[ $template_id ] );
			} else {
				self::$in_flight[ $template_id ]--;
			}
		}

		return (string) $html;
	}

	/**
	 * Filter callback: forces get_option( 'elementor_element_cache_ttl' ) to
	 * report 'disable' for the duration of a render() call.
	 *
	 * @return string
	 */
	public static function disable_element_cache() {
		return 'disable';
	}

	/**
	 * Renders a template against many records.
	 *
	 * Enqueues the template's CSS once, then renders each record in turn.
	 * A single record's render failure does not abort the batch: the
	 * catalogue would rather show one blank card than none.
	 *
	 * @param int      $template_id The elementor_library post id.
	 * @param string   $type        Record type, e.g. 'event' or 'course'.
	 * @param array    $records     The records to render, one card each.
	 * @param callable $extra_for   ( array $record, int $index ): array. Builds
	 *                              the per-record extra context (slug, detail
	 *                              url, ...).
	 * @return array<int, string> Rendered HTML per record, same order and
	 *                            count as $records; a failed record is ''.
	 */
	public static function render_many( int $template_id, string $type, array $records, callable $extra_for ): array {
		$html = array();

		if ( ! self::is_valid_template( $template_id ) ) {
			return array_fill( 0, count( $records ), '' );
		}

		self::ensure_styles( $template_id );

		foreach ( array_values( $records ) as $index => $record ) {
			try {
				$extra   = $extra_for( $record, $index );
				$html[] = self::render( $template_id, $type, $record, $extra );
			} catch ( \Throwable $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( sprintf( 'Agend Elementor: card render failed for template %d, record %d: %s', $template_id, $index, $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}

				$html[] = '';
			}
		}

		return $html;
	}
}
