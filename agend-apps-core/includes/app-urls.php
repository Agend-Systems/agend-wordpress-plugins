<?php
/**
 * Where an Agend app lives, for links that take a person into it.
 *
 * The gateway does not say where Loop, Atlas and the other apps are hosted, so
 * the address is worked out from the API root the site is already configured
 * with. The platform keeps each app on its own subdomain of the platform root
 * (`loop.` and `atlas.` beside `api.`, on the production and staging domains)
 * and on its own port locally. The portal address is not used: it can be an
 * association's own domain while the apps stay on the platform.
 *
 * One implementation for every app, so every app is found the same way:
 *
 * 1. an explicit option for the app, if it is a web address;
 * 2. otherwise the address derived from the API root;
 * 3. then the `agend_apps_app_url` filter has the last word.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What is known about each app: the option that overrides its address, the
 * subdomain it takes in place of `api.`, and the port it has beside the local
 * gateway (which is on 3072).
 *
 * @return array<string, array{option: string, subdomain: string, local_port: int}>
 */
function agend_apps_app_directory(): array {
	return array(
		'loop'  => array(
			'option'     => 'agend_apps_loop_url',
			'subdomain'  => 'loop',
			'local_port' => 3063,
		),
		'atlas' => array(
			'option'     => 'agend_apps_atlas_url',
			'subdomain'  => 'atlas',
			'local_port' => 3066,
		),
	);
}

/**
 * The address of an Agend app that goes with an API root.
 *
 * @param string $app  The app: `loop` or `atlas`.
 * @param string $root The API root URL, as `Agend_Apps_Settings::get_root_url()` gives it.
 * @return string The app's root URL without a trailing slash, or '' when the
 *                app is unknown or the API host has no `api.` or local form to
 *                swap (a guess would send visitors somewhere that is not the app).
 */
function agend_apps_app_derive_url( string $app, string $root ): string {
	$known = agend_apps_app_directory();

	if ( ! isset( $known[ $app ] ) ) {
		return '';
	}

	$root = rtrim( $root, '/' );

	// Local development: the gateway and the app are two ports on one host.
	if ( 1 === preg_match( '#^(https?://[^/:]+):3072$#i', $root, $match ) ) {
		return $match[1] . ':' . $known[ $app ]['local_port'];
	}

	if ( 1 === preg_match( '#^(https?://)api\.(.+)$#i', $root, $match ) ) {
		return $match[1] . $known[ $app ]['subdomain'] . '.' . $match[2];
	}

	return '';
}

/**
 * The root URL of an Agend app, without a trailing slash.
 *
 * @param string $app The app: `loop` or `atlas`.
 * @return string '' when there is none to derive (a custom API host that does
 *                not start with `api.`) and nothing sets one.
 */
function agend_apps_app_url( string $app ): string {
	$known = agend_apps_app_directory();
	$url   = '';

	if ( isset( $known[ $app ] ) ) {
		$explicit = trim( (string) get_option( $known[ $app ]['option'], '' ) );

		// A value that is not a web address (`javascript:` and the like) is
		// ignored, not trusted: these addresses end up in links.
		if ( 1 === preg_match( '#^https?://#i', $explicit ) ) {
			$url = rtrim( $explicit, '/' );
		} else {
			$url = agend_apps_app_derive_url( $app, class_exists( 'Agend_Apps_Settings' ) ? Agend_Apps_Settings::get_root_url() : '' );
		}
	}

	/**
	 * Filters the root URL of an Agend app.
	 *
	 * @param string $url The resolved root URL, without a trailing slash; '' when unknown.
	 * @param string $app The app: `loop` or `atlas`.
	 */
	return rtrim( (string) apply_filters( 'agend_apps_app_url', $url, $app ), '/' );
}
