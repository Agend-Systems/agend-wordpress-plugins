<?php
/**
 * Elementor "Agend Listing Enquiry Form" widget: a form on a directory
 * listing's page that sends the visitor's message to the listed member.
 *
 * @package Agend_Elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * An adapter over the core renderer, `agend_apps_records_render_listing_enquiry()`.
 *
 * The widget reads the listing from the record context of the template it sits
 * in, so it belongs in a Directory detail template. WordPress sends no email:
 * the form posts to the Agend Apps Core enquiry route and the gateway sends it.
 * On the live site it renders nothing unless the listing accepts enquiries; in
 * the editor it says why it would not show, and still draws the form so it can
 * be styled.
 */
class Agend_Elementor_Listing_Enquiry extends \Elementor\Widget_Base {

	use Agend_Elementor_Field_Widget_Trait;

	public function get_name(): string {
		return 'agend-listing-enquiry';
	}

	public function get_title(): string {
		return __( 'Agend Listing Enquiry Form', 'agend-elementor' );
	}

	public function get_icon(): string {
		return 'eicon-form-horizontal';
	}

	public function get_categories(): array {
		return array( Agend_Elementor::CATEGORY );
	}

	public function get_keywords(): array {
		return array( 'agend', 'enquiry', 'contact', 'form', 'directory', 'listing', 'message' );
	}

	public function get_script_depends(): array {
		return array( 'agend-apps-records-listing-enquiry' );
	}

	public function get_style_depends(): array {
		return array( 'agend-apps-records-listing-enquiry' );
	}

	/**
	 * This widget only ever previews a listing, whatever the template around
	 * it has declared so far.
	 */
	protected function preview_type(): string {
		return 'listing';
	}

	/**
	 * Adds one text control, defaulting to the core renderer's default copy.
	 *
	 * @param string $name     Control (and setting) name.
	 * @param string $label    Control label.
	 * @param string $default  Default value.
	 * @param array  $extra    Extra control args.
	 */
	private function add_text_control( string $name, string $label, string $default, array $extra = array() ): void {
		$this->add_control(
			$name,
			array_merge(
				array(
					'label'       => $label,
					'type'        => \Elementor\Controls_Manager::TEXT,
					'default'     => $default,
					'label_block' => true,
				),
				$extra
			)
		);
	}

	protected function register_controls(): void {
		$defaults = agend_apps_records_listing_enquiry_defaults();

		$this->start_controls_section(
			'section_content',
			array(
				'label' => __( 'Content', 'agend-elementor' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_text_control(
			'heading',
			__( 'Heading', 'agend-elementor' ),
			$defaults['heading'],
			array( 'description' => __( '{listing_name} becomes the listing\'s name. Clear the field to hide the heading.', 'agend-elementor' ) )
		);

		$this->add_control(
			'heading_tag',
			array(
				'label'   => __( 'Heading level', 'agend-elementor' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => $defaults['heading_tag'],
				'options' => array(
					'h1' => 'H1',
					'h2' => 'H2',
					'h3' => 'H3',
					'h4' => 'H4',
					'h5' => 'H5',
					'h6' => 'H6',
				),
			)
		);

		$this->add_control(
			'start_folded',
			array(
				'label'        => __( 'Start folded', 'agend-elementor' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'agend-elementor' ),
				'label_off'    => __( 'No', 'agend-elementor' ),
				'return_value' => 'yes',
				'default'      => '',
				'description'  => __( 'Show only the heading until a visitor opens the form. A button with the action "Open enquiry form" opens it. The editor always shows the form open.', 'agend-elementor' ),
			)
		);

		$this->add_text_control(
			'folded_intro',
			__( 'Intro line', 'agend-elementor' ),
			'',
			array(
				'description' => __( 'Shown under the heading while the form is folded, e.g. "Replies come straight to your inbox. You\'ll get a copy."', 'agend-elementor' ),
				'condition'   => array( 'start_folded' => 'yes' ),
			)
		);

		$this->add_text_control( 'button_text', __( 'Button text', 'agend-elementor' ), $defaults['button_text'] );

		$this->add_control(
			'success_message',
			array(
				'label'       => __( 'Success message', 'agend-elementor' ),
				'type'        => \Elementor\Controls_Manager::TEXTAREA,
				'default'     => $defaults['success_message'],
				'label_block' => true,
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_fields',
			array(
				'label' => __( 'Fields', 'agend-elementor' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		foreach ( array(
			'first'   => __( 'First name', 'agend-elementor' ),
			'last'    => __( 'Last name', 'agend-elementor' ),
			'email'   => __( 'Email', 'agend-elementor' ),
			'phone'   => __( 'Mobile', 'agend-elementor' ),
			'message' => __( 'Message', 'agend-elementor' ),
		) as $key => $title ) {
			$this->add_control(
				$key . '_heading',
				array(
					'label'     => $title,
					'type'      => \Elementor\Controls_Manager::HEADING,
					'separator' => 'before',
				)
			);
			$this->add_text_control( $key . '_label', __( 'Label', 'agend-elementor' ), $defaults[ $key . '_label' ] );
			$this->add_text_control( $key . '_placeholder', __( 'Placeholder', 'agend-elementor' ), $defaults[ $key . '_placeholder' ] );
		}

		$this->end_controls_section();

		$this->start_controls_section(
			'section_terms',
			array(
				'label' => __( 'Terms', 'agend-elementor' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'terms_note',
			array(
				'type'            => \Elementor\Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'A terms checkbox shows only when the listing has a terms link set in Agend.', 'agend-elementor' ),
				'content_classes' => 'elementor-descriptor',
			)
		);

		$this->add_text_control(
			'terms_label',
			__( 'Checkbox label', 'agend-elementor' ),
			$defaults['terms_label'],
			array( 'description' => __( '{terms_link} becomes the link.', 'agend-elementor' ) )
		);

		$this->add_text_control( 'terms_link_text', __( 'Link text', 'agend-elementor' ), $defaults['terms_link_text'] );

		$this->end_controls_section();

		$this->register_style_controls();
	}

	/**
	 * The Style tab: heading, labels, fields, button and messages.
	 */
	private function register_style_controls(): void {
		$colour = \Elementor\Controls_Manager::COLOR;

		$this->start_controls_section(
			'section_style_heading',
			array(
				'label' => __( 'Heading', 'agend-elementor' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);
		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'heading_typography',
				'selector' => '{{WRAPPER}} .agend-enquiry__heading',
			)
		);
		$this->add_control(
			'heading_colour',
			array(
				'label'     => __( 'Colour', 'agend-elementor' ),
				'type'      => $colour,
				'selectors' => array( '{{WRAPPER}} .agend-enquiry__heading' => 'color: {{VALUE}};' ),
			)
		);
		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_summary',
			array(
				'label'     => __( 'Folded summary', 'agend-elementor' ),
				'tab'       => \Elementor\Controls_Manager::TAB_STYLE,
				'condition' => array( 'start_folded' => 'yes' ),
			)
		);
		$this->add_control(
			'summary_background',
			array(
				'label'     => __( 'Background colour', 'agend-elementor' ),
				'type'      => $colour,
				'selectors' => array( '{{WRAPPER}} .agend-enquiry__summary' => 'background-color: {{VALUE}};' ),
			)
		);
		$this->add_responsive_control(
			'summary_padding',
			array(
				'label'      => __( 'Padding', 'agend-elementor' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array( '{{WRAPPER}} .agend-enquiry__summary' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);
		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_labels',
			array(
				'label' => __( 'Labels', 'agend-elementor' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);
		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'label_typography',
				'selector' => '{{WRAPPER}} .agend-enquiry__label, {{WRAPPER}} .agend-enquiry__terms',
			)
		);
		$this->add_control(
			'label_colour',
			array(
				'label'     => __( 'Colour', 'agend-elementor' ),
				'type'      => $colour,
				'selectors' => array( '{{WRAPPER}} .agend-enquiry__label, {{WRAPPER}} .agend-enquiry__terms' => 'color: {{VALUE}};' ),
			)
		);
		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_fields',
			array(
				'label' => __( 'Fields', 'agend-elementor' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);
		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'field_typography',
				'selector' => '{{WRAPPER}} .agend-enquiry__input',
			)
		);
		$this->add_control(
			'field_text_colour',
			array(
				'label'     => __( 'Text colour', 'agend-elementor' ),
				'type'      => $colour,
				'selectors' => array( '{{WRAPPER}} .agend-enquiry__input' => 'color: {{VALUE}};' ),
			)
		);
		$this->add_control(
			'field_background',
			array(
				'label'     => __( 'Background colour', 'agend-elementor' ),
				'type'      => $colour,
				'selectors' => array( '{{WRAPPER}} .agend-enquiry__input' => 'background-color: {{VALUE}};' ),
			)
		);
		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			array(
				'name'      => 'field_border',
				'selector'  => '{{WRAPPER}} .agend-enquiry__input',
				'separator' => 'before',
			)
		);
		$this->add_responsive_control(
			'field_radius',
			array(
				'label'      => __( 'Border radius', 'agend-elementor' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array( '{{WRAPPER}} .agend-enquiry__input' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);
		$this->add_responsive_control(
			'field_padding',
			array(
				'label'      => __( 'Padding', 'agend-elementor' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array( '{{WRAPPER}} .agend-enquiry__input' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);
		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_button',
			array(
				'label' => __( 'Button', 'agend-elementor' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);
		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'button_typography',
				'selector' => '{{WRAPPER}} .agend-enquiry__submit',
			)
		);

		$this->start_controls_tabs( 'tabs_button' );

		$this->start_controls_tab( 'tab_normal', array( 'label' => __( 'Normal', 'agend-elementor' ) ) );
		$this->add_control(
			'button_text_colour',
			array(
				'label'     => __( 'Text colour', 'agend-elementor' ),
				'type'      => $colour,
				'selectors' => array( '{{WRAPPER}} .agend-enquiry__submit' => 'color: {{VALUE}};' ),
			)
		);
		$this->add_control(
			'button_background',
			array(
				'label'     => __( 'Background colour', 'agend-elementor' ),
				'type'      => $colour,
				'selectors' => array( '{{WRAPPER}} .agend-enquiry__submit' => 'background-color: {{VALUE}};' ),
			)
		);
		$this->end_controls_tab();

		$this->start_controls_tab( 'tab_hover', array( 'label' => __( 'Hover', 'agend-elementor' ) ) );
		$this->add_control(
			'button_text_colour_hover',
			array(
				'label'     => __( 'Text colour', 'agend-elementor' ),
				'type'      => $colour,
				'selectors' => array( '{{WRAPPER}} .agend-enquiry__submit:hover, {{WRAPPER}} .agend-enquiry__submit:focus' => 'color: {{VALUE}};' ),
			)
		);
		$this->add_control(
			'button_background_hover',
			array(
				'label'     => __( 'Background colour', 'agend-elementor' ),
				'type'      => $colour,
				'selectors' => array( '{{WRAPPER}} .agend-enquiry__submit:hover, {{WRAPPER}} .agend-enquiry__submit:focus' => 'background-color: {{VALUE}};' ),
			)
		);
		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			array(
				'name'      => 'button_border',
				'selector'  => '{{WRAPPER}} .agend-enquiry__submit',
				'separator' => 'before',
			)
		);
		$this->add_responsive_control(
			'button_radius',
			array(
				'label'      => __( 'Border radius', 'agend-elementor' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array( '{{WRAPPER}} .agend-enquiry__submit' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);
		$this->add_responsive_control(
			'button_padding',
			array(
				'label'      => __( 'Padding', 'agend-elementor' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array( '{{WRAPPER}} .agend-enquiry__submit' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);
		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'button_shadow',
				'selector' => '{{WRAPPER}} .agend-enquiry__submit',
			)
		);
		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_messages',
			array(
				'label' => __( 'Messages', 'agend-elementor' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);
		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'status_typography',
				'selector' => '{{WRAPPER}} .agend-enquiry__status',
			)
		);
		$this->add_control(
			'success_colour',
			array(
				'label'     => __( 'Success colour', 'agend-elementor' ),
				'type'      => $colour,
				'selectors' => array( '{{WRAPPER}} .agend-enquiry__status--success' => 'color: {{VALUE}};' ),
			)
		);
		$this->add_control(
			'error_colour',
			array(
				'label'     => __( 'Error colour', 'agend-elementor' ),
				'type'      => $colour,
				'selectors' => array(
					'{{WRAPPER}} .agend-enquiry__error, {{WRAPPER}} .agend-enquiry__status--error' => 'color: {{VALUE}};',
					'{{WRAPPER}} .agend-enquiry__input[aria-invalid="true"]' => 'border-color: {{VALUE}};',
				),
			)
		);
		$this->end_controls_section();
	}

	/**
	 * The 'preview' / 'preview_type' opts the core listing state needs, built
	 * the way Agend_Elementor_Record_Link builds its own.
	 *
	 * @return array{preview: bool, preview_type: string, id: string}
	 */
	private function render_opts(): array {
		$is_editor = $this->is_editor();

		return array(
			'preview'      => $is_editor,
			'preview_type' => $is_editor ? $this->preview_type() : '',
			'id'           => $this->get_id(),
		);
	}

	/**
	 * What an editor is told when the form would not show a visitor.
	 *
	 * @param array $state The core listing state.
	 * @return string The notice, or '' when the form shows.
	 */
	private function editor_notice( array $state ): string {
		switch ( $state['status'] ) {
			case 'no_listing':
				return __( 'Place the Listing Enquiry Form in a Directory detail template: it sends its message to the listing it is shown on.', 'agend-elementor' );

			case 'wrong_type':
				return __( 'The Listing Enquiry Form is only available for directory listings.', 'agend-elementor' );

			case 'unavailable':
				return function_exists( 'agend_apps_records_feature_missing_scope_notice' )
					? agend_apps_records_feature_missing_scope_notice( 'directory_enquiry_form' )
					: __( 'The Agend API key does not allow sending listing enquiries.', 'agend-elementor' );

			case 'no_captcha':
				return __( 'No captcha is configured, so visitors will not see this form. Set a provider, site key and secret key in Settings > Agend Apps > Form Captcha.', 'agend-elementor' );

			case 'closed':
				return __( 'This listing does not accept enquiries, so visitors will not see this form. It is drawn here so you can style it.', 'agend-elementor' );
		}

		return '';
	}

	protected function render(): void {
		$s     = Agend_Elementor_Global_Colours::resolve( $this->get_settings_for_display() );
		$opts  = $this->render_opts();
		$state = agend_apps_records_listing_enquiry_state( $opts );

		$notice = $this->editor_notice( $state );
		if ( '' !== $notice ) {
			$this->render_editor_notice( $notice );
		}

		// An editor still sees the form for a listing that is closed to
		// enquiries, so it can be styled. A visitor never does.
		if ( in_array( $state['status'], array( 'closed', 'no_captcha' ), true ) && $this->is_editor() ) {
			$state['status'] = 'ready';
		}

		$opts['state'] = $state;

		echo agend_apps_records_render_listing_enquiry( $s, $opts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the core renderer.
	}
}
