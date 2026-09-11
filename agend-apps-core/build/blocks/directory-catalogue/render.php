<?php
/**
 * Front-end output of the directory catalogue block: the core renderer, fed
 * the block's attributes.
 *
 * The Elementor widget refuses to render inside a card or detail template
 * (a catalogue would otherwise refetch the listing search once per card);
 * agend_apps_records_render_directory_catalogue() carries that same guard,
 * returning an empty string rather than an Elementor-flavoured alert, so
 * this adapter needs no guard of its own and stays correct once block-based
 * card templates exist. The block editor gets its own notice for this
 * surface, if any, through agend_apps_records_block_surface_notices().
 *
 * @var array $attributes
 * @package Agend_Apps_Core
 */

echo agend_apps_records_render_block( 'directory-catalogue', $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the core renderer.
