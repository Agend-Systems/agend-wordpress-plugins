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

	public function options( string $placeholder = '' ): array {
		return Agend_Elementor_Templates::options( $placeholder );
	}
}
