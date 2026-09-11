<?php
/**
 * Front-end output of the record image block: the core renderer, fed the
 * block's attributes converted to Elementor-shaped settings, with the
 * `inline_style` opt turned on.
 *
 * agend_apps_records_render_block() forwards only $attributes, with no way to
 * say "apply the four adapter settings as an inline style" -- a block has no
 * Elementor stylesheet to carry aspect_ratio/object_fit/min_height, unlike the
 * Elementor widget, which renders them through CSS selectors it generates
 * itself and so never needs this opt (see the `inline_style` docblock on
 * agend_apps_records_render_record_image()). That is the one thing this
 * render.php needs beyond what the shared helper gives every other block,
 * the same reason export-reports/render.php calls its surface renderer
 * directly rather than widening agend_apps_records_render_block()'s
 * signature.
 *
 * min_height's block control is a plain pixel number (see the `block`
 * declaration on that field in schema/record-image.php): the core renderer
 * is untouched here and still expects Elementor's own
 * `array( 'size' => ..., 'unit' => ... )` shape, so that reshaping happens
 * once, here, rather than in the renderer.
 *
 * @var array $attributes
 * @package Agend_Apps_Core
 */

$schema   = agend_apps_records_surface_schema( 'record-image' );
$settings = agend_apps_records_settings_from_attributes( $schema, $attributes );

if ( isset( $attributes['min_height'] ) && is_numeric( $attributes['min_height'] ) ) {
	$settings['min_height'] = array(
		'size' => $attributes['min_height'],
		'unit' => 'px',
	);
}

echo agend_apps_records_render_record_image( $settings, array( 'inline_style' => true ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the core renderer.
