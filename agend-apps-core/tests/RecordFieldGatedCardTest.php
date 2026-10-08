<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend_Apps_Records_Record_Context;
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
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/format.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/fields.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/record-context.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/preview-records.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/record-field.php';

/**
 * Stands in for an editor's template renderer: like the Elementor and block
 * adapters it pushes the record into {@see Agend_Apps_Records_Record_Context}
 * and renders one Agend Field against it, so the card path is exercised
 * through the same core renderer a real card template reaches.
 */
final class Gated_Card_Test_Renderer implements Agend_Apps_Template_Renderer {

	/**
	 * Settings of the one Agend Field the card template holds.
	 *
	 * @var array
	 */
	public array $field_settings = array();

	public function is_valid_template( int $template_id ): bool {
		return true;
	}

	public function ensure_styles( int $template_id ): void {}

	public function render( int $template_id, string $type, array $record, array $extra = array(), bool $with_css = false ): string {
		Agend_Apps_Records_Record_Context::push( $type, $record, $extra );
		try {
			return agend_apps_records_render_record_field( $this->field_settings );
		} finally {
			Agend_Apps_Records_Record_Context::pop();
		}
	}

	public function render_plain( int $template_id, bool $with_css = false ): string {
		return '';
	}
}

/**
 * A templated catalogue card holding an Agend Field set to a custom field
 * tells a restricted listing from one with no data for that field. Both the
 * first-page render and the REST card fragments go through
 * agend_apps_records_render_cards(), so one test covers both.
 */
#[CoversFunction( 'agend_apps_records_render_cards' )]
final class RecordFieldGatedCardTest extends TestCase {

	private Gated_Card_Test_Renderer $renderer;

	protected function setUp(): void {
		parent::setUp();
		Agend_Apps_Records_Record_Context::reset();
		Agend_Apps_Templates::reset();
		$this->renderer = new Gated_Card_Test_Renderer();
		Agend_Apps_Templates::register_renderer( $this->renderer );
	}

	protected function tearDown(): void {
		Agend_Apps_Templates::reset();
		Agend_Apps_Records_Record_Context::reset();
		parent::tearDown();
	}

	#[Test]
	public function should_tell_a_restricted_listing_from_an_empty_one_on_a_card(): void {
		$this->renderer->field_settings = array(
			'field'            => 'common:custom_field',
			'custom_field_key' => 'sco_subregion',
			'fallback_text'    => 'Not recorded',
		);

		$records = array(
			array(
				'slug'          => 'restricted',
				'custom_fields' => array(
					array( 'key' => 'sco_subregion', 'label' => 'SCD-Sub Region', 'mode' => 'selected_entitlements', 'state' => 'access_restricted', 'gated' => true ),
				),
			),
			array(
				'slug'          => 'empty',
				'custom_fields' => array(),
			),
			array(
				'slug'          => 'visible',
				'custom_fields' => array(
					array( 'key' => 'sco_subregion', 'label' => 'SCD-Sub Region', 'type' => 'text', 'value' => 'North' ),
				),
			),
		);

		$cards = agend_apps_records_render_cards( 'listing', 5, $records, array( 'card_link_whole' => false ) );

		$this->assertCount( 3, $cards );
		$this->assertStringContainsString( 'agend-field--gated', $cards[0]['html'] );
		$this->assertStringContainsString( '>Restricted</span>', $cards[0]['html'] );
		$this->assertStringNotContainsString( 'Not recorded', $cards[0]['html'] );

		$this->assertStringContainsString( 'Not recorded', $cards[1]['html'] );
		$this->assertStringNotContainsString( 'agend-field--gated', $cards[1]['html'] );

		$this->assertStringContainsString( '>North</div>', $cards[2]['html'] );
		$this->assertStringNotContainsString( 'agend-field--gated', $cards[2]['html'] );
	}
}
