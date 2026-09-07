<?php
/**
 * Elementor's implementation of the Agend Apps Core template renderer contract.
 *
 * @package Agend_Elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Delegates the {@see Agend_Apps_Template_Renderer} contract to the existing
 * all-static {@see Agend_Elementor_Template_Renderer}.
 */
final class Agend_Elementor_Template_Renderer_Adapter implements Agend_Apps_Template_Renderer {

	public function is_valid_template( int $template_id ): bool {
		return Agend_Elementor_Template_Renderer::is_valid_template( $template_id );
	}

	public function ensure_styles( int $template_id ): void {
		Agend_Elementor_Template_Renderer::ensure_styles( $template_id );
	}

	public function render( int $template_id, string $type, array $record, array $extra = array(), bool $with_css = false ): string {
		return Agend_Elementor_Template_Renderer::render( $template_id, $type, $record, $extra, $with_css );
	}

	public function render_plain( int $template_id, bool $with_css = false ): string {
		return Agend_Elementor_Template_Renderer::render_plain( $template_id, $with_css );
	}
}
