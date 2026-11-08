<?php
/**
 * Server render of the jobs catalogue surface (Agend Careers).
 *
 * Page-builder agnostic: the same markup and client config whichever editor
 * placed the surface. An adapter passes the surface's settings (see
 * `agend_apps_records_surface_schema()`) and echoes the returned HTML. The
 * board, its filters and the job detail view are drawn client-side by
 * assets/js/jobs-catalogue.js from the `/agend-apps/v1/jobs` proxy.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Every renderer asks the surface gate before it fetches or draws anything;
// callers that load one renderer on its own (the unit tests) need it present.
require_once __DIR__ . '/../surface-gate.php';
require_once __DIR__ . '/../jobs-visibility.php';
require_once __DIR__ . '/../filters.php';

/**
 * The sort orders the jobs catalogue offers, as gateway `sortBy`/`sortOrder`.
 *
 * @return array<string, array{0: string, 1: string}>
 */
function agend_apps_records_jobs_catalogue_sorts(): array {
	return array(
		'published_at:desc' => array( 'published_at', 'desc' ),
		'expires_at:asc'    => array( 'expires_at', 'asc' ),
		'title:asc'         => array( 'title', 'asc' ),
		'salary_max:desc'   => array( 'salary_max', 'desc' ),
	);
}

/**
 * The orders a visitor's sort dropdown offers, as value to label. `distance`
 * is not listed: the script adds "Nearest" once the visitor has set a
 * location, since the gateway refuses it without one.
 *
 * @return array<string, string>
 */
function agend_apps_records_jobs_catalogue_visitor_sorts(): array {
	return array(
		'published_at:desc' => __( 'Newest first', 'agend-apps-core' ),
		'expires_at:asc'    => __( 'Closing soonest', 'agend-apps-core' ),
		'title:asc'         => __( 'Title, A to Z', 'agend-apps-core' ),
	);
}

/**
 * The sort dropdown's options. The board's own order setting always leads,
 * even when it is one a visitor could not otherwise pick (highest salary), so
 * the dropdown opens on the order the board is actually in.
 *
 * @param string $default The board's `sort` setting, as `sortBy:sortOrder`.
 * @return array<int, array{value: string, label: string}>
 */
function agend_apps_records_jobs_catalogue_sort_options( string $default ): array {
	$labels = agend_apps_records_jobs_catalogue_visitor_sorts() + array( 'salary_max:desc' => __( 'Highest salary', 'agend-apps-core' ) );
	$values = array_unique( array_merge( array( $default ), array_keys( agend_apps_records_jobs_catalogue_visitor_sorts() ) ) );

	$options = array();
	foreach ( $values as $value ) {
		if ( isset( $labels[ $value ] ) ) {
			$options[] = array(
				'value' => $value,
				'label' => $labels[ $value ],
			);
		}
	}
	return $options;
}

/**
 * The distance search's runtime settings: the radius choices (what the
 * directory's Location filter accepts, from 1 up to the gateway's 1000 km,
 * ascending) and the choice the control opens on, the nearest to the default
 * the author set.
 *
 * @param array $s Surface settings.
 * @return array{radii: float[], radius: float}
 */
function agend_apps_records_jobs_catalogue_distance_config( array $s ): array {
	// The jobs list takes a radius of 1 km or more; the directory's helper
	// also lets a fraction through, which the route would refuse.
	$radii = array_values(
		array_filter(
			agend_apps_records_filter_radius_choices( $s['distance_radius_choices'] ?? '10, 25, 50, 100, 250' ),
			static function ( $km ) {
				return $km >= 1;
			}
		)
	);
	if ( array() === $radii ) {
		$radii = array( 10.0, 25.0, 50.0, 100.0, 250.0 );
	}
	$default = is_numeric( $s['distance_radius_default'] ?? null ) ? (float) $s['distance_radius_default'] : 50.0;

	$radius = $radii[0];
	foreach ( $radii as $choice ) {
		if ( abs( $choice - $default ) < abs( $radius - $default ) ) {
			$radius = $choice;
		}
	}

	return array(
		'radii'  => $radii,
		'radius' => $radius,
	);
}

/**
 * The states a board's state filter offers, from the comma-separated setting.
 *
 * @param string $setting The "States offered" setting.
 * @return string[]
 */
function agend_apps_records_jobs_catalogue_states( string $setting ): array {
	$states = array();
	foreach ( explode( ',', $setting ) as $state ) {
		$state = trim( sanitize_text_field( $state ) );
		if ( '' !== $state && strlen( $state ) <= 60 && ! isset( $states[ strtolower( $state ) ] ) ) {
			$states[ strtolower( $state ) ] = $state;
		}
	}
	return array_values( $states );
}

/**
 * The board's tag filters: one per repeater row that names at least one tag.
 * A dropdown row lets the visitor pick one of its tags; a checkboxes row
 * offers each tag on its own. The gateway keeps a job only when it carries
 * every tag the visitor sets, across all rows.
 *
 * @param mixed $rows The `tag_filters` repeater value.
 * @return array<int, array{label: string, style: string, tags: string[]}>
 */
function agend_apps_records_jobs_catalogue_tag_filters( $rows ): array {
	$filters = array();
	foreach ( is_array( $rows ) ? $rows : array() as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$tags = array_values( array_unique( array_filter( array_map( 'sanitize_title', array_map( 'strval', (array) ( $row['tags'] ?? array() ) ) ) ) ) );
		if ( array() === $tags ) {
			continue;
		}
		$label     = trim( (string) ( $row['label'] ?? '' ) );
		$filters[] = array(
			'label' => '' !== $label ? $label : __( 'Tags', 'agend-apps-core' ),
			'style' => 'checkboxes' === ( $row['style'] ?? '' ) ? 'checkboxes' : 'select',
			'tags'  => $tags,
		);
	}
	return $filters;
}

/**
 * Builds the client-side config object from the surface settings.
 *
 * @param array $s Surface settings (Elementor-shaped values: toggles are 'yes'/'').
 * @return array Config passed to the frontend script as JSON.
 */
function agend_apps_records_jobs_catalogue_build_config( array $s ): array {
	$colours = agend_apps_records_resolve_colours(
		array( 'inherit_colours' => (string) ( $s['inherit_colours'] ?? 'yes' ) ) + $s,
		AGEND_APPS_RECORDS_COLOUR_DEFAULTS
	);

	$sorts = agend_apps_records_jobs_catalogue_sorts();
	$sort  = (string) ( $s['sort'] ?? 'published_at:desc' );
	if ( ! isset( $sorts[ $sort ] ) ) {
		$sort = 'published_at:desc';
	}

	$on = static function ( string $key, string $fallback = 'yes' ) use ( $s ): bool {
		return 'yes' === ( $s[ $key ] ?? $fallback );
	};

	$category = sanitize_title( (string) ( $s['category'] ?? '' ) );
	$states   = agend_apps_records_jobs_catalogue_states( (string) ( $s['state_options'] ?? 'ACT, NSW, NT, QLD, SA, TAS, VIC, WA' ) );
	$layout   = 'grid' === ( $s['layout_style'] ?? 'list' ) ? 'grid' : 'list';
	$apply    = trim( (string) ( $s['apply_label'] ?? '' ) );
	$distance = agend_apps_records_jobs_catalogue_distance_config( $s );

	return array(
		'heading'    => array(
			'show'     => $on( 'show_heading' ),
			'title'    => (string) ( $s['heading_text'] ?? '' ),
			'subtitle' => (string) ( $s['subheading_text'] ?? '' ),
		),
		'listing'    => array(
			'featuredOnly'  => $on( 'featured_only', '' ),
			'featuredFirst' => $on( 'featured_first' ),
			'category'     => $category,
			'sortBy'       => $sorts[ $sort ][0],
			'sortOrder'    => $sorts[ $sort ][1],
			'sort'         => $sort,
		),
		'layout'     => array(
			'style'      => $layout,
			'desktop'    => 'grid' === $layout ? max( 1, (int) ( $s['columns_desktop'] ?? 3 ) ) : 1,
			'tablet'     => 'grid' === $layout ? max( 1, (int) ( $s['columns_tablet'] ?? 2 ) ) : 1,
			'mobile'     => 1,
			'cardRadius' => (int) ( $s['card_radius'] ?? 10 ),
		),
		'card'       => array(
			'logo'            => $on( 'show_logo' ),
			'company'         => $on( 'show_company' ),
			'location'        => $on( 'show_location' ),
			'employmentType'  => $on( 'show_employment_type' ),
			'experience'      => $on( 'show_experience' ),
			'workArrangement' => $on( 'show_work_arrangement' ),
			'salary'          => $on( 'show_salary' ),
			'categories'      => $on( 'show_categories' ),
			'summary'         => $on( 'show_summary' ),
			'excerptLength'   => (int) ( $s['excerpt_length'] ?? 160 ),
			'postedDate'      => $on( 'show_posted_date' ),
			'closingDate'     => $on( 'show_closing_date' ),
		),
		'filters'    => array(
			'search'          => $on( 'show_search' ),
			// A board pinned to one category has nothing to choose between.
			'category'        => '' === $category && $on( 'show_category_filter' ),
			'type'            => $on( 'show_type_filter' ),
			'experience'      => $on( 'show_experience_filter' ),
			'workArrangement' => $on( 'show_work_arrangement_filter' ),
			'multiple'        => $on( 'allow_multiple', '' ),
			'state'           => $on( 'show_state_filter', '' ) && array() !== $states,
			'states'          => $states,
			'posted'          => $on( 'show_posted_filter', '' ),
			'location'        => $on( 'show_location_filter' ),
			'distance'        => $on( 'show_distance_filter', '' ),
			'distanceRadii'   => $distance['radii'],
			'distanceRadius'  => $distance['radius'],
			'sort'            => $on( 'show_sort', '' ),
			'sortOptions'     => agend_apps_records_jobs_catalogue_sort_options( $sort ),
			'tags'            => agend_apps_records_jobs_catalogue_tag_filters( $s['tag_filters'] ?? array() ),
		),
		'detail'     => array(
			'applyLabel'   => '' !== $apply ? $apply : __( 'Apply now', 'agend-apps-core' ),
			'emailApply'   => $on( 'allow_email_apply' ),
			'companyPanel' => $on( 'show_company_panel' ),
		),
		'signIn'     => array(
			'text'   => '' !== trim( (string) ( $s['signin_text'] ?? '' ) ) ? (string) $s['signin_text'] : __( 'Sign in as a member to see the full job details.', 'agend-apps-core' ),
			'button' => '' !== trim( (string) ( $s['signin_button'] ?? '' ) ) ? (string) $s['signin_button'] : __( 'Sign in', 'agend-apps-core' ),
		),
		'pagination' => array(
			'style'   => in_array( $s['pagination_style'] ?? '', array( 'numbered', 'load_more', 'none' ), true ) ? (string) $s['pagination_style'] : 'numbered',
			'perPage' => min( 100, max( 1, (int) ( $s['per_page'] ?? 10 ) ) ),
		),
		'colours'    => $colours['colours'],
		'theme'      => array(
			'inheritFonts' => $on( 'inherit_fonts' ),
			'colourSource' => $colours['source'],
		),
	);
}

/**
 * The colour and radius CSS variables the catalogue styles read.
 *
 * @param array $config The surface config.
 * @return string
 */
function agend_apps_records_jobs_catalogue_inline_style( array $config ): string {
	return sprintf(
		'--agend-jobs-heading:%1$s;--agend-jobs-body:%2$s;--agend-jobs-accent:%3$s;--agend-jobs-button:%4$s;--agend-jobs-button-text:%5$s;--agend-jobs-card-radius:%6$dpx;--agend-jobs-cols-desktop:%7$d;--agend-jobs-cols-tablet:%8$d;',
		esc_attr( $config['colours']['heading'] ),
		esc_attr( $config['colours']['body'] ),
		esc_attr( $config['colours']['accent'] ),
		esc_attr( $config['colours']['button'] ),
		esc_attr( $config['colours']['buttonText'] ),
		(int) $config['layout']['cardRadius'],
		(int) $config['layout']['desktop'],
		(int) $config['layout']['tablet']
	);
}

/**
 * Renders the surface container on the front end, returned as HTML.
 *
 * @param array $settings Surface settings.
 * @return string
 */
function agend_apps_records_render_jobs_catalogue( array $settings ): string {
	$gate = agend_apps_records_surface_gate( 'jobs-catalogue', false );
	if ( null !== $gate ) {
		return $gate;
	}

	// A catalogue inside a card template would fetch the list once per card;
	// nothing sensible can come of it.
	if ( Agend_Apps_Records_Record_Context::has() ) {
		return '';
	}

	$config = agend_apps_records_jobs_catalogue_build_config( $settings );

	// Path-based detail routing via the `job` rewrite endpoint
	// (/{page}/job/{slug}/, query var `agend_job`). The slug is injected
	// server-side so a direct load opens the detail with no board flash; the
	// base page path lets the script build pretty links, falling back to the
	// ?agend_job= query param when pretty permalinks are off.
	$page_id               = get_queried_object_id();
	$base_path             = $page_id ? get_permalink( $page_id ) : '';
	$config['deepLink']    = sanitize_title( (string) get_query_var( 'agend_job' ) );
	$config['prettyLinks'] = (bool) get_option( 'permalink_structure' );
	$config['basePath']    = is_string( $base_path ) ? $base_path : '';

	// Dedicated Jobs page: with one configured, a board elsewhere (a featured
	// strip on the home page) links there rather than opening a detail in
	// place. With none configured, whichever page hosts the board is used.
	$config['detailBase']   = Agend_Apps_Records_Pages::page_url( 'job' );
	$config['onDetailPage'] = '' === $config['detailBase']
		|| Agend_Apps_Records_Pages::is_dedicated_page( 'job', $page_id );
	if ( ! $config['onDetailPage'] ) {
		$config['deepLink'] = '';
	}

	// The site's jobs visibility setting (jobs-visibility.php), applied here
	// and in the REST proxy. The sign-in URL carries a placeholder the script
	// swaps for the job the visitor wanted, so they land back on it.
	$current_url      = $config['basePath'];
	$config['access'] = array(
		'visibility'  => agend_apps_records_jobs_visibility(),
		'canList'     => agend_apps_records_jobs_visitor_can_view( 'list' ),
		'canDetail'   => agend_apps_records_jobs_visitor_can_view( 'detail' ),
		'signInUrl'   => agend_apps_records_jobs_sign_in_url( '__AGEND_JOB_RETURN__' ),
		'placeholder' => '__AGEND_JOB_RETURN__',
	);

	$style     = agend_apps_records_jobs_catalogue_inline_style( $config );

	// Members only, and this visitor is not signed in: no board and no data,
	// only the invitation to sign in and come back to this page.
	if ( ! $config['access']['canList'] ) {
		$return = '' !== $current_url ? $current_url : home_url( '/' );
		ob_start();
		?>
			<div class="agend-jobs-catalogue agend-jobs-catalogue--signin" style="<?php echo esc_attr( $style ); ?>">
				<?php if ( ! empty( $config['heading']['show'] ) && '' !== $config['heading']['title'] ) : ?>
					<div class="agend-jobs-heading">
						<h2 class="agend-jobs-heading__title"><?php echo esc_html( $config['heading']['title'] ); ?></h2>
					</div>
				<?php endif; ?>
				<div class="agend-jobs-signin">
					<p class="agend-jobs-signin__text"><?php echo esc_html( $config['signIn']['text'] ); ?></p>
					<a class="agend-jobs-button" href="<?php echo esc_url( agend_apps_records_jobs_sign_in_url( $return ) ); ?>"><?php echo esc_html( $config['signIn']['button'] ); ?></a>
				</div>
			</div>
			<?php
		return (string) ob_get_clean();
	}
	$skeletons = 'grid' === $config['layout']['style'] ? max( 1, (int) $config['layout']['desktop'] ) : 3;

	ob_start();
	?>
		<div class="agend-jobs-catalogue agend-jobs-catalogue--<?php echo esc_attr( $config['layout']['style'] ); ?>" style="<?php echo esc_attr( $style ); ?>" data-agend-jobs-config="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">
			<span class="agend-visually-hidden" role="status"><?php esc_html_e( 'Loading jobs…', 'agend-apps-core' ); ?></span>
			<div class="agend-jobs-grid">
				<?php for ( $i = 0; $i < $skeletons; $i++ ) : ?>
					<article class="agend-jobs-card agend-jobs-skeleton" aria-hidden="true">
						<div class="agend-jobs-card__body">
							<div class="agend-skel-line" style="width:55%"></div>
							<div class="agend-skel-line" style="width:35%"></div>
							<div class="agend-skel-line" style="width:80%"></div>
						</div>
					</article>
				<?php endfor; ?>
			</div>
		</div>
		<?php
	return (string) ob_get_clean();
}
