<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Blocks;
use Agend_Test_WP;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

require_once __DIR__ . '/BlockEnforcementTestCase.php';

/**
 * A block the visitor may not see is removed before it renders.
 *
 * Every case drives `do_blocks()`, which runs the `pre_render_block` chain the
 * way WordPress does and honours a non-null short-circuit. A test that called
 * the callback directly would pass whether or not WordPress ever skipped the
 * block's children, which is the property that matters most here.
 */
#[CoversClass( Agend_Content_Access_Blocks::class )]
final class BlockSuppressionTest extends BlockEnforcementTestCase {

	private const FALLBACK = 4242;

	// -----------------------------------------------------------------------
	// Registration
	// -----------------------------------------------------------------------

	/**
	 * Pinned because the failure is silent: an unregistered or late callback
	 * leaves every restricted block rendering to everyone. First, so a later
	 * callback that renders a block itself sees a denied one already as `''`.
	 * Three arguments, so the parent block reaches it.
	 */
	#[Test]
	public function the_pre_render_filter_runs_first_and_takes_the_parent_block(): void {
		$entry = Agend_Test_WP::$filter_registry['pre_render_block'][0];

		$this->assertSame( PHP_INT_MIN, $entry['priority'] );
		$this->assertSame( 3, $entry['args'] );
		$this->assertSame( array( $this->blocks, 'pre_render_block' ), $entry['callback'] );
	}

	/** Registered once, so a block is never judged twice and two copies cannot disagree. */
	#[Test]
	public function the_enforcement_is_registered_exactly_once(): void {
		$this->assertCount( 1, Agend_Test_WP::$filter_registry['pre_render_block'] );
	}

	/** A value an earlier callback already chose is not overridden. */
	#[Test]
	public function a_value_already_chosen_by_an_earlier_callback_is_returned_untouched(): void {
		$block = parse_blocks( $this->paragraph( 'SECRET', array( 'mode' => 'active_member' ) ) )[0];

		$this->assertSame( 'CHOSEN', $this->blocks->pre_render_block( 'CHOSEN', $block ) );
		$this->assertSame( '', $this->blocks->pre_render_block( '', $block ) );
	}

	// -----------------------------------------------------------------------
	// An audience policy on a block
	// -----------------------------------------------------------------------

	#[Test]
	public function a_members_only_paragraph_is_removed_and_its_siblings_render(): void {
		$html = do_blocks(
			$this->paragraph( 'BEFORE' )
			. $this->paragraph( 'SECRET', array( 'mode' => 'active_member' ) )
			. $this->paragraph( 'AFTER' )
		);

		$this->assertSame( '<p>BEFORE</p><p>AFTER</p>', $html );
	}

	#[Test]
	public function a_denied_group_removes_its_children(): void {
		$html = do_blocks(
			$this->paragraph( 'BEFORE' )
			. $this->group(
				array( 'mode' => 'active_member' ),
				$this->paragraph( 'CHILD ONE' ),
				$this->paragraph( 'CHILD TWO' )
			)
			. $this->paragraph( 'AFTER' )
		);

		$this->assertSame( '<p>BEFORE</p><p>AFTER</p>', $html );
	}

	/**
	 * The container short-circuit. Skipping the group must skip its children's
	 * RENDERING, not just their output: a dynamic child's callback may query,
	 * enqueue or call the gateway, and none of it may run for content the visitor
	 * cannot read.
	 */
	#[Test]
	public function a_denied_container_never_runs_its_children(): void {
		$ran = 0;

		register_block_type(
			'test/dynamic',
			array(
				'render_callback' => static function () use ( &$ran ): string {
					++$ran;

					return '<p>DYNAMIC SECRET</p>';
				},
			)
		);

		$html = do_blocks(
			$this->group( array( 'mode' => 'active_member' ), '<!-- wp:test/dynamic /-->' )
		);

		$this->assertSame( '', $html );
		$this->assertSame( 0, $ran, 'the child must never have been rendered' );
	}

	/** The same block, unrestricted, does run: the test above is not vacuous. */
	#[Test]
	public function the_same_dynamic_block_runs_when_nothing_restricts_it(): void {
		$ran = 0;

		register_block_type(
			'test/dynamic',
			array(
				'render_callback' => static function () use ( &$ran ): string {
					++$ran;

					return '<p>DYNAMIC</p>';
				},
			)
		);

		$html = do_blocks( $this->group( null, '<!-- wp:test/dynamic /-->' ) );

		$this->assertSame( '<div class="wp-block-group"><p>DYNAMIC</p></div>', $html );
		$this->assertSame( 1, $ran );
	}

	/** A restricted block nested inside a plain container is removed on its own. */
	#[Test]
	public function a_restricted_block_inside_a_plain_group_is_removed_alone(): void {
		$html = do_blocks(
			$this->group(
				null,
				$this->paragraph( 'KEPT' ),
				$this->paragraph( 'SECRET', array( 'mode' => 'active_member' ) )
			)
		);

		$this->assertSame( '<div class="wp-block-group"><p>KEPT</p></div>', $html );
	}

	#[Test]
	public function a_member_on_the_right_plan_sees_the_block(): void {
		$this->asMember( self::TIER_A );

		$html = do_blocks( $this->paragraph( 'SECRET', array( 'mode' => 'selected_tiers', 'tierIds' => array( self::TIER_A ) ) ) );

		$this->assertSame( '<p>SECRET</p>', $html );
	}

	#[Test]
	public function a_member_on_another_plan_does_not(): void {
		$this->asMember( self::TIER_B );

		$html = do_blocks( $this->paragraph( 'SECRET', array( 'mode' => 'selected_tiers', 'tierIds' => array( self::TIER_A ) ) ) );

		$this->assertSame( '', $html );
	}

	#[Test]
	public function an_anonymous_visitor_does_not_see_a_selected_plans_block(): void {
		$html = do_blocks( $this->paragraph( 'SECRET', array( 'mode' => 'selected_tiers', 'tierIds' => array( self::TIER_A ) ) ) );

		$this->assertSame( '', $html );
	}

	#[Test]
	public function any_active_member_sees_a_members_only_block(): void {
		$this->asMember( self::TIER_B );

		$this->assertSame( '<p>SECRET</p>', do_blocks( $this->paragraph( 'SECRET', array( 'mode' => 'active_member' ) ) ) );
	}

	#[Test]
	public function a_signed_in_non_member_does_not_see_a_members_only_block(): void {
		$this->asMember();

		$this->assertSame( '', do_blocks( $this->paragraph( 'SECRET', array( 'mode' => 'active_member' ) ) ) );
	}

	// -----------------------------------------------------------------------
	// Segments and entitlements
	// -----------------------------------------------------------------------

	#[Test]
	public function a_member_in_the_segment_sees_a_segments_block(): void {
		$this->asMemberOf( array( self::SEG_A ) );

		$html = do_blocks( $this->paragraph( 'SECRET', array( 'mode' => 'segments', 'segmentIds' => array( self::SEG_A ) ) ) );

		$this->assertSame( '<p>SECRET</p>', $html );
	}

	#[Test]
	public function a_member_outside_the_segment_does_not(): void {
		$this->asMemberOf( array( self::SEG_B ) );

		$html = do_blocks( $this->paragraph( 'SECRET', array( 'mode' => 'segments', 'segmentIds' => array( self::SEG_A ) ) ) );

		$this->assertSame( '', $html );
	}

	#[Test]
	public function an_anonymous_visitor_does_not_see_a_segments_block_and_no_segment_is_fetched(): void {
		$html = do_blocks( $this->paragraph( 'SECRET', array( 'mode' => 'segments', 'segmentIds' => array( self::SEG_A ) ) ) );

		$this->assertSame( '', $html );
		$this->assertSame( array(), Agend_Test_WP::$requests );
	}

	#[Test]
	public function a_member_holding_the_entitlement_sees_an_entitlements_block(): void {
		$this->asMemberOf( array(), array( self::ENT_A ) );

		$html = do_blocks( $this->paragraph( 'SECRET', array( 'mode' => 'selected_entitlements', 'entitlementKeys' => array( self::ENT_A ) ) ) );

		$this->assertSame( '<p>SECRET</p>', $html );
	}

	#[Test]
	public function a_member_without_the_entitlement_does_not(): void {
		$this->asMemberOf( array(), array( self::ENT_B ) );

		$html = do_blocks( $this->paragraph( 'SECRET', array( 'mode' => 'selected_entitlements', 'entitlementKeys' => array( self::ENT_A ) ) ) );

		$this->assertSame( '', $html );
	}

	#[Test]
	public function an_anonymous_visitor_does_not_see_an_entitlements_block(): void {
		$html = do_blocks( $this->paragraph( 'SECRET', array( 'mode' => 'selected_entitlements', 'entitlementKeys' => array( self::ENT_A ) ) ) );

		$this->assertSame( '', $html );
	}

	// -----------------------------------------------------------------------
	// Fail closed
	// -----------------------------------------------------------------------

	/** @return array<string, array{0: string}> */
	public static function unreadable_attributes(): array {
		return array(
			'unknown mode'                  => array( '{"agendAccess":{"mode":"tiers_only"}}' ),
			'mode that is not a string'     => array( '{"agendAccess":{"mode":5}}' ),
			'attribute that is a string'    => array( '{"agendAccess":"members"}' ),
			'attribute that is a number'    => array( '{"agendAccess":1}' ),
			'attribute that is a boolean'   => array( '{"agendAccess":true}' ),
			'attribute that is a list'      => array( '{"agendAccess":["active_member"]}' ),
			'plans with nothing selected'   => array( '{"agendAccess":{"mode":"selected_tiers","tierIds":[]}}' ),
			'plans with no list at all'     => array( '{"agendAccess":{"mode":"selected_tiers"}}' ),
			'plans with only junk selected' => array( '{"agendAccess":{"mode":"selected_tiers","tierIds":[5,null,"not-a-uuid"]}}' ),
			'segments with nothing'         => array( '{"agendAccess":{"mode":"segments","segmentIds":[]}}' ),
			'entitlements with nothing'     => array( '{"agendAccess":{"mode":"selected_entitlements","entitlementKeys":[]}}' ),
		);
	}

	/**
	 * Absent means "no restriction"; a value somebody wrote that cannot be read
	 * is not absence. Each of these is a restriction nobody satisfies, or at
	 * least members only, so an anonymous visitor never gets the block.
	 */
	#[Test]
	#[DataProvider( 'unreadable_attributes' )]
	public function an_attribute_that_cannot_be_read_denies_an_anonymous_visitor( string $attributes ): void {
		$html = do_blocks( $this->paragraph( 'BEFORE' ) . $this->rawAttribute( $attributes, 'SECRET' ) );

		$this->assertSame( '<p>BEFORE</p>', $html );
	}

	#[Test]
	#[DataProvider( 'unreadable_attributes' )]
	public function an_attribute_that_cannot_be_read_denies_a_signed_in_non_member( string $attributes ): void {
		$this->asMember();

		$this->assertSame( '', do_blocks( $this->rawAttribute( $attributes, 'SECRET' ) ) );
	}

	/** An empty selection is nobody, so not even a member on every plan gets in. */
	#[Test]
	public function an_empty_selection_denies_even_a_member(): void {
		$this->asMemberOf( array( self::SEG_A ), array( self::ENT_A ) );

		foreach ( array( 'selected_tiers' => 'tierIds', 'segments' => 'segmentIds', 'selected_entitlements' => 'entitlementKeys' ) as $mode => $list ) {
			$html = do_blocks( $this->paragraph( 'SECRET', array( 'mode' => $mode, $list => array() ) ) );

			$this->assertSame( '', $html, $mode );
		}
	}

	/** Anything that is not a grant is a denial: a check that blows up removes the block. */
	#[Test]
	public function a_failing_access_check_removes_the_block(): void {
		Agend_Test_WP::$filters['agend_apps_bearer_token'] = static function (): string {
			throw new \RuntimeException( 'the gateway client fell over' );
		};

		$html = do_blocks( $this->paragraph( 'BEFORE' ) . $this->paragraph( 'SECRET', array( 'mode' => 'active_member' ) ) );

		$this->assertSame( '<p>BEFORE</p>', $html );
	}

	#[Test]
	public function a_block_that_only_inherits_renders_for_everybody(): void {
		$html = do_blocks(
			$this->paragraph( 'A', array( 'mode' => 'inherit' ) )
			. $this->paragraph( 'B', array() )
			. $this->rawAttribute( '{"agendAccess":null}', 'C' )
		);

		$this->assertSame( '<p>A</p><p>B</p><p>C</p>', $html );
	}

	// -----------------------------------------------------------------------
	// The document policy is the policy of the post the block belongs to
	// -----------------------------------------------------------------------

	/**
	 * A restricted post's block, rendered in a loop on a public page, is judged
	 * against THAT post's policy. Read from the queried object it would be judged
	 * against the public page, so a member on the wrong plan would be handed the
	 * block, and the failure would look like a working page.
	 */
	#[Test]
	public function a_block_in_a_restricted_post_in_a_loop_is_judged_against_that_post(): void {
		$this->registerPost( 201, $this->paragraph( 'SECRET', array( 'mode' => 'active_member' ) ), array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_B ) ) );
		Agend_Test_WP::$queried_object_id = self::PUBLIC_PAGE;

		// A member, but not on the plan the post it lives in requires.
		$this->asMember( self::TIER_A );

		$this->assertSame( '', $this->renderPost( 201 ) );
	}

	#[Test]
	public function a_member_on_the_plan_the_posts_policy_requires_sees_its_block_in_a_loop(): void {
		$this->registerPost( 201, $this->paragraph( 'SECRET', array( 'mode' => 'active_member' ) ), array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_B ) ) );
		Agend_Test_WP::$queried_object_id = self::PUBLIC_PAGE;

		$this->asMember( self::TIER_B );

		$this->assertSame( '<p>SECRET</p>', $this->renderPost( 201 ) );
	}

	/** And the other way: a public post's block is not narrowed by the page it appears on. */
	#[Test]
	public function a_block_in_a_public_post_is_not_judged_against_the_restricted_page_it_appears_on(): void {
		$this->registerPost( 202, $this->paragraph( 'SECRET', array( 'mode' => 'active_member' ) ) );

		// The page being viewed needs plan B. The block lives in a public post.
		Agend_Test_WP::$queried_object_id = self::TIER_B_POST;
		$this->asMember( self::TIER_A );

		$this->assertSame( '<p>SECRET</p>', $this->renderPost( 202 ) );
	}

	/** The intersection narrows: a block cannot reveal what its document hides. */
	#[Test]
	public function a_block_cannot_widen_the_policy_of_its_post(): void {
		$this->registerPost( 203, $this->paragraph( 'SECRET', array( 'mode' => 'active_member' ) ), array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_B ) ) );

		$this->asMember( self::TIER_A );
		$this->assertSame( '', $this->renderPost( 203 ) );
	}

	/** Two disjoint plan lists intersect to nobody. */
	#[Test]
	public function a_block_on_one_plan_in_a_post_on_another_is_visible_to_nobody(): void {
		$this->registerPost(
			204,
			$this->paragraph( 'SECRET', array( 'mode' => 'selected_tiers', 'tierIds' => array( self::TIER_A ) ) ),
			array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_B ) )
		);

		$this->asMember( self::TIER_A, self::TIER_B );

		$this->assertSame( '', $this->renderPost( 204 ) );
	}

	/** A document policy this version cannot read is enforced as nobody, so the block goes too. */
	#[Test]
	public function a_block_in_a_post_whose_policy_is_unreadable_is_removed(): void {
		$this->registerPost( 205, $this->paragraph( 'SECRET', array( 'mode' => 'active_member' ) ), array( 'mode' => 'authenticated' ) );

		$this->asMember( self::TIER_A );

		$this->assertSame( '', $this->renderPost( 205 ) );
	}

	/** With no post in context the block's own policy still applies. */
	#[Test]
	public function a_block_outside_any_post_is_judged_on_its_own_policy(): void {
		unset( $GLOBALS['post'] );

		$this->assertSame( '', do_blocks( $this->paragraph( 'SECRET', array( 'mode' => 'active_member' ) ) ) );

		$this->asMember( self::TIER_A );

		$this->assertSame( '<p>SECRET</p>', do_blocks( $this->paragraph( 'SECRET', array( 'mode' => 'active_member' ) ) ) );
	}

	// -----------------------------------------------------------------------
	// Synced patterns
	// -----------------------------------------------------------------------

	#[Test]
	public function a_restricted_block_inside_a_synced_pattern_is_enforced(): void {
		$this->registerPattern(
			900,
			$this->paragraph( 'PATTERN PUBLIC' ) . $this->paragraph( 'PATTERN SECRET', array( 'mode' => 'active_member' ) )
		);
		$this->registerPost( 301, $this->paragraph( 'BEFORE' ) . $this->reference( 900 ) . $this->paragraph( 'AFTER' ) );

		$this->assertSame( '<p>BEFORE</p><p>PATTERN PUBLIC</p><p>AFTER</p>', $this->renderPost( 301 ) );

		$this->asMember( self::TIER_A );
		\Agend_Content_Access_Decision::reset_cache();

		$this->assertSame( '<p>BEFORE</p><p>PATTERN PUBLIC</p><p>PATTERN SECRET</p><p>AFTER</p>', $this->renderPost( 301 ) );
	}

	/** The reference itself can carry the restriction, and then the whole pattern goes. */
	#[Test]
	public function a_restricted_synced_pattern_reference_removes_the_whole_pattern(): void {
		$this->registerPattern( 901, $this->paragraph( 'PATTERN PUBLIC' ) );

		$html = do_blocks( '<!-- wp:block {"ref":901,"agendAccess":{"mode":"active_member"}} /-->' );

		$this->assertSame( '', $html );
	}

	// -----------------------------------------------------------------------
	// Display conditions
	// -----------------------------------------------------------------------

	/** The conditions as an editor stores them: signed-in only. */
	private function signedInOnly( array $over = array() ): array {
		return array_merge(
			array(
				'conditionsEnabled' => true,
				'conditions'        => array( 'agend_wordpress:logged_in' ),
			),
			$over
		);
	}

	private function publishFallback( ?array $policy = null, string $content = 'FALLBACK TEXT' ): void {
		$this->registerPost( self::FALLBACK, $content, $policy );
	}

	#[Test]
	public function a_block_whose_conditions_fail_is_removed(): void {
		$html = do_blocks( $this->paragraph( 'BEFORE' ) . $this->paragraph( 'MEMBERS NOTE', $this->signedInOnly() ) . $this->paragraph( 'AFTER' ) );

		$this->assertSame( '<p>BEFORE</p><p>AFTER</p>', $html );
	}

	#[Test]
	public function a_block_whose_conditions_pass_renders(): void {
		$GLOBALS['agend_test_current_user_id'] = 9;

		$this->assertSame( '<p>MEMBERS NOTE</p>', do_blocks( $this->paragraph( 'MEMBERS NOTE', $this->signedInOnly() ) ) );
	}

	#[Test]
	public function conditions_switched_on_with_none_chosen_render_for_everybody(): void {
		$html = do_blocks( $this->paragraph( 'NOTE', array( 'conditionsEnabled' => true, 'conditions' => array() ) ) );

		$this->assertSame( '<p>NOTE</p>', $html );
	}

	#[Test]
	public function conditions_that_are_switched_off_are_not_evaluated(): void {
		$html = do_blocks( $this->paragraph( 'NOTE', array( 'conditionsEnabled' => false, 'conditions' => array( 'agend_wordpress:logged_in' ) ) ) );

		$this->assertSame( '<p>NOTE</p>', $html );
	}

	#[Test]
	public function an_excluded_block_shows_a_public_fallback(): void {
		$this->publishFallback();

		$html = do_blocks( $this->paragraph( 'BEFORE' ) . $this->paragraph( 'MEMBERS NOTE', $this->signedInOnly( array( 'fallbackId' => self::FALLBACK ) ) ) . $this->paragraph( 'AFTER' ) );

		$this->assertSame( '<p>BEFORE</p>FALLBACK TEXT<p>AFTER</p>', $html );
	}

	/**
	 * The disclosure the reference implementation walks into: a restricted page
	 * picked as the fallback is shown to exactly the visitors who failed the
	 * check. It renders nothing here.
	 */
	#[Test]
	public function an_excluded_block_never_shows_a_restricted_fallback(): void {
		$this->publishFallback( array( 'mode' => 'active_member' ), 'MEMBERS ONLY FALLBACK' );

		$html = do_blocks( $this->paragraph( 'MEMBERS NOTE', $this->signedInOnly( array( 'fallbackId' => self::FALLBACK ) ) ) );

		$this->assertSame( '', $html );
	}

	#[Test]
	public function an_unpublished_or_missing_fallback_renders_nothing(): void {
		$this->publishFallback();
		$GLOBALS['agend_test_posts'][ self::FALLBACK ]['post_status'] = 'draft';

		$this->assertSame( '', do_blocks( $this->paragraph( 'NOTE', $this->signedInOnly( array( 'fallbackId' => self::FALLBACK ) ) ) ) );
		$this->assertSame( '', do_blocks( $this->paragraph( 'NOTE', $this->signedInOnly( array( 'fallbackId' => 999999 ) ) ) ) );
	}

	/** A block hidden by its POLICY gets no consolation render, only one the conditions excluded does. */
	#[Test]
	public function a_block_hidden_by_its_policy_gets_no_fallback(): void {
		$this->publishFallback();

		$html = do_blocks(
			$this->paragraph(
				'SECRET',
				array(
					'mode'              => 'active_member',
					'conditionsEnabled' => true,
					'conditions'        => array( 'agend_wordpress:logged_out' ),
					'fallbackId'        => self::FALLBACK,
				)
			)
		);

		$this->assertSame( '', $html );
	}

	/** The conditions pass, so the policy decides, and denies. */
	#[Test]
	public function conditions_that_pass_do_not_override_a_denying_policy(): void {
		$html = do_blocks(
			$this->paragraph(
				'SECRET',
				array(
					'mode'              => 'active_member',
					'conditionsEnabled' => true,
					'conditions'        => array( 'agend_wordpress:logged_out' ),
				)
			)
		);

		$this->assertSame( '', $html );
	}

	/** And the policy passing does not override conditions that fail. */
	#[Test]
	public function a_policy_that_passes_does_not_override_failing_conditions(): void {
		$this->asMember( self::TIER_A );

		$html = do_blocks( $this->paragraph( 'SECRET', $this->signedInOnly( array( 'mode' => 'active_member', 'conditions' => array( 'agend_wordpress:role_editor' ) ) ) ) );

		$this->assertSame( '', $html );
	}

	/**
	 * Inverting must not turn a rule that cannot be evaluated into a reveal: the
	 * provider is unknown (a plugin was deactivated), so the block is denied
	 * whichever way the result is read.
	 */
	#[Test]
	public function an_inverted_rule_on_an_unknown_provider_still_denies(): void {
		$html = do_blocks(
			$this->paragraph(
				'SECRET',
				array(
					'conditionsEnabled' => true,
					'conditions'        => array( 'plugin_gone:whatever' ),
					'conditionsInvert'  => true,
				)
			)
		);

		$this->assertSame( '', $html );
	}

	#[Test]
	public function a_malformed_condition_denies(): void {
		$html = do_blocks( $this->paragraph( 'SECRET', array( 'conditionsEnabled' => true, 'conditions' => array( 'no_colon_at_all', 5 ) ) ) );

		$this->assertSame( '', $html );
	}

	/**
	 * A fallback is itself rendered through `the_content`, so its blocks are
	 * enforced too, and one that names its own post as ITS fallback ends instead
	 * of recursing.
	 */
	#[Test]
	public function a_fallback_that_is_its_own_fallback_does_not_recurse(): void {
		$this->theContentRunsBlocks();
		$this->registerPost(
			self::FALLBACK,
			$this->paragraph( 'LOOP', $this->signedInOnly( array( 'fallbackId' => self::FALLBACK ) ) )
		);

		$html = do_blocks( $this->paragraph( 'NOTE', $this->signedInOnly( array( 'fallbackId' => self::FALLBACK ) ) ) );

		$this->assertSame( '', $html );
	}

	#[Test]
	public function the_blocks_inside_a_fallback_are_enforced(): void {
		$this->theContentRunsBlocks();
		$this->publishFallback( null, $this->paragraph( 'FALLBACK PUBLIC' ) . $this->paragraph( 'FALLBACK SECRET', array( 'mode' => 'active_member' ) ) );

		$html = do_blocks( $this->paragraph( 'NOTE', $this->signedInOnly( array( 'fallbackId' => self::FALLBACK ) ) ) );

		$this->assertSame( '<p>FALLBACK PUBLIC</p>', $html );
	}

	// -----------------------------------------------------------------------
	// Cost
	// -----------------------------------------------------------------------

	/**
	 * Nearly every block on every page is plain. Rendering a page of them must not
	 * ask who the visitor is: no bearer, no gateway call.
	 */
	#[Test]
	public function plain_blocks_cost_no_identity_lookup(): void {
		$content = '';

		for ( $i = 0; $i < 20; $i++ ) {
			$content .= $this->paragraph( 'PLAIN ' . $i );
		}

		$content .= $this->group( null, $this->paragraph( 'NESTED' ) )
			. $this->paragraph( 'INHERITS', array( 'mode' => 'inherit' ) )
			. $this->paragraph( 'EMPTY', array() );

		$called = 0;
		Agend_Test_WP::$filters['agend_apps_crm_get_my_entitlements_response'] = static function () use ( &$called ) {
			++$called;

			return array();
		};

		$html = do_blocks( $content );

		$this->assertStringContainsString( 'PLAIN 19', $html );
		$this->assertStringContainsString( 'NESTED', $html );
		$this->assertSame( 0, $this->identity_lookups, 'no bearer may be asked for' );
		$this->assertSame( 0, $called, 'the entitlements wrapper must never be called' );
		$this->assertSame( 0, $this->gatewayCalls( '/crm/me/entitlements' ) );
		$this->assertSame( array(), Agend_Test_WP::$requests );
	}

	/** The lookup is what a plain block avoids, so it has to happen for a restricted one. */
	#[Test]
	public function a_restricted_block_does_ask_who_the_visitor_is(): void {
		do_blocks( $this->paragraph( 'SECRET', array( 'mode' => 'active_member' ) ) );

		$this->assertGreaterThan( 0, $this->identity_lookups );
	}

	/** The standing is resolved once however many restricted blocks a page holds. */
	#[Test]
	public function many_restricted_blocks_ask_for_the_visitors_standing_once(): void {
		$this->asMember( self::TIER_A );

		$content = '';

		for ( $i = 0; $i < 10; $i++ ) {
			$content .= $this->paragraph( 'SECRET ' . $i, array( 'mode' => 'active_member' ) );
		}

		$html = do_blocks( $content );

		$this->assertSame( 10, substr_count( $html, '<p>SECRET' ) );
		$this->assertSame( 1, $this->gatewayCalls( '/crm/me/entitlements' ) );
		$this->assertSame( 1, $this->identity_lookups );
	}
}
