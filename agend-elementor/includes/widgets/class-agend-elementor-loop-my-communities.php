<?php
/**
 * Elementor My Communities widget for Agend Elementor Widgets.
 *
 * @package Agend_Elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The communities (Agend Loop channels) a signed-in member belongs to, each with
 * an "Access community" button that signs them in to Loop. A thin adapter: the
 * controls come from the core `loop-my-communities` surface schema and the
 * markup from the core renderer.
 *
 * What the page holds is a heading and a loading state, the same for everyone.
 * The member's list is fetched by script after the page has loaded, and never
 * stored, so a page cache cannot show one member's communities to another. A
 * visitor who is not signed in gets a sign-in card. In the editor the widget
 * draws a labelled example and calls nothing.
 */
class Agend_Elementor_My_Communities extends \Elementor\Widget_Base {

	/**
	 * Returns the widget name (unique identifier).
	 *
	 * @return string Widget name.
	 */
	public function get_name(): string {
		return 'agend-loop-my-communities';
	}

	/**
	 * Returns the widget display title.
	 *
	 * @return string Widget title.
	 */
	public function get_title(): string {
		return __( 'Agend My Communities', 'agend-elementor' );
	}

	/**
	 * Returns the Elementor icon class for the widget.
	 *
	 * @return string Icon class.
	 */
	public function get_icon(): string {
		return 'eicon-user-circle-o';
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
		return array( 'agend', 'loop', 'community', 'communities', 'channels', 'my communities', 'member', 'sso' );
	}

	/**
	 * Returns the frontend script handle this widget depends on.
	 *
	 * @return array Script handles.
	 */
	public function get_script_depends(): array {
		return Agend_Elementor_Schema_Controls::depends( 'loop-my-communities', array( 'agend-apps-records-loop-my-communities' ) );
	}

	/**
	 * Returns the frontend style handle this widget depends on.
	 *
	 * @return array Style handles.
	 */
	public function get_style_depends(): array {
		return Agend_Elementor_Schema_Controls::depends( 'loop-my-communities', array( 'agend-apps-records-loop-my-communities' ) );
	}

	/**
	 * Never cached by Elementor's element cache.
	 *
	 * What this draws depends on who is looking: a sign-in card for a visitor,
	 * the shell for a member. Elementor's default already says dynamic; it is
	 * stated here because a cached copy of either would be shown to the wrong
	 * person.
	 *
	 * @return bool
	 */
	protected function is_dynamic_content(): bool {
		return true;
	}

	/**
	 * Registers all Elementor controls for this widget.
	 */
	protected function register_controls(): void {
		Agend_Elementor_Schema_Controls::register( $this, agend_apps_records_surface_schema( 'loop-my-communities' ), 'loop-my-communities' );
	}

	/**
	 * Echoes the surface, rendered by Agend Apps Core from this widget's settings.
	 */
	protected function render(): void {
		// Stays in the panel and the canvas so a developer sees it, but draws
		// nothing for a visitor and makes no gateway call, while the API key
		// lacks what this widget needs.
		$gate = Agend_Elementor_Schema_Controls::render_gate( 'loop-my-communities' );
		if ( null !== $gate ) {
			echo $gate; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the surface gate.
			return;
		}

		// A member's list inside a card template would be fetched once per card.
		if ( Agend_Apps_Records_Record_Context::has() ) {
			if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
				echo '<div class="elementor-alert elementor-alert-warning">' . esc_html__( 'A My Communities widget cannot be placed inside a card or detail template.', 'agend-elementor' ) . '</div>';
			}
			return;
		}

		$preview = \Elementor\Plugin::$instance->editor->is_edit_mode() || \Elementor\Plugin::$instance->preview->is_preview_mode();

		echo agend_apps_records_render_loop_my_communities( Agend_Elementor_Global_Colours::resolve( $this->get_settings_for_display() ), array( 'preview' => $preview ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the core renderer.
	}
}
