<?php
/**
 * Documents that carry rules of their own below the document policy.
 *
 * @package Agend_Content_Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Knows which posts hold section-level rules, and so which posts' RAW body is
 * more than the page shows.
 *
 * A document policy governs the whole document, and the gates elsewhere in this
 * plugin enforce it wherever the document renders. A section rule is different:
 * the page is public and one section of it is not. The rendered page withholds
 * that section from the people it is not for, but the post's raw body (what
 * `post_content` holds, what core search matches against, what an automatic
 * excerpt is cut from, what the REST edit context returns) is written by the page
 * builder with EVERY section in it. Anything that reads the raw body therefore
 * publishes the restricted section, and none of the render-time gates see it.
 *
 * This class is the one place that question is answered, so each reader asks the
 * same thing and gets the same answer.
 */
class Agend_Content_Access_Fragment_Rules {

	/**
	 * Post meta flagging a post whose document carries section-level rules.
	 *
	 * `1` when it does, absent when it does not. A CACHE of `carries()` for the one
	 * reader that cannot ask it per post: a search query has to filter in SQL.
	 * Every other reader asks the document itself, so a missing flag, on a post
	 * saved before this existed, never makes anything leak except through search,
	 * and the backfill below closes that.
	 *
	 * The name is shared with the block editor integration, which maintains the
	 * same flag for documents it understands. Do not rename it.
	 *
	 * @var string
	 */
	const META_KEY = '_agend_access_fragment_rules';

	/**
	 * Option holding the progress of the one-off backfill.
	 *
	 * @var string
	 */
	const BACKFILL_OPTION = 'agend_content_access_fragment_backfill';

	/**
	 * Posts examined per backfill pass.
	 *
	 * @var int
	 */
	const BACKFILL_BATCH = 100;

	/**
	 * Registers hooks.
	 *
	 * Called once at bootstrap, on every request: a search can be a REST request,
	 * a save can be an import, and a flag that is only maintained when wp-admin is
	 * loaded would drift.
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register_meta' ), 20 );
		add_filter( 'is_protected_meta', array( __CLASS__, 'protect_meta' ), 10, 2 );

		// Keeping the flag true.
		add_action( 'save_post', array( __CLASS__, 'sync_on_save' ), 20, 1 );
		// Elementor writes its document AFTER the `save_post` its own settings save
		// fires, so that hook sees the previous document. Its own save action is
		// the one that sees the new one.
		add_action( 'elementor/document/after_save', array( __CLASS__, 'sync_on_elementor_save' ), 10, 2 );
		// And a document written without a save at all, by an importer, WP-CLI or
		// a search and replace tool, changes only the meta.
		add_action( 'added_post_meta', array( __CLASS__, 'sync_on_meta_change' ), 10, 4 );
		add_action( 'updated_post_meta', array( __CLASS__, 'sync_on_meta_change' ), 10, 4 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'sync_on_meta_change' ), 10, 4 );

		// Using the flag, and the document, to keep the raw body out of the places
		// that read it.
		add_action( 'pre_get_posts', array( __CLASS__, 'exclude_from_search' ), PHP_INT_MAX );
		add_filter( 'get_the_excerpt', array( __CLASS__, 'filter_excerpt' ), 11, 2 );

		// Converging posts saved before the flag existed.
		add_action( 'admin_init', array( __CLASS__, 'maybe_backfill' ) );
	}

	/**
	 * Registers the flag as protected and absent from REST.
	 *
	 * Underscore-prefixed, so WordPress already hides it from the custom fields
	 * box. `show_in_rest` false keeps it out of `meta` on every REST item, and the
	 * auth callback refuses every capability check, so a request cannot clear it
	 * to bring a post back into search. This plugin writes it directly.
	 */
	public static function register_meta(): void {
		foreach ( Agend_Content_Access_Meta_Box::supported_post_types() as $post_type ) {
			register_post_meta(
				$post_type,
				self::META_KEY,
				array(
					'type'          => 'string',
					'single'        => true,
					'show_in_rest'  => false,
					'auth_callback' => '__return_false',
				)
			);
		}
	}

	/**
	 * Marks the flag protected explicitly, rather than relying on its prefix.
	 *
	 * @param bool   $protected Whether the key is protected so far.
	 * @param string $meta_key  Meta key.
	 * @return bool
	 */
	public static function protect_meta( $protected, $meta_key = '' ) {
		return self::META_KEY === $meta_key ? true : $protected;
	}

	// -----------------------------------------------------------------------
	// The question
	// -----------------------------------------------------------------------

	/**
	 * Whether a post carries section-level rules, decided from the document now.
	 *
	 * A LIVE check of the stored document, not a read of a cached flag, so it is
	 * correct for a post saved before any flag existed.
	 *
	 * @param WP_Post|int $post Post or post id.
	 * @return bool
	 */
	public static function carries( $post ): bool {
		$post_id = $post instanceof WP_Post ? (int) $post->ID : (int) $post;

		if ( $post_id <= 0 ) {
			return false;
		}

		$carries = self::carries_elementor_rules( $post_id );

		/**
		 * Filters whether a post carries section-level rules.
		 *
		 * The seam an editor integration plugs its own document format into, so a
		 * post built another way is covered by every reader that asks. Receives the
		 * answer so far: return it unchanged, or true, never false for a document
		 * that does carry rules.
		 *
		 * @param bool $carries Whether the post carries rules, as far as is known.
		 * @param int  $post_id Post id.
		 */
		return (bool) apply_filters( 'agend_content_access_post_carries_fragment_rules', $carries, $post_id );
	}

	/**
	 * Whether a post's Elementor document carries a fragment policy or display
	 * conditions anywhere in it.
	 *
	 * Reads the document whether or not Elementor is active now. Elementor keeps
	 * `_elementor_data` after it is deactivated, and the body it wrote into
	 * `post_content` stays too, so a site that removes Elementor is not thereby
	 * rid of the text of its restricted sections.
	 *
	 * @param int $post_id Post id.
	 * @return bool
	 */
	public static function carries_elementor_rules( int $post_id ): bool {
		if ( ! class_exists( 'Agend_Content_Access_Elementor', false ) ) {
			require_once __DIR__ . '/class-agend-content-access-elementor.php';
		}

		return Agend_Content_Access_Elementor::data_carries_policy(
			get_post_meta( $post_id, '_elementor_data', true )
		);
	}
	// -----------------------------------------------------------------------
	// The flag
	// -----------------------------------------------------------------------

	/**
	 * Brings a post's flag in line with its document.
	 *
	 * Writes only when the answer changed, so the common save of a post that has
	 * none, and never had, costs no query.
	 *
	 * @param int $post_id Post id.
	 * @return bool Whether the post carries section-level rules.
	 */
	public static function sync( int $post_id ): bool {
		if ( $post_id <= 0 || wp_is_post_revision( $post_id ) ) {
			return false;
		}

		$carries = self::carries( $post_id );
		$flagged = '1' === (string) get_post_meta( $post_id, self::META_KEY, true );

		if ( $carries && ! $flagged ) {
			update_post_meta( $post_id, self::META_KEY, '1' );
		} elseif ( ! $carries && $flagged ) {
			delete_post_meta( $post_id, self::META_KEY );
		}

		return $carries;
	}

	/**
	 * Keeps the flag true when a post is saved.
	 *
	 * Revisions and autosaves are skipped: neither is what a visitor reads, and
	 * both fire this hook for a row that is not the post.
	 *
	 * @param int $post_id Post id.
	 */
	public static function sync_on_save( $post_id ): void {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_autosave( (int) $post_id ) ) {
			return;
		}

		self::sync( (int) $post_id );
	}

	/**
	 * Keeps the flag true when Elementor has saved a document.
	 *
	 * Guarded: the action's argument is Elementor's own object, and this must not
	 * assume a shape a later release changes. With no usable id nothing is done,
	 * which leaves the other hooks and the backfill to catch it.
	 *
	 * @param mixed $document Elementor document that was saved.
	 */
	public static function sync_on_elementor_save( $document ): void {
		if ( ! is_object( $document ) || ! method_exists( $document, 'get_main_id' ) ) {
			return;
		}

		self::sync( (int) $document->get_main_id() );
	}

	/**
	 * Keeps the flag true when a document is written without a save.
	 *
	 * @param mixed  $meta_id   Meta row id, or ids.
	 * @param int    $object_id Post id.
	 * @param string $meta_key  Meta key written.
	 */
	public static function sync_on_meta_change( $meta_id, $object_id = 0, $meta_key = '' ): void {
		if ( '_elementor_data' !== $meta_key ) {
			return;
		}

		self::sync( (int) $object_id );
	}

	// -----------------------------------------------------------------------
	// Search
	// -----------------------------------------------------------------------

	/**
	 * Keeps posts that carry section rules out of search for people who cannot
	 * edit everyone's posts.
	 *
	 * Core search matches its terms against the raw `post_content`, which holds
	 * the text of every section including the restricted ones, so a visitor could
	 * find a public page by a word only its members-only section contains, and by
	 * narrowing the search learn what that section says. The result then shows the
	 * public page, with the section gated, and nothing on it betrays why it matched.
	 *
	 * Last in the chain, and wrapping what is already there rather than adding to
	 * it, so a theme's own `pre_get_posts` can neither run after this and replace
	 * the meta query, nor OR its way past the guard: the existing query becomes one
	 * clause inside an AND with the guard.
	 *
	 * Applies to every query with a search term: the front end, `?search=` on the
	 * REST routes, and `/wp/v2/search`. It does not apply to people who can edit
	 * others' posts, who can read the raw body anyway. Authors and contributors are
	 * covered, so they cannot find their OWN such pages by text either; that is the
	 * price of not being able to tell them from a visitor in a query.
	 *
	 * @param mixed $query The query.
	 */
	public static function exclude_from_search( $query ): void {
		if ( ! $query instanceof WP_Query || ! self::has_search_term( $query ) ) {
			return;
		}

		/**
		 * Filters whether search leaves out posts that carry section-level rules.
		 *
		 * True by default, and it should stay true: a post left in is a post whose
		 * restricted sections can be searched. For a site that has decided its
		 * sections are not secret and wants them found.
		 *
		 * @param bool $excludes Whether to leave them out.
		 */
		if ( ! (bool) apply_filters( 'agend_content_access_search_excludes_fragment_rules', true ) ) {
			return;
		}

		if ( current_user_can( 'edit_others_posts' ) ) {
			return;
		}

		$guard    = array(
			'key'     => self::META_KEY,
			'compare' => 'NOT EXISTS',
		);
		$existing = $query->get( 'meta_query' );

		$query->set(
			'meta_query',
			is_array( $existing ) && array() !== $existing
				? array( 'relation' => 'AND', $guard, $existing )
				: array( $guard )
		);
	}

	/**
	 * Whether a query carries a search term.
	 *
	 * Anything other than an empty string counts, an array included, because core
	 * reads some shapes this would otherwise have to enumerate.
	 *
	 * @param WP_Query $query The query.
	 * @return bool
	 */
	private static function has_search_term( $query ): bool {
		$term = $query->get( 's' );

		if ( is_array( $term ) ) {
			return array() !== $term;
		}

		return is_scalar( $term ) && '' !== trim( (string) $term );
	}

	// -----------------------------------------------------------------------
	// Excerpts
	// -----------------------------------------------------------------------

	/**
	 * Keeps an excerpt generated from the raw body from carrying a restricted
	 * section.
	 *
	 * Core builds an automatic excerpt from the first words of the body, and for a
	 * page-builder post the body holds every section. After core has done it
	 * (priority 11, `wp_trim_excerpt()` being 10) a post that carries section rules
	 * and has no written excerpt yields nothing. A written excerpt is an editor's
	 * deliberate summary and is left alone.
	 *
	 * An Elementor document yields an empty string and nothing can change that:
	 * the plain text it stored cannot be told apart from the sections it came from.
	 * A document in another format can supply a rebuilt excerpt on
	 * `agend_content_access_safe_generated_excerpt`, which is how a block document,
	 * whose sections can be rendered for the visitor, gets one. Anything that is not
	 * a string from it is discarded.
	 *
	 * Decided from the document, not from the flag, so it is right for a post
	 * saved before the flag existed. A post with an audience of its own is gated by
	 * the excerpt filter that runs after this, which has the last word.
	 *
	 * @param string       $excerpt Excerpt as built so far.
	 * @param WP_Post|null $post    Post the excerpt is for.
	 * @return string
	 */
	public static function filter_excerpt( $excerpt, $post = null ) {
		if ( ! $post instanceof WP_Post || ! Agend_Content_Access_Frontend::renders_for_visitors( (int) $post->ID ) ) {
			return $excerpt;
		}

		if ( has_excerpt( $post ) || ! self::carries( $post ) ) {
			return $excerpt;
		}

		if ( self::carries_elementor_rules( (int) $post->ID ) ) {
			return '';
		}

		/**
		 * Supplies an excerpt for a post that carries section-level rules and has
		 * none written.
		 *
		 * The default is an empty string: nothing is safe to show until something
		 * that understands the document says so. Whatever is returned is published,
		 * so it must be built from what THIS visitor may read, never from the raw
		 * body.
		 *
		 * @param string  $safe Excerpt to show. Empty by default.
		 * @param WP_Post $post Post the excerpt is for.
		 */
		$safe = apply_filters( 'agend_content_access_safe_generated_excerpt', '', $post );

		return is_string( $safe ) ? $safe : '';
	}

	// -----------------------------------------------------------------------
	// Backfill
	// -----------------------------------------------------------------------

	/**
	 * Flags one batch of posts, in id order, after a given id.
	 *
	 * @param int           $after_id Last post id already examined.
	 * @param int           $limit    Posts to examine.
	 * @param callable|null $fetch    Returns the ids, for a test: `fn( int $after_id, int $limit ): int[]`.
	 * @return array{processed: int, flagged: int, last_id: int, done: bool}
	 */
	public static function backfill_batch( int $after_id, int $limit = self::BACKFILL_BATCH, ?callable $fetch = null ): array {
		$limit = max( 1, $limit );
		$ids   = null === $fetch ? self::post_ids_after( $after_id, $limit ) : (array) $fetch( $after_id, $limit );

		// One query for the batch's meta rather than one per post.
		if ( array() !== $ids && function_exists( 'update_meta_cache' ) ) {
			update_meta_cache( 'post', $ids );
		}

		$flagged = 0;
		$last_id = $after_id;

		foreach ( $ids as $id ) {
			$id = (int) $id;

			if ( self::sync( $id ) ) {
				++$flagged;
			}

			$last_id = max( $last_id, $id );
		}

		return array(
			'processed' => count( $ids ),
			'flagged'   => $flagged,
			'last_id'   => $last_id,
			// A short batch is the end. A full one may be, and costs one more empty
			// pass to find out.
			'done'      => count( $ids ) < $limit,
		);
	}

	/**
	 * Ids of the posts to examine, after a given id, lowest first.
	 *
	 * Every status but `auto-draft`, so a draft, a scheduled post or one in the
	 * trash already has its flag right on the day it is published or restored.
	 *
	 * @param int $after_id Last id examined.
	 * @param int $limit    Rows.
	 * @return int[]
	 */
	public static function post_ids_after( int $after_id, int $limit ): array {
		global $wpdb;

		$types = Agend_Content_Access_Meta_Box::supported_post_types();

		if ( array() === $types ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $types ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table name and a generated placeholder list; every value is bound.
		$sql = $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type IN ( {$placeholders} ) AND post_status <> 'auto-draft' ORDER BY ID ASC LIMIT %d",
			array_merge( array( $after_id ), array_values( $types ), array( $limit ) )
		);

		return array_map( 'intval', (array) $wpdb->get_col( $sql ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * The progress of the one-off backfill.
	 *
	 * @return array{last_id: int, done: bool}
	 */
	public static function backfill_state(): array {
		$stored = get_option( self::BACKFILL_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return array(
			'last_id' => isset( $stored['last_id'] ) ? max( 0, (int) $stored['last_id'] ) : 0,
			'done'    => ! empty( $stored['done'] ),
		);
	}

	/**
	 * Records the progress of the backfill.
	 *
	 * Not autoloaded while it runs, so a site that is partway through carries no
	 * extra weight on every request; autoloaded once done, because every wp-admin
	 * page for a manager reads it to find that out, and a lookup that costs a query
	 * for ever is the worse trade.
	 *
	 * @param int  $last_id Last post id examined.
	 * @param bool $done    Whether the whole table has been.
	 */
	private static function save_backfill_state( int $last_id, bool $done ): void {
		update_option(
			self::BACKFILL_OPTION,
			array(
				'last_id' => $last_id,
				'done'    => $done,
			),
			$done
		);
	}

	/**
	 * Flags posts saved before the flag existed, a batch at a time.
	 *
	 * Runs for a user who can manage options, on a wp-admin page load, until the
	 * whole table has been examined, so the flag converges on its own with nobody
	 * having to run anything. A hundred posts a pass, so no one page load pays for
	 * a big site; the same hundred processed twice by two administrators at once is
	 * harmless, since the flag is written only when it is wrong.
	 *
	 * Skipped for AJAX: heartbeat requests would run it every fifteen seconds in a
	 * tab nobody is looking at.
	 */
	public static function maybe_backfill(): void {
		if ( wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$state = self::backfill_state();

		if ( $state['done'] ) {
			return;
		}

		$result = self::backfill_batch( $state['last_id'] );

		self::save_backfill_state( $result['last_id'], $result['done'] );
	}

	/**
	 * Flags every post, from the start, in batches.
	 *
	 * What `wp agend-content-access backfill-fragment-flags` runs. Starts from the
	 * first post whatever the one-off recorded, so it can be run again after an
	 * integration that understands another document format is installed, and marks
	 * the one-off done when it finishes so the admin pass does not repeat it.
	 *
	 * @param int           $batch    Posts per pass.
	 * @param callable|null $progress Called after each pass with its result.
	 * @param callable|null $fetch    Returns the ids, for a test.
	 * @return array{processed: int, flagged: int}
	 */
	public static function backfill_all( int $batch = self::BACKFILL_BATCH, ?callable $progress = null, ?callable $fetch = null ): array {
		$last_id   = 0;
		$processed = 0;
		$flagged   = 0;

		do {
			$result = self::backfill_batch( $last_id, $batch, $fetch );

			$last_id    = $result['last_id'];
			$processed += $result['processed'];
			$flagged   += $result['flagged'];

			self::save_backfill_state( $last_id, $result['done'] );

			if ( null !== $progress ) {
				$progress( $result );
			}
			// A pass that examined nothing is the end whatever it reported, so a
			// fetcher that never says "done" cannot hold this in a loop.
		} while ( ! $result['done'] && $result['processed'] > 0 );

		return array(
			'processed' => $processed,
			'flagged'   => $flagged,
		);
	}
}
