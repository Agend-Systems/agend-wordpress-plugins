<?php
/**
 * Front-end output of the add-to-cart block: the shop's core-shaped
 * renderer, fed the block's attributes.
 *
 * @var array $attributes
 * @package Agend_Apps_Shop
 */

echo agend_apps_records_render_block( 'add-to-cart', $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the shop's renderer.
