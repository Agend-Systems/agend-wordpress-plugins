<?php
/**
 * Elementor Jobs (Agend Careers) widget for Agend Elementor Widgets.
 *
 * @package Agend_Elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the Agend Careers jobs board: a searchable, filterable list or grid
 * of published jobs with a connected client-side job detail view. With
 * "Featured jobs only" on and a small page size it doubles as a featured jobs
 * strip, linking to the dedicated Jobs page. A thin adapter: the controls come
 * from the core `jobs-catalogue` surface schema and the markup from the core
 * renderer.
 */
class Agend_Elementor_Jobs_Catalogue extends \Elementor\Widget_Base {

	/**
	 * Returns the widget name (unique identifier).
	 *
	 * @return string Widget name.
	 */
	public function get_name(): string {
		return 'agend-jobs-catalogue';
	}

	/**
	 * Returns the widget display title.
	 *
	 * @return string Widget title.
	 */
	public function get_title(): string {
		return __( 'Agend Jobs', 'agend-elementor' );
	}

	/**
	 * Returns the Elementor icon class for the widget.
	 *
	 * @return string Icon class.
	 */
	public function get_icon(): string {
		return 'eicon-person';
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
		return array( 'agend', 'jobs', 'careers', 'job board', 'vacancies' );
	}

	/**
	 * Returns the frontend script handle this widget depends on.
	 *
	 * @return array Script handles.
	 */
	public function get_script_depends(): array {
		return Agend_Elementor_Schema_Controls::depends( 'jobs-catalogue', array( 'agend-apps-records-jobs-catalogue' ) );
	}

	/**
	 * Returns the frontend style handle this widget depends on.
	 *
	 * @return array Style handles.
	 */
	public function get_style_depends(): array {
		return Agend_Elementor_Schema_Controls::depends( 'jobs-catalogue', array( 'agend-apps-records-jobs-catalogue' ) );
	}

	/**
	 * Registers all Elementor controls for this widget.
	 */
	protected function register_controls(): void {
		Agend_Elementor_Schema_Controls::register( $this, agend_apps_records_surface_schema( 'jobs-catalogue' ), 'jobs-catalogue' );
		$this->register_style_controls();
	}

	/**
	 * Registers the Style tab typography controls. The colours section comes
	 * from the surface schema, registered by register_controls() above.
	 */
	private function register_style_controls(): void {
		$this->start_controls_section(
			'section_style_typography',
			array(
				'label' => __( 'Typography', 'agend-elementor' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'inherit_fonts',
			array(
				'label'   => __( 'Inherit site theme fonts', 'agend-elementor' ),
				'type'    => \Elementor\Controls_Manager::SWITCHER,
				'default' => 'yes',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Echoes the surface, rendered by Agend Apps Core from this widget's settings.
	 */
	protected function render(): void {
		// Stays in the panel and the canvas so a developer sees it, but draws
		// nothing for a visitor and makes no gateway call, while the API key
		// lacks what this widget needs.
		$gate = Agend_Elementor_Schema_Controls::render_gate( 'jobs-catalogue' );
		if ( null !== $gate ) {
			echo $gate; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the surface gate.
			return;
		}

		// A board inside a card template would fetch the list once per card.
		if ( Agend_Apps_Records_Record_Context::has() ) {
			if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
				echo '<div class="elementor-alert elementor-alert-warning">' . esc_html__( 'A Jobs board cannot be placed inside a card or detail template.', 'agend-elementor' ) . '</div>';
			}
			return;
		}

		echo agend_apps_records_render_jobs_catalogue( Agend_Elementor_Global_Colours::resolve( $this->get_settings_for_display() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the core renderer.
	}
}
