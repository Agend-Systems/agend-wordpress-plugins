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
 * (agend_elementor_ssr_swap_to_virtual_page); each widget contributes only a
 * resolver (fetch + render) and an enqueue callback via the registry in
 * agend_elementor_ssr_detail_registry().
 *
 * Loaded from the plugin bootstrap only once Agend Apps Core is available (the
 * detail is resolved through its cached REST wrappers).
 *
 * @package Agend_Elementor
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
function agend_elementor_ssr_detail_registry(): array {
	return array(
		array(
			'query_var' => 'listing',
			'available' => 'agend_apps_directory_get_listing',
			'resolver'  => 'agend_elementor_ssr_resolve_listing',
			'segment'   => 'listing/',
			'enqueue'   => 'agend_elementor_ssr_enqueue_directory',
		),
		array(
			'query_var' => 'event',
			'available' => 'agend_apps_events_get_event',
			'resolver'  => 'agend_elementor_ssr_resolve_event',
			'segment'   => 'event/',
			'enqueue'   => 'agend_elementor_ssr_enqueue_events',
		),
		array(
			'query_var' => 'course',
			'available' => 'agend_apps_lms_get_course',
			'resolver'  => 'agend_elementor_ssr_resolve_course',
			'segment'   => 'course/',
			'enqueue'   => 'agend_elementor_ssr_enqueue_courses',
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
function agend_elementor_ssr_maybe_render_detail(): void {
	// Front-end main document requests only.
	if ( is_admin() || wp_doing_ajax() || is_feed() || is_embed() ) {
		return;
	}
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return;
	}
	if ( ! agend_elementor_ssr_detail_enabled() ) {
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

	foreach ( agend_elementor_ssr_detail_registry() as $type ) {
		if ( ! function_exists( $type['available'] ) ) {
			continue;
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
			return;
		}

		agend_elementor_ssr_swap_to_virtual_page(
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
add_action( 'wp', 'agend_elementor_ssr_maybe_render_detail', 5 );

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
function agend_elementor_ssr_swap_to_virtual_page( WP_Post $host, string $slug, string $title, string $content, string $path_segment, callable $enqueue_cb ): void {
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
function agend_elementor_ssr_resolve_listing( string $slug, WP_Post $host ): ?array {
	// Request member achievements only when opted in (the gateway 403s the whole
	// detail for keys without the directory.achievements.browse scope).
	$listing_query = agend_elementor_show_achievements_enabled()
		? array( 'include' => 'achievements' )
		: array();
	$response = agend_apps_directory_get_listing( $slug, $listing_query );
	$item     = ( ! is_wp_error( $response ) && ! empty( $response['data'] ) && is_array( $response['data'] ) )
		? $response['data']
		: null;

	if ( null === $item ) {
		return null;
	}

	$name             = isset( $item['name'] ) ? (string) $item['name'] : __( 'Listing', 'agend-elementor' );
	$reviews_response = function_exists( 'agend_apps_directory_get_listing_reviews' )
		? agend_apps_directory_get_listing_reviews( $slug, array( 'limit' => 10 ) )
		: null;

	return array(
		'title'   => $name,
		'content' => agend_elementor_render_directory_detail( $item, $slug, $reviews_response, $host ),
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
function agend_elementor_ssr_resolve_event( string $slug, WP_Post $host ): ?array {
	$response = agend_apps_events_get_event( $slug, array( 'include' => 'sponsors,categories' ) );
	$item     = ( ! is_wp_error( $response ) && ! empty( $response['data'] ) && is_array( $response['data'] ) )
		? $response['data']
		: null;

	if ( null === $item ) {
		return null;
	}

	$name = isset( $item['name'] ) ? (string) $item['name'] : __( 'Event', 'agend-elementor' );

	return array(
		'title'   => $name,
		'content' => agend_elementor_render_events_detail( $item, $slug, $host ),
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
function agend_elementor_ssr_resolve_course( string $slug, WP_Post $host ): ?array {
	$response = agend_apps_lms_get_course( $slug );
	$item     = ( ! is_wp_error( $response ) && ! empty( $response['data'] ) && is_array( $response['data'] ) )
		? $response['data']
		: null;

	if ( null === $item ) {
		return null;
	}

	$title = isset( $item['title'] ) ? (string) $item['title'] : __( 'Course', 'agend-elementor' );

	return array(
		'title'   => $title,
		'content' => agend_elementor_render_courses_detail( $item, $slug, $host ),
	);
}

/**
 * Enqueues the Directory detail progressive enhancement (review submit + gallery
 * lightbox).
 */
function agend_elementor_ssr_enqueue_directory(): void {
	if ( ! wp_style_is( 'agend-elementor-directory-catalogue', 'enqueued' ) ) {
		wp_enqueue_style(
			'agend-elementor-directory-catalogue',
			AGEND_ELEMENTOR_URL . 'assets/css/directory-catalogue.css',
			array(),
			AGEND_ELEMENTOR_VERSION
		);
	}
	wp_enqueue_script(
		'agend-elementor-directory-detail',
		AGEND_ELEMENTOR_URL . 'assets/js/directory-detail.js',
		array(),
		AGEND_ELEMENTOR_VERSION,
		true
	);
}

/**
 * Ensures the Events catalogue assets are present so the server-rendered detail
 * is styled and its "Register Now" button hydrates the registration flow. The
 * catalogue script (which owns that flow) is normally enqueued globally; this is
 * a defensive fallback that reuses the same handles.
 */
function agend_elementor_ssr_enqueue_events(): void {
	if ( ! wp_style_is( 'agend-elementor-events-catalogue', 'enqueued' ) ) {
		wp_enqueue_style(
			'agend-elementor-events-catalogue',
			AGEND_ELEMENTOR_URL . 'assets/css/events-catalogue.css',
			array(),
			AGEND_ELEMENTOR_VERSION
		);
	}
	if ( ! wp_script_is( 'agend-elementor-events-catalogue', 'enqueued' ) ) {
		agend_elementor_register_dompurify();

		// Match the global enqueue: depend on the shop cart-session helper when
		// the shop is active so the hydrated registration flow can add to cart.
		$events_deps = array( 'agend-elementor-dompurify' );
		if ( agend_elementor_shop_cart_enabled() ) {
			$events_deps[] = 'agend-apps-shop-cart-session';
		}

		wp_enqueue_script(
			'agend-elementor-events-catalogue',
			AGEND_ELEMENTOR_URL . 'assets/js/events-catalogue.js',
			$events_deps,
			AGEND_ELEMENTOR_VERSION,
			true
		);
	}
}

/**
 * Ensures the Courses catalogue stylesheet is present so the server-rendered
 * detail is styled. The course enrol CTA is a plain member sign-in link, so no
 * script hydration is required.
 */
function agend_elementor_ssr_enqueue_courses(): void {
	if ( ! wp_style_is( 'agend-elementor-courses-catalogue', 'enqueued' ) ) {
		wp_enqueue_style(
			'agend-elementor-courses-catalogue',
			AGEND_ELEMENTOR_URL . 'assets/css/courses-catalogue.css',
			array(),
			AGEND_ELEMENTOR_VERSION
		);
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
function agend_elementor_ssr_stars( $rating, int $review_count = 0, bool $with_count = false ): string {
	$value   = is_numeric( $rating ) ? (float) $rating : 0.0;
	$rounded = (int) round( $value );
	$html    = '<div class="agend-dir-stars">';
	for ( $i = 1; $i <= 5; $i++ ) {
		$html .= '<span class="agend-dir-star' . ( $i <= $rounded ? ' is-on' : '' ) . '">&#9733;</span>';
	}
	if ( $with_count ) {
		$label = ( ! is_numeric( $rating ) || 0 === $review_count )
			? __( 'No reviews yet', 'agend-elementor' )
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
function agend_elementor_ssr_badges( $badges ): string {
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
function agend_elementor_ssr_rating_bars( $breakdown, int $total ): string {
	if ( ! is_array( $breakdown ) || $total <= 0 ) {
		return '';
	}
	$rows = '';
	for ( $star = 5; $star >= 1; $star-- ) {
		$count = (int) ( $breakdown[ (string) $star ] ?? 0 );
		$pct   = (int) round( ( $count / $total ) * 100 );
		$label = sprintf(
			/* translators: %d: star rating. */
			_n( '%d star', '%d stars', $star, 'agend-elementor' ),
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
function agend_elementor_ssr_achievements( $achievements ): string {
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
			$meta[] = esc_html( agend_elementor_ssr_date( (string) $achievement['earned_at'] ) );
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
		. esc_html__( 'Badges & Credentials', 'agend-elementor' )
		. '</h2><div class="agend-dir-creds">' . $cards . '</div></section>';
}

/**
 * Renders the public custom fields ("Details" section) as a label/value list.
 *
 * @param mixed $fields Array of { key, label, type, value }, or null.
 * @return string Section HTML, or empty string.
 */
function agend_elementor_ssr_custom_fields( $fields ): string {
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
			. agend_elementor_ssr_cf_value( $field ) . '</div>';
	}
	if ( '' === $rows ) {
		return '';
	}
	return '<section class="agend-dir-detail__section"><h2 class="agend-dir-detail__section-title">'
		. esc_html__( 'Details', 'agend-elementor' )
		. '</h2><div class="agend-dir-cf">' . $rows . '</div></section>';
}

/**
 * Renders a single custom-field value by its type.
 *
 * @param array $field { key, label, type, value }.
 * @return string Value HTML.
 */
function agend_elementor_ssr_cf_value( array $field ): string {
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
				. ( $value ? esc_html__( 'Yes', 'agend-elementor' ) : esc_html__( 'No', 'agend-elementor' ) )
				. '</span>';
		case 'date':
			return '<span class="agend-dir-cf__value">' . esc_html( agend_elementor_ssr_date( (string) $value ) ) . '</span>';
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
function agend_elementor_ssr_date( string $iso ): string {
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
function agend_elementor_render_directory_detail( array $item, string $slug, $reviews_response, WP_Post $host ): string {
	$name     = isset( $item['name'] ) ? (string) $item['name'] : '';
	$host_url = get_permalink( $host->ID );

	$socials = array(
		'website'       => __( 'Website', 'agend-elementor' ),
		'facebook_url'  => __( 'Facebook', 'agend-elementor' ),
		'instagram_url' => __( 'Instagram', 'agend-elementor' ),
		'twitter_url'   => __( 'Twitter', 'agend-elementor' ),
		'linkedin_url'  => __( 'LinkedIn', 'agend-elementor' ),
		'youtube_url'   => __( 'YouTube', 'agend-elementor' ),
	);

	ob_start();
	?>
	<div class="agend-directory-catalogue agend-directory-catalogue--ssr">
		<div class="agend-dir-detail">
			<a class="agend-dir-detail__back" href="<?php echo esc_url( $host_url ); ?>">&larr; <?php esc_html_e( 'Back to Directory', 'agend-elementor' ); ?></a>

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
							<div class="agend-dir-detail__mine"><?php esc_html_e( 'This is your listing', 'agend-elementor' ); ?></div>
						<?php endif; ?>
					<?php if ( ! empty( $item['primary_category']['name'] ) ) : ?>
						<div class="agend-dir-card__pills"><span class="agend-dir-pill agend-dir-pill--category"><?php echo esc_html( $item['primary_category']['name'] ); ?></span></div>
					<?php endif; ?>
					<?php echo agend_elementor_ssr_badges( $item['badges'] ?? array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo agend_elementor_ssr_stars( $item['average_rating'] ?? null, (int) ( $item['review_count'] ?? 0 ), true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
			</div>

			<div class="agend-dir-detail__layout">
				<div class="agend-dir-detail__main">
					<?php if ( ! empty( $item['description'] ) ) : ?>
						<section class="agend-dir-detail__section">
							<h2 class="agend-dir-detail__section-title"><?php esc_html_e( 'About', 'agend-elementor' ); ?></h2>
							<div class="agend-dir-detail__body-text"><?php echo wp_kses_post( $item['description'] ); ?></div>
						</section>
					<?php endif; ?>

					<?php echo agend_elementor_ssr_custom_fields( $item['custom_fields'] ?? array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

					<?php echo agend_elementor_ssr_achievements( $item['achievements'] ?? array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

					<?php if ( ! empty( $item['gallery_images'] ) && is_array( $item['gallery_images'] ) ) : ?>
						<section class="agend-dir-detail__section">
							<h2 class="agend-dir-detail__section-title"><?php esc_html_e( 'Gallery', 'agend-elementor' ); ?></h2>
							<div class="agend-dir-gallery">
								<?php foreach ( $item['gallery_images'] as $image ) : ?>
									<?php if ( is_string( $image ) && '' !== $image ) : ?>
										<button type="button" class="agend-dir-gallery__thumb" data-agend-lightbox="<?php echo esc_url( $image ); ?>">
											<img class="agend-dir-gallery__img" src="<?php echo esc_url( $image ); ?>" alt="<?php echo esc_attr( $name ); ?>" loading="lazy" />
										</button>
									<?php endif; ?>
								<?php endforeach; ?>
							</div>
						</section>
					<?php endif; ?>

					<?php if ( ! empty( $item['locations'] ) && is_array( $item['locations'] ) ) : ?>
						<section class="agend-dir-detail__section">
							<h2 class="agend-dir-detail__section-title"><?php esc_html_e( 'Locations', 'agend-elementor' ); ?></h2>
							<div class="agend-dir-locations">
								<?php foreach ( $item['locations'] as $location ) : ?>
									<div class="agend-dir-location">
										<?php if ( ! empty( $location['name'] ) ) : ?>
											<div class="agend-dir-location__name"><?php echo esc_html( $location['name'] ); ?></div>
										<?php endif; ?>
										<?php
										$address = array_filter(
											array(
												$location['address_line_1'] ?? '',
												$location['address_line_2'] ?? '',
												$location['city'] ?? '',
												$location['state'] ?? '',
												$location['postcode'] ?? '',
											),
											static fn( $part ) => '' !== (string) $part
										);
										?>
										<?php if ( ! empty( $address ) ) : ?>
											<div class="agend-dir-location__address"><?php echo esc_html( implode( ', ', $address ) ); ?></div>
										<?php endif; ?>
										<?php if ( ! empty( $location['phone'] ) ) : ?>
											<a class="agend-dir-location__phone" href="tel:<?php echo esc_attr( preg_replace( '/[^+\d]/', '', (string) $location['phone'] ) ); ?>"><?php echo esc_html( $location['phone'] ); ?></a>
										<?php endif; ?>
										<?php echo agend_elementor_ssr_hours( $location['hours'] ?? null ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									</div>
								<?php endforeach; ?>
							</div>
						</section>
					<?php endif; ?>

					<?php echo agend_elementor_ssr_hours_section( $item['business_hours'] ?? null ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

					<?php if ( ! empty( $item['tags'] ) && is_array( $item['tags'] ) ) : ?>
						<section class="agend-dir-detail__section">
							<h2 class="agend-dir-detail__section-title"><?php esc_html_e( 'Tags', 'agend-elementor' ); ?></h2>
							<div class="agend-dir-card__pills">
								<?php foreach ( $item['tags'] as $tag ) : ?>
									<span class="agend-dir-pill agend-dir-pill--tag"><?php echo esc_html( $tag['name'] ?? '' ); ?></span>
								<?php endforeach; ?>
							</div>
						</section>
					<?php endif; ?>

					<?php echo agend_elementor_ssr_reviews_section( $item, $slug, $reviews_response ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>

				<aside class="agend-dir-detail__side">
					<div class="agend-dir-detail__panel">
						<h2 class="agend-dir-detail__panel-title"><?php esc_html_e( 'Contact', 'agend-elementor' ); ?></h2>
						<?php
						$has_contact = false;
						if ( ! empty( $item['phone'] ) ) :
							$has_contact = true;
							?>
							<a class="agend-dir-detail__contact" href="tel:<?php echo esc_attr( preg_replace( '/[^+\d]/', '', (string) $item['phone'] ) ); ?>"><?php echo esc_html( $item['phone'] ); ?></a>
						<?php endif; ?>
						<?php
						foreach ( $socials as $field => $label ) :
							$url = isset( $item[ $field ] ) ? esc_url_raw( (string) $item[ $field ] ) : '';
							if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
								continue;
							}
							$has_contact = true;
							?>
							<a class="agend-dir-detail__contact agend-dir-detail__contact--link" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $label ); ?></a>
						<?php endforeach; ?>
						<?php if ( ! $has_contact ) : ?>
							<p class="agend-dir-detail__note"><?php esc_html_e( 'No contact details provided.', 'agend-elementor' ); ?></p>
						<?php endif; ?>
					</div>

					<?php if ( ! empty( $item['categories'] ) && is_array( $item['categories'] ) ) : ?>
						<div class="agend-dir-detail__panel">
							<h2 class="agend-dir-detail__panel-title"><?php esc_html_e( 'Categories', 'agend-elementor' ); ?></h2>
							<div class="agend-dir-card__pills">
								<?php foreach ( $item['categories'] as $category ) : ?>
									<span class="agend-dir-pill agend-dir-pill--category"><?php echo esc_html( $category['name'] ?? '' ); ?></span>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endif; ?>
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
function agend_elementor_ssr_hours( $hours ): string {
	if ( ! is_array( $hours ) || empty( $hours ) ) {
		return '';
	}
	$days = array(
		'monday'    => __( 'Monday', 'agend-elementor' ),
		'tuesday'   => __( 'Tuesday', 'agend-elementor' ),
		'wednesday' => __( 'Wednesday', 'agend-elementor' ),
		'thursday'  => __( 'Thursday', 'agend-elementor' ),
		'friday'    => __( 'Friday', 'agend-elementor' ),
		'saturday'  => __( 'Saturday', 'agend-elementor' ),
		'sunday'    => __( 'Sunday', 'agend-elementor' ),
	);
	$rows = '';
	foreach ( $days as $key => $label ) {
		if ( empty( $hours[ $key ] ) ) {
			continue;
		}
		$entry = $hours[ $key ];
		if ( ! empty( $entry['closed'] ) ) {
			$value = __( 'Closed', 'agend-elementor' );
		} else {
			$parts = array_filter( array( $entry['open'] ?? '', $entry['close'] ?? '' ), static fn( $p ) => '' !== (string) $p );
			$value = ! empty( $parts ) ? implode( ' &ndash; ', array_map( 'esc_html', $parts ) ) : __( 'Closed', 'agend-elementor' );
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
function agend_elementor_ssr_hours_section( $hours ): string {
	$hours_html = agend_elementor_ssr_hours( $hours );
	if ( '' === $hours_html ) {
		return '';
	}
	return '<section class="agend-dir-detail__section"><h2 class="agend-dir-detail__section-title">'
		. esc_html__( 'Opening Hours', 'agend-elementor' )
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
function agend_elementor_ssr_reviews_section( array $item, string $slug, $reviews_response ): string {
	$reviews = ( is_array( $reviews_response ) && ! empty( $reviews_response['data'] ) && is_array( $reviews_response['data'] ) )
		? $reviews_response['data']
		: array();

	ob_start();
	?>
	<section class="agend-dir-detail__section agend-dir-reviews">
		<h2 class="agend-dir-detail__section-title"><?php esc_html_e( 'Reviews', 'agend-elementor' ); ?></h2>
		<div class="agend-dir-reviews__summary">
			<?php echo agend_elementor_ssr_stars( $item['average_rating'] ?? null, (int) ( $item['review_count'] ?? 0 ), true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php echo agend_elementor_ssr_rating_bars( $item['rating_breakdown'] ?? null, (int) ( $item['review_count'] ?? 0 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
		<div class="agend-dir-reviews__list">
			<?php if ( empty( $reviews ) ) : ?>
				<div class="agend-dir-status"><?php esc_html_e( 'No reviews yet. Be the first to review.', 'agend-elementor' ); ?></div>
			<?php else : ?>
				<?php foreach ( $reviews as $review ) : ?>
					<div class="agend-dir-review">
						<div class="agend-dir-review__head">
							<div class="agend-dir-review__name-wrap">
								<span class="agend-dir-review__name"><?php echo esc_html( $review['reviewer_name'] ?? __( 'Anonymous', 'agend-elementor' ) ); ?></span>
								<?php if ( ! empty( $review['is_verified'] ) ) : ?>
									<span class="agend-dir-review__verified"><?php esc_html_e( 'Verified', 'agend-elementor' ); ?></span>
								<?php endif; ?>
							</div>
							<?php echo agend_elementor_ssr_stars( $review['rating'] ?? 0, 0, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</div>
						<?php if ( ! empty( $review['created_at'] ) ) : ?>
							<div class="agend-dir-review__date"><?php echo esc_html( agend_elementor_ssr_date( (string) $review['created_at'] ) ); ?></div>
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
		?>
		<div class="agend-dir-review-form" data-agend-listing-id="<?php echo esc_attr( $item['id'] ?? '' ); ?>" data-agend-member="<?php echo $member_logged_in ? '1' : '0'; ?>">
			<h3 class="agend-dir-review-form__title"><?php esc_html_e( 'Write a Review', 'agend-elementor' ); ?></h3>
			<div class="agend-dir-review-form__rating">
				<span class="agend-dir-review-form__label"><?php esc_html_e( 'Your rating', 'agend-elementor' ); ?></span>
				<div class="agend-dir-review-form__stars">
					<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
						<button type="button" class="agend-dir-review-form__star" data-value="<?php echo (int) $i; ?>" aria-label="<?php echo esc_attr( sprintf( _n( '%d star', '%d stars', $i, 'agend-elementor' ), $i ) ); ?>">&#9733;</button>
					<?php endfor; ?>
				</div>
			</div>
			<?php if ( ! $member_logged_in ) : ?>
				<label class="agend-dir-review-form__field">
					<span class="agend-dir-review-form__label"><?php esc_html_e( 'Name', 'agend-elementor' ); ?></span>
					<input type="text" class="agend-dir-review-form__input" data-field="name" placeholder="<?php esc_attr_e( 'Your name', 'agend-elementor' ); ?>" />
				</label>
				<label class="agend-dir-review-form__field">
					<span class="agend-dir-review-form__label"><?php esc_html_e( 'Email', 'agend-elementor' ); ?></span>
					<input type="email" class="agend-dir-review-form__input" data-field="email" placeholder="you@example.com" />
				</label>
			<?php endif; ?>
			<label class="agend-dir-review-form__field">
				<span class="agend-dir-review-form__label"><?php esc_html_e( 'Review', 'agend-elementor' ); ?></span>
				<textarea class="agend-dir-review-form__textarea" data-field="content" rows="4" placeholder="<?php esc_attr_e( 'Share your experience (at least 20 characters)…', 'agend-elementor' ); ?>"></textarea>
			</label>
			<div class="agend-dir-review-form__error" style="display:none;"></div>
			<button type="button" class="agend-dir-review-form__submit"><?php esc_html_e( 'Submit Review', 'agend-elementor' ); ?></button>
		</div>
	</section>
	<?php
	return (string) ob_get_clean();
}

/**
 * Builds the default catalogue colour CSS variables for a server-rendered
 * detail.
 *
 * The virtual detail page is not tied to a specific widget instance, so the
 * plugin's default palette is applied. The prefix selects the widget family
 * ('agend-ev' for Events, 'agend-lms' for Courses).
 *
 * @param string $prefix The CSS-variable prefix (without the leading '--').
 * @return string The inline style declaration string.
 */
function agend_elementor_ssr_colour_style( string $prefix ): string {
	return sprintf(
		'--%1$s-heading:#1E2A4A;--%1$s-body:#26304D;--%1$s-accent:#FF6B55;--%1$s-button:#FF6B55;--%1$s-button-text:#FFFFFF;--%1$s-card-radius:10px;',
		$prefix
	);
}

/**
 * Maps an event venue type to its display label.
 *
 * Mirrors TYPE_LABELS in assets/js/events-catalogue.js.
 *
 * @param string $type The venue type (physical, virtual, hybrid).
 * @return string The display label, or the raw type when unknown.
 */
function agend_elementor_ssr_ev_type_label( string $type ): string {
	$labels = array(
		'physical' => __( 'In-Person', 'agend-elementor' ),
		'virtual'  => __( 'Online', 'agend-elementor' ),
		'hybrid'   => __( 'Hybrid', 'agend-elementor' ),
	);
	return $labels[ $type ] ?? $type;
}

/**
 * Formats an event date range as "6 Jul 2026" or "6 Jul 2026 – 8 Jul 2026".
 *
 * Mirrors dateRange() in assets/js/events-catalogue.js, formatted in the
 * event's own timezone via wp_date().
 *
 * @param mixed             $start ISO 8601 start datetime.
 * @param mixed             $end   ISO 8601 end datetime, or empty.
 * @param DateTimeZone|null $tz    The event timezone (null = site timezone).
 * @return string The formatted range, or empty string.
 */
function agend_elementor_ssr_ev_date_range( $start, $end, ?DateTimeZone $tz = null ): string {
	$start_ts = strtotime( (string) $start );
	if ( ! $start_ts ) {
		return '';
	}
	$start_str = wp_date( 'j M Y', $start_ts, $tz );
	$end_ts    = strtotime( (string) $end );
	if ( ! $end_ts ) {
		return $start_str;
	}
	$end_str = wp_date( 'j M Y', $end_ts, $tz );
	return $start_str === $end_str ? $start_str : $start_str . ' – ' . $end_str;
}

/**
 * Formats an event date and time, e.g. "Monday, 6 July 2026, 9:00 am – 5:00 pm
 * AEST".
 *
 * Mirrors dateTime() in assets/js/events-catalogue.js, formatted in the event's
 * own timezone via wp_date(), with the zone abbreviation appended so a time
 * shown in a zone other than the viewer's is unambiguous.
 *
 * @param mixed             $start ISO 8601 start datetime.
 * @param mixed             $end   ISO 8601 end datetime, or empty.
 * @param DateTimeZone|null $tz    The event timezone (null = site timezone).
 * @return string The formatted date and time, or empty string.
 */
function agend_elementor_ssr_ev_date_time( $start, $end, ?DateTimeZone $tz = null ): string {
	$start_ts = strtotime( (string) $start );
	if ( ! $start_ts ) {
		return '';
	}
	$str    = wp_date( 'l, j F Y', $start_ts, $tz ) . ', ' . wp_date( 'g:i a', $start_ts, $tz );
	$end_ts = strtotime( (string) $end );
	if ( $end_ts ) {
		$str .= ' – ' . wp_date( 'g:i a', $end_ts, $tz );
	}
	$zone_label = wp_date( 'T', $start_ts, $tz );
	if ( '' !== (string) $zone_label ) {
		$str .= ' ' . $zone_label;
	}
	return $str;
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
 * @param array   $item Public event detail (gateway shape).
 * @param string  $slug Event slug.
 * @param WP_Post $host The catalogue (host) page.
 * @return string Detail HTML wrapped in the widget's style scope.
 */
function agend_elementor_render_events_detail( array $item, string $slug, WP_Post $host ): string {
	$name     = isset( $item['name'] ) ? (string) $item['name'] : '';
	$host_url = get_permalink( $host->ID );
	$style    = agend_elementor_ssr_colour_style( 'agend-ev' );

	// Event times display in the event's own timezone (each event carries one),
	// falling back to the site timezone for a missing or invalid value.
	$event_tz = wp_timezone();
	if ( ! empty( $item['timezone'] ) ) {
		try {
			$event_tz = new DateTimeZone( (string) $item['timezone'] );
		} catch ( Exception $e ) {
			$event_tz = wp_timezone();
		}
	}

	$cat = '';
	if ( ! empty( $item['categories'][0]['name'] ) ) {
		$cat = (string) $item['categories'][0]['name'];
	} elseif ( ! empty( $item['category']['name'] ) ) {
		$cat = (string) $item['category']['name'];
	}

	$venue_type = isset( $item['venue_type'] ) ? (string) $item['venue_type'] : '';
	$type_label = agend_elementor_ssr_ev_type_label( $venue_type );
	$sold_out   = ! empty( $item['sold_out'] );

	$venue_or_mode = ! empty( $item['venue_name'] )
		? (string) $item['venue_name']
		: ( 'virtual' === $venue_type ? __( 'Online', 'agend-elementor' ) : __( 'TBA', 'agend-elementor' ) );
	$meta_line = implode(
		' · ',
		array_filter( array( agend_elementor_ssr_ev_date_range( $item['start_date'] ?? '', $item['end_date'] ?? '', $event_tz ), $venue_or_mode ) )
	);

	$location = implode(
		', ',
		array_filter(
			array( $item['venue_name'] ?? '', $item['venue_address'] ?? '', $item['venue_city'] ?? '' ),
			static fn( $part ) => '' !== (string) $part
		)
	);
	if ( '' === $location ) {
		$location = 'virtual' === $venue_type ? __( 'Online', 'agend-elementor' ) : __( 'TBA', 'agend-elementor' );
	}

	$ical_url = rest_url( 'agend-apps/v1/events/' . rawurlencode( $slug ) . '/ical' );

	$config = wp_json_encode(
		array(
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
			'cartEnabled' => agend_elementor_shop_cart_enabled(),
			'cartPageUrl' => agend_elementor_shop_cart_page_url(),
		)
	);

	ob_start();
	?>
	<div class="agend-events-catalogue agend-events-catalogue--ssr" style="<?php echo esc_attr( $style ); ?>" data-agend-events-config="<?php echo esc_attr( $config ); ?>">
		<div class="agend-ev-detail">
			<a class="agend-ev-detail__back" href="<?php echo esc_url( $host_url ); ?>">&larr; <?php esc_html_e( 'Back to Events', 'agend-elementor' ); ?></a>

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
							<h2 class="agend-ev-detail__section-title"><?php esc_html_e( 'About This Event', 'agend-elementor' ); ?></h2>
							<div class="agend-ev-detail__body-text"><?php echo wp_kses_post( $item['description'] ); ?></div>
						</section>
					<?php endif; ?>

					<?php if ( ! empty( $item['sponsors'] ) && is_array( $item['sponsors'] ) ) : ?>
						<section class="agend-ev-detail__section">
							<h2 class="agend-ev-detail__section-title"><?php esc_html_e( 'Sponsors', 'agend-elementor' ); ?></h2>
							<div class="agend-ev-detail__sponsors">
								<?php foreach ( $item['sponsors'] as $sponsor ) : ?>
									<?php if ( ! empty( $sponsor['logo_url'] ) ) : ?>
										<img class="agend-ev-detail__sponsor-logo" src="<?php echo esc_url( $sponsor['logo_url'] ); ?>" alt="<?php echo esc_attr( $sponsor['name'] ?? '' ); ?>" loading="lazy" />
									<?php elseif ( ! empty( $sponsor['name'] ) ) : ?>
										<span class="agend-ev-detail__sponsor-name"><?php echo esc_html( $sponsor['name'] ); ?></span>
									<?php endif; ?>
								<?php endforeach; ?>
							</div>
						</section>
					<?php endif; ?>
				</div>

				<aside class="agend-ev-detail__side">
					<div class="agend-ev-detail__panel agend-ev-detail__panel--register">
						<h2 class="agend-ev-detail__panel-title"><?php esc_html_e( 'Registration', 'agend-elementor' ); ?></h2>
						<?php
						// Member enrichment (SPEC-CORE-20260722 US-2.3): the fetch is
						// bearer-attended and cache-bypassed for signed-in members, so
						// the viewer's registration state and price group arrive on
						// $item. Absent fields resolve to the anonymous baseline.
						$viewer_group   = isset( $item['viewer_price_group'] ) ? (string) $item['viewer_price_group'] : '';
						$is_member      = ( 'member' === $viewer_group || 'corporate' === $viewer_group );
						$is_registered  = ! empty( $item['my_registration'] );
						$register_label = $sold_out
							? __( 'Sold Out', 'agend-elementor' )
							: ( $is_registered ? __( 'Register Another Attendee', 'agend-elementor' ) : __( 'Register Now', 'agend-elementor' ) );
						?>
						<?php if ( $is_registered ) : ?>
							<div class="agend-ev-detail__registered"><?php esc_html_e( '✓ You’re registered for this event', 'agend-elementor' ); ?></div>
						<?php endif; ?>
						<button type="button" class="agend-ev-detail__cta" data-agend-event-slug="<?php echo esc_attr( $slug ); ?>"<?php echo $sold_out ? ' disabled' : ''; ?>>
							<?php echo esc_html( $register_label ); ?>
						</button>
						<a class="agend-ev-detail__calendar" href="<?php echo esc_url( $ical_url ); ?>"><?php esc_html_e( 'Add to Calendar', 'agend-elementor' ); ?></a>
						<?php if ( $is_member ) : ?>
							<p class="agend-ev-detail__note"><?php esc_html_e( 'Member pricing applies to your registration.', 'agend-elementor' ); ?></p>
						<?php else : ?>
							<p class="agend-ev-detail__note"><?php esc_html_e( 'Not a member? Join for discounted pricing.', 'agend-elementor' ); ?></p>
						<?php endif; ?>
					</div>

					<div class="agend-ev-detail__panel">
						<h2 class="agend-ev-detail__panel-title"><?php esc_html_e( 'Details', 'agend-elementor' ); ?></h2>
						<?php
						$facts = array(
							array( __( 'Date & Time', 'agend-elementor' ), agend_elementor_ssr_ev_date_time( $item['start_date'] ?? '', $item['end_date'] ?? '', $event_tz ) ),
							array( __( 'Location', 'agend-elementor' ), $location ),
							array( __( 'Format', 'agend-elementor' ), $type_label ),
						);
						foreach ( $facts as $pair ) :
							if ( '' === (string) $pair[1] ) {
								continue;
							}
							?>
							<div class="agend-ev-detail__fact">
								<span class="agend-ev-detail__fact-label"><?php echo esc_html( $pair[0] ); ?></span>
								<span class="agend-ev-detail__fact-value"><?php echo esc_html( $pair[1] ); ?></span>
							</div>
						<?php endforeach; ?>
					</div>
				</aside>
			</div>
		</div>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * Formats a course duration in minutes as "2h 30m" / "45m" / "Self-paced".
 *
 * Mirrors formatDuration() in assets/js/courses-catalogue.js.
 *
 * @param mixed $minutes Total duration in minutes.
 * @return string The formatted duration.
 */
function agend_elementor_ssr_lms_duration( $minutes ): string {
	$m = is_numeric( $minutes ) ? (int) $minutes : 0;
	if ( $m <= 0 ) {
		return __( 'Self-paced', 'agend-elementor' );
	}
	$hours   = intdiv( $m, 60 );
	$remains = $m % 60;
	if ( $hours && $remains ) {
		return $hours . 'h ' . $remains . 'm';
	}
	return $hours ? $hours . 'h' : $remains . 'm';
}

/**
 * Maps a course difficulty to its display label.
 *
 * Mirrors DIFFICULTY_LABELS in assets/js/courses-catalogue.js.
 *
 * @param string $value The difficulty value.
 * @return string The display label, the raw value when unknown, or empty.
 */
function agend_elementor_ssr_lms_difficulty( string $value ): string {
	if ( '' === $value ) {
		return '';
	}
	$labels = array(
		'beginner'     => __( 'Beginner', 'agend-elementor' ),
		'intermediate' => __( 'Intermediate', 'agend-elementor' ),
		'advanced'     => __( 'Advanced', 'agend-elementor' ),
		'all_levels'   => __( 'All Levels', 'agend-elementor' ),
	);
	return $labels[ $value ] ?? $value;
}

/**
 * Maps a course delivery mode to its display label.
 *
 * Mirrors DELIVERY_MODE_LABELS in assets/js/courses-catalogue.js (an unknown or
 * unset mode yields an empty label).
 *
 * @param string $value The delivery mode value.
 * @return string The display label, or empty string.
 */
function agend_elementor_ssr_lms_mode( string $value ): string {
	$labels = array(
		'self_paced'  => __( 'Self-paced', 'agend-elementor' ),
		'live_online' => __( 'Live Online', 'agend-elementor' ),
		'in_person'   => __( 'In-Person', 'agend-elementor' ),
		'blended'     => __( 'Blended', 'agend-elementor' ),
	);
	return $labels[ $value ] ?? '';
}

/**
 * Formats a course price as "$120.00" or "Free".
 *
 * Mirrors priceLabel() in assets/js/courses-catalogue.js.
 *
 * @param array $course Course detail (gateway shape).
 * @return string The formatted price.
 */
function agend_elementor_ssr_lms_price( array $course ): string {
	if ( ! empty( $course['is_free'] ) ) {
		return __( 'Free', 'agend-elementor' );
	}
	$price = $course['base_price'] ?? null;
	$num   = is_numeric( $price ) ? (float) $price : 0.0;
	if ( $num <= 0.0 ) {
		return __( 'Free', 'agend-elementor' );
	}
	// No thousands separator, matching the client priceLabel() (toFixed(2)) used
	// on the catalogue cards and the client-rendered detail.
	return '$' . number_format( $num, 2, '.', '' );
}

/**
 * Renders the sidebar enrolment panel for a signed-in member's course.
 *
 * Mirrors renderEnrollmentPanel() in assets/js/courses-catalogue.js: a
 * completed enrolment shows the completed state (semantic green), an in-progress
 * enrolment shows a progress bar, and neither ever shows a price
 * (SPEC-CORE-20260722 US-2.4, Decision 2.9).
 *
 * @param array $enrollment The viewer's my_enrollment record (gateway shape).
 * @return string Panel HTML with all dynamic values escaped.
 */
function agend_elementor_ssr_lms_enrollment_panel( array $enrollment ): string {
	$progress = ( ! empty( $enrollment['progress'] ) && is_array( $enrollment['progress'] ) )
		? $enrollment['progress']
		: array();

	ob_start();

	if ( ! empty( $progress['completed'] ) ) {
		$when = '';
		if ( ! empty( $progress['completed_at'] ) ) {
			$ts = strtotime( (string) $progress['completed_at'] );
			if ( false !== $ts ) {
				$when = ' ' . sprintf(
					/* translators: %s: completion date. */
					__( 'on %s', 'agend-elementor' ),
					wp_date( (string) get_option( 'date_format', 'j M Y' ), $ts )
				);
			}
		}
		?>
		<div class="agend-lms-detail__panel agend-lms-detail__panel--enrollment is-completed">
			<h2 class="agend-lms-detail__panel-title"><?php esc_html_e( 'Course Completed', 'agend-elementor' ); ?></h2>
			<div class="agend-lms-enrol__completed">
				<span class="agend-lms-enrol__tick">&#10003;</span>
				<span class="agend-lms-enrol__completed-text">
					<?php
					/* translators: %s: optional " on <date>" suffix. */
					echo esc_html( sprintf( __( 'You completed this course%s.', 'agend-elementor' ), $when ) );
					?>
				</span>
			</div>
			<p class="agend-lms-detail__note"><?php esc_html_e( 'Your certificate is available in your learning portal.', 'agend-elementor' ); ?></p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	$pct       = max( 0, min( 100, (int) round( (float) ( $progress['percentage'] ?? 0 ) ) ) );
	$done      = (int) ( $progress['lessons_completed'] ?? 0 );
	$total     = (int) ( $progress['total_lessons'] ?? 0 );
	?>
	<div class="agend-lms-detail__panel agend-lms-detail__panel--enrollment">
		<h2 class="agend-lms-detail__panel-title"><?php esc_html_e( 'Your Progress', 'agend-elementor' ); ?></h2>
		<div class="agend-lms-enrol__bar">
			<div class="agend-lms-enrol__bar-fill" style="width:<?php echo esc_attr( $pct ); ?>%;"></div>
		</div>
		<div class="agend-lms-enrol__meta">
			<span class="agend-lms-enrol__lessons">
				<?php
				/* translators: 1: completed module count, 2: total module count. */
				echo esc_html( sprintf( __( '%1$d of %2$d modules complete', 'agend-elementor' ), $done, $total ) );
				?>
			</span>
			<span class="agend-lms-enrol__pct"><?php echo esc_html( $pct . '%' ); ?></span>
		</div>
		<p class="agend-lms-detail__note"><?php esc_html_e( 'Continue learning in your member portal.', 'agend-elementor' ); ?></p>
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
function agend_elementor_render_courses_detail( array $item, string $slug, WP_Post $host ): string {
	$title    = isset( $item['title'] ) ? (string) $item['title'] : '';
	$host_url = get_permalink( $host->ID );
	$style    = agend_elementor_ssr_colour_style( 'agend-lms' );

	$difficulty = isset( $item['difficulty'] ) ? (string) $item['difficulty'] : '';
	$mode       = isset( $item['delivery_mode'] ) ? (string) $item['delivery_mode'] : '';
	$category   = isset( $item['category'] ) ? (string) $item['category'] : '';
	$instructor = isset( $item['instructor_name'] ) ? (string) $item['instructor_name'] : '';
	$duration   = agend_elementor_ssr_lms_duration( $item['total_duration_minutes'] ?? null );
	$lessons    = (int) ( $item['lessons_count'] ?? 0 );
	$price      = agend_elementor_ssr_lms_price( $item );

	$meta_line = implode(
		' · ',
		array_filter(
			array(
				$duration,
				$lessons . ' ' . _n( 'module', 'modules', $lessons, 'agend-elementor' ),
				$instructor,
			)
		)
	);

	$detail_url  = trailingslashit( is_string( $host_url ) ? $host_url : '' ) . 'course/' . $slug . '/';
	$sign_in_url = wp_login_url( $detail_url );

	ob_start();
	?>
	<div class="agend-courses-catalogue agend-courses-catalogue--ssr" style="<?php echo esc_attr( $style ); ?>">
		<div class="agend-lms-detail">
			<a class="agend-lms-detail__back" href="<?php echo esc_url( $host_url ); ?>">&larr; <?php esc_html_e( 'Back to Learning', 'agend-elementor' ); ?></a>

			<div class="agend-lms-detail__hero"<?php echo ! empty( $item['image_url'] ) ? ' style="background-image:linear-gradient(180deg, rgba(30,42,74,0.4), rgba(30,42,74,0.88)), url(\'' . esc_url( $item['image_url'] ) . '\');"' : ''; ?>>
				<div class="agend-lms-detail__hero-inner">
					<?php if ( '' !== $category || '' !== $difficulty || '' !== $mode ) : ?>
						<div class="agend-lms-card__pills">
							<?php if ( '' !== $category ) : ?>
								<span class="agend-lms-pill agend-lms-pill--category"><?php echo esc_html( $category ); ?></span>
							<?php endif; ?>
							<?php if ( '' !== $difficulty ) : ?>
								<span class="agend-lms-pill agend-lms-pill--difficulty"><?php echo esc_html( agend_elementor_ssr_lms_difficulty( $difficulty ) ); ?></span>
							<?php endif; ?>
							<?php if ( '' !== $mode ) : ?>
								<span class="agend-lms-pill agend-lms-pill--mode"><?php echo esc_html( agend_elementor_ssr_lms_mode( $mode ) ); ?></span>
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
							<h2 class="agend-lms-detail__section-title"><?php esc_html_e( 'About This Course', 'agend-elementor' ); ?></h2>
							<div class="agend-lms-detail__body-text"><?php echo wp_kses_post( $item['description'] ); ?></div>
						</section>
					<?php endif; ?>

					<?php
					$outcomes = ( ! empty( $item['learning_outcomes'] ) && is_array( $item['learning_outcomes'] ) )
						? $item['learning_outcomes']
						: array();
					if ( ! empty( $outcomes ) ) :
						?>
						<section class="agend-lms-detail__section">
							<h2 class="agend-lms-detail__section-title"><?php esc_html_e( "What You'll Learn", 'agend-elementor' ); ?></h2>
							<ul class="agend-lms-detail__outcomes">
								<?php
								foreach ( $outcomes as $outcome ) :
									$text = is_string( $outcome ) ? $outcome : (string) ( $outcome['text'] ?? '' );
									if ( '' === $text ) {
										continue;
									}
									?>
									<li class="agend-lms-detail__outcome"><?php echo esc_html( $text ); ?></li>
								<?php endforeach; ?>
							</ul>
						</section>
					<?php endif; ?>
				</div>

				<aside class="agend-lms-detail__side">
					<?php
					// Member enrichment (SPEC-CORE-20260722 US-2.4): the LMS
					// single-course fetch bypasses the shared cache for a signed-in
					// member, so my_enrollment arrives on $item. An enrolled member
					// gets their progress panel and never the "Sign in to enrol"
					// pricing shell (Decision 2.9); everyone else keeps the
					// anonymous pricing panel and the client hydrates the
					// identity-aware CTA.
					$enrollment = ( ! empty( $item['my_enrollment'] ) && is_array( $item['my_enrollment'] ) )
						? $item['my_enrollment']
						: null;
					if ( null !== $enrollment ) {
						echo agend_elementor_ssr_lms_enrollment_panel( $enrollment ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Panel escapes internally.
					} else {
						?>
						<div class="agend-lms-detail__panel agend-lms-detail__panel--pricing">
							<h2 class="agend-lms-detail__panel-title"><?php esc_html_e( 'Course Pricing', 'agend-elementor' ); ?></h2>
							<div class="agend-lms-detail__price-row">
								<span class="agend-lms-detail__price-label"><?php esc_html_e( 'Price', 'agend-elementor' ); ?></span>
								<span class="agend-lms-detail__price-value<?php echo ( __( 'Free', 'agend-elementor' ) === $price ) ? ' is-free' : ''; ?>"><?php echo esc_html( $price ); ?></span>
							</div>
							<a class="agend-lms-detail__cta" href="<?php echo esc_url( $sign_in_url ); ?>"><?php esc_html_e( 'Enrol Now', 'agend-elementor' ); ?></a>
							<p class="agend-lms-detail__note"><?php esc_html_e( 'Sign in to enrol and track your progress.', 'agend-elementor' ); ?></p>
						</div>
						<?php
					}
					?>

					<div class="agend-lms-detail__panel">
						<h2 class="agend-lms-detail__panel-title"><?php esc_html_e( 'Details', 'agend-elementor' ); ?></h2>
						<?php
						$facts = array(
							array( __( 'Level', 'agend-elementor' ), agend_elementor_ssr_lms_difficulty( $difficulty ) ),
							array( __( 'Format', 'agend-elementor' ), agend_elementor_ssr_lms_mode( $mode ) ),
							array( __( 'Duration', 'agend-elementor' ), $duration ),
							array( __( 'Modules', 'agend-elementor' ), $lessons > 0 ? (string) $lessons : '' ),
							array( __( 'Category', 'agend-elementor' ), $category ),
							array( __( 'Instructor', 'agend-elementor' ), $instructor ),
						);
						foreach ( $facts as $pair ) :
							if ( '' === (string) $pair[1] ) {
								continue;
							}
							?>
							<div class="agend-lms-detail__fact">
								<span class="agend-lms-detail__fact-label"><?php echo esc_html( $pair[0] ); ?></span>
								<span class="agend-lms-detail__fact-value"><?php echo esc_html( $pair[1] ); ?></span>
							</div>
						<?php endforeach; ?>
					</div>
				</aside>
			</div>
		</div>
	</div>
	<?php
	return (string) ob_get_clean();
}
