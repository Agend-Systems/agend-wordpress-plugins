<?php
/**
 * Comments on pages the viewer may not read.
 *
 * @package Agend_Content_Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps the comments of a restricted page from a visitor who is refused the page.
 *
 * A page's rules govern its body, its excerpt, its feed and its REST item. Its
 * comments are a second copy of what members said there, and until now nothing
 * governed them: a denied page's template ran and printed them, the REST comments
 * route listed them for any published post, and a recent-comments widget quoted them
 * in the sidebar of every other page. Two separate things, handled separately.
 *
 * THE DENIED PAGE ITSELF. The page gate (`Agend_Content_Access_Frontend`) replaces the
 * template of a denied page, so its comments are not printed by the template either.
 * What this closes is the rest of the request, for the page the gate denied:
 * `comments_open` and `pings_open` are false (no form, no pingback), `comments_array`
 * is empty and `get_comments_number` is zero, so a header, a widget or a template part
 * that asks about this page's comments is told it has none. Its comment feed is
 * refused by the gate. They read the denial the gate left (`current_gate()`), so they
 * apply only to the page the request is for, and only when the visitor was denied.
 *
 * COMMENTS ON A RESTRICTED PAGE, ELSEWHERE. Every list of comments, whatever asks for
 * it, is a `WP_Comment_Query`: `get_comments()`, a recent-comments widget, the global
 * comment feed, the comment blocks and the REST collection (`/wp/v2/comments`, with or
 * without `post=`). So the rule is applied to the query, once, on `pre_get_comments`:
 * the posts the viewer is denied are added to its `post__not_in`.
 *
 * Why the query's own argument and not the SQL (`comments_clauses`): `WP_Comment_Query`
 * caches its result under a key made from its arguments alone, and a persistent object
 * cache would then hand the comments an editor was shown to the next visitor who asked
 * the same question. An exclusion added to the arguments is part of the key, so two
 * viewers with different denied sets never share an entry.
 *
 * A viewer is denied a post when the decision for the post is anything but granted
 * (`Agend_Content_Access_Decision::for_post()`): the same verdict as the page gate,
 * including the editor's preview, so a user who may `edit_post` the page keeps its
 * comments. A feed is judged as an anonymous reader, as every feed is.
 *
 * FAIL CLOSED. If the list of restricted posts cannot be read, the query is made to
 * match nothing (`post__in` of `array( 0 )`), not everything. A post whose decision is
 * anything but granted is excluded, so a rule that cannot be evaluated hides the
 * comments, as it hides the page.
 *
 * THE COST. Only a request that asks for comments pays, and only when a restricted post
 * has some. One indexed query finds the posts that carry page rules (`_agend_access_rules`
 * or the 0.1.x `_agend_access_policy`) AND have at least one comment, so a site whose
 * restricted pages have no comments pays that one query and nothing more. For each post
 * it finds, one decision: the post's rules read from the primed meta cache and judged
 * against the viewer's facts, which are memoised for the request, so the gateway is
 * asked at most once however many posts there are. The result is kept for the request.
 * The cost is therefore linear in the number of restricted posts that have comments,
 * once per request, and zero for a request that never asks for comments. The list that
 * ends up in `post__not_in` is as long as the posts the viewer is denied, which is the
 * price of doing it in the cache-safe place.
 *
 * A SINGLE COMMENT over REST (`/wp/v2/comments/<id>`) is not a query, so it is
 * refused on `rest_request_before_callbacks` for a comment on a post the viewer is
 * denied, with the error core gives a comment it will not show.
 *
 * Out of scope, on purpose: a comment COUNT in a list (`get_comments_number()` for a
 * post other than the denied page) and a comment POSTED to a restricted page by someone
 * who cannot read it. Neither shows a comment.
 */
class Agend_Content_Access_Comments {

	/**
	 * The posts each viewer is denied, for the request, keyed by viewer.
	 *
	 * Null for a viewer whose list could not be made.
	 *
	 * @var array<string, int[]|null>
	 */
	private static array $denied = array();

	/**
	 * Whether the list is being made right now, so nothing it calls can start it again.
	 *
	 * @var bool
	 */
	private static bool $resolving = false;

	/**
	 * Registers hooks.
	 */
	public function __construct() {
		// The last word on each: a theme or a plugin that opens a page's comments again
		// afterwards must not be able to.
		add_filter( 'comments_open', array( $this, 'close_for_denied_page' ), PHP_INT_MAX, 2 );
		add_filter( 'pings_open', array( $this, 'close_for_denied_page' ), PHP_INT_MAX, 2 );
		add_filter( 'comments_array', array( $this, 'empty_for_denied_page' ), PHP_INT_MAX, 2 );
		add_filter( 'get_comments_number', array( $this, 'zero_for_denied_page' ), PHP_INT_MAX, 2 );

		// Last, so a callback that rewrites the exclusions cannot undo this one.
		add_action( 'pre_get_comments', array( $this, 'exclude_denied_posts' ), PHP_INT_MAX );

		add_filter( 'rest_request_before_callbacks', array( $this, 'refuse_denied_rest_comment' ), 10, 3 );
	}

	/**
	 * Forgets what was worked out for the request. Tests only: a request is one viewer.
	 */
	public static function reset(): void {
		self::$denied    = array();
		self::$resolving = false;
	}

	// -----------------------------------------------------------------------
	// The page this request denied
	// -----------------------------------------------------------------------

	/**
	 * Whether a post is the page the gate denied for this request.
	 *
	 * @param mixed $post_id A post id, as a filter passes it.
	 * @return bool
	 */
	private static function is_denied_page( $post_id ): bool {
		if ( ! class_exists( 'Agend_Content_Access_Frontend', false ) ) {
			return false;
		}

		$gate = Agend_Content_Access_Frontend::current_gate();

		return null !== $gate && is_numeric( $post_id ) && (int) $post_id === (int) $gate['post_id'];
	}

	/**
	 * Closes comments and pingbacks for the denied page.
	 *
	 * @param mixed $open    Whether they are open.
	 * @param mixed $post_id Post id.
	 * @return mixed
	 */
	public function close_for_denied_page( $open, $post_id = 0 ) {
		return self::is_denied_page( $post_id ) ? false : $open;
	}

	/**
	 * Empties the comments of the denied page.
	 *
	 * @param mixed $comments Comments.
	 * @param mixed $post_id  Post id.
	 * @return mixed
	 */
	public function empty_for_denied_page( $comments, $post_id = 0 ) {
		return self::is_denied_page( $post_id ) ? array() : $comments;
	}

	/**
	 * Zeroes the comment count of the denied page.
	 *
	 * @param mixed $count   Comment count.
	 * @param mixed $post_id Post id.
	 * @return mixed
	 */
	public function zero_for_denied_page( $count, $post_id = 0 ) {
		return self::is_denied_page( $post_id ) ? 0 : $count;
	}

	// -----------------------------------------------------------------------
	// Every list of comments
	// -----------------------------------------------------------------------

	/**
	 * Keeps the comments of posts the viewer is denied out of a comment query.
	 *
	 * See the class docblock for why this is the query's arguments and not its SQL.
	 *
	 * @param mixed $query The `WP_Comment_Query`, about to run.
	 */
	public function exclude_denied_posts( $query ): void {
		if ( ! is_object( $query ) || ! isset( $query->query_vars ) || ! is_array( $query->query_vars ) ) {
			return;
		}

		$denied = self::denied_post_ids();

		if ( null === $denied ) {
			// The list could not be made: match no comment at all.
			$query->query_vars['post__in'] = array( 0 );

			return;
		}

		if ( array() === $denied ) {
			return;
		}

		$query->query_vars['post__not_in'] = array_values(
			array_unique( array_merge( self::ids( $query->query_vars['post__not_in'] ?? array() ), $denied ) )
		);
	}

	/**
	 * Refuses one comment, over REST, on a post the viewer is denied.
	 *
	 * `GET /wp/v2/comments/<id>` reads one comment by its id and is not a comment query.
	 * Cheap for everything else: a route that is not a single comment is returned after
	 * one comparison.
	 *
	 * @param mixed $response The response so far, or an error.
	 * @param mixed $handler  The route handler. Unused.
	 * @param mixed $request  The `WP_REST_Request`.
	 * @return mixed
	 */
	public function refuse_denied_rest_comment( $response, $handler = null, $request = null ) {
		if ( is_wp_error( $response ) || ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) {
			return $response;
		}

		if ( 1 !== preg_match( '#^/wp/v2/comments/(\d+)/?$#', (string) $request->get_route(), $matches ) ) {
			return $response;
		}

		$comment = get_comment( (int) $matches[1] );

		if ( ! is_object( $comment ) || ! isset( $comment->comment_post_ID ) || (int) $comment->comment_post_ID <= 0 ) {
			return $response;
		}

		if ( ! class_exists( 'Agend_Content_Access_Decision', false ) ) {
			return self::rest_refusal();
		}

		$decision = Agend_Content_Access_Decision::for_post( (int) $comment->comment_post_ID );

		return Agend_Content_Access_Decision::STATE_GRANTED === $decision['state'] ? $response : self::rest_refusal();
	}

	/**
	 * The error for a comment the viewer may not read: core's own, for the same.
	 *
	 * @return WP_Error
	 */
	private static function rest_refusal(): WP_Error {
		return new WP_Error(
			'rest_cannot_read',
			__( 'Sorry, you are not allowed to read this comment.', 'agend-content-access' ),
			array( 'status' => function_exists( 'rest_authorization_required_code' ) ? rest_authorization_required_code() : 403 )
		);
	}

	/**
	 * The posts the current viewer is denied and that have comments.
	 *
	 * Made once per viewer per request. See the class docblock for the cost.
	 *
	 * @return int[]|null Null when the list could not be made, which the caller reads as
	 *                    "every post".
	 */
	private static function denied_post_ids(): ?array {
		$anonymous = is_feed();
		$viewer    = $anonymous ? 'feed' : (string) get_current_user_id();

		if ( array_key_exists( $viewer, self::$denied ) ) {
			return self::$denied[ $viewer ];
		}

		if ( self::$resolving ) {
			return null;
		}

		self::$resolving = true;

		try {
			self::$denied[ $viewer ] = self::resolve_denied_post_ids( $anonymous );
		} finally {
			self::$resolving = false;
		}

		return self::$denied[ $viewer ];
	}

	/**
	 * Finds the restricted posts with comments and judges each for the viewer.
	 *
	 * @param bool $anonymous Judge as an anonymous reader, as a feed is.
	 * @return int[]|null The posts denied, null when they could not be found.
	 */
	private static function resolve_denied_post_ids( bool $anonymous ): ?array {
		global $wpdb;

		if (
			! class_exists( 'Agend_Content_Access_Decision', false )
			|| ! class_exists( 'Agend_Content_Access_Rules', false )
			|| ! class_exists( 'Agend_Content_Access_Policy', false )
			|| ! is_object( $wpdb )
		) {
			return null;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table names from $wpdb; both values are bound.
		$sql = $wpdb->prepare(
			"SELECT DISTINCT m.post_id FROM {$wpdb->postmeta} AS m INNER JOIN {$wpdb->comments} AS c ON c.comment_post_ID = m.post_id WHERE m.meta_key IN ( %s, %s )",
			Agend_Content_Access_Rules::META_KEY,
			Agend_Content_Access_Policy::META_KEY
		);

		$found = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		if ( ! is_array( $found ) || '' !== (string) $wpdb->last_error ) {
			return null;
		}

		$ids = array_values( array_filter( self::ids( $found ) ) );

		if ( array() === $ids ) {
			return array();
		}

		// One query for the meta of all of them, not one for each.
		if ( function_exists( 'update_meta_cache' ) ) {
			update_meta_cache( 'post', $ids );
		}

		$denied = array();

		foreach ( $ids as $post_id ) {
			$decision = Agend_Content_Access_Decision::for_post( $post_id, $anonymous );

			if ( Agend_Content_Access_Decision::STATE_GRANTED !== $decision['state'] ) {
				$denied[] = $post_id;
			}
		}

		return $denied;
	}

	/**
	 * A list of ids as unique positive integers, from an array or a comma separated
	 * string, which is how a query argument may arrive.
	 *
	 * @param mixed $value Candidate list.
	 * @return int[]
	 */
	private static function ids( $value ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$ids = array();

		foreach ( $value as $id ) {
			if ( is_numeric( $id ) && (int) $id > 0 ) {
				$ids[] = (int) $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}
}
