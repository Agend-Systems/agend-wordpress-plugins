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
	 * shared schema vocabulary cannot describe it (two REPEATER controls, and
	 * a notice conditionally registered on live account data rather than on
	 * another field's value).
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

			case 'reports':
				$reports = agend_apps_records_export_reports_report_options();
				$chosen  = new \Elementor\Repeater();
				$chosen->add_control(
					'report_id',
					array(
						'label'       => __( 'Report', 'agend-elementor' ),
						'type'        => \Elementor\Controls_Manager::SELECT,
						'default'     => '',
						'options'     => $reports,
						'label_block' => true,
					)
				);
				$chosen->add_control(
					'report_label',
					array(
						'label'       => __( 'Label', 'agend-elementor' ),
						'type'        => \Elementor\Controls_Manager::TEXT,
						'default'     => '',
						'description' => __( 'Leave empty to use the report\'s own name.', 'agend-elementor' ),
					)
				);
				$this->add_control(
					'reports',
					array(
						'label'       => __( 'Reports in the menu', 'agend-elementor' ),
						'type'        => \Elementor\Controls_Manager::REPEATER,
						'fields'      => $chosen->get_controls(),
						'title_field' => '{{{ report_label }}}',
						'default'     => array(),
						'condition'   => array( 'mode' => 'dropdown' ),
					)
				);
				break;

			case 'reports_unavailable':
				// The report list has only the "Select a report" placeholder
				// when the account has none; count() > 1 is the "has real
				// reports" test both call sites use.
				if ( count( agend_apps_records_export_reports_report_options() ) <= 1 ) {
					$this->add_control(
						'reports_unavailable',
						array(
							'type'            => \Elementor\Controls_Manager::RAW_HTML,
							'raw'             => esc_html__( 'No export reports were returned for this account. Check that the API key holds the directory.export_reports.browse scope and that at least one report is published.', 'agend-elementor' ),
							'content_classes' => 'elementor-panel-alert elementor-panel-alert-warning',
						)
					);
				}
				break;

			case 'parameters':
				$parameters = new \Elementor\Repeater();
				$parameters->add_control(
					'param_field',
					array(
						'label'       => __( 'Parameter field', 'agend-elementor' ),
						'type'        => \Elementor\Controls_Manager::TEXT,
						'default'     => '',
						'placeholder' => 'keyword',
						'description' => __( 'The field the report parameter filters on.', 'agend-elementor' ),
					)
				);
				$parameters->add_control(
					'param_source',
					array(
						'label'   => __( 'Value from', 'agend-elementor' ),
						'type'    => \Elementor\Controls_Manager::SELECT,
						'default' => 'manual',
						'options' => array(
							'manual'    => __( 'A value I set here', 'agend-elementor' ),
							'catalogue' => __( 'The Directory Catalogue on this page', 'agend-elementor' ),
						),
					)
				);
				$parameters->add_control(
					'param_value',
					array(
						'label'     => __( 'Value', 'agend-elementor' ),
						'type'      => \Elementor\Controls_Manager::TEXT,
						'default'   => '',
						'condition' => array( 'param_source' => 'manual' ),
					)
				);
				$parameters->add_control(
					'param_catalogue_filter',
					array(
						'label'       => __( 'Read from filter', 'agend-elementor' ),
						'type'        => \Elementor\Controls_Manager::SELECT,
						'default'     => '',
						'options'     => agend_apps_records_export_reports_catalogue_source_options(),
						'description' => __( 'Takes whatever the visitor has this filter set to when they press the button.', 'agend-elementor' ),
						'condition'   => array( 'param_source' => 'catalogue' ),
					)
				);
				$parameters->add_control(
					'param_custom_key',
					array(
						'label'       => __( 'Custom field key', 'agend-elementor' ),
						'type'        => \Elementor\Controls_Manager::TEXT,
						'default'     => '',
						'condition'   => array( 'param_source' => 'catalogue', 'param_catalogue_filter' => 'custom_field' ),
						'description' => __( 'Which custom field the catalogue filter targets.', 'agend-elementor' ),
					)
				);
				$this->add_control(
					'parameters',
					array(
						'label'       => __( 'Parameter mapping', 'agend-elementor' ),
						'type'        => \Elementor\Controls_Manager::REPEATER,
						'fields'      => $parameters->get_controls(),
						'title_field' => '{{{ param_field }}}',
						'default'     => array(),
					)
				);
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

	/**
	 * The parameter mapping rows, normalised for the script.
	 *
	 * @param array $s Widget settings.
	 * @return array<int, array<string, string>>
	 */
	private function parameter_map( array $s ): array {
		$rows = array();
		foreach ( (array) ( $s['parameters'] ?? array() ) as $row ) {
			$field = trim( (string) ( $row['param_field'] ?? '' ) );
			if ( '' === $field ) {
				continue;
			}
			$rows[] = array(
				'field'      => $field,
				'source'     => 'catalogue' === ( $row['param_source'] ?? 'manual' ) ? 'catalogue' : 'manual',
				'value'      => (string) ( $row['param_value'] ?? '' ),
				'filter'     => (string) ( $row['param_catalogue_filter'] ?? '' ),
				'customKey'  => trim( (string) ( $row['param_custom_key'] ?? '' ) ),
			);
		}
		return $rows;
	}

	/**
	 * The reports this instance offers.
	 *
	 * @param array $s Widget settings.
	 * @return array<int, array<string, string>>
	 */
	private function offered_reports( array $s ): array {
		if ( 'dropdown' !== ( $s['mode'] ?? 'button' ) ) {
			$id = (string) ( $s['report'] ?? '' );
			return '' === $id ? array() : array( array( 'id' => $id, 'label' => '' ) );
		}

		$reports = array();
		foreach ( (array) ( $s['reports'] ?? array() ) as $row ) {
			$id = (string) ( $row['report_id'] ?? '' );
			if ( '' === $id ) {
				continue;
			}
			$reports[] = array( 'id' => $id, 'label' => trim( (string) ( $row['report_label'] ?? '' ) ) );
		}
		return $reports;
	}

	protected function render(): void {
		$s       = $this->get_settings_for_display();
		$mode    = 'dropdown' === ( $s['mode'] ?? 'button' ) ? 'dropdown' : 'button';
		$reports = $this->offered_reports( $s );

		if ( empty( $reports ) ) {
			$this->render_editor_notice(
				'dropdown' === $mode
					? __( 'Add the reports this menu should offer.', 'agend-elementor' )
					: __( 'Choose the report this button downloads.', 'agend-elementor' )
			);
			return;
		}

		$config = array(
			'restBase'   => esc_url_raw( rest_url( 'agend-apps/v1' ) ),
			'mode'       => $mode,
			'reports'    => $reports,
			'format'     => 'xlsx' === ( $s['format'] ?? 'csv' ) ? 'xlsx' : 'csv',
			'parameters' => $this->parameter_map( $s ),
			'labels'     => array(
				'working' => __( 'Preparing…', 'agend-elementor' ),
				'failed'  => __( 'That report could not be produced. Try again shortly.', 'agend-elementor' ),
			),
		);

		$classes = 'agend-export-report agend-export-report--' . $mode;
		if ( 'yes' === ( $s['full_width'] ?? '' ) ) {
			$classes .= ' agend-export-report--full';
		}

		$trigger_text = (string) ( $s['button_text'] ?? __( 'Export', 'agend-elementor' ) );

		echo '<div class="' . esc_attr( $classes ) . '" data-agend-export-config="' . esc_attr( (string) wp_json_encode( $config ) ) . '">';

		if ( 'button' === $mode ) {
			echo '<button type="button" class="agend-export__submit" data-agend-export-submit data-report-id="' . esc_attr( $reports[0]['id'] ) . '">' . esc_html( $trigger_text ) . '</button>';
			echo '</div>';
			return;
		}

		$menu_id = 'agend-export-menu-' . esc_attr( $this->get_id() );

		echo '<button type="button" class="agend-export__submit agend-export__trigger" data-agend-export-trigger aria-haspopup="true" aria-expanded="false" aria-controls="' . $menu_id . '">';
		echo esc_html( $trigger_text );
		echo '<span class="agend-export__caret" aria-hidden="true"></span>';
		echo '</button>';

		// Choosing a report IS the action, so each entry is a button rather
		// than a value to be confirmed with a second click.
		echo '<ul class="agend-export__menu" id="' . $menu_id . '" data-agend-export-menu hidden>';
		foreach ( $reports as $report ) {
			echo '<li class="agend-export__menu-item">';
			echo '<button type="button" class="agend-export__item" data-agend-export-submit data-report-id="' . esc_attr( $report['id'] ) . '">';
			// A blank label is filled in with the report's current name at view
			// time, so a rename in Agend does not go stale in a saved template.
			echo esc_html( '' !== $report['label'] ? $report['label'] : $report['id'] );
			echo '</button></li>';
		}
		echo '</ul>';
		echo '</div>';
	}
}
