<?php
/**
 * Elementor "Agend Link / Button" widget: the actions a card or detail page
 * offers for the current record.
 *
 * @package Agend_Elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The `register` action emits the same `data-agend-event-slug` button the
 * built-in detail uses, so the existing registration flow in
 * assets/js/events-catalogue.js hydrates it without changes.
 */
class Agend_Elementor_Record_Link extends \Elementor\Widget_Base {

	use Agend_Elementor_Field_Widget_Trait;

	public function get_name(): string {
		return 'agend-record-link';
	}

	public function get_title(): string {
		return __( 'Agend Link / Button', 'agend-elementor' );
	}

	public function get_icon(): string {
		return 'eicon-button';
	}

	public function get_categories(): array {
		return array( Agend_Elementor::CATEGORY );
	}

	public function get_keywords(): array {
		return array( 'agend', 'button', 'link', 'register', 'enrol', 'detail' );
	}

	public function get_style_depends(): array {
		return array( 'agend-apps-records-record-fields' );
	}

	protected function register_controls(): void {
		Agend_Elementor_Schema_Controls::register( $this, agend_apps_records_surface_schema( 'record-link' ) );

		$this->start_controls_section(
			'section_style',
			array(
				'label' => __( 'Button', 'agend-elementor' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'typography',
				'selector' => '{{WRAPPER}} .agend-record-link',
			)
		);

		$this->start_controls_tabs( 'tabs_button' );

		$this->start_controls_tab( 'tab_normal', array( 'label' => __( 'Normal', 'agend-elementor' ) ) );
		$this->add_control(
			'text_colour',
			array(
				'label'     => __( 'Text colour', 'agend-elementor' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .agend-record-link' => 'color: {{VALUE}};' ),
			)
		);
		$this->add_control(
			'background_colour',
			array(
				'label'     => __( 'Background colour', 'agend-elementor' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .agend-record-link--button' => 'background-color: {{VALUE}};' ),
			)
		);
		$this->end_controls_tab();

		$this->start_controls_tab( 'tab_hover', array( 'label' => __( 'Hover', 'agend-elementor' ) ) );
		$this->add_control(
			'text_colour_hover',
			array(
				'label'     => __( 'Text colour', 'agend-elementor' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .agend-record-link:hover, {{WRAPPER}} .agend-record-link:focus' => 'color: {{VALUE}};' ),
			)
		);
		$this->add_control(
			'background_colour_hover',
			array(
				'label'     => __( 'Background colour', 'agend-elementor' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .agend-record-link--button:hover, {{WRAPPER}} .agend-record-link--button:focus' => 'background-color: {{VALUE}};' ),
			)
		);
		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			array(
				'name'      => 'border',
				'selector'  => '{{WRAPPER}} .agend-record-link--button',
				'separator' => 'before',
			)
		);

		$this->add_responsive_control(
			'border_radius',
			array(
				'label'      => __( 'Border radius', 'agend-elementor' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array( '{{WRAPPER}} .agend-record-link--button' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'padding',
			array(
				'label'      => __( 'Padding', 'agend-elementor' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array( '{{WRAPPER}} .agend-record-link--button' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'box_shadow',
				'selector' => '{{WRAPPER}} .agend-record-link--button',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * The 'preview'/'preview_type' opts this widget's record-context reads
	 * always need, built the same way
	 * Agend_Elementor_Field_Widget_Trait::resolve_context() builds them
	 * internally. The core render/reason functions take these opts directly
	 * rather than a resolved context (a block passes its own), so this widget
	 * has to construct them itself.
	 *
	 * @return array{preview: bool, preview_type: string}
	 */
	private function render_opts(): array {
		$is_editor = $this->is_editor();

		return array(
			'preview'      => $is_editor,
			'preview_type' => $is_editor ? $this->preview_type() : '',
		);
	}

	protected function render(): void {
		$s    = $this->get_settings_for_display();
		$opts = $this->render_opts();

		switch ( agend_apps_records_record_link_render_reason( $s, $opts ) ) {
			case 'ical_wrong_type':
				$this->render_editor_notice( __( 'Add to calendar is only available for events.', 'agend-elementor' ) );
				return;

			case 'enrol_wrong_type':
				$this->render_editor_notice( __( 'Enrol is only available for courses.', 'agend-elementor' ) );
				return;

			case 'register_wrong_type':
				$this->render_editor_notice( __( 'Register is only available for events.', 'agend-elementor' ) );
				return;
		}

		echo agend_apps_records_render_record_link( $s, $opts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the core renderer.
	}
}
