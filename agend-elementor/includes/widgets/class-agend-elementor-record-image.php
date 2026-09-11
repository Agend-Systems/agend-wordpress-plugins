<?php
/**
 * Elementor "Agend Image" widget: the current record's image, as an image
 * element or as a background.
 *
 * @package Agend_Elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Record images are remote gateway URLs, not media-library attachments, so
 * WordPress image sizes do not apply; sizing is done with aspect ratio and
 * object-fit instead.
 *
 * Background mode has three placements because free Elementor's container
 * background control cannot take a per-record URL: `fill` stretches behind
 * the sibling widgets of the container it sits in, `parent` paints the URL
 * onto the parent container itself (via assets/js/record-fields.js), and
 * `block` is an ordinary sized box.
 */
class Agend_Elementor_Record_Image extends \Elementor\Widget_Base {

	use Agend_Elementor_Field_Widget_Trait;

	public function get_name(): string {
		return 'agend-record-image';
	}

	public function get_title(): string {
		return __( 'Agend Image', 'agend-elementor' );
	}

	public function get_icon(): string {
		return 'eicon-image';
	}

	public function get_categories(): array {
		return array( Agend_Elementor::CATEGORY );
	}

	public function get_keywords(): array {
		return array( 'agend', 'image', 'background', 'hero', 'event', 'course' );
	}

	public function get_style_depends(): array {
		return array( 'agend-apps-records-record-fields' );
	}

	public function get_script_depends(): array {
		return array( 'agend-apps-records-record-fields' );
	}

	/**
	 * Registers a Content-tab control this widget declares itself because the
	 * shared schema vocabulary cannot describe it (a COLOR picker, a
	 * responsive SLIDER, or a SELECT that needs `selectors`).
	 *
	 * @param string $name Schema field name.
	 * @return void
	 */
	public function register_adapter_control( string $name ): void {
		switch ( $name ) {
			case 'aspect_ratio':
				$this->add_control(
					'aspect_ratio',
					array(
						'label'     => __( 'Aspect ratio', 'agend-elementor' ),
						'type'      => \Elementor\Controls_Manager::SELECT,
						'default'   => '16 / 9',
						'options'   => array(
							''       => __( 'Original', 'agend-elementor' ),
							'1 / 1'  => '1:1',
							'4 / 3'  => '4:3',
							'3 / 2'  => '3:2',
							'16 / 9' => '16:9',
							'21 / 9' => '21:9',
						),
						'selectors' => array( '{{WRAPPER}} .agend-record-image--img' => 'aspect-ratio: {{VALUE}};' ),
						'condition' => array( 'mode' => 'img' ),
					)
				);
				break;

			case 'object_fit':
				$this->add_control(
					'object_fit',
					array(
						'label'     => __( 'Object fit', 'agend-elementor' ),
						'type'      => \Elementor\Controls_Manager::SELECT,
						'default'   => 'cover',
						'options'   => array(
							'cover'   => __( 'Cover', 'agend-elementor' ),
							'contain' => __( 'Contain', 'agend-elementor' ),
							'fill'    => __( 'Fill', 'agend-elementor' ),
							'none'    => __( 'None', 'agend-elementor' ),
						),
						'selectors' => array( '{{WRAPPER}} .agend-record-image--img' => 'object-fit: {{VALUE}};' ),
						'condition' => array( 'mode' => 'img' ),
					)
				);
				break;

			case 'min_height':
				$this->add_responsive_control(
					'min_height',
					array(
						'label'      => __( 'Minimum height', 'agend-elementor' ),
						'type'       => \Elementor\Controls_Manager::SLIDER,
						'size_units' => array( 'px', 'vh', 'em' ),
						'range'      => array( 'px' => array( 'min' => 0, 'max' => 1000 ) ),
						'default'    => array( 'size' => 240, 'unit' => 'px' ),
						'selectors'  => array( '{{WRAPPER}} .agend-record-image--block' => 'min-height: {{SIZE}}{{UNIT}};' ),
						'condition'  => array( 'mode' => 'background', 'placement' => 'block' ),
					)
				);
				break;

			case 'overlay_colour':
				$this->add_control(
					'overlay_colour',
					array(
						'label'       => __( 'Overlay colour', 'agend-elementor' ),
						'type'        => \Elementor\Controls_Manager::COLOR,
						'default'     => '',
						'description' => __( 'A translucent colour layered over the image, for legible text.', 'agend-elementor' ),
						'condition'   => array( 'mode' => 'background' ),
					)
				);
				break;
		}
	}

	protected function register_controls(): void {
		Agend_Elementor_Schema_Controls::register( $this, agend_apps_records_surface_schema( 'record-image' ) );
	}

	/**
	 * The 'preview'/'preview_type' opts this widget's record-context reads
	 * always need, built the same way
	 * Agend_Elementor_Field_Widget_Trait::resolve_context() builds them
	 * internally. The core render/reason functions take these opts directly
	 * rather than a resolved context (a block passes its own), so this widget
	 * has to construct them itself.
	 *
	 * @return array{preview: bool, preview_type: string}
	 */
	private function render_opts(): array {
		$is_editor = $this->is_editor();

		return array(
			'preview'      => $is_editor,
			'preview_type' => $is_editor ? $this->preview_type() : '',
		);
	}

	protected function render(): void {
		$s    = $this->get_settings_for_display();
		$opts = $this->render_opts();

		if ( 'no_image' === agend_apps_records_record_image_render_reason( $s, $opts ) ) {
			$this->render_editor_notice( __( 'This record has no image and no fallback image is set.', 'agend-elementor' ) );
			return;
		}

		$html = agend_apps_records_render_record_image( $s, $opts );
		if ( '' === $html ) {
			// No record context in scope (live, outside a template, not a
			// preview): the same silent "renders nothing" the widget always had
			// for this case, with nothing more specific to tell an author.
			return;
		}

		if ( 'background' === (string) ( $s['mode'] ?? 'img' ) && 'fill' === agend_apps_records_record_image_placement( $s ) ) {
			// The fill placement positions the inner box against the parent
			// container, so this widget's own wrapper must not be the nearest
			// positioned ancestor. This is an Elementor-only concern (the core
			// renderer returns only the surface's own inner markup, never the
			// widget's wrapper), so it stays here rather than moving with the
			// rest of the render logic.
			$this->add_render_attribute( '_wrapper', 'class', 'agend-record-image-host--fill' );
		}

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the core renderer.
	}
}
