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
 * Colour role defaults the member login surface exposes: the same four roles
 * as the catalogues, minus accent, which the login form's CSS has no use for
 * (US-1.2 business rule).
 *
 * @var array<string, string>
 */
const AGEND_APPS_RECORDS_MEMBER_LOGIN_COLOUR_ROLES = array(
	'heading'    => AGEND_APPS_RECORDS_COLOUR_DEFAULTS['heading'],
	'body'       => AGEND_APPS_RECORDS_COLOUR_DEFAULTS['body'],
	'button'     => AGEND_APPS_RECORDS_COLOUR_DEFAULTS['button'],
	'buttonText' => AGEND_APPS_RECORDS_COLOUR_DEFAULTS['buttonText'],
);

/**
 * Builds the client-side config object from the surface settings.
 *
 * @param array $s Surface settings (Elementor-shaped values: toggles are 'yes'/'').
 * @return array Config passed to the frontend script as JSON.
 */
function agend_apps_records_member_login_build_config( array $s, array $colours ): array {
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
		// US-1.3: no inheritFonts here, unlike the catalogues -- the login
		// form has no font settings to inherit or override (Decision 2.1/6.2).
		'theme'                 => array( 'colourSource' => $colours['source'] ),
	);
}

/**
 * Renders the member login surface.
 *
 * In `credentials` mode the form and signed-in states are rendered
 * client-side by assets/js/member-login.js from the config and the session
 * status. In `wordpress` mode there is no credential form and no session to
 * probe -- see agend_apps_records_render_member_login_wordpress() for why
 * that path is server-rendered instead.
 *
 * @param array $settings Surface settings (see agend_apps_records_surface_schema( 'member-login' )).
 * @return string The rendered markup, or '' in `sso` mode (SPEC-CORE-20260907 US-4.1 AC7:
 *                the credential login surface does not exist at all in `sso` member sign-in mode).
 */
function agend_apps_records_render_member_login( array $settings ): string {
	$agend_settings_available = class_exists( 'Agend_Apps_Settings' );
	$credential_enabled       = ! $agend_settings_available || Agend_Apps_Settings::credential_login_enabled();
	// docs/PLAN-wordpress-idp-option-b.md section 4.5: `wordpress` mode is a
	// real, member-facing sign-in mode, unlike `sso` -- the surface must
	// render something useful rather than standing down.
	$wordpress_enabled        = $agend_settings_available && Agend_Apps_Settings::wordpress_idp_enabled();

	if ( ! $credential_enabled && ! $wordpress_enabled ) {
		return '';
	}

	$colours = agend_apps_records_resolve_colours(
		array( 'inherit_colours' => (string) ( $settings['inherit_colours'] ?? 'yes' ) ) + $settings,
		AGEND_APPS_RECORDS_MEMBER_LOGIN_COLOUR_ROLES
	);

	$style = sprintf(
		'--agend-ml-heading:%1$s;--agend-ml-body:%2$s;--agend-ml-button:%3$s;--agend-ml-button-text:%4$s;',
		esc_attr( $colours['colours']['heading'] ),
		esc_attr( $colours['colours']['body'] ),
		esc_attr( $colours['colours']['button'] ),
		esc_attr( $colours['colours']['buttonText'] )
	);

	if ( $wordpress_enabled ) {
		return agend_apps_records_render_member_login_wordpress( $settings, $style );
	}

	$config = agend_apps_records_member_login_build_config( $settings, $colours );

	ob_start();
	?>
		<div class="agend-member-login" style="<?php echo esc_attr( $style ); ?>" data-agend-member-login-config="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">
			<div class="agend-ml-status" role="status"><?php esc_html_e( 'Loading…', 'agend-apps-core' ); ?></div>
		</div>
		<?php
	return (string) ob_get_clean();
}

/**
 * Renders the member login surface in `wordpress` sign-in mode
 * (docs/PLAN-wordpress-idp-option-b.md section 4.5).
 *
 * `wordpress` mode has no credential form and no `/auth/*` proxy routes at
 * all (`includes/rest/auth-routes.php` loads only when
 * `Agend_Apps_Settings::credential_login_enabled()`), so this cannot reuse
 * member-login.js's client-side `/auth/session` probe the way `credentials`
 * mode does. It is server-rendered instead, straight from
 * `is_user_logged_in()` at request time, and the markup deliberately carries
 * no `data-agend-member-login-config` attribute, so the script -- which
 * still loads because a `credentials`-mode surface elsewhere on the site
 * needs it -- leaves this instance alone.
 *
 * Signed out: a prompt linking to `wp_login_url()`, carrying the current URL
 * as the redirect so the member lands back here once signed in. WordPress
 * owns the credential in this mode, so the copy never implies a separate
 * Agend password.
 *
 * Signed in: the same "signed in" card `credentials` mode renders -- same
 * CSS classes, same `signed_in_message` / `portal_button_label` /
 * `sign_out_label` settings -- reused rather than re-described here, with
 * the portal button pointing at the plain portal URL (there is no
 * authenticated hand-off to mint in this mode, unlike `credentials` mode's
 * `/auth/portal-handoff`) and sign out at `wp_logout_url()`.
 *
 * @param array  $settings Surface settings (see agend_apps_records_surface_schema( 'member-login' )).
 * @param string $style    Inline custom-property style string for the resolved colours.
 * @return string The rendered markup.
 */
function agend_apps_records_render_member_login_wordpress( array $settings, string $style ): string {
	$heading     = (string) ( $settings['heading_text'] ?? '' );
	$current_url = home_url( isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '' );

	ob_start();

	if ( is_user_logged_in() ) {
		$signed_in_message = (string) ( $settings['signed_in_message'] ?? '' );
		$portal_label      = (string) ( $settings['portal_button_label'] ?? '' );
		// An emptied sign-out label intentionally hides the control, matching
		// the credential-mode config in agend_apps_records_member_login_build_config().
		$sign_out_label    = trim( (string) ( $settings['sign_out_label'] ?? __( 'Sign out', 'agend-apps-core' ) ) );
		$portal_url        = '';

		if ( class_exists( 'Agend_Apps_Settings' ) ) {
			// The account portal home ({portal}/home/{slug}) when the core
			// plugin provides it; the portal root on older core versions.
			$portal_url = method_exists( 'Agend_Apps_Settings', 'get_portal_home_url' )
				? Agend_Apps_Settings::get_portal_home_url()
				: Agend_Apps_Settings::get_portal_url();
		}
		?>
		<div class="agend-member-login" style="<?php echo esc_attr( $style ); ?>">
			<div class="agend-ml-card is-signed-in">
				<?php if ( '' !== $heading ) : ?>
					<h3 class="agend-ml-card__heading"><?php echo esc_html( $heading ); ?></h3>
				<?php endif; ?>
				<?php if ( '' !== $signed_in_message ) : ?>
					<p class="agend-ml-card__text"><?php echo esc_html( $signed_in_message ); ?></p>
				<?php endif; ?>
				<?php if ( '' !== $portal_url && '' !== $portal_label ) : ?>
					<a class="agend-ml-card__button" href="<?php echo esc_url( $portal_url ); ?>"><?php echo esc_html( $portal_label ); ?></a>
				<?php endif; ?>
				<?php if ( '' !== $sign_out_label ) : ?>
					<a class="agend-ml-card__link" href="<?php echo esc_url( wp_logout_url( $current_url ) ); ?>"><?php echo esc_html( $sign_out_label ); ?></a>
				<?php endif; ?>
			</div>
		</div>
		<?php
	} else {
		?>
		<div class="agend-member-login" style="<?php echo esc_attr( $style ); ?>">
			<div class="agend-ml-card">
				<?php if ( '' !== $heading ) : ?>
					<h3 class="agend-ml-card__heading"><?php echo esc_html( $heading ); ?></h3>
				<?php endif; ?>
				<p class="agend-ml-card__text">
					<?php esc_html_e( 'Sign in with your WordPress account to continue.', 'agend-apps-core' ); ?>
				</p>
				<a class="agend-ml-card__button" href="<?php echo esc_url( wp_login_url( $current_url ) ); ?>">
					<?php esc_html_e( 'Sign in', 'agend-apps-core' ); ?>
				</a>
			</div>
		</div>
		<?php
	}

	return (string) ob_get_clean();
}
