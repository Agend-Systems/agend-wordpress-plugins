<?php
/**
 * Elementor Member Login widget for Agend Elementor Widgets.
 *
 * @package Agend_Elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders an in-page sign-in form for members to authenticate with their Agend
 * credentials (SPEC-CORE-20260722-wordpress-member-login US-1.4), without
 * leaving the WordPress site.
 *
 * The form posts to the Agend Apps Core REST proxy (`agend-apps/v1/auth/login`)
 * which validates the credentials server-side, stores the session, and signs
 * the member in. Signed-in members instead see a "go to my account" link (the
 * portal hand-off, US-1.5) and a sign-out control. The secret API key and the
 * refresh token never reach the browser; the widget only ever exchanges the
 * WordPress REST nonce.
 */
class Agend_Elementor_Member_Login extends \Elementor\Widget_Base {

	/**
	 * Returns the widget name (unique identifier).
	 *
	 * @return string Widget name.
	 */
	public function get_name(): string {
		return 'agend-member-login';
	}

	/**
	 * Returns the widget display title.
	 *
	 * @return string Widget title.
	 */
	public function get_title(): string {
		return __( 'Agend Member Login', 'agend-elementor' );
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
		return array( 'agend-apps-records-member-login' );
	}

	/**
	 * Returns the frontend style handle this widget depends on.
	 *
	 * @return array Style handles.
	 */
	public function get_style_depends(): array {
		return array( 'agend-apps-records-member-login' );
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
			'heading_text',
			array(
				'label'   => __( 'Heading', 'agend-elementor' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Sign in', 'agend-elementor' ),
			)
		);

		$this->add_control(
			'intro_text',
			array(
				'label'   => __( 'Intro text', 'agend-elementor' ),
				'type'    => \Elementor\Controls_Manager::TEXTAREA,
				'default' => __( 'Sign in with your Agend member account.', 'agend-elementor' ),
			)
		);

		$this->add_control(
			'submit_label',
			array(
				'label'   => __( 'Sign-in button label', 'agend-elementor' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Sign in', 'agend-elementor' ),
			)
		);

		$this->add_control(
			'forgot_label',
			array(
				'label'       => __( 'Forgot-password link text', 'agend-elementor' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Forgot your password?', 'agend-elementor' ),
				'description' => __( 'Leave empty to hide the password-recovery link.', 'agend-elementor' ),
			)
		);

		$this->add_control(
			'back_to_sign_in_label',
			array(
				'label'       => __( 'Return link text', 'agend-elementor' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Back to sign in', 'agend-elementor' ),
				'description' => __( 'Shown in the password-recovery and reset views to return to the sign-in form.', 'agend-elementor' ),
			)
		);

		$this->add_control(
			'signed_in_message',
			array(
				'label'   => __( 'Signed-in message', 'agend-elementor' ),
				'type'    => \Elementor\Controls_Manager::TEXTAREA,
				'default' => __( 'You are signed in to your Agend account.', 'agend-elementor' ),
			)
		);

		$this->add_control(
			'portal_button_label',
			array(
				'label'       => __( 'Portal button label', 'agend-elementor' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Go to my account', 'agend-elementor' ),
				'description' => __( 'Opens the member portal, already signed in. Leave empty to hide.', 'agend-elementor' ),
			)
		);

		$this->add_control(
			'sign_out_label',
			array(
				'label'   => __( 'Sign-out label', 'agend-elementor' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Sign out', 'agend-elementor' ),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style',
			array(
				'label' => __( 'Colours', 'agend-elementor' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
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
		// An emptied return label falls back to the default so the recovery
		// views never render an unlabelled control; an emptied forgot label
		// intentionally hides the link (see the control description).
		$back_label = trim( (string) ( $s['back_to_sign_in_label'] ?? '' ) );
		if ( '' === $back_label ) {
			$back_label = __( 'Back to sign in', 'agend-elementor' );
		}

		return array(
			'messages' => array(
				'heading'      => (string) ( $s['heading_text'] ?? '' ),
				'intro'        => (string) ( $s['intro_text'] ?? '' ),
				'email'        => __( 'Email', 'agend-elementor' ),
				'password'     => __( 'Password', 'agend-elementor' ),
				'submit'       => (string) ( $s['submit_label'] ?? __( 'Sign in', 'agend-elementor' ) ),
				'signedIn'     => (string) ( $s['signed_in_message'] ?? '' ),
				'portalButton' => (string) ( $s['portal_button_label'] ?? '' ),
				'signOut'      => (string) ( $s['sign_out_label'] ?? __( 'Sign out', 'agend-elementor' ) ),
				'error'        => __( 'Sign-in failed. Check your details and try again.', 'agend-elementor' ),
				'working'      => __( 'Signing in…', 'agend-elementor' ),
				// Password recovery (SPEC-CORE-20260722 US-2.7).
				'forgot'       => (string) ( $s['forgot_label'] ?? __( 'Forgot your password?', 'agend-elementor' ) ),
				'forgotTitle'  => __( 'Reset your password', 'agend-elementor' ),
				'forgotIntro'  => __( 'Enter your account email and we will send you a link to reset your password.', 'agend-elementor' ),
				'forgotSubmit' => __( 'Send reset link', 'agend-elementor' ),
				'forgotWorking' => __( 'Sending…', 'agend-elementor' ),
				'forgotDone'   => __( 'If an account exists for that email, a password reset link has been sent. Check your inbox.', 'agend-elementor' ),
				'forgotError'  => __( 'Could not send the reset link. Please try again.', 'agend-elementor' ),
				'backToSignIn' => $back_label,
				// Password-reset completion (SPEC-CORE-20260722 US-2.7).
				'resetTitle'       => __( 'Choose a new password', 'agend-elementor' ),
				'resetIntro'       => __( 'Enter your account email and a new password to finish resetting it.', 'agend-elementor' ),
				'newPassword'      => __( 'New password', 'agend-elementor' ),
				'confirmPassword'  => __( 'Confirm new password', 'agend-elementor' ),
				'resetSubmit'      => __( 'Update password', 'agend-elementor' ),
				'resetWorking'     => __( 'Updating…', 'agend-elementor' ),
				'resetDone'        => __( 'Your password has been updated. You can now sign in.', 'agend-elementor' ),
				'resetError'       => __( 'That reset link is invalid or has expired. Request a new one.', 'agend-elementor' ),
				'passwordMismatch' => __( 'The two passwords do not match.', 'agend-elementor' ),
			),
		);
	}

	/**
	 * Renders the widget container on the frontend.
	 *
	 * The form and signed-in states are rendered client-side by
	 * assets/js/member-login.js from the config and the session status.
	 */
	protected function render(): void {
		$settings = $this->get_settings_for_display();
		$config   = $this->build_config( $settings );

		$style = sprintf(
			'--agend-ml-heading:%1$s;--agend-ml-body:%2$s;--agend-ml-button:%3$s;--agend-ml-button-text:%4$s;',
			esc_attr( (string) ( $settings['heading_colour'] ?? '#1E2A4A' ) ),
			esc_attr( (string) ( $settings['body_colour'] ?? '#26304D' ) ),
			esc_attr( (string) ( $settings['button_colour'] ?? '#FF6B55' ) ),
			esc_attr( (string) ( $settings['button_text_colour'] ?? '#FFFFFF' ) )
		);
		?>
		<div class="agend-member-login" style="<?php echo esc_attr( $style ); ?>" data-agend-member-login-config="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">
			<div class="agend-ml-status" role="status"><?php esc_html_e( 'Loading…', 'agend-elementor' ); ?></div>
		</div>
		<?php
	}
}
