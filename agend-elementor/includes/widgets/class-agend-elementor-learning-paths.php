<?php
/**
 * Elementor Learning Pathways widget for Agend Elementor Widgets.
 *
 * @package Agend_Elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the published Agend LMS learning paths, each with its courses in
 * order. A thin adapter: the controls come from the core `learning-paths`
 * surface schema and the markup from the core renderer, which draws it all on
 * the server (there is no script) from the public path reads. Courses link to
 * the Courses page set under Catalogue Pages when there is one.
 */
class Agend_Elementor_Learning_Paths extends \Elementor\Widget_Base {

	/**
	 * Returns the widget name (unique identifier).
	 *
	 * @return string Widget name.
	 */
	public function get_name(): string {
		return 'agend-learning-paths';
	}

	/**
	 * Returns the widget display title.
	 *
	 * @return string Widget title.
	 */
	public function get_title(): string {
		return __( 'Agend Learning Pathways', 'agend-elementor' );
	}

	/**
	 * Returns the Elementor icon class for the widget.
	 *
	 * @return string Icon class.
	 */
	public function get_icon(): string {
		return 'eicon-flow';
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
		return array( 'agend', 'learning', 'paths', 'pathways', 'courses', 'lms' );
	}

	/**
	 * Returns the frontend style handle this widget depends on. The widget has
	 * no script.
	 *
	 * @return array Style handles.
	 */
	public function get_style_depends(): array {
		return Agend_Elementor_Schema_Controls::depends( 'learning-paths', array( 'agend-apps-records-learning-paths' ) );
	}

	/**
	 * Registers all Elementor controls for this widget.
	 */
	protected function register_controls(): void {
		Agend_Elementor_Schema_Controls::register( $this, agend_apps_records_surface_schema( 'learning-paths' ), 'learning-paths' );
	}

	/**
	 * Echoes the surface, rendered by Agend Apps Core from this widget's settings.
	 */
	protected function render(): void {
		// Stays in the panel and the canvas so a developer sees it, but draws
		// nothing for a visitor and makes no gateway call, while the API key
		// lacks what this widget needs.
		$gate = Agend_Elementor_Schema_Controls::render_gate( 'learning-paths' );
		if ( null !== $gate ) {
			echo $gate; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the surface gate.
			return;
		}

		// A path list inside a card template would be fetched once per card.
		if ( Agend_Apps_Records_Record_Context::has() ) {
			if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
				echo '<div class="elementor-alert elementor-alert-warning">' . esc_html__( 'A Learning Pathways widget cannot be placed inside a card or detail template.', 'agend-elementor' ) . '</div>';
			}
			return;
		}

		echo agend_apps_records_render_learning_paths( Agend_Elementor_Global_Colours::resolve( $this->get_settings_for_display() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the core renderer.
	}
}
