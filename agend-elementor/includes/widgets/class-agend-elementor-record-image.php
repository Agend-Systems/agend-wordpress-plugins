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
		return array( 'agend-elementor-record-fields' );
	}

	public function get_script_depends(): array {
		return array( 'agend-elementor-record-fields' );
	}

	/**
	 * Registers a Content-tab control this widget declares itself because the
	 * shared schema vocabulary cannot describe it (a MEDIA picker, a COLOR
	 * picker, a responsive SLIDER, or a SELECT that needs `selectors`).
	 *
	 * @param string $name Schema field name.
	 * @return void
	 */
	public function register_adapter_control( string $name ): void {
		switch ( $name ) {
			case 'fallback_image':
				$this->add_control(
					'fallback_image',
					array(
						'label'       => __( 'Fallback image', 'agend-elementor' ),
						'type'        => \Elementor\Controls_Manager::MEDIA,
						'description' => __( 'Used when the record has no image.', 'agend-elementor' ),
					)
				);
				break;

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
	 * The image URL for the current record, or the fallback, or ''.
	 *
	 * @param array $s   Widget settings.
	 * @param array $ctx Resolved context.
	 * @return string
	 */
	private function image_url( array $s, array $ctx ): string {
		$key = (string) ( $s['field'] ?? 'common:image' );
		$url = agend_apps_records_field_value( $key, $ctx['type'], $ctx['record'], $ctx['extra'] );
		$url = is_string( $url ) ? $url : '';
		if ( '' === $url && ! empty( $s['fallback_image']['url'] ) ) {
			$url = (string) $s['fallback_image']['url'];
		}
		return $url;
	}

	/**
	 * The CSS `background-image` value including any overlay layers.
	 *
	 * @param string $url Image URL.
	 * @param array  $s   Widget settings.
	 * @return string
	 */
	private function background_image_value( string $url, array $s ): string {
		$layers = array();
		if ( 'yes' === ( $s['overlay_gradient'] ?? '' ) ) {
			$layers[] = 'linear-gradient(180deg, rgba(30,42,74,0.35), rgba(30,42,74,0.85))';
		}
		$overlay = trim( (string) ( $s['overlay_colour'] ?? '' ) );
		if ( '' !== $overlay ) {
			$layers[] = 'linear-gradient(' . $overlay . ', ' . $overlay . ')';
		}
		$layers[] = 'url(\'' . esc_url( $url ) . '\')';
		return implode( ', ', $layers );
	}

	protected function render(): void {
		$s   = $this->get_settings_for_display();
		$ctx = $this->resolve_context();

		if ( '' === $ctx['type'] ) {
			return;
		}

		$url = $this->image_url( $s, $ctx );
		if ( '' === $url ) {
			$this->render_editor_notice( __( 'This record has no image and no fallback image is set.', 'agend-elementor' ) );
			return;
		}

		$title = (string) ( agend_apps_records_field_value( 'common:title', $ctx['type'], $ctx['record'], $ctx['extra'] ) ?? '' );

		if ( 'background' === ( $s['mode'] ?? 'img' ) ) {
			$placement = (string) ( $s['placement'] ?? 'fill' );
			if ( ! in_array( $placement, array( 'fill', 'parent', 'block' ), true ) ) {
				$placement = 'fill';
			}
			// The fill placement positions the inner box against the parent
			// container, so this widget's own wrapper must not be the nearest
			// positioned ancestor.
			if ( 'fill' === $placement ) {
				$this->add_render_attribute( '_wrapper', 'class', 'agend-record-image-host--fill' );
			}

			$style = sprintf(
				'background-image:%s;background-size:%s;background-position:%s;',
				$this->background_image_value( $url, $s ),
				esc_attr( (string) ( $s['background_size'] ?? 'cover' ) ),
				esc_attr( (string) ( $s['background_position'] ?? 'center center' ) )
			);

			$this->add_render_attribute(
				'image',
				array(
					'class'               => array( 'agend-record-image', 'agend-record-image--bg', 'agend-record-image--' . $placement ),
					'style'               => $style,
					'role'                => 'img',
					'aria-label'          => $title,
					'data-agend-bg-url'   => esc_url( $url ),
					'data-agend-bg-style' => $style,
				)
			);
			if ( 'parent' === $placement ) {
				$this->add_render_attribute( 'image', 'data-agend-bg-target', 'parent' );
			}
			echo '<div ' . $this->get_render_attribute_string( 'image' ) . '></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes escaped by Elementor.
			return;
		}

		$this->add_render_attribute(
			'image',
			array(
				'class'   => array( 'agend-record-image', 'agend-record-image--img' ),
				'src'     => esc_url( $url ),
				'alt'     => $title,
				'loading' => 'lazy',
			)
		);
		$img = '<img ' . $this->get_render_attribute_string( 'image' ) . ' />';

		$link = ( 'yes' === ( $s['link_to_detail'] ?? '' ) && empty( $ctx['extra']['in_card_link'] ) )
			? (string) ( agend_apps_records_field_value( 'common:detail_url', $ctx['type'], $ctx['record'], $ctx['extra'] ) ?? '' )
			: '';
		if ( '' !== $link && '#' !== $link ) {
			$img = '<a class="agend-record-image__link" href="' . esc_url( $link ) . '">' . $img . '</a>';
		}

		echo $img; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes escaped above.
	}
}
