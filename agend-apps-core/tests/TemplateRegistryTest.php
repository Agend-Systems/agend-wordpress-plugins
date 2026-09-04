<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\AppsCore;

use Agend_Apps_Template_Renderer;
use Agend_Apps_Template_Source;
use Agend_Apps_Templates;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/templates/interface-agend-apps-template-renderer.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/templates/interface-agend-apps-template-source.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/templates/class-agend-apps-templates.php';

/**
 * Stub renderer recording every call it receives.
 */
final class Template_Registry_Test_Renderer implements Agend_Apps_Template_Renderer {

	/** @var array<int, array<string, mixed>> */
	public array $render_calls = array();

	public bool $valid = true;

	public function is_valid_template( int $template_id ): bool {
		return $this->valid;
	}

	public function ensure_styles( int $template_id ): void {
		$this->render_calls[] = array( 'method' => 'ensure_styles', 'template_id' => $template_id );
	}

	public function render( int $template_id, string $type, array $record, array $extra = array(), bool $with_css = false ): string {
		$this->render_calls[] = array( 'method' => 'render', 'template_id' => $template_id );
		return 'rendered:' . $template_id;
	}

	public function render_plain( int $template_id, bool $with_css = false ): string {
		$this->render_calls[] = array( 'method' => 'render_plain', 'template_id' => $template_id );
		return 'plain:' . $template_id;
	}
}

/**
 * Stub source recording the placeholder it was asked for.
 */
final class Template_Registry_Test_Source implements Agend_Apps_Template_Source {

	public function options( string $placeholder = '' ): array {
		return array( '' => $placeholder, '12' => 'Card A' );
	}
}

/**
 * `Agend_Apps_Templates`: the renderer/source registry the framework-agnostic
 * layer depends on instead of a concrete page-builder plugin's classes.
 */
#[CoversClass( Agend_Apps_Templates::class )]
final class TemplateRegistryTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Agend_Apps_Templates::reset();
	}

	protected function tearDown(): void {
		Agend_Apps_Templates::reset();
		parent::tearDown();
	}

	#[Test]
	public function should_delegate_is_valid_template_to_the_registered_renderer(): void {
		$renderer         = new Template_Registry_Test_Renderer();
		$renderer->valid = false;
		Agend_Apps_Templates::set_renderer( $renderer );

		$this->assertFalse( Agend_Apps_Templates::is_valid_template( 5 ) );
	}

	#[Test]
	public function should_delegate_ensure_styles_to_the_registered_renderer(): void {
		$renderer = new Template_Registry_Test_Renderer();
		Agend_Apps_Templates::set_renderer( $renderer );

		Agend_Apps_Templates::ensure_styles( 5 );

		$this->assertSame(
			array( array( 'method' => 'ensure_styles', 'template_id' => 5 ) ),
			$renderer->render_calls
		);
	}

	#[Test]
	public function should_delegate_render_to_the_registered_renderer(): void {
		$renderer = new Template_Registry_Test_Renderer();
		Agend_Apps_Templates::set_renderer( $renderer );

		$html = Agend_Apps_Templates::render( 5, 'event', array( 'id' => 1 ) );

		$this->assertSame( 'rendered:5', $html );
	}

	#[Test]
	public function should_delegate_render_plain_to_the_registered_renderer(): void {
		$renderer = new Template_Registry_Test_Renderer();
		Agend_Apps_Templates::set_renderer( $renderer );

		$html = Agend_Apps_Templates::render_plain( 5 );

		$this->assertSame( 'plain:5', $html );
	}

	#[Test]
	public function should_delegate_options_to_the_registered_source(): void {
		Agend_Apps_Templates::set_source( new Template_Registry_Test_Source() );

		$options = Agend_Apps_Templates::options( 'Pick one' );

		$this->assertSame( array( '' => 'Pick one', '12' => 'Card A' ), $options );
	}

	#[Test]
	public function should_return_false_from_is_valid_template_when_no_renderer_is_registered(): void {
		$this->assertFalse( Agend_Apps_Templates::is_valid_template( 5 ) );
	}

	#[Test]
	public function should_no_op_ensure_styles_when_no_renderer_is_registered(): void {
		Agend_Apps_Templates::ensure_styles( 5 );

		$this->addToAssertionCount( 1 );
	}

	#[Test]
	public function should_return_empty_string_from_render_when_no_renderer_is_registered(): void {
		$this->assertSame( '', Agend_Apps_Templates::render( 5, 'event', array() ) );
	}

	#[Test]
	public function should_return_empty_string_from_render_plain_when_no_renderer_is_registered(): void {
		$this->assertSame( '', Agend_Apps_Templates::render_plain( 5 ) );
	}

	#[Test]
	public function should_return_placeholder_only_from_options_when_no_source_is_registered(): void {
		$this->assertSame( array( '' => 'Pick one' ), Agend_Apps_Templates::options( 'Pick one' ) );
	}

	#[Test]
	public function should_have_no_renderer_or_source_after_reset(): void {
		Agend_Apps_Templates::set_renderer( new Template_Registry_Test_Renderer() );
		Agend_Apps_Templates::set_source( new Template_Registry_Test_Source() );

		Agend_Apps_Templates::reset();

		$this->assertFalse( Agend_Apps_Templates::has_renderer() );
		$this->assertFalse( Agend_Apps_Templates::has_source() );
		$this->assertNull( Agend_Apps_Templates::renderer() );
		$this->assertNull( Agend_Apps_Templates::source() );
	}
}
