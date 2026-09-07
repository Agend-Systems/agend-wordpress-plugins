<?php
/**
 * Elementor Memberships Catalogue widget for Agend Elementor Widgets.
 *
 * @package Agend_Elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders a grid of membership tiers with a dynamic signup form.
 *
 * The widget outputs a configured container; the frontend script
 * (assets/js/memberships-catalogue.js) fetches tiers and signup fields from the
 * Agend Apps Core REST proxy and renders the catalogue and signup form
 * client-side. This is SPEC-CRM-20260721-elementor-membership-signup.
 */
class Agend_Elementor_Memberships_Catalogue extends \Elementor\Widget_Base {

	/**
	 * Returns the widget name (unique identifier).
	 *
	 * @return string Widget name.
	 */
	public function get_name(): string {
		return 'agend-memberships-catalogue';
	}

	/**
	 * Returns the widget display title.
	 *
	 * @return string Widget title.
	 */
	public function get_title(): string {
		return __( 'Agend Memberships', 'agend-elementor' );
	}

	/**
	 * Returns the Elementor icon class for the widget.
	 *
	 * @return string Icon class.
	 */
	public function get_icon(): string {
		return 'eicon-price-table';
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
		return array( 'agend-apps-records-memberships-catalogue' );
	}

	/**
	 * Returns the frontend style handle this widget depends on.
	 *
	 * @return array Style handles.
	 */
	public function get_style_depends(): array {
		return array( 'agend-apps-records-memberships-catalogue' );
	}

	/**
	 * Registers all Elementor controls for this widget.
	 */
	protected function register_controls(): void {
		$this->register_content_controls();
		$this->register_style_controls();
	}

	/**
	 * Registers the Content tab controls.
	 */
	private function register_content_controls(): void {
		Agend_Elementor_Schema_Controls::register( $this, agend_apps_records_surface_schema( 'memberships-catalogue' ) );
	}

	/**
	 * Registers a Content-tab control this widget declares itself because the
	 * shared schema vocabulary cannot describe it.
	 *
	 * @param string $name Schema field name.
	 * @return void
	 */
	public function register_adapter_control( string $name ): void {
		switch ( $name ) {
			case 'tier_mode_overrides':
				$this->add_control(
					'tier_mode_overrides',
					array(
						'label'       => __( 'Tier-specific overrides', 'agend-elementor' ),
						'type'        => \Elementor\Controls_Manager::REPEATER,
						'fields'      => array(
							array(
								'name'        => 'tier_slug',
								'label'       => __( 'Tier slug', 'agend-elementor' ),
								'type'        => \Elementor\Controls_Manager::TEXT,
								'placeholder' => 'professional',
							),
							array(
								'name'    => 'tier_mode',
								'label'   => __( 'Mode for this tier', 'agend-elementor' ),
								'type'    => \Elementor\Controls_Manager::SELECT,
								'options' => array(
									'application' => __( 'Application', 'agend-elementor' ),
									'direct'      => __( 'Direct purchase', 'agend-elementor' ),
								),
								'default' => 'application',
							),
						),
						'default'     => array(),
						'title_field' => '{{{ "undefined" !== typeof tier_slug && tier_slug ? tier_slug : "Tier override" }}}',
					)
				);
				break;

			case 'success_url':
				$this->add_control(
					'success_url',
					array(
						'label'       => __( 'Success page URL (optional)', 'agend-elementor' ),
						'type'        => \Elementor\Controls_Manager::URL,
						'placeholder' => 'https://example.com/thank-you',
						'description' => __( 'URL to redirect to after successful signup. Defaults to the current page.', 'agend-elementor' ),
					)
				);
				break;
		}
	}

	/**
	 * Registers the Style tab controls.
	 */
	private function register_style_controls(): void {
		// Colours section.
		$this->start_controls_section(
			'section_style_colours',
			array(
				'label' => __( 'Colours', 'agend-elementor' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'accent_colour',
			array(
				'label'   => __( 'Accent colour', 'agend-elementor' ),
				'type'    => \Elementor\Controls_Manager::COLOR,
				'default' => '#F76B4F',
				'description' => __( 'Used for buttons, selected card borders, and highlights.', 'agend-elementor' ),
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

		$this->end_controls_section();
	}

	/**
	 * Echoes the surface, rendered by Agend Apps Core from this widget's settings.
	 */
	protected function render(): void {
		echo agend_apps_records_render_memberships_catalogue( $this->get_settings_for_display() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the core renderer.
	}
}
