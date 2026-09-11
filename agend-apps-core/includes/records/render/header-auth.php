<?php
/**
 * Server render of the header auth-link surface.
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

// This renderer reads a `url` field through the shared normaliser. The plugin
// bootstrap already loads format.php ahead of every renderer, so this require
// is for the callers that load a single renderer on its own, the unit tests
// among them, rather than for production.
require_once __DIR__ . '/../format.php';

/**
 * Builds the client-side config object from the surface settings.
 *
 * @param array $s Surface settings (see agend_apps_records_surface_schema( 'header-auth' )).
 * @return array Config passed to the frontend script as JSON.
 */
function agend_apps_records_header_auth_build_config( array $s ): array {
	// `login_url` is a `url`-shaped `adapter` field: Elementor hands us
	// `array( 'url' => ... )`, a Gutenberg block a plain string.
	$login_url = agend_apps_records_normalise_url_setting( $s['login_url'] ?? null );
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

	$config = array(
		'loggedOutLabel' => (string) ( $s['logged_out_label'] ?? __( 'Log In', 'agend-apps-core' ) ),
		'loggedInLabel'  => (string) ( $s['logged_in_label'] ?? __( 'My Portal', 'agend-apps-core' ) ),
		// An emptied label intentionally hides the sign-out dropdown (see
		// the control description).
		'signOutLabel'   => trim( (string) ( $s['sign_out_label'] ?? __( 'Sign out', 'agend-apps-core' ) ) ),
		'loginUrl'       => $login_url,
		// Fallback portal URL used if the authenticated hand-off cannot be
		// minted; the signed-in click prefers the hand-off (US-1.5).
		'portalUrl'      => $portal_url,
	);

	// docs/PLAN-wordpress-idp-option-b.md section 4.5: `wordpress` mode has
	// no credential session for window.agendApps.loggedIn to reflect (it is
	// populated from Agend_Apps_Member_Session, the credential-login store,
	// which nothing ever writes to in this mode) and no `/auth/*` proxy
	// routes (`includes/rest/auth-routes.php` loads only when
	// `credential_login_enabled()`) to hand off to the portal or sign out
	// through. assets/js/header-auth.js reads `wordpressMode` to switch to
	// the real WordPress session and to plain navigation for both actions.
	if ( class_exists( 'Agend_Apps_Settings' ) && Agend_Apps_Settings::wordpress_idp_enabled() ) {
		$config['wordpressMode'] = true;
		$config['signedIn']      = is_user_logged_in();
		$config['logoutUrl']     = wp_logout_url();
	}

	return $config;
}

/**
 * Renders the header auth-link surface.
 *
 * The link is rendered client-side by assets/js/header-auth.js from the
 * config and the shared `window.agendApps.loggedIn` signal, so one cached
 * header adapts per member. In `wordpress` mode nothing populates that
 * signal, so the config carries the session state instead
 * (docs/PLAN-wordpress-idp-option-b.md section 4.5).
 *
 * @param array $settings Surface settings (see agend_apps_records_surface_schema( 'header-auth' )).
 * @return string The rendered markup, or '' in `sso` mode (SPEC-CORE-20260907 US-4.1 AC7:
 *                the credential login surface does not exist at all in `sso` member sign-in mode).
 */
function agend_apps_records_render_header_auth( array $settings ): string {
	$agend_settings_available = class_exists( 'Agend_Apps_Settings' );
	$credential_enabled       = ! $agend_settings_available || Agend_Apps_Settings::credential_login_enabled();
	// docs/PLAN-wordpress-idp-option-b.md section 4.5: this is a "Log In /
	// My Portal" control, meaningful in every mode where a member actually
	// signs in somewhere -- `wordpress` included. Only `sso` mode has
	// nothing for it to point at, so it alone still stands down.
	$wordpress_enabled        = $agend_settings_available && Agend_Apps_Settings::wordpress_idp_enabled();

	if ( ! $credential_enabled && ! $wordpress_enabled ) {
		return '';
	}

	$config = agend_apps_records_header_auth_build_config( $settings );

	ob_start();
	?>
		<div class="agend-header-auth" data-agend-header-auth-config="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">
			<span class="agend-header-auth__placeholder" aria-hidden="true"></span>
		</div>
		<?php
	return (string) ob_get_clean();
}
