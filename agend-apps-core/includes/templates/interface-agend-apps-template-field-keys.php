<?php
/**
 * Contract for reading which record fields a template binds.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reports the record field keys and custom field keys a template's Agend
 * Field, Agend Pills and Agend Image elements are bound to, so a search can
 * ask the gateway for the optional data that template renders and nothing
 * more.
 *
 * Kept apart from {@see Agend_Apps_Template_Renderer} on purpose: the two
 * plugins are updated independently on live sites, and a method added to an
 * interface another plugin implements would fatal that plugin until it caught
 * up. A renderer that does not implement this reports no keys, which asks for
 * no optional data.
 */
interface Agend_Apps_Template_Field_Keys {

	/**
	 * The keys a template binds, or null when the id is not a template this
	 * reader owns.
	 *
	 * @param int $template_id The template's post id.
	 * @return array{fields: string[], custom_fields: string[]}|null
	 */
	public function field_keys( int $template_id ): ?array;
}
