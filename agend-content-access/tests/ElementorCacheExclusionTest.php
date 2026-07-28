<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Elementor;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-policy.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-catalogue.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-decision.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-frontend.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-elementor.php';

/**
 * Keeping policy-bearing elements out of Elementor's frozen-HTML cache.
 *
 * Elementor's element cache re-renders a document once and stores the result in
 * post meta: one blob, 24 hour TTL, shared by every visitor. Elements that are
 * neither dynamic nor opted in are baked into that blob as finished markup, and
 * finished markup never runs `should_render` again.
 *
 * So if a member is the one who happens to build the cache, the restricted
 * region is frozen into it and served to anonymous visitors until the TTL
 * expires. Reproduced on Elementor 4.2.0 before this was fixed.
 *
 * Declaring a policy-bearing element dynamic forces the per-request shortcode
 * path instead. These tests pin the predicate that decides that, because the
 * failure is silent: everything looks correct until the wrong visitor warms the
 * cache first.
 */
#[CoversClass( Agend_Content_Access_Elementor::class )]
final class ElementorCacheExclusionTest extends TestCase {

	private const TIER_A = '11111111-1111-1111-1111-111111111111';

	private function widget( string $id, array $settings = array() ): array {
		return array(
			'id'         => $id,
			'elType'     => 'widget',
			'widgetType' => 'text-editor',
			'settings'   => $settings,
		);
	}

	private function section( string $id, array $children, array $settings = array() ): array {
		return array(
			'id'       => $id,
			'elType'   => 'section',
			'settings' => $settings,
			'elements' => $children,
		);
	}

	#[Test]
	public function an_element_with_no_policy_is_left_cacheable(): void {
		$node = $this->section( 's1', array( $this->widget( 'w1', array( 'editor' => 'hello' ) ) ) );

		$this->assertFalse( Agend_Content_Access_Elementor::subtree_carries_policy( $node ) );
	}

	#[Test]
	public function an_element_with_its_own_policy_is_excluded(): void {
		$node = $this->section( 's1', array(), array( 'agend_access_mode' => 'active_member' ) );

		$this->assertTrue( Agend_Content_Access_Elementor::subtree_carries_policy( $node ) );
	}

	/**
	 * The case that makes a shallow check unsafe. An unrestricted static section
	 * holding a restricted widget would be frozen WHOLE, taking the widget's
	 * rendered state with it, so the leak simply moves one level down.
	 */
	#[Test]
	public function a_policy_on_a_descendant_excludes_the_whole_subtree(): void {
		$node = $this->section(
			's1',
			array(
				$this->widget( 'plain', array( 'editor' => 'public' ) ),
				$this->widget( 'restricted', array( 'agend_access_mode' => 'active_member' ) ),
			)
		);

		$this->assertTrue( Agend_Content_Access_Elementor::subtree_carries_policy( $node ) );
	}

	#[Test]
	public function a_policy_nested_several_levels_deep_still_excludes(): void {
		$node = $this->section(
			's1',
			array(
				array(
					'id'       => 'c1',
					'elType'   => 'column',
					'settings' => array(),
					'elements' => array(
						$this->section(
							's2',
							array( $this->widget( 'deep', array( 'agend_access_mode' => 'selected_tiers', 'agend_access_tier_ids' => array( self::TIER_A ) ) ) )
						),
					),
				),
			)
		);

		$this->assertTrue( Agend_Content_Access_Elementor::subtree_carries_policy( $node ) );
	}

	/**
	 * `inherit` is the explicit opt-out, so it carries no restriction of its own
	 * and must not force every element on a page out of the cache.
	 */
	#[Test]
	public function an_inherit_policy_does_not_exclude(): void {
		$node = $this->section( 's1', array(), array( 'agend_access_mode' => 'inherit' ) );

		$this->assertFalse( Agend_Content_Access_Elementor::subtree_carries_policy( $node ) );
	}

	#[Test]
	public function the_filter_preserves_an_existing_dynamic_verdict(): void {
		$elementor = new Agend_Content_Access_Elementor();
		$plain     = $this->section( 's1', array() );

		// Elementor already decided this is dynamic for its own reasons; we never
		// downgrade that, only ever add exclusions.
		$this->assertTrue( $elementor->policy_is_dynamic_content( true, $plain ) );
		$this->assertFalse( $elementor->policy_is_dynamic_content( false, $plain ) );
	}

	#[Test]
	public function the_filter_excludes_a_restricted_element(): void {
		$elementor  = new Agend_Content_Access_Elementor();
		$restricted = $this->section( 's1', array(), array( 'agend_access_mode' => 'active_member' ) );

		$this->assertTrue( $elementor->policy_is_dynamic_content( false, $restricted ) );
	}

	/**
	 * Malformed raw data must not throw during a render pass.
	 */
	#[Test]
	public function malformed_raw_data_is_tolerated(): void {
		$elementor = new Agend_Content_Access_Elementor();

		$this->assertFalse( $elementor->policy_is_dynamic_content( false, array() ) );
		$this->assertFalse( $elementor->policy_is_dynamic_content( false, 'not an array' ) );
		$this->assertFalse(
			Agend_Content_Access_Elementor::subtree_carries_policy(
				array( 'settings' => 'not an array', 'elements' => 'not an array' )
			)
		);
	}

	/**
	 * Display conditions personalise per visitor just as much as a policy
	 * restricts per visitor, so they must leave the shared cache too.
	 *
	 * Missed on the first pass, because US-4.4 predates the condition control.
	 * Found by loading a conditioned page as a member on wdaa.test and being
	 * served the anonymous render. The reverse is the dangerous direction: a
	 * member-warmed cache would show member-only sections to anonymous
	 * visitors.
	 */
	#[Test]
	public function an_element_with_display_conditions_is_excluded(): void {
		$node = $this->section(
			's1',
			array(),
			array(
				'agend_conditions_enabled' => 'yes',
				'agend_conditions'         => array( 'agend_wordpress:logged_in' ),
			)
		);

		$this->assertTrue( Agend_Content_Access_Elementor::subtree_carries_policy( $node ) );
	}

	#[Test]
	public function conditions_switched_off_leave_the_element_cacheable(): void {
		$node = $this->section(
			's1',
			array(),
			array(
				'agend_conditions_enabled' => '',
				'agend_conditions'         => array( 'agend_wordpress:logged_in' ),
			)
		);

		$this->assertFalse( Agend_Content_Access_Elementor::subtree_carries_policy( $node ) );
	}

	#[Test]
	public function conditions_on_a_DESCENDANT_also_exclude_the_subtree(): void {
		$node = $this->section(
			's1',
			array(
				$this->widget( 'plain', array( 'editor' => 'public' ) ),
				$this->widget(
					'conditioned',
					array(
						'agend_conditions_enabled' => 'yes',
						'agend_conditions'         => array( 'agend_membership:is_member' ),
					)
				),
			)
		);

		$this->assertTrue( Agend_Content_Access_Elementor::subtree_carries_policy( $node ) );
	}
}
