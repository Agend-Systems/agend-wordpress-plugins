<?php
/**
 * Shared behaviour for the small Agend widgets used inside card/detail
 * templates (Agend Field, Agend Pills, Agend Image, Agend Link, Agend Panel).
 *
 * Those widgets have no record of their own: at render time they read
 * whichever record Agend_Elementor_Template_Renderer pushed onto
 * Agend_Apps_Records_Record_Context before rendering the template they live in.
 * A widget placed directly on an ordinary page, or on a card/detail template
 * opened for editing (there is no live render happening, so no context is
 * pushed), has nothing to read; this trait supplies an editor-only preview
 * record so the widget still shows something meaningful in the builder, and a
 * consistent way to warn instead of silently rendering blank.
 *
 * @package Agend_Elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait for use inside \Elementor\Widget_Base subclasses.
 */
trait Agend_Elementor_Field_Widget_Trait {

	/**
	 * Whether Elementor is currently building this request in its editor,
	 * or previewing it (the iframe the editor renders into).
	 *
	 * @return bool
	 */
	protected function is_editor(): bool {
		return \Elementor\Plugin::$instance->editor->is_edit_mode()
			|| \Elementor\Plugin::$instance->preview->is_preview_mode();
	}

	/**
	 * Resolves the record this widget should render against.
	 *
	 * There is no record-type setting to reconcile: the widget's field or
	 * panel key names the type by itself, and a key that does not apply to
	 * the surrounding template is caught where it is read, by
	 * agend_apps_records_field_applies() and by the panel's own type check,
	 * both of which can say which field or panel is wrong rather than only
	 * that something is.
	 *
	 * @return array{type: string, record: array, extra: array, is_preview: bool}
	 */
	protected function resolve_context(): array {
		if ( Agend_Apps_Records_Record_Context::has() ) {
			$current = Agend_Apps_Records_Record_Context::current();

			return array(
				'type'       => (string) ( $current['type'] ?? '' ),
				'record'     => is_array( $current['record'] ?? null ) ? $current['record'] : array(),
				'extra'      => is_array( $current['extra'] ?? null ) ? $current['extra'] : array(),
				'is_preview' => false,
			);
		}

		if ( $this->is_editor() ) {
			$type = $this->preview_type();

			$record = function_exists( 'agend_apps_records_preview_record' )
				? agend_apps_records_preview_record( $type )
				: array();

			$extra = function_exists( 'agend_apps_records_preview_extra' )
				? agend_apps_records_preview_extra( $type, $record )
				: array(
					'slug'       => $record['slug'] ?? '',
					'detail_url' => '#',
					'is_detail'  => false,
				);

			return array(
				'type'       => $type,
				'record'     => $record,
				'extra'      => $extra,
				'is_preview' => true,
			);
		}

		return array(
			'type'       => '',
			'record'     => array(),
			'extra'      => array(),
			'is_preview' => false,
		);
	}

	/**
	 * The record type this widget previews against in the editor.
	 *
	 * Its own settings first: a widget set to `listing:name` or
	 * `event_tickets` has already named the record it wants. Then the
	 * template it sits in, which the rest of its widgets have named the same
	 * way -- that is what lets a `common:title` in a directory template
	 * preview a listing's name rather than an event's. Events last, as the
	 * type this fallback has always had.
	 *
	 * @return string 'event', 'course' or 'listing'.
	 */
	protected function preview_type(): string {
		$own = $this->preview_type_from_settings();
		if ( '' !== $own ) {
			return $own;
		}

		$template = class_exists( 'Agend_Elementor_Preview_Type' )
			? Agend_Elementor_Preview_Type::for_current_document()
			: '';

		return '' !== $template ? $template : 'event';
	}

	/**
	 * The record type this widget's own field/block setting implies.
	 *
	 * @return string 'event', 'course', 'listing', or '' for a widget whose
	 *                setting names no single type (a `common:` field, or the
	 *                Agend Link widget, which has neither setting).
	 */
	private function preview_type_from_settings(): string {
		if ( ! function_exists( 'agend_apps_records_type_from_key' ) ) {
			return '';
		}

		foreach ( array( 'field', 'block' ) as $name ) {
			$type = agend_apps_records_type_from_key( (string) $this->get_widget_setting( $name ) );

			if ( '' !== $type ) {
				return $type;
			}
		}

		return '';
	}

	/**
	 * Echoes an editor-only warning notice. No-op on the live frontend, so it
	 * is safe to call unconditionally from render().
	 *
	 * @param string $message Plain-text notice; escaped before output.
	 * @return void
	 */
	protected function render_editor_notice( string $message ): void {
		if ( ! $this->is_editor() ) {
			return;
		}

		echo '<div class="elementor-alert elementor-alert-warning">' . esc_html( $message ) . '</div>';
	}

	/**
	 * Thin wrapper over get_settings_for_display() with a default, so callers
	 * do not need to know whether a setting is unset vs. empty.
	 *
	 * @param string $key     Control name.
	 * @param mixed  $default Value to use when the setting is unset.
	 * @return mixed
	 */
	protected function get_widget_setting( string $key, $default = '' ) {
		$value = $this->get_settings_for_display( $key );

		return null === $value ? $default : $value;
	}
}
