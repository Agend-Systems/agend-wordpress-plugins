<?php
/**
 * Elementor Agend Resources Collections widget for Agend Elementor Widgets.
 *
 * @package Agend_Elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the collections an editor chose from the Agend Atlas library, the same for every visitor, and adds each signed-in member's own collections after the page loads.
 *
 * A thin adapter: the controls come from the core `resources-collections` surface schema
 * and the markup from the core renderer.
 */
class Agend_Elementor_Resources_Collections extends \Elementor\Widget_Base {

	/**
	 * Returns the widget name (unique identifier).
	 *
	 * @return string Widget name.
	 */
	public function get_name(): string {
		return 'agend-resources-collections';
	}

	/**
	 * Returns the widget display title.
	 *
	 * @return string Widget title.
	 */
	public function get_title(): string {
		return __( 'Agend Resources Collections', 'agend-elementor' );
	}

	/**
	 * Returns the Elementor icon class for the widget.
	 *
	 * @return string Icon class.
	 */
	public function get_icon(): string {
		return 'eicon-library-open';
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
		return array( 'agend', 'resources', 'collections', 'library', 'knowledge', 'atlas' );
	}

	/**
	 * Returns the frontend script handle this widget depends on.
	 *
	 * @return array Script handles.
	 */
	public function get_script_depends(): array {
		return Agend_Elementor_Schema_Controls::depends( 'resources-collections', array( 'agend-apps-records-resources-collections' ) );
	}

	/**
	 * Returns the frontend style handle this widget depends on.
	 *
	 * @return array Style handles.
	 */
	public function get_style_depends(): array {
		return Agend_Elementor_Schema_Controls::depends( 'resources-collections', array( 'agend-apps-records-resources' ) );
	}

	/**
	 * Registers all Elementor controls for this widget.
	 */
	protected function register_controls(): void {
		Agend_Elementor_Schema_Controls::register( $this, agend_apps_records_surface_schema( 'resources-collections' ), 'resources-collections' );
	}

	/**
	 * Echoes the surface, rendered by Agend Apps Core from this widget's settings.
	 */
	protected function render(): void {
		// Stays in the panel and the canvas so a developer sees it, but draws
		// nothing for a visitor and makes no gateway call, while the API key
		// lacks what this widget needs.
		$gate = Agend_Elementor_Schema_Controls::render_gate( 'resources-collections' );
		if ( null !== $gate ) {
			echo $gate; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the surface gate.
			return;
		}

		// A collection list inside a card template would be fetched once per card.
		if ( Agend_Apps_Records_Record_Context::has() ) {
			if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
				echo '<div class="elementor-alert elementor-alert-warning">' . esc_html__( 'Resources Collections cannot be placed inside a card or detail template.', 'agend-elementor' ) . '</div>';
			}
			return;
		}

		$preview = \Elementor\Plugin::$instance->editor->is_edit_mode() || \Elementor\Plugin::$instance->preview->is_preview_mode();

		echo agend_apps_records_render_resources_collections( Agend_Elementor_Global_Colours::resolve( $this->get_settings_for_display() ), array( 'preview' => $preview ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the core renderer.
	}
}
