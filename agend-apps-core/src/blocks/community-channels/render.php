<?php
/**
 * Front-end output of the Agend Community Channels block: the core renderer, fed the
 * block's attributes.
 *
 * @var array $attributes
 * @package Agend_Apps_Core
 */

echo agend_apps_records_render_block( 'community-channels', $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the core renderer.
