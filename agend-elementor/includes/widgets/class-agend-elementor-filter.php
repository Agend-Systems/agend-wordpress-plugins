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
		return array( 'agend-elementor-filters' );
	}

	protected function register_controls(): void {
		$this->start_controls_section(
			'section_filter',
			array(
				'label' => __( 'Filter', 'agend-elementor' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'filter',
			array(
				'label'       => __( 'Filter', 'agend-elementor' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'default'     => 'event:search',
				'groups'      => agend_elementor_filter_options(),
				'label_block' => true,
				'description' => __( 'Choose the filter for the catalogue this template belongs to. A filter from another catalogue renders nothing.', 'agend-elementor' ),
			)
		);

		$this->add_control(
			'control',
			array(
				'label'       => __( 'Presentation', 'agend-elementor' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'default'     => '',
				'options'     => array(
					''           => __( 'Default for this filter', 'agend-elementor' ),
					'search'     => __( 'Search box', 'agend-elementor' ),
					'select'     => __( 'Dropdown', 'agend-elementor' ),
					'checkboxes' => __( 'Checkboxes', 'agend-elementor' ),
					'buttons'    => __( 'Buttons', 'agend-elementor' ),
					'date'       => __( 'Date', 'agend-elementor' ),
					'reset'      => __( 'Clear button', 'agend-elementor' ),
				),
				'description' => __( 'Presentations the chosen filter does not support fall back to its default.', 'agend-elementor' ),
			)
		);

		$this->add_control(
			'show_label',
			array(
				'label'   => __( 'Show label', 'agend-elementor' ),
				'type'    => \Elementor\Controls_Manager::SWITCHER,
				'default' => 'yes',
			)
		);

		$this->add_control(
			'label',
			array(
				'label'       => __( 'Label', 'agend-elementor' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => '',
				'description' => __( 'Leave empty for the filter\'s own name.', 'agend-elementor' ),
			)
		);

		$this->add_control(
			'placeholder',
			array(
				'label'     => __( 'Placeholder', 'agend-elementor' ),
				'type'      => \Elementor\Controls_Manager::TEXT,
				'default'   => '',
				'condition' => array( 'control' => array( '', 'search', 'date', 'reset' ) ),
			)
		);

		$this->add_control(
			'any_label',
			array(
				'label'       => __( '"Any" option label', 'agend-elementor' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => '',
				'description' => __( 'The option that clears this filter, for example "All Categories".', 'agend-elementor' ),
				'condition'   => array( 'control!' => array( 'search', 'date' ) ),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_values',
			array(
				'label'     => __( 'Values', 'agend-elementor' ),
				'tab'       => \Elementor\Controls_Manager::TAB_CONTENT,
				'condition' => array( 'control!' => array( 'search', 'date', 'reset' ) ),
			)
		);

		$this->add_control(
			'values_mode',
			array(
				'label'       => __( 'Values', 'agend-elementor' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'default'     => 'all',
				'options'     => array(
					'all'     => __( 'Every value that exists', 'agend-elementor' ),
					'choices' => __( 'Choices I define', 'agend-elementor' ),
				),
				'description' => __( 'Defined choices each send a fixed selection, so one choice can stand for several values, for example "All States". Tag and badge filters always use defined choices, because the API cannot list their values yet.', 'agend-elementor' ),
			)
		);

		$choices = new \Elementor\Repeater();
		$choices->add_control(
			'choice_label',
			array(
				'label'   => __( 'Label', 'agend-elementor' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => '',
			)
		);
		$choices->add_control(
			'choice_value',
			array(
				'label'       => __( 'Sends', 'agend-elementor' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => '',
				'description' => __( 'One value, or several separated by commas. Several values match any of them.', 'agend-elementor' ),
			)
		);

		$this->add_control(
			'choices',
			array(
				'label'       => __( 'Choices', 'agend-elementor' ),
				'type'        => \Elementor\Controls_Manager::REPEATER,
				'fields'      => $choices->get_controls(),
				'title_field' => '{{{ choice_label }}}',
				'default'     => array(),
				'condition'   => array( 'values_mode' => 'choices' ),
			)
		);

		$this->end_controls_section();

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
		$s        = $this->get_settings_for_display();
		$selected = (string) ( $s['filter'] ?? '' );
		if ( false === strpos( $selected, ':' ) ) {
			return;
		}
		list( $type, $key ) = explode( ':', $selected, 2 );

		$context = Agend_Elementor_Filter_Context::type();
		if ( '' === $context ) {
			// Not inside a catalogue's filter template: the editor shows what
			// the control will be, the live site shows nothing.
			$this->render_editor_notice(
				sprintf(
					/* translators: %s: the filter's label. */
					__( 'Agend Filter (%s). This renders once the template is selected as a catalogue\'s filter template.', 'agend-elementor' ),
					(string) ( $s['label'] ?? $key )
				)
			);
			return;
		}
		if ( $context !== $type ) {
			$this->render_editor_notice( __( 'This filter belongs to a different catalogue, so it renders nothing here.', 'agend-elementor' ) );
			return;
		}

		$config = agend_elementor_filter_config( $type, $key, $s );
		if ( null === $config ) {
			return;
		}

		if ( ! empty( $config['choicesOnly'] ) && empty( $config['values'] ) ) {
			$this->render_editor_notice( __( 'This filter needs the choices you define: its values cannot be listed from the API yet. Add choices under Values.', 'agend-elementor' ) );
			return;
		}

		$classes = 'agend-filter agend-filter--' . sanitize_html_class( $config['control'] ) . ' agend-filter--' . sanitize_html_class( $key );
		echo '<div class="' . esc_attr( $classes ) . '" data-agend-filter="' . esc_attr( (string) wp_json_encode( $config ) ) . '">';
		if ( $config['showLabel'] && '' !== $config['label'] ) {
			echo '<span class="agend-filter__label">' . esc_html( $config['label'] ) . '</span>';
		}
		echo '<div class="agend-filter__control"></div>';
		echo '</div>';
	}
}
