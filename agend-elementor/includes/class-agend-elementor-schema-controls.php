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
				'tab'   => 'style' === ( $section['tab'] ?? '' )
					? \Elementor\Controls_Manager::TAB_STYLE
					: \Elementor\Controls_Manager::TAB_CONTENT,
			);

			if ( isset( $section['condition'] ) ) {
				$section_args['condition'] = $section['condition'];
			}

			$widget->start_controls_section( $section['id'], $section_args );

			foreach ( $section['fields'] ?? array() as $field ) {
				// A field gated on an optional feature (SPEC-CORE-20260908
				// scope-gated features): when the connected API key does not
				// (or is not yet known to) hold the scope(s) the feature
				// needs, a notice naming the missing scope(s) replaces the
				// control entirely, rather than letting the site configure a
				// setting the runtime will never honour.
				if ( isset( $field['requires_feature'] ) && function_exists( 'agend_apps_records_feature_available' )
					&& ! agend_apps_records_feature_available( (string) $field['requires_feature'] )
				) {
					$widget->add_control(
						$field['name'],
						array(
							'type'            => \Elementor\Controls_Manager::RAW_HTML,
							'raw'             => esc_html( agend_apps_records_feature_missing_scope_notice( (string) $field['requires_feature'] ) ),
							'content_classes' => 'elementor-panel-alert elementor-panel-alert-warning',
						)
					);
					continue;
				}

				if ( 'adapter' === ( $field['type'] ?? '' ) ) {
					if ( method_exists( $widget, 'register_adapter_control' ) ) {
						$widget->register_adapter_control( $field['name'] );
					}
					continue;
				}

				$args = self::control_args( $field );

				// A newer core schema may describe a field type this adapter
				// does not understand yet (Decision 2.10); skip it rather than
				// registering a typeless control.
				if ( array() === $args ) {
					continue;
				}

				$widget->add_control( $field['name'], $args );
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
				$args['type'] = \Elementor\Controls_Manager::TEXT;
				// Optional default, like url/media/multiselect below: a nested
				// repeater field transcribed from a widget's own hand-declared
				// Repeater control may have no default at all (Elementor does
				// not require one on a Repeater field), so a field that omits
				// one leaves Elementor's own control default unset rather than
				// forcing an empty string it never had.
				if ( array_key_exists( 'default', $field ) ) {
					$args['default'] = $field['default'];
				}
				if ( isset( $field['placeholder'] ) ) {
					$args['placeholder'] = $field['placeholder'];
				}
				break;

			case 'textarea':
				$args['type']    = \Elementor\Controls_Manager::TEXTAREA;
				$args['default'] = $field['default'] ?? '';
				if ( isset( $field['placeholder'] ) ) {
					$args['placeholder'] = $field['placeholder'];
				}
				break;

			case 'colour':
				$args['type']    = \Elementor\Controls_Manager::COLOR;
				$args['default'] = $field['default'] ?? '';
				break;

			case 'number':
				$args['type']    = \Elementor\Controls_Manager::NUMBER;
				$args['default'] = $field['default'] ?? 0;
				if ( isset( $field['options'] ) ) {
					$args['options'] = $field['options'];
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
				break;

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

			case 'url':
				$args['type'] = \Elementor\Controls_Manager::URL;
				if ( isset( $field['placeholder'] ) ) {
					$args['placeholder'] = $field['placeholder'];
				}
				if ( isset( $field['show_external'] ) ) {
					$args['show_external'] = $field['show_external'];
				}
				if ( array_key_exists( 'default', $field ) ) {
					$args['default'] = $field['default'];
				}
				break;

			case 'media':
				$args['type'] = \Elementor\Controls_Manager::MEDIA;
				if ( array_key_exists( 'default', $field ) ) {
					$args['default'] = $field['default'];
				}
				break;

			case 'repeater':
				$args['type'] = \Elementor\Controls_Manager::REPEATER;

				$repeater = new \Elementor\Repeater();
				foreach ( $field['fields'] ?? array() as $nested_field ) {
					$nested_args = self::control_args( $nested_field );

					// Same Decision 2.10 fallthrough as the top-level loop in
					// register(): a nested field type this adapter does not
					// know yet registers no control rather than a typeless one.
					if ( array() === $nested_args ) {
						continue;
					}

					$repeater->add_control( $nested_field['name'], $nested_args );
				}
				$args['fields'] = $repeater->get_controls();

				if ( isset( $field['title_field'] ) ) {
					// An explicit `title_field` is Elementor's own row-title
					// template, carried verbatim for the rare row a bare
					// `row_label` cannot describe (see the `repeater` type note
					// in schema.php); it wins over one derived from `row_label`.
					$args['title_field'] = $field['title_field'];
				} elseif ( isset( $field['row_label'] ) ) {
					// The common case: Elementor's own Mustache-ish `title_field`
					// syntax is built here, not carried in the schema, so the
					// schema only ever names the field plainly.
					$args['title_field'] = '{{{ ' . $field['row_label'] . ' }}}';
				}
				if ( array_key_exists( 'default', $field ) ) {
					$args['default'] = $field['default'];
				}
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

			default:
				// Unknown field type: no control this adapter can build.
				return array();
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
