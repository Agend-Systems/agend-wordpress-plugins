<?php
/**
 * Front-end output of the Agend Map block: the core renderer, fed the
 * block's attributes. agend_apps_records_render_directory_map() refuses to
 * draw inside a card or detail template itself, so this adapter needs no
 * guard of its own.
 *
 * @var array $attributes
 * @package Agend_Apps_Core
 */

echo agend_apps_records_render_block( 'directory-map', $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the core renderer.
