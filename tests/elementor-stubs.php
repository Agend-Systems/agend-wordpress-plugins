<?php
/**
 * Minimal Elementor test doubles.
 *
 * Just enough of the `\Elementor` namespace for a widget file to load and for
 * `register_controls()` to run against a recording `Widget_Base`, so a widget
 * can be exercised without the real Elementor plugin. Guarded so a real
 * Elementor install (an integration run, not this unit suite) is never
 * shadowed by these stubs.
 *
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Elementor {

	if ( ! class_exists( '\\Elementor\\Widget_Base' ) ) {

		/**
		 * Control type constants referenced by the Agend Elementor widgets.
		 */
		final class Controls_Manager {
			const CHOOSE      = 'choose';
			const COLOR       = 'color';
			const DIMENSIONS  = 'dimensions';
			const HEADING     = 'heading';
			const MEDIA       = 'media';
			const NUMBER      = 'number';
			const RAW_HTML    = 'raw_html';
			const REPEATER    = 'repeater';
			const SELECT      = 'select';
			const SELECT2     = 'select2';
			const SLIDER      = 'slider';
			const SWITCHER    = 'switcher';
			const TAB_CONTENT = 'content';
			const TAB_STYLE   = 'style';
			const TEXT        = 'text';
			const TEXTAREA    = 'textarea';
			const URL         = 'url';
		}

		/**
		 * Recording double for `\Elementor\Widget_Base`.
		 *
		 * Records every section and control registration in call order, so a
		 * test can assert on exactly what a widget's `register_controls()`
		 * declared without a real Elementor editor.
		 */
		class Widget_Base {

			/** @var array<int, array<string, mixed>> Every recorded section/control call, in call order. */
			public array $recordings = array();

			/** @var array<string, mixed> */
			protected array $settings;

			/**
			 * @param array<string, mixed> $data     Unused; kept for signature parity with Elementor.
			 * @param array<string, mixed> $args     Unused; kept for signature parity with Elementor.
			 * @param array<string, mixed> $settings Settings returned by get_settings_for_display().
			 */
			public function __construct( array $data = array(), ?array $args = null, array $settings = array() ) {
				$this->settings = $settings;
			}

			public function start_controls_section( string $id, array $args ): void {
				$this->recordings[] = array(
					'method' => 'start_controls_section',
					'id'     => $id,
					'args'   => $args,
				);
			}

			public function add_control( string $id, array $args ): void {
				$this->recordings[] = array(
					'method' => 'add_control',
					'id'     => $id,
					'args'   => $args,
				);
			}

			public function add_group_control( string $type, array $args ): void {
				$this->recordings[] = array(
					'method' => 'add_group_control',
					'type'   => $type,
					'args'   => $args,
				);
			}

			public function add_responsive_control( string $id, array $args ): void {
				$this->recordings[] = array(
					'method' => 'add_responsive_control',
					'id'     => $id,
					'args'   => $args,
				);
			}

			public function end_controls_section(): void {
				$this->recordings[] = array( 'method' => 'end_controls_section' );
			}

			// Style-tab-only tabbed-control grouping (Normal/Hover). Never
			// entered while reducing to Content-tab recordings, so a no-op is
			// enough to let a widget's register_controls() run to completion.
			public function start_controls_tabs( string $id, array $args = array() ): void {}

			public function start_controls_tab( string $id, array $args = array() ): void {}

			public function end_controls_tab(): void {}

			public function end_controls_tabs(): void {}

			public function add_render_attribute( $element, $key = null, $value = null ): void {}

			/**
			 * @param string|null $key Single setting key, or null for all settings.
			 * @return mixed
			 */
			public function get_settings_for_display( ?string $key = null ) {
				if ( null === $key ) {
					return $this->settings;
				}

				return $this->settings[ $key ] ?? null;
			}

			public function get_id(): string {
				return 'test-widget-id';
			}

			public function get_name(): string {
				return 'test-widget';
			}
		}

		if ( ! class_exists( '\\Elementor\\Repeater' ) ) {
			/**
			 * Recording double for `\Elementor\Repeater`: enough of the real
			 * class for a widget's `register_controls()` to build one up with
			 * `add_control()` and read it back with `get_controls()`, in the
			 * same shape Elementor's `Repeater::get_controls()` returns.
			 */
			class Repeater {
				/** @var array<int, array<string, mixed>> */
				private array $controls = array();

				public function add_control( string $id, array $args ): void {
					$args['name'] = $id;
					$this->controls[] = $args;
				}

				/**
				 * @return array<int, array<string, mixed>>
				 */
				public function get_controls(): array {
					return $this->controls;
				}
			}
		}

		if ( ! class_exists( '\\Elementor\\Group_Control_Typography' ) ) {
			/** Group control double: only `get_type()` is ever called on these in the widgets under test. */
			final class Group_Control_Typography {
				public static function get_type(): string {
					return 'typography';
				}
			}
		}

		if ( ! class_exists( '\\Elementor\\Group_Control_Border' ) ) {
			/** @see Group_Control_Typography */
			final class Group_Control_Border {
				public static function get_type(): string {
					return 'border';
				}
			}
		}

		if ( ! class_exists( '\\Elementor\\Group_Control_Box_Shadow' ) ) {
			/** @see Group_Control_Typography */
			final class Group_Control_Box_Shadow {
				public static function get_type(): string {
					return 'box_shadow';
				}
			}
		}
	}
}
