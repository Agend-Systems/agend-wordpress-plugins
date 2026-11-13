<?php
/**
 * Elementor "Agend Member Jobs" widget: a signed-in member's job credits,
 * their own job submissions and the "Post a job" form.
 *
 * @package Agend_Elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A thin adapter: the controls come from the core `member-jobs` surface schema
 * and the markup from the core renderer. Credits are spent and returned by
 * Agend Apps Core's `jobs/me` routes, never by this widget.
 */
class Agend_Elementor_Member_Jobs extends \Elementor\Widget_Base {

	/**
	 * Returns the widget name (unique identifier).
	 *
	 * @return string Widget name.
	 */
	public function get_name(): string {
		return 'agend-member-jobs';
	}

	/**
	 * Returns the widget display title.
	 *
	 * @return string Widget title.
	 */
	public function get_title(): string {
		return __( 'Agend Member Jobs', 'agend-elementor' );
	}

	/**
	 * Returns the Elementor icon class for the widget.
	 *
	 * @return string Icon class.
	 */
	public function get_icon(): string {
		return 'eicon-form-vertical';
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
		return array( 'agend', 'jobs', 'careers', 'post a job', 'my jobs', 'credits', 'member' );
	}

	/**
	 * Returns the frontend script handle this widget depends on.
	 *
	 * @return array Script handles.
	 */
	public function get_script_depends(): array {
		return Agend_Elementor_Schema_Controls::depends( 'member-jobs', array( 'agend-apps-records-member-jobs' ) );
	}

	/**
	 * Returns the frontend style handle this widget depends on.
	 *
	 * @return array Style handles.
	 */
	public function get_style_depends(): array {
		return Agend_Elementor_Schema_Controls::depends( 'member-jobs', array( 'agend-apps-records-member-jobs' ) );
	}

	/**
	 * Registers all Elementor controls for this widget.
	 */
	protected function register_controls(): void {
		Agend_Elementor_Schema_Controls::register( $this, agend_apps_records_surface_schema( 'member-jobs' ), 'member-jobs' );

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
		$gate = Agend_Elementor_Schema_Controls::render_gate( 'member-jobs' );
		if ( null !== $gate ) {
			echo $gate; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the surface gate.
			return;
		}

		if ( Agend_Apps_Records_Record_Context::has() ) {
			if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
				echo '<div class="elementor-alert elementor-alert-warning">' . esc_html__( 'Member Jobs cannot be placed inside a card or detail template.', 'agend-elementor' ) . '</div>';
			}
			return;
		}

		$preview = \Elementor\Plugin::$instance->editor->is_edit_mode() || \Elementor\Plugin::$instance->preview->is_preview_mode();

		echo agend_apps_records_render_member_jobs( Agend_Elementor_Global_Colours::resolve( $this->get_settings_for_display() ), array( 'preview' => $preview ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the core renderer.
	}
}
