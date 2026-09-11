<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\AppsCore;

use Agend_Apps_Block_Template_Renderer;
use Agend_Apps_Records_Record_Context;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/templates/interface-agend-apps-template-renderer.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/templates/class-agend-apps-block-template-renderer.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/record-context.php';

/**
 * `Agend_Apps_Block_Template_Renderer`: the block-editor implementation of
 * the Agend Apps Core template renderer contract.
 */
#[CoversClass( Agend_Apps_Block_Template_Renderer::class )]
final class BlockTemplateRendererTest extends TestCase {

	private Agend_Apps_Block_Template_Renderer $renderer;

	protected function setUp(): void {
		parent::setUp();
		Agend_Apps_Block_Template_Renderer::reset();
		Agend_Apps_Records_Record_Context::reset();
		$this->renderer = new Agend_Apps_Block_Template_Renderer();
	}

	protected function tearDown(): void {
		Agend_Apps_Block_Template_Renderer::reset();
		Agend_Apps_Records_Record_Context::reset();
		parent::tearDown();
	}

	/**
	 * @param int    $id           Post id to seed.
	 * @param string $content      post_content.
	 * @param string $post_type    post_type.
	 * @param string $post_status  post_status.
	 */
	private function seedTemplate( int $id, string $content = '', string $post_type = 'wp_block', string $post_status = 'publish' ): void {
		$GLOBALS['agend_test_posts'][ $id ] = array(
			'ID'           => $id,
			'post_type'    => $post_type,
			'post_status'  => $post_status,
			'post_content' => $content,
			'post_title'   => 'Test template ' . $id,
		);
	}

	// -- is_valid_template() ------------------------------------------------

	#[Test]
	public function should_accept_a_published_wp_block_post(): void {
		$this->seedTemplate( 5 );

		$this->assertTrue( $this->renderer->is_valid_template( 5 ) );
	}

	#[Test]
	public function should_reject_a_post_of_a_different_post_type(): void {
		$this->seedTemplate( 6, '', 'page' );

		$this->assertFalse( $this->renderer->is_valid_template( 6 ) );
	}

	#[Test]
	public function should_reject_an_unpublished_wp_block_post(): void {
		$this->seedTemplate( 7, '', 'wp_block', 'draft' );

		$this->assertFalse( $this->renderer->is_valid_template( 7 ) );
	}

	#[Test]
	public function should_reject_id_zero(): void {
		$this->assertFalse( $this->renderer->is_valid_template( 0 ) );
	}

	#[Test]
	public function should_reject_an_id_with_no_matching_post(): void {
		$this->assertFalse( $this->renderer->is_valid_template( 999 ) );
	}

	// -- render() / render_plain() basics -----------------------------------

	#[Test]
	public function should_return_empty_string_from_render_when_the_template_is_invalid(): void {
		$this->assertSame( '', $this->renderer->render( 404, 'event', array() ) );
	}

	#[Test]
	public function should_return_empty_string_from_render_plain_when_the_template_is_invalid(): void {
		$this->assertSame( '', $this->renderer->render_plain( 404 ) );
	}

	#[Test]
	public function should_render_a_simple_static_block(): void {
		$this->seedTemplate( 10, '<!-- wp:agend-test/plain /-->' );
		register_block_type(
			'agend-test/plain',
			array( 'render_callback' => static fn(): string => '<p>hello</p>' )
		);

		$this->assertSame( '<p>hello</p>', $this->renderer->render( 10, 'event', array( 'slug' => 'x' ) ) );
	}

	#[Test]
	public function should_render_a_template_with_no_record_via_render_plain(): void {
		$this->seedTemplate( 11, '<!-- wp:agend-test/plain /-->' );
		register_block_type(
			'agend-test/plain',
			array(
				'render_callback' => static fn(): string => 0 === Agend_Apps_Records_Record_Context::depth() ? 'no-context' : 'has-context',
			)
		);

		$this->assertSame( 'no-context', $this->renderer->render_plain( 11 ) );
	}

	// -- Record context (push/pop, including when the render throws) --------

	#[Test]
	public function should_push_the_record_onto_context_for_the_duration_of_the_render(): void {
		$this->seedTemplate( 12, '<!-- wp:agend-test/reads-context /-->' );
		register_block_type(
			'agend-test/reads-context',
			array(
				'render_callback' => static fn(): string => Agend_Apps_Records_Record_Context::type() . ':' . Agend_Apps_Records_Record_Context::record()['slug'],
			)
		);

		$html = $this->renderer->render( 12, 'event', array( 'slug' => 'sample-event' ) );

		$this->assertSame( 'event:sample-event', $html );
		$this->assertSame( 0, Agend_Apps_Records_Record_Context::depth(), 'the frame is popped once the render completes' );
	}

	#[Test]
	public function should_pop_the_record_context_even_when_the_render_throws(): void {
		$this->seedTemplate( 13, '<!-- wp:agend-test/throws /-->' );
		register_block_type(
			'agend-test/throws',
			array(
				'render_callback' => static function (): string {
					throw new RuntimeException( 'boom' );
				},
			)
		);

		try {
			$this->renderer->render( 13, 'event', array( 'slug' => 'x' ) );
			$this->fail( 'Expected the exception to propagate.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'boom', $e->getMessage() );
		}

		$this->assertSame( 0, Agend_Apps_Records_Record_Context::depth() );

		// The in-flight counter must also have unwound correctly, so a second
		// render of the same template id behaves normally rather than still
		// looking "in flight" from the failed call.
		register_block_type(
			'agend-test/throws',
			array( 'render_callback' => static fn(): string => 'recovered' )
		);

		$this->assertSame( 'recovered', $this->renderer->render( 13, 'event', array( 'slug' => 'x' ) ) );
	}

	// -- Recursion guard (Decision D5) ---------------------------------------

	#[Test]
	public function should_trip_the_recursion_guard_at_max_depth(): void {
		$this->seedTemplate( 14, '<!-- wp:agend-test/self-render /-->' );

		$calls = 0;
		register_block_type(
			'agend-test/self-render',
			array(
				'render_callback' => function () use ( &$calls ): string {
					++$calls;

					return $this->renderer->render( 14, 'event', array( 'slug' => 'x' ) );
				},
			)
		);

		$this->renderer->render( 14, 'event', array( 'slug' => 'x' ) );

		// One outer call plus MAX_DEPTH (3) recursive calls succeed and run the
		// callback; the next nested attempt trips the guard and returns ''
		// without invoking the callback a further time.
		$this->assertSame( 3, $calls );
		$this->assertSame( 0, Agend_Apps_Records_Record_Context::depth(), 'every pushed frame across the recursive chain is popped again' );
	}

	// -- ensure_styles() (Decision D6, style handle delivery) ---------------

	#[Test]
	public function should_enqueue_a_block_types_registered_style_handles(): void {
		$this->seedTemplate( 15, '<!-- wp:agend-test/styled /-->' );
		register_block_type( 'agend-test/styled', array( 'style_handles' => array( 'test-style-handle' ) ) );

		$this->renderer->ensure_styles( 15 );

		$this->assertSame( array( 'test-style-handle' ), Agend_Test_WP::$enqueued_styles );
	}

	#[Test]
	public function should_only_enqueue_styles_once_per_template_id_per_request(): void {
		$this->seedTemplate( 16, '<!-- wp:agend-test/styled /-->' );
		register_block_type( 'agend-test/styled', array( 'style_handles' => array( 'test-style-handle' ) ) );

		$this->renderer->ensure_styles( 16 );
		$this->renderer->ensure_styles( 16 );

		$this->assertSame( array( 'test-style-handle' ), Agend_Test_WP::$enqueued_styles );
	}

	#[Test]
	public function should_enqueue_styles_for_every_distinct_block_type_including_nested_ones(): void {
		$this->seedTemplate(
			17,
			'<!-- wp:agend-test/wrapper --><!-- wp:agend-test/styled /--><!-- /wp:agend-test/wrapper -->'
		);
		register_block_type( 'agend-test/wrapper', array( 'style_handles' => array( 'wrapper-style' ) ) );
		register_block_type( 'agend-test/styled', array( 'style_handles' => array( 'styled-style' ) ) );

		$this->renderer->ensure_styles( 17 );

		sort( Agend_Test_WP::$enqueued_styles );
		$this->assertSame( array( 'styled-style', 'wrapper-style' ), Agend_Test_WP::$enqueued_styles );
	}

	#[Test]
	public function should_no_op_ensure_styles_when_no_block_type_registry_style_handles_are_declared(): void {
		$this->seedTemplate( 18, '<!-- wp:agend-test/unstyled /-->' );
		register_block_type( 'agend-test/unstyled', array() );

		$this->renderer->ensure_styles( 18 );

		$this->assertSame( array(), Agend_Test_WP::$enqueued_styles );
	}

	// -- with_css / block-supports layout CSS (Decision D6) ------------------

	#[Test]
	public function should_prepend_the_captured_block_supports_stylesheet_when_with_css_is_true(): void {
		$this->seedTemplate( 19, '<!-- wp:agend-test/plain /-->' );
		register_block_type( 'agend-test/plain', array( 'render_callback' => static fn(): string => '<div>card</div>' ) );
		Agend_Test_WP::$style_engine_stylesheet = '.wp-block-abc123{gap:10px}';

		$html = $this->renderer->render( 19, 'event', array(), array(), true );

		$this->assertSame( '<style>.wp-block-abc123{gap:10px}</style><div>card</div>', $html );
	}

	#[Test]
	public function should_not_inline_any_style_tag_when_with_css_is_false(): void {
		$this->seedTemplate( 20, '<!-- wp:agend-test/plain /-->' );
		register_block_type( 'agend-test/plain', array( 'render_callback' => static fn(): string => '<div>card</div>' ) );
		Agend_Test_WP::$style_engine_stylesheet = '.wp-block-abc123{gap:10px}';

		$html = $this->renderer->render( 20, 'event', array(), array(), false );

		$this->assertSame( '<div>card</div>', $html );
	}

	#[Test]
	public function should_not_inline_an_empty_style_tag_when_the_style_engine_store_is_empty(): void {
		$this->seedTemplate( 21, '<!-- wp:agend-test/plain /-->' );
		register_block_type( 'agend-test/plain', array( 'render_callback' => static fn(): string => '<div>card</div>' ) );

		$html = $this->renderer->render( 21, 'event', array(), array(), true );

		$this->assertSame( '<div>card</div>', $html );
	}

	// -- Parse once, render many (Decision D4) -------------------------------

	#[Test]
	public function should_parse_the_template_content_only_once_per_template_id_per_request(): void {
		$this->seedTemplate( 22, '<!-- wp:agend-test/echo /-->' );
		register_block_type( 'agend-test/echo', array( 'render_callback' => static fn(): string => 'first' ) );

		$first = $this->renderer->render( 22, 'event', array() );
		$this->assertSame( 'first', $first );

		// Mutate the stored post content and register a NEW block type; a
		// fresh parse would pick this up. The cached parse from the first
		// render must not.
		$GLOBALS['agend_test_posts'][22]['post_content'] = '<!-- wp:agend-test/changed /-->';
		register_block_type( 'agend-test/changed', array( 'render_callback' => static fn(): string => 'second' ) );

		$second = $this->renderer->render( 22, 'event', array() );
		$this->assertSame( 'first', $second, 'the parsed block array is cached per template id per request' );
	}
}
