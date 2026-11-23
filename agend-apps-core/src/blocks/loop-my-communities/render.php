<?php
/**
 * Front-end output of the My Communities block: the core renderer, fed the
 * block's attributes. A visitor gets a sign-in card; a signed-in member gets a
 * shell their own communities are loaded into after the page has loaded.
 *
 * @var array $attributes
 * @package Agend_Apps_Core
 */

echo agend_apps_records_render_block( 'loop-my-communities', $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the core renderer.
