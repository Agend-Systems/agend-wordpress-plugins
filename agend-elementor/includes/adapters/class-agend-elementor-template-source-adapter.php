<?php
/**
 * Elementor's implementation of the Agend Apps Core template source contract.
 *
 * @package Agend_Elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Delegates the {@see Agend_Apps_Template_Source} contract to the existing
 * all-static {@see Agend_Elementor_Templates}.
 */
final class Agend_Elementor_Template_Source_Adapter implements Agend_Apps_Template_Source {

	public function label(): string {
		return __( 'Elementor', 'agend-elementor' );
	}

	public function templates(): array {
		return Agend_Elementor_Templates::map();
	}

	public function page_contains_surface( int $page_id, string $surface ): ?bool {
		$widget_types = array(
			'events-catalogue'    => 'agend-events-catalogue',
			'courses-catalogue'   => 'agend-courses-catalogue',
			'directory-catalogue' => 'agend-directory-catalogue',
		);

		if ( ! isset( $widget_types[ $surface ] ) ) {
			return null;
		}

		if ( ! metadata_exists( 'post', $page_id, '_elementor_data' ) ) {
			return null;
		}

		$data = get_post_meta( $page_id, '_elementor_data', true );

		// Matching the widgetType string directly is a cheap heuristic (no JSON
		// parse) and is advisory only, so a false negative (e.g. the widget
		// nested unusually) never blocks saving the setting.
		return false !== strpos( (string) $data, '"widgetType":"' . $widget_types[ $surface ] . '"' );
	}
}
