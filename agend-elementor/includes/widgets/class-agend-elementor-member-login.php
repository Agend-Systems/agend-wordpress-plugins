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
		Agend_Elementor_Schema_Controls::register( $this, agend_apps_records_surface_schema( 'member-login' ) );
	}

	/**
	 * Echoes the surface, rendered by Agend Apps Core from this widget's settings.
	 *
	 * The form and signed-in states are rendered client-side by
	 * assets/js/member-login.js from the config and the session status.
	 */
	protected function render(): void {
		// SPEC-CORE-20260907 US-4.1 AC7: the credential login surface does not
		// exist at all in `sso` member sign-in mode. The edit-mode notice stays
		// here, in the widget: the core renderer's '' return is what a real
		// front-end visitor sees, but an editor needs to know why the canvas
		// is empty.
		if ( class_exists( 'Agend_Apps_Settings' ) && ! Agend_Apps_Settings::credential_login_enabled() ) {
			if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
				echo '<div class="agend-widget-notice">' . esc_html__( 'Member sign-in is set to SSO in Agend Apps settings.', 'agend-elementor' ) . '</div>';
			}
			return;
		}

		echo agend_apps_records_render_member_login( $this->get_settings_for_display() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the core renderer.
	}
}
