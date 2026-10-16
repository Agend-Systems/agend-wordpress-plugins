<?php
/**
 * Elementor's reader of the record fields a card template binds.
 *
 * @package Agend_Elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Walks a saved Elementor template's element tree for Agend widgets and
 * reports the record field keys and custom field keys they are bound to.
 *
 * Loaded only when Agend Apps Core declares {@see Agend_Apps_Template_Field_Keys}:
 * the two plugins update independently, and an older core without that
 * interface cannot load a class implementing it.
 */
final class Agend_Elementor_Template_Field_Keys_Adapter implements Agend_Apps_Template_Field_Keys {

	public function field_keys( int $template_id ): ?array {
		if ( ! Agend_Elementor_Template_Renderer::is_valid_template( $template_id ) ) {
			return null;
		}

		// Read the meta directly, as Agend_Elementor_Preview_Type does: the
		// document API can convert and save a post it reads in edit mode.
		return self::from_meta( get_post_meta( $template_id, '_elementor_data', true ) );
	}

	/**
	 * The keys a saved `_elementor_data` value binds. Elementor stores it as a
	 * JSON string; an already-decoded tree is accepted too.
	 *
	 * @param mixed $data The meta value.
	 * @return array{fields: string[], custom_fields: string[]}
	 */
	public static function from_meta( $data ): array {
		if ( is_string( $data ) && '' !== $data ) {
			$data = json_decode( $data, true );
		}

		return agend_apps_records_merge_binding_keys( ...self::from_elements( is_array( $data ) ? $data : array() ) );
	}

	/**
	 * The key set each Agend widget in an element tree binds.
	 *
	 * @param array $elements Elementor element data (the `_elementor_data` tree).
	 * @return array<int, array{fields: string[], custom_fields: string[]}>
	 */
	public static function from_elements( array $elements ): array {
		$sets = array();

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$widget = (string) ( $element['widgetType'] ?? '' );
			if ( 'widget' === ( $element['elType'] ?? '' ) && 0 === strpos( $widget, 'agend-' ) ) {
				$sets[] = agend_apps_records_template_binding_keys(
					substr( $widget, strlen( 'agend-' ) ),
					is_array( $element['settings'] ?? null ) ? $element['settings'] : array()
				);
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$sets = array_merge( $sets, self::from_elements( $element['elements'] ) );
			}
		}

		return $sets;
	}
}
