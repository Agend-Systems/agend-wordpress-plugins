<?php
/**
 * Elementor "Agend Export Report" widget: a download control for the
 * directory's export reports.
 *
 * @package Agend_Elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Two shapes, chosen per instance: a button that downloads one nominated
 * report, or a menu whose trigger opens a list of reports where choosing one
 * downloads it. The output format is the designer's choice, not the
 * visitor's: a visitor picking between CSV and Excel is a decision they have
 * no basis to make.
 *
 * A report's parameters are supplied by mapping rows keyed on the parameter's
 * FIELD rather than on its condition id. A report declares its parameters as
 * `{name, field}`, where `name` is an internal uuid that differs per report
 * while `field` is what the parameter filters on. Mapping on the field means
 * one set of rows serves every report a dropdown offers, and a report added
 * later inherits the mapping instead of needing new rows.
 *
 * A mapping row supplies its value by hand or reads it from a Directory
 * Catalogue on the same page, so an export can follow whatever the visitor has
 * filtered the catalogue down to.
 */
class Agend_Elementor_Export_Reports extends \Elementor\Widget_Base {

	use Agend_Elementor_Field_Widget_Trait;

	public function get_name(): string {
		return 'agend-export-reports';
	}

	public function get_title(): string {
		return __( 'Agend Export Report', 'agend-elementor' );
	}

	public function get_icon(): string {
		return 'eicon-download-button';
	}

	public function get_categories(): array {
		return array( Agend_Elementor::CATEGORY );
	}

	public function get_keywords(): array {
		return array( 'agend', 'export', 'report', 'download', 'csv', 'xlsx', 'directory' );
	}

	public function get_script_depends(): array {
		return array( 'agend-apps-records-export-reports' );
	}

	public function get_style_depends(): array {
		return array( 'agend-apps-records-export-reports' );
	}

	/**
	 * Registers a Content-tab control this widget declares itself because the
	 * shared schema vocabulary cannot describe it: a notice conditionally
	 * registered on live account data rather than on another field's value,
	 * and a RAW_HTML descriptor whose `content_classes` the `note` type does
	 * not pass through.
	 *
	 * @param string $name Schema field name.
	 * @return void
	 */
	public function register_adapter_control( string $name ): void {
		switch ( $name ) {
			case 'parameters_note':
				$this->add_control(
					'parameters_note',
					array(
						'type'            => \Elementor\Controls_Manager::RAW_HTML,
						'raw'             => esc_html__( 'A report can accept parameters that narrow what it exports. Match a row to the field the parameter filters on, for example keyword or custom.education_level. A report that declares no parameters ignores these rows.', 'agend-elementor' ),
						'content_classes' => 'elementor-descriptor',
					)
				);
				break;

			case 'reports_unavailable':
				// The report list has only the "Select a report" placeholder
				// when the account has none; count() > 1 is the "has real
				// reports" test both call sites use.
				if ( count( agend_apps_records_export_reports_report_options() ) <= 1 ) {
					$scope_notice = function_exists( 'agend_apps_records_feature_available' ) && ! agend_apps_records_feature_available( 'directory_export_reports' )
						? agend_apps_records_feature_missing_scope_notice( 'directory_export_reports' )
						: '';

					$this->add_control(
						'reports_unavailable',
						array(
							'type'            => \Elementor\Controls_Manager::RAW_HTML,
							'raw'             => '' !== $scope_notice
								? esc_html( $scope_notice )
								: esc_html__( 'No export reports were returned for this account. Check that the API key holds the directory.export_reports.browse scope and that at least one report is published.', 'agend-elementor' ),
							'content_classes' => 'elementor-panel-alert elementor-panel-alert-warning',
						)
					);
				}
				break;
		}
	}

	protected function register_controls(): void {
		Agend_Elementor_Schema_Controls::register( $this, agend_apps_records_surface_schema( 'export-reports' ) );

		$this->start_controls_section(
			'section_style',
			array(
				'label' => __( 'Control', 'agend-elementor' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'button_background',
			array(
				'label'     => __( 'Button background', 'agend-elementor' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .agend-export__submit' => 'background-color: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'button_colour',
			array(
				'label'     => __( 'Button text', 'agend-elementor' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .agend-export__submit' => 'color: {{VALUE}};' ),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'button_typography',
				'selector' => '{{WRAPPER}} .agend-export__submit',
			)
		);

		$this->add_responsive_control(
			'button_padding',
			array(
				'label'      => __( 'Button padding', 'agend-elementor' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array( '{{WRAPPER}} .agend-export__submit' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'button_radius',
			array(
				'label'      => __( 'Button radius', 'agend-elementor' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array( '{{WRAPPER}} .agend-export__submit' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);

		$this->add_control(
			'menu_background',
			array(
				'label'     => __( 'Menu background', 'agend-elementor' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .agend-export__menu' => 'background-color: {{VALUE}};' ),
				'condition' => array( 'mode' => 'dropdown' ),
			)
		);

		$this->add_control(
			'menu_item_colour',
			array(
				'label'     => __( 'Menu item text', 'agend-elementor' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .agend-export__item' => 'color: {{VALUE}};' ),
				'condition' => array( 'mode' => 'dropdown' ),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'      => 'menu_item_typography',
				'selector'  => '{{WRAPPER}} .agend-export__item',
				'condition' => array( 'mode' => 'dropdown' ),
			)
		);

		$this->add_control(
			'full_width',
			array(
				'label'   => __( 'Full width', 'agend-elementor' ),
				'type'    => \Elementor\Controls_Manager::SWITCHER,
				'default' => '',
			)
		);

		$this->end_controls_section();
	}

	protected function render(): void {
		$s       = $this->get_settings_for_display();
		$mode    = 'dropdown' === ( $s['mode'] ?? 'button' ) ? 'dropdown' : 'button';
		$reports = agend_apps_records_export_reports_offered_reports( $s );

		if ( empty( $reports ) ) {
			$this->render_editor_notice(
				'dropdown' === $mode
					? __( 'Add the reports this menu should offer.', 'agend-elementor' )
					: __( 'Choose the report this button downloads.', 'agend-elementor' )
			);
			return;
		}

		echo agend_apps_records_render_export_reports( $s, array( 'id' => $this->get_id() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the core renderer.
	}
}
