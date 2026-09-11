<?php
/**
 * Front-end output of the header auth block: the core renderer, fed the
 * block's attributes.
 *
 * Renders nothing in `sso` member sign-in mode (agend_apps_records_render_header_auth()
 * returns '' itself); the editor-only reason why is surfaced as a block
 * notice instead (see agend_apps_records_block_surface_notices()), the same
 * way member-login's SSO-mode gap is.
 *
 * @var array $attributes
 * @package Agend_Apps_Core
 */

echo agend_apps_records_render_block( 'header-auth', $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the core renderer.
