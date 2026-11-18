<?php
/**
 * Elementor "Agend Filter Shortcuts" widget: a grid of tiles that each apply
 * one value of one directory filter.
 *
 * @package Agend_Elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A thin adapter over the core surface: Content settings come from
 * agend_apps_records_surface_schema( 'filter-shortcuts' ), and the markup from
 * agend_apps_records_render_filter_shortcuts(). The tile list and the shared
 * icon are adapter controls, because an icon picker is Elementor's own. The
 * Style tab is declared here because it is CSS.
 *
 * Tile colours are written to custom properties the stylesheet reads, so a
 * theme's own `a:hover` colours cannot override them and a tile stays readable
 * in every state.
 */
class Agend_Elementor_Filter_Shortcuts extends \Elementor\Widget_Base {

	use Agend_Elementor_Field_Widget_Trait;

	public function get_name(): string {
		return 'agend-filter-shortcuts';
	}

	public function get_title(): string {
		return __( 'Agend Filter Shortcuts', 'agend-elementor' );
	}

	public function get_icon(): string {
		return 'eicon-gallery-grid';
	}

	public function get_categories(): array {
		return array( Agend_Elementor::CATEGORY );
	}

	public function get_keywords(): array {
		return array( 'agend', 'directory', 'filter', 'shortcut', 'tiles', 'browse', 'category', 'profession' );
	}

	public function get_script_depends(): array {
		return Agend_Elementor_Schema_Controls::depends( 'filter-shortcuts', array( 'agend-apps-records-filter-shortcuts' ) );
	}

	public function get_style_depends(): array {
		return Agend_Elementor_Schema_Controls::depends( 'filter-shortcuts', array( 'agend-apps-records-filter-shortcuts' ) );
	}

	/**
	 * The controls the shared vocabulary cannot describe.
	 *
	 * @param string $name Schema field name.
	 */
	public function register_adapter_control( string $name ): void {
		if ( 'items' === $name ) {
			$repeater = new \Elementor\Repeater();
			$repeater->add_control(
				'label',
				array(
					'label'       => __( 'Label', 'agend-elementor' ),
					'type'        => \Elementor\Controls_Manager::TEXT,
					'label_block' => true,
				)
			);
			$repeater->add_control(
				'value',
				array(
					'label'       => __( 'Value', 'agend-elementor' ),
					'type'        => \Elementor\Controls_Manager::TEXT,
					'label_block' => true,
					'description' => __( 'Leave empty to use the label.', 'agend-elementor' ),
				)
			);
			$repeater->add_control(
				'icon',
				array(
					'label' => __( 'Icon', 'agend-elementor' ),
					'type'  => \Elementor\Controls_Manager::ICONS,
				)
			);

			$this->add_control(
				'items',
				array(
					'label'       => __( 'Tiles', 'agend-elementor' ),
					'type'        => \Elementor\Controls_Manager::REPEATER,
					'fields'      => $repeater->get_controls(),
					'title_field' => '{{{ label }}}',
					'default'     => array(),
					'condition'   => array( 'source' => 'manual' ),
				)
			);
			return;
		}

		if ( 'all_icon' === $name ) {
			$this->add_control(
				'all_icon',
				array(
					'label'     => __( 'Icon for every tile', 'agend-elementor' ),
					'type'      => \Elementor\Controls_Manager::ICONS,
					'condition' => array( 'source' => 'all' ),
				)
			);
		}
	}

	protected function register_controls(): void {
		Agend_Elementor_Schema_Controls::register( $this, agend_apps_records_surface_schema( 'filter-shortcuts' ), 'filter-shortcuts' );
		$this->register_style_controls();
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
				'selectors' => array( '{{WRAPPER}} .agend-fs' => $property . ': {{VALUE}};' ),
			)
		);
	}

	/**
	 * Background, border and text colours for one tile state, as a tab.
	 *
	 * @param string $id       State id: 'normal', 'hover' or 'active'.
	 * @param string $label    Tab label.
	 * @param string $suffix   Custom property suffix: '', '-hover' or '-active'.
	 */
	private function add_state_tab( string $id, string $label, string $suffix ): void {
		$this->start_controls_tab( 'tile_' . $id, array( 'label' => $label ) );
		$this->add_property_colour( 'tile_bg_' . $id, __( 'Background', 'agend-elementor' ), '--agend-fs-bg' . $suffix );
		$this->add_property_colour( 'tile_border_' . $id, __( 'Border colour', 'agend-elementor' ), '--agend-fs-border' . $suffix );
		$this->add_property_colour( 'tile_text_' . $id, __( 'Text colour', 'agend-elementor' ), '--agend-fs-text' . $suffix );
		$this->end_controls_tab();
	}

	/**
	 * The Style tab: grid, tiles in each state, icon and label.
	 */
	private function register_style_controls(): void {
		$tab        = \Elementor\Controls_Manager::TAB_STYLE;
		$slider     = \Elementor\Controls_Manager::SLIDER;
		$dimensions = \Elementor\Controls_Manager::DIMENSIONS;

		$this->start_controls_section(
			'section_style_grid',
			array(
				'label' => __( 'Grid', 'agend-elementor' ),
				'tab'   => $tab,
			)
		);
		$this->add_responsive_control(
			'grid_gap',
			array(
				'label'      => __( 'Gap', 'agend-elementor' ),
				'type'       => $slider,
				'size_units' => array( 'px', 'em' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 64,
					),
				),
				'selectors'  => array( '{{WRAPPER}} .agend-fs__grid' => 'gap: {{SIZE}}{{UNIT}};' ),
			)
		);
		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_tile',
			array(
				'label' => __( 'Tile', 'agend-elementor' ),
				'tab'   => $tab,
			)
		);
		$this->add_responsive_control(
			'tile_padding',
			array(
				'label'      => __( 'Padding', 'agend-elementor' ),
				'type'       => $dimensions,
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array( '{{WRAPPER}} .agend-fs .agend-fs__tile' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);
		$this->add_responsive_control(
			'tile_radius',
			array(
				'label'      => __( 'Corner radius', 'agend-elementor' ),
				'type'       => $dimensions,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array( '{{WRAPPER}} .agend-fs .agend-fs__tile' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);
		$this->add_responsive_control(
			'tile_border_width',
			array(
				'label'      => __( 'Border width', 'agend-elementor' ),
				'type'       => $slider,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 8,
					),
				),
				'selectors'  => array( '{{WRAPPER}} .agend-fs' => '--agend-fs-border-width: {{SIZE}}{{UNIT}};' ),
			)
		);
		$this->add_responsive_control(
			'tile_align',
			array(
				'label'                => __( 'Alignment', 'agend-elementor' ),
				'type'                 => \Elementor\Controls_Manager::CHOOSE,
				'options'              => array(
					'left'   => array(
						'title' => __( 'Left', 'agend-elementor' ),
						'icon'  => 'eicon-text-align-left',
					),
					'center' => array(
						'title' => __( 'Centre', 'agend-elementor' ),
						'icon'  => 'eicon-text-align-center',
					),
				),
				'selectors_dictionary' => array(
					'left'   => 'align-items: flex-start; text-align: left;',
					'center' => 'align-items: center; text-align: center;',
				),
				'selectors'            => array( '{{WRAPPER}} .agend-fs .agend-fs__tile' => '{{VALUE}}' ),
			)
		);
		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'tile_shadow',
				'selector' => '{{WRAPPER}} .agend-fs .agend-fs__tile',
			)
		);
		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'tile_shadow_hover',
				'label'    => __( 'Hover box shadow', 'agend-elementor' ),
				'selector' => '{{WRAPPER}} .agend-fs .agend-fs__tile:hover, {{WRAPPER}} .agend-fs .agend-fs__tile:focus',
			)
		);
		$this->add_property_colour( 'tile_focus_ring', __( 'Focus ring colour', 'agend-elementor' ), '--agend-fs-focus' );

		$this->start_controls_tabs( 'tile_states' );
		$this->add_state_tab( 'normal', __( 'Normal', 'agend-elementor' ), '' );
		$this->add_state_tab( 'hover', __( 'Hover', 'agend-elementor' ), '-hover' );
		$this->add_state_tab( 'active', __( 'Active', 'agend-elementor' ), '-active' );
		$this->end_controls_tabs();
		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_icon',
			array(
				'label' => __( 'Icon', 'agend-elementor' ),
				'tab'   => $tab,
			)
		);
		$this->add_responsive_control(
			'icon_box',
			array(
				'label'      => __( 'Box size', 'agend-elementor' ),
				'type'       => $slider,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 16,
						'max' => 120,
					),
				),
				'selectors'  => array( '{{WRAPPER}} .agend-fs' => '--agend-fs-icon-box: {{SIZE}}{{UNIT}};' ),
			)
		);
		$this->add_responsive_control(
			'icon_size',
			array(
				'label'      => __( 'Icon size', 'agend-elementor' ),
				'type'       => $slider,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 8,
						'max' => 80,
					),
				),
				'selectors'  => array( '{{WRAPPER}} .agend-fs__icon' => 'font-size: {{SIZE}}{{UNIT}};' ),
			)
		);
		$this->add_responsive_control(
			'icon_radius',
			array(
				'label'      => __( 'Corner radius', 'agend-elementor' ),
				'type'       => $dimensions,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array( '{{WRAPPER}} .agend-fs__icon' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);
		$this->add_property_colour( 'icon_colour', __( 'Icon colour', 'agend-elementor' ), '--agend-fs-icon' );
		$this->add_property_colour( 'icon_background', __( 'Background', 'agend-elementor' ), '--agend-fs-icon-bg' );
		$this->add_property_colour( 'icon_background_hover', __( 'Hover background', 'agend-elementor' ), '--agend-fs-icon-bg-hover' );
		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_label',
			array(
				'label' => __( 'Label', 'agend-elementor' ),
				'tab'   => $tab,
			)
		);
		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'label_typography',
				'selector' => '{{WRAPPER}} .agend-fs .agend-fs__label',
			)
		);
		$this->end_controls_section();
	}

	/**
	 * An Elementor icon value as markup, or '' for none.
	 *
	 * @param mixed $icon An ICONS control value.
	 * @return string
	 */
	protected function icon_html( $icon ): string {
		if ( ! is_array( $icon ) || empty( $icon['value'] ) || ! class_exists( '\Elementor\Icons_Manager' ) ) {
			return '';
		}

		ob_start();
		\Elementor\Icons_Manager::render_icon( $icon, array( 'aria-hidden' => 'true' ) );

		return (string) ob_get_clean();
	}

	protected function render(): void {
		$gate = Agend_Elementor_Schema_Controls::render_gate( 'filter-shortcuts' );
		if ( null !== $gate ) {
			echo $gate; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the surface gate.
			return;
		}

		$s = Agend_Elementor_Global_Colours::resolve( $this->get_settings_for_display() );

		// The renderer takes icons as markup, so the editor-specific picker
		// values are turned into it here.
		$s['all_icon_html'] = $this->icon_html( $s['all_icon'] ?? null );
		foreach ( (array) ( $s['items'] ?? array() ) as $index => $row ) {
			if ( is_array( $row ) ) {
				$s['items'][ $index ]['icon_html'] = $this->icon_html( $row['icon'] ?? null );
			}
		}

		echo agend_apps_records_render_filter_shortcuts( $s, array( 'preview' => $this->is_editor() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the core renderer.
	}
}
