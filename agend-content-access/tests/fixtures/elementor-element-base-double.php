<?php
/**
 * Stand-in for Elementor's `Element_Base`, which the shared stubs do not model.
 *
 * `Agend_Content_Access_Elementor::maybe_add_section()` only acts on a control
 * stack that is an `Element_Base` and not a widget (a widget is handled through
 * Elementor's shared common stack instead). This is a bare recording stack of
 * the first kind, so a test can show which controls the section registers, in
 * what order, and how many times the catalogue is asked while it does.
 *
 * It deliberately does NOT extend the stub `Widget_Base`: a class that did would
 * be treated as a widget and refused. And because other tests assert that
 * `Element_Base` is absent, this is only ever `require`d from inside a test that
 * runs in its own process.
 *
 * Not a test file: it has no `Test.php` suffix, so PHPUnit does not collect it.
 *
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Elementor {

	if ( ! class_exists( '\\Elementor\\Element_Base' ) ) {
		class Element_Base {

			/** @var array<int, array<string, mixed>> Every recorded call, in call order. */
			public array $recordings = array();

			private string $unique_name;

			public function __construct( string $unique_name = 'section' ) {
				$this->unique_name = $unique_name;
			}

			public function get_unique_name(): string {
				return $this->unique_name;
			}

			public function start_controls_section( string $id, array $args ): void {
				$this->recordings[] = array( 'method' => 'start_controls_section', 'id' => $id, 'args' => $args );
			}

			public function add_control( string $id, array $args ): void {
				$this->recordings[] = array( 'method' => 'add_control', 'id' => $id, 'args' => $args );
			}

			public function end_controls_section(): void {
				$this->recordings[] = array( 'method' => 'end_controls_section' );
			}

			/** @return string[] Ids of the controls registered, in order. */
			public function control_ids(): array {
				return array_values(
					array_map(
						static fn( array $call ): string => (string) $call['id'],
						array_filter(
							$this->recordings,
							static fn( array $call ): bool => 'add_control' === $call['method']
						)
					)
				);
			}
		}
	}
}
