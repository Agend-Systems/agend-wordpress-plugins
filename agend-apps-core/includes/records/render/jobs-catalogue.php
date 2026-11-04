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
	$layout   = 'grid' === ( $s['layout_style'] ?? 'list' ) ? 'grid' : 'list';
	$apply    = trim( (string) ( $s['apply_label'] ?? '' ) );

	return array(
		'heading'    => array(
			'show'     => $on( 'show_heading' ),
			'title'    => (string) ( $s['heading_text'] ?? '' ),
			'subtitle' => (string) ( $s['subheading_text'] ?? '' ),
		),
		'listing'    => array(
			'featuredOnly' => $on( 'featured_only', '' ),
			'category'     => $category,
			'sortBy'       => $sorts[ $sort ][0],
			'sortOrder'    => $sorts[ $sort ][1],
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
			'workArrangement' => $on( 'show_work_arrangement' ),
			'salary'          => $on( 'show_salary' ),
			'categories'      => $on( 'show_categories' ),
			'summary'         => $on( 'show_summary' ),
			'excerptLength'   => (int) ( $s['excerpt_length'] ?? 160 ),
			'postedDate'      => $on( 'show_posted_date' ),
			'closingDate'     => $on( 'show_closing_date' ),
		),
		'filters'    => array(
			'search'   => $on( 'show_search' ),
			// A board pinned to one category has nothing to choose between.
			'category' => '' === $category && $on( 'show_category_filter' ),
			'type'     => $on( 'show_type_filter' ),
			'location' => $on( 'show_location_filter' ),
		),
		'detail'     => array(
			'applyLabel'   => '' !== $apply ? $apply : __( 'Apply now', 'agend-apps-core' ),
			'emailApply'   => $on( 'allow_email_apply' ),
			'companyPanel' => $on( 'show_company_panel' ),
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

	$style     = agend_apps_records_jobs_catalogue_inline_style( $config );
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
