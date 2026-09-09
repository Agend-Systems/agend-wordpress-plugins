<?php
/**
 * Elementor "Agend Link / Button" widget: the actions a card or detail page
 * offers for the current record.
 *
 * @package Agend_Elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The `register` action emits the same `data-agend-event-slug` button the
 * built-in detail uses, so the existing registration flow in
 * assets/js/events-catalogue.js hydrates it without changes.
 */
class Agend_Elementor_Record_Link extends \Elementor\Widget_Base {

	use Agend_Elementor_Field_Widget_Trait;

	public function get_name(): string {
		return 'agend-record-link';
	}

	public function get_title(): string {
		return __( 'Agend Link / Button', 'agend-elementor' );
	}

	public function get_icon(): string {
		return 'eicon-button';
	}

	public function get_categories(): array {
		return array( Agend_Elementor::CATEGORY );
	}

	public function get_keywords(): array {
		return array( 'agend', 'button', 'link', 'register', 'enrol', 'detail' );
	}

	public function get_style_depends(): array {
		return array( 'agend-elementor-record-fields' );
	}

	protected function register_controls(): void {
		Agend_Elementor_Schema_Controls::register( $this, agend_apps_records_surface_schema( 'record-link' ) );

		$this->start_controls_section(
			'section_style',
			array(
				'label' => __( 'Button', 'agend-elementor' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'typography',
				'selector' => '{{WRAPPER}} .agend-record-link',
			)
		);

		$this->start_controls_tabs( 'tabs_button' );

		$this->start_controls_tab( 'tab_normal', array( 'label' => __( 'Normal', 'agend-elementor' ) ) );
		$this->add_control(
			'text_colour',
			array(
				'label'     => __( 'Text colour', 'agend-elementor' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .agend-record-link' => 'color: {{VALUE}};' ),
			)
		);
		$this->add_control(
			'background_colour',
			array(
				'label'     => __( 'Background colour', 'agend-elementor' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .agend-record-link--button' => 'background-color: {{VALUE}};' ),
			)
		);
		$this->end_controls_tab();

		$this->start_controls_tab( 'tab_hover', array( 'label' => __( 'Hover', 'agend-elementor' ) ) );
		$this->add_control(
			'text_colour_hover',
			array(
				'label'     => __( 'Text colour', 'agend-elementor' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .agend-record-link:hover, {{WRAPPER}} .agend-record-link:focus' => 'color: {{VALUE}};' ),
			)
		);
		$this->add_control(
			'background_colour_hover',
			array(
				'label'     => __( 'Background colour', 'agend-elementor' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .agend-record-link--button:hover, {{WRAPPER}} .agend-record-link--button:focus' => 'background-color: {{VALUE}};' ),
			)
		);
		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			array(
				'name'      => 'border',
				'selector'  => '{{WRAPPER}} .agend-record-link--button',
				'separator' => 'before',
			)
		);

		$this->add_responsive_control(
			'border_radius',
			array(
				'label'      => __( 'Border radius', 'agend-elementor' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array( '{{WRAPPER}} .agend-record-link--button' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'padding',
			array(
				'label'      => __( 'Padding', 'agend-elementor' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array( '{{WRAPPER}} .agend-record-link--button' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'box_shadow',
				'selector' => '{{WRAPPER}} .agend-record-link--button',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * The record's detail URL from context, falling back to the page resolver.
	 *
	 * @param array $ctx Resolved context.
	 * @return string
	 */
	private function detail_url( array $ctx ): string {
		return (string) ( agend_apps_records_field_value( 'common:detail_url', $ctx['type'], $ctx['record'], $ctx['extra'] ) ?? '' );
	}

	/**
	 * The catalogue (dedicated or host) page URL.
	 *
	 * @param array $ctx Resolved context.
	 * @return string
	 */
	private function catalogue_url( array $ctx ): string {
		$url = Agend_Apps_Records_Pages::page_url( $ctx['type'] );
		if ( '' === $url && ! empty( $ctx['extra']['host_page_id'] ) ) {
			$permalink = get_permalink( (int) $ctx['extra']['host_page_id'] );
			$url       = is_string( $permalink ) ? $permalink : '';
		}
		return $url;
	}

	/**
	 * Outputs an anchor with the shared classes.
	 *
	 * @param string $href    Destination.
	 * @param string $label   Visible label.
	 * @param array  $s       Widget settings.
	 * @param array  $ctx     Resolved context.
	 * @param bool   $new_tab Whether to open in a new tab.
	 */
	private function output_anchor( string $href, string $label, array $s, array $ctx, bool $new_tab = false ): void {
		$classes = $this->classes( $s, 'action-' . (string) ( $s['action'] ?? '' ) );

		// Inside a card that is one big link a nested anchor is invalid HTML;
		// render the label as a span and let the card link do the navigating.
		if ( ! empty( $ctx['extra']['in_card_link'] ) ) {
			echo '<span class="' . esc_attr( $classes ) . '">' . esc_html( $label ) . '</span>';
			return;
		}

		$attrs = ' class="' . esc_attr( $classes ) . '" href="' . esc_url( $href ) . '"';
		if ( $new_tab ) {
			$attrs .= ' target="_blank" rel="noopener"';
		}
		echo '<a' . $attrs . '>' . esc_html( $label ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
	}

	/**
	 * Class list for the rendered element.
	 *
	 * @param array  $s     Widget settings.
	 * @param string $extra Additional modifier.
	 * @return string
	 */
	private function classes( array $s, string $extra = '' ): string {
		$classes = array( 'agend-record-link' );
		$classes[] = 'link' === ( $s['style_as'] ?? 'button' ) ? 'agend-record-link--text' : 'agend-record-link--button';
		if ( 'yes' === ( $s['full_width'] ?? '' ) ) {
			$classes[] = 'agend-record-link--full';
		}
		if ( '' !== $extra ) {
			$classes[] = 'agend-record-link--' . $extra;
		}
		return implode( ' ', $classes );
	}

	protected function render(): void {
		$s   = $this->get_settings_for_display();
		$ctx = $this->resolve_context();

		if ( $ctx['mismatch'] ) {
			$this->render_mismatch_notice( (string) $s['record_type'], $ctx['type'] );
			return;
		}
		if ( '' === $ctx['type'] ) {
			return;
		}

		$record = $ctx['record'];
		$slug   = isset( $record['slug'] ) ? (string) $record['slug'] : (string) ( $ctx['extra']['slug'] ?? '' );
		$title  = (string) ( agend_apps_records_field_value( 'common:title', $ctx['type'], $record, $ctx['extra'] ) ?? '' );
		$text   = trim( (string) ( $s['text'] ?? '' ) );
		$action = (string) ( $s['action'] ?? 'detail' );

		switch ( $action ) {
			case 'detail':
				$href = $this->detail_url( $ctx );
				if ( '' === $href ) {
					return;
				}
				$this->output_anchor( $href, '' !== $text ? $text : __( 'View details', 'agend-elementor' ), $s, $ctx );
				return;

			case 'catalogue':
				$href = $this->catalogue_url( $ctx );
				if ( '' === $href ) {
					return;
				}
				$labels = array(
					'course'  => __( 'Back to Courses', 'agend-elementor' ),
					'listing' => __( 'Back to Directory', 'agend-elementor' ),
				);
				$label  = '' !== $text ? $text : ( $labels[ $ctx['type'] ] ?? __( 'Back to Events', 'agend-elementor' ) );
				$this->output_anchor( $href, $label, $s, $ctx );
				return;

			case 'ical':
				if ( 'event' !== $ctx['type'] || '' === $slug ) {
					$this->render_editor_notice( __( 'Add to calendar is only available for events.', 'agend-elementor' ) );
					return;
				}
				$href = rest_url( 'agend-apps/v1/events/' . rawurlencode( $slug ) . '/ical' );
				$this->output_anchor( $href, '' !== $text ? $text : __( 'Add to Calendar', 'agend-elementor' ), $s, $ctx, 'yes' === ( $s['new_tab'] ?? '' ) );
				return;

			case 'custom':
				$template = (string) ( $s['custom_url'] ?? '' );
				$href     = str_replace( array( '{slug}', '{title}' ), array( rawurlencode( $slug ), rawurlencode( $title ) ), $template );
				if ( '' === trim( $href ) ) {
					return;
				}
				$this->output_anchor( $href, '' !== $text ? $text : $title, $s, $ctx, 'yes' === ( $s['new_tab'] ?? '' ) );
				return;

			case 'enrol':
				if ( 'course' !== $ctx['type'] ) {
					$this->render_editor_notice( __( 'Enrol is only available for courses.', 'agend-elementor' ) );
					return;
				}
				if ( 'yes' === ( $s['hide_when_enrolled'] ?? 'yes' ) && ! empty( $record['my_enrollment'] ) ) {
					return;
				}
				// Mirrors the built-in detail: enrolment starts from a member
				// sign-in that returns to this course.
				$href = wp_login_url( $this->detail_url( $ctx ) );
				$this->output_anchor( $href, '' !== $text ? $text : __( 'Enrol Now', 'agend-elementor' ), $s, $ctx );
				return;

			case 'register':
				if ( 'event' !== $ctx['type'] || '' === $slug ) {
					$this->render_editor_notice( __( 'Register is only available for events.', 'agend-elementor' ) );
					return;
				}
				$registered = ! empty( $record['my_registration'] );
				if ( $registered && 'yes' === ( $s['hide_when_registered'] ?? '' ) ) {
					return;
				}
				$sold_out = ! empty( $record['sold_out'] );
				$disabled = $sold_out && 'yes' === ( $s['disabled_when_sold_out'] ?? 'yes' );
				if ( '' === $text ) {
					$text = $sold_out
						? __( 'Sold Out', 'agend-elementor' )
						: ( $registered ? __( 'Register Another Attendee', 'agend-elementor' ) : __( 'Register Now', 'agend-elementor' ) );
				}
				// Never inside a card link: the button's own click must win, so
				// the card wrapper skips clicks that land on [data-agend-event-slug].
				echo '<button type="button" class="' . esc_attr( $this->classes( $s, 'action-register' ) ) . '" data-agend-event-slug="' . esc_attr( $slug ) . '"' . ( $disabled ? ' disabled' : '' ) . '>' . esc_html( $text ) . '</button>';
				return;
		}
	}
}
