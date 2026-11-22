<?php
/**
 * The site's language for browser-side formatting.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The site's locale as a BCP 47 language tag for `Intl` in the browser:
 * `en_AU` becomes `en-AU`. Falls back to `en-AU` when WordPress gives none.
 *
 * @return string
 */
function agend_apps_js_locale(): string {
	$locale = function_exists( 'get_locale' ) ? (string) get_locale() : '';
	$locale = str_replace( '_', '-', $locale );
	// Keep only what Intl accepts: letters, digits and hyphens.
	$locale = (string) preg_replace( '/[^A-Za-z0-9-]/', '', $locale );
	return '' !== $locale ? $locale : 'en-AU';
}
