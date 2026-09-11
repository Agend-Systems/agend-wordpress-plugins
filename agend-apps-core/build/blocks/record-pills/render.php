<?php
/**
 * Front-end output of the record pills block: the core renderer, fed the
 * block's attributes.
 *
 * Renders nothing outside a card or detail template
 * (Agend_Apps_Records_Record_Context carries no frame there), when the
 * chosen terms field does not apply to the record type in scope, or when the
 * record has no terms for it (agend_apps_records_record_pills_render_reason()
 * names both). A live page has nothing more to say in any case, since the
 * surface simply renders nothing; the block's own editor view surfaces the
 * reason instead, from the dedicated preview route (see
 * shared/surface-preview.js).
 *
 * @var array $attributes
 * @package Agend_Apps_Core
 */

echo agend_apps_records_render_block( 'record-pills', $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the core renderer.
