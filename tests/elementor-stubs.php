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
			const ICONS       = 'icons';
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

		if ( ! class_exists( '\\Elementor\\Plugin' ) ) {
			/**
			 * Settable double for `\Elementor\Editor`: only the one method
			 * Agend_Elementor_Field_Widget_Trait::is_editor() and a few
			 * widgets' render() methods call.
			 */
			final class Elementor_Test_Editor_Double {
				/** @var bool Toggled by a test to simulate the Elementor editor being open. */
				public bool $is_edit_mode = false;

				public function is_edit_mode(): bool {
					return $this->is_edit_mode;
				}
			}

			/**
			 * Settable double for `\Elementor\Preview`: only the one method
			 * Agend_Elementor_Field_Widget_Trait::is_editor() calls.
			 */
			final class Elementor_Test_Preview_Double {
				/** @var bool Toggled by a test to simulate the editor's live preview iframe. */
				public bool $is_preview_mode = false;

				public function is_preview_mode(): bool {
					return $this->is_preview_mode;
				}
			}

			/**
			 * Settable double for `\Elementor\Plugin`: exposes just enough of
			 * `::$instance->editor->is_edit_mode()` and
			 * `::$instance->preview->is_preview_mode()` for
			 * Agend_Elementor_Field_Widget_Trait::is_editor() (and the few
			 * widgets that call the editor check directly) to be driven by a
			 * test, without the real Elementor plugin.
			 */
			final class Plugin {
				/** @var self */
				public static $instance;

				/** @var Elementor_Test_Editor_Double */
				public $editor;

				/** @var Elementor_Test_Preview_Double */
				public $preview;

				public function __construct() {
					$this->editor  = new Elementor_Test_Editor_Double();
					$this->preview = new Elementor_Test_Preview_Double();
				}

				/** Resets both flags to their default (not in the editor). Called by Agend\Tests\TestCase::setUp(). */
				public static function reset(): void {
					self::$instance->editor->is_edit_mode     = false;
					self::$instance->preview->is_preview_mode = false;
				}
			}

			Plugin::$instance = new Plugin();
		}
	}
}
