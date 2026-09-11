<?php
/**
 * Front-end output of the record block (Agend Panel) block: the core
 * renderer, fed the block's attributes.
 *
 * Unlike record-image, this surface needs no opt beyond what
 * agend_apps_records_render_block() already forwards, so it uses the shared
 * helper directly, the same as filter/render.php: the editor's own need for
 * a 'preview' opt is served by the dedicated
 * agend_apps_records_register_surface_preview_route() route in blocks.php,
 * not by this render callback.
 *
 * Renders nothing outside a card or detail template
 * (Agend_Apps_Records_Record_Context has no frame there), or when the chosen
 * panel key cannot render for one of the reasons
 * agend_apps_records_record_block_render_reason() names. A retired key is the
 * one exception: it still renders its fragment exactly as before, alongside
 * (not instead of) the notice that key has moved to a field. Those reasons
 * are per-instance, so agend_apps_records_block_surface_notices() (keyed by
 * surface id alone) cannot express them; the block's editor view surfaces
 * them itself instead, from the same preview route the filter block uses.
 *
 * @var array $attributes
 * @package Agend_Apps_Core
 */

echo agend_apps_records_render_block( 'record-block', $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the core renderer.
