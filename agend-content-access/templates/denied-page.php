<?php
/**
 * The template a denied page is served with, in place of its own.
 *
 * Chosen by `Agend_Content_Access_Frontend::use_denied_template()` on
 * `template_include`, last, so the page's own template never runs and nothing it
 * would have printed from the protected document reaches the visitor. It holds no
 * markup of its own: the page it prints is `Agend_Content_Access_Frontend::print_denied_page()`,
 * which is the theme's header, the placeholder and the theme's footer.
 *
 * @package Agend_Content_Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prints the denied page. Answered only on a request the page gate denied.
 */
do_action( Agend_Content_Access_Frontend::DENIED_PAGE_ACTION );
