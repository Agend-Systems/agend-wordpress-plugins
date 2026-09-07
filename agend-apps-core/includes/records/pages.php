<?php
/**
 * Dedicated Events/Courses catalogue pages.
 *
 * A catalogue widget's detail routing has always resolved against whatever
 * page hosts the widget instance (see routing.php and
 * ssr-detail.php), so a catalogue placed as a homepage
 * CTA turns the homepage into the detail page for whatever item a visitor
 * opens. This class lets a site nominate one dedicated page per catalogue
 * type; every widget instance then links back to that page instead of taking
 * over its own host page. With no page configured for a type, behaviour is
 * unchanged from before this class existed.
 *
 * Loaded unconditionally from the plugin bootstrap, like the settings file,
 * so the wp:4 redirect below and the widgets' page_url()/detail_url() calls
 * work even before Elementor or Agend Apps Core finish bootstrapping.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static accessor for the dedicated catalogue pages (Events, Courses).
 */
class Agend_Apps_Records_Pages {

	/**
	 * Per-type configuration: the option storing the configured page id, the
	 * detail URL path segment, the rewrite-endpoint query var, and the legacy
	 * (non-pretty) query-string parameter name.
	 *
	 * @return array<string, array{option: string, segment: string, query_var: string, legacy: string}>
	 */
	public static function types(): array {
		return array(
			'event'  => array(
				'option'    => AGEND_APPS_RECORDS_EVENTS_PAGE_OPTION,
				'segment'   => 'event/',
				'query_var' => 'event',
				'legacy'    => 'agend_event',
			),
			'course' => array(
				'option'    => AGEND_APPS_RECORDS_COURSES_PAGE_OPTION,
				'segment'   => 'course/',
				'query_var' => 'course',
				'legacy'    => 'agend_course',
			),
			// The directory detail path is /{page}/listing/{slug}/ like the
			// others, but the slug arrives on a namespaced private query var
			// (see routing.php).
			'listing' => array(
				'option'    => AGEND_APPS_RECORDS_DIRECTORY_PAGE_OPTION,
				'segment'   => 'listing/',
				'query_var' => 'agend_dir_listing',
				'legacy'    => 'agend_listing',
			),
		);
	}

	/**
	 * Resolves the configured dedicated page id for a catalogue type.
	 *
	 * Returns 0 for an unknown type, an unset option, or a configured page
	 * that is no longer a published page (trashed, deleted, or converted to
	 * another post type), the same as unset, so a widget silently falls back
	 * to today's host-page behaviour rather than linking to a dead page.
	 *
	 * @param string $type 'event' or 'course'.
	 * @return int The dedicated page id, or 0 when unset/invalid.
	 */
	public static function page_id( string $type ): int {
		$types = self::types();
		if ( ! isset( $types[ $type ] ) ) {
			return 0;
		}

		$id = absint( get_option( $types[ $type ]['option'], 0 ) );
		if ( 0 === $id ) {
			return 0;
		}

		$post = get_post( $id );
		if ( ! ( $post instanceof WP_Post ) || 'page' !== $post->post_type || 'publish' !== $post->post_status ) {
			return 0;
		}

		/**
		 * Filters the resolved dedicated page id for a catalogue type.
		 *
		 * Only ever called with a validated, published page id, so a filter
		 * cannot use this hook to resurrect a trashed page; it can only
		 * redirect an already-valid configuration elsewhere.
		 *
		 * @param int    $id   The validated dedicated page id.
		 * @param string $type 'event' or 'course'.
		 */
		$id = absint( apply_filters( 'agend_apps_records_dedicated_page_id', $id, $type ) );

		// A site's existing add_filter() on the pre-rename hook name still
		// applies for one release.
		return absint( apply_filters_deprecated( 'agend_elementor_dedicated_page_id', array( $id, $type ), '1.8.0', 'agend_apps_records_dedicated_page_id' ) );
	}

	/**
	 * The dedicated page's URL, or an empty string when unset.
	 *
	 * @param string $type 'event' or 'course'.
	 * @return string The page URL, or ''.
	 */
	public static function page_url( string $type ): string {
		$id = self::page_id( $type );
		if ( 0 === $id ) {
			return '';
		}

		$url = get_permalink( $id );
		return is_string( $url ) ? $url : '';
	}

	/**
	 * Builds an item's detail URL against a given host page id.
	 *
	 * Pretty permalinks: {page}/{segment}{slug}/. Otherwise the legacy
	 * ?agend_event=/?agend_course= query parameter appended to the page URL.
	 * An empty slug returns the bare page URL (no dangling path segment or
	 * query parameter).
	 *
	 * @param array<string, string> $type_cfg One entry from self::types().
	 * @param int                   $page_id  The host page id to build against.
	 * @param string                $slug     The item slug.
	 * @return string The detail URL, or '' when the page id has no permalink.
	 */
	private static function build_url( array $type_cfg, int $page_id, string $slug ): string {
		$base = get_permalink( $page_id );
		if ( ! is_string( $base ) || '' === $base ) {
			return '';
		}

		if ( '' === $slug ) {
			return $base;
		}

		if ( get_option( 'permalink_structure' ) ) {
			return trailingslashit( $base ) . $type_cfg['segment'] . rawurlencode( $slug ) . '/';
		}

		$sep = false !== strpos( $base, '?' ) ? '&' : '?';
		return $base . $sep . $type_cfg['legacy'] . '=' . rawurlencode( $slug );
	}

	/**
	 * Resolves an item's detail URL: the configured dedicated page when set,
	 * else the given fallback host page (today's behaviour, unchanged).
	 *
	 * @param string $type              'event' or 'course'.
	 * @param string $slug              The item slug.
	 * @param int    $fallback_page_id  The host page id to fall back to when
	 *                                  no dedicated page is configured.
	 * @return string The detail URL, or '' when neither page resolves.
	 */
	public static function detail_url( string $type, string $slug, int $fallback_page_id = 0 ): string {
		$types = self::types();
		if ( ! isset( $types[ $type ] ) ) {
			return '';
		}
		$type_cfg = $types[ $type ];

		$dedicated_id = self::page_id( $type );
		$page_id      = $dedicated_id > 0 ? $dedicated_id : $fallback_page_id;

		$url = $page_id > 0 ? self::build_url( $type_cfg, $page_id, $slug ) : '';

		/**
		 * Filters a catalogue item's resolved detail URL.
		 *
		 * @param string $url     The resolved detail URL, or ''.
		 * @param string $type    'event' or 'course'.
		 * @param string $slug    The item slug.
		 * @param int    $page_id The host page id the URL was built against (0 when unresolved).
		 */
		$url = (string) apply_filters( 'agend_apps_records_detail_url', $url, $type, $slug, $page_id );

		// A site's existing add_filter() on the pre-rename hook name still
		// applies for one release.
		return (string) apply_filters_deprecated( 'agend_elementor_detail_url', array( $url, $type, $slug, $page_id ), '1.8.0', 'agend_apps_records_detail_url' );
	}

	/**
	 * Whether the given page id is the configured dedicated page for a type.
	 *
	 * @param string $type    'event' or 'course'.
	 * @param int    $page_id The page id to test.
	 * @return bool True when $page_id is the configured dedicated page.
	 */
	public static function is_dedicated_page( string $type, int $page_id ): bool {
		return $page_id > 0 && $page_id === self::page_id( $type );
	}

	/**
	 * Decides whether a request for an item on the given page should redirect
	 * to the dedicated page instead.
	 *
	 * Empty when the slug is empty, when no dedicated page is configured for
	 * the type, or when the current page already is the dedicated page.
	 *
	 * @param string $type             'event' or 'course'.
	 * @param string $slug             The item slug.
	 * @param int    $current_page_id  The page id the request landed on.
	 * @return string The dedicated page's detail URL to redirect to, or ''.
	 */
	public static function redirect_target( string $type, string $slug, int $current_page_id ): string {
		if ( '' === $slug ) {
			return '';
		}
		if ( 0 === self::page_id( $type ) ) {
			return '';
		}
		if ( self::is_dedicated_page( $type, $current_page_id ) ) {
			return '';
		}

		return self::detail_url( $type, $slug );
	}

	/**
	 * The configured Elementor detail template id for a type, or 0 when none.
	 *
	 * Nothing reads this until the detail-template settings fields exist; the
	 * option names are fixed here so those fields and the SSR resolvers agree.
	 *
	 * @param string $type 'event' or 'course'.
	 * @return int The template post id, or 0.
	 */
	public static function detail_template_id( string $type ): int {
		if ( ! isset( self::types()[ $type ] ) ) {
			return 0;
		}
		return absint( get_option( 'agend_elementor_' . $type . '_detail_template', 0 ) );
	}
}

/**
 * Redirects a catalogue detail request to its dedicated page.
 *
 * Runs on `wp` at priority 4, before the SSR virtual-page swap at priority 5
 * (ssr-detail.php), so a request that lands on a
 * non-dedicated page with an item slug is sent to the dedicated page before
 * anything renders there. GET requests only, and only for the main query on
 * a normal front-end document request.
 */
function agend_apps_records_maybe_redirect_to_dedicated_page(): void {
	if ( is_admin() || wp_doing_ajax() || is_feed() || is_embed() ) {
		return;
	}
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return;
	}
	if ( 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
		return;
	}

	global $wp_query;
	if ( ! $wp_query->is_main_query() ) {
		return;
	}

	$current_page_id = get_queried_object_id();

	foreach ( Agend_Apps_Records_Pages::types() as $type => $cfg ) {
		// The pretty path sets the rewrite-endpoint query var directly; the
		// legacy ?agend_event=/?agend_course= form arrives as a public query
		// var too (registered in routing.php), so both
		// forms are visible the same way here.
		$slug = sanitize_title( (string) get_query_var( $cfg['query_var'] ) );
		if ( '' === $slug ) {
			$slug = sanitize_title( (string) get_query_var( $cfg['legacy'] ) );
		}
		if ( '' === $slug ) {
			continue;
		}

		$target = Agend_Apps_Records_Pages::redirect_target( $type, $slug, $current_page_id );
		if ( '' === $target ) {
			continue;
		}

		wp_safe_redirect( esc_url_raw( $target ), 301 );
		exit;
	}
}
add_action( 'wp', 'agend_apps_records_maybe_redirect_to_dedicated_page', 4 );
