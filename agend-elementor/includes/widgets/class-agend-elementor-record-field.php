<?php
/**
 * Elementor "Agend Field" widget: one value from the current event or course
 * record, for use inside card and detail templates.
 *
 * @package Agend_Elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Outputs a single record field (title, date, price, venue ...) with the
 * formatting options that field kind needs. Which record is "current" is
 * decided by Agend_Elementor_Template_Renderer, not by this widget.
 */
class Agend_Elementor_Record_Field extends \Elementor\Widget_Base {

	use Agend_Elementor_Field_Widget_Trait;

	public function get_name(): string {
		return 'agend-record-field';
	}

	public function get_title(): string {
		return __( 'Agend Field', 'agend-elementor' );
	}

	public function get_icon(): string {
		return 'eicon-text';
	}

	public function get_categories(): array {
		return array( Agend_Elementor::CATEGORY );
	}

	public function get_keywords(): array {
		return array( 'agend', 'event', 'course', 'field', 'dynamic', 'template' );
	}

	public function get_style_depends(): array {
		return array( 'agend-elementor-record-fields' );
	}

	protected function register_controls(): void {
		Agend_Elementor_Schema_Controls::register( $this, agend_apps_records_surface_schema( 'record-field' ) );

		$this->start_controls_section(
			'section_style',
			array(
				'label' => __( 'Text', 'agend-elementor' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'align',
			array(
				'label'     => __( 'Alignment', 'agend-elementor' ),
				'type'      => \Elementor\Controls_Manager::CHOOSE,
				'options'   => array(
					'left'   => array( 'title' => __( 'Left', 'agend-elementor' ), 'icon' => 'eicon-text-align-left' ),
					'center' => array( 'title' => __( 'Centre', 'agend-elementor' ), 'icon' => 'eicon-text-align-center' ),
					'right'  => array( 'title' => __( 'Right', 'agend-elementor' ), 'icon' => 'eicon-text-align-right' ),
				),
				'selectors' => array( '{{WRAPPER}} .agend-field' => 'text-align: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'colour',
			array(
				'label'     => __( 'Colour', 'agend-elementor' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .agend-field, {{WRAPPER}} .agend-field a' => 'color: {{VALUE}};' ),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'typography',
				'selector' => '{{WRAPPER}} .agend-field',
			)
		);

		$this->add_control(
			'label_style_heading',
			array(
				'label'     => __( 'Label', 'agend-elementor' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
				'condition' => array( 'show_label' => 'yes' ),
			)
		);

		$this->add_control(
			'label_colour',
			array(
				'label'     => __( 'Label colour', 'agend-elementor' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .agend-field__label' => 'color: {{VALUE}};' ),
				'condition' => array( 'show_label' => 'yes' ),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'      => 'label_typography',
				'selector'  => '{{WRAPPER}} .agend-field__label',
				'condition' => array( 'show_label' => 'yes' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Formatting options for agend_apps_records_format_field() from the controls.
	 *
	 * @param array $s Widget settings.
	 * @return array
	 */
	private function format_options( array $s ): array {
		$date_format = (string) ( $s['date_format'] ?? '' );
		if ( 'custom' === $date_format ) {
			$date_format = (string) ( $s['date_format_custom'] ?? '' );
		}
		return array(
			'date_format'      => $date_format,
			'price_free_label' => (string) ( $s['price_free_label'] ?? '' ),
			'list_separator'   => (string) ( $s['list_separator'] ?? ', ' ),
			'list_max'         => (int) ( $s['list_max'] ?? 0 ),
			'bool_true'        => (string) ( $s['bool_true'] ?? '' ),
			'bool_false'       => (string) ( $s['bool_false'] ?? '' ),
			'number_suffix'    => (string) ( $s['number_suffix'] ?? '' ),
			'truncate'         => (int) ( $s['truncate_chars'] ?? 0 ),
		);
	}

	protected function render(): void {
		$s   = $this->get_settings_for_display();
		$ctx = $this->resolve_context();

		if ( '' === $ctx['type'] ) {
			return;
		}

		$key  = (string) ( $s['field'] ?? 'common:title' );
		$kind = agend_apps_records_field_kind( $key );
		if ( '' === $kind ) {
			return;
		}
		if ( ! agend_apps_records_field_applies( $key, $ctx['type'] ) ) {
			$this->render_editor_notice( __( 'This field does not exist on the record type this template renders.', 'agend-elementor' ) );
			return;
		}

		$extra                     = $ctx['extra'];
		$extra['custom_field_key'] = $this->custom_field_key( $s );

		$html = agend_apps_records_render_field( $key, $ctx['type'], $ctx['record'], $extra, $this->format_options( $s ) );
		if ( '' === $html ) {
			$fallback = trim( (string) ( $s['fallback_text'] ?? '' ) );
			if ( '' === $fallback ) {
				return;
			}
			$html = esc_html( $fallback );
		}

		$before = (string) ( $s['before_text'] ?? '' );
		$after  = (string) ( $s['after_text'] ?? '' );
		if ( 'html' === $kind ) {
			$inner = $html;
		} else {
			$inner = esc_html( $before ) . $html . esc_html( $after );
		}

		// A card that is already one big link cannot contain another anchor.
		$link = ( 'yes' === ( $s['link_to_detail'] ?? '' ) && empty( $extra['in_card_link'] ) )
			? (string) agend_apps_records_field_value( 'common:detail_url', $ctx['type'], $ctx['record'], $extra )
			: '';
		if ( '' !== $link && '#' !== $link ) {
			$inner = '<a class="agend-field__link" href="' . esc_url( $link ) . '">' . $inner . '</a>';
		}

		$tag = (string) ( $s['html_tag'] ?? 'div' );
		if ( ! in_array( $tag, array( 'div', 'span', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) ) {
			$tag = 'div';
		}

		$classes = array( 'agend-field', 'agend-field--' . $kind, 'agend-field--' . str_replace( ':', '-', $key ) );
		if ( $ctx['is_preview'] ) {
			$classes[] = 'agend-field--preview';
		}

		$label = $this->label_html( $s, $key, $ctx['record'], $extra );
		if ( '' !== $label ) {
			$classes[] = 'agend-field--labelled';
			if ( 'yes' === ( $s['label_block_display'] ?? '' ) ) {
				$classes[] = 'agend-field--label-block';
			}
		}

		echo '<' . $tag . ' class="' . esc_attr( implode( ' ', $classes ) ) . '">' . $label . $inner . '</' . $tag . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $label and $inner are escaped where they are built.
	}

	/**
	 * The custom field key this widget reads.
	 *
	 * The picker wins when it names a key; the free-text control is what an
	 * author uses for a field this site's key cannot enumerate, or one that
	 * does not exist yet. A widget saved before the picker existed has only
	 * the free-text value, which is why the picker's empty option leaves that
	 * control visible rather than hiding a key the render is still using.
	 *
	 * @param array $s Widget settings.
	 * @return string
	 */
	private function custom_field_key( array $s ): string {
		$choice = trim( (string) ( $s['custom_field_key_choice'] ?? '' ) );

		return '' !== $choice ? $choice : trim( (string) ( $s['custom_field_key'] ?? '' ) );
	}

	/**
	 * The label element, or '' when the widget is not showing one.
	 *
	 * @param array  $s      Widget settings.
	 * @param string $key    Field key.
	 * @param array  $record The record.
	 * @param array  $extra  Render context.
	 * @return string Escaped HTML.
	 */
	private function label_html( array $s, string $key, array $record, array $extra ): string {
		if ( 'yes' !== ( $s['show_label'] ?? '' ) ) {
			return '';
		}

		$text = trim( (string) ( $s['label_text'] ?? '' ) );
		if ( '' === $text ) {
			$text = agend_apps_records_field_label( $key, $record, $extra );
		}
		if ( '' === $text ) {
			return '';
		}

		return '<span class="agend-field__label">' . esc_html( $text )
			. esc_html( (string) ( $s['label_separator'] ?? '' ) ) . '</span>';
	}
}
