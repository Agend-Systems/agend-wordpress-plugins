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
 * Agend Apps Core portal-handoff proxy). Both labels are editable in Elementor.
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
				'label' => __( 'Style', 'agend-elementor' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'text_colour',
			array(
				'label'     => __( 'Text colour', 'agend-elementor' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '',
				'selectors' => array(
					'{{WRAPPER}} .agend-header-auth__link' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'bg_colour',
			array(
				'label'       => __( 'Button background', 'agend-elementor' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				'default'     => '',
				'description' => __( 'Set a background to render the link as a button. Leave blank for a plain text link.', 'agend-elementor' ),
				'selectors'   => array(
					'{{WRAPPER}} .agend-header-auth__link' => 'background-color: {{VALUE}}; padding: 0.5rem 1rem; border-radius: 6px;',
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

		$portal_url = class_exists( 'Agend_Apps_Settings' )
			? Agend_Apps_Settings::get_portal_url()
			: '';

		return array(
			'loggedOutLabel' => (string) ( $s['logged_out_label'] ?? __( 'Log In', 'agend-elementor' ) ),
			'loggedInLabel'  => (string) ( $s['logged_in_label'] ?? __( 'My Portal', 'agend-elementor' ) ),
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
		$settings = $this->get_settings_for_display();
		$config   = $this->build_config( $settings );
		?>
		<div class="agend-header-auth" data-agend-header-auth-config="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">
			<span class="agend-header-auth__placeholder" aria-hidden="true"></span>
		</div>
		<?php
	}
}
