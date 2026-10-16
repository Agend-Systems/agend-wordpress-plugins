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
		$is_editor = $this->is_editor();

		// Core owns the resolution itself, so the block editor's templated
		// surfaces resolve their record exactly the same way. Only the two
		// questions core cannot ask for itself are answered here: whether an
		// editor is drawing this widget, and which record type it should
		// preview. preview_type() is evaluated only when it will be used,
		// since it scans the current document.
		if ( function_exists( 'agend_apps_records_resolve_record_context' ) ) {
			return agend_apps_records_resolve_record_context(
				array(
					'preview'      => $is_editor,
					'preview_type' => $is_editor ? $this->preview_type() : '',
				)
			);
		}

		// Fallback for an Agend Apps Core older than the shared resolver; the
		// two plugins are updated independently on live sites.
		if ( Agend_Apps_Records_Record_Context::has() ) {
			$current = Agend_Apps_Records_Record_Context::current();

			return array(
				'type'       => (string) ( $current['type'] ?? '' ),
				'record'     => is_array( $current['record'] ?? null ) ? $current['record'] : array(),
				'extra'      => is_array( $current['extra'] ?? null ) ? $current['extra'] : array(),
				'is_preview' => false,
			);
		}

		if ( $is_editor ) {
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
	 * Echoes the surface gate when the catalogue this widget draws a record
	 * from cannot be used with the API key's scopes (see surface-gate.php): a
	 * disabled box in the editor, nothing for a visitor.
	 *
	 * @param array $settings Resolved widget settings.
	 * @param array $opts     Render opts ('preview', 'preview_type').
	 * @return bool True when the gate was echoed and render() should stop.
	 */
	protected function render_record_gate( array $settings, array $opts ): bool {
		if ( ! function_exists( 'agend_apps_records_record_gate' ) ) {
			return false;
		}

		$gate = agend_apps_records_record_gate( $settings, $opts );

		if ( null === $gate ) {
			return false;
		}

		echo $gate; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the surface gate.

		return true;
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

	/**
	 * The Style tab section for a capped list's "Show the rest" control.
	 * Colours and box values are set as the custom properties record-fields.css
	 * reads, so they apply whatever a theme's button styling does.
	 *
	 * @param string $typography_selector A selector that out-ranks the
	 *                                    control's own rule in record-fields.css.
	 */
	protected function register_rest_toggle_style_controls( string $typography_selector ): void {
		$pill = array(
			'show_rest'  => 'yes',
			'rest_style' => 'pill',
		);

		$this->start_controls_section(
			'section_rest_style',
			array(
				'label'     => __( 'Show the rest', 'agend-elementor' ),
				'tab'       => \Elementor\Controls_Manager::TAB_STYLE,
				'condition' => array( 'show_rest' => 'yes' ),
			)
		);

		$this->add_control(
			'rest_colour',
			array(
				'label'     => __( 'Colour', 'agend-elementor' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}}' => '--agend-rest-colour: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'rest_colour_hover',
			array(
				'label'     => __( 'Hover colour', 'agend-elementor' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}}' => '--agend-rest-colour-hover: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'rest_focus_colour',
			array(
				'label'       => __( 'Focus outline colour', 'agend-elementor' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				'description' => __( 'Shown when the control is reached by keyboard. Defaults to the text colour.', 'agend-elementor' ),
				'selectors'   => array( '{{WRAPPER}}' => '--agend-rest-focus: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'rest_background',
			array(
				'label'     => __( 'Background colour', 'agend-elementor' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}}' => '--agend-rest-bg: {{VALUE}};' ),
				'condition' => $pill,
			)
		);

		$this->add_control(
			'rest_background_hover',
			array(
				'label'     => __( 'Hover background colour', 'agend-elementor' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}}' => '--agend-rest-bg-hover: {{VALUE}};' ),
				'condition' => $pill,
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'rest_typography',
				'selector' => $typography_selector,
			)
		);

		$this->add_responsive_control(
			'rest_padding',
			array(
				'label'      => __( 'Padding', 'agend-elementor' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array( '{{WRAPPER}}' => '--agend-rest-padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
				'condition'  => $pill,
			)
		);

		$this->add_responsive_control(
			'rest_radius',
			array(
				'label'      => __( 'Border radius', 'agend-elementor' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array( '{{WRAPPER}}' => '--agend-rest-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
				'condition'  => $pill,
			)
		);

		$this->end_controls_section();
	}
}
