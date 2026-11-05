<?php
/**
 * Front-end output of the record link block: the core renderer, fed the
 * block's attributes.
 *
 * Renders nothing outside a card or detail template
 * (Agend_Apps_Records_Record_Context carries no frame there), or for one of
 * the three actions that need a record type they were not given
 * (agend_apps_records_record_link_render_reason() names those). Every other
 * "renders nothing" case (no URL to link to, an already enrolled or
 * registered record hidden by its own toggle, a blank custom URL) has never
 * shown an editor notice either, so a live page has nothing more to say. The
 * block's own editor view surfaces the three named reasons instead, from the
 * dedicated preview route (see shared/surface-preview.js).
 *
 * @var array $attributes
 * @package Agend_Apps_Core
 */

$html = agend_apps_records_render_block( 'record-link', $attributes );

// The block has no editor wrapper to carry the pin class, so it gets its own.
$pin_class = agend_apps_records_record_link_pin_class( agend_apps_records_settings_from_attributes( agend_apps_records_surface_schema( 'record-link' ), $attributes ) );
if ( '' !== $html && '' !== $pin_class ) {
	$html = '<div class="' . esc_attr( $pin_class ) . '">' . $html . '</div>';
}

echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the core renderer.
