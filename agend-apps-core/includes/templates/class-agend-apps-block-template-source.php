<?php
/**
 * Block-editor implementation of the Agend Apps Core template picker source.
 *
 * A card or detail template authored in the block editor is a `wp_block`
 * post: WordPress's own reusable-block/synced-pattern post type. Chosen over
 * the alternatives because it is the only block-content container that is
 * BOTH addressable by a bare integer post id, which
 * {@see Agend_Apps_Templates} requires so it can ask "whose is this id" of
 * every registered renderer, AND gets a real edit-once/applies-everywhere
 * editing UI for free: a registered block PATTERN has no post id at all, and
 * `wp_template_part` needs a block theme, which most sites running Agend do
 * not have.
 *
 * A `wp_block` post pool holds every reusable block on the site, almost all
 * of them nothing to do with Agend. Rather than detect a qualifying template
 * on read (parsing every candidate's content on every picker render), a
 * `save_post_wp_block` hook here tags a post's content once, on save: if it
 * contains a block whose name starts `agend-apps/record-` (the five
 * record-authoring blocks; a separate, later task this one is designed to
 * sit alongside), a postmeta flag is written recording that fact together
 * with the record type the template implies, derived with
 * {@see agend_apps_records_type_from_key()} against each record block's
 * `field`/`block` attribute, the same helper
 * {@see Agend_Elementor_Preview_Type} uses to infer an Elementor template's
 * preview type. {@see self::templates()} then filters on that flag via
 * `meta_query`, exactly as {@see Agend_Elementor_Templates} filters on
 * `_elementor_template_type`.
 *
 * The five record blocks do not exist yet, so nothing on a real site
 * satisfies this predicate until they ship; the tagging predicate is tested
 * here against synthetic block content built directly from the
 * `agend-apps/record-` naming convention the record blocks are specified to
 * use.
 *
 * Ships inside Agend Apps Core and is always present (unlike Elementor's
 * template source, which only exists when the Elementor plugin does), so
 * this is the concrete class itself rather than an adapter delegating to a
 * separate static class.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists and tags block-editor card/detail templates.
 */
final class Agend_Apps_Block_Template_Source implements Agend_Apps_Template_Source {

	/**
	 * Post meta key flagging a `wp_block` post as a qualifying Agend template
	 * and recording the record type it implies ('event', 'course' or
	 * 'listing'). The value doubles as the flag: {@see self::query_candidates()}
	 * matches on the key's mere presence, so a template is untagged (meta
	 * deleted) rather than tagged with an empty value when it stops
	 * qualifying. Leading underscore keeps it out of the custom-fields UI.
	 *
	 * @var string
	 */
	const TYPE_META_KEY = '_agend_apps_block_template_type';

	/** Transient key the resolved id => title map is cached under. */
	const TRANSIENT_KEY = 'agend_apps_block_template_options';

	/** Per-request cache, so repeated calls in the same request never re-query. */
	private static ?array $cache = null;

	/**
	 * The builder label appended after a template title when more than one
	 * source is registered, e.g. "Event card (Blocks)" (Decision D8).
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Blocks', 'agend-apps-core' );
	}

	/**
	 * The eligible templates this source offers, without a placeholder entry
	 * (per the {@see Agend_Apps_Template_Source} contract).
	 *
	 * @return array<string, string>
	 */
	public function templates(): array {
		return self::map();
	}

	/**
	 * The id => title map of qualifying templates, string keys.
	 *
	 * Split out from {@see self::templates()} so a test can assert on the
	 * resolved map directly, mirroring {@see Agend_Elementor_Templates::map()}.
	 *
	 * @return array<string, string>
	 */
	public static function map(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$cached = get_transient( self::TRANSIENT_KEY );
		if ( is_array( $cached ) ) {
			self::$cache = $cached;
			return self::$cache;
		}

		$map = self::build_map();

		self::$cache = $map;
		set_transient( self::TRANSIENT_KEY, $map, 5 * MINUTE_IN_SECONDS );

		return $map;
	}

	/**
	 * Builds the id => title map from real candidates or, in tests, the
	 * {@see 'agend_apps_block_template_candidate_ids'} filter.
	 *
	 * Mirrors {@see Agend_Elementor_Templates::build_map()}: the filter exists
	 * to make this class testable without a `WP_Query` stub. A non-null
	 * return value replaces the query entirely and is trusted as-is (order
	 * preserved, keys cast to strings).
	 *
	 * @return array<string, string>
	 */
	private static function build_map(): array {
		/**
		 * Filters the candidate template id => title map, bypassing the
		 * WP_Query lookup entirely when non-null.
		 *
		 * @param array<int|string, string>|null $candidates Null to run the real query.
		 */
		$candidates = apply_filters( 'agend_apps_block_template_candidate_ids', null );

		if ( is_array( $candidates ) ) {
			$map = array();
			foreach ( $candidates as $id => $title ) {
				$map[ (string) $id ] = (string) $title;
			}

			return $map;
		}

		return self::query_candidates();
	}

	/**
	 * Runs the real `wp_block` lookup: published posts flagged with
	 * {@see self::TYPE_META_KEY}, ordered by title.
	 *
	 * @return array<string, string>
	 */
	private static function query_candidates(): array {
		$query = new WP_Query(
			array(
				'post_type'      => 'wp_block',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'meta_query'     => array(
					array(
						'key'     => self::TYPE_META_KEY,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$map = array();
		foreach ( (array) $query->posts as $id ) {
			$map[ (string) $id ] = (string) get_the_title( $id );
		}

		return $map;
	}

	/** Clears both the per-request and the transient cache. */
	public static function flush_cache(): void {
		self::$cache = null;
		delete_transient( self::TRANSIENT_KEY );
	}

	/**
	 * `save_post_wp_block` handler: tags or untags a post as a qualifying
	 * Agend template (Decision D2).
	 *
	 * No revision/autosave guard is needed here, unlike a generic `save_post`
	 * handler: `save_post_wp_block` only ever fires for a post actually of
	 * type `wp_block`, and WordPress always stores an autosave or revision as
	 * a `revision`-type post regardless of the post it revises, so this
	 * handler is never invoked for one.
	 *
	 * @param int          $post_id Post id.
	 * @param WP_Post|null $post    The saved post. Re-fetched when not given,
	 *                              so this method also works called directly.
	 * @return void
	 */
	public static function tag_post( int $post_id, $post = null ): void {
		if ( ! ( $post instanceof WP_Post ) ) {
			$post = get_post( $post_id );
		}

		if ( ! ( $post instanceof WP_Post ) || 'wp_block' !== $post->post_type ) {
			return;
		}

		$type = self::implied_type( (string) $post->post_content );

		if ( '' !== $type ) {
			update_post_meta( $post_id, self::TYPE_META_KEY, $type );
		} else {
			delete_post_meta( $post_id, self::TYPE_META_KEY );
		}

		self::flush_cache();
	}

	/**
	 * The record type a template's serialised content implies, or '' when it
	 * contains no `agend-apps/record-*` block.
	 *
	 * Its only WordPress dependency is `parse_blocks()`, so a test can drive
	 * it directly with synthetic block content, matching the tagging
	 * predicate this class is built around.
	 *
	 * @param string $content A `wp_block` post's `post_content`.
	 * @return string 'event', 'course', 'listing', or ''.
	 */
	public static function implied_type( string $content ): string {
		return self::first_record_block_type( parse_blocks( $content ) );
	}

	/**
	 * Walks a parsed block tree depth-first for the first record type an
	 * `agend-apps/record-*` block's `field` or `block` attribute implies.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return string 'event', 'course', 'listing', or ''.
	 */
	private static function first_record_block_type( array $blocks ): string {
		foreach ( $blocks as $block ) {
			$name = $block['blockName'] ?? null;

			if ( is_string( $name ) && 0 === strpos( $name, 'agend-apps/record-' ) ) {
				$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
				$type  = self::type_from_attrs( $attrs );

				if ( '' !== $type ) {
					return $type;
				}
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$nested = self::first_record_block_type( $block['innerBlocks'] );

				if ( '' !== $nested ) {
					return $nested;
				}
			}
		}

		return '';
	}

	/**
	 * The record type a record block's saved attributes imply.
	 *
	 * @param array<string, mixed> $attrs One block's attrs.
	 * @return string 'event', 'course', 'listing', or ''.
	 */
	private static function type_from_attrs( array $attrs ): string {
		if ( ! function_exists( 'agend_apps_records_type_from_key' ) ) {
			return '';
		}

		foreach ( array( 'field', 'block' ) as $key ) {
			$type = agend_apps_records_type_from_key( (string) ( $attrs[ $key ] ?? '' ) );

			if ( '' !== $type ) {
				return $type;
			}
		}

		return '';
	}

	/**
	 * Whether the page contains one of this source's catalogue block
	 * surfaces (Decision D7).
	 *
	 * `has_block()` is core's own maintained equivalent of
	 * {@see Agend_Elementor_Template_Source_Adapter::page_contains_surface()}'s
	 * hand-rolled substring match. Its one known false negative: a surface
	 * placed inside a synced pattern that the host page only references by
	 * id is invisible to it, because the host page's own content holds just
	 * the reference, not the pattern's content. Acceptable, since this method
	 * is explicitly advisory ({@see Agend_Apps_Template_Source} docblock) and
	 * `null`/`false` only ever suppress an advisory notice, never block a
	 * save.
	 *
	 * @param int    $page_id The page to inspect.
	 * @param string $surface Agnostic surface kind, e.g. 'events-catalogue'.
	 * @return bool|null
	 */
	public function page_contains_surface( int $page_id, string $surface ): ?bool {
		$block_names = array(
			'events-catalogue'    => 'agend-apps/events-catalogue',
			'courses-catalogue'   => 'agend-apps/courses-catalogue',
			'directory-catalogue' => 'agend-apps/directory-catalogue',
		);

		if ( ! isset( $block_names[ $surface ] ) ) {
			return null;
		}

		$post = get_post( $page_id );

		if ( ! ( $post instanceof WP_Post ) ) {
			return null;
		}

		return has_block( $block_names[ $surface ], $post );
	}
}

// Guarded so this file loads under the unit-test stub harness, where
// add_action() is itself a stub rather than absent.
if ( function_exists( 'add_action' ) ) {
	add_action( 'save_post_wp_block', array( 'Agend_Apps_Block_Template_Source', 'tag_post' ), 10, 2 );
	add_action( 'trashed_post', array( 'Agend_Apps_Block_Template_Source', 'flush_cache' ) );
	add_action( 'deleted_post', array( 'Agend_Apps_Block_Template_Source', 'flush_cache' ) );
}
