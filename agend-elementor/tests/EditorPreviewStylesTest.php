<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Elementor;

use Agend_Elementor;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

// The main plugin file defines AGEND_ELEMENTOR_VERSION and AGEND_ELEMENTOR_URL,
// which the enqueue reads. Loaded rather than redefined here so the constants
// are the plugin's own however the suite orders its test classes.
require_once AGEND_TESTS_ROOT . '/agend-elementor/agend-elementor.php';
require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor.php';

/**
 * {@see Agend_Elementor::enqueue_editor_preview_styles()}: the stylesheet that
 * keeps Agend widgets inert inside the Elementor editor preview, so a click on
 * one selects it instead of operating it.
 */
#[CoversClass( Agend_Elementor::class )]
final class EditorPreviewStylesTest extends TestCase {

	private const HANDLE = 'agend-elementor-editor-preview';

	private const SELECTOR_PREFIX = 'body.elementor-editor-active .elementor-widget[data-widget_type^="agend-"]';

	/**
	 * The stylesheet's rules, comments stripped.
	 *
	 * @return array<int, array{selectors: string[], declarations: string}>
	 */
	private function stylesheet_rules(): array {
		$css = (string) file_get_contents( AGEND_TESTS_ROOT . '/agend-elementor/assets/css/editor-preview.css' );
		$css = (string) preg_replace( '~/\*.*?\*/~s', '', $css );

		preg_match_all( '~([^{}]+)\{([^{}]*)\}~', $css, $matches, PREG_SET_ORDER );

		return array_map(
			static fn( array $match ): array => array(
				'selectors'    => array_map( 'trim', explode( ',', $match[1] ) ),
				'declarations' => trim( $match[2] ),
			),
			$matches
		);
	}

	#[Test]
	public function should_hook_the_stylesheet_onto_elementors_preview_enqueue(): void {
		$plugin = new Agend_Elementor();

		$this->assertContains(
			array( $plugin, 'enqueue_editor_preview_styles' ),
			Agend_Test_WP::$actions['elementor/preview/enqueue_styles'] ?? array()
		);
	}

	#[Test]
	public function should_enqueue_the_stylesheet_when_the_preview_hook_fires(): void {
		new Agend_Elementor();

		do_action( 'elementor/preview/enqueue_styles' );

		$this->assertSame( array( self::HANDLE ), Agend_Test_WP::$enqueued_styles );
		$this->assertSame(
			AGEND_ELEMENTOR_URL . 'assets/css/editor-preview.css',
			Agend_Test_WP::$enqueued_style_args[ self::HANDLE ]['src']
		);
	}

	#[Test]
	public function should_version_the_stylesheet_with_the_plugin_version_so_a_release_busts_caches(): void {
		new Agend_Elementor();

		do_action( 'elementor/preview/enqueue_styles' );

		$this->assertSame( AGEND_ELEMENTOR_VERSION, Agend_Test_WP::$enqueued_style_args[ self::HANDLE ]['ver'] );
		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+/', AGEND_ELEMENTOR_VERSION );
	}

	#[Test]
	public function should_not_enqueue_the_stylesheet_on_the_ordinary_front_end(): void {
		new Agend_Elementor();

		do_action( 'wp_enqueue_scripts' );
		do_action( 'enqueue_block_editor_assets' );

		$this->assertSame( array(), Agend_Test_WP::$enqueued_styles );
		$this->assertArrayNotHasKey( 'wp_enqueue_scripts', Agend_Test_WP::$actions );
		$this->assertArrayNotHasKey( 'enqueue_block_editor_assets', Agend_Test_WP::$actions );
	}

	#[Test]
	public function should_ship_the_stylesheet_the_enqueue_points_at(): void {
		$this->assertFileExists( AGEND_TESTS_ROOT . '/agend-elementor/assets/css/editor-preview.css' );
	}

	#[Test]
	public function should_scope_every_rule_to_the_editor_body_and_to_agend_widgets(): void {
		$rules = $this->stylesheet_rules();

		$this->assertNotEmpty( $rules );

		foreach ( $rules as $rule ) {
			foreach ( $rule['selectors'] as $selector ) {
				$this->assertStringStartsWith( self::SELECTOR_PREFIX . ' > ', $selector );
			}
		}
	}

	#[Test]
	public function should_leave_elementors_own_edit_handles_interactive(): void {
		foreach ( $this->stylesheet_rules() as $rule ) {
			foreach ( $rule['selectors'] as $selector ) {
				// Without these the handle bar and resize grips would go dead and
				// the widget could no longer be moved, resized or deleted.
				$this->assertStringContainsString( ':not(.elementor-element-overlay)', $selector );
				$this->assertStringContainsString( ':not(.ui-resizable-handle)', $selector );
			}
		}
	}

	#[Test]
	public function should_cover_the_widget_content_with_and_without_the_container_wrapper(): void {
		$selectors = array_merge( ...array_column( $this->stylesheet_rules(), 'selectors' ) );

		$direct_children = self::SELECTOR_PREFIX . ' > :not(.elementor-element-overlay):not(.ui-resizable-handle)';

		// The content child itself, whatever it is, and everything beneath it.
		$this->assertContains( $direct_children, $selectors );
		$this->assertContains( $direct_children . ' *', $selectors );

		// Naming the wrapper would miss the optimised markup that omits it.
		$this->assertStringNotContainsString( 'elementor-widget-container', implode( ',', $selectors ) );
	}

	#[Test]
	public function should_force_pointer_events_off_because_descendants_set_their_own(): void {
		foreach ( $this->stylesheet_rules() as $rule ) {
			$this->assertSame( 'pointer-events: none !important;', $rule['declarations'] );
		}
	}
}
