<?php
/**
 * Content-settings schema for the Agend Image surface.
 *
 * Transcribed from the Agend Image widget's register_controls().
 *
 * Deviation from the F2 inventory: `aspect_ratio` and `object_fit` are plain
 * SELECT controls in the original widget, but both carry a `selectors` key
 * (they drive CSS directly from a Content-tab value), which the shared
 * vocabulary's `select` type does not pass through. Declaring them as
 * `select` would silently drop `selectors` and break the fidelity fixture, so
 * they are `adapter` fields alongside the MEDIA, COLOR and responsive SLIDER
 * controls the inventory already named.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Agend Image widget's content-settings schema.
 *
 * @return array
 */
function agend_apps_records_schema_record_image(): array {
	return array(
		'sections' => array(
			array(
				'id'     => 'section_image',
				'label'  => __( 'Image', 'agend-apps-core' ),
				'fields' => array(
					agend_apps_records_schema_record_type_field(),
					array(
						'name'        => 'field',
						'label'       => __( 'Image field', 'agend-apps-core' ),
						'type'        => 'select',
						'default'     => 'common:image',
						'groups'      => 'agend_apps_records_record_image_field_options',
						'label_block' => true,
					),
					array(
						'name'        => 'fallback_image',
						'label'       => __( 'Fallback image', 'agend-apps-core' ),
						'type'        => 'adapter',
						'description' => __( 'Used when the record has no image.', 'agend-apps-core' ),
					),
					array(
						'name'    => 'mode',
						'label'   => __( 'Render as', 'agend-apps-core' ),
						'type'    => 'select',
						'default' => 'img',
						'options' => array(
							'img'        => __( 'Image', 'agend-apps-core' ),
							'background' => __( 'Background', 'agend-apps-core' ),
						),
					),
					array(
						'name'        => 'link_to_detail',
						'label'       => __( 'Link to detail page', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => false,
						'description' => __( 'Ignored when the whole card is already a link.', 'agend-apps-core' ),
						'condition'   => array( 'mode' => 'img' ),
					),
					array(
						'name'        => 'aspect_ratio',
						'label'       => __( 'Aspect ratio', 'agend-apps-core' ),
						'type'        => 'adapter',
						'description' => __( 'Selectable aspect ratios for the image, in "img" mode.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'object_fit',
						'label'       => __( 'Object fit', 'agend-apps-core' ),
						'type'        => 'adapter',
						'description' => __( 'How the image fills its box, in "img" mode.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'placement',
						'label'       => __( 'Placement', 'agend-apps-core' ),
						'type'        => 'select',
						'default'     => 'fill',
						'options'     => array(
							'fill'   => __( 'Fill the container behind other widgets', 'agend-apps-core' ),
							'parent' => __( 'Paint onto the parent container', 'agend-apps-core' ),
							'block'  => __( 'Sized block', 'agend-apps-core' ),
						),
						'description' => __( 'Fill: drop this widget into a container as its first child and it becomes that container\'s background. Parent: the image is applied to the parent container\'s own background. Block: a box of the height set below.', 'agend-apps-core' ),
						'condition'   => array( 'mode' => 'background' ),
					),
					array(
						'name'        => 'min_height',
						'label'       => __( 'Minimum height', 'agend-apps-core' ),
						'type'        => 'adapter',
						'description' => __( 'Minimum height of the sized block, in "background" + "block" placement.', 'agend-apps-core' ),
					),
					array(
						'name'      => 'background_size',
						'label'     => __( 'Background size', 'agend-apps-core' ),
						'type'      => 'select',
						'default'   => 'cover',
						'options'   => array(
							'cover'   => __( 'Cover', 'agend-apps-core' ),
							'contain' => __( 'Contain', 'agend-apps-core' ),
							'auto'    => __( 'Auto', 'agend-apps-core' ),
						),
						'condition' => array( 'mode' => 'background' ),
					),
					array(
						'name'      => 'background_position',
						'label'     => __( 'Background position', 'agend-apps-core' ),
						'type'      => 'select',
						'default'   => 'center center',
						'options'   => array(
							'center center' => __( 'Centre', 'agend-apps-core' ),
							'center top'    => __( 'Top', 'agend-apps-core' ),
							'center bottom' => __( 'Bottom', 'agend-apps-core' ),
							'left center'   => __( 'Left', 'agend-apps-core' ),
							'right center'  => __( 'Right', 'agend-apps-core' ),
						),
						'condition' => array( 'mode' => 'background' ),
					),
					array(
						'name'        => 'overlay_colour',
						'label'       => __( 'Overlay colour', 'agend-apps-core' ),
						'type'        => 'adapter',
						'description' => __( 'A translucent colour layered over the image, for legible text.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'overlay_gradient',
						'label'       => __( 'Darken towards the bottom', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => false,
						'description' => __( 'Adds the same top-to-bottom darkening the built-in detail hero uses.', 'agend-apps-core' ),
						'condition'   => array( 'mode' => 'background' ),
					),
				),
			),
		),
	);
}

/**
 * Image field options for the Agend Image widget.
 *
 * Moved from the widget's inline `agend_apps_records_field_options( array( 'url' ) )`
 * call so the callable options shape stays a zero-argument callable string.
 *
 * @return array
 */
function agend_apps_records_record_image_field_options(): array {
	return agend_apps_records_field_options( array( 'url' ) );
}
