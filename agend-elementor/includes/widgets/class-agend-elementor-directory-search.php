<?php
/**
 * Elementor "Agend Directory Search" widget: a keyword and place search box
 * for the directory.
 *
 * @package Agend_Elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A thin adapter over the core surface: Content settings come from
 * agend_apps_records_surface_schema( 'directory-search' ), and the markup from
 * agend_apps_records_render_directory_search(). The Style tab is declared here
 * because it is CSS.
 *
 * Colours the theme tends to fight (the button and the location link) are
 * written to custom properties the stylesheet reads, so they win over a
 * theme's own `button:hover` rule; the rest set the property directly.
 */
class Agend_Elementor_Directory_Search extends \Elementor\Widget_Base {

	use Agend_Elementor_Field_Widget_Trait;

	public function get_name(): string {
		return 'agend-directory-search';
	}

	public function get_title(): string {
		return __( 'Agend Directory Search', 'agend-elementor' );
	}

	public function get_icon(): string {
		return 'eicon-search';
	}

	public function get_categories(): array {
		return array( Agend_Elementor::CATEGORY );
	}

	public function get_keywords(): array {
		return array( 'agend', 'directory', 'search', 'keyword', 'location', 'near me', 'hero' );
	}

	public function get_script_depends(): array {
		return Agend_Elementor_Schema_Controls::depends( 'directory-search', array( 'agend-apps-records-directory-search' ) );
	}

	public function get_style_depends(): array {
		return Agend_Elementor_Schema_Controls::depends( 'directory-search', array( 'agend-apps-records-directory-search' ) );
	}

	protected function register_controls(): void {
		Agend_Elementor_Schema_Controls::register( $this, agend_apps_records_surface_schema( 'directory-search' ), 'directory-search' );
		$this->register_style_controls();
	}

	/**
	 * Box-model controls for one element: padding and corner radius.
	 *
	 * @param string $prefix   Control name prefix.
	 * @param string $selector Selector below the widget wrapper.
	 */
	private function add_box_controls( string $prefix, string $selector ): void {
		$dimensions = \Elementor\Controls_Manager::DIMENSIONS;

		$this->add_responsive_control(
			$prefix . '_padding',
			array(
				'label'      => __( 'Padding', 'agend-elementor' ),
				'type'       => $dimensions,
				'size_units' => array( 'px', 'em', '%' ),
				'selectors'  => array( '{{WRAPPER}} ' . $selector => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);
		$this->add_responsive_control(
			$prefix . '_radius',
			array(
				'label'      => __( 'Corner radius', 'agend-elementor' ),
				'type'       => $dimensions,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array( '{{WRAPPER}} ' . $selector => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);
	}

	/**
	 * A colour control that sets one custom property on the widget's root.
	 *
	 * @param string $name     Control name.
	 * @param string $label    Control label.
	 * @param string $property Custom property the stylesheet reads.
	 */
	private function add_property_colour( string $name, string $label, string $property ): void {
		$this->add_control(
			$name,
			array(
				'label'     => $label,
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .agend-ds' => $property . ': {{VALUE}};' ),
			)
		);
	}

	/**
	 * The Style tab: panel, layout, fields, button and location link.
	 */
	private function register_style_controls(): void {
		$tab    = \Elementor\Controls_Manager::TAB_STYLE;
		$colour = \Elementor\Controls_Manager::COLOR;
		$slider = \Elementor\Controls_Manager::SLIDER;

		$this->start_controls_section(
			'section_style_panel',
			array(
				'label' => __( 'Panel', 'agend-elementor' ),
				'tab'   => $tab,
			)
		);
		$this->add_control(
			'panel_background',
			array(
				'label'     => __( 'Background', 'agend-elementor' ),
				'type'      => $colour,
				'selectors' => array( '{{WRAPPER}} .agend-ds__form' => 'background-color: {{VALUE}};' ),
			)
		);
		$this->add_box_controls( 'panel', '.agend-ds__form' );
		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'panel_shadow',
				'selector' => '{{WRAPPER}} .agend-ds__form',
			)
		);
		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_layout',
			array(
				'label' => __( 'Layout', 'agend-elementor' ),
				'tab'   => $tab,
			)
		);
		$this->add_responsive_control(
			'layout_direction',
			array(
				'label'                => __( 'Fields', 'agend-elementor' ),
				'type'                 => \Elementor\Controls_Manager::CHOOSE,
				'options'              => array(
					'row'    => array(
						'title' => __( 'Side by side', 'agend-elementor' ),
						'icon'  => 'eicon-arrow-right',
					),
					'column' => array(
						'title' => __( 'Stacked', 'agend-elementor' ),
						'icon'  => 'eicon-arrow-down',
					),
				),
				'selectors_dictionary' => array(
					'row'    => 'flex-direction: row; --agend-ds-basis: 220px;',
					'column' => 'flex-direction: column; --agend-ds-basis: auto;',
				),
				'selectors'            => array( '{{WRAPPER}} .agend-ds__form' => '{{VALUE}}' ),
				'description'          => __( 'Unset, the fields sit side by side and stack on narrow screens. Set a value per device to decide yourself.', 'agend-elementor' ),
			)
		);
		$this->add_responsive_control(
			'layout_gap',
			array(
				'label'      => __( 'Gap', 'agend-elementor' ),
				'type'       => $slider,
				'size_units' => array( 'px', 'em' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 48,
					),
				),
				'selectors'  => array( '{{WRAPPER}} .agend-ds__form' => 'gap: {{SIZE}}{{UNIT}};' ),
			)
		);
		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_fields',
			array(
				'label' => __( 'Fields', 'agend-elementor' ),
				'tab'   => $tab,
			)
		);
		$this->add_control(
			'field_background',
			array(
				'label'     => __( 'Background', 'agend-elementor' ),
				'type'      => $colour,
				'selectors' => array( '{{WRAPPER}} .agend-ds__field' => 'background-color: {{VALUE}};' ),
			)
		);
		$this->add_control(
			'field_text_colour',
			array(
				'label'     => __( 'Text colour', 'agend-elementor' ),
				'type'      => $colour,
				'selectors' => array( '{{WRAPPER}} .agend-ds__input' => 'color: {{VALUE}};' ),
			)
		);
		$this->add_control(
			'field_placeholder_colour',
			array(
				'label'     => __( 'Placeholder colour', 'agend-elementor' ),
				'type'      => $colour,
				// A dropdown showing its first, "any", option reads as a
				// placeholder too, so it takes the same colour.
				'selectors' => array(
					'{{WRAPPER}} .agend-ds__input::placeholder' => 'color: {{VALUE}}; opacity: 1;',
					'{{WRAPPER}} .agend-ds__select.is-empty'    => 'color: {{VALUE}};',
				),
			)
		);
		$this->add_control(
			'field_icon_colour',
			array(
				'label'     => __( 'Icon colour', 'agend-elementor' ),
				'type'      => $colour,
				'selectors' => array( '{{WRAPPER}} .agend-ds__field .agend-ds__icon' => 'color: {{VALUE}};' ),
			)
		);
		$this->add_responsive_control(
			'field_height',
			array(
				'label'      => __( 'Height', 'agend-elementor' ),
				'type'       => $slider,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 32,
						'max' => 96,
					),
				),
				'selectors'  => array( '{{WRAPPER}} .agend-ds__field' => 'min-height: {{SIZE}}{{UNIT}};' ),
			)
		);
		$this->add_box_controls( 'field', '.agend-ds__field' );
		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			array(
				'name'     => 'field_border',
				'selector' => '{{WRAPPER}} .agend-ds__field',
			)
		);
		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'field_typography',
				'selector' => '{{WRAPPER}} .agend-ds__input',
			)
		);
		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_button',
			array(
				'label' => __( 'Button', 'agend-elementor' ),
				'tab'   => $tab,
			)
		);
		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'button_typography',
				'selector' => '{{WRAPPER}} .agend-ds__button',
			)
		);
		$this->start_controls_tabs( 'button_states' );
		$this->start_controls_tab( 'button_normal', array( 'label' => __( 'Normal', 'agend-elementor' ) ) );
		$this->add_property_colour( 'button_text_colour', __( 'Text colour', 'agend-elementor' ), '--agend-ds-btn-text' );
		$this->add_property_colour( 'button_background', __( 'Background', 'agend-elementor' ), '--agend-ds-btn-bg' );
		$this->end_controls_tab();
		$this->start_controls_tab( 'button_hover', array( 'label' => __( 'Hover', 'agend-elementor' ) ) );
		$this->add_property_colour( 'button_text_colour_hover', __( 'Text colour', 'agend-elementor' ), '--agend-ds-btn-text-hover' );
		$this->add_property_colour( 'button_background_hover', __( 'Background', 'agend-elementor' ), '--agend-ds-btn-bg-hover' );
		$this->end_controls_tab();
		$this->end_controls_tabs();
		$this->add_box_controls( 'button', '.agend-ds__button' );
		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_locate',
			array(
				'label' => __( 'Use my location link', 'agend-elementor' ),
				'tab'   => $tab,
			)
		);
		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'locate_typography',
				'selector' => '{{WRAPPER}} .agend-ds__locate',
			)
		);
		$this->add_property_colour( 'locate_colour', __( 'Colour', 'agend-elementor' ), '--agend-ds-locate' );
		$this->add_property_colour( 'locate_colour_hover', __( 'Hover colour', 'agend-elementor' ), '--agend-ds-locate-hover' );
		$this->end_controls_section();
	}

	protected function render(): void {
		$gate = Agend_Elementor_Schema_Controls::render_gate( 'directory-search' );
		if ( null !== $gate ) {
			echo $gate; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the surface gate.
			return;
		}

		$s = Agend_Elementor_Global_Colours::resolve( $this->get_settings_for_display() );

		// The editor never runs the page script, so it shows the parts the
		// script would otherwise reveal.
		echo agend_apps_records_render_directory_search( $s, array( 'preview' => $this->is_editor() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the core renderer.
	}
}
