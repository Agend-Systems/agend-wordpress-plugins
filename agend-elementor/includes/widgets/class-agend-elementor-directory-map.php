<?php
/**
 * Elementor "Agend Map" widget: a map of the listings the Directory
 * Catalogue on the same page is showing.
 *
 * @package Agend_Elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A thin adapter over the core surface: settings come from
 * agend_apps_records_surface_schema( 'directory-map' ), so the block editor
 * offers exactly the same ones, and the markup from
 * agend_apps_records_render_directory_map().
 *
 * Placed anywhere on the page, not in a template: beside the catalogue in a
 * column for a split view, or just below it with "Show only in Map view" on
 * for a List / Map switch.
 */
class Agend_Elementor_Directory_Map extends \Elementor\Widget_Base {

	use Agend_Elementor_Field_Widget_Trait;

	public function get_name(): string {
		return 'agend-directory-map';
	}

	public function get_title(): string {
		return __( 'Agend Map', 'agend-elementor' );
	}

	public function get_icon(): string {
		return 'eicon-google-maps';
	}

	public function get_categories(): array {
		return array( Agend_Elementor::CATEGORY );
	}

	public function get_keywords(): array {
		return array( 'agend', 'map', 'directory', 'location', 'pins', 'near me' );
	}

	public function get_script_depends(): array {
		return array( 'agend-apps-records-directory-map' );
	}

	public function get_style_depends(): array {
		return array( 'agend-apps-records-directory-map' );
	}

	protected function register_controls(): void {
		Agend_Elementor_Schema_Controls::register( $this, agend_apps_records_surface_schema( 'directory-map' ) );
	}

	protected function render(): void {
		$s = Agend_Elementor_Global_Colours::resolve( $this->get_settings_for_display() );

		// In the editor the map always shows, even when it waits for a List /
		// Map switch on the live page, so a designer can see and size it.
		echo agend_apps_records_render_directory_map( $s, array( 'preview' => $this->is_editor() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the core renderer.
	}
}
