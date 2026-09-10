<?php
/**
 * Elementor "Agend Filter" widget: one catalogue filter control, placed in a
 * filter template that a catalogue widget renders.
 *
 * @package Agend_Elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The widget emits a labelled shell carrying its configuration; the catalogue
 * script builds the control inside it and wires changes to the catalogue's own
 * filter state. Keeping the control client-built is what lets an
 * endpoint-backed value list (categories, cities) stay per-account rather than
 * being baked into the saved template.
 *
 * Styling is deliberately minimal: the label and the control get plain classes
 * and Elementor's own typography, spacing and border controls do the rest.
 */
class Agend_Elementor_Filter extends \Elementor\Widget_Base {

	use Agend_Elementor_Field_Widget_Trait;

	public function get_name(): string {
		return 'agend-filter';
	}

	public function get_title(): string {
		return __( 'Agend Filter', 'agend-elementor' );
	}

	public function get_icon(): string {
		return 'eicon-filter';
	}

	public function get_categories(): array {
		return array( Agend_Elementor::CATEGORY );
	}

	public function get_keywords(): array {
		return array( 'agend', 'filter', 'facet', 'search', 'category', 'refine' );
	}

	public function get_style_depends(): array {
		return array( 'agend-apps-records-filters' );
	}

	protected function register_controls(): void {
		Agend_Elementor_Schema_Controls::register( $this, agend_apps_records_surface_schema( 'filter' ) );

		$this->start_controls_section(
			'section_style',
			array(
				'label' => __( 'Filter', 'agend-elementor' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'label_colour',
			array(
				'label'     => __( 'Label colour', 'agend-elementor' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .agend-filter__label' => 'color: {{VALUE}};' ),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'label_typography',
				'selector' => '{{WRAPPER}} .agend-filter__label',
			)
		);

		$this->add_responsive_control(
			'label_spacing',
			array(
				'label'      => __( 'Space below label', 'agend-elementor' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 40 ) ),
				'selectors'  => array( '{{WRAPPER}} .agend-filter__label' => 'margin-bottom: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_control(
			'control_colour',
			array(
				'label'     => __( 'Control text colour', 'agend-elementor' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .agend-filter__control' => 'color: {{VALUE}};' ),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'control_typography',
				'selector' => '{{WRAPPER}} .agend-filter__control',
			)
		);

		$this->end_controls_section();
	}

	protected function render(): void {
		$s       = $this->get_settings_for_display();
		$context = Agend_Apps_Records_Filter_Context::type();

		switch ( agend_apps_records_filter_render_reason( $s, $context ) ) {
			case 'wrong_catalogue':
				$this->render_editor_notice( __( 'This filter belongs to a different catalogue, so it renders nothing here.', 'agend-elementor' ) );
				return;

			case 'unconfigured':
				$this->render_editor_notice( __( 'This filter is not configured yet. A custom field filter needs its field key.', 'agend-elementor' ) );
				return;

			case 'missing_field_key':
				$this->render_editor_notice( __( 'Enter the custom field key this filter targets.', 'agend-elementor' ) );
				return;

			case 'no_choices':
				$this->render_editor_notice( __( 'This filter needs the choices you define: its values cannot be listed from the API yet. Add choices under Values.', 'agend-elementor' ) );
				return;
		}

		// The editor draws the stand-in control markup assets/js/filters.js
		// would otherwise build at runtime, so a designer can see and style
		// exactly what a visitor will get.
		echo agend_apps_records_render_filter( $s, array( 'preview' => $this->is_editor() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the core renderer.
	}
}
