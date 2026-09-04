<?php
/**
 * Renders Elementor Content-tab controls from a page-builder-agnostic content-
 * settings schema (see agend-apps-core/includes/records/schema.php for the
 * vocabulary).
 *
 * This is the Elementor half of the seam: a widget declares its settings once
 * in core, as plain PHP data, and this class is what turns that declaration
 * into real `add_control()` calls. The future block editor surface renders
 * the same schema through its own adapter instead.
 *
 * @package Agend_Elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static Elementor adapter for the shared content-settings schema.
 */
final class Agend_Elementor_Schema_Controls {

	/**
	 * Registers every section and control a schema describes on a widget.
	 *
	 * @param \Elementor\Widget_Base $widget The widget to register controls on.
	 * @param array                  $schema Schema (see schema.php for the shape).
	 * @return void
	 */
	public static function register( \Elementor\Widget_Base $widget, array $schema ): void {
		foreach ( $schema['sections'] ?? array() as $section ) {
			$section_args = array(
				'label' => $section['label'] ?? '',
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			);

			if ( isset( $section['condition'] ) ) {
				$section_args['condition'] = $section['condition'];
			}

			$widget->start_controls_section( $section['id'], $section_args );

			foreach ( $section['fields'] ?? array() as $field ) {
				$widget->add_control( $field['name'], self::control_args( $field ) );
			}

			$widget->end_controls_section();
		}
	}

	/**
	 * The Elementor add_control() args for one schema field.
	 *
	 * Public so it is testable alone.
	 *
	 * @param array $field One field from a schema section's `fields` list.
	 * @return array
	 */
	public static function control_args( array $field ): array {
		$type = $field['type'] ?? '';

		$args = array();

		if ( isset( $field['label'] ) ) {
			$args['label'] = $field['label'];
		}

		switch ( $type ) {
			case 'toggle':
				$args['type'] = \Elementor\Controls_Manager::SWITCHER;
				// Usually boolean, converted to Elementor's 'yes'/''. A field
				// already holding a literal Elementor value (e.g. a legacy
				// 'no' default predating that convention) passes through
				// unchanged, so a transcribed field can keep its exact
				// original default.
				$args['default'] = is_bool( $field['default'] ?? null )
					? ( $field['default'] ? 'yes' : '' )
					: ( $field['default'] ?? '' );
				break;

			case 'text':
				$args['type']    = \Elementor\Controls_Manager::TEXT;
				$args['default'] = $field['default'] ?? '';
				break;

			case 'textarea':
				$args['type']    = \Elementor\Controls_Manager::TEXTAREA;
				$args['default'] = $field['default'] ?? '';
				break;

			case 'number':
				$args['type']    = \Elementor\Controls_Manager::NUMBER;
				$args['default'] = $field['default'] ?? 0;
				if ( isset( $field['options'] ) ) {
					$args['options'] = $field['options'];
				}
				if ( isset( $field['label_block'] ) ) {
					$args['label_block'] = $field['label_block'];
				}
				if ( isset( $field['description'] ) ) {
					$args['description'] = $field['description'];
				}
				if ( isset( $field['condition'] ) ) {
					$args['condition'] = $field['condition'];
				}
				if ( isset( $field['min'] ) ) {
					$args['min'] = $field['min'];
				}
				if ( isset( $field['max'] ) ) {
					$args['max'] = $field['max'];
				}
				if ( isset( $field['step'] ) ) {
					$args['step'] = $field['step'];
				}
				return $args;

			case 'select':
				$args['type']    = \Elementor\Controls_Manager::SELECT;
				$args['default'] = $field['default'] ?? '';
				if ( isset( $field['options'] ) ) {
					$args['options'] = self::resolve_options( $field['options'] );
				}
				if ( isset( $field['groups'] ) ) {
					$args['groups'] = self::resolve_options( $field['groups'] );
				}
				break;

			case 'multiselect':
				$args['type']     = \Elementor\Controls_Manager::SELECT2;
				$args['multiple'] = true;
				// No default key unless the schema gives one: Elementor's own
				// SELECT2 controls leave it unset rather than defaulting to
				// an empty array.
				if ( array_key_exists( 'default', $field ) ) {
					$args['default'] = $field['default'];
				}
				if ( isset( $field['options'] ) ) {
					$args['options'] = self::resolve_options( $field['options'] );
				}
				break;

			case 'template':
				$args['type']    = \Elementor\Controls_Manager::SELECT;
				$args['default'] = $field['default'] ?? '';
				$args['options'] = self::template_options( $field['placeholder'] ?? '' );
				break;

			case 'note':
				$args['type'] = \Elementor\Controls_Manager::RAW_HTML;
				$args['raw']  = $field['content'] ?? '';
				return $args;

			case 'heading':
				$args['type'] = \Elementor\Controls_Manager::HEADING;
				if ( isset( $field['separator'] ) ) {
					$args['separator'] = $field['separator'];
				}
				if ( isset( $field['condition'] ) ) {
					$args['condition'] = $field['condition'];
				}
				return $args;
		}

		if ( isset( $field['label_block'] ) ) {
			$args['label_block'] = $field['label_block'];
		}
		if ( isset( $field['description'] ) ) {
			$args['description'] = $field['description'];
		}
		if ( isset( $field['condition'] ) ) {
			$args['condition'] = $field['condition'];
		}

		return $args;
	}

	/**
	 * Resolves a schema `options`/`groups` value: invoked when it is a
	 * callable string (a gateway-backed option list, fetched only when an
	 * editor opens), returned as-is otherwise.
	 *
	 * @param array|string $options Schema options value.
	 * @return array
	 */
	private static function resolve_options( $options ): array {
		if ( is_string( $options ) && function_exists( $options ) ) {
			return $options();
		}

		return is_array( $options ) ? $options : array();
	}

	/**
	 * The saved-template SELECT options for a `template` field, sourced from
	 * the framework-agnostic registry when present, else this plugin's own
	 * Elementor-only picker.
	 *
	 * @param string $placeholder The "no template" entry's label.
	 * @return array
	 */
	private static function template_options( string $placeholder ): array {
		if ( class_exists( 'Agend_Apps_Templates' ) ) {
			return Agend_Apps_Templates::options( $placeholder );
		}

		return Agend_Elementor_Templates::options( $placeholder );
	}
}
