<?php
/**
 * Server-side rendering of a block-editor template against a single record.
 *
 * A card or detail template authored in the block editor is a `wp_block`
 * post (see {@see Agend_Apps_Block_Template_Source} for why that post type,
 * rather than a registered block pattern or a `wp_template_part`, is what a
 * template actually is), rendered here once per record: the record a
 * template renders against is not passed as a render() argument any nested
 * block can see, so it is pushed onto
 * {@see Agend_Apps_Records_Record_Context} before the render and popped
 * after, exactly as {@see Agend_Elementor_Template_Renderer} does for an
 * Elementor template. The record-* blocks nested inside a template (a
 * separate, later task) read the current record from there.
 *
 * NO OUTPUT CACHE TO DEFEAT (Decision D3). Elementor's own renderer has to
 * disable Elementor's per-document element cache
 * (`\Elementor\Core\Base\Document`, keyed by document id with no notion of
 * "which record") for the duration of every render, or every card after the
 * first would return the first card's cached markup. WordPress's block
 * rendering path has no equivalent to defeat. This was checked against the
 * core source itself (`wordpress-develop` trunk), not inferred, because the
 * whole per-record design rests on it:
 * - `WP_Block::render()` (`wp-includes/class-wp-block.php`) holds exactly one
 *   static, `$root_interactive_block`, which tracks the current root
 *   interactive block for directive processing and never stores output. There
 *   are no `wp_cache_*` calls, no transients, and no early return of stored
 *   markup, so every call re-invokes a dynamic block's `render_callback`. A
 *   static (non-dynamic) block just re-emits the markup already sitting in the
 *   parsed array this class holds in `$parsed` (Decision D4), which is
 *   naturally identical every time because nothing mutates it between calls.
 * - The one thing that looks like a cache, the reusable-block reference
 *   block's (`core/block`) own recursion guard in
 *   `render_block_core_block()` (`wp-includes/blocks/block.php`), stores only
 *   `$seen_refs[ $attributes['ref'] ] = true`, a set of reference ids
 *   currently resolving, and `unset()`s each id once its render completes. It
 *   holds no rendered output, and it is never consulted for a template
 *   rendered through this class anyway: this class never renders a template
 *   via a `core/block` reference. It reads the template's own `post_content`
 *   directly and calls `render_block()` on the parsed result itself.
 * A future reader relying on this claim should re-check it if WordPress ever
 * grows a render-output cache of its own; there was none to find as of this
 * writing.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders card/detail templates authored in the block editor.
 */
final class Agend_Apps_Block_Template_Renderer implements Agend_Apps_Template_Renderer {

	/**
	 * Maximum nesting depth allowed for a render()/render_plain() call.
	 *
	 * Matches {@see Agend_Elementor_Template_Renderer::MAX_DEPTH}. WordPress's
	 * own reusable-block recursion guard (see the class docblock) only fires
	 * when a `core/block` reference is resolved; it does nothing about the
	 * path this codebase actually risks, a nested catalogue block or a panel
	 * fragment inside a template calling back into
	 * {@see Agend_Apps_Templates::render()} for the same (or another)
	 * template id. This class needs its own guard for exactly the same
	 * reason Elementor's does.
	 *
	 * Unlike Elementor's guard, tripping this one never shows an
	 * editor-only loop warning: Elementor can ask its own Plugin instance
	 * whether the current request is its editor; there is no equally cheap,
	 * reliable way to ask "is this render happening inside the block
	 * editor's own preview" from plain PHP. Tripping the guard here simply
	 * returns '', matching this codebase's fail-soft style elsewhere (e.g.
	 * {@see Agend_Apps_Templates::render()} returning '' when no adapter
	 * claims an id).
	 */
	const MAX_DEPTH = 3;

	/**
	 * Template ids currently being rendered, keyed by id, valued by how many
	 * times render()/render_plain() is on the call stack for that id.
	 *
	 * @var array<int, int>
	 */
	private static array $in_flight = array();

	/**
	 * Template ids whose block styles have already been enqueued this request.
	 *
	 * @var array<int, bool>
	 */
	private static array $styles_ensured = array();

	/**
	 * A template's content, parsed once per template id per request
	 * (Decision D4): every record in a batch renders the same parsed array,
	 * only the record in {@see Agend_Apps_Records_Record_Context} differs
	 * between calls, so re-parsing the source string per record would be
	 * pure waste.
	 *
	 * @var array<int, array<int, array<string, mixed>>>
	 */
	private static array $parsed = array();

	/**
	 * Whether a given post id is a usable block-editor template.
	 *
	 * @param int $template_id The wp_block post id.
	 * @return bool True when the id is a published wp_block post.
	 */
	public function is_valid_template( int $template_id ): bool {
		if ( $template_id <= 0 ) {
			return false;
		}

		if ( 'wp_block' !== get_post_type( $template_id ) ) {
			return false;
		}

		return 'publish' === get_post_status( $template_id );
	}

	/**
	 * Enqueues the registered style handle(s) of every distinct block type
	 * used in the template, once per template id per request.
	 *
	 * Deliberately does NOT render anything here, and does NOT inline any
	 * stylesheet's contents (Decision D6). With
	 * `should_load_separate_core_block_assets` off (WordPress's default), a
	 * core block's "own" stylesheet is the whole of `wp-block-library`, and
	 * inlining that into every card would be absurd. Enqueuing the
	 * registered handle(s) instead lets the host page carry them as ordinary
	 * `<link>` tags, exactly like any other block already on the page.
	 *
	 * @param int $template_id The wp_block post id.
	 * @return void
	 */
	public function ensure_styles( int $template_id ): void {
		if ( $template_id <= 0 || isset( self::$styles_ensured[ $template_id ] ) ) {
			return;
		}

		self::$styles_ensured[ $template_id ] = true;

		if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
			return;
		}

		$registry = WP_Block_Type_Registry::get_instance();

		foreach ( self::block_names( self::parsed_blocks( $template_id ) ) as $name ) {
			$block_type = $registry->get_registered( $name );

			if ( ! $block_type ) {
				continue;
			}

			foreach ( (array) ( $block_type->style_handles ?? array() ) as $handle ) {
				if ( is_string( $handle ) && '' !== $handle ) {
					wp_enqueue_style( $handle );
				}
			}
		}
	}

	/**
	 * Renders a template against a single record.
	 *
	 * @param int    $template_id The wp_block post id.
	 * @param string $type        Record type, e.g. 'event' or 'course'.
	 * @param array  $record      The record data.
	 * @param array  $extra       Extra context (slug, detail_url, is_detail, ...).
	 * @param bool   $with_css    Whether to inline the template's block-supports
	 *                            layout CSS alongside the markup.
	 * @return string Rendered HTML, or '' when the template is invalid or the
	 *                recursion guard trips.
	 */
	public function render( int $template_id, string $type, array $record, array $extra = array(), bool $with_css = false ): string {
		if ( ! $this->is_valid_template( $template_id ) ) {
			return '';
		}

		return $this->guarded_render(
			$template_id,
			$with_css,
			array(
				'type'   => $type,
				'record' => $record,
				'extra'  => $extra,
			)
		);
	}

	/**
	 * Renders a template with no record in scope.
	 *
	 * @param int  $template_id The wp_block post id.
	 * @param bool $with_css    Whether to inline the template's block-supports CSS.
	 * @return string Rendered HTML, or ''.
	 */
	public function render_plain( int $template_id, bool $with_css = false ): string {
		if ( ! $this->is_valid_template( $template_id ) ) {
			return '';
		}

		return $this->guarded_render( $template_id, $with_css, null );
	}

	/**
	 * Shared recursion guard, render and CSS handling for render() and
	 * render_plain(), which differ only in whether a record context frame is
	 * pushed (Decision D5 for the guard; D6 for the CSS).
	 *
	 * @param int        $template_id The wp_block post id. Already proved valid by the caller.
	 * @param bool       $with_css    Whether to inline block-supports CSS alongside the markup.
	 * @param array|null $context     `array{type: string, record: array, extra: array}` to push, or null for a plain render.
	 * @return string
	 */
	private function guarded_render( int $template_id, bool $with_css, ?array $context ): string {
		$depth = self::$in_flight[ $template_id ] ?? 0;

		if ( $depth >= self::MAX_DEPTH ) {
			return '';
		}

		self::$in_flight[ $template_id ] = $depth + 1;

		$this->ensure_styles( $template_id );

		if ( null !== $context ) {
			Agend_Apps_Records_Record_Context::push( $context['type'], $context['record'], $context['extra'] );
		}

		try {
			$html = '';

			foreach ( self::parsed_blocks( $template_id ) as $block ) {
				$html .= render_block( $block );
			}
		} finally {
			if ( null !== $context ) {
				Agend_Apps_Records_Record_Context::pop();
			}

			if ( 1 === self::$in_flight[ $template_id ] ) {
				unset( self::$in_flight[ $template_id ] );
			} else {
				--self::$in_flight[ $template_id ];
			}
		}

		return self::with_supports_css( $html, $with_css );
	}

	/**
	 * The template's content, parsed once per template id per request
	 * (Decision D4).
	 *
	 * @param int $template_id The wp_block post id.
	 * @return array<int, array<string, mixed>>
	 */
	private static function parsed_blocks( int $template_id ): array {
		if ( array_key_exists( $template_id, self::$parsed ) ) {
			return self::$parsed[ $template_id ];
		}

		$post    = get_post( $template_id );
		$content = ( $post instanceof WP_Post ) ? (string) $post->post_content : '';

		self::$parsed[ $template_id ] = parse_blocks( $content );

		return self::$parsed[ $template_id ];
	}

	/**
	 * Every distinct, non-empty block name used in a parsed block tree.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return string[]
	 */
	private static function block_names( array $blocks ): array {
		$names = array();

		foreach ( $blocks as $block ) {
			$name = $block['blockName'] ?? null;

			if ( is_string( $name ) && '' !== $name ) {
				$names[ $name ] = true;
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				foreach ( self::block_names( $block['innerBlocks'] ) as $inner_name ) {
					$names[ $inner_name ] = true;
				}
			}
		}

		return array_keys( $names );
	}

	/**
	 * Inlines block-supports layout CSS alongside the markup when requested
	 * (Decision D6).
	 *
	 * `ensure_styles()` only enqueues a block's REGISTERED stylesheet; it
	 * cannot deliver block-supports CSS (chiefly `blockGap` and flex/grid
	 * child rules), which WordPress generates with content-hash-derived
	 * class names into the style engine's `block-supports` store DURING the
	 * render, and normally drains into the page in `wp_footer`. A REST
	 * fragment request never fires `wp_footer`, so capturing the store
	 * straight after the render is the only chance to deliver it at all.
	 *
	 * Three things make this correct and worth stating explicitly:
	 * - Capturing does not drain the store, so calling this after every card
	 *   in a batch would simply return the same accumulated stylesheet again.
	 * - `$with_css` is only ever true from the REST fragments controller
	 *   ({@see Agend_Apps_Records_Fragments_Controller}), so in that request
	 *   the store starts empty and, by the time this runs, holds only this
	 *   template's rules.
	 * - The generated class names are content-hash-derived from a structure
	 *   identical across every record in a batch, so one capture (on the
	 *   first card) covers the whole batch, which is why
	 *   {@see agend_apps_records_render_cards()} only asks for `with_css`
	 *   when `0 === $index`.
	 *
	 * `wp_style_engine_get_stylesheet_from_context()` requires WordPress 6.1;
	 * this plugin still declares `Requires at least: 6.0`. Guarded so an
	 * older site simply omits the layout CSS rather than fataling, matching
	 * this codebase's fail-soft style elsewhere.
	 *
	 * Does not attempt to ship theme.json global styles or element defaults
	 * in a fragment: the host page already carries those from its own head.
	 *
	 * @param string $html     Rendered markup.
	 * @param bool   $with_css Whether to inline the CSS.
	 * @return string
	 */
	private static function with_supports_css( string $html, bool $with_css ): string {
		if ( ! $with_css || ! function_exists( 'wp_style_engine_get_stylesheet_from_context' ) ) {
			return $html;
		}

		$css = (string) wp_style_engine_get_stylesheet_from_context( 'block-supports' );

		return '' !== $css ? '<style>' . $css . '</style>' . $html : $html;
	}

	/**
	 * Clears every cached/in-flight/ensured state this class holds. Exists
	 * for tests, so state from one test cannot leak into the next; a real
	 * request never needs to call this (a fresh PHP process starts empty).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$in_flight      = array();
		self::$styles_ensured = array();
		self::$parsed         = array();
	}
}
