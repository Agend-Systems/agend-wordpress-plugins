<?php
/**
 * Elementor "Agend Pills" widget: a record's categories, tags or other terms,
 * one styled pill per term.
 *
 * @package Agend_Elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The pill count follows the record, so an event with three tags renders three
 * pills from one widget. An Agend Field styled to look like a pill cannot do
 * that: it renders one element holding a joined list, so three tags become one
 * wide chip reading "a, b, c".
 *
 * Elementor omits a widget entirely when its render produces no output, so a
 * record with no terms leaves nothing behind either way.
 */
class Agend_Elementor_Record_Pills extends \Elementor\Widget_Base {

	use Agend_Elementor_Field_Widget_Trait;

	public function get_name(): string {
		return 'agend-record-pills';
	}

	public function get_title(): string {
		return __( 'Agend Pills', 'agend-elementor' );
	}

	public function get_icon(): string {
		return 'eicon-tags';
	}

	public function get_categories(): array {
		return array( Agend_Elementor::CATEGORY );
	}

	public function get_keywords(): array {
		return array( 'agend', 'pill', 'tag', 'category', 'badge', 'chip', 'taxonomy' );
	}

	public function get_style_depends(): array {
		return array( 'agend-elementor-record-fields' );
	}

	protected function register_controls(): void {
		$this->start_controls_section(
			'section_pills',
			array(
				'label' => __( 'Pills', 'agend-elementor' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->record_type_control();

		$this->add_control(
			'field',
			array(
				'label'       => __( 'Terms', 'agend-elementor' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'default'     => 'common:category',
				'groups'      => agend_elementor_pill_field_options(),
				'label_block' => true,
				'description' => __( 'A list field renders one pill per term. A single-value field renders one pill.', 'agend-elementor' ),
			)
		);

		$this->add_control(
			'max_items',
			array(
				'label'       => __( 'Maximum pills', 'agend-elementor' ),
				'type'        => \Elementor\Controls_Manager::NUMBER,
				'default'     => 0,
				'min'         => 0,
				'description' => __( '0 shows every term.', 'agend-elementor' ),
			)
		);

		$this->add_control(
			'link_to_detail',
			array(
				'label'   => __( 'Link pills to the detail page', 'agend-elementor' ),
				'type'    => \Elementor\Controls_Manager::SWITCHER,
				'default' => '',
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_pill_style',
			array(
				'label' => __( 'Pills', 'agend-elementor' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'align',
			array(
				'label'     => __( 'Alignment', 'agend-elementor' ),
				'type'      => \Elementor\Controls_Manager::CHOOSE,
				'options'   => array(
					'flex-start' => array( 'title' => __( 'Left', 'agend-elementor' ), 'icon' => 'eicon-text-align-left' ),
					'center'     => array( 'title' => __( 'Centre', 'agend-elementor' ), 'icon' => 'eicon-text-align-center' ),
					'flex-end'   => array( 'title' => __( 'Right', 'agend-elementor' ), 'icon' => 'eicon-text-align-right' ),
				),
				'default'   => 'flex-start',
				'selectors' => array( '{{WRAPPER}} .agend-pills' => 'justify-content: {{VALUE}};' ),
			)
		);

		$this->add_responsive_control(
			'gap',
			array(
				'label'      => __( 'Gap between pills', 'agend-elementor' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 40 ) ),
				'default'    => array( 'size' => 6, 'unit' => 'px' ),
				'selectors'  => array( '{{WRAPPER}} .agend-pills' => 'gap: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_control(
			'pill_colour',
			array(
				'label'     => __( 'Text colour', 'agend-elementor' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#FF6B55',
				'selectors' => array( '{{WRAPPER}} .agend-pill' => 'color: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'pill_background',
			array(
				'label'     => __( 'Background colour', 'agend-elementor' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => 'rgba(255, 107, 85, 0.12)',
				'selectors' => array( '{{WRAPPER}} .agend-pill' => 'background-color: {{VALUE}};' ),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'typography',
				'selector' => '{{WRAPPER}} .agend-pill',
			)
		);

		$this->add_responsive_control(
			'pill_padding',
			array(
				'label'      => __( 'Padding', 'agend-elementor' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'default'    => array( 'top' => '3', 'right' => '8', 'bottom' => '3', 'left' => '8', 'unit' => 'px', 'isLinked' => false ),
				'selectors'  => array( '{{WRAPPER}} .agend-pill' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'pill_radius',
			array(
				'label'      => __( 'Border radius', 'agend-elementor' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'default'    => array( 'top' => '999', 'right' => '999', 'bottom' => '999', 'left' => '999', 'unit' => 'px', 'isLinked' => true ),
				'selectors'  => array( '{{WRAPPER}} .agend-pill' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			array(
				'name'     => 'pill_border',
				'selector' => '{{WRAPPER}} .agend-pill',
			)
		);

		$this->end_controls_section();
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

		$key = (string) ( $s['field'] ?? 'common:category' );
		if ( ! agend_elementor_field_applies( $key, $ctx['type'] ) ) {
			$this->render_editor_notice( __( 'These terms do not exist on the record type this template renders.', 'agend-elementor' ) );
			return;
		}

		$terms = agend_elementor_field_terms( $key, $ctx['type'], $ctx['record'], $ctx['extra'] );
		$max   = (int) ( $s['max_items'] ?? 0 );
		if ( $max > 0 ) {
			$terms = array_slice( $terms, 0, $max );
		}

		if ( empty( $terms ) ) {
			$this->render_editor_notice( __( 'This record has no terms for this field, so nothing renders here on the live site.', 'agend-elementor' ) );
			return;
		}

		$link = ( 'yes' === ( $s['link_to_detail'] ?? '' ) && empty( $ctx['extra']['in_card_link'] ) )
			? (string) ( agend_elementor_field_value( 'common:detail_url', $ctx['type'], $ctx['record'], $ctx['extra'] ) ?? '' )
			: '';

		echo '<div class="agend-pills">';
		foreach ( $terms as $term ) {
			if ( '' !== $link && '#' !== $link ) {
				echo '<a class="agend-pill" href="' . esc_url( $link ) . '">' . esc_html( $term ) . '</a>';
			} else {
				echo '<span class="agend-pill">' . esc_html( $term ) . '</span>';
			}
		}
		echo '</div>';
	}
}
