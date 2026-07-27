<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Elementor_Parser;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-policy.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-catalogue.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-meta-box.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-decision.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-frontend.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-elementor.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-elementor-parser.php';

/**
 * Flattening an Elementor document into ordered fragments.
 *
 * The property that matters: a policy anywhere on a node's ancestor chain must
 * reach the fragment. Losing one produces a fragment Agend believes is public,
 * and the projection then serves protected content it never knew to gate.
 */
#[CoversClass( Agend_Content_Access_Elementor_Parser::class )]
final class ElementorParserTest extends TestCase {

	private const TIER_A = '11111111-1111-1111-1111-111111111111';
	private const TIER_B = '22222222-2222-2222-2222-222222222222';

	private function widget( string $id, string $text, array $settings = array() ): array {
		return array(
			'id'         => $id,
			'elType'     => 'widget',
			'widgetType' => 'text-editor',
			'settings'   => array_merge( array( 'editor' => $text ), $settings ),
		);
	}

	private function node( string $type, string $id, array $children, array $settings = array() ): array {
		return array(
			'id'       => $id,
			'elType'   => $type,
			'settings' => $settings,
			'elements' => $children,
		);
	}

	private function parse( array $document ): array {
		return Agend_Content_Access_Elementor_Parser::parse( (string) json_encode( $document ) );
	}

	#[Test]
	public function a_flat_section_list_produces_ordered_fragments(): void {
		$result = $this->parse(
			array(
				$this->node( 'section', 's1', array( $this->node( 'column', 'c1', array( $this->widget( 'w1', 'one' ) ) ) ) ),
				$this->node( 'section', 's2', array( $this->node( 'column', 'c2', array( $this->widget( 'w2', 'two' ) ) ) ) ),
			)
		);

		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( array( 'w1', 'w2' ), array_column( $result['fragments'], 'id' ) );
		$this->assertSame( array( 0, 1 ), array_column( $result['fragments'], 'position' ) );
	}

	#[Test]
	public function structural_nodes_are_not_fragments(): void {
		$result = $this->parse(
			array( $this->node( 'container', 'k1', array( $this->widget( 'w1', 'only' ) ) ) )
		);

		// One widget in, one fragment out. The container itself is structure.
		$this->assertCount( 1, $result['fragments'] );
		$this->assertSame( 'w1', $result['fragments'][0]['id'] );
	}

	#[Test]
	public function a_widget_with_no_policy_inherits(): void {
		$result = $this->parse(
			array( $this->node( 'section', 's1', array( $this->widget( 'w1', 'x' ) ) ) )
		);

		$this->assertSame( array( 'mode' => 'inherit' ), $result['fragments'][0]['policy'] );
	}

	/**
	 * The core property. A section restricted to members must reach the widget
	 * inside it, or the projection would serve that widget to anybody.
	 */
	#[Test]
	public function an_ancestor_policy_folds_down_to_the_widget(): void {
		$result = $this->parse(
			array(
				$this->node(
					'section',
					's1',
					array( $this->node( 'column', 'c1', array( $this->widget( 'w1', 'secret' ) ) ) ),
					array( 'agend_access_mode' => 'active_member' )
				),
			)
		);

		$this->assertSame( array( 'mode' => 'active_member' ), $result['fragments'][0]['policy'] );
	}

	#[Test]
	public function a_widget_narrows_its_ancestor_but_cannot_widen_it(): void {
		$restricted = $this->node(
			'section',
			's1',
			array(
				$this->widget( 'narrower', 'a', array(
					'agend_access_mode'     => 'selected_tiers',
					'agend_access_tier_ids' => array( self::TIER_A ),
				) ),
				$this->widget( 'wider', 'b', array( 'agend_access_mode' => 'inherit' ) ),
			),
			array( 'agend_access_mode' => 'active_member' )
		);

		$result = $this->parse( array( $restricted ) );
		$byId   = array_column( $result['fragments'], 'policy', 'id' );

		$this->assertSame( 'selected_tiers', $byId['narrower']['mode'] );
		$this->assertSame( array( 'mode' => 'active_member' ), $byId['wider'] );
	}

	#[Test]
	public function nested_plan_policies_intersect_down_the_chain(): void {
		$result = $this->parse(
			array(
				$this->node(
					'section',
					's1',
					array(
						$this->widget( 'w1', 'x', array(
							'agend_access_mode'     => 'selected_tiers',
							'agend_access_tier_ids' => array( self::TIER_A, self::TIER_B ),
						) ),
					),
					array(
						'agend_access_mode'     => 'selected_tiers',
						'agend_access_tier_ids' => array( self::TIER_B ),
					)
				),
			)
		);

		$this->assertSame( array( self::TIER_B ), $result['fragments'][0]['policy']['tier_ids'] );
	}

	#[Test]
	public function a_legacy_section_column_layout_is_handled(): void {
		$result = $this->parse(
			array(
				$this->node(
					'section',
					's1',
					array(
						$this->node( 'column', 'c1', array( $this->widget( 'w1', 'left' ) ) ),
						$this->node( 'column', 'c2', array( $this->widget( 'w2', 'right' ) ) ),
					)
				),
			)
		);

		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( array( 'w1', 'w2' ), array_column( $result['fragments'], 'id' ) );
	}

	/**
	 * Skipping an unknown node is how a restricted region silently stops being
	 * represented, so it fails the revision instead.
	 */
	#[Test]
	public function an_unknown_node_type_fails_the_document(): void {
		$result = $this->parse(
			array( array( 'id' => 'x1', 'elType' => 'atomic-thing', 'settings' => array() ) )
		);

		$this->assertNotSame( array(), $result['errors'] );
		$this->assertStringContainsString( 'atomic-thing', $result['errors'][0] );
	}

	#[Test]
	public function a_node_with_no_type_fails_the_document(): void {
		$result = $this->parse( array( array( 'id' => 'x1', 'settings' => array() ) ) );

		$this->assertNotSame( array(), $result['errors'] );
	}

	#[Test]
	public function invalid_json_fails_the_document(): void {
		$result = Agend_Content_Access_Elementor_Parser::parse( 'not json' );

		$this->assertNotSame( array(), $result['errors'] );
		$this->assertSame( array(), $result['fragments'] );
	}

	#[Test]
	public function an_empty_document_is_not_an_error(): void {
		$result = Agend_Content_Access_Elementor_Parser::parse( '' );

		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( array(), $result['fragments'] );
	}

	#[Test]
	public function widget_text_is_extracted_and_the_type_recorded(): void {
		$result = $this->parse(
			array( $this->node( 'section', 's1', array( $this->widget( 'w1', 'hello' ) ) ) )
		);

		$this->assertSame( 'elementor:text-editor', $result['fragments'][0]['type'] );
		$this->assertSame( 'hello', $result['fragments'][0]['payload']['html'] );
		$this->assertTrue( $result['fragments'][0]['payload']['extracted'] );
	}

	/**
	 * A widget whose content this does not recognise still yields a fragment, so
	 * its POLICY survives. The failure mode is a region that renders empty, not
	 * one that renders to the wrong audience.
	 */
	#[Test]
	public function an_unrecognised_widget_still_carries_its_policy(): void {
		$result = $this->parse(
			array(
				$this->node(
					'section',
					's1',
					array(
						array(
							'id'         => 'w1',
							'elType'     => 'widget',
							'widgetType' => 'some-third-party-slider',
							'settings'   => array( 'slides' => array( 1, 2, 3 ) ),
						),
					),
					array( 'agend_access_mode' => 'active_member' )
				),
			)
		);

		$this->assertSame( array(), $result['errors'] );
		$this->assertFalse( $result['fragments'][0]['payload']['extracted'] );
		$this->assertSame( array( 'mode' => 'active_member' ), $result['fragments'][0]['policy'] );
	}

	#[Test]
	public function deeply_nested_documents_keep_render_order(): void {
		$result = $this->parse(
			array(
				$this->node(
					'container',
					'k1',
					array(
						$this->widget( 'w1', 'a' ),
						$this->node( 'container', 'k2', array( $this->widget( 'w2', 'b' ), $this->widget( 'w3', 'c' ) ) ),
						$this->widget( 'w4', 'd' ),
					)
				),
			)
		);

		$this->assertSame( array( 'w1', 'w2', 'w3', 'w4' ), array_column( $result['fragments'], 'id' ) );
	}
}
