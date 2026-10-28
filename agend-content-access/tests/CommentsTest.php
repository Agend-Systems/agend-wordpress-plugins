<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Comments as Comments;
use Agend_Content_Access_Frontend;
use Agend_Content_Access_Rules as Rules;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use WP_Error;
use WP_REST_Request;

require_once __DIR__ . '/has-elementor-double.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-policy.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-catalogue.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-meta-box.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-viewer-facts.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-decision.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-frontend.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-comments.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-placeholder.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-destination.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-fragment-rules.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-block-attributes.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-conditions.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-providers.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-runtime.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-rules.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-access.php';

/** A page gate that throws where it would `exit`. */
final class CommentsGateFrontend extends Agend_Content_Access_Frontend {

	protected function terminate(): void {
		throw new RuntimeException( 'ended' );
	}

	protected static function send_header( string $line ): void {}
}

/** The one part of `WP_Comment_Query` the exclusion touches: its arguments. */
final class FakeCommentQuery {

	/** @var array<string, mixed> */
	public array $query_vars;

	/** @param array<string, mixed> $query_vars */
	public function __construct( array $query_vars = array() ) {
		$this->query_vars = $query_vars + array( 'post__in' => '', 'post__not_in' => '', 'post_id' => 0 );
	}
}

/**
 * Comments on a restricted page, for a viewer who is refused the page.
 *
 * The comments of a page are a second copy of what members said there. These tests
 * are about the comments a viewer must NOT be given: the denied page's own, the
 * REST route's, and those a comment query lists on any other page, such as a
 * recent-comments widget.
 */
#[CoversClass( Comments::class )]
final class CommentsTest extends TestCase {

	private const TIER_A = '11111111-1111-1111-1111-111111111111';
	private const TIER_B = '22222222-2222-2222-2222-222222222222';

	/** Members only. */
	private const MEMBERS = 100;

	/** A public page. */
	private const PUBLIC = 101;

	/** Plan B only. */
	private const PLAN_B = 102;

	protected function setUp(): void {
		parent::setUp();

		$this->page( self::MEMBERS, 'Members' );
		$this->page( self::PUBLIC, 'Public' );
		$this->page( self::PLAN_B, 'Plan B' );

		$GLOBALS['agend_test_post_meta'][ self::MEMBERS ][ Rules::META_KEY ] = array( 'rules' => array( 'agend_membership:is_member' ), 'match' => 'any', 'invert' => false );
		$GLOBALS['agend_test_post_meta'][ self::PLAN_B ][ Rules::META_KEY ]  = array( 'rules' => array( 'agend_membership:' . self::TIER_B ), 'match' => 'any', 'invert' => false );

		$GLOBALS['agend_test_posts_with_comments']           = array( self::MEMBERS, self::PUBLIC, self::PLAN_B );
		$GLOBALS['agend_test_comments']                      = array(
			9  => self::MEMBERS,
			10 => self::PUBLIC,
			11 => self::PLAN_B,
		);
		$GLOBALS['agend_test_current_user_can']['edit_post'] = false;

		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', '' );

		$_SERVER['REQUEST_URI'] = '/members/';

		new Comments();
	}

	protected function tearDown(): void {
		unset(
			$_SERVER['REQUEST_URI'],
			$GLOBALS['agend_test_is_singular'],
			$GLOBALS['agend_test_queried_object']
		);

		parent::tearDown();
	}

	private function page( int $id, string $title ): void {
		$GLOBALS['agend_test_posts'][ $id ] = array(
			'ID'           => $id,
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_title'   => $title,
			'post_content' => 'BODY OF ' . $title,
			'post_excerpt' => '',
		);
	}

	private function asMember( string ...$tiers ): void {
		$GLOBALS['agend_test_current_user_id'] = 7;

		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'member-token' );
		Agend_Test_WP::set_filter(
			'agend_apps_crm_get_my_entitlements_response',
			array( 'data' => array( 'is_member' => true, 'tier_ids' => $tiers ) )
		);
	}

	private function asEditor(): void {
		$GLOBALS['agend_test_current_user_id']               = 3;
		$GLOBALS['agend_test_current_user_can']['edit_post'] = true;
	}

	/** Runs the page gate for a page, as `template_redirect` does. */
	private function gate( int $id ): void {
		$frontend = new CommentsGateFrontend();

		Agend_Test_WP::$queried_object_id     = $id;
		$GLOBALS['agend_test_is_singular']    = true;
		$GLOBALS['agend_test_queried_object'] = get_post( $id );

		$frontend->gate_request();
	}

	/**
	 * Runs a comment query's `pre_get_comments`, as `WP_Comment_Query::get_comments()` does.
	 *
	 * @param array<string, mixed> $vars The query's arguments.
	 */
	private function query( array $vars = array() ): FakeCommentQuery {
		$query = new FakeCommentQuery( $vars );

		do_action( 'pre_get_comments', $query );

		return $query;
	}

	/** @return int[] */
	private function excluded( FakeCommentQuery $query ): array {
		$excluded = is_array( $query->query_vars['post__not_in'] ) ? $query->query_vars['post__not_in'] : array();
		sort( $excluded );

		return $excluded;
	}

	// -----------------------------------------------------------------------
	// The page the gate denied
	// -----------------------------------------------------------------------

	#[Test]
	public function a_denied_page_s_comments_are_closed_emptied_and_counted_as_none(): void {
		$this->gate( self::MEMBERS );

		$this->assertFalse( Agend_Test_WP::apply_registered( 'comments_open', true, self::MEMBERS ) );
		$this->assertFalse( Agend_Test_WP::apply_registered( 'pings_open', true, self::MEMBERS ) );
		$this->assertSame( array(), Agend_Test_WP::apply_registered( 'comments_array', array( 'a comment' ), self::MEMBERS ) );
		$this->assertSame( 0, Agend_Test_WP::apply_registered( 'get_comments_number', 12, self::MEMBERS ) );
	}

	/** Open comments elsewhere on the request, and the page's own when nothing denied it, are not touched. */
	#[Test]
	public function only_the_denied_page_is_closed(): void {
		$this->gate( self::MEMBERS );

		$this->assertTrue( Agend_Test_WP::apply_registered( 'comments_open', true, self::PUBLIC ) );
		$this->assertTrue( Agend_Test_WP::apply_registered( 'pings_open', true, self::PUBLIC ) );
		$this->assertSame( array( 'a comment' ), Agend_Test_WP::apply_registered( 'comments_array', array( 'a comment' ), self::PUBLIC ) );
		$this->assertSame( 12, Agend_Test_WP::apply_registered( 'get_comments_number', 12, self::PUBLIC ) );
	}

	#[Test]
	public function a_page_that_was_not_denied_keeps_its_comments(): void {
		$this->asMember( self::TIER_A );

		$this->gate( self::MEMBERS );

		$this->assertTrue( Agend_Test_WP::apply_registered( 'comments_open', true, self::MEMBERS ) );
		$this->assertSame( array( 'a comment' ), Agend_Test_WP::apply_registered( 'comments_array', array( 'a comment' ), self::MEMBERS ) );
		$this->assertSame( 12, Agend_Test_WP::apply_registered( 'get_comments_number', 12, self::MEMBERS ) );
	}

	/** On the live site an editor is a reader: the rules deny them, so the comments are closed. */
	#[Test]
	public function an_editor_who_fails_the_rules_has_the_denied_page_s_comments_closed(): void {
		$this->asEditor();

		$this->gate( self::MEMBERS );

		$this->assertFalse( Agend_Test_WP::apply_registered( 'comments_open', true, self::MEMBERS ) );
		$this->assertSame( array(), Agend_Test_WP::apply_registered( 'comments_array', array( 'a comment' ), self::MEMBERS ) );
	}

	/** In an editing session for the page nothing is denied, so nothing is closed. */
	#[Test]
	public function an_editor_in_an_editing_session_keeps_the_page_s_comments_open(): void {
		$this->asEditor();
		$GLOBALS['agend_test_is_admin'] = true;

		$this->gate( self::MEMBERS );

		$this->assertTrue( Agend_Test_WP::apply_registered( 'comments_open', true, self::MEMBERS ) );
	}

	#[Test]
	public function nothing_is_closed_before_a_page_has_been_denied(): void {
		$this->assertTrue( Agend_Test_WP::apply_registered( 'comments_open', true, self::MEMBERS ) );
	}

	// -----------------------------------------------------------------------
	// Comment queries: widgets, feeds, blocks, REST
	// -----------------------------------------------------------------------

	#[Test]
	public function a_comment_query_leaves_out_the_posts_the_visitor_is_denied(): void {
		$query = $this->query();

		$this->assertSame( array( self::MEMBERS, self::PLAN_B ), $this->excluded( $query ) );
	}

	/** The REST collection with `post=<id>` reaches the same query: the post is still left out. */
	#[Test]
	public function a_query_for_one_denied_post_is_left_with_nothing_to_return(): void {
		$query = $this->query( array( 'post_id' => self::MEMBERS ) );

		$this->assertContains( self::MEMBERS, $this->excluded( $query ), '`comment_post_ID = 100 AND comment_post_ID NOT IN ( 100, 102 )` matches nothing' );
	}

	#[Test]
	public function a_member_keeps_the_comments_of_the_pages_their_rules_admit(): void {
		$this->asMember( self::TIER_A );

		$query = $this->query();

		$this->assertSame( array( self::PLAN_B ), $this->excluded( $query ) );
	}

	/** The exclusion is part of the arguments, which is what `WP_Comment_Query` builds its cache key from. */
	#[Test]
	public function the_exclusion_is_made_in_the_query_s_arguments_and_not_its_sql(): void {
		$this->assertSame( array(), Agend_Test_WP::$filter_registry['comments_clauses'] ?? array() );

		$query = $this->query();

		$this->assertNotSame( '', $query->query_vars['post__not_in'], 'two viewers with different denied sets do not share a cached result' );
		$this->assertNotSame( $this->excluded( $this->query() ), $this->excluded( $this->asMemberQuery() ) );
	}

	/** A member's query, in a request of its own. */
	private function asMemberQuery(): FakeCommentQuery {
		Comments::reset();
		\Agend_Content_Access_Decision::reset_cache();
		$this->asMember( self::TIER_A );

		return $this->query();
	}

	/** On the live site a user who may edit the post is judged like any reader. */
	#[Test]
	public function a_user_who_may_edit_the_post_is_judged_by_their_own_membership_on_the_live_site(): void {
		$this->asEditor();

		$this->assertSame( array( self::MEMBERS, self::PLAN_B ), $this->excluded( $this->query() ) );
	}

	/** Their own screens for a post they may edit, a wp-admin comment list, keep its comments. */
	#[Test]
	public function a_user_who_may_edit_the_post_keeps_its_comments_in_wp_admin(): void {
		$this->asEditor();
		$GLOBALS['agend_test_is_admin'] = true;

		$this->assertSame( array(), $this->excluded( $this->query() ) );
	}

	/** An admin-ajax request is a visitor's, whatever screen it came from. */
	#[Test]
	public function an_editor_s_admin_ajax_request_is_a_reader_s(): void {
		$this->asEditor();
		$GLOBALS['agend_test_is_admin']   = true;
		$GLOBALS['agend_test_doing_ajax'] = true;

		$this->assertSame( array( self::MEMBERS, self::PLAN_B ), $this->excluded( $this->query() ) );
	}

	/** A feed is shared and cached, and is judged as an anonymous reader whoever fetched it. */
	#[Test]
	public function a_feed_is_judged_as_an_anonymous_reader(): void {
		$this->asEditor();

		$GLOBALS['agend_test_is_feed'] = true;

		$this->assertSame( array( self::MEMBERS, self::PLAN_B ), $this->excluded( $this->query() ) );
	}

	#[Test]
	public function exclusions_a_query_already_has_are_kept(): void {
		$this->assertSame( array( 5, self::MEMBERS, self::PLAN_B ), $this->excluded( $this->query( array( 'post__not_in' => array( 5 ) ) ) ) );

		Comments::reset();

		$this->assertSame( array( 5, 6, self::MEMBERS, self::PLAN_B ), $this->excluded( $this->query( array( 'post__not_in' => '5, 6' ) ) ) );
	}

	/** A site whose restricted pages have no comments pays one query and no decisions. */
	#[Test]
	public function a_restricted_post_with_no_comments_costs_no_decision(): void {
		$GLOBALS['agend_test_posts_with_comments'] = array( self::PUBLIC );
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'member-token' );
		$GLOBALS['agend_test_current_user_id'] = 7;

		$query = $this->query();

		$this->assertSame( array(), $this->excluded( $query ) );
		$this->assertSame( array(), Agend_Test_WP::$requests, 'no lookup of who the visitor is' );
	}

	#[Test]
	public function the_list_is_made_once_for_the_request(): void {
		$this->query();
		$queries = count( $GLOBALS['wpdb']->queries );

		$this->query();
		$this->query();

		$this->assertSame( $queries, count( $GLOBALS['wpdb']->queries ) );
	}

	/** If the list cannot be made the query matches nothing, not everything. */
	#[Test]
	public function a_query_fails_closed_when_the_restricted_posts_cannot_be_listed(): void {
		$GLOBALS['wpdb']->last_error = 'Lost connection to MySQL server';

		$query = $this->query();

		$this->assertSame( array( 0 ), $query->query_vars['post__in'] );
	}

	#[Test]
	public function something_that_is_not_a_query_is_left_alone(): void {
		Comments::reset();

		( new Comments() )->exclude_denied_posts( 'not a query' );
		( new Comments() )->exclude_denied_posts( (object) array() );

		$this->assertSame( array(), $GLOBALS['wpdb']->queries, 'nothing was asked' );
	}

	// -----------------------------------------------------------------------
	// The global comment feed, which builds its own SQL and runs no comment query
	// -----------------------------------------------------------------------

	/** The WHERE clause `WP_Query::get_posts()` starts a front-page comment feed with. */
	private const FEED_WHERE = "WHERE ( post_status = 'publish' OR ( post_status = 'inherit' AND post_type = 'attachment' ) ) AND comment_approved = '1'";

	/** Runs `comment_feed_where`, as `WP_Query::get_posts()` does, for a comment feed. */
	private function feedWhere( string $where = self::FEED_WHERE ) {
		return Agend_Test_WP::apply_registered( 'comment_feed_where', $where, new \stdClass() );
	}

	/**
	 * `/comments/feed/` is built by `WP_Query::get_posts()` with its own SQL, so
	 * `pre_get_comments` never fires for it. It is the clause that must carry the exclusion.
	 */
	#[Test]
	public function the_global_comment_feed_leaves_out_the_posts_an_anonymous_reader_is_denied(): void {
		$GLOBALS['agend_test_is_feed'] = true;

		$this->assertSame(
			self::FEED_WHERE . ' AND wp_comments.comment_post_ID NOT IN ( 100, 102 )',
			$this->feedWhere()
		);
	}

	/** The clause is applied last, so nothing that ran before it can add the exclusion back out. */
	#[Test]
	public function the_feed_filter_is_the_last_word_on_the_clause(): void {
		$entries = Agend_Test_WP::$filter_registry['comment_feed_where'] ?? array();

		$this->assertCount( 1, $entries );
		$this->assertSame( PHP_INT_MAX, $entries[0]['priority'] );
	}

	/** A feed is shared and cached whoever fetched it: an editor's own access is not applied to it. */
	#[Test]
	public function the_feed_is_judged_as_an_anonymous_reader_even_for_an_editor_or_a_member(): void {
		// Not flagged as a feed on purpose: the clause is the feed's, whatever `is_feed()` says.
		$GLOBALS['agend_test_is_feed'] = false;

		$this->asEditor();

		$this->assertStringContainsString( 'NOT IN ( 100, 102 )', (string) $this->feedWhere(), 'an editor' );

		Comments::reset();
		\Agend_Content_Access_Decision::reset_cache();
		$this->asMember( self::TIER_A );

		$this->assertStringContainsString( 'NOT IN ( 100, 102 )', (string) $this->feedWhere(), 'a member' );
	}

	/** The comments table is named, because the feed's query joins the posts table to it. */
	#[Test]
	public function the_feed_clause_names_the_comments_table(): void {
		$this->assertMatchesRegularExpression( '/ AND wp_comments\.comment_post_ID NOT IN \( [\d, ]+ \)$/', (string) $this->feedWhere() );
	}

	#[Test]
	public function a_feed_with_nothing_to_hide_is_left_as_it_was(): void {
		$GLOBALS['agend_test_posts_with_comments'] = array( self::PUBLIC );

		$this->assertSame( self::FEED_WHERE, $this->feedWhere() );
	}

	/** If the restricted posts cannot be listed the feed matches no comment, not every one. */
	#[Test]
	public function the_feed_fails_closed_when_the_restricted_posts_cannot_be_listed(): void {
		$GLOBALS['wpdb']->last_error = 'Lost connection to MySQL server';

		$this->assertSame( self::FEED_WHERE . ' AND 0', $this->feedWhere() );
	}

	/** A clause another plugin left as something this cannot append to is refused, not passed. */
	#[Test]
	public function a_feed_clause_that_cannot_be_appended_to_matches_nothing(): void {
		$this->assertSame( 'WHERE 0', Agend_Test_WP::apply_registered( 'comment_feed_where', null, new \stdClass() ) );
		$this->assertSame( 'WHERE 0', $this->feedWhere( '   ' ) );
	}

	/**
	 * Core keeps a feed's comment ids under the md5 of its whole SQL. A viewer who is denied
	 * different posts gets different SQL, so no entry is shared between them.
	 */
	#[Test]
	public function the_feed_clause_is_part_of_the_sql_core_builds_its_cache_key_from(): void {
		$denied = (string) $this->feedWhere();

		Comments::reset();
		$GLOBALS['agend_test_posts_with_comments'] = array( self::PUBLIC );

		$this->assertNotSame( md5( $denied ), md5( (string) $this->feedWhere() ) );
	}

	/** The list is made once for the request, however many feeds ask. */
	#[Test]
	public function the_feed_and_a_comment_query_share_one_list(): void {
		$GLOBALS['agend_test_is_feed'] = true;

		$this->feedWhere();
		$queries = count( $GLOBALS['wpdb']->queries );

		$this->feedWhere();
		$this->query();

		$this->assertSame( $queries, count( $GLOBALS['wpdb']->queries ) );
	}

	// -----------------------------------------------------------------------
	// One comment, over REST
	// -----------------------------------------------------------------------

	private function rest( string $route, string $method = 'GET' ) {
		return Agend_Test_WP::apply_registered( 'rest_request_before_callbacks', null, array(), new WP_REST_Request( $method, $route ) );
	}

	#[Test]
	public function a_single_comment_on_a_denied_page_is_refused_over_rest(): void {
		$result = $this->rest( '/wp/v2/comments/9' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_cannot_read', $result->get_error_code() );
	}

	#[Test]
	public function a_single_comment_on_a_page_the_visitor_may_read_is_returned(): void {
		$this->assertNull( $this->rest( '/wp/v2/comments/10' ), 'a public page' );

		$this->asMember( self::TIER_A );

		$this->assertNull( $this->rest( '/wp/v2/comments/9' ), 'a page their rules admit' );
		$this->assertInstanceOf( WP_Error::class, $this->rest( '/wp/v2/comments/11' ), 'a page they do not' );
	}

	#[Test]
	public function an_editor_is_refused_a_single_comment_over_rest_on_the_live_site_and_has_it_in_wp_admin(): void {
		$this->asEditor();

		$this->assertInstanceOf( WP_Error::class, $this->rest( '/wp/v2/comments/9' ) );

		$GLOBALS['agend_test_is_admin'] = true;

		$this->assertNull( $this->rest( '/wp/v2/comments/9' ) );
	}

	#[Test]
	public function a_route_that_is_not_a_single_comment_is_not_looked_at(): void {
		$this->assertNull( $this->rest( '/wp/v2/comments' ) );
		$this->assertNull( $this->rest( '/wp/v2/posts/9' ) );
		$this->assertNull( $this->rest( '/wp/v2/comments/9/extra' ) );
		$this->assertSame( array(), $GLOBALS['wpdb']->queries );
	}

	#[Test]
	public function a_comment_that_does_not_exist_is_left_to_core_to_report(): void {
		$this->assertNull( $this->rest( '/wp/v2/comments/999' ) );
	}

	#[Test]
	public function an_error_already_on_the_way_is_left_as_it_is(): void {
		$error = new WP_Error( 'rest_forbidden', 'Nope' );

		$this->assertSame(
			$error,
			Agend_Test_WP::apply_registered( 'rest_request_before_callbacks', $error, array(), new WP_REST_Request( 'GET', '/wp/v2/comments/9' ) )
		);
	}
}
