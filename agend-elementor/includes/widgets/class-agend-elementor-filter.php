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
				'groups'      => agend_apps_records_filter_options(),
				'label_block' => true,
				'description' => __( 'Choose the filter for the catalogue this template belongs to. A filter from another catalogue renders nothing.', 'agend-elementor' ),
			)
		);

		$this->add_control(
			'custom_field_key',
			array(
				'label'       => __( 'Custom field key', 'agend-elementor' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => '',
				'description' => __( 'The key as configured in Agend, for example education_level. The field must be configured as a search filter on the account, and a visitor who is not entitled to read it never sees this control.', 'agend-elementor' ),
				'condition'   => array( 'filter' => 'listing:custom_field' ),
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
					'range'      => __( 'Number range', 'agend-elementor' ),
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
				'condition' => array( 'control!' => array( 'search', 'date', 'reset', 'range' ) ),
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

		$context = Agend_Apps_Records_Filter_Context::type();

		// Outside a catalogue's filter template there is no record type in
		// scope. In the editor the widget still draws itself, using its own
		// declared type, so a designer can see and style the real control; on
		// the live site it renders nothing.
		if ( '' === $context && ! $this->is_editor() ) {
			return;
		}
		if ( '' !== $context && $context !== $type ) {
			$this->render_editor_notice( __( 'This filter belongs to a different catalogue, so it renders nothing here.', 'agend-elementor' ) );
			return;
		}

		$config = agend_apps_records_filter_config( $type, $key, $s );
		if ( null === $config ) {
			$this->render_editor_notice( __( 'This filter is not configured yet. A custom field filter needs its field key.', 'agend-elementor' ) );
			return;
		}

		if ( ! empty( $config['needsKey'] ) && '' === $config['fieldKey'] ) {
			$this->render_editor_notice( __( 'Enter the custom field key this filter targets.', 'agend-elementor' ) );
			return;
		}

		if ( ! empty( $config['choicesOnly'] ) && empty( $config['values'] ) ) {
			$this->render_editor_notice( __( 'This filter needs the choices you define: its values cannot be listed from the API yet. Add choices under Values.', 'agend-elementor' ) );
			return;
		}

		$classes = 'agend-filter agend-filter--' . sanitize_html_class( $config['control'] ) . ' agend-filter--' . sanitize_html_class( $key );
		if ( $this->is_editor() ) {
			$classes .= ' agend-filter--preview';
		}

		echo '<div class="' . esc_attr( $classes ) . '" data-agend-filter="' . esc_attr( (string) wp_json_encode( $config ) ) . '">';
		if ( $config['showLabel'] && '' !== $config['label'] ) {
			echo '<span class="agend-filter__label">' . esc_html( $config['label'] ) . '</span>';
		}
		echo '<div class="agend-filter__control">';
		if ( $this->is_editor() ) {
			// The live control is built by assets/js/filters.js once a
			// catalogue drives it. Nothing does that in the editor, so the
			// same markup is drawn here instead: a designer styles and
			// positions exactly what a visitor will see.
			$this->render_preview_control( $config );
		}
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Stand-in values for a control whose real list is fetched at render time.
	 *
	 * A facet or endpoint-backed filter has no values until a visitor loads
	 * the page, so the editor shows plausible ones at a realistic width.
	 *
	 * @param array $config The filter config.
	 * @return array<int, string> Option labels.
	 */
	private function preview_values( array $config ): array {
		if ( ! empty( $config['values'] ) ) {
			return array_map(
				static function ( $entry ) {
					return (string) $entry['label'];
				},
				$config['values']
			);
		}

		$label = '' !== $config['label'] ? $config['label'] : __( 'Value', 'agend-elementor' );
		return array(
			/* translators: %s: the filter's label, e.g. Category. */
			sprintf( __( 'Example %s one', 'agend-elementor' ), strtolower( $label ) ),
			sprintf( __( 'Example %s two', 'agend-elementor' ), strtolower( $label ) ),
			sprintf( __( 'Example %s three', 'agend-elementor' ), strtolower( $label ) ),
		);
	}

	/**
	 * Draws the control the runtime would build, for the editor only.
	 *
	 * Mirrors the markup in assets/js/filters.js element for element and class
	 * for class, so every style control on this widget lands on the same nodes
	 * in the editor as on the live page.
	 *
	 * @param array $config The filter config.
	 */
	private function render_preview_control( array $config ): void {
		$any = '' !== $config['anyLabel']
			? $config['anyLabel']
			/* translators: %s: the filter's label, e.g. Category. "Any" rather
			than "All" so a singular label still reads correctly. */
			: sprintf( __( 'Any %s', 'agend-elementor' ), $config['label'] );

		switch ( $config['control'] ) {
			case 'search':
				printf(
					'<input type="search" placeholder="%s" />',
					esc_attr( '' !== $config['placeholder'] ? $config['placeholder'] : $config['label'] )
				);
				return;

			case 'date':
				echo '<input type="date" />';
				return;

			case 'range':
				echo '<div class="agend-filter__range"><input type="number" placeholder="' . esc_attr__( 'Min', 'agend-elementor' ) . '" /><input type="number" placeholder="' . esc_attr__( 'Max', 'agend-elementor' ) . '" /></div>';
				return;

			case 'reset':
				echo '<button type="button" class="agend-filter__button agend-filter__reset">' . esc_html( '' !== $config['label'] ? $config['label'] : __( 'Clear filters', 'agend-elementor' ) ) . '</button>';
				return;

			case 'checkboxes':
				echo '<div class="agend-filter__options">';
				foreach ( $this->preview_values( $config ) as $value ) {
					echo '<label class="agend-filter__option"><input type="checkbox" /><span>' . esc_html( $value ) . '</span></label>';
				}
				echo '</div>';
				return;

			case 'buttons':
				echo '<div class="agend-filter__options">';
				echo '<button type="button" class="agend-filter__button is-active" aria-pressed="true">' . esc_html( $any ) . '</button>';
				foreach ( $this->preview_values( $config ) as $value ) {
					echo '<button type="button" class="agend-filter__button" aria-pressed="false">' . esc_html( $value ) . '</button>';
				}
				echo '</div>';
				return;

			default:
				echo '<select><option>' . esc_html( $any ) . '</option>';
				foreach ( $this->preview_values( $config ) as $value ) {
					echo '<option>' . esc_html( $value ) . '</option>';
				}
				echo '</select>';
		}
	}
}
