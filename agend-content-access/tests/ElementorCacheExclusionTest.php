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
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-fragment-rules.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-catalogue.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-viewer-facts.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-decision.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-frontend.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-elementor.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-block-attributes.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-conditions.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-providers.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-runtime.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-rules.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-access.php';

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

		$this->assertFalse( Agend_Content_Access_Elementor::subtree_carries_rules( $node ) );
	}

	#[Test]
	public function an_element_with_its_own_policy_is_excluded(): void {
		$node = $this->section( 's1', array(), array( 'agend_access_mode' => 'active_member' ) );

		$this->assertTrue( Agend_Content_Access_Elementor::subtree_carries_rules( $node ) );
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

		$this->assertTrue( Agend_Content_Access_Elementor::subtree_carries_rules( $node ) );
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

		$this->assertTrue( Agend_Content_Access_Elementor::subtree_carries_rules( $node ) );
	}

	/**
	 * `inherit` is the explicit opt-out, so it carries no restriction of its own
	 * and must not force every element on a page out of the cache.
	 */
	#[Test]
	public function an_inherit_policy_does_not_exclude(): void {
		$node = $this->section( 's1', array(), array( 'agend_access_mode' => 'inherit' ) );

		$this->assertFalse( Agend_Content_Access_Elementor::subtree_carries_rules( $node ) );
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
			Agend_Content_Access_Elementor::subtree_carries_rules(
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

		$this->assertTrue( Agend_Content_Access_Elementor::subtree_carries_rules( $node ) );
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

		$this->assertFalse( Agend_Content_Access_Elementor::subtree_carries_rules( $node ) );
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

		$this->assertTrue( Agend_Content_Access_Elementor::subtree_carries_rules( $node ) );
	}

	// -----------------------------------------------------------------------
	// The rule list
	// -----------------------------------------------------------------------

	/**
	 * The new rule list personalises per visitor like everything above, so an element
	 * carrying one leaves the shared cache: a member-warmed copy would otherwise show
	 * a members-only section to anonymous visitors.
	 */
	#[Test]
	public function an_element_with_rules_is_excluded(): void {
		$node = $this->section( 's1', array(), array( 'agend_access_rules' => array( 'agend_membership:is_member' ) ) );

		$this->assertTrue( Agend_Content_Access_Elementor::subtree_carries_rules( $node ) );
		$this->assertTrue( ( new Agend_Content_Access_Elementor() )->policy_is_dynamic_content( false, $node ) );
	}

	#[Test]
	public function rules_with_match_and_reverse_set_are_excluded(): void {
		$node = $this->section(
			's1',
			array(),
			array(
				'agend_access_rules'  => array( 'agend_membership:is_member', 'agend_wordpress:logged_in' ),
				'agend_access_match'  => 'all',
				'agend_access_invert' => 'yes',
			)
		);

		$this->assertTrue( Agend_Content_Access_Elementor::subtree_carries_rules( $node ) );
	}

	/** A reverse over nothing is nobody, not "no rules", so it is not cached either. */
	#[Test]
	public function a_reverse_left_over_an_emptied_list_is_still_excluded(): void {
		$node = $this->section( 's1', array(), array( 'agend_access_rules' => array(), 'agend_access_invert' => 'yes' ) );

		$this->assertTrue( Agend_Content_Access_Elementor::subtree_carries_rules( $node ) );
	}

	/** The controls' defaults are not rules: an element nobody touched stays cacheable. */
	#[Test]
	public function an_empty_rule_list_leaves_the_element_cacheable(): void {
		$node = $this->section(
			's1',
			array( $this->widget( 'w1', array( 'editor' => 'hello' ) ) ),
			array( 'agend_access_rules' => array(), 'agend_access_match' => 'any', 'agend_access_invert' => '' )
		);

		$this->assertFalse( Agend_Content_Access_Elementor::subtree_carries_rules( $node ) );
	}

	#[Test]
	public function rules_on_a_descendant_exclude_the_whole_subtree(): void {
		$node = $this->section(
			's1',
			array(
				$this->widget( 'plain', array( 'editor' => 'public' ) ),
				$this->widget( 'ruled', array( 'agend_access_rules' => array( 'agend_membership:' . self::TIER_A ) ) ),
			)
		);

		$this->assertTrue( Agend_Content_Access_Elementor::subtree_carries_rules( $node ) );
	}

	/** An older setting is not forgotten because the new list is empty beside it. */
	#[Test]
	public function an_older_setting_beside_an_empty_rule_list_is_still_excluded(): void {
		$this->assertTrue(
			Agend_Content_Access_Elementor::subtree_carries_rules(
				$this->section( 's1', array(), array( 'agend_access_rules' => array(), 'agend_access_mode' => 'active_member' ) )
			)
		);
		$this->assertTrue(
			Agend_Content_Access_Elementor::subtree_carries_rules(
				$this->section( 's1', array(), array( 'agend_access_rules' => array(), 'agend_conditions_enabled' => 'yes', 'agend_conditions' => array( 'agend_wordpress:logged_in' ) ) )
			)
		);
	}

	/** Rules that cannot be read are rules: "I could not tell" is not "there are none". */
	#[Test]
	public function rules_that_cannot_be_read_are_excluded(): void {
		$node = $this->section( 's1', array(), array( 'agend_access_rules' => 'agend_membership:is_member' ) );

		$this->assertTrue( Agend_Content_Access_Elementor::subtree_carries_rules( $node ) );
	}
}
