<?php
/**
 * Front-end output of the record field block: the core renderer, fed the
 * block's attributes.
 *
 * Renders nothing outside a card or detail template
 * (Agend_Apps_Records_Record_Context carries no frame there), or when the
 * chosen field does not apply to the record type in scope
 * (agend_apps_records_record_field_render_reason() names that one case). A
 * live page has nothing more to say in either case, since the surface simply
 * renders nothing; the block's own editor view surfaces the reason instead,
 * from the dedicated preview route (see shared/surface-preview.js).
 *
 * @var array $attributes
 * @package Agend_Apps_Core
 */

echo agend_apps_records_render_block( 'record-field', $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the core renderer.
