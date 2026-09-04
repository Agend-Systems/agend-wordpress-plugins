<?php
/**
 * Shared behaviour for the small Agend widgets used inside card/detail
 * templates (Agend Field, Agend Image, Agend Link, Agend Content Block).
 *
 * Those widgets have no record of their own: at render time they read
 * whichever record Agend_Elementor_Template_Renderer pushed onto
 * Agend_Elementor_Record_Context before rendering the template they live in.
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
	 * Adds the shared "record_type" control every field widget exposes.
	 *
	 * 'auto' (the default) takes whatever type the surrounding template is
	 * being rendered for; a widget only needs an explicit type when it is
	 * meant to reject a template it was dropped into by mistake (see
	 * resolve_context()'s mismatch flag).
	 *
	 * @param string $section_id_hint Reserved for a future per-widget section
	 *                                id; unused while every field widget adds
	 *                                this control to its own first section.
	 * @return void
	 */
	protected function record_type_control( string $section_id_hint = '' ): void {
		$this->add_control(
			'record_type',
			array(
				'label'       => __( 'Record type', 'agend-elementor' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'default'     => 'auto',
				'options'     => array(
					'auto'   => __( 'Auto', 'agend-elementor' ),
					'event'  => __( 'Event', 'agend-elementor' ),
					'course' => __( 'Course', 'agend-elementor' ),
				),
				'description' => __( 'Auto uses whatever record the surrounding template is rendering.', 'agend-elementor' ),
			)
		);
	}

	/**
	 * Resolves the record this widget should render against.
	 *
	 * @return array{type: string, record: array, extra: array, is_preview: bool, mismatch: bool}
	 */
	protected function resolve_context(): array {
		$setting = (string) $this->get_widget_setting( 'record_type', 'auto' );

		if ( Agend_Elementor_Record_Context::has() ) {
			$current = Agend_Elementor_Record_Context::current();
			$type    = (string) ( $current['type'] ?? '' );

			$mismatch = 'auto' !== $setting && $setting !== $type;

			return array(
				'type'       => $type,
				'record'     => is_array( $current['record'] ?? null ) ? $current['record'] : array(),
				'extra'      => is_array( $current['extra'] ?? null ) ? $current['extra'] : array(),
				'is_preview' => false,
				'mismatch'   => $mismatch,
			);
		}

		if ( $this->is_editor() ) {
			$type = 'auto' === $setting ? 'event' : $setting;

			$record = function_exists( 'agend_elementor_preview_record' )
				? agend_elementor_preview_record( $type )
				: array();

			return array(
				'type'       => $type,
				'record'     => $record,
				'extra'      => array(
					'slug'       => $record['slug'] ?? '',
					'detail_url' => '#',
					'is_detail'  => false,
				),
				'is_preview' => true,
				'mismatch'   => false,
			);
		}

		return array(
			'type'       => '',
			'record'     => array(),
			'extra'      => array(),
			'is_preview' => false,
			'mismatch'   => false,
		);
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
	 * Editor-only notice for a widget whose fixed record_type does not match
	 * the type of the template it has been placed inside.
	 *
	 * @param string $expected The widget's configured record_type.
	 * @param string $actual   The record type the surrounding template is
	 *                         actually rendering.
	 * @return void
	 */
	protected function render_mismatch_notice( string $expected, string $actual ): void {
		$this->render_editor_notice(
			sprintf(
				/* translators: 1: configured record type, 2: template's actual record type. */
				__( 'This widget is set to "%1$s" but the surrounding template is rendering "%2$s". Nothing will show here on the live site.', 'agend-elementor' ),
				$expected,
				$actual
			)
		);
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
