<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Elementor;

use Agend_Apps_Template_Renderer;
use Agend_Apps_Templates;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/templates/interface-agend-apps-template-renderer.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/templates/interface-agend-apps-template-source.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/templates/class-agend-apps-templates.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/settings.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/pages.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/cards.php';

/**
 * A stub {@see Agend_Apps_Template_Renderer} with no Elementor class in
 * sight, standing in for a future non-Elementor presentation adapter.
 */
final class Cards_Agnostic_Test_Renderer implements Agend_Apps_Template_Renderer {

	/** @var array<int, array{type: string, record: array, extra: array}> */
	public array $render_calls = array();

	/** @var array<int, int> */
	public array $ensure_styles_calls = array();

	public function is_valid_template( int $template_id ): bool {
		return true;
	}

	public function ensure_styles( int $template_id ): void {
		$this->ensure_styles_calls[] = $template_id;
	}

	public function render( int $template_id, string $type, array $record, array $extra = array(), bool $with_css = false ): string {
		$this->render_calls[] = array(
			'type'   => $type,
			'record' => $record,
			'extra'  => $extra,
		);

		return '<p>' . $record['slug'] . '</p>';
	}

	public function render_plain( int $template_id, bool $with_css = false ): string {
		return '';
	}
}

/**
 * `agend_apps_records_render_cards()` proves the framework-agnostic call site
 * reaches a template renderer purely through the {@see Agend_Apps_Templates}
 * contract: this stub registers no Elementor class at all, which is exactly
 * what makes the seam real rather than aspirational.
 */
#[CoversFunction( 'agend_apps_records_render_cards' )]
final class CardsAgnosticTest extends TestCase {

	private Cards_Agnostic_Test_Renderer $renderer;

	protected function setUp(): void {
		parent::setUp();
		Agend_Apps_Templates::reset();
		$this->renderer = new Cards_Agnostic_Test_Renderer();
		Agend_Apps_Templates::register_renderer( $this->renderer );
	}

	protected function tearDown(): void {
		Agend_Apps_Templates::reset();
		parent::tearDown();
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function records(): array {
		return array(
			array( 'slug' => 'alpha' ),
			array( 'slug' => 'beta' ),
		);
	}

	#[Test]
	public function should_return_two_cards_in_order_with_the_right_slug_and_html(): void {
		$cards = agend_apps_records_render_cards( 'event', 5, $this->records() );

		$this->assertCount( 2, $cards );
		$this->assertSame( 'alpha', $cards[0]['slug'] );
		$this->assertStringContainsString( '<p>alpha</p>', $cards[0]['html'] );
		$this->assertSame( 'beta', $cards[1]['slug'] );
		$this->assertStringContainsString( '<p>beta</p>', $cards[1]['html'] );
	}

	private function seedHostPage( int $id ): void {
		$GLOBALS['agend_test_posts'][ $id ] = array(
			'ID'          => $id,
			'post_type'   => 'page',
			'post_status' => 'publish',
		);
	}

	#[Test]
	public function should_wrap_the_card_in_a_whole_link_anchor_when_card_link_whole_is_true(): void {
		$this->seedHostPage( 9 );

		$cards = agend_apps_records_render_cards( 'event', 5, $this->records(), array( 'card_link_whole' => true, 'host_page_id' => 9 ) );

		$this->assertStringStartsWith( '<a ', $cards[0]['html'] );
	}

	#[Test]
	public function should_wrap_the_card_in_a_div_when_card_link_whole_is_false(): void {
		$this->seedHostPage( 9 );

		$cards = agend_apps_records_render_cards( 'event', 5, $this->records(), array( 'card_link_whole' => false, 'host_page_id' => 9 ) );

		$this->assertStringStartsWith( '<div ', $cards[0]['html'] );
	}

	#[Test]
	public function should_pass_the_correct_per_record_extra_context_to_the_renderer(): void {
		agend_apps_records_render_cards( 'event', 5, $this->records(), array( 'card_link_whole' => false ) );

		$this->assertCount( 2, $this->renderer->render_calls );

		$first = $this->renderer->render_calls[0]['extra'];
		$this->assertSame( 'alpha', $first['slug'] );
		$this->assertSame( 0, $first['index'] );
		$this->assertFalse( $first['in_card_link'] );
		$this->assertFalse( $first['is_detail'] );

		$second = $this->renderer->render_calls[1]['extra'];
		$this->assertSame( 'beta', $second['slug'] );
		$this->assertSame( 1, $second['index'] );
		$this->assertFalse( $second['in_card_link'] );
		$this->assertFalse( $second['is_detail'] );
	}

	#[Test]
	public function should_call_ensure_styles_exactly_once_for_the_whole_batch(): void {
		agend_apps_records_render_cards( 'event', 5, $this->records() );

		$this->assertSame( array( 5 ), $this->renderer->ensure_styles_calls );
	}
}
