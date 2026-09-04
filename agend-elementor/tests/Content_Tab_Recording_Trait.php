<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Elementor;

/**
 * Reduces a widget's full `Widget_Base::$recordings` to its Content-tab
 * sections and their controls, matching the shape the fidelity fixtures use.
 *
 * Shared by every `*ControlsTest` so a widget with an adapter control
 * (`add_responsive_control()`, `add_group_control()`) is captured the same
 * way as a plain `add_control()` one; {@see EventsCatalogueControlsTest}
 * keeps its own copy because it predates this trait and only ever needed the
 * `add_control()` case.
 */
trait Content_Tab_Recording_Trait {

	/**
	 * @param array<int, array<string, mixed>> $recordings Widget_Base::$recordings.
	 * @return array{sections: array<int, array<string, mixed>>}
	 */
	private function contentTabRecordings( array $recordings ): array {
		$sections   = array();
		$current    = null;
		$in_content = false;

		foreach ( $recordings as $entry ) {
			if ( 'start_controls_section' === $entry['method'] ) {
				$in_content = ( \Elementor\Controls_Manager::TAB_CONTENT === ( $entry['args']['tab'] ?? null ) );
				if ( $in_content ) {
					$current = array(
						'id'       => $entry['id'],
						'args'     => $entry['args'],
						'controls' => array(),
					);
				}
				continue;
			}
			if ( 'end_controls_section' === $entry['method'] ) {
				if ( $in_content && null !== $current ) {
					$sections[] = $current;
				}
				$current    = null;
				$in_content = false;
				continue;
			}
			if ( ! $in_content || null === $current ) {
				continue;
			}
			if ( 'add_control' === $entry['method'] ) {
				$current['controls'][] = array(
					'id'   => $entry['id'],
					'args' => $entry['args'],
				);
			} elseif ( 'add_responsive_control' === $entry['method'] ) {
				$current['controls'][] = array(
					'id'     => $entry['id'],
					'args'   => $entry['args'],
					'method' => 'add_responsive_control',
				);
			} elseif ( 'add_group_control' === $entry['method'] ) {
				$current['controls'][] = array(
					'id'     => $entry['type'],
					'args'   => $entry['args'],
					'method' => 'add_group_control',
				);
			}
		}

		return array( 'sections' => $sections );
	}
}
