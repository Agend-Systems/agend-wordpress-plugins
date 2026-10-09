<?php
/**
 * Supplies a saved Elementor page's Directory Catalogue settings to the
 * server-rendered listing detail.
 *
 * A server-rendered detail page never renders the widget, so a setting chosen
 * on it ("Item list layout", "Item fields") cannot be read where it is set.
 * Agend Apps Core asks for the host page's catalogue settings through the
 * `agend_apps_records_directory_detail_settings` filter, and reads a
 * block-editor catalogue itself; this class answers for Elementor, because core
 * must not read another builder's data. Unlike the sign-in flag
 * (class-agend-elementor-detail-login-flag.php) nothing is mirrored on save:
 * the element data is read when a listing with an item list is rendered.
 *
 * Only a catalogue in the page's own element tree is seen. One inside a saved
 * template, a template widget, a global widget or an Elementor Pro theme-builder
 * template is not. When a page holds several catalogues, the first one counts.
 *
 * @package Agend_Elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static reader of the host page's Directory Catalogue settings.
 */
final class Agend_Elementor_Detail_Settings {

	/**
	 * The Directory Catalogue's Elementor widget name.
	 */
	const WIDGET_TYPE = 'agend-directory-catalogue';

	/**
	 * The post meta Elementor stores a document's element tree in.
	 */
	const DATA_META_KEY = '_elementor_data';

	/**
	 * The post meta that says which editor builds a page. `builder` while
	 * Elementor does; a page switched back to the block editor keeps its
	 * element data but loses this value.
	 */
	const EDIT_MODE_META_KEY = '_elementor_edit_mode';

	/**
	 * Registers the filter. Called from the plugin bootstrap.
	 */
	public static function init(): void {
		add_filter( 'agend_apps_records_directory_detail_settings', array( __CLASS__, 'filter' ), 10, 2 );
	}

	/**
	 * The settings of the first Directory Catalogue widget in an element tree.
	 *
	 * Pure, so it is testable without Elementor or WordPress. Elementor stores
	 * only the settings an author changed, so a default is simply absent.
	 *
	 * @param array $elements Elementor element data (the `_elementor_data` tree).
	 * @return array|null The widget's settings, or null when the tree has no catalogue.
	 */
	public static function from_elements( array $elements ): ?array {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( 'widget' === ( $element['elType'] ?? '' ) && self::WIDGET_TYPE === ( $element['widgetType'] ?? '' ) ) {
				return is_array( $element['settings'] ?? null ) ? $element['settings'] : array();
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$found = self::from_elements( $element['elements'] );
				if ( null !== $found ) {
					return $found;
				}
			}
		}

		return null;
	}

	/**
	 * The Directory Catalogue settings saved on an Elementor page.
	 *
	 * The element data is read as post meta rather than through the Elementor
	 * document: in edit mode the document API converts a document with no
	 * element data into one and saves it, and a read here must never write. A
	 * page Elementor no longer builds is skipped, because its element data is
	 * left behind when it is switched back to the block editor and would give a
	 * stale choice.
	 *
	 * @param int $post_id The page's post id.
	 * @return array|null The settings, or null when the page is not built with
	 *                    Elementor or holds no catalogue.
	 */
	public static function for_post( int $post_id ): ?array {
		if ( 'builder' !== get_post_meta( $post_id, self::EDIT_MODE_META_KEY, true ) ) {
			return null;
		}

		$data = get_post_meta( $post_id, self::DATA_META_KEY, true );

		if ( is_string( $data ) && '' !== $data ) {
			$data = json_decode( $data, true );
		}

		return is_array( $data ) ? self::from_elements( $data ) : null;
	}

	/**
	 * `agend_apps_records_directory_detail_settings`: answers for Elementor when
	 * nothing earlier found a catalogue on the page.
	 *
	 * @param mixed $settings The catalogue settings found so far, or array().
	 * @param mixed $host     The catalogue (host) page.
	 * @return mixed
	 */
	public static function filter( $settings, $host ) {
		if ( ! empty( $settings ) || ! ( $host instanceof WP_Post ) ) {
			return $settings;
		}

		$found = self::for_post( (int) $host->ID );

		return null === $found ? $settings : $found;
	}
}
