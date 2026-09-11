<?php
/**
 * Front-end output of the export reports block: the core renderer, fed the
 * block's attributes converted to Elementor-shaped settings.
 *
 * agend_apps_records_render_export_reports() takes a second $opts argument
 * (an 'id' for unique DOM ids across several instances on one page), which
 * the Elementor widget supplies from $this->get_id(). agend_apps_records_render_block()
 * forwards only $attributes, not a third argument, and it is a shared helper
 * every other block's render.php also calls, so widening its signature for
 * this one surface would ripple into every surface's contract for a need
 * only this one has. Calling the surface renderer directly here, the same
 * two calls agend_apps_records_render_block() itself makes, keeps that
 * widening out of the shared helper entirely.
 *
 * @var array $attributes
 * @package Agend_Apps_Core
 */

$settings = agend_apps_records_settings_from_attributes( agend_apps_records_surface_schema( 'export-reports' ), $attributes );

echo agend_apps_records_render_export_reports( $settings, array( 'id' => wp_unique_id( 'agend-export-reports-' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the core renderer.
