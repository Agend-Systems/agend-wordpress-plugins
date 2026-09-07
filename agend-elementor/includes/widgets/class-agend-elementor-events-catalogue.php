<?php
/**
 * Elementor Events Catalogue widget for Agend Elementor Widgets.
 *
 * @package Agend_Elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders a searchable, filterable grid of published Agend events.
 *
 * The widget outputs a configured container; the frontend script
 * (assets/js/events-catalogue.js) fetches events from the Agend Apps Core
 * REST proxy (`/wp-json/agend-apps/v1/events`) and renders the catalogue
 * client-side. This is the catalogue surface of SPEC-INFRA-EVT-001 (US-EVT.1,
 * US-EVT.2, US-EVT.7, US-EVT.8); detail and registration are separate stories.
 */
class Agend_Elementor_Events_Catalogue extends \Elementor\Widget_Base {

	/**
	 * Returns the widget name (unique identifier).
	 *
	 * @return string Widget name.
	 */
	public function get_name(): string {
		return 'agend-events-catalogue';
	}

	/**
	 * Returns the widget display title.
	 *
	 * @return string Widget title.
	 */
	public function get_title(): string {
		return __( 'Agend Events', 'agend-elementor' );
	}

	/**
	 * Returns the Elementor icon class for the widget.
	 *
	 * @return string Icon class.
	 */
	public function get_icon(): string {
		return 'eicon-calendar';
	}

	/**
	 * Returns the Elementor categories this widget belongs to.
	 *
	 * @return array List of category slugs.
	 */
	public function get_categories(): array {
		return array( Agend_Elementor::CATEGORY );
	}

	/**
	 * Returns the frontend script handle this widget depends on.
	 *
	 * @return array Script handles.
	 */
	public function get_script_depends(): array {
		return array( 'agend-apps-records-events-catalogue' );
	}

	/**
	 * Returns the frontend style handle this widget depends on.
	 *
	 * @return array Style handles.
	 */
	public function get_style_depends(): array {
		return array( 'agend-apps-records-events-catalogue' );
	}

	/**
	 * Registers all Elementor controls for this widget.
	 */
	protected function register_controls(): void {
		$this->register_content_controls();
		$this->register_style_controls();
	}

	/**
	 * Registers the Content tab controls (US-EVT.7).
	 */
	private function register_content_controls(): void {
		Agend_Elementor_Schema_Controls::register( $this, agend_apps_records_surface_schema( 'events-catalogue' ) );
	}

	/**
	 * Registers the Style tab controls (US-EVT.8, colour subset).
	 */
	private function register_style_controls(): void {
		// Typography section.
		$this->start_controls_section(
			'section_style_typography',
			array(
				'label' => __( 'Typography', 'agend-elementor' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'inherit_fonts',
			array(
				'label'       => __( 'Inherit site theme fonts', 'agend-elementor' ),
				'type'        => \Elementor\Controls_Manager::SWITCHER,
				'default'     => 'yes',
				'description' => __( 'Pull heading and body fonts from the connected Agend account\'s site config.', 'agend-elementor' ),
			)
		);

		$this->end_controls_section();

		// Colours section.
		$this->start_controls_section(
			'section_style_colours',
			array(
				'label' => __( 'Colours', 'agend-elementor' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'inherit_colours',
			array(
				'label'       => __( 'Inherit site theme colours', 'agend-elementor' ),
				'type'        => \Elementor\Controls_Manager::SWITCHER,
				'default'     => 'yes',
				'description' => __( 'Pull heading, body, and accent colours from the connected account\'s site config. Turn off to set them manually below.', 'agend-elementor' ),
			)
		);

		$this->add_control(
			'heading_colour',
			array(
				'label'   => __( 'Heading colour', 'agend-elementor' ),
				'type'    => \Elementor\Controls_Manager::COLOR,
				'default' => '#1E2A4A',
			)
		);

		$this->add_control(
			'body_colour',
			array(
				'label'   => __( 'Body text colour', 'agend-elementor' ),
				'type'    => \Elementor\Controls_Manager::COLOR,
				'default' => '#26304D',
			)
		);

		$this->add_control(
			'accent_colour',
			array(
				'label'       => __( 'Highlight / accent colour', 'agend-elementor' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				'default'     => '#FF6B55',
				'description' => __( 'Drives FREE price text, active filter state, and the date badge month label.', 'agend-elementor' ),
			)
		);

		$this->add_control(
			'button_colour',
			array(
				'label'   => __( 'Button colour', 'agend-elementor' ),
				'type'    => \Elementor\Controls_Manager::COLOR,
				'default' => '#FF6B55',
			)
		);

		$this->add_control(
			'button_text_colour',
			array(
				'label'   => __( 'Button text colour', 'agend-elementor' ),
				'type'    => \Elementor\Controls_Manager::COLOR,
				'default' => '#FFFFFF',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Echoes the surface, rendered by Agend Apps Core from this widget's settings.
	 */
	protected function render(): void {
		// A catalogue inside a card template would fetch the list once per
		// card; nothing sensible can come of it.
		if ( Agend_Apps_Records_Record_Context::has() ) {
			if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
				echo '<div class="elementor-alert elementor-alert-warning">' . esc_html__( 'An Events Catalogue cannot be placed inside a card or detail template.', 'agend-elementor' ) . '</div>';
			}
			return;
		}

		echo agend_apps_records_render_events_catalogue( $this->get_settings_for_display() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the core renderer.
	}
}
