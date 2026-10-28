<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Decision;
use Agend_Content_Access_Frontend;
use Agend_Content_Access_Policy;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use WP_Query;

require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-policy.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-catalogue.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-meta-box.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-decision.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-frontend.php';

/**
 * The comments tables, as far as the comment feeds read them: the posts that
 * store a policy and have an approved comment. Anything else the real query does
 * is not modelled, so a test of the SQL reads `$queries`.
 */
final class CommentedPoliciesDouble extends \wpdb {

	/** @var int[] Posts with at least one approved comment. */
	public array $commented = array();

	/** @var bool Whether the next query fails, as a lost connection does. */
	public bool $fails = false;

	public function get_col( string $query ): array {
		$this->queries[] = $query;

		if ( $this->fails ) {
			$this->last_error = 'MySQL server has gone away';

			return array();
		}

		$this->last_error = '';

		preg_match( "/meta_key = '(.*?)'/", $query, $key );

		$ids = array();

		foreach ( $GLOBALS['agend_test_post_meta'] as $post_id => $meta ) {
			if ( in_array( $post_id, $this->commented, true ) && array_key_exists( stripslashes( $key[1] ?? '' ), $meta ) ) {
				$ids[] = (string) $post_id;
			}
		}

		return $ids;
	}
}

/**
 * The site-wide and archive comment feeds do not serve comments on posts the
 * reader is denied.
 *
 * `/comments/feed/`, a search's comment feed and an archive's comment feed are
 * built by `WP_Query::get_posts()` with SQL of its own. No `WP_Comment_Query`
 * runs, so the `the_comments` exclusion never sees them, and the feed template
 * printed each comment's text and the title of the post it was on. The WHERE
 * clause is the one thing core offers them, so that is where the restricted
 * posts are taken out, as an anonymous reader, since a feed is fetched without a
 * session and shared.
 */
#[CoversClass( Agend_Content_Access_Frontend::class )]
final class CommentFeedTest extends TestCase {

	private const TIER_A = '11111111-1111-1111-1111-111111111111';

	/** Plan A only. */
	private const A_ONLY = 91;
	/** Members only. */
	private const MEMBERS = 92;
	private const PUBLIC_POST = 93;
	private const NO_POLICY   = 94;
	/** A policy this release cannot read, which restricts to nobody. */
	private const UNREADABLE = 95;
	/** Restricted, and nobody has commented on it. */
	private const QUIET = 96;
	/** Restricted and public in turn, for the cache tests. */
	private const SWITCHED = 97;

	/** The WHERE clause core hands a search's or an archive's comment feed. */
	private const CORE_WHERE = "WHERE comment_approved = '1' AND wp_posts.post_status = 'publish'";

	/** @var int Calls to the bearer lookup: the identity lookup a feed must never cause. */
	private int $identity_lookups = 0;

	private CommentedPoliciesDouble $db;

	protected function setUp(): void {
		parent::setUp();

		$this->identity_lookups = 0;

		$this->registerPost( self::A_ONLY, array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A ) ) );
		$this->registerPost( self::MEMBERS, array( 'mode' => 'active_member' ) );
		$this->registerPost( self::PUBLIC_POST, array( 'mode' => 'public' ) );
		$this->registerPost( self::NO_POLICY, null );
		$this->registerPost( self::UNREADABLE, array( 'mode' => 'segments' ) );
		$this->registerPost( self::QUIET, array( 'mode' => 'active_member' ) );
		$this->registerPost( self::SWITCHED, array( 'mode' => 'active_member' ) );

		$this->db            = new CommentedPoliciesDouble();
		$this->db->commented = array( self::A_ONLY, self::MEMBERS, self::PUBLIC_POST, self::NO_POLICY, self::UNREADABLE, self::SWITCHED );

		$GLOBALS['wpdb'] = $this->db;

		$this->become( 'anonymous' );
	}

	// -----------------------------------------------------------------------
	// Fixtures
	// -----------------------------------------------------------------------

	private function registerPost( int $id, ?array $policy ): void {
		$GLOBALS['agend_test_posts'][ $id ] = array(
			'ID'          => $id,
			'post_status' => 'publish',
			'post_type'   => 'post',
			'post_title'  => 'Post ' . $id,
		);

		if ( null !== $policy ) {
			$GLOBALS['agend_test_post_meta'][ $id ] = array( Agend_Content_Access_Policy::META_KEY => $policy );
		}
	}

	/** The visitor: one of the four that matter, as `CommentExposureTest` has them. */
	private function become( string $viewer ): void {
		$GLOBALS['agend_test_current_user_can']['edit_post'] = ( 'editor' === $viewer );

		Agend_Test_WP::$filters['agend_apps_bearer_token'] = function () use ( $viewer ): string {
			++$this->identity_lookups;

			return in_array( $viewer, array( 'wrong_plan', 'right_plan' ), true ) ? 'member-token' : '';
		};

		Agend_Test_WP::set_filter(
			'agend_apps_crm_get_my_entitlements_response',
			array( 'data' => array( 'tier_ids' => 'right_plan' === $viewer ? array( self::TIER_A ) : array() ) )
		);

		Agend_Content_Access_Decision::reset_cache();
	}

	/** Runs the WHERE clause through the chain as core does for a feed that is not one post's. */
	private function feedWhere( string $where = self::CORE_WHERE, bool $singular = false ): string {
		new Agend_Content_Access_Frontend();

		$query              = new WP_Query();
		$query->is_singular = $singular;

		return (string) Agend_Test_WP::apply_registered( 'comment_feed_where', $where, $query );
	}

	/** @return int[] The posts a clause leaves out. */
	private function excludedBy( string $where ): array {
		if ( 1 !== preg_match( '/ AND wp_comments\.comment_post_ID NOT IN \( ([\d, ]+) \)$/', $where, $m ) ) {
			return array();
		}

		return array_map( 'intval', explode( ', ', $m[1] ) );
	}

	/** @return array<string, array{0: string}> */
	public static function viewers(): array {
		return array(
			'an anonymous visitor'       => array( 'anonymous' ),
			'a member on the wrong plan' => array( 'wrong_plan' ),
			'a member on the right plan' => array( 'right_plan' ),
			'an editor of the posts'     => array( 'editor' ),
		);
	}

	// -----------------------------------------------------------------------
	// What is left out
	// -----------------------------------------------------------------------

	#[Test]
	public function the_hook_is_registered_last_with_both_arguments(): void {
		new Agend_Content_Access_Frontend();

		$entries = Agend_Test_WP::$filter_registry['comment_feed_where'] ?? array();

		$this->assertCount( 1, $entries );
		$this->assertSame( 'withhold_comment_feed_on_denied_posts', $entries[0]['callback'][1] );
		$this->assertSame( PHP_INT_MAX, $entries[0]['priority'] );
		$this->assertSame( 2, $entries[0]['args'] );
	}

	#[Test]
	public function the_restricted_posts_with_comments_are_left_out_of_the_clause(): void {
		$where = $this->feedWhere();

		$this->assertStringStartsWith( self::CORE_WHERE, $where, "core's own clause is kept whole" );
		$this->assertEqualsCanonicalizing(
			array( self::A_ONLY, self::MEMBERS, self::UNREADABLE, self::SWITCHED ),
			$this->excludedBy( $where )
		);
	}

	#[Test]
	public function the_exclusion_is_qualified_with_the_comments_table_so_the_join_cannot_make_it_ambiguous(): void {
		$this->assertStringContainsString( ' AND wp_comments.comment_post_ID NOT IN ( ', $this->feedWhere() );
	}

	#[Test]
	public function a_public_a_policyless_and_a_commentless_post_are_not_left_out(): void {
		$excluded = $this->excludedBy( $this->feedWhere() );

		$this->assertNotContains( self::PUBLIC_POST, $excluded );
		$this->assertNotContains( self::NO_POLICY, $excluded );
		$this->assertNotContains( self::QUIET, $excluded, 'a restricted post nobody has commented on is never judged' );
	}

	#[Test]
	public function a_policy_this_release_cannot_read_is_a_denial(): void {
		$this->assertContains( self::UNREADABLE, $this->excludedBy( $this->feedWhere() ) );
	}

	#[Test]
	public function a_clause_core_wrote_for_the_front_page_feed_is_extended_the_same_way(): void {
		$where = $this->feedWhere( "WHERE ( post_status = 'publish' OR ( post_status = 'inherit' AND post_type = 'attachment' ) ) AND comment_approved = '1'" );

		$this->assertStringEndsWith( ' )', $where );
		$this->assertContains( self::A_ONLY, $this->excludedBy( $where ) );
	}

	#[Test]
	public function a_site_with_no_restricted_post_that_has_a_comment_changes_nothing(): void {
		$this->db->commented = array( self::PUBLIC_POST, self::NO_POLICY );

		$this->assertSame( self::CORE_WHERE, $this->feedWhere() );
	}

	// -----------------------------------------------------------------------
	// Judged as an anonymous reader, whoever fetched it
	// -----------------------------------------------------------------------

	/** A feed is shared. Nobody's standing, a member's or an editor's, widens what it prints. */
	#[Test]
	#[DataProvider( 'viewers' )]
	public function every_viewer_gets_the_clause_an_anonymous_reader_gets( string $viewer ): void {
		$this->become( 'anonymous' );
		$anonymous = $this->feedWhere();

		Agend_Test_WP::$filter_registry = array();
		$this->become( $viewer );

		$this->assertSame( $anonymous, $this->feedWhere() );
	}

	#[Test]
	#[DataProvider( 'viewers' )]
	public function building_the_clause_never_looks_an_identity_up( string $viewer ): void {
		$this->become( $viewer );

		$this->feedWhere();

		$this->assertSame( 0, $this->identity_lookups );
	}

	// -----------------------------------------------------------------------
	// Cost
	// -----------------------------------------------------------------------

	#[Test]
	public function the_candidates_are_found_in_one_query_that_joins_comments_to_the_policy_meta(): void {
		$this->feedWhere();

		$this->assertCount( 1, $this->db->queries );
		$this->assertStringContainsString( "meta_key = '" . Agend_Content_Access_Policy::META_KEY . "'", $this->db->queries[0] );
		$this->assertStringContainsString( 'INNER JOIN wp_comments', $this->db->queries[0] );
		$this->assertStringContainsString( "comment_approved = '1'", $this->db->queries[0] );
	}

	#[Test]
	public function the_answer_is_kept_for_the_request(): void {
		$frontend = new Agend_Content_Access_Frontend();
		$query    = new WP_Query();

		$first  = $frontend->withhold_comment_feed_on_denied_posts( self::CORE_WHERE, $query );
		$second = $frontend->withhold_comment_feed_on_denied_posts( self::CORE_WHERE, $query );

		$this->assertSame( $first, $second );
		$this->assertCount( 1, $this->db->queries );
	}

	#[Test]
	public function a_request_that_is_not_a_comment_feed_never_runs_the_query(): void {
		new Agend_Content_Access_Frontend();

		Agend_Test_WP::apply_registered( 'the_comments', array() );
		Agend_Test_WP::apply_registered( 'the_content', 'Body' );

		$this->assertSame( array(), $this->db->queries );
	}

	// -----------------------------------------------------------------------
	// Fails closed
	// -----------------------------------------------------------------------

	#[Test]
	public function a_failed_query_empties_the_feed(): void {
		$this->db->fails = true;

		$this->assertSame( self::CORE_WHERE . ' AND 0', $this->feedWhere() );
	}

	#[Test]
	public function a_missing_database_empties_the_feed(): void {
		$GLOBALS['wpdb'] = null;

		$this->assertSame( self::CORE_WHERE . ' AND 0', $this->feedWhere() );
	}

	// -----------------------------------------------------------------------
	// The post's own comment feed is somebody else's decision
	// -----------------------------------------------------------------------

	/** `gate_request()` judges it for the real viewer: refused, or served to the member it admits. */
	#[Test]
	public function a_single_posts_comment_feed_is_left_for_the_gate_to_judge(): void {
		$this->become( 'right_plan' );

		$own = "WHERE comment_post_ID = '" . self::A_ONLY . "' AND comment_approved = '1'";

		$this->assertSame( $own, $this->feedWhere( $own, true ) );
		$this->assertSame( array(), $this->db->queries );
	}

	// -----------------------------------------------------------------------
	// Core's cache
	// -----------------------------------------------------------------------

	/**
	 * Core keys the matched comment ids by the md5 of the SQL. The denied posts are
	 * in the SQL, so a post that becomes restricted, or public, changes the key and
	 * the old answer is not served.
	 */
	#[Test]
	public function a_change_of_policy_changes_the_sql_core_caches_by(): void {
		$restricted = $this->feedWhere();

		Agend_Test_WP::$filter_registry                        = array();
		$GLOBALS['agend_test_post_meta'][ self::SWITCHED ]     = array( Agend_Content_Access_Policy::META_KEY => array( 'mode' => 'public' ) );

		$opened = $this->feedWhere();

		$this->assertNotSame( md5( $restricted ), md5( $opened ) );
		$this->assertContains( self::SWITCHED, $this->excludedBy( $restricted ) );
		$this->assertNotContains( self::SWITCHED, $this->excludedBy( $opened ) );
	}
}
