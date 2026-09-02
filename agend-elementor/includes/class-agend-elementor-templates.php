<?php
/**
 * Elementor template picker options for the Card Template / Detail Template
 * SELECT controls.
 *
 * Elementor templates are `elementor_library` posts; the type worth offering
 * here (page, section, container -- Loop Item is Elementor Pro only, so it is
 * deliberately excluded) is stored in the `_elementor_template_type` post
 * meta, not the post type itself, so listing candidates needs a meta query
 * rather than a plain post-type query.
 *
 * @package Agend_Elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static accessor for the Card/Detail Template SELECT options.
 */
final class Agend_Elementor_Templates {

	/** Template types offered by the picker. Loop Item is Elementor Pro only. */
	const ALLOWED_TYPES = array( 'page', 'section', 'container' );

	/** Transient key the resolved id => title map is cached under. */
	const TRANSIENT_KEY = 'agend_elementor_template_options';

	/** Per-request cache, so repeated calls in the same request never re-query. */
	private static ?array $cache = null;

	/**
	 * The id => title map of eligible templates, string keys.
	 *
	 * Not part of the public {@see self::options()} result (which additionally
	 * carries the placeholder); split out so tests can assert on the resolved
	 * map directly.
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
	 * The full SELECT options array: placeholder first, then eligible
	 * templates ordered by title.
	 *
	 * @param string $placeholder Placeholder label. Defaults to a translated
	 *                            "Default (built-in layout)" when empty.
	 * @return array<string, string> Keyed '' => placeholder, then string
	 *                                template ids => titles.
	 */
	public static function options( string $placeholder = '' ): array {
		$label = '' !== $placeholder ? $placeholder : __( 'Default (built-in layout)', 'agend-elementor' );

		return array( '' => $label ) + self::map();
	}

	/**
	 * Builds the id => title map from real candidates or, in tests, the
	 * {@see 'agend_elementor_template_candidate_ids'} filter.
	 *
	 * The filter exists to make this class testable without a WP_Query stub:
	 * a non-null return value replaces the query entirely and is trusted
	 * as-is (order preserved, keys cast to strings).
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
		$candidates = apply_filters( 'agend_elementor_template_candidate_ids', null );

		if ( is_array( $candidates ) ) {
			// PHP coerces a numeric-looking string array key back to int, so
			// this cast does not change the key's runtime type for a real
			// post id -- it only guarantees every value on this side is a
			// string, and it keeps the empty-placeholder key ('' -- never
			// numeric) unambiguously distinct from any real template id, the
			// coexistence Elementor's SELECT control actually needs.
			$map = array();
			foreach ( $candidates as $id => $title ) {
				$map[ (string) $id ] = (string) $title;
			}

			return $map;
		}

		return self::query_candidates();
	}

	/**
	 * Runs the real `elementor_library` lookup: published templates whose
	 * `_elementor_template_type` is one of {@see self::ALLOWED_TYPES},
	 * ordered by title.
	 *
	 * @return array<string, string>
	 */
	private static function query_candidates(): array {
		$query = new WP_Query(
			array(
				'post_type'      => 'elementor_library',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'meta_query'     => array(
					array(
						'key'     => '_elementor_template_type',
						'value'   => self::ALLOWED_TYPES,
						'compare' => 'IN',
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
}

// Guarded so this file loads under the unit-test stub harness, where
// add_action() is itself a stub rather than absent.
if ( function_exists( 'add_action' ) ) {
	add_action( 'save_post_elementor_library', array( 'Agend_Elementor_Templates', 'flush_cache' ) );
	add_action( 'trashed_post', array( 'Agend_Elementor_Templates', 'flush_cache' ) );
	add_action( 'deleted_post', array( 'Agend_Elementor_Templates', 'flush_cache' ) );
}
