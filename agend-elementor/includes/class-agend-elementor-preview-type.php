<?php
/**
 * Which record type a card/detail template is being built for, inferred from
 * the template itself while it is open in the Elementor editor.
 *
 * A card or detail template is an ordinary `elementor_library` post: nothing
 * on it records that it is "the directory listing template". That binding
 * lives on the catalogue widget or the detail settings that point AT the
 * template, neither of which is in scope while the template itself is being
 * edited. So a widget inside a template being edited has no record context
 * (nothing is being rendered) and no declaration to read either, and every
 * Agend widget left on "Auto" used to fall back to previewing an event --
 * which is why a directory template previewed as "Sample Event" with every
 * listing block reporting it belonged to the other record type.
 *
 * The template does say which record it is for, implicitly: its widgets are
 * set to `listing:name`, `listing_hours`, and so on. This class reads that
 * back out of the saved document, so the whole template previews against a
 * listing without the author setting `record_type` on every widget.
 *
 * @package Agend_Elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static resolver for a template's editor preview record type.
 */
final class Agend_Elementor_Preview_Type {

	/**
	 * The post meta Elementor stores a document's element tree in.
	 *
	 * Read directly rather than through `Document::get_elements_data()`,
	 * which -- in edit mode, for a document with no element data yet --
	 * converts the post to Elementor and SAVES it. Inferring a preview type
	 * must never write.
	 */
	const DATA_META_KEY = '_elementor_data';

	/**
	 * Resolved type per document id, for this request.
	 *
	 * @var array<int, string>
	 */
	private static array $memo = array();

	/**
	 * The preview record type for the document currently being edited.
	 *
	 * @return string 'event', 'course', 'listing', or '' when no document is
	 *                in scope or it declares nothing.
	 */
	public static function for_current_document(): string {
		return self::for_document( self::current_document_id() );
	}

	/**
	 * The preview record type a saved document declares.
	 *
	 * @param int $document_id The elementor_library (or page) post id.
	 * @return string 'event', 'course', 'listing', or ''.
	 */
	public static function for_document( int $document_id ): string {
		if ( $document_id <= 0 ) {
			return '';
		}

		if ( isset( self::$memo[ $document_id ] ) ) {
			return self::$memo[ $document_id ];
		}

		$data = get_post_meta( $document_id, self::DATA_META_KEY, true );
		if ( is_string( $data ) && '' !== $data ) {
			$data = json_decode( $data, true );
		}

		self::$memo[ $document_id ] = is_array( $data ) ? self::from_elements( $data ) : '';

		return self::$memo[ $document_id ];
	}

	/**
	 * The record type an element tree declares.
	 *
	 * A widget with an explicit `record_type` wins over one that only implies
	 * a type through its field/block key, wherever in the tree each sits: an
	 * author who set the type by hand has said so outright.
	 *
	 * Pure, so it is testable without Elementor or WordPress.
	 *
	 * @param array $elements Elementor element data (the `_elementor_data` tree).
	 * @return string 'event', 'course', 'listing', or ''.
	 */
	public static function from_elements( array $elements ): string {
		$found = self::scan( $elements );

		return '' !== $found['explicit'] ? $found['explicit'] : $found['implied'];
	}

	/**
	 * Walks an element tree collecting the first explicit and first implied
	 * record type it carries.
	 *
	 * @param array $elements Elementor element data.
	 * @return array{explicit: string, implied: string}
	 */
	private static function scan( array $elements ): array {
		$implied = '';

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( self::is_agend_widget( $element ) ) {
				$settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : array();
				$declared = (string) ( $settings['record_type'] ?? '' );

				if ( '' !== $declared && 'auto' !== $declared ) {
					return array( 'explicit' => $declared, 'implied' => $implied );
				}

				if ( '' === $implied ) {
					$implied = self::from_widget_settings( $settings );
				}
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$nested = self::scan( $element['elements'] );

				if ( '' !== $nested['explicit'] ) {
					return array( 'explicit' => $nested['explicit'], 'implied' => $implied );
				}

				if ( '' === $implied ) {
					$implied = $nested['implied'];
				}
			}
		}

		return array( 'explicit' => '', 'implied' => $implied );
	}

	/**
	 * Whether an element is one of this plugin's widgets.
	 *
	 * Scoped to `agend-` so a third-party widget that happens to carry a
	 * `field` setting cannot decide what an Agend template previews as.
	 *
	 * @param array $element One element from the tree.
	 * @return bool
	 */
	private static function is_agend_widget( array $element ): bool {
		return 'widget' === ( $element['elType'] ?? '' )
			&& 0 === strpos( (string) ( $element['widgetType'] ?? '' ), 'agend-' );
	}

	/**
	 * The record type a widget's own settings imply.
	 *
	 * @param array $settings One widget's saved settings.
	 * @return string 'event', 'course', 'listing', or ''.
	 */
	private static function from_widget_settings( array $settings ): string {
		if ( ! function_exists( 'agend_apps_records_type_from_key' ) ) {
			return '';
		}

		foreach ( array( 'field', 'block' ) as $name ) {
			$type = agend_apps_records_type_from_key( (string) ( $settings[ $name ] ?? '' ) );

			if ( '' !== $type ) {
				return $type;
			}
		}

		return '';
	}

	/**
	 * The id of the document currently being edited or previewed.
	 *
	 * `documents->get_current()` covers both server-side render paths the
	 * editor uses: the full preview iframe, and the per-widget re-render
	 * (`ajax_render_widget()`, which switches to the document first). The
	 * editor's own post id is the fallback for anything else.
	 *
	 * @return int Post id, or 0.
	 */
	private static function current_document_id(): int {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return 0;
		}

		$plugin = \Elementor\Plugin::$instance;

		if ( isset( $plugin->documents ) ) {
			$document = $plugin->documents->get_current();

			if ( $document && method_exists( $document, 'get_main_id' ) ) {
				return (int) $document->get_main_id();
			}
		}

		if ( isset( $plugin->editor ) && method_exists( $plugin->editor, 'get_post_id' ) ) {
			return (int) $plugin->editor->get_post_id();
		}

		return 0;
	}
}
