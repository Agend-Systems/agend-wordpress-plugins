<?php
/**
 * Front-end output of the cart-view block: the shop's core-shaped renderer,
 * fed the block's attributes.
 *
 * @var array $attributes
 * @package Agend_Apps_Shop
 */

echo agend_apps_records_render_block( 'cart-view', $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the shop's renderer.
