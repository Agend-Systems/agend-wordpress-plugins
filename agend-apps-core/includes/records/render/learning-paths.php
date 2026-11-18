<?php
/**
 * Server render of the learning paths surface.
 *
 * Page-builder agnostic: the same markup whichever editor placed the surface.
 * An adapter passes the surface's settings (see
 * `agend_apps_records_surface_schema()`) and echoes the returned HTML.
 *
 * Unlike the catalogues this draws everything on the server and ships no
 * script: it is a read-only list with no filters, paging or detail view, so
 * there is nothing for a script to do, and the content is in the page for
 * visitors and search engines alike. The paths come from the cached LMS
 * wrappers, read as an anonymous visitor would (a path is the same for every
 * viewer, and a member's token would otherwise take the read out of the shared
 * cache), so the surface works the same in every member sign-in mode and needs
 * no sign-in.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Every renderer asks the surface gate before it fetches or draws anything;
// callers that load one renderer on its own (the unit tests) need it present.
require_once __DIR__ . '/../surface-gate.php';

// The path limit lives with the setting that exposes it.
require_once __DIR__ . '/../schema/learning-paths.php';

// The difficulty label is shared with the courses.
require_once __DIR__ . '/../format.php';

/**
 * A setting that holds path ids, as a clean list: the multiselect's array, or
 * a comma-separated string; anything but the characters an id or slug is made
 * of is dropped, and a repeat keeps its first place.
 *
 * @param mixed $value Raw setting value.
 * @return string[]
 */
function agend_apps_records_learning_paths_id_list( $value ): array {
	if ( is_string( $value ) ) {
		$value = explode( ',', $value );
	}

	if ( ! is_array( $value ) ) {
		return array();
	}

	$ids = array();

	foreach ( $value as $id ) {
		$id = is_scalar( $id ) ? preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $id ) : '';

		if ( '' !== $id && ! in_array( $id, $ids, true ) ) {
			$ids[] = $id;
		}
	}

	return $ids;
}

/**
 * Builds the render config from the surface settings.
 *
 * @param array $s Surface settings (Elementor-shaped values: toggles are 'yes'/'').
 * @return array The values the renderer reads, typed and bounded.
 */
function agend_apps_records_learning_paths_build_config( array $s ): array {
	$colours = agend_apps_records_resolve_colours(
		array( 'inherit_colours' => (string) ( $s['inherit_colours'] ?? 'yes' ) ) + $s,
		array(
			'heading' => AGEND_APPS_RECORDS_COLOUR_DEFAULTS['heading'],
			'body'    => AGEND_APPS_RECORDS_COLOUR_DEFAULTS['body'],
			'accent'  => AGEND_APPS_RECORDS_COLOUR_DEFAULTS['accent'],
		)
	);

	$on = static function ( string $key, string $fallback = 'yes' ) use ( $s ): bool {
		return 'yes' === ( $s[ $key ] ?? $fallback );
	};

	$source   = 'selected' === ( $s['path_source'] ?? 'all' ) ? 'selected' : 'all';
	$selected = array_slice( agend_apps_records_learning_paths_id_list( $s['selected_paths'] ?? array() ), 0, AGEND_APPS_RECORDS_LEARNING_PATHS_MAX );

	return array(
		'heading' => array(
			'show'     => $on( 'show_heading' ),
			'title'    => trim( (string) ( $s['heading_text'] ?? '' ) ),
			'subtitle' => trim( (string) ( $s['subheading_text'] ?? '' ) ),
		),
		'source'  => $source,
		'paths'   => $selected,
		'max'     => max( 1, min( AGEND_APPS_RECORDS_LEARNING_PATHS_MAX, (int) ( $s['max_paths'] ?? 6 ) ) ),
		'layout'  => array(
			'desktop'    => max( 1, min( 3, (int) ( $s['columns_desktop'] ?? 2 ) ) ),
			'tablet'     => max( 1, min( 2, (int) ( $s['columns_tablet'] ?? 2 ) ) ),
			'cardRadius' => max( 0, min( 48, (int) ( $s['card_radius'] ?? 10 ) ) ),
			'card'       => 'stacked' === ( $s['card_layout'] ?? 'auto' ) ? 'stacked' : 'auto',
		),
		'card'    => array(
			'image'         => $on( 'show_image' ),
			'difficulty'    => $on( 'show_difficulty' ),
			'duration'      => $on( 'show_duration' ),
			'description'   => $on( 'show_description' ),
			'excerptLength' => max( 20, min( 400, (int) ( $s['excerpt_length'] ?? 160 ) ) ),
			'sequence'      => $on( 'show_sequence' ),
			'courses'       => $on( 'show_courses' ),
		),
		'courses' => array(
			'links'  => 'none' !== ( $s['course_links'] ?? 'detail' ),
			'newTab' => $on( 'links_new_tab', '' ),
		),
		'empty'   => array(
			'message' => trim( (string) ( $s['empty_message'] ?? '' ) ),
			'hide'    => $on( 'hide_when_empty', '' ),
		),
		'colours' => $colours['colours'],
		'theme'   => array(
			'colourSource' => $colours['source'],
		),
	);
}

/**
 * A description as plain text of at most $length characters, cut at a word.
 *
 * Path descriptions are plain text on the gateway, but this is shown inside
 * markup of ours, so any tag in it is dropped rather than trusted.
 *
 * @param mixed $text   The description.
 * @param int   $length Most characters, not counting the ellipsis.
 * @return string
 */
function agend_apps_records_learning_paths_excerpt( $text, int $length ): string {
	$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( is_scalar( $text ) ? (string) $text : '' ) ) );

	$strlen = function_exists( 'mb_strlen' ) ? 'mb_strlen' : 'strlen';
	$substr = function_exists( 'mb_substr' ) ? 'mb_substr' : 'substr';

	if ( $strlen( $text ) <= $length ) {
		return $text;
	}

	$cut = $substr( $text, 0, $length );

	// Back to the last whole word, unless that would throw most of it away.
	if ( ' ' !== $substr( $text, $length, 1 ) ) {
		$whole = preg_replace( '/\s+\S*$/u', '', $cut );

		if ( null !== $whole && $strlen( $whole ) > $length / 2 ) {
			$cut = $whole;
		}
	}

	return rtrim( $cut, " \t\n\r\0\x0B.,;:-" ) . '…';
}

/**
 * One path as the renderer draws it, from the gateway's path row and, when the
 * course list was read, the path's detail.
 *
 * @param array      $path    The path, from the list or the detail.
 * @param array|null $detail  The path's detail (with `courses`), or null when
 *                            the course list was not read or could not be.
 * @return array{id: string, slug: string, title: string, description: string, image: string, difficulty: string, hours: int, sequential: bool, courses: array|null}
 *         `courses` is null when the list is unknown, and an empty array for a
 *         path known to have none.
 */
function agend_apps_records_learning_paths_normalise( array $path, ?array $detail = null ): array {
	$courses = null;

	if ( null !== $detail && isset( $detail['courses'] ) && is_array( $detail['courses'] ) ) {
		$rows = array_values( array_filter( $detail['courses'], 'is_array' ) );

		// The gateway sends them in order already; sorting again keeps the
		// numbering right if that ever changed, and a tie keeps the position
		// the gateway gave it (usort() is not stable before PHP 8.0).
		$ordered = array();

		foreach ( $rows as $position => $course ) {
			$ordered[] = array( (int) ( $course['order_index'] ?? $position ), $position, $course );
		}

		usort(
			$ordered,
			static function ( array $a, array $b ): int {
				return ( $a[0] <=> $b[0] ) ?: ( $a[1] <=> $b[1] );
			}
		);

		$rows = array_column( $ordered, 2 );

		$courses = array();

		foreach ( $rows as $course ) {
			$title = trim( (string) ( $course['title'] ?? '' ) );

			if ( '' === $title ) {
				continue;
			}

			$courses[] = array(
				'id'       => (string) ( $course['id'] ?? '' ),
				'slug'     => sanitize_title( (string) ( $course['slug'] ?? '' ) ),
				'title'    => $title,
				'optional' => ! empty( $course['is_optional'] ),
			);
		}
	}

	$hours = $path['estimated_duration_hours'] ?? 0;

	return array(
		'id'          => (string) ( $path['id'] ?? '' ),
		'slug'        => (string) ( $path['slug'] ?? '' ),
		'title'       => trim( (string) ( $path['title'] ?? '' ) ),
		'description' => is_scalar( $path['description'] ?? null ) ? (string) $path['description'] : '',
		'image'       => is_scalar( $path['image_url'] ?? null ) ? (string) $path['image_url'] : '',
		'difficulty'  => is_scalar( $path['difficulty'] ?? null ) ? (string) $path['difficulty'] : '',
		'hours'       => is_numeric( $hours ) ? max( 0, (int) $hours ) : 0,
		'sequential'  => ! empty( $path['is_sequential'] ),
		'courses'     => $courses,
	);
}

/**
 * The decoded body of a single-path response: the path itself, whether the
 * gateway wrapped it in `data` or not.
 *
 * @param mixed $response A single-path wrapper result.
 * @return array|null Null for an error or an unreadable answer.
 */
function agend_apps_records_learning_paths_unwrap_one( $response ): ?array {
	if ( is_wp_error( $response ) || ! is_array( $response ) ) {
		return null;
	}

	if ( isset( $response['data'] ) && is_array( $response['data'] ) ) {
		return $response['data'];
	}

	return isset( $response['id'] ) ? $response : null;
}

/**
 * Whether a path is published. The gateway's list only holds published paths,
 * but its single read answers for any path it owns, and a chosen path may have
 * been unpublished since. A row that does not say is shown.
 *
 * @param array $path A path row.
 * @return bool
 */
function agend_apps_records_learning_paths_is_published( array $path ): bool {
	return ! isset( $path['published'] ) || (bool) $path['published'];
}

/**
 * Reads the paths a surface shows, with their courses when the card lists them.
 *
 * "All": one list read for the newest published paths, then one detail read per
 * path for its courses (sent together, and cached per path). "Chosen": one
 * detail read per chosen path, in the order chosen, which carries the path
 * itself as well as its courses. A path whose course list could not be read
 * still shows, without one; the surface only fails when no path could be read.
 *
 * @param array $config The surface config {@see agend_apps_records_learning_paths_build_config()}.
 * @return array{paths: array, error: bool} Normalised paths, and whether the read failed.
 */
function agend_apps_records_learning_paths_fetch( array $config ): array {
	$failed = array(
		'paths' => array(),
		'error' => true,
	);

	if ( ! function_exists( 'agend_apps_lms_get_paths' ) || ! function_exists( 'agend_apps_lms_get_paths_by_id' ) ) {
		return $failed;
	}

	$with_courses = ! empty( $config['card']['courses'] );

	if ( 'selected' === $config['source'] ) {
		if ( array() === $config['paths'] ) {
			return array(
				'paths' => array(),
				'error' => false,
			);
		}

		$reads = agend_apps_lms_get_paths_by_id( $config['paths'], true );
		$paths = array();
		$read  = 0;

		foreach ( $config['paths'] as $id ) {
			$detail = agend_apps_records_learning_paths_unwrap_one( $reads[ $id ] ?? null );

			if ( null === $detail ) {
				continue;
			}

			++$read;

			if ( agend_apps_records_learning_paths_is_published( $detail ) ) {
				$paths[] = agend_apps_records_learning_paths_normalise( $detail, $with_courses ? $detail : null );
			}
		}

		return array(
			'paths' => $paths,
			'error' => 0 === $read,
		);
	}

	$list = agend_apps_records_unwrap_list( agend_apps_lms_get_paths( array( 'limit' => $config['max'] ), true ) );

	if ( $list['error'] ) {
		return $failed;
	}

	$rows = array();

	foreach ( $list['items'] as $row ) {
		if ( '' !== (string) ( $row['id'] ?? '' ) && agend_apps_records_learning_paths_is_published( $row ) ) {
			$rows[] = $row;
		}
	}

	$rows    = array_slice( $rows, 0, $config['max'] );
	$details = array();

	if ( $with_courses && array() !== $rows ) {
		$reads = agend_apps_lms_get_paths_by_id( array_map( 'strval', array_column( $rows, 'id' ) ), true );

		foreach ( $reads as $id => $response ) {
			$details[ (string) $id ] = agend_apps_records_learning_paths_unwrap_one( $response );
		}
	}

	$paths = array();

	foreach ( $rows as $row ) {
		$paths[] = agend_apps_records_learning_paths_normalise( $row, $details[ (string) $row['id'] ] ?? null );
	}

	return array(
		'paths' => $paths,
		'error' => false,
	);
}

/**
 * The colour and layout CSS variables the stylesheet reads.
 *
 * @param array $config The surface config.
 * @return string
 */
function agend_apps_records_learning_paths_inline_style( array $config ): string {
	return sprintf(
		'--agend-lp-heading:%1$s;--agend-lp-body:%2$s;--agend-lp-accent:%3$s;--agend-lp-card-radius:%4$dpx;--agend-lp-cols-desktop:%5$d;--agend-lp-cols-tablet:%6$d;',
		esc_attr( $config['colours']['heading'] ),
		esc_attr( $config['colours']['body'] ),
		esc_attr( $config['colours']['accent'] ),
		(int) $config['layout']['cardRadius'],
		(int) $config['layout']['desktop'],
		(int) $config['layout']['tablet']
	);
}

/**
 * A path's image address, or '' when it is not one a page should load: the
 * gateway sends a full http(s) URL, and anything else (a script or data
 * scheme, a bare word) is not drawn.
 *
 * @param string $url The image URL.
 * @return string
 */
function agend_apps_records_learning_paths_image_url( string $url ): string {
	$url = trim( $url );

	return 1 === preg_match( '#^https?://#i', $url ) ? esc_url_raw( $url ) : '';
}

/**
 * The URL of a course's page, or '' when it has none to link to.
 *
 * The course page is the Courses page set under Catalogue Pages: the page that
 * hosts the courses catalogue and opens a course at `{page}/course/{slug}/`. A
 * site with no such page set has nowhere sensible to send a visitor (this page
 * is not a courses catalogue), so the course is listed without a link.
 *
 * @param string $slug The course slug.
 * @return string
 */
function agend_apps_records_learning_paths_course_url( string $slug ): string {
	if ( '' === $slug || ! class_exists( 'Agend_Apps_Records_Pages' ) || 0 === Agend_Apps_Records_Pages::page_id( 'course' ) ) {
		return '';
	}

	return Agend_Apps_Records_Pages::detail_url( 'course', $slug );
}

/**
 * Renders the surface on the front end, returned as HTML.
 *
 * @param array $settings Surface settings (Elementor-shaped values).
 * @return string
 */
function agend_apps_records_render_learning_paths( array $settings ): string {
	$gate = agend_apps_records_surface_gate( 'learning-paths', false );
	if ( null !== $gate ) {
		return $gate;
	}

	// A path list inside a card template would be fetched once per card;
	// nothing sensible can come of it.
	if ( Agend_Apps_Records_Record_Context::has() ) {
		return '';
	}

	$config = agend_apps_records_learning_paths_build_config( $settings );
	$read   = agend_apps_records_learning_paths_fetch( $config );
	$paths  = $read['paths'];

	// Nothing to show, and the author would rather the visitor saw nothing.
	// A failed read is not "no paths": it says so.
	if ( array() === $paths && ! $read['error'] && $config['empty']['hide'] ) {
		return '';
	}

	$style   = agend_apps_records_learning_paths_inline_style( $config );
	$heading = $config['heading'];
	$classes = 'stacked' === $config['layout']['card'] ? 'agend-learning-paths agend-learning-paths--stacked' : 'agend-learning-paths';

	ob_start();
	?>
	<div class="<?php echo esc_attr( $classes ); ?>" style="<?php echo esc_attr( $style ); ?>">
		<?php if ( ! empty( $heading['show'] ) && ( '' !== $heading['title'] || '' !== $heading['subtitle'] ) ) : ?>
			<div class="agend-learning-paths__heading">
				<?php if ( '' !== $heading['title'] ) : ?>
					<h2 class="agend-learning-paths__title"><?php echo esc_html( $heading['title'] ); ?></h2>
				<?php endif; ?>
				<?php if ( '' !== $heading['subtitle'] ) : ?>
					<p class="agend-learning-paths__subtitle"><?php echo esc_html( $heading['subtitle'] ); ?></p>
				<?php endif; ?>
			</div>
		<?php endif; ?>
		<?php if ( array() === $paths ) : ?>
			<p class="agend-learning-paths__status<?php echo $read['error'] ? ' agend-learning-paths__status--error' : ''; ?>" role="status">
				<?php
				echo $read['error']
					? esc_html__( 'Unable to load learning paths.', 'agend-apps-core' )
					: esc_html( '' !== $config['empty']['message'] ? $config['empty']['message'] : __( 'No learning paths are available yet.', 'agend-apps-core' ) );
				?>
			</p>
		<?php else : ?>
			<ul class="agend-learning-paths__grid" role="list">
				<?php foreach ( $paths as $path ) : ?>
					<li class="agend-learning-paths__item">
						<?php echo agend_apps_records_learning_paths_render_card( $path, $config ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the card renderer. ?>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</div>
	<?php

	return (string) ob_get_clean();
}

/**
 * One path's card.
 *
 * @param array $path   A normalised path {@see agend_apps_records_learning_paths_normalise()}.
 * @param array $config The surface config.
 * @return string
 */
function agend_apps_records_learning_paths_render_card( array $path, array $config ): string {
	$card = $config['card'];

	$meta = array();

	if ( $card['duration'] && $path['hours'] > 0 ) {
		/* translators: %s: number of hours. */
		$meta[] = sprintf( _n( '%s hour', '%s hours', $path['hours'], 'agend-apps-core' ), number_format_i18n( $path['hours'] ) );
	}

	if ( $card['courses'] && is_array( $path['courses'] ) && array() !== $path['courses'] ) {
		/* translators: %s: number of courses. */
		$meta[] = sprintf( _n( '%s course', '%s courses', count( $path['courses'] ), 'agend-apps-core' ), number_format_i18n( count( $path['courses'] ) ) );
	}

	$difficulty = $card['difficulty'] ? agend_apps_records_ssr_lms_difficulty( $path['difficulty'] ) : '';
	$excerpt    = $card['description'] ? agend_apps_records_learning_paths_excerpt( $path['description'], $card['excerptLength'] ) : '';
	$image      = $card['image'] ? agend_apps_records_learning_paths_image_url( $path['image'] ) : '';
	$title      = '' !== $path['title'] ? $path['title'] : $path['slug'];

	$has_courses = $card['courses'] && is_array( $path['courses'] ) && array() !== $path['courses'];
	$note        = $card['sequence'] && $path['sequential'];

	ob_start();
	?>
	<article class="agend-learning-paths__card">
		<div class="agend-learning-paths__head">
			<?php if ( '' !== $image ) : ?>
				<div class="agend-learning-paths__media">
					<img class="agend-learning-paths__img" src="<?php echo esc_url( $image ); ?>" alt="" loading="lazy" decoding="async">
				</div>
			<?php endif; ?>
			<div class="agend-learning-paths__head-text">
				<?php if ( '' !== $difficulty ) : ?>
					<p class="agend-learning-paths__eyebrow"><?php echo esc_html( $difficulty ); ?></p>
				<?php endif; ?>
				<h3 class="agend-learning-paths__name"><?php echo esc_html( $title ); ?></h3>
				<?php if ( array() !== $meta ) : ?>
					<ul class="agend-learning-paths__meta" role="list">
						<?php foreach ( $meta as $item ) : ?>
							<li class="agend-learning-paths__meta-item"><?php echo esc_html( $item ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<?php if ( '' !== $excerpt ) : ?>
					<p class="agend-learning-paths__description"><?php echo esc_html( $excerpt ); ?></p>
				<?php endif; ?>
				<?php if ( $note && ! $has_courses ) : ?>
					<p class="agend-learning-paths__sequence"><?php esc_html_e( 'Complete the courses in order.', 'agend-apps-core' ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php if ( $has_courses ) : ?>
			<div class="agend-learning-paths__steps">
				<?php if ( $note ) : ?>
					<div class="agend-learning-paths__steps-head">
						<p class="agend-learning-paths__sequence"><?php esc_html_e( 'Complete the courses in order.', 'agend-apps-core' ); ?></p>
					</div>
				<?php endif; ?>
				<ol class="agend-learning-paths__courses<?php echo $path['sequential'] ? ' agend-learning-paths__courses--sequential' : ''; ?>" role="list">
					<?php foreach ( $path['courses'] as $course ) : ?>
						<?php
						$url       = $config['courses']['links'] ? agend_apps_records_learning_paths_course_url( $course['slug'] ) : '';
						$row_class = 'agend-learning-paths__course' . ( '' !== $url ? ' agend-learning-paths__course--link' : '' ) . ( $course['optional'] ? ' agend-learning-paths__course--optional' : '' );
						?>
						<li class="<?php echo esc_attr( $row_class ); ?>">
							<span class="agend-learning-paths__course-main">
								<?php if ( '' !== $url ) : ?>
									<a class="agend-learning-paths__course-link exclude-agend-theme-link" href="<?php echo esc_url( $url ); ?>"<?php echo $config['courses']['newTab'] ? ' target="_blank" rel="noopener"' : ''; ?>><?php echo esc_html( $course['title'] ); ?><?php if ( $config['courses']['newTab'] ) : ?><span class="agend-learning-paths__sr"> <?php esc_html_e( '(opens in a new tab)', 'agend-apps-core' ); ?></span><?php endif; ?></a>
								<?php else : ?>
									<span class="agend-learning-paths__course-title"><?php echo esc_html( $course['title'] ); ?></span>
								<?php endif; ?>
								<?php if ( $course['optional'] ) : ?>
									<span class="agend-learning-paths__optional"><?php esc_html_e( 'Optional', 'agend-apps-core' ); ?></span>
								<?php endif; ?>
							</span>
							<?php if ( '' !== $url ) : ?>
								<svg class="agend-learning-paths__chevron" width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M6 3.5 10.5 8 6 12.5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/></svg>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ol>
			</div>
		<?php endif; ?>
	</article>
	<?php

	return (string) ob_get_clean();
}
