<?php
/**
 * Elementor Credential Check widget for Agend Elementor Widgets.
 *
 * @package Agend_Elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A form where a visitor checks a certificate or badge code against the Agend
 * learning record. A thin adapter: the controls come from the core
 * `credential-check` surface schema and the markup from the core renderer. The
 * result is drawn by the surface's script from an answer the plugin's own route
 * gives, so nothing about a credential is ever in the page.
 */
class Agend_Elementor_Credential_Check extends \Elementor\Widget_Base {

	/**
	 * Returns the widget name (unique identifier).
	 *
	 * @return string Widget name.
	 */
	public function get_name(): string {
		return 'agend-credential-check';
	}

	/**
	 * Returns the widget display title.
	 *
	 * @return string Widget title.
	 */
	public function get_title(): string {
		return __( 'Agend Credential Check', 'agend-elementor' );
	}

	/**
	 * Returns the Elementor icon class for the widget.
	 *
	 * @return string Icon class.
	 */
	public function get_icon(): string {
		return 'eicon-check-circle';
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
	 * Returns search keywords for the widget panel.
	 *
	 * @return array Keywords.
	 */
	public function get_keywords(): array {
		return array( 'agend', 'credential', 'certificate', 'badge', 'verify', 'check', 'learning', 'lms' );
	}

	/**
	 * Returns the frontend script handle this widget depends on. It pulls in
	 * the shared captcha client itself.
	 *
	 * @return array Script handles.
	 */
	public function get_script_depends(): array {
		return Agend_Elementor_Schema_Controls::depends( 'credential-check', array( 'agend-apps-records-credential-check' ) );
	}

	/**
	 * Returns the frontend style handle this widget depends on.
	 *
	 * @return array Style handles.
	 */
	public function get_style_depends(): array {
		return Agend_Elementor_Schema_Controls::depends( 'credential-check', array( 'agend-apps-records-credential-check' ) );
	}

	/**
	 * Registers all Elementor controls for this widget.
	 */
	protected function register_controls(): void {
		Agend_Elementor_Schema_Controls::register( $this, agend_apps_records_surface_schema( 'credential-check' ), 'credential-check' );
	}

	/**
	 * Whether the widget is being drawn for an editor rather than a visitor.
	 *
	 * @return bool
	 */
	private function is_editor(): bool {
		return \Elementor\Plugin::$instance->editor->is_edit_mode()
			|| \Elementor\Plugin::$instance->preview->is_preview_mode();
	}

	/**
	 * Echoes the surface, rendered by Agend Apps Core from this widget's settings.
	 */
	protected function render(): void {
		$editor = $this->is_editor();

		// Stays in the panel and the canvas so a developer sees it, but draws
		// nothing for a visitor while the API key lacks what this widget needs.
		$gate = Agend_Elementor_Schema_Controls::render_gate( 'credential-check' );
		if ( null !== $gate ) {
			echo $gate; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the surface gate.
			return;
		}

		// A lookup form inside a card template would be drawn once per card.
		if ( Agend_Apps_Records_Record_Context::has() ) {
			if ( $editor ) {
				echo '<div class="elementor-alert elementor-alert-warning">' . esc_html__( 'A Credential Check widget cannot be placed inside a card or detail template.', 'agend-elementor' ) . '</div>';
			}
			return;
		}

		// An editor is told why visitors would not see the form (no security
		// check set up), and still sees it so it can be styled.
		if ( $editor ) {
			foreach ( agend_apps_records_credential_check_notices( agend_apps_records_credential_check_state() ) as $notice ) {
				echo '<div class="elementor-alert elementor-alert-warning">' . esc_html( $notice ) . '</div>';
			}
		}

		echo agend_apps_records_render_credential_check( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the core renderer.
			Agend_Elementor_Global_Colours::resolve( $this->get_settings_for_display() ),
			array(
				'preview' => $editor,
				'id'      => $this->get_id(),
			)
		);
	}
}
