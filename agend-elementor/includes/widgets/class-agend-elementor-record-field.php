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
		$this->start_controls_section(
			'section_field',
			array(
				'label' => __( 'Field', 'agend-elementor' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->record_type_control();

		$this->add_control(
			'field',
			array(
				'label'       => __( 'Field', 'agend-elementor' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'default'     => 'common:title',
				'groups'      => agend_apps_records_field_options(),
				'label_block' => true,
				'description' => __( 'Common fields work in both event and course templates. Event and Course fields render only inside a template of that type.', 'agend-elementor' ),
			)
		);

		$this->add_control(
			'custom_field_key',
			array(
				'label'       => __( 'Custom field key', 'agend-elementor' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => '',
				'description' => __( 'The key as configured in Agend, for example education_level. Which custom fields a visitor receives depends on their entitlements, so this renders empty for a visitor who is not entitled to it.', 'agend-elementor' ),
				'condition'   => array( 'field' => array( 'common:custom_field', 'common:custom_field_label' ) ),
			)
		);

		$this->add_control(
			'html_tag',
			array(
				'label'   => __( 'HTML tag', 'agend-elementor' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'div',
				'options' => array(
					'div'  => 'div',
					'span' => 'span',
					'p'    => 'p',
					'h1'   => 'H1',
					'h2'   => 'H2',
					'h3'   => 'H3',
					'h4'   => 'H4',
					'h5'   => 'H5',
					'h6'   => 'H6',
				),
			)
		);

		$this->add_control(
			'before_text',
			array(
				'label'       => __( 'Text before', 'agend-elementor' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => '',
				'description' => __( 'Shown before the value, for example "From " ahead of a price.', 'agend-elementor' ),
			)
		);

		$this->add_control(
			'after_text',
			array(
				'label'   => __( 'Text after', 'agend-elementor' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => '',
			)
		);

		$this->add_control(
			'fallback_text',
			array(
				'label'       => __( 'Fallback text', 'agend-elementor' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => '',
				'description' => __( 'Shown when the record has no value for this field. Leave empty to render nothing.', 'agend-elementor' ),
			)
		);

		$this->add_control(
			'link_to_detail',
			array(
				'label'       => __( 'Link to detail page', 'agend-elementor' ),
				'type'        => \Elementor\Controls_Manager::SWITCHER,
				'default'     => '',
				'description' => __( 'Ignored when the whole card is already a link.', 'agend-elementor' ),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_format',
			array(
				'label' => __( 'Formatting', 'agend-elementor' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'date_format',
			array(
				'label'       => __( 'Date format', 'agend-elementor' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'default'     => '',
				'options'     => array(
					''         => __( 'Site default', 'agend-elementor' ),
					'j M Y'    => '6 Jul 2026',
					'D, j M Y' => 'Mon, 6 Jul 2026',
					'l, j F Y' => 'Monday, 6 July 2026',
					'j M'      => '6 Jul',
					'j'        => '6',
					'M'        => 'Jul',
					'g:i a'    => '9:00 am',
					'custom'   => __( 'Custom', 'agend-elementor' ),
				),
				'description' => __( 'Applies to date fields. Date range and date-and-time fields use their fixed format.', 'agend-elementor' ),
			)
		);

		$this->add_control(
			'date_format_custom',
			array(
				'label'       => __( 'Custom date format', 'agend-elementor' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => 'j M Y',
				'description' => __( 'A PHP date format string.', 'agend-elementor' ),
				'condition'   => array( 'date_format' => 'custom' ),
			)
		);

		$this->add_control(
			'price_free_label',
			array(
				'label'       => __( 'Free label', 'agend-elementor' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Free', 'agend-elementor' ),
				'description' => __( 'Applies to price fields when the price is zero.', 'agend-elementor' ),
			)
		);

		$this->add_control(
			'list_separator',
			array(
				'label'       => __( 'List separator', 'agend-elementor' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => ', ',
				'description' => __( 'Applies to list fields such as categories and learning outcomes.', 'agend-elementor' ),
			)
		);

		$this->add_control(
			'list_max',
			array(
				'label'       => __( 'Maximum list items', 'agend-elementor' ),
				'type'        => \Elementor\Controls_Manager::NUMBER,
				'default'     => 0,
				'min'         => 0,
				'description' => __( '0 shows every item.', 'agend-elementor' ),
			)
		);

		$this->add_control(
			'bool_true',
			array(
				'label'       => __( 'Text when true', 'agend-elementor' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Yes', 'agend-elementor' ),
				'description' => __( 'Applies to yes/no fields such as Sold out.', 'agend-elementor' ),
			)
		);

		$this->add_control(
			'bool_false',
			array(
				'label'       => __( 'Text when false', 'agend-elementor' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => '',
				'description' => __( 'Leave empty to render nothing when false.', 'agend-elementor' ),
			)
		);

		$this->add_control(
			'number_suffix',
			array(
				'label'       => __( 'Number suffix', 'agend-elementor' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => '',
				'description' => __( 'Applies to number fields, for example " lessons" or "%".', 'agend-elementor' ),
			)
		);

		$this->add_control(
			'truncate_chars',
			array(
				'label'       => __( 'Truncate to characters', 'agend-elementor' ),
				'type'        => \Elementor\Controls_Manager::NUMBER,
				'default'     => 0,
				'min'         => 0,
				'description' => __( 'Applies to text and HTML fields. HTML fields are reduced to plain text when truncated. 0 keeps the full value.', 'agend-elementor' ),
			)
		);

		$this->end_controls_section();

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

		if ( $ctx['mismatch'] ) {
			$this->render_mismatch_notice( (string) $s['record_type'], $ctx['type'] );
			return;
		}
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

		$extra = $ctx['extra'];
		$extra['custom_field_key'] = (string) ( $s['custom_field_key'] ?? '' );

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

		echo '<' . $tag . ' class="' . esc_attr( implode( ' ', $classes ) ) . '">' . $inner . '</' . $tag . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $inner is escaped by agend_apps_records_format_field().
	}
}
