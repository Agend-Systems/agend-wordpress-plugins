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
use Agend_Test_WP_Die_Exception;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-policy.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-catalogue.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-meta-box.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-decision.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-frontend.php';

/**
 * The comments on a restricted post are withheld from a viewer the post is.
 *
 * A teaser page replaced the body and left the theme to print the comment list,
 * the form and the count. The post's comment feed was served as it was. And the
 * REST comment routes and every front-end comment query, a recent-comments
 * widget among them, returned comments by the query that matched them, without
 * ever rendering the post those comments were on. Each surface is walked for the
 * four viewers that matter: somebody who cannot be identified, a member on a plan
 * the post does not admit, a member on the plan it does, and an editor of it.
 *
 * Every chain is run the way WordPress runs it (see `Agend_Test_WP::apply_registered()`):
 * what matters is what is left after every registered filter has had its turn.
 */
#[CoversClass( Agend_Content_Access_Frontend::class )]
#[CoversClass( Agend_Content_Access_Decision::class )]
final class CommentExposureTest extends TestCase {

	private const TIER_A = '11111111-1111-1111-1111-111111111111';
	private const TIER_B = '22222222-2222-2222-2222-222222222222';

	/** Plan A only, with a written excerpt, so a denied visitor gets a teaser page. */
	private const A_ONLY = 81;
	/** Plan B only. */
	private const B_ONLY = 82;
	private const PUBLIC_POST = 83;
	private const NO_POLICY   = 84;
	/** Members only, with no excerpt, so a denied visitor gets a bare 403. */
	private const NO_TEASER = 85;

	/** @var int Calls to the bearer lookup: the identity lookup a public post must never cause. */
	private int $identity_lookups = 0;

	/** @var int[] Post ids passed to `agend_content_access_uncacheable_render`. */
	private array $uncacheable = array();

	protected function setUp(): void {
		parent::setUp();

		$this->identity_lookups = 0;
		$this->uncacheable      = array();

		$this->registerPost( self::A_ONLY, array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A ) ), 'A public summary.' );
		$this->registerPost( self::B_ONLY, array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_B ) ) );
		$this->registerPost( self::PUBLIC_POST, array( 'mode' => 'public' ) );
		$this->registerPost( self::NO_POLICY, null );
		$this->registerPost( self::NO_TEASER, array( 'mode' => 'active_member' ) );

		add_action(
			'agend_content_access_uncacheable_render',
			function ( int $post_id ): void {
				$this->uncacheable[] = $post_id;
			}
		);

		$this->become( 'anonymous' );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['agend_test_is_singular'], $GLOBALS['agend_test_queried_object'] );

		parent::tearDown();
	}

	// -----------------------------------------------------------------------
	// Fixtures
	// -----------------------------------------------------------------------

	private function registerPost( int $id, ?array $policy, string $excerpt = '' ): void {
		$GLOBALS['agend_test_posts'][ $id ] = array(
			'ID'           => $id,
			'post_status'  => 'publish',
			'post_type'    => 'post',
			'post_title'   => 'Post ' . $id,
			'post_content' => 'BODY ' . $id,
			'post_excerpt' => $excerpt,
		);

		if ( null !== $policy ) {
			$GLOBALS['agend_test_post_meta'][ $id ] = array( Agend_Content_Access_Policy::META_KEY => $policy );
		}
	}

	/**
	 * Turns the visitor into one of the four viewers under test.
	 *
	 * `anonymous` has no session. `wrong_plan` holds plan B. `right_plan` holds
	 * plan A. `editor` may edit every post, which is what the carve-out asks about.
	 */
	private function become( string $viewer ): void {
		$GLOBALS['agend_test_current_user_can']['edit_post'] = ( 'editor' === $viewer );

		$tiers = array(
			'wrong_plan' => array( self::TIER_B ),
			'right_plan' => array( self::TIER_A ),
		);

		Agend_Test_WP::$filters['agend_apps_bearer_token'] = function () use ( $viewer, $tiers ): string {
			++$this->identity_lookups;

			return isset( $tiers[ $viewer ] ) ? 'member-token' : '';
		};

		Agend_Test_WP::set_filter(
			'agend_apps_crm_get_my_entitlements_response',
			array( 'data' => array( 'tier_ids' => $tiers[ $viewer ] ?? array() ) )
		);

		Agend_Content_Access_Decision::reset_cache();
	}

	/** @return array<string, array{0: string, 1: bool, 2: int[]}> Viewer, whether plan A's post is denied to them, and the posts whose comments they may read. */
	public static function viewers(): array {
		return array(
			'an anonymous visitor'       => array( 'anonymous', true, array( self::PUBLIC_POST, self::NO_POLICY ) ),
			'a member on the wrong plan' => array( 'wrong_plan', true, array( self::PUBLIC_POST, self::NO_POLICY, self::B_ONLY, self::NO_TEASER ) ),
			'a member on the right plan' => array( 'right_plan', false, array( self::PUBLIC_POST, self::NO_POLICY, self::A_ONLY, self::NO_TEASER ) ),
			'an editor of the posts'     => array( 'editor', false, array( self::A_ONLY, self::B_ONLY, self::PUBLIC_POST, self::NO_POLICY, self::NO_TEASER ) ),
		);
	}

	/** One approved comment on each post, ids in step with the post they are on. */
	private function everyComment(): array {
		$comments = array();

		foreach ( array( self::A_ONLY, self::B_ONLY, self::PUBLIC_POST, self::NO_POLICY, self::NO_TEASER ) as $post_id ) {
			$comments[] = $this->comment( $post_id );
		}

		return $comments;
	}

	private function comment( int $post_id, int $number = 1 ): object {
		return (object) array(
			'comment_ID'      => $post_id * 100 + $number,
			'comment_post_ID' => $post_id,
			'comment_content' => 'COMMENT ' . $number . ' ON ' . $post_id,
		);
	}

	/** Requests the page for a post, the way `template_redirect` does. */
	private function requestPage( int $id, bool $comment_feed = false ): Agend_Content_Access_Frontend {
		$GLOBALS['agend_test_is_singular']     = true;
		$GLOBALS['agend_test_queried_object']  = get_post( $id );
		$GLOBALS['agend_test_is_comment_feed'] = $comment_feed;
		Agend_Test_WP::$queried_object_id      = $id;

		$frontend = new Agend_Content_Access_Frontend();
		$frontend->gate_request();

		return $frontend;
	}

	/** @return string[] Names of the methods registered on a hook. */
	private function registeredMethods( string $hook ): array {
		return array_map(
			static fn( array $entry ): string => is_array( $entry['callback'] ) ? $entry['callback'][1] : '(closure)',
			Agend_Test_WP::$filter_registry[ $hook ] ?? array()
		);
	}

	/** @return int[] Distinct post ids of the comments that survived, in order. */
	private function postsOf( array $comments ): array {
		return array_values( array_unique( array_map( static fn( object $comment ): int => $comment->comment_post_ID, $comments ) ) );
	}

	// -----------------------------------------------------------------------
	// A denied page served as a teaser: the theme's comment list, form and count
	// -----------------------------------------------------------------------

	#[Test]
	#[DataProvider( 'viewers' )]
	public function a_teaser_page_prints_no_comments_form_or_count_to_a_viewer_denied_the_post( string $viewer, bool $denied ): void {
		$this->become( $viewer );
		$this->requestPage( self::A_ONLY );

		$thread = array( $this->comment( self::A_ONLY, 1 ), $this->comment( self::A_ONLY, 2 ) );

		$open  = Agend_Test_WP::apply_registered( 'comments_open', true, self::A_ONLY );
		$pings = Agend_Test_WP::apply_registered( 'pings_open', true, self::A_ONLY );
		$list  = Agend_Test_WP::apply_registered( 'comments_array', $thread, self::A_ONLY );
		$count = Agend_Test_WP::apply_registered( 'get_comments_number', 2, self::A_ONLY );

		if ( $denied ) {
			$this->assertFalse( $open, 'no comment form' );
			$this->assertFalse( $pings, 'and no pingback invitation' );
			$this->assertSame( array(), $list, 'no comment list' );
			$this->assertSame( 0, $count, 'and no count of what is not shown' );

			return;
		}

		$this->assertTrue( $open );
		$this->assertTrue( $pings );
		$this->assertSame( $thread, $list );
		$this->assertSame( 2, $count );
	}

	/** The filters belong to the gated post. A related post, or a widget, on the same page keeps its own. */
	#[Test]
	public function the_teaser_page_filters_act_for_the_gated_post_only(): void {
		$this->requestPage( self::A_ONLY );

		$other = array( $this->comment( self::PUBLIC_POST ) );

		$this->assertTrue( Agend_Test_WP::apply_registered( 'comments_open', true, self::PUBLIC_POST ) );
		$this->assertTrue( Agend_Test_WP::apply_registered( 'pings_open', true, self::PUBLIC_POST ) );
		$this->assertSame( $other, Agend_Test_WP::apply_registered( 'comments_array', $other, self::PUBLIC_POST ) );
		$this->assertSame( 7, Agend_Test_WP::apply_registered( 'get_comments_number', 7, self::PUBLIC_POST ) );
	}

	/** A page nobody is denied pays nothing for any of this. */
	#[Test]
	public function a_request_that_is_not_gated_registers_none_of_the_comment_filters(): void {
		$this->become( 'right_plan' );
		$this->requestPage( self::A_ONLY );
		$this->requestPage( self::PUBLIC_POST );

		foreach ( array( 'comments_open', 'pings_open', 'comments_array', 'get_comments_number' ) as $hook ) {
			$this->assertSame( array(), Agend_Test_WP::$filter_registry[ $hook ] ?? array(), $hook );
		}
	}

	// -----------------------------------------------------------------------
	// The post's comment feed
	// -----------------------------------------------------------------------

	#[Test]
	#[DataProvider( 'viewers' )]
	public function a_comment_feed_is_refused_to_a_viewer_denied_the_post_even_when_it_has_a_teaser( string $viewer, bool $denied ): void {
		$this->become( $viewer );

		if ( $denied ) {
			$this->expectException( Agend_Test_WP_Die_Exception::class );
		}

		$this->requestPage( self::A_ONLY, true );

		$this->assertFalse( $denied, 'a viewer who may read the post is served its comment feed' );
		$this->assertNotContains( 'filter_the_content', $this->registeredMethods( 'the_content' ), 'and it is not turned into a teaser page' );
	}

	#[Test]
	public function a_comment_feed_for_a_post_with_no_teaser_is_refused_to_an_anonymous_visitor(): void {
		$this->expectException( Agend_Test_WP_Die_Exception::class );

		$this->requestPage( self::NO_TEASER, true );
	}

	/** It is the comment feed that is refused. The same post's page still shows its teaser. */
	#[Test]
	public function the_same_post_still_serves_its_teaser_page_when_it_is_not_the_comment_feed(): void {
		$this->requestPage( self::A_ONLY, false );

		$this->assertContains( 'filter_the_content', $this->registeredMethods( 'the_content' ) );
	}

	/** A member's comment feed is a member's render: a shared cache must not keep it for the next anonymous reader. */
	#[Test]
	public function a_member_served_the_comment_feed_of_a_restricted_post_marks_it_uncacheable(): void {
		$this->become( 'right_plan' );

		$this->requestPage( self::A_ONLY, true );

		$this->assertSame( array( self::A_ONLY ), $this->uncacheable );
	}

	#[Test]
	public function an_editor_and_a_public_posts_comment_feed_are_not_marked_uncacheable(): void {
		$this->become( 'editor' );
		$this->requestPage( self::A_ONLY, true );

		$this->become( 'anonymous' );
		$this->requestPage( self::PUBLIC_POST, true );
		$this->requestPage( self::NO_POLICY, true );

		$this->assertSame( array(), $this->uncacheable );
	}

	// -----------------------------------------------------------------------
	// Comment queries: widgets, the site-wide comment feed, blocks
	// -----------------------------------------------------------------------

	/**
	 * @param int[] $readable Posts whose comments this viewer may read.
	 */
	#[Test]
	#[DataProvider( 'viewers' )]
	public function a_comment_query_leaves_out_comments_on_posts_the_viewer_is_denied( string $viewer, bool $denied, array $readable ): void {
		$this->become( $viewer );
		new Agend_Content_Access_Frontend();

		$result = Agend_Test_WP::apply_registered( 'the_comments', $this->everyComment() );

		$this->assertEqualsCanonicalizing( $readable, $this->postsOf( $result ) );
	}

	#[Test]
	public function the_comments_that_stay_are_the_same_objects_in_the_same_order(): void {
		new Agend_Content_Access_Frontend();

		$mixed  = array( $this->comment( self::PUBLIC_POST, 1 ), $this->comment( self::A_ONLY, 1 ), $this->comment( self::NO_POLICY, 1 ), $this->comment( self::PUBLIC_POST, 2 ) );
		$result = Agend_Test_WP::apply_registered( 'the_comments', $mixed );

		$this->assertSame( array( $mixed[0], $mixed[2], $mixed[3] ), $result );
	}

	#[Test]
	public function an_empty_result_and_anything_that_is_not_a_comment_pass_through(): void {
		new Agend_Content_Access_Frontend();

		$this->assertSame( array(), Agend_Test_WP::apply_registered( 'the_comments', array() ) );
		$this->assertSame( array( 7, 9 ), Agend_Test_WP::apply_registered( 'the_comments', array( 7, 9 ) ), 'ids are not comments, and nothing prints from one' );
	}

	/** The reason the check is where it is: a public post is answered before any identity lookup. */
	#[Test]
	public function a_list_of_comments_on_public_posts_causes_no_identity_lookup(): void {
		new Agend_Content_Access_Frontend();

		$list = array();

		for ( $number = 1; $number <= 25; ++$number ) {
			$list[] = $this->comment( self::PUBLIC_POST, $number );
			$list[] = $this->comment( self::NO_POLICY, $number );
		}

		$this->assertCount( 50, Agend_Test_WP::apply_registered( 'the_comments', $list ) );
		$this->assertSame( 0, $this->identity_lookups );
	}

	/** Many comments on a few restricted posts is a few decisions, and one lookup of who is asking. */
	#[Test]
	public function many_comments_on_restricted_posts_cost_one_identity_lookup(): void {
		$this->become( 'wrong_plan' );
		new Agend_Content_Access_Frontend();

		$list = array();

		for ( $number = 1; $number <= 20; ++$number ) {
			$list[] = $this->comment( self::A_ONLY, $number );
			$list[] = $this->comment( self::B_ONLY, $number );
			$list[] = $this->comment( self::NO_TEASER, $number );
		}

		$result = Agend_Test_WP::apply_registered( 'the_comments', $list );

		$this->assertSame( array( self::B_ONLY, self::NO_TEASER ), $this->postsOf( $result ) );
		$this->assertSame( 1, $this->identity_lookups );
	}

	/** The viewer does not change mid-request, so the answer for a post is worked out once and kept. */
	#[Test]
	public function the_answer_for_a_post_is_kept_for_the_rest_of_the_request(): void {
		new Agend_Content_Access_Frontend();

		$first = Agend_Test_WP::apply_registered( 'the_comments', array( $this->comment( self::A_ONLY ) ) );

		$this->become( 'editor' );

		$second = Agend_Test_WP::apply_registered( 'the_comments', array( $this->comment( self::A_ONLY ), $this->comment( self::PUBLIC_POST ) ) );

		$this->assertSame( array(), $first );
		$this->assertSame( array( self::PUBLIC_POST ), $this->postsOf( $second ), 'it was not asked again' );
	}

	// -----------------------------------------------------------------------
	// REST: /wp/v2/comments
	// -----------------------------------------------------------------------

	private function restResponseFor( object $comment ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'id'      => $comment->comment_ID,
				'post'    => $comment->comment_post_ID,
				'content' => array( 'rendered' => '<p>' . $comment->comment_content . '</p>' ),
			)
		);
	}

	/**
	 * One read of a single comment, `GET /wp/v2/comments/<id>`.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	private function restSingle( object $comment, string $method = 'GET' ) {
		$frontend = new Agend_Content_Access_Frontend();
		$frontend->register_rest_filters();

		return Agend_Test_WP::apply_registered(
			'rest_prepare_comment',
			$this->restResponseFor( $comment ),
			$comment,
			new WP_REST_Request( $method, '/wp/v2/comments/' . $comment->comment_ID )
		);
	}

	/**
	 * The collection, `GET /wp/v2/comments`, built the way core builds it: the
	 * query runs and its result passes through `the_comments`, then each comment
	 * that is left is prepared for the response. What this returns is what a client
	 * would be sent, an error object marked so an assertion can see it.
	 *
	 * @param int[] $posts The `post` parameter, or none for the whole site.
	 * @return array<int, int|string> Comment ids, or `error` for an item that came back as one.
	 */
	private function restCollection( array $posts = array() ): array {
		$frontend = new Agend_Content_Access_Frontend();
		$frontend->register_rest_filters();

		$matched = array_values(
			array_filter(
				$this->everyComment(),
				static fn( object $comment ): bool => array() === $posts || in_array( $comment->comment_post_ID, $posts, true )
			)
		);

		$items = array();

		foreach ( Agend_Test_WP::apply_registered( 'the_comments', $matched ) as $comment ) {
			$item = Agend_Test_WP::apply_registered(
				'rest_prepare_comment',
				$this->restResponseFor( $comment ),
				$comment,
				new WP_REST_Request( 'GET', '/wp/v2/comments' )
			);

			$items[] = $item instanceof WP_Error ? 'error' : $item->get_data()['id'];
		}

		return $items;
	}

	private function idOf( int $post_id ): int {
		return $post_id * 100 + 1;
	}

	#[Test]
	public function the_rest_filter_is_registered_with_the_other_rest_filters(): void {
		( new Agend_Content_Access_Frontend() )->register_rest_filters();

		$this->assertNotSame( array(), Agend_Test_WP::$filter_registry['rest_prepare_comment'] ?? array() );
	}

	#[Test]
	#[DataProvider( 'viewers' )]
	public function a_single_comment_on_a_restricted_post_is_refused_to_a_viewer_denied_the_post( string $viewer, bool $denied ): void {
		$this->become( $viewer );

		$comment = $this->comment( self::A_ONLY );
		$result  = $this->restSingle( $comment );

		if ( ! $denied ) {
			$this->assertInstanceOf( WP_REST_Response::class, $result );
			$this->assertSame( '<p>' . $comment->comment_content . '</p>', $result->get_data()['content']['rendered'] );

			return;
		}

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_cannot_read_post', $result->get_error_code() );
		$this->assertSame( array( 'status' => 403 ), $result->get_error_data() );
		$this->assertStringNotContainsString( 'COMMENT', $result->get_error_message() );
	}

	#[Test]
	public function a_single_comment_on_a_public_or_unset_post_is_served_to_anyone_without_an_identity_lookup(): void {
		foreach ( array( self::PUBLIC_POST, self::NO_POLICY ) as $post_id ) {
			$this->assertInstanceOf( WP_REST_Response::class, $this->restSingle( $this->comment( $post_id ) ) );
		}

		$this->assertSame( 0, $this->identity_lookups );
	}

	/** Reads are refused. A write returns the comment through the same filter, and core's delete handler calls `get_data()` on whatever it gets. */
	#[Test]
	public function a_write_is_not_turned_into_an_error(): void {
		foreach ( array( 'POST', 'PUT', 'PATCH', 'DELETE' ) as $method ) {
			$this->assertInstanceOf( WP_REST_Response::class, $this->restSingle( $this->comment( self::A_ONLY ), $method ), $method );
		}
	}

	#[Test]
	public function a_head_request_is_a_read(): void {
		$this->assertInstanceOf( WP_Error::class, $this->restSingle( $this->comment( self::A_ONLY ), 'HEAD' ) );
	}

	/**
	 * @param int[] $readable Posts whose comments this viewer may read.
	 */
	#[Test]
	#[DataProvider( 'viewers' )]
	public function the_collection_without_a_post_parameter_holds_only_comments_the_viewer_may_read( string $viewer, bool $denied, array $readable ): void {
		$this->become( $viewer );

		$expected = array();

		foreach ( array( self::A_ONLY, self::B_ONLY, self::PUBLIC_POST, self::NO_POLICY, self::NO_TEASER ) as $post_id ) {
			if ( in_array( $post_id, $readable, true ) ) {
				$expected[] = $this->idOf( $post_id );
			}
		}

		$this->assertSame( $expected, $this->restCollection() );
	}

	#[Test]
	#[DataProvider( 'viewers' )]
	public function the_collection_for_one_post_is_empty_or_whole_by_whether_the_viewer_may_read_it( string $viewer, bool $denied ): void {
		$this->become( $viewer );

		$this->assertSame( $denied ? array() : array( $this->idOf( self::A_ONLY ) ), $this->restCollection( array( self::A_ONLY ) ) );
	}

	#[Test]
	public function a_collection_for_several_posts_keeps_the_readable_ones(): void {
		$this->become( 'right_plan' );

		$this->assertSame(
			array( $this->idOf( self::A_ONLY ), $this->idOf( self::PUBLIC_POST ) ),
			$this->restCollection( array( self::A_ONLY, self::B_ONLY, self::PUBLIC_POST ) )
		);
	}

	/** The policy is not in a comment, and a denied comment is not described anywhere in what is sent. */
	#[Test]
	public function nothing_of_a_withheld_comment_is_in_what_a_client_is_sent(): void {
		$sent = (string) wp_json_encode( $this->restCollection() );

		$this->assertStringNotContainsString( (string) $this->idOf( self::A_ONLY ), $sent );
		$this->assertStringNotContainsString( 'error', $sent );
	}
}
