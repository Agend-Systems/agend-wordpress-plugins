<?php
/**
 * Elementor header auth-link widget for Agend Elementor Widgets.
 *
 * @package Agend_Elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A single header call-to-action that adapts to the member's session
 * (SPEC-CORE-20260722-wordpress-member-login US-2.8): a signed-out visitor sees
 * a "Log In" link to the configured login page; a signed-in member sees "My
 * Portal", which hands off to the member portal already authenticated (via the
 * Agend Apps Core portal-handoff proxy), plus a sign-out action revealed in a
 * dropdown on hover or keyboard focus. All labels are editable in Elementor.
 *
 * The signed-in/out decision is made client-side from the shared
 * `window.agendApps.loggedIn` signal, so a single cached header markup adapts
 * per member without a per-page server render.
 */
class Agend_Elementor_Header_Auth extends \Elementor\Widget_Base {

	/**
	 * Returns the widget name (unique identifier).
	 *
	 * @return string Widget name.
	 */
	public function get_name(): string {
		return 'agend-header-auth';
	}

	/**
	 * Returns the widget display title.
	 *
	 * @return string Widget title.
	 */
	public function get_title(): string {
		return __( 'Agend Login / Portal Link', 'agend-elementor' );
	}

	/**
	 * Returns the Elementor icon class for the widget.
	 *
	 * @return string Icon class.
	 */
	public function get_icon(): string {
		return 'eicon-lock-user';
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
		return array( 'agend-elementor-header-auth' );
	}

	/**
	 * Returns the frontend style handle this widget depends on.
	 *
	 * @return array Style handles.
	 */
	public function get_style_depends(): array {
		return array( 'agend-elementor-header-auth' );
	}

	/**
	 * Registers all Elementor controls for this widget.
	 */
	protected function register_controls(): void {
		$this->start_controls_section(
			'section_content',
			array(
				'label' => __( 'Content', 'agend-elementor' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'logged_out_label',
			array(
				'label'       => __( 'Logged-out label', 'agend-elementor' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Log In', 'agend-elementor' ),
				'description' => __( 'Shown to signed-out visitors; links to the login page.', 'agend-elementor' ),
			)
		);

		$this->add_control(
			'logged_in_label',
			array(
				'label'       => __( 'Logged-in label', 'agend-elementor' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'My Portal', 'agend-elementor' ),
				'description' => __( 'Shown to signed-in members; opens the member portal, already signed in.', 'agend-elementor' ),
			)
		);

		$this->add_control(
			'sign_out_label',
			array(
				'label'       => __( 'Sign-out label', 'agend-elementor' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Sign out', 'agend-elementor' ),
				'description' => __( 'Shown in a dropdown when a signed-in member hovers or focuses the button. Leave empty to hide the dropdown.', 'agend-elementor' ),
			)
		);

		$this->add_control(
			'login_url',
			array(
				'label'         => __( 'Login page', 'agend-elementor' ),
				'type'          => \Elementor\Controls_Manager::URL,
				'description'   => __( 'Where signed-out visitors go. Leave blank to use the WordPress login page.', 'agend-elementor' ),
				'placeholder'   => home_url( '/login/' ),
				'show_external' => false,
				'default'       => array(
					'url' => '',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style',
			array(
				'label' => __( 'Button', 'agend-elementor' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'align',
			array(
				'label'     => __( 'Alignment', 'agend-elementor' ),
				'type'      => \Elementor\Controls_Manager::CHOOSE,
				'options'   => array(
					'left'   => array(
						'title' => __( 'Left', 'agend-elementor' ),
						'icon'  => 'eicon-text-align-left',
					),
					'center' => array(
						'title' => __( 'Center', 'agend-elementor' ),
						'icon'  => 'eicon-text-align-center',
					),
					'right'  => array(
						'title' => __( 'Right', 'agend-elementor' ),
						'icon'  => 'eicon-text-align-right',
					),
				),
				'selectors' => array(
					'{{WRAPPER}}' => 'text-align: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'typography',
				'selector' => '{{WRAPPER}} .agend-header-auth__link',
			)
		);

		$this->add_responsive_control(
			'padding',
			array(
				'label'      => __( 'Padding', 'agend-elementor' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', 'rem', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .agend-header-auth__link' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			array(
				'name'     => 'border',
				'selector' => '{{WRAPPER}} .agend-header-auth__link',
			)
		);

		$this->add_responsive_control(
			'border_radius',
			array(
				'label'      => __( 'Border radius', 'agend-elementor' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', 'rem', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .agend-header-auth__link' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'box_shadow',
				'selector' => '{{WRAPPER}} .agend-header-auth__link',
			)
		);

		$this->start_controls_tabs( 'colour_tabs' );

		$this->start_controls_tab(
			'tab_normal',
			array( 'label' => __( 'Normal', 'agend-elementor' ) )
		);

		$this->add_control(
			'text_colour',
			array(
				'label'     => __( 'Text colour', 'agend-elementor' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .agend-header-auth__link' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'bg_colour',
			array(
				'label'     => __( 'Background', 'agend-elementor' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .agend-header-auth__link' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_tab();

		$this->start_controls_tab(
			'tab_hover',
			array( 'label' => __( 'Hover', 'agend-elementor' ) )
		);

		$this->add_control(
			'text_colour_hover',
			array(
				'label'     => __( 'Text colour', 'agend-elementor' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .agend-header-auth__link:hover, {{WRAPPER}} .agend-header-auth__link:focus' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'bg_colour_hover',
			array(
				'label'     => __( 'Background', 'agend-elementor' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .agend-header-auth__link:hover, {{WRAPPER}} .agend-header-auth__link:focus' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'border_colour_hover',
			array(
				'label'     => __( 'Border colour', 'agend-elementor' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .agend-header-auth__link:hover, {{WRAPPER}} .agend-header-auth__link:focus' => 'border-color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->end_controls_section();

		$this->start_controls_section(
			'section_dropdown_style',
			array(
				'label' => __( 'Sign-out dropdown', 'agend-elementor' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'menu_bg_colour',
			array(
				'label'     => __( 'Dropdown background', 'agend-elementor' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .agend-header-auth__menu' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'menu_text_colour',
			array(
				'label'     => __( 'Item text colour', 'agend-elementor' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .agend-header-auth__menu-item' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'menu_text_colour_hover',
			array(
				'label'     => __( 'Item text colour (hover)', 'agend-elementor' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .agend-header-auth__menu-item:hover, {{WRAPPER}} .agend-header-auth__menu-item:focus' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'menu_bg_colour_hover',
			array(
				'label'     => __( 'Item background (hover)', 'agend-elementor' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .agend-header-auth__menu-item:hover, {{WRAPPER}} .agend-header-auth__menu-item:focus' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Builds the config passed to the frontend script as JSON.
	 *
	 * @param array $s Widget settings.
	 * @return array Config for the frontend renderer.
	 */
	private function build_config( array $s ): array {
		$login_url = isset( $s['login_url']['url'] ) ? (string) $s['login_url']['url'] : '';
		if ( '' === $login_url ) {
			$login_url = wp_login_url();
		}

		$portal_url = '';
		if ( class_exists( 'Agend_Apps_Settings' ) ) {
			// The account portal home ({portal}/home/{slug}, from the connected
			// account slug setting) when the core plugin provides it; the portal
			// root on older core plugin versions.
			$portal_url = method_exists( 'Agend_Apps_Settings', 'get_portal_home_url' )
				? Agend_Apps_Settings::get_portal_home_url()
				: Agend_Apps_Settings::get_portal_url();
		}

		return array(
			'loggedOutLabel' => (string) ( $s['logged_out_label'] ?? __( 'Log In', 'agend-elementor' ) ),
			'loggedInLabel'  => (string) ( $s['logged_in_label'] ?? __( 'My Portal', 'agend-elementor' ) ),
			// An emptied label intentionally hides the sign-out dropdown (see
			// the control description).
			'signOutLabel'   => trim( (string) ( $s['sign_out_label'] ?? __( 'Sign out', 'agend-elementor' ) ) ),
			'loginUrl'       => $login_url,
			// Fallback portal URL used if the authenticated hand-off cannot be
			// minted; the signed-in click prefers the hand-off (US-1.5).
			'portalUrl'      => $portal_url,
		);
	}

	/**
	 * Renders the widget container on the frontend.
	 *
	 * The link is rendered client-side by assets/js/header-auth.js from the
	 * config and the shared `window.agendApps.loggedIn` signal, so one cached
	 * header adapts per member.
	 */
	protected function render(): void {
		// SPEC-CORE-20260907 US-4.1 AC7: the credential login surface does not
		// exist at all in `sso` member sign-in mode.
		if ( class_exists( 'Agend_Apps_Settings' ) && ! Agend_Apps_Settings::credential_login_enabled() ) {
			if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
				echo '<div class="agend-widget-notice">' . esc_html__( 'Member sign-in is set to SSO in Agend Apps settings.', 'agend-elementor' ) . '</div>';
			}
			return;
		}

		$settings = $this->get_settings_for_display();
		$config   = $this->build_config( $settings );
		?>
		<div class="agend-header-auth" data-agend-header-auth-config="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">
			<span class="agend-header-auth__placeholder" aria-hidden="true"></span>
		</div>
		<?php
	}
}
