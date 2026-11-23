<?php
/**
 * Elementor "Agend Directory Count" widget: the number of listings in the
 * connected directory.
 *
 * @package Agend_Elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A thin adapter over the core surface: Content settings come from
 * agend_apps_records_surface_schema( 'directory-count' ), and the markup from
 * agend_apps_records_render_directory_count(). The Style tab is declared here
 * because it is CSS.
 */
class Agend_Elementor_Directory_Count extends \Elementor\Widget_Base {

	use Agend_Elementor_Field_Widget_Trait;

	public function get_name(): string {
		return 'agend-directory-count';
	}

	public function get_title(): string {
		return __( 'Agend Directory Count', 'agend-elementor' );
	}

	public function get_icon(): string {
		return 'eicon-counter';
	}

	public function get_categories(): array {
		return array( Agend_Elementor::CATEGORY );
	}

	public function get_keywords(): array {
		return array( 'agend', 'directory', 'count', 'total', 'number', 'stat', 'listings' );
	}

	public function get_script_depends(): array {
		return Agend_Elementor_Schema_Controls::depends( 'directory-count', array( 'agend-apps-records-directory-count' ) );
	}

	public function get_style_depends(): array {
		return Agend_Elementor_Schema_Controls::depends( 'directory-count', array( 'agend-apps-records-directory-count' ) );
	}

	protected function register_controls(): void {
		Agend_Elementor_Schema_Controls::register( $this, agend_apps_records_surface_schema( 'directory-count' ), 'directory-count' );
		$this->register_style_controls();
	}

	/**
	 * The Style tab: layout, number and label.
	 */
	private function register_style_controls(): void {
		$tab    = \Elementor\Controls_Manager::TAB_STYLE;
		$colour = \Elementor\Controls_Manager::COLOR;

		$this->start_controls_section(
			'section_style_layout',
			array(
				'label' => __( 'Layout', 'agend-elementor' ),
				'tab'   => $tab,
			)
		);
		$this->add_responsive_control(
			'count_align',
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
					'right'  => array(
						'title' => __( 'Right', 'agend-elementor' ),
						'icon'  => 'eicon-text-align-right',
					),
				),
				'selectors_dictionary' => array(
					'left'   => 'align-items: flex-start; justify-content: flex-start; text-align: left;',
					'center' => 'align-items: center; justify-content: center; text-align: center;',
					'right'  => 'align-items: flex-end; justify-content: flex-end; text-align: right;',
				),
				'selectors'            => array( '{{WRAPPER}} .agend-dc' => '{{VALUE}}' ),
			)
		);
		$this->add_responsive_control(
			'count_gap',
			array(
				'label'      => __( 'Gap', 'agend-elementor' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 48,
					),
				),
				'selectors'  => array( '{{WRAPPER}} .agend-dc' => 'gap: {{SIZE}}{{UNIT}};' ),
			)
		);
		$this->end_controls_section();

		foreach ( array(
			'number' => array( __( 'Number', 'agend-elementor' ), '.agend-dc__number' ),
			'label'  => array( __( 'Label', 'agend-elementor' ), '.agend-dc__label' ),
		) as $part => $details ) {
			$this->start_controls_section(
				'section_style_' . $part,
				array(
					'label' => $details[0],
					'tab'   => $tab,
				)
			);
			$this->add_group_control(
				\Elementor\Group_Control_Typography::get_type(),
				array(
					'name'     => $part . '_typography',
					'selector' => '{{WRAPPER}} ' . $details[1],
				)
			);
			$this->add_control(
				$part . '_colour',
				array(
					'label'     => __( 'Colour', 'agend-elementor' ),
					'type'      => $colour,
					'selectors' => array( '{{WRAPPER}} ' . $details[1] => 'color: {{VALUE}};' ),
				)
			);
			$this->end_controls_section();
		}
	}

	protected function render(): void {
		$gate = Agend_Elementor_Schema_Controls::render_gate( 'directory-count' );
		if ( null !== $gate ) {
			echo $gate; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the surface gate.
			return;
		}

		$s = Agend_Elementor_Global_Colours::resolve( $this->get_settings_for_display() );

		echo agend_apps_records_render_directory_count( $s, array( 'preview' => $this->is_editor() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the core renderer.
	}
}
