<?php
/**
 * Plugin Name:       Agend Embed
 * Plugin URI:        https://agend.com.au
 * Description:       Embeds Agend app surfaces (LMS, Loop) in an iframe with host-assisted single sign-on. When the embed reports it has no session, this plugin navigates the iframe through the site's own IdP-initiated SSO so a logged-in member is signed into the embed without re-entering credentials. Works with any WordPress SAML IdP via a driver filter.
 * Version:           1.0.0
 * Author:            Agend
 * Author URI:        https://agend.com.au
 * Text Domain:       agend-embed
 * Requires at least: 6.0
 * Requires PHP:      7.4
 *
 * @package Agend_Embed
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin version.
 *
 * @var string
 */
define( 'AGEND_EMBED_VERSION', '1.0.0' );

/**
 * Absolute path to the plugin directory, with trailing slash.
 *
 * @var string
 */
define( 'AGEND_EMBED_PATH', plugin_dir_path( __FILE__ ) );

require_once AGEND_EMBED_PATH . 'includes/class-agend-embed-sso-drivers.php';
require_once AGEND_EMBED_PATH . 'includes/class-agend-embed-shortcode.php';

add_action( 'init', array( 'Agend_Embed_Shortcode', 'register' ) );
