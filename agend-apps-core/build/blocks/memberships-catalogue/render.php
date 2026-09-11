<?php
/**
 * Front-end output of the memberships catalogue block: the core renderer, fed
 * the block's attributes.
 *
 * @var array $attributes
 * @package Agend_Apps_Core
 */

echo agend_apps_records_render_block( 'memberships-catalogue', $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the core renderer.
