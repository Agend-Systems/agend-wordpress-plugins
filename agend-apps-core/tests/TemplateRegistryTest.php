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
 * Stub renderer claiming a fixed set of template ids and recording its calls.
 */
final class Template_Registry_Test_Renderer implements Agend_Apps_Template_Renderer {

	/** @var array<int, array<string, mixed>> */
	public array $calls = array();

	/** @param int[] $owns Template ids this renderer claims. */
	public function __construct( private array $owns = array(), private string $name = 'stub' ) {}

	public function is_valid_template( int $template_id ): bool {
		return in_array( $template_id, $this->owns, true );
	}

	public function ensure_styles( int $template_id ): void {
		$this->calls[] = array( 'method' => 'ensure_styles', 'template_id' => $template_id );
	}

	public function render( int $template_id, string $type, array $record, array $extra = array(), bool $with_css = false ): string {
		$this->calls[] = array( 'method' => 'render', 'template_id' => $template_id );
		return $this->name . ':rendered:' . $template_id;
	}

	public function render_plain( int $template_id, bool $with_css = false ): string {
		$this->calls[] = array( 'method' => 'render_plain', 'template_id' => $template_id );
		return $this->name . ':plain:' . $template_id;
	}
}

/**
 * Stub source offering a fixed template map under a fixed builder label.
 */
final class Template_Registry_Test_Source implements Agend_Apps_Template_Source {

	/** @param array<string, string> $templates */
	public function __construct(
		private string $label = 'Stub',
		private array $templates = array(),
		private ?bool $page_surface_result = null
	) {}

	public function label(): string {
		return $this->label;
	}

	public function templates(): array {
		return $this->templates;
	}

	public function page_contains_surface( int $page_id, string $surface ): ?bool {
		return $this->page_surface_result;
	}
}

/**
 * `Agend_Apps_Templates`: the renderer/source registry the framework-agnostic
 * record layer depends on instead of a concrete page-builder plugin's classes.
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
	public function should_report_a_template_valid_when_a_registered_renderer_claims_it(): void {
		Agend_Apps_Templates::register_renderer( new Template_Registry_Test_Renderer( array( 5 ) ) );

		$this->assertTrue( Agend_Apps_Templates::is_valid_template( 5 ) );
	}

	#[Test]
	public function should_report_a_template_invalid_when_no_registered_renderer_claims_it(): void {
		Agend_Apps_Templates::register_renderer( new Template_Registry_Test_Renderer( array( 5 ) ) );

		$this->assertFalse( Agend_Apps_Templates::is_valid_template( 99 ) );
	}

	#[Test]
	public function should_route_a_render_to_the_renderer_that_claims_the_template(): void {
		$blocks = new Template_Registry_Test_Renderer( array( 5 ), 'blocks' );
		$elementor = new Template_Registry_Test_Renderer( array( 12 ), 'elementor' );
		Agend_Apps_Templates::register_renderer( $blocks );
		Agend_Apps_Templates::register_renderer( $elementor );

		$this->assertSame( 'blocks:rendered:5', Agend_Apps_Templates::render( 5, 'event', array() ) );
		$this->assertSame( 'elementor:rendered:12', Agend_Apps_Templates::render( 12, 'event', array() ) );
	}

	#[Test]
	public function should_route_ensure_styles_only_to_the_renderer_that_claims_the_template(): void {
		$blocks = new Template_Registry_Test_Renderer( array( 5 ), 'blocks' );
		$elementor = new Template_Registry_Test_Renderer( array( 12 ), 'elementor' );
		Agend_Apps_Templates::register_renderer( $blocks );
		Agend_Apps_Templates::register_renderer( $elementor );

		Agend_Apps_Templates::ensure_styles( 12 );

		$this->assertSame( array(), $blocks->calls );
		$this->assertSame(
			array( array( 'method' => 'ensure_styles', 'template_id' => 12 ) ),
			$elementor->calls
		);
	}

	#[Test]
	public function should_route_render_plain_to_the_renderer_that_claims_the_template(): void {
		Agend_Apps_Templates::register_renderer( new Template_Registry_Test_Renderer( array( 5 ), 'blocks' ) );
		Agend_Apps_Templates::register_renderer( new Template_Registry_Test_Renderer( array( 12 ), 'elementor' ) );

		$this->assertSame( 'elementor:plain:12', Agend_Apps_Templates::render_plain( 12 ) );
	}

	#[Test]
	public function should_resolve_to_the_first_registered_renderer_when_two_claim_the_same_template(): void {
		Agend_Apps_Templates::register_renderer( new Template_Registry_Test_Renderer( array( 5 ), 'first' ) );
		Agend_Apps_Templates::register_renderer( new Template_Registry_Test_Renderer( array( 5 ), 'second' ) );

		$this->assertSame( 'first:rendered:5', Agend_Apps_Templates::render( 5, 'event', array() ) );
	}

	#[Test]
	public function should_list_one_sources_templates_unqualified_when_it_is_the_only_source(): void {
		Agend_Apps_Templates::register_source(
			new Template_Registry_Test_Source( 'Block editor', array( '5' => 'Event card' ) )
		);

		$this->assertSame(
			array( '' => 'Pick one', '5' => 'Event card' ),
			Agend_Apps_Templates::options( 'Pick one' )
		);
	}

	#[Test]
	public function should_merge_and_label_every_sources_templates_when_more_than_one_is_registered(): void {
		Agend_Apps_Templates::register_source(
			new Template_Registry_Test_Source( 'Block editor', array( '5' => 'Event card' ) )
		);
		Agend_Apps_Templates::register_source(
			new Template_Registry_Test_Source( 'Elementor', array( '12' => 'Event card' ) )
		);

		$this->assertSame(
			array(
				''   => 'Pick one',
				'5'  => 'Event card (Block editor)',
				'12' => 'Event card (Elementor)',
			),
			Agend_Apps_Templates::options( 'Pick one' )
		);
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
	public function should_hold_no_renderers_or_sources_after_reset(): void {
		Agend_Apps_Templates::register_renderer( new Template_Registry_Test_Renderer( array( 5 ) ) );
		Agend_Apps_Templates::register_source( new Template_Registry_Test_Source() );

		Agend_Apps_Templates::reset();

		$this->assertFalse( Agend_Apps_Templates::has_renderer() );
		$this->assertFalse( Agend_Apps_Templates::has_source() );
		$this->assertSame( array(), Agend_Apps_Templates::renderers() );
		$this->assertSame( array(), Agend_Apps_Templates::sources() );
		$this->assertNull( Agend_Apps_Templates::renderer_for( 5 ) );
	}
}
