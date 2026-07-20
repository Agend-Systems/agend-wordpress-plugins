<?php
/**
 * Server-rendered detail pages for the Agend catalogue widgets.
 *
 * When the "Server-rendered detail pages" setting is on, a catalogue detail URL
 * (currently the Directory widget's /{page}/listing/{slug}/) is resolved
 * server-side into a virtual child page of the listings page: the listing name
 * becomes the page title and the listings page its parent, so any breadcrumb
 * system natively renders Home > Listings > Item, and the detail body is
 * rendered server-side for SEO. Progressive enhancement (review submit, gallery
 * lightbox) is layered on by assets/js/directory-detail.js.
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
 * Resolves a Directory detail request into a virtual child page.
 *
 * Runs on `wp` (after the main query is parsed, before the header renders) so
 * the synthesized queried object is in place when breadcrumbs and the document
 * title are generated.
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
	if ( ! function_exists( 'agend_apps_directory_get_listing' ) ) {
		return;
	}

	$slug = sanitize_title( (string) get_query_var( 'listing' ) );
	if ( '' === $slug ) {
		return;
	}

	global $wp_query;
	if ( ! $wp_query->is_main_query() ) {
		return;
	}

	// The listings page is the page the detail endpoint hangs off; it becomes
	// the parent crumb.
	$host = get_queried_object();
	if ( ! ( $host instanceof WP_Post ) ) {
		return;
	}

	// Request member achievements only when opted in (the gateway 403s the whole
	// detail for keys without the directory.achievements.browse scope).
	$listing_query = agend_elementor_show_achievements_enabled()
		? array( 'include' => 'achievements' )
		: array();
	$response = agend_apps_directory_get_listing( $slug, $listing_query );
	$item     = ( ! is_wp_error( $response ) && ! empty( $response['data'] ) && is_array( $response['data'] ) )
		? $response['data']
		: null;

	// Unknown listing: a real 404 (correct for SEO) rather than an empty shell.
	if ( null === $item ) {
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
		return;
	}

	$name  = isset( $item['name'] ) ? (string) $item['name'] : __( 'Listing', 'agend-elementor' );
	$reviews_response = function_exists( 'agend_apps_directory_get_listing_reviews' )
		? agend_apps_directory_get_listing_reviews( $slug, array( 'limit' => 10 ) )
		: null;

	$content = agend_elementor_render_directory_detail( $item, $slug, $reviews_response, $host );

	// A synthetic id just above the highest real post id: high enough never to
	// collide with a real post, but well below the very large id ranges other
	// plugins claim (e.g. The Events Calendar Pro treats any id at/above its
	// dynamic "provisional post" base, kept a large margin above the max post
	// id, as one of its occurrence posts). Primed into the posts cache so
	// get_post()/get_post_ancestors() resolve the parent chain (host page) for
	// breadcrumb systems that pass the id, and removed on shutdown so it cannot
	// leak into a persistent object cache.
	global $wpdb;
	$max_post_id = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" );
	$fake_id     = $max_post_id + 1 + ( abs( crc32( 'listing:' . $slug ) ) % 20000 );

	$data = array(
		'ID'                    => $fake_id,
		'post_author'           => $host->post_author,
		'post_date'             => $host->post_date,
		'post_date_gmt'         => $host->post_date_gmt,
		'post_content'          => $content,
		'post_title'            => $name,
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
		'guid'                  => trailingslashit( get_permalink( $host->ID ) ) . 'listing/' . $slug . '/',
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
	add_action(
		'wp_enqueue_scripts',
		static function () {
			if ( ! wp_script_is( 'agend-elementor-directory-catalogue', 'registered' )
				&& ! wp_style_is( 'agend-elementor-directory-catalogue', 'enqueued' ) ) {
				wp_enqueue_style(
					'agend-elementor-directory-catalogue',
					AGEND_ELEMENTOR_URL . 'assets/css/directory-catalogue.css',
					array(),
					AGEND_ELEMENTOR_VERSION
				);
			}
			wp_enqueue_style( 'agend-elementor-directory-catalogue' );
			wp_enqueue_script(
				'agend-elementor-directory-detail',
				AGEND_ELEMENTOR_URL . 'assets/js/directory-detail.js',
				array(),
				AGEND_ELEMENTOR_VERSION,
				true
			);
		},
		20
	);
}
add_action( 'wp', 'agend_elementor_ssr_maybe_render_detail', 5 );

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
 * Renders the member LMS achievements ("Badges & Credentials" section).
 *
 * @param mixed $achievements Array of { type, name, color, image_url, course_title, cpd_points, earned_at }, or null.
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
		$cpd = ( isset( $achievement['cpd_points'] ) && (float) $achievement['cpd_points'] > 0 )
			? '<span class="agend-dir-cred__cpd">' . esc_html( sprintf( /* translators: %s: CPD points. */ __( '%s CPD', 'agend-elementor' ), (string) ( 0 + $achievement['cpd_points'] ) ) ) . '</span>'
			: '';

		$cards .= '<div class="agend-dir-cred">' . $media
			. '<div class="agend-dir-cred__body"><span class="agend-dir-cred__name">' . esc_html( $title ) . '</span>'
			. ( ! empty( $meta ) ? '<span class="agend-dir-cred__meta">' . implode( ' &middot; ', $meta ) . '</span>' : '' )
			. $cpd . '</div></div>';
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

			<div class="agend-dir-detail__hero"<?php echo ! empty( $item['hero_image_url'] ) ? ' style="background-image:linear-gradient(180deg, rgba(30,42,74,0.30), rgba(30,42,74,0.80)), url(\'' . esc_url( $item['hero_image_url'] ) . '\');"' : ''; ?>>
				<div class="agend-dir-detail__hero-inner">
					<?php if ( ! empty( $item['logo_url'] ) ) : ?>
						<img class="agend-dir-detail__logo" src="<?php echo esc_url( $item['logo_url'] ); ?>" alt="<?php echo esc_attr( $name ); ?>" />
					<?php endif; ?>
					<h1 class="agend-dir-detail__title"><?php echo esc_html( $name ); ?></h1>
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

		<div class="agend-dir-review-form" data-agend-listing-id="<?php echo esc_attr( $item['id'] ?? '' ); ?>">
			<h3 class="agend-dir-review-form__title"><?php esc_html_e( 'Write a Review', 'agend-elementor' ); ?></h3>
			<div class="agend-dir-review-form__rating">
				<span class="agend-dir-review-form__label"><?php esc_html_e( 'Your rating', 'agend-elementor' ); ?></span>
				<div class="agend-dir-review-form__stars">
					<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
						<button type="button" class="agend-dir-review-form__star" data-value="<?php echo (int) $i; ?>" aria-label="<?php echo esc_attr( sprintf( _n( '%d star', '%d stars', $i, 'agend-elementor' ), $i ) ); ?>">&#9733;</button>
					<?php endfor; ?>
				</div>
			</div>
			<label class="agend-dir-review-form__field">
				<span class="agend-dir-review-form__label"><?php esc_html_e( 'Name', 'agend-elementor' ); ?></span>
				<input type="text" class="agend-dir-review-form__input" data-field="name" placeholder="<?php esc_attr_e( 'Your name', 'agend-elementor' ); ?>" />
			</label>
			<label class="agend-dir-review-form__field">
				<span class="agend-dir-review-form__label"><?php esc_html_e( 'Email', 'agend-elementor' ); ?></span>
				<input type="email" class="agend-dir-review-form__input" data-field="email" placeholder="you@example.com" />
			</label>
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
