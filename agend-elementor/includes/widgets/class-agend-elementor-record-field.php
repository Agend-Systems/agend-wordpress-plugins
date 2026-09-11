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
		return array( 'agend-apps-records-record-fields' );
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
	 * Echoes the surface, rendered by Agend Apps Core from this widget's
	 * settings. The "renders nothing, and why" conditions live in
	 * agend_apps_records_record_field_render_reason(); this widget only maps
	 * the reason it gets back to the translated notice it already owned.
	 */
	protected function render(): void {
		$s = $this->get_settings_for_display();

		$is_editor = $this->is_editor();
		$opts      = array(
			'preview'      => $is_editor,
			'preview_type' => $is_editor ? $this->preview_type() : '',
		);

		switch ( agend_apps_records_record_field_render_reason( $s, $opts ) ) {
			case 'field_not_applicable':
				$this->render_editor_notice( __( 'This field does not exist on the record type this template renders.', 'agend-elementor' ) );
				return;
		}

		echo agend_apps_records_render_record_field( $s, $opts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the core renderer.
	}
}
