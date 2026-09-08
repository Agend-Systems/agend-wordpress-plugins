<?php
/**
 * Server-rendered detail pages for the Agend catalogue widgets.
 *
 * When the "Server-rendered detail pages" setting is on, a catalogue detail URL
 * (the Directory widget's /{page}/listing/{slug}/, the Events widget's
 * /{page}/event/{slug}/, and the Courses widget's /{page}/course/{slug}/) is
 * resolved server-side into a virtual child page of the page holding the
 * catalogue widget: the item name becomes the page title and the catalogue page
 * its parent, so any breadcrumb system natively renders Home > Catalogue > Item,
 * and the detail body is rendered server-side for SEO. Progressive enhancement
 * is layered on the read-only markup: directory review submit + gallery lightbox
 * (assets/js/directory-detail.js), and the events registration flow (reusing
 * assets/js/events-catalogue.js). Course enrolment is a member sign-in link.
 *
 * The virtual-page synthesis is shared by all three widgets
 * (agend_apps_records_ssr_swap_to_virtual_page); each widget contributes only a
 * resolver (fetch + render) and an enqueue callback via the registry in
 * agend_apps_records_ssr_detail_registry().
 *
 * Loaded from the plugin bootstrap only once Agend Apps Core is available (the
 * detail is resolved through its cached REST wrappers).
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The catalogue detail types eligible for server-side rendering.
 *
 * Each entry maps a rewrite-endpoint query var to the proxy that must exist for
 * the type to be available, the resolver that fetches and renders the item, the
 * URL path segment used to build the detail permalink, and the enqueue callback
 * that layers the type's progressive enhancement onto the read-only markup.
 *
 * @return array<int, array<string, string>> Ordered detail-type descriptors.
 */
function agend_apps_records_ssr_detail_registry(): array {
	return array(
		array(
			// Private, namespaced query var (see routing.php):
			// the public path segment stays `listing/`, but the internal query var
			// avoids the common `listing` collision that 301s the detail page.
			'query_var' => 'agend_dir_listing',
			'available' => 'agend_apps_directory_get_listing',
			'resolver'  => 'agend_apps_records_ssr_resolve_listing',
			'segment'   => 'listing/',
			'enqueue'   => 'agend_apps_records_ssr_enqueue_directory',
			'type'      => 'listing',
		),
		array(
			'query_var' => 'event',
			'available' => 'agend_apps_events_get_event',
			'resolver'  => 'agend_apps_records_ssr_resolve_event',
			'segment'   => 'event/',
			'enqueue'   => 'agend_apps_records_ssr_enqueue_events',
			'type'      => 'event',
		),
		array(
			'query_var' => 'course',
			'available' => 'agend_apps_lms_get_course',
			'resolver'  => 'agend_apps_records_ssr_resolve_course',
			'segment'   => 'course/',
			'enqueue'   => 'agend_apps_records_ssr_enqueue_courses',
			'type'      => 'course',
		),
	);
}

/**
 * Resolves a catalogue detail request into a virtual child page.
 *
 * Runs on `wp` (after the main query is parsed, before the header renders) so
 * the synthesized queried object is in place when breadcrumbs and the document
 * title are generated. Dispatches to the first registered detail type whose
 * rewrite-endpoint query var is present on the current page URL.
 */
/**
 * Whether any catalogue type has a detail template configured.
 *
 * @return bool
 */
function agend_apps_records_ssr_any_detail_template(): bool {
	foreach ( array_keys( Agend_Apps_Records_Pages::types() ) as $type ) {
		if ( Agend_Apps_Records_Pages::detail_template_id( $type ) > 0 ) {
			return true;
		}
	}
	return false;
}

function agend_apps_records_ssr_maybe_render_detail(): void {
	// Front-end main document requests only.
	if ( is_admin() || wp_doing_ajax() || is_feed() || is_embed() ) {
		return;
	}
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return;
	}
	// A configured detail template implies server rendering for that type,
	// whatever the legacy toggle says: an Elementor template has no
	// client-rendered form.
	$ssr_enabled = agend_apps_records_ssr_detail_enabled();
	if ( ! $ssr_enabled && ! agend_apps_records_ssr_any_detail_template() ) {
		return;
	}

	global $wp_query;
	if ( ! $wp_query->is_main_query() ) {
		return;
	}

	// The catalogue page the detail endpoint hangs off; it becomes the parent
	// crumb.
	$host = get_queried_object();
	if ( ! ( $host instanceof WP_Post ) ) {
		return;
	}

	foreach ( agend_apps_records_ssr_detail_registry() as $type ) {
		if ( ! function_exists( $type['available'] ) ) {
			continue;
		}
		if ( ! $ssr_enabled && ( '' === $type['type'] || 0 === Agend_Apps_Records_Pages::detail_template_id( $type['type'] ) ) ) {
			continue;
		}

		// The wp:4 redirect (pages.php) normally sends a
		// request for this type to its dedicated page before this hook (wp:5)
		// runs; this is the fallback for a request that reached this page some
		// other way (a hard-coded old link, a cached page). Directory has no
		// dedicated page ('type' => '') so keeps any-page behaviour.
		if ( '' !== $type['type'] ) {
			$dedicated_id = Agend_Apps_Records_Pages::page_id( $type['type'] );
			if ( 0 !== $dedicated_id && $dedicated_id !== $host->ID ) {
				continue;
			}
		}

		$slug = sanitize_title( (string) get_query_var( $type['query_var'] ) );
		if ( '' === $slug ) {
			continue;
		}

		$resolved = call_user_func( $type['resolver'], $slug, $host );

		// A recognised detail endpoint with an unknown item: a real 404 (correct
		// for SEO) rather than an empty shell.
		if ( null === $resolved ) {
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			// redirect_canonical() treats a 404 on a page URL as a stray path and
			// 301s back to the catalogue page, which hides the 404 (and whatever
			// upstream failure caused it) behind a silent redirect.
			remove_action( 'template_redirect', 'redirect_canonical' );
			return;
		}

		agend_apps_records_ssr_swap_to_virtual_page(
			$host,
			$slug,
			$resolved['title'],
			$resolved['content'],
			$type['segment'],
			$type['enqueue']
		);
		return;
	}
}
add_action( 'wp', 'agend_apps_records_ssr_maybe_render_detail', 5 );

/**
 * Swaps the main query onto a synthetic child page rendering the given content.
 *
 * Shared by every catalogue detail type. The synthetic page's parent is the
 * catalogue (host) page, so breadcrumb systems render Home > Catalogue > Item;
 * its title is the item name; its body is the server-rendered detail.
 *
 * @param WP_Post  $host         The catalogue (host) page.
 * @param string   $slug         The item slug.
 * @param string   $title        The item name (becomes the page title).
 * @param string   $content      The server-rendered detail HTML.
 * @param string   $path_segment The detail URL path segment (e.g. 'event/').
 * @param callable $enqueue_cb   Enqueues the type's progressive enhancement.
 */
function agend_apps_records_ssr_swap_to_virtual_page( WP_Post $host, string $slug, string $title, string $content, string $path_segment, callable $enqueue_cb ): void {
	global $wp_query, $wpdb;

	// A synthetic id just above the highest real post id: high enough never to
	// collide with a real post, but well below the very large id ranges other
	// plugins claim (e.g. The Events Calendar Pro treats any id at/above its
	// dynamic "provisional post" base, kept a large margin above the max post
	// id, as one of its occurrence posts). Primed into the posts cache so
	// get_post()/get_post_ancestors() resolve the parent chain (host page) for
	// breadcrumb systems that pass the id, and removed on shutdown so it cannot
	// leak into a persistent object cache.
	$max_post_id = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" );
	$fake_id     = $max_post_id + 1 + ( abs( crc32( $path_segment . $slug ) ) % 20000 );

	$data = array(
		'ID'                    => $fake_id,
		'post_author'           => $host->post_author,
		'post_date'             => $host->post_date,
		'post_date_gmt'         => $host->post_date_gmt,
		'post_content'          => $content,
		'post_title'            => $title,
		'post_excerpt'          => '',
		'post_status'           => 'publish',
		'comment_status'        => 'closed',
		'ping_status'           => 'closed',
		'post_password'         => '',
		'post_name'             => $slug,
		'to_ping'               => '',
		'pinged'                => '',
		'post_modified'         => $host->post_modified,
		'post_modified_gmt'     => $host->post_modified_gmt,
		'post_content_filtered' => '',
		'post_parent'           => $host->ID,
		'guid'                  => trailingslashit( get_permalink( $host->ID ) ) . $path_segment . $slug . '/',
		'menu_order'            => 0,
		'post_type'             => 'page',
		'post_mime_type'        => '',
		'comment_count'         => 0,
		'filter'                => 'raw',
	);

	$post_obj = new WP_Post( (object) $data );

	wp_cache_add( $fake_id, (object) $data, 'posts' );
	add_action(
		'shutdown',
		static function () use ( $fake_id ) {
			wp_cache_delete( $fake_id, 'posts' );
		}
	);

	// Isolate the virtual post's metadata from every other plugin. The synthetic
	// post is the main queried object, so post-view counters and similar hooks
	// (Essential Addons) try to write meta to it; on a site running The Events
	// Calendar Pro, a high synthetic id lands in its "provisional post" range and
	// the write recurses through the provisional meta handler until the stack
	// overflows. Short-circuiting all meta ops for this id (at priority 1, before
	// those handlers) both breaks the recursion and prevents orphaned meta rows.
	$block_meta = static function ( $check, $object_id ) use ( $fake_id ) {
		return (int) $object_id === $fake_id ? false : $check;
	};
	add_filter( 'update_post_metadata', $block_meta, 1, 2 );
	add_filter( 'add_post_metadata', $block_meta, 1, 2 );
	add_filter( 'delete_post_metadata', $block_meta, 1, 2 );
	add_filter(
		'get_post_metadata',
		static function ( $value, $object_id, $meta_key, $single ) use ( $fake_id ) {
			if ( (int) $object_id !== $fake_id ) {
				return $value;
			}
			return $single ? '' : array();
		},
		1,
		4
	);

	// Self-links (breadcrumb leaf, canonical) point at the real detail URL.
	$detail_url = $data['guid'];
	add_filter(
		'page_link',
		static function ( $link, $post_id ) use ( $fake_id, $detail_url ) {
			return $post_id === $fake_id ? $detail_url : $link;
		},
		10,
		2
	);

	// Swap the main query onto the virtual child page.
	$wp_query->posts             = array( $post_obj );
	$wp_query->post              = $post_obj;
	$wp_query->queried_object    = $post_obj;
	$wp_query->queried_object_id = $fake_id;
	$wp_query->post_count        = 1;
	$wp_query->current_post      = -1;
	$wp_query->found_posts       = 1;
	$wp_query->max_num_pages     = 1;
	$wp_query->is_singular       = true;
	$wp_query->is_page           = true;
	$wp_query->is_single         = false;
	$wp_query->is_home           = false;
	$wp_query->is_archive        = false;
	$wp_query->is_404            = false;

	$GLOBALS['post'] = $post_obj;
	setup_postdata( $post_obj );

	// Progressive enhancement for the server-rendered detail.
	add_action( 'wp_enqueue_scripts', $enqueue_cb, 20 );
}

/**
 * Resolves a Directory listing detail into a title + server-rendered body.
 *
 * @param string  $slug The listing slug.
 * @param WP_Post $host The catalogue (host) page.
 * @return array{title: string, content: string}|null The resolved detail, or
 *         null when the listing does not exist.
 */
function agend_apps_records_ssr_resolve_listing( string $slug, WP_Post $host ): ?array {
	// Request member achievements only when opted in (the gateway 403s the whole
	// detail for keys without the directory.achievements.browse scope).
	$listing_query = agend_apps_records_show_achievements_enabled()
		? array( 'include' => 'achievements' )
		: array();
	$response = agend_apps_directory_get_listing( $slug, $listing_query );
	$item     = ( ! is_wp_error( $response ) && ! empty( $response['data'] ) && is_array( $response['data'] ) )
		? $response['data']
		: null;

	if ( null === $item ) {
		return null;
	}

	$name             = isset( $item['name'] ) ? (string) $item['name'] : __( 'Listing', 'agend-apps-core' );
	$reviews_response = function_exists( 'agend_apps_directory_get_listing_reviews' )
		? agend_apps_directory_get_listing_reviews( $slug, array( 'limit' => 10 ) )
		: null;

	$template_id = Agend_Apps_Records_Pages::detail_template_id( 'listing' );
	if ( $template_id > 0 && class_exists( 'Agend_Apps_Templates' ) ) {
		$html = Agend_Apps_Templates::render(
			$template_id,
			'listing',
			$item,
			array(
				'slug'         => $slug,
				'reviews'      => $reviews_response,
				'detail_url'   => Agend_Apps_Records_Pages::detail_url( 'listing', $slug, (int) $host->ID ),
				'is_detail'    => true,
				'host_page_id' => (int) $host->ID,
				'host'         => $host,
			)
		);
		if ( '' !== trim( $html ) ) {
			return array(
				'title'   => $name,
				'content' => '<div class="agend-directory-catalogue agend-directory-catalogue--ssr agend-directory-catalogue--templated-detail" style="' . esc_attr( agend_apps_records_ssr_colour_style( 'agend-dir' ) ) . '"><div class="agend-dir-detail agend-dir-detail--templated">' . $html . '</div></div>',
			);
		}
	}

	return array(
		'title'   => $name,
		'content' => agend_apps_records_render_directory_detail( $item, $slug, $reviews_response, $host ),
	);
}

/**
 * Resolves an Events event detail into a title + server-rendered body.
 *
 * @param string  $slug The event slug.
 * @param WP_Post $host The catalogue (host) page.
 * @return array{title: string, content: string}|null The resolved detail, or
 *         null when the event does not exist.
 */
function agend_apps_records_ssr_resolve_event( string $slug, WP_Post $host ): ?array {
	$response = agend_apps_events_get_event( $slug, array( 'include' => 'sponsors,categories' ) );
	$item     = ( ! is_wp_error( $response ) && ! empty( $response['data'] ) && is_array( $response['data'] ) )
		? $response['data']
		: null;

	if ( null === $item ) {
		return null;
	}

	$name = isset( $item['name'] ) ? (string) $item['name'] : __( 'Event', 'agend-apps-core' );

	// Ticket types (public price list, cached) for the detail's Tickets panel.
	// Which price is highlighted is decided at render time from the event's
	// bearer-enriched viewer_price_group, not from the tickets response, so the
	// cached ticket list is safe to share across viewers (US-2.3).
	$tickets_response = function_exists( 'agend_apps_events_get_tickets' )
		? agend_apps_events_get_tickets( $slug )
		: null;
	$tickets          = ( ! is_wp_error( $tickets_response ) && isset( $tickets_response['data'] ) && is_array( $tickets_response['data'] ) )
		? $tickets_response['data']
		: array();

	return array(
		'title'   => $name,
		'content' => agend_apps_records_ssr_event_content( $item, $slug, $host, $tickets ),
	);
}

/**
 * Resolves a Courses course detail into a title + server-rendered body.
 *
 * @param string  $slug The course slug.
 * @param WP_Post $host The catalogue (host) page.
 * @return array{title: string, content: string}|null The resolved detail, or
 *         null when the course does not exist.
 */
function agend_apps_records_ssr_resolve_course( string $slug, WP_Post $host ): ?array {
	$response = agend_apps_lms_get_course( $slug );
	$item     = ( ! is_wp_error( $response ) && ! empty( $response['data'] ) && is_array( $response['data'] ) )
		? $response['data']
		: null;

	if ( null === $item ) {
		return null;
	}

	$title = isset( $item['title'] ) ? (string) $item['title'] : __( 'Course', 'agend-apps-core' );

	return array(
		'title'   => $title,
		'content' => agend_apps_records_ssr_course_content( $item, $slug, $host ),
	);
}

/**
 * Enqueues the Directory detail progressive enhancement (review submit + gallery
 * lightbox).
 */
function agend_apps_records_ssr_enqueue_directory(): void {
	if ( ! wp_style_is( 'agend-apps-records-directory-catalogue', 'enqueued' ) ) {
		wp_enqueue_style( 'agend-apps-records-directory-catalogue' );
	}
	wp_enqueue_script( 'agend-apps-records-directory-detail' );
}

/**
 * Ensures the Events catalogue assets are present so the server-rendered detail
 * is styled and its "Register Now" button hydrates the registration flow. The
 * catalogue script (which owns that flow) is normally enqueued globally; this is
 * a defensive fallback that reuses the same handles.
 */
function agend_apps_records_ssr_enqueue_events(): void {
	if ( ! wp_style_is( 'agend-apps-records-events-catalogue', 'enqueued' ) ) {
		wp_enqueue_style( 'agend-apps-records-events-catalogue' );
	}
	if ( ! wp_script_is( 'agend-apps-records-events-catalogue', 'enqueued' ) ) {
		wp_enqueue_script( 'agend-apps-records-events-catalogue' );
	}
}

/**
 * Ensures the Courses catalogue stylesheet is present so the server-rendered
 * detail is styled. The course enrol CTA is a plain member sign-in link, so no
 * script hydration is required.
 */
function agend_apps_records_ssr_enqueue_courses(): void {
	if ( ! wp_style_is( 'agend-apps-records-courses-catalogue', 'enqueued' ) ) {
		wp_enqueue_style( 'agend-apps-records-courses-catalogue' );
	}
}

/**
 * Renders five rating stars.
 *
 * @param mixed $rating       Average rating (0 to 5) or null.
 * @param int   $review_count Number of reviews.
 * @param bool  $with_count   Whether to append the numeric summary.
 * @return string Stars HTML.
 */
function agend_apps_records_ssr_stars( $rating, int $review_count = 0, bool $with_count = false ): string {
	$value   = is_numeric( $rating ) ? (float) $rating : 0.0;
	$rounded = (int) round( $value );
	$html    = '<div class="agend-dir-stars">';
	for ( $i = 1; $i <= 5; $i++ ) {
		$html .= '<span class="agend-dir-star' . ( $i <= $rounded ? ' is-on' : '' ) . '">&#9733;</span>';
	}
	if ( $with_count ) {
		$label = ( ! is_numeric( $rating ) || 0 === $review_count )
			? __( 'No reviews yet', 'agend-apps-core' )
			: sprintf( '%s (%d)', number_format_i18n( $value, 1 ), $review_count );
		$html .= '<span class="agend-dir-stars__count">' . esc_html( $label ) . '</span>';
	}
	return $html . '</div>';
}

/**
 * Renders the listing's recognition badges as coloured pills (icon + name).
 *
 * @param mixed $badges Array of badges ({ name, icon, color }), or null.
 * @return string Badges HTML, or empty string.
 */
function agend_apps_records_ssr_badges( $badges ): string {
	if ( ! is_array( $badges ) || empty( $badges ) ) {
		return '';
	}
	$pills = '';
	foreach ( $badges as $badge ) {
		if ( empty( $badge['name'] ) ) {
			continue;
		}
		$style = ! empty( $badge['color'] )
			? ' style="--agend-dir-badge-colour:' . esc_attr( $badge['color'] ) . ';"'
			: '';
		$icon  = ! empty( $badge['icon'] )
			? '<span class="agend-dir-detail__badge-icon">' . esc_html( $badge['icon'] ) . '</span>'
			: '';
		$pills .= '<span class="agend-dir-detail__badge"' . $style . '>' . $icon . esc_html( $badge['name'] ) . '</span>';
	}
	if ( '' === $pills ) {
		return '';
	}
	return '<div class="agend-dir-detail__badges">' . $pills . '</div>';
}

/**
 * Renders the rating distribution bars (5 star to 1 star, with percentages).
 *
 * @param mixed $breakdown Per-star count map ({ "1": n, ... "5": n }), or null.
 * @param int   $total     Total approved reviews.
 * @return string Bars HTML, or empty string.
 */
function agend_apps_records_ssr_rating_bars( $breakdown, int $total ): string {
	if ( ! is_array( $breakdown ) || $total <= 0 ) {
		return '';
	}
	$rows = '';
	for ( $star = 5; $star >= 1; $star-- ) {
		$count = (int) ( $breakdown[ (string) $star ] ?? 0 );
		$pct   = (int) round( ( $count / $total ) * 100 );
		$label = sprintf(
			/* translators: %d: star rating. */
			_n( '%d star', '%d stars', $star, 'agend-apps-core' ),
			$star
		);
		$rows .= '<div class="agend-dir-ratingbar">'
			. '<span class="agend-dir-ratingbar__label">' . esc_html( $label ) . '</span>'
			. '<span class="agend-dir-ratingbar__track"><span class="agend-dir-ratingbar__fill" style="width:' . $pct . '%;"></span></span>'
			. '<span class="agend-dir-ratingbar__pct">' . $pct . '%</span></div>';
	}
	return '<div class="agend-dir-ratingbars">' . $rows . '</div>';
}

/**
 * Renders the member LMS achievements ("Badges & Credentials" section).
 *
 * @param mixed $achievements Array of { type, name, color, image_url, course_title, earned_at }, or null.
 * @return string Section HTML, or empty string.
 */
function agend_apps_records_ssr_achievements( $achievements ): string {
	if ( ! is_array( $achievements ) || empty( $achievements ) ) {
		return '';
	}
	$cards = '';
	foreach ( $achievements as $achievement ) {
		$title = ! empty( $achievement['name'] )
			? (string) $achievement['name']
			: (string) ( $achievement['course_title'] ?? '' );
		if ( '' === $title ) {
			continue;
		}
		$image = ! empty( $achievement['image_url'] ) ? esc_url( (string) $achievement['image_url'] ) : '';
		$color = ! empty( $achievement['color'] ) ? esc_attr( (string) $achievement['color'] ) : '';
		$media = '' !== $image
			? '<img class="agend-dir-cred__img" src="' . $image . '" alt="' . esc_attr( $title ) . '" loading="lazy" />'
			: '<span class="agend-dir-cred__icon"' . ( '' !== $color ? ' style="--agend-dir-cred-colour:' . $color . ';"' : '' ) . '>' . ( 'certificate' === ( $achievement['type'] ?? 'badge' ) ? '&#127894;' : '&#9733;' ) . '</span>';

		$meta = array();
		if ( ! empty( $achievement['course_title'] ) ) {
			$meta[] = esc_html( (string) $achievement['course_title'] );
		}
		if ( ! empty( $achievement['earned_at'] ) ) {
			$meta[] = esc_html( agend_apps_records_ssr_date( (string) $achievement['earned_at'] ) );
		}

		$cards .= '<div class="agend-dir-cred">' . $media
			. '<div class="agend-dir-cred__body"><span class="agend-dir-cred__name">' . esc_html( $title ) . '</span>'
			. ( ! empty( $meta ) ? '<span class="agend-dir-cred__meta">' . implode( ' &middot; ', $meta ) . '</span>' : '' )
			. '</div></div>';
	}
	if ( '' === $cards ) {
		return '';
	}
	return '<section class="agend-dir-detail__section"><h2 class="agend-dir-detail__section-title">'
		. esc_html__( 'Badges & Credentials', 'agend-apps-core' )
		. '</h2><div class="agend-dir-creds">' . $cards . '</div></section>';
}

/**
 * Renders the public custom fields ("Details" section) as a label/value list.
 *
 * @param mixed $fields Array of { key, label, type, value }, or null.
 * @return string Section HTML, or empty string.
 */
function agend_apps_records_ssr_custom_fields( $fields ): string {
	if ( ! is_array( $fields ) || empty( $fields ) ) {
		return '';
	}
	$rows = '';
	foreach ( $fields as $field ) {
		if ( empty( $field['label'] ) ) {
			continue;
		}
		$rows .= '<div class="agend-dir-cf__row"><span class="agend-dir-cf__label">'
			. esc_html( $field['label'] ) . '</span>'
			. agend_apps_records_ssr_cf_value( $field ) . '</div>';
	}
	if ( '' === $rows ) {
		return '';
	}
	return '<section class="agend-dir-detail__section"><h2 class="agend-dir-detail__section-title">'
		. esc_html__( 'Details', 'agend-apps-core' )
		. '</h2><div class="agend-dir-cf">' . $rows . '</div></section>';
}

/**
 * Renders a single custom-field value by its type.
 *
 * @param array $field { key, label, type, value }.
 * @return string Value HTML.
 */
function agend_apps_records_ssr_cf_value( array $field ): string {
	$type  = $field['type'] ?? 'text';
	$value = $field['value'] ?? '';

	switch ( $type ) {
		case 'array':
			if ( ! is_array( $value ) ) {
				return '';
			}
			$chips = '';
			foreach ( $value as $item ) {
				$chips .= '<span class="agend-dir-pill agend-dir-pill--tag">' . esc_html( (string) $item ) . '</span>';
			}
			return '<span class="agend-dir-cf__value"><span class="agend-dir-card__pills">' . $chips . '</span></span>';
		case 'url':
			$url = esc_url( (string) $value );
			return '' !== $url
				? '<a class="agend-dir-cf__value agend-dir-cf__link" href="' . $url . '" target="_blank" rel="noopener noreferrer">' . esc_html( (string) $value ) . '</a>'
				: '';
		case 'email':
			$email = sanitize_email( (string) $value );
			return '' !== $email
				? '<a class="agend-dir-cf__value agend-dir-cf__link" href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a>'
				: '';
		case 'boolean':
			return '<span class="agend-dir-cf__value">'
				. ( $value ? esc_html__( 'Yes', 'agend-apps-core' ) : esc_html__( 'No', 'agend-apps-core' ) )
				. '</span>';
		case 'date':
			return '<span class="agend-dir-cf__value">' . esc_html( agend_apps_records_ssr_date( (string) $value ) ) . '</span>';
		default:
			return '<span class="agend-dir-cf__value">' . esc_html( (string) $value ) . '</span>';
	}
}

/**
 * Formats an ISO date as DD/MM/YYYY.
 *
 * @param string $iso ISO 8601 date string.
 * @return string Formatted date, or empty string.
 */
function agend_apps_records_ssr_date( string $iso ): string {
	$ts = strtotime( $iso );
	return $ts ? gmdate( 'd/m/Y', $ts ) : '';
}

/**
 * Renders the server-side Directory listing detail body.
 *
 * Mirrors the client-side detail (assets/js/directory-catalogue.js): hero,
 * about, gallery, locations, hours, tags, contact, categories, and the approved
 * reviews list plus a submission form. The listing email is never rendered.
 *
 * @param array        $item             Public listing detail (gateway shape).
 * @param string       $slug             Listing slug.
 * @param array|null   $reviews_response Decoded reviews response, or null.
 * @param WP_Post      $host             The listings (host) page.
 * @return string Detail HTML wrapped in the widget's style scope.
 */
function agend_apps_records_render_directory_detail( array $item, string $slug, $reviews_response, WP_Post $host ): string {
	$name     = isset( $item['name'] ) ? (string) $item['name'] : '';
	$host_url = get_permalink( $host->ID );

	$socials = array(
		'website'       => __( 'Website', 'agend-apps-core' ),
		'facebook_url'  => __( 'Facebook', 'agend-apps-core' ),
		'instagram_url' => __( 'Instagram', 'agend-apps-core' ),
		'twitter_url'   => __( 'Twitter', 'agend-apps-core' ),
		'linkedin_url'  => __( 'LinkedIn', 'agend-apps-core' ),
		'youtube_url'   => __( 'YouTube', 'agend-apps-core' ),
	);

	ob_start();
	?>
	<div class="agend-directory-catalogue agend-directory-catalogue--ssr">
		<div class="agend-dir-detail">
			<a class="agend-dir-detail__back" href="<?php echo esc_url( $host_url ); ?>">&larr; <?php esc_html_e( 'Back to Directory', 'agend-apps-core' ); ?></a>

			<div class="agend-dir-detail__hero">
				<?php if ( ! empty( $item['logo_url'] ) ) : ?>
					<img class="agend-dir-detail__logo" src="<?php echo esc_url( $item['logo_url'] ); ?>" alt="<?php echo esc_attr( $name ); ?>" />
				<?php endif; ?>
				<div class="agend-dir-detail__hero-inner">
					<h1 class="agend-dir-detail__title"><?php echo esc_html( $name ); ?></h1>
						<?php
						// Member enrichment (SPEC-CORE-20260722 US-2.6): the fetch is
						// bearer-attended and cache-bypassed for a signed-in member, so
						// is_mine flags the viewer's own listing. Absent resolves false.
						if ( ! empty( $item['is_mine'] ) ) :
							?>
							<div class="agend-dir-detail__mine"><?php esc_html_e( 'This is your listing', 'agend-apps-core' ); ?></div>
						<?php endif; ?>
					<?php if ( ! empty( $item['primary_category']['name'] ) ) : ?>
						<div class="agend-dir-card__pills"><span class="agend-dir-pill agend-dir-pill--category"><?php echo esc_html( $item['primary_category']['name'] ); ?></span></div>
					<?php endif; ?>
					<?php echo agend_apps_records_ssr_badges( $item['badges'] ?? array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo agend_apps_records_ssr_stars( $item['average_rating'] ?? null, (int) ( $item['review_count'] ?? 0 ), true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
			</div>

			<div class="agend-dir-detail__layout">
				<div class="agend-dir-detail__main">
					<?php echo agend_apps_records_fragment_listing_about( $item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

					<?php echo agend_apps_records_ssr_custom_fields( $item['custom_fields'] ?? array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

					<?php echo agend_apps_records_ssr_achievements( $item['achievements'] ?? array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

					<?php echo agend_apps_records_fragment_listing_gallery( $item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

					<?php echo agend_apps_records_fragment_listing_locations( $item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

					<?php echo agend_apps_records_ssr_hours_section( $item['business_hours'] ?? null ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

					<?php echo agend_apps_records_fragment_listing_tags( $item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

					<?php echo agend_apps_records_ssr_reviews_section( $item, $slug, $reviews_response ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>

				<aside class="agend-dir-detail__side">
					<?php echo agend_apps_records_fragment_listing_contact( $item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo agend_apps_records_fragment_listing_categories( $item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</aside>
			</div>
		</div>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * Renders a business-hours table (map of day to open/close/closed).
 *
 * @param mixed $hours Hours map, or null.
 * @return string Hours HTML, or empty string.
 */
function agend_apps_records_ssr_hours( $hours ): string {
	if ( ! is_array( $hours ) || empty( $hours ) ) {
		return '';
	}
	$days = array(
		'monday'    => __( 'Monday', 'agend-apps-core' ),
		'tuesday'   => __( 'Tuesday', 'agend-apps-core' ),
		'wednesday' => __( 'Wednesday', 'agend-apps-core' ),
		'thursday'  => __( 'Thursday', 'agend-apps-core' ),
		'friday'    => __( 'Friday', 'agend-apps-core' ),
		'saturday'  => __( 'Saturday', 'agend-apps-core' ),
		'sunday'    => __( 'Sunday', 'agend-apps-core' ),
	);
	$rows = '';
	foreach ( $days as $key => $label ) {
		if ( empty( $hours[ $key ] ) ) {
			continue;
		}
		$entry = $hours[ $key ];
		if ( ! empty( $entry['closed'] ) ) {
			$value = __( 'Closed', 'agend-apps-core' );
		} else {
			$parts = array_filter( array( $entry['open'] ?? '', $entry['close'] ?? '' ), static fn( $p ) => '' !== (string) $p );
			$value = ! empty( $parts ) ? implode( ' &ndash; ', array_map( 'esc_html', $parts ) ) : __( 'Closed', 'agend-apps-core' );
		}
		$rows .= '<div class="agend-dir-hours__row"><span class="agend-dir-hours__day">' . esc_html( $label ) . '</span><span class="agend-dir-hours__value">' . $value . '</span></div>';
	}
	if ( '' === $rows ) {
		return '';
	}
	return '<div class="agend-dir-hours">' . $rows . '</div>';
}

/**
 * Wraps the top-level business hours in a titled section.
 *
 * @param mixed $hours Hours map, or null.
 * @return string Section HTML, or empty string.
 */
function agend_apps_records_ssr_hours_section( $hours ): string {
	$hours_html = agend_apps_records_ssr_hours( $hours );
	if ( '' === $hours_html ) {
		return '';
	}
	return '<section class="agend-dir-detail__section"><h2 class="agend-dir-detail__section-title">'
		. esc_html__( 'Opening Hours', 'agend-apps-core' )
		. '</h2>' . $hours_html . '</section>';
}

/**
 * Renders the reviews summary, the approved review list, and a submission form.
 *
 * @param array      $item             Public listing detail.
 * @param string     $slug             Listing slug.
 * @param array|null $reviews_response Decoded reviews response, or null.
 * @return string Reviews section HTML.
 */
function agend_apps_records_ssr_reviews_section( array $item, string $slug, $reviews_response ): string {
	$reviews = ( is_array( $reviews_response ) && ! empty( $reviews_response['data'] ) && is_array( $reviews_response['data'] ) )
		? $reviews_response['data']
		: array();

	ob_start();
	?>
	<section class="agend-dir-detail__section agend-dir-reviews">
		<h2 class="agend-dir-detail__section-title"><?php esc_html_e( 'Reviews', 'agend-apps-core' ); ?></h2>
		<div class="agend-dir-reviews__summary">
			<?php echo agend_apps_records_ssr_stars( $item['average_rating'] ?? null, (int) ( $item['review_count'] ?? 0 ), true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php echo agend_apps_records_ssr_rating_bars( $item['rating_breakdown'] ?? null, (int) ( $item['review_count'] ?? 0 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
		<div class="agend-dir-reviews__list">
			<?php if ( empty( $reviews ) ) : ?>
				<div class="agend-dir-status"><?php esc_html_e( 'No reviews yet. Be the first to review.', 'agend-apps-core' ); ?></div>
			<?php else : ?>
				<?php foreach ( $reviews as $review ) : ?>
					<div class="agend-dir-review">
						<div class="agend-dir-review__head">
							<div class="agend-dir-review__name-wrap">
								<span class="agend-dir-review__name"><?php echo esc_html( $review['reviewer_name'] ?? __( 'Anonymous', 'agend-apps-core' ) ); ?></span>
								<?php if ( ! empty( $review['is_verified'] ) ) : ?>
									<span class="agend-dir-review__verified"><?php esc_html_e( 'Verified', 'agend-apps-core' ); ?></span>
								<?php endif; ?>
							</div>
							<?php echo agend_apps_records_ssr_stars( $review['rating'] ?? 0, 0, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</div>
						<?php if ( ! empty( $review['created_at'] ) ) : ?>
							<div class="agend-dir-review__date"><?php echo esc_html( agend_apps_records_ssr_date( (string) $review['created_at'] ) ); ?></div>
						<?php endif; ?>
						<p class="agend-dir-review__content"><?php echo esc_html( $review['content'] ?? '' ); ?></p>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>

		<?php
		// Member enrichment (SPEC-CORE-20260722 US-2.6): a signed-in member's
		// reviewer identity is derived server-side from the bearer, so the
		// name/email fields are omitted. The flag is mirrored onto a data
		// attribute so the client review submitter skips them too.
		$member_logged_in = (
			is_user_logged_in() &&
			class_exists( 'Agend_Apps_Member_Session' ) &&
			Agend_Apps_Member_Session::has_session( get_current_user_id() )
		);

		// The review-form optional feature (SPEC-CORE-20260908 scope-gated
		// features): omit the submission form entirely when the connected key
		// does not (or is not yet known to) hold directory.reviews.manage,
		// rather than render a form whose submit 403s.
		$review_form_available = ! function_exists( 'agend_apps_records_feature_available' ) || agend_apps_records_feature_available( 'directory_review_form' );
		?>
		<?php if ( $review_form_available ) : ?>
		<div class="agend-dir-review-form" data-agend-listing-id="<?php echo esc_attr( $item['id'] ?? '' ); ?>" data-agend-member="<?php echo $member_logged_in ? '1' : '0'; ?>">
			<h3 class="agend-dir-review-form__title"><?php esc_html_e( 'Write a Review', 'agend-apps-core' ); ?></h3>
			<div class="agend-dir-review-form__rating">
				<span class="agend-dir-review-form__label"><?php esc_html_e( 'Your rating', 'agend-apps-core' ); ?></span>
				<div class="agend-dir-review-form__stars">
					<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
						<button type="button" class="agend-dir-review-form__star" data-value="<?php echo (int) $i; ?>" aria-label="<?php echo esc_attr( sprintf( _n( '%d star', '%d stars', $i, 'agend-apps-core' ), $i ) ); ?>">&#9733;</button>
					<?php endfor; ?>
				</div>
			</div>
			<?php if ( ! $member_logged_in ) : ?>
				<label class="agend-dir-review-form__field">
					<span class="agend-dir-review-form__label"><?php esc_html_e( 'Name', 'agend-apps-core' ); ?></span>
					<input type="text" class="agend-dir-review-form__input" data-field="name" placeholder="<?php esc_attr_e( 'Your name', 'agend-apps-core' ); ?>" />
				</label>
				<label class="agend-dir-review-form__field">
					<span class="agend-dir-review-form__label"><?php esc_html_e( 'Email', 'agend-apps-core' ); ?></span>
					<input type="email" class="agend-dir-review-form__input" data-field="email" placeholder="you@example.com" />
				</label>
			<?php endif; ?>
			<label class="agend-dir-review-form__field">
				<span class="agend-dir-review-form__label"><?php esc_html_e( 'Review', 'agend-apps-core' ); ?></span>
				<textarea class="agend-dir-review-form__textarea" data-field="content" rows="4" placeholder="<?php esc_attr_e( 'Share your experience (at least 20 characters)…', 'agend-apps-core' ); ?>"></textarea>
			</label>
			<div class="agend-dir-review-form__error" style="display:none;"></div>
			<button type="button" class="agend-dir-review-form__submit"><?php esc_html_e( 'Submit Review', 'agend-apps-core' ); ?></button>
		</div>
		<?php endif; ?>
	</section>
	<?php
	return (string) ob_get_clean();
}

/**
 * Renders the server-side Events event detail body.
 *
 * Mirrors the client-side detail (assets/js/events-catalogue.js renderDetail):
 * hero, About, Sponsors, and a sidebar with the registration panel and details
 * facts. The read-only markup is wrapped in a `.agend-events-catalogue` mount
 * carrying an `ssrDetail` config so the catalogue script hydrates the
 * "Register Now" button into the existing registration flow.
 *
 * @param array   $item    Public event detail (gateway shape).
 * @param string  $slug    Event slug.
 * @param WP_Post $host    The catalogue (host) page.
 * @param array   $tickets Ticket-type list (gateway shape) for the Tickets
 *                         panel. Default empty.
 * @return string Detail HTML wrapped in the widget's style scope.
 */
/**
 * The script config for a server-rendered event detail: puts the events
 * script into hydrate-only mode for the registration flow.
 *
 * @param array   $item Event detail.
 * @param string  $slug Event slug.
 * @param WP_Post $host The catalogue (host) page.
 * @return array
 */
function agend_apps_records_ssr_events_config( array $item, string $slug, WP_Post $host ): array {
	$host_url = get_permalink( $host->ID );
	return array(
		'ssrDetail'   => true,
		'deepLink'    => $slug,
		'prettyLinks' => (bool) get_option( 'permalink_structure' ),
		'basePath'    => is_string( $host_url ) ? $host_url : '',
		// Fallback timezone for the hydrated registration flow; the client
		// prefers the fetched event's own timezone. This single-event page
		// uses that event's zone, falling back to the site timezone.
		'timezone'    => ! empty( $item['timezone'] ) ? (string) $item['timezone'] : wp_timezone_string(),
		// Cart mode: mirror the client catalogue so the hydrated "Register
		// Now" flow adds tickets to the shop cart when the shop is active.
		'cartEnabled' => agend_apps_records_shop_cart_enabled(),
		'cartPageUrl' => agend_apps_records_shop_cart_page_url(),
	);
}

/**
 * Event detail body: the configured detail template when one is set and
 * renders, otherwise the built-in layout.
 *
 * The template output sits in the same shell as the built-in detail so the
 * events script's hydrate-only mode finds its config and the Register button.
 *
 * @param array   $item    Event detail.
 * @param string  $slug    Event slug.
 * @param WP_Post $host    The catalogue (host) page.
 * @param array   $tickets Ticket types.
 * @return string
 */
function agend_apps_records_ssr_event_content( array $item, string $slug, WP_Post $host, array $tickets ): string {
	$template_id = Agend_Apps_Records_Pages::detail_template_id( 'event' );
	if ( $template_id > 0 && class_exists( 'Agend_Apps_Templates' ) ) {
		$html = Agend_Apps_Templates::render(
			$template_id,
			'event',
			$item,
			array(
				'slug'         => $slug,
				'tickets'      => $tickets,
				'detail_url'   => Agend_Apps_Records_Pages::detail_url( 'event', $slug, (int) $host->ID ),
				'is_detail'    => true,
				'host_page_id' => (int) $host->ID,
				'host'         => $host,
			)
		);
		if ( '' !== trim( $html ) ) {
			$config = wp_json_encode( agend_apps_records_ssr_events_config( $item, $slug, $host ) );
			return '<div class="agend-events-catalogue agend-events-catalogue--ssr agend-events-catalogue--templated-detail" style="' . esc_attr( agend_apps_records_ssr_colour_style( 'agend-ev' ) ) . '" data-agend-events-config="' . esc_attr( $config ) . '"><div class="agend-ev-detail agend-ev-detail--templated">' . $html . '</div></div>';
		}
	}
	return agend_apps_records_render_events_detail( $item, $slug, $host, $tickets );
}

/**
 * Course detail body: the configured detail template when one is set and
 * renders, otherwise the built-in layout.
 *
 * @param array   $item Course detail.
 * @param string  $slug Course slug.
 * @param WP_Post $host The catalogue (host) page.
 * @return string
 */
function agend_apps_records_ssr_course_content( array $item, string $slug, WP_Post $host ): string {
	$template_id = Agend_Apps_Records_Pages::detail_template_id( 'course' );
	if ( $template_id > 0 && class_exists( 'Agend_Apps_Templates' ) ) {
		$html = Agend_Apps_Templates::render(
			$template_id,
			'course',
			$item,
			array(
				'slug'         => $slug,
				'detail_url'   => Agend_Apps_Records_Pages::detail_url( 'course', $slug, (int) $host->ID ),
				'is_detail'    => true,
				'host_page_id' => (int) $host->ID,
				'host'         => $host,
			)
		);
		if ( '' !== trim( $html ) ) {
			return '<div class="agend-courses-catalogue agend-courses-catalogue--ssr agend-courses-catalogue--templated-detail" style="' . esc_attr( agend_apps_records_ssr_colour_style( 'agend-lms' ) ) . '"><div class="agend-lms-detail agend-lms-detail--templated">' . $html . '</div></div>';
		}
	}
	return agend_apps_records_render_courses_detail( $item, $slug, $host );
}

function agend_apps_records_render_events_detail( array $item, string $slug, WP_Post $host, array $tickets = array() ): string {
	$name     = isset( $item['name'] ) ? (string) $item['name'] : '';
	$host_url = get_permalink( $host->ID );
	$style    = agend_apps_records_ssr_colour_style( 'agend-ev' );

	// Event times display in the event's own timezone (each event carries one),
	// falling back to the site timezone for a missing or invalid value.
	$event_tz = agend_apps_records_record_timezone( $item );

	$cat = '';
	if ( ! empty( $item['categories'][0]['name'] ) ) {
		$cat = (string) $item['categories'][0]['name'];
	} elseif ( ! empty( $item['category']['name'] ) ) {
		$cat = (string) $item['category']['name'];
	}

	$venue_type = isset( $item['venue_type'] ) ? (string) $item['venue_type'] : '';
	$type_label = agend_apps_records_ssr_ev_type_label( $venue_type );

	$venue_or_mode = ! empty( $item['venue_name'] )
		? (string) $item['venue_name']
		: ( 'virtual' === $venue_type ? __( 'Online', 'agend-apps-core' ) : __( 'TBA', 'agend-apps-core' ) );
	$meta_line = implode(
		' · ',
		array_filter( array( agend_apps_records_ssr_ev_date_range( $item['start_date'] ?? '', $item['end_date'] ?? '', $event_tz ), $venue_or_mode ) )
	);

	$config = wp_json_encode( agend_apps_records_ssr_events_config( $item, $slug, $host ) );

	ob_start();
	?>
	<div class="agend-events-catalogue agend-events-catalogue--ssr" style="<?php echo esc_attr( $style ); ?>" data-agend-events-config="<?php echo esc_attr( $config ); ?>">
		<div class="agend-ev-detail">
			<a class="agend-ev-detail__back" href="<?php echo esc_url( $host_url ); ?>">&larr; <?php esc_html_e( 'Back to Events', 'agend-apps-core' ); ?></a>

			<div class="agend-ev-detail__hero"<?php echo ! empty( $item['hero_image_url'] ) ? ' style="background-image:linear-gradient(180deg, rgba(30,42,74,0.35), rgba(30,42,74,0.85)), url(\'' . esc_url( $item['hero_image_url'] ) . '\');"' : ''; ?>>
				<div class="agend-ev-detail__hero-inner">
					<?php if ( '' !== $cat || '' !== $type_label ) : ?>
						<div class="agend-ev-card__pills">
							<?php if ( '' !== $cat ) : ?>
								<span class="agend-ev-pill agend-ev-pill--category"><?php echo esc_html( $cat ); ?></span>
							<?php endif; ?>
							<?php if ( '' !== $type_label ) : ?>
								<span class="agend-ev-pill agend-ev-pill--type"><?php echo esc_html( $type_label ); ?></span>
							<?php endif; ?>
						</div>
					<?php endif; ?>
					<h1 class="agend-ev-detail__title"><?php echo esc_html( $name ); ?></h1>
					<?php if ( '' !== $meta_line ) : ?>
						<div class="agend-ev-detail__meta"><?php echo esc_html( $meta_line ); ?></div>
					<?php endif; ?>
				</div>
			</div>

			<div class="agend-ev-detail__layout">
				<div class="agend-ev-detail__main">
					<?php if ( ! empty( $item['description'] ) ) : ?>
						<section class="agend-ev-detail__section">
							<h2 class="agend-ev-detail__section-title"><?php esc_html_e( 'About This Event', 'agend-apps-core' ); ?></h2>
							<div class="agend-ev-detail__body-text"><?php echo wp_kses_post( $item['description'] ); ?></div>
						</section>
					<?php endif; ?>

					<?php echo agend_apps_records_fragment_event_sponsors( $item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fragment escapes internally. ?>
				</div>

				<aside class="agend-ev-detail__side">
					<?php echo agend_apps_records_fragment_event_registration( $item, $slug ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fragment escapes internally. ?>

					<?php echo agend_apps_records_fragment_event_tickets( $item, $tickets ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fragment escapes internally. ?>

					<?php echo agend_apps_records_fragment_event_facts( $item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fragment escapes internally. ?>
				</aside>
			</div>
		</div>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * Renders the server-side Courses course detail body.
 *
 * Mirrors the client-side detail (assets/js/courses-catalogue.js renderDetail):
 * hero, About, What You'll Learn, and a sidebar. A signed-in member's fetch is
 * bearer-attended and cache-bypassed, so an enrolled member gets their progress
 * panel server-side (SPEC-CORE-20260722 US-2.4); the anonymous view keeps the
 * pricing panel with a member sign-in CTA.
 *
 * @param array   $item Course detail (gateway shape; bearer-enriched for members).
 * @param string  $slug Course slug.
 * @param WP_Post $host The catalogue (host) page.
 * @return string Detail HTML wrapped in the widget's style scope.
 */
function agend_apps_records_render_courses_detail( array $item, string $slug, WP_Post $host ): string {
	$title    = isset( $item['title'] ) ? (string) $item['title'] : '';
	$host_url = get_permalink( $host->ID );
	$style    = agend_apps_records_ssr_colour_style( 'agend-lms' );

	$difficulty = isset( $item['difficulty'] ) ? (string) $item['difficulty'] : '';
	$mode       = isset( $item['delivery_mode'] ) ? (string) $item['delivery_mode'] : '';
	$category   = isset( $item['category'] ) ? (string) $item['category'] : '';
	$instructor = isset( $item['instructor_name'] ) ? (string) $item['instructor_name'] : '';
	$duration   = agend_apps_records_ssr_lms_duration( $item['total_duration_minutes'] ?? null );
	$lessons    = (int) ( $item['lessons_count'] ?? 0 );

	$meta_line = implode(
		' · ',
		array_filter(
			array(
				$duration,
				$lessons . ' ' . _n( 'module', 'modules', $lessons, 'agend-apps-core' ),
				$instructor,
			)
		)
	);

	ob_start();
	?>
	<div class="agend-courses-catalogue agend-courses-catalogue--ssr" style="<?php echo esc_attr( $style ); ?>">
		<div class="agend-lms-detail">
			<a class="agend-lms-detail__back" href="<?php echo esc_url( $host_url ); ?>">&larr; <?php esc_html_e( 'Back to Learning', 'agend-apps-core' ); ?></a>

			<div class="agend-lms-detail__hero"<?php echo ! empty( $item['image_url'] ) ? ' style="background-image:linear-gradient(180deg, rgba(30,42,74,0.4), rgba(30,42,74,0.88)), url(\'' . esc_url( $item['image_url'] ) . '\');"' : ''; ?>>
				<div class="agend-lms-detail__hero-inner">
					<?php if ( '' !== $category || '' !== $difficulty || '' !== $mode ) : ?>
						<div class="agend-lms-card__pills">
							<?php if ( '' !== $category ) : ?>
								<span class="agend-lms-pill agend-lms-pill--category"><?php echo esc_html( $category ); ?></span>
							<?php endif; ?>
							<?php if ( '' !== $difficulty ) : ?>
								<span class="agend-lms-pill agend-lms-pill--difficulty"><?php echo esc_html( agend_apps_records_ssr_lms_difficulty( $difficulty ) ); ?></span>
							<?php endif; ?>
							<?php if ( '' !== $mode ) : ?>
								<span class="agend-lms-pill agend-lms-pill--mode"><?php echo esc_html( agend_apps_records_ssr_lms_mode( $mode ) ); ?></span>
							<?php endif; ?>
						</div>
					<?php endif; ?>
					<h1 class="agend-lms-detail__title"><?php echo esc_html( $title ); ?></h1>
					<?php if ( '' !== $meta_line ) : ?>
						<div class="agend-lms-detail__meta"><?php echo esc_html( $meta_line ); ?></div>
					<?php endif; ?>
				</div>
			</div>

			<div class="agend-lms-detail__layout">
				<div class="agend-lms-detail__main">
					<?php if ( ! empty( $item['description'] ) ) : ?>
						<section class="agend-lms-detail__section">
							<h2 class="agend-lms-detail__section-title"><?php esc_html_e( 'About This Course', 'agend-apps-core' ); ?></h2>
							<div class="agend-lms-detail__body-text"><?php echo wp_kses_post( $item['description'] ); ?></div>
						</section>
					<?php endif; ?>

					<?php echo agend_apps_records_fragment_course_outcomes( $item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fragment escapes internally. ?>
				</div>

				<aside class="agend-lms-detail__side">
					<?php echo agend_apps_records_fragment_course_enrolment( $item, $slug, array( 'host' => $host ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fragment escapes internally. ?>

					<?php echo agend_apps_records_fragment_course_meta( $item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fragment escapes internally. ?>
				</aside>
			</div>
		</div>
	</div>
	<?php
	return (string) ob_get_clean();
}
