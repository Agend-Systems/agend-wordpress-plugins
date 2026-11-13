<?php
/**
 * Support for the inline sign-in form the member login surface renders in
 * `wordpress` sign-in mode.
 *
 * The form posts to WordPress's own login handler, so every `authenticate`
 * filter, the SAML IdP continuation, rate limiting and security plugins still
 * apply. wp-login.php answers a failed attempt by rendering its own screen
 * rather than redirecting back, so this file hooks `wp_login_failed` to send
 * the member back to the page they signed in from, with a flag the surface
 * turns into an inline message. Failures that need wp-login.php's own UI
 * (an MFA step, a lockout) are left to it.
 *
 * It also gives the wp-login.php screens the member can still reach (lost
 * password, reset, an MFA step) the site's name and logo instead of
 * WordPress's.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Query arg the failure redirect adds to the page the form was submitted from.
 *
 * @var string
 */
const AGEND_APPS_WP_LOGIN_FAILURE_ARG = 'agend_login';

/**
 * Hidden field naming the page the inline form was submitted from.
 *
 * @var string
 */
const AGEND_APPS_WP_LOGIN_RETURN_FIELD = 'agend_ml_return';

/**
 * Failure codes the inline form handles, mapped to the flag the surface reads.
 * Any other code is left to wp-login.php.
 *
 * @return array<string, string>
 */
function agend_apps_wp_login_inline_failure_flags(): array {
	return array(
		'invalid_username'                 => 'failed',
		'invalid_email'                    => 'failed',
		'incorrect_password'               => 'failed',
		'empty_username'                   => 'failed',
		'empty_password'                   => 'failed',
		'agend_apps_invalid_credentials'   => 'failed',
		'agend_apps_verification_required' => 'verify',
	);
}

/**
 * The message the surface shows for a failure flag.
 *
 * Deliberately generic: it never says whether the email or the password was
 * wrong.
 *
 * @param string $flag Value of the failure query arg.
 * @return string Empty when the flag is not one the form sets.
 */
function agend_apps_wp_login_inline_failure_message( string $flag ): string {
	if ( 'failed' === $flag ) {
		return __( 'The email or password you entered is incorrect.', 'agend-apps-core' );
	}
	if ( 'verify' === $flag ) {
		return __( 'Check your email for a link to verify your address, then sign in again.', 'agend-apps-core' );
	}
	return '';
}

/**
 * Where to send the member back to after a failed inline sign-in.
 *
 * @param WP_Error|mixed       $error WordPress's login error.
 * @param array<string, mixed> $post  The submitted form (`$_POST`).
 * @return string The page to return to with the failure flag, or '' when the
 *                request did not come from the inline form or the failure is
 *                one wp-login.php should show itself.
 */
function agend_apps_wp_login_inline_failure_redirect( $error, array $post ): string {
	if ( ! $error instanceof WP_Error || empty( $post[ AGEND_APPS_WP_LOGIN_RETURN_FIELD ] ) ) {
		return '';
	}

	$flags = agend_apps_wp_login_inline_failure_flags();
	$flag  = 'failed';
	foreach ( $error->get_error_codes() as $code ) {
		if ( ! isset( $flags[ $code ] ) ) {
			// One code the form cannot explain (a lockout, an MFA step) hands
			// the whole failure to wp-login.php.
			return '';
		}
		if ( 'verify' === $flags[ $code ] ) {
			$flag = 'verify';
		}
	}

	$return = wp_validate_redirect( esc_url_raw( wp_unslash( (string) $post[ AGEND_APPS_WP_LOGIN_RETURN_FIELD ] ) ), '' );
	if ( '' === $return ) {
		return '';
	}

	return add_query_arg( AGEND_APPS_WP_LOGIN_FAILURE_ARG, $flag, remove_query_arg( AGEND_APPS_WP_LOGIN_FAILURE_ARG, $return ) );
}

/**
 * `wp_login_failed` handler: returns the member to the sign-in page.
 *
 * @param string   $username Submitted username (unused).
 * @param WP_Error $error    Login error.
 */
function agend_apps_wp_login_inline_on_failure( $username, $error = null ): void {
	unset( $username );

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the login form carries no nonce; the target is validated to this site.
	$target = agend_apps_wp_login_inline_failure_redirect( $error, $_POST );
	if ( '' === $target ) {
		return;
	}

	wp_safe_redirect( $target );
	exit;
}

/**
 * Prints the site name or custom logo in place of WordPress's logo on
 * wp-login.php.
 */
function agend_apps_wp_login_inline_print_branding(): void {
	$logo_id  = (int) get_theme_mod( 'custom_logo' );
	$logo_url = $logo_id > 0 ? (string) wp_get_attachment_image_url( $logo_id, 'medium' ) : '';

	echo '<style id="agend-apps-login-branding">';
	if ( '' !== $logo_url ) {
		printf(
			'#login h1 a{background-image:url(%s);background-size:contain;background-position:center;width:100%%;max-width:200px;height:80px;}',
			esc_url( $logo_url )
		);
	} else {
		echo '#login h1 a{background-image:none;width:auto;height:auto;text-indent:0;font-size:1.5rem;font-weight:600;line-height:1.3;text-align:center;}';
	}
	echo '</style>';
}

/**
 * Registers the `wordpress`-mode hooks.
 */
function agend_apps_wp_login_inline_register(): void {
	add_action( 'wp_login_failed', 'agend_apps_wp_login_inline_on_failure', 10, 2 );
	add_action( 'login_enqueue_scripts', 'agend_apps_wp_login_inline_print_branding' );
	add_filter(
		'login_headerurl',
		static function () {
			return home_url( '/' );
		}
	);
	add_filter(
		'login_headertext',
		static function () {
			return wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		}
	);
}
