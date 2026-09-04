<?php
/**
 * Elementor Account Link widget for Agend Elementor Widgets.
 *
 * @package Agend_Elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shows a logged-in WordPress member whether their account is linked to the
 * connected Agend account, and prompts them to establish the link via SSO when
 * it is not.
 *
 * The widget outputs a configured container; the frontend script
 * (assets/js/account-link.js) calls the Agend Apps Core REST proxy
 * (`/wp-json/agend-apps/v1/account-link/status`) which resolves the current
 * user's link status server-side and returns the SSO initiate URL to use when
 * unlinked.
 */
class Agend_Elementor_Account_Link extends \Elementor\Widget_Base {

	/**
	 * Returns the widget name (unique identifier).
	 *
	 * @return string Widget name.
	 */
	public function get_name(): string {
		return 'agend-account-link';
	}

	/**
	 * Returns the widget display title.
	 *
	 * @return string Widget title.
	 */
	public function get_title(): string {
		return __( 'Agend Account Link', 'agend-elementor' );
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
		return array( 'agend-apps-records-account-link' );
	}

	/**
	 * Returns the frontend style handle this widget depends on.
	 *
	 * @return array Style handles.
	 */
	public function get_style_depends(): array {
		return array( 'agend-apps-records-account-link' );
	}

	/**
	 * Registers all Elementor controls for this widget.
	 */
	protected function register_controls(): void {
		$this->register_content_controls();
		$this->register_style_controls();
	}

	/**
	 * Registers the Content tab controls (copy for each state).
	 */
	private function register_content_controls(): void {
		$this->start_controls_section(
			'section_content',
			array(
				'label' => __( 'Content', 'agend-elementor' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'show_heading',
			array(
				'label'   => __( 'Show heading', 'agend-elementor' ),
				'type'    => \Elementor\Controls_Manager::SWITCHER,
				'default' => 'yes',
			)
		);

		$this->add_control(
			'heading_text',
			array(
				'label'     => __( 'Heading', 'agend-elementor' ),
				'type'      => \Elementor\Controls_Manager::TEXT,
				'default'   => __( 'Your Agend Account', 'agend-elementor' ),
				'condition' => array( 'show_heading' => 'yes' ),
			)
		);

		$this->add_control(
			'linked_message',
			array(
				'label'   => __( 'Linked message', 'agend-elementor' ),
				'type'    => \Elementor\Controls_Manager::TEXTAREA,
				'default' => __( 'Your account is linked. You have full access to member content.', 'agend-elementor' ),
			)
		);

		$this->add_control(
			'unlinked_message',
			array(
				'label'   => __( 'Not-linked message', 'agend-elementor' ),
				'type'    => \Elementor\Controls_Manager::TEXTAREA,
				'default' => __( 'Link your account to unlock member content, courses, and event pricing.', 'agend-elementor' ),
			)
		);

		$this->add_control(
			'button_label',
			array(
				'label'   => __( 'Link button label', 'agend-elementor' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Link my account', 'agend-elementor' ),
			)
		);

		$this->add_control(
			'portal_link_label',
			array(
				'label'       => __( 'Portal link label', 'agend-elementor' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Review your Agend details', 'agend-elementor' ),
				'description' => __( 'Shown to linked members as a link to the member portal. Leave empty to hide the link.', 'agend-elementor' ),
			)
		);

		$this->add_control(
			'logged_out_message',
			array(
				'label'       => __( 'Logged-out message', 'agend-elementor' ),
				'type'        => \Elementor\Controls_Manager::TEXTAREA,
				'default'     => __( 'Log in to link your account with Agend.', 'agend-elementor' ),
				'description' => __( 'Shown to visitors who are not logged in to WordPress. Leave empty to hide the widget for logged-out visitors.', 'agend-elementor' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Registers the Style tab controls (theme inheritance + colour subset).
	 */
	private function register_style_controls(): void {
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
				'description' => __( 'Drives the linked-state tick.', 'agend-elementor' ),
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
	 * Builds the client-side config object from the widget settings.
	 *
	 * @param array $s Settings for display.
	 * @return array Config passed to the frontend script as JSON.
	 */
	private function build_config( array $s ): array {
		return array(
			'showHeading' => 'yes' === ( $s['show_heading'] ?? 'yes' ),
			'messages'    => array(
				'heading'    => (string) ( $s['heading_text'] ?? '' ),
				'linked'     => (string) ( $s['linked_message'] ?? '' ),
				'unlinked'   => (string) ( $s['unlinked_message'] ?? '' ),
				'button'     => (string) ( $s['button_label'] ?? '' ),
				'portalLink' => (string) ( $s['portal_link_label'] ?? '' ),
				'loggedOut'  => (string) ( $s['logged_out_message'] ?? '' ),
			),
			'colours'     => array(
				'heading'    => (string) ( $s['heading_colour'] ?? '#1E2A4A' ),
				'body'       => (string) ( $s['body_colour'] ?? '#26304D' ),
				'accent'     => (string) ( $s['accent_colour'] ?? '#FF6B55' ),
				'button'     => (string) ( $s['button_colour'] ?? '#FF6B55' ),
				'buttonText' => (string) ( $s['button_text_colour'] ?? '#FFFFFF' ),
			),
			'theme'       => array(
				'inheritFonts'   => 'yes' === ( $s['inherit_fonts'] ?? 'yes' ),
				'inheritColours' => 'yes' === ( $s['inherit_colours'] ?? 'yes' ),
			),
		);
	}

	/**
	 * Renders the widget container on the frontend.
	 *
	 * The status card is rendered client-side from the config below by
	 * assets/js/account-link.js.
	 */
	protected function render(): void {
		$settings = $this->get_settings_for_display();
		$config   = $this->build_config( $settings );

		$style = sprintf(
			'--agend-al-heading:%1$s;--agend-al-body:%2$s;--agend-al-accent:%3$s;--agend-al-button:%4$s;--agend-al-button-text:%5$s;',
			esc_attr( $config['colours']['heading'] ),
			esc_attr( $config['colours']['body'] ),
			esc_attr( $config['colours']['accent'] ),
			esc_attr( $config['colours']['button'] ),
			esc_attr( $config['colours']['buttonText'] )
		);
		?>
		<div class="agend-account-link" style="<?php echo esc_attr( $style ); ?>" data-agend-account-link-config="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">
			<div class="agend-al-status" role="status"><?php esc_html_e( 'Checking your account…', 'agend-elementor' ); ?></div>
		</div>
		<?php
	}
}
