<?php
/**
 * Shared base of the Agend Elementor Community widgets.
 *
 * @package Agend_Elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The part of the Community announcements, channels and popular posts widgets
 * that does not differ. A thin adapter: the controls come from the core surface
 * schema and the markup from the core renderer, which draws it all on the
 * server (there is no script) from the Community reads. A subclass names its
 * surface and its look in the panel.
 */
abstract class Agend_Elementor_Community_Widget extends \Elementor\Widget_Base {

	/**
	 * The core surface id, e.g. 'community-announcements'.
	 *
	 * @return string
	 */
	abstract protected function surface(): string;

	/**
	 * Returns the Elementor categories this widget belongs to.
	 *
	 * @return array List of category slugs.
	 */
	public function get_categories(): array {
		return array( Agend_Elementor::CATEGORY );
	}

	/**
	 * Returns the frontend style handle this widget depends on. The widget has
	 * no script.
	 *
	 * @return array Style handles.
	 */
	public function get_style_depends(): array {
		return Agend_Elementor_Schema_Controls::depends( $this->surface(), array( 'agend-apps-records-community' ) );
	}

	/**
	 * Registers all Elementor controls for this widget.
	 */
	protected function register_controls(): void {
		Agend_Elementor_Schema_Controls::register( $this, agend_apps_records_surface_schema( $this->surface() ), $this->surface() );
	}

	/**
	 * Echoes the surface, rendered by Agend Apps Core from this widget's settings.
	 */
	protected function render(): void {
		// Stays in the panel and the canvas so a developer sees it, but draws
		// nothing for a visitor and makes no gateway call, while the API key
		// lacks what this widget needs.
		$gate = Agend_Elementor_Schema_Controls::render_gate( $this->surface() );
		if ( null !== $gate ) {
			echo $gate; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the surface gate.
			return;
		}

		// A list inside a card template would be read once per card.
		if ( Agend_Apps_Records_Record_Context::has() ) {
			if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
				echo '<div class="elementor-alert elementor-alert-warning">' . esc_html__( 'A Community widget cannot be placed inside a card or detail template.', 'agend-elementor' ) . '</div>';
			}
			return;
		}

		$render = 'agend_apps_records_render_' . str_replace( '-', '_', $this->surface() );

		// The editor is told where to allow channels when none are allowed; a
		// visitor never is.
		echo $render( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the core renderer.
			Agend_Elementor_Global_Colours::resolve( $this->get_settings_for_display() ),
			array( 'preview' => agend_apps_records_is_editor_request() )
		);
	}
}
