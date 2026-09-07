<?php
/**
 * Server render of the member login surface.
 *
 * Page-builder agnostic: the same markup and client config whichever editor
 * placed the surface. An adapter passes the surface's settings (see
 * `agend_apps_records_surface_schema()`) and echoes the returned HTML.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the client-side config object from the surface settings.
 *
 * @param array $s Surface settings (Elementor-shaped values: toggles are 'yes'/'').
 * @return array Config passed to the frontend script as JSON.
 */
function agend_apps_records_member_login_build_config( array $s ): array {
	// An emptied return label falls back to the default so the recovery
	// views never render an unlabelled control; an emptied forgot label
	// intentionally hides the link (see the control description).
	$back_label = trim( (string) ( $s['back_to_sign_in_label'] ?? '' ) );
	if ( '' === $back_label ) {
		$back_label = __( 'Back to sign in', 'agend-apps-core' );
	}

	return array(
		'messages' => array(
			'heading'      => (string) ( $s['heading_text'] ?? '' ),
			'intro'        => (string) ( $s['intro_text'] ?? '' ),
			'email'        => __( 'Email', 'agend-apps-core' ),
			'password'     => __( 'Password', 'agend-apps-core' ),
			'submit'       => (string) ( $s['submit_label'] ?? __( 'Sign in', 'agend-apps-core' ) ),
			'signedIn'     => (string) ( $s['signed_in_message'] ?? '' ),
			'portalButton' => (string) ( $s['portal_button_label'] ?? '' ),
			'signOut'      => (string) ( $s['sign_out_label'] ?? __( 'Sign out', 'agend-apps-core' ) ),
			'error'        => __( 'Sign-in failed. Check your details and try again.', 'agend-apps-core' ),
			'working'      => __( 'Signing in…', 'agend-apps-core' ),
			// Password recovery (SPEC-CORE-20260722 US-2.7).
			'forgot'       => (string) ( $s['forgot_label'] ?? __( 'Forgot your password?', 'agend-apps-core' ) ),
			'forgotTitle'  => __( 'Reset your password', 'agend-apps-core' ),
			'forgotIntro'  => __( 'Enter your account email and we will send you a link to reset your password.', 'agend-apps-core' ),
			'forgotSubmit' => __( 'Send reset link', 'agend-apps-core' ),
			'forgotWorking' => __( 'Sending…', 'agend-apps-core' ),
			'forgotDone'   => __( 'If an account exists for that email, a password reset link has been sent. Check your inbox.', 'agend-apps-core' ),
			'forgotError'  => __( 'Could not send the reset link. Please try again.', 'agend-apps-core' ),
			'backToSignIn' => $back_label,
			// Password-reset completion (SPEC-CORE-20260722 US-2.7).
			'resetTitle'       => __( 'Choose a new password', 'agend-apps-core' ),
			'resetIntro'       => __( 'Enter your account email and a new password to finish resetting it.', 'agend-apps-core' ),
			'newPassword'      => __( 'New password', 'agend-apps-core' ),
			'confirmPassword'  => __( 'Confirm new password', 'agend-apps-core' ),
			'resetSubmit'      => __( 'Update password', 'agend-apps-core' ),
			'resetWorking'     => __( 'Updating…', 'agend-apps-core' ),
			'resetDone'        => __( 'Your password has been updated. You can now sign in.', 'agend-apps-core' ),
			'resetError'       => __( 'That reset link is invalid or has expired. Request a new one.', 'agend-apps-core' ),
			'passwordMismatch' => __( 'The two passwords do not match.', 'agend-apps-core' ),
			// Account registration (SPEC-CORE-20260907 US-3.2). This is the one
			// surface that names a duplicate email; the sign-in form never does.
			'register'         => (string) ( $s['register_label'] ?? '' ),
			'registerTitle'    => __( 'Create your account', 'agend-apps-core' ),
			'registerIntro'    => __( 'Create an Agend member account to sign in on this site.', 'agend-apps-core' ),
			'firstName'        => __( 'First name', 'agend-apps-core' ),
			'lastName'         => __( 'Last name', 'agend-apps-core' ),
			'registerSubmit'   => __( 'Create account', 'agend-apps-core' ),
			'registerWorking'  => __( 'Creating…', 'agend-apps-core' ),
			'registerError'    => __( 'Could not create the account. Check your details and try again.', 'agend-apps-core' ),
			// Email ownership verification (SPEC-CORE-20260907 US-4.3).
			'resend'              => __( 'Send another link', 'agend-apps-core' ),
			'verificationPending' => __( 'Your email address is not yet verified. Check your inbox for the verification link, or request a new one below.', 'agend-apps-core' ),
		),
		// Seconds the resend button stays disabled after each attempt
		// (SPEC-CORE-20260907 US-4.3 AC2).
		'resendCooldownSeconds' => 60,
	);
}

/**
 * Renders the member login surface.
 *
 * The form and signed-in states are rendered client-side by
 * assets/js/member-login.js from the config and the session status.
 *
 * @param array $settings Surface settings (see agend_apps_records_surface_schema( 'member-login' )).
 * @return string The rendered markup, or '' in SSO mode (SPEC-CORE-20260907 US-4.1 AC7:
 *                the credential login surface does not exist at all in `sso` member sign-in mode).
 */
function agend_apps_records_render_member_login( array $settings ): string {
	if ( class_exists( 'Agend_Apps_Settings' ) && ! Agend_Apps_Settings::credential_login_enabled() ) {
		return '';
	}

	$config = agend_apps_records_member_login_build_config( $settings );

	$style = sprintf(
		'--agend-ml-heading:%1$s;--agend-ml-body:%2$s;--agend-ml-button:%3$s;--agend-ml-button-text:%4$s;',
		esc_attr( (string) ( $settings['heading_colour'] ?? '#1E2A4A' ) ),
		esc_attr( (string) ( $settings['body_colour'] ?? '#26304D' ) ),
		esc_attr( (string) ( $settings['button_colour'] ?? '#FF6B55' ) ),
		esc_attr( (string) ( $settings['button_text_colour'] ?? '#FFFFFF' ) )
	);

	ob_start();
	?>
		<div class="agend-member-login" style="<?php echo esc_attr( $style ); ?>" data-agend-member-login-config="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">
			<div class="agend-ml-status" role="status"><?php esc_html_e( 'Loading…', 'agend-apps-core' ); ?></div>
		</div>
		<?php
	return (string) ob_get_clean();
}
