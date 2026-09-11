<?php
/**
 * Front-end output of the filter block: the core renderer, fed the block's
 * attributes.
 *
 * Never called with the 'preview' opt here: agend_apps_records_render_block()
 * forwards only $attributes, and that is exactly what a live page needs for
 * this surface (unlike export-reports/render.php, which bypasses that helper
 * because its surface renderer needs a per-instance 'id' on the front end
 * too). The editor's own need for 'preview' is served by a separate route,
 * agend_apps_records_register_filter_preview_route() in blocks.php, which
 * calls agend_apps_records_render_filter() directly with that opt; the
 * block's index.js fetches it there rather than through this render callback.
 *
 * Renders nothing outside a catalogue's filters template
 * (Agend_Apps_Records_Filter_Context::type() is '' there), or when the chosen
 * filter cannot render for one of the reasons
 * agend_apps_records_filter_render_reason() names. Those reasons are
 * per-instance, so agend_apps_records_block_surface_notices() (keyed by
 * surface id alone) cannot express them; the block's editor view surfaces
 * them itself instead, from the same preview route.
 *
 * @var array $attributes
 * @package Agend_Apps_Core
 */

echo agend_apps_records_render_block( 'filter', $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the core renderer.
