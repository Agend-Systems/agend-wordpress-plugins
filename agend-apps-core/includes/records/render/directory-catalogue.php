<?php
/**
 * Server render of the directory catalogue surface.
 *
 * Page-builder agnostic: the same markup and client config whichever editor
 * placed the surface. An adapter passes the surface's settings (see
 * `agend_apps_records_surface_schema()`) and echoes the returned HTML.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalises a SELECT2 multiple value to a clean string list.
 *
 * @param mixed $value Raw setting value.
 * @return array List of non-empty strings.
 */
function agend_apps_records_directory_catalogue_string_list( $value ): array {
	if ( ! is_array( $value ) ) {
		return array();
	}

	return array_values( array_filter( array_map( 'strval', $value ), 'strlen' ) );
}

/**
 * Builds the client-side config object from the widget settings.
 *
 * @param array $s Surface settings (Elementor-shaped values: toggles are 'yes'/'').
 * @return array Config passed to the frontend script as JSON.
 */
function agend_apps_records_directory_catalogue_build_config( array $s ): array {
	return array(
		'heading'    => array(
			'show'     => 'yes' === ( $s['show_heading'] ?? 'yes' ),
			'title'    => (string) ( $s['heading_text'] ?? '' ),
			'subtitle' => (string) ( $s['subheading_text'] ?? '' ),
		),
		'layout'     => array(
			'style'      => (string) ( $s['layout_style'] ?? 'grid' ),
			'desktop'    => (int) ( $s['columns_desktop'] ?? 3 ),
			'tablet'     => (int) ( $s['columns_tablet'] ?? 2 ),
			'mobile'     => (int) ( $s['columns_mobile'] ?? 1 ),
			'cardRadius' => (int) ( $s['card_radius'] ?? 10 ),
		),
		'card'       => array(
			'logo'          => 'yes' === ( $s['show_logo'] ?? 'yes' ),
			'rating'        => 'yes' === ( $s['show_rating'] ?? 'yes' ),
			'category'      => 'yes' === ( $s['show_category'] ?? 'yes' ),
			'location'      => 'yes' === ( $s['show_location'] ?? 'yes' ),
			'badges'        => 'yes' === ( $s['show_badges'] ?? 'yes' ),
			'description'   => 'yes' === ( $s['show_description'] ?? 'yes' ),
			'excerptLength' => (int) ( $s['excerpt_length'] ?? 110 ),
		),
		'filters'    => array(
			'search'        => 'yes' === ( $s['show_search'] ?? 'yes' ),
			'category'      => 'yes' === ( $s['show_category_filter'] ?? 'yes' ),
			'rating'        => 'yes' === ( $s['show_rating_filter'] ?? 'yes' ),
			'categoryMulti' => 'yes' === ( $s['multi_category_filter'] ?? 'no' ),
		),
		'exclusions' => array(
			'categories' => agend_apps_records_directory_catalogue_string_list( $s['exclude_categories'] ?? array() ),
			'featured'   => 'yes' === ( $s['featured_only'] ?? 'no' ),
		),
		'detail'     => array(
			'reviews'    => 'yes' === ( $s['show_reviews'] ?? 'yes' ),
			'reviewForm' => 'yes' === ( $s['show_review_form'] ?? 'yes' ),
		),
		'pagination' => array(
			'style'   => (string) ( $s['pagination_style'] ?? 'numbered' ),
			'perPage' => (int) ( $s['per_page'] ?? 12 ),
		),
		'colours'    => array(
			'heading'    => (string) ( $s['heading_colour'] ?? '#1E2A4A' ),
			'body'       => (string) ( $s['body_colour'] ?? '#26304D' ),
			'accent'     => (string) ( $s['accent_colour'] ?? '#FF6B55' ),
			'button'     => (string) ( $s['button_colour'] ?? '#FF6B55' ),
			'buttonText' => (string) ( $s['button_text_colour'] ?? '#FFFFFF' ),
		),
		'theme'      => array(
			'inheritFonts'   => 'yes' === ( $s['inherit_fonts'] ?? 'yes' ),
			'inheritColours' => 'yes' === ( $s['inherit_colours'] ?? 'yes' ),
		),
	);
}

/**
 * Renders the surface container on the front end, returned as HTML.
 *
 * The catalogue itself is rendered client-side from the config below by
 * assets/js/directory-catalogue.js.
 */
function agend_apps_records_render_directory_catalogue( array $settings ): string {
	ob_start();
	// A catalogue inside a card template would fetch the list once per
	// card; nothing sensible can come of it.
	if ( Agend_Apps_Records_Record_Context::has() ) {
		return (string) ob_get_clean();
	}

	$config   = agend_apps_records_directory_catalogue_build_config( $settings );

	// US-3.2: path-based detail routing. The directory detail rule
	// (registered in routing.php) exposes the slug on
	// the current page URL as /{page}/listing/{slug}/ via the private
	// `agend_dir_listing` query var (the public `listing` segment maps to it;
	// the namespaced var avoids the common `listing` query-var collision).
	// The slug is injected server-side so a direct load renders the detail
	// with no catalogue flash; the base page path lets the script build
	// pretty links, and it falls back to the ?agend_listing= query param when
	// pretty permalinks are off or the base path is unavailable.
	$page_id               = get_queried_object_id();
	$base_path             = $page_id ? get_permalink( $page_id ) : '';
	$config['deepLink']    = sanitize_title( (string) get_query_var( 'agend_dir_listing' ) );
	$config['prettyLinks'] = (bool) get_option( 'permalink_structure' );
	$config['basePath']    = is_string( $base_path ) ? $base_path : '';
	// When server-rendered detail pages are on, cards navigate to the
	// server-rendered detail URL (a real child page) instead of swapping the
	// detail in client-side, so breadcrumbs and SEO resolve natively.
	$config['ssrDetail']   = function_exists( 'agend_apps_records_ssr_detail_enabled' )
		&& agend_apps_records_ssr_detail_enabled();
	// Member LMS achievements ("Badges & Credentials") are opt-in and require
	// the directory.achievements.browse scope on the account's API key.
	$config['showAchievements'] = function_exists( 'agend_apps_records_show_achievements_enabled' )
		&& agend_apps_records_show_achievements_enabled();

	// Dedicated Directory page: with one configured, a catalogue elsewhere
	// never opens a detail in place, and deepLink is only honoured on that
	// page (unchanged behaviour when no page is set).
	$config['detailBase']   = Agend_Apps_Records_Pages::page_url( 'listing' );
	$config['onDetailPage'] = '' === $config['detailBase']
		|| Agend_Apps_Records_Pages::is_dedicated_page( 'listing', $page_id );
	if ( ! $config['onDetailPage'] ) {
		$config['deepLink'] = '';
	}

	$style   = agend_apps_records_directory_catalogue_inline_style( $config );
	$columns = ( 'list' === $config['layout']['style'] ) ? 1 : max( 1, (int) $config['layout']['desktop'] );

	// Card template mode: the first page is rendered here through the
	// template and later pages arrive as fragments from
	// /agend-apps/v1/cards/listings.
	$template_id = (int) ( $settings['card_template'] ?? 0 );
	if ( $template_id > 0 && Agend_Apps_Templates::is_valid_template( $template_id ) ) {
		$config['cardMode']      = 'template';
		$config['filterTemplate'] = agend_apps_records_directory_catalogue_filter_template_id( $settings );
		$config['filterPosition'] = (string) ( $settings['filter_position'] ?? 'top' );
		$config['cardTemplate']  = $template_id;
		$config['cardLinkWhole'] = 'yes' === ( $settings['card_link_whole'] ?? 'yes' );
		$config['hostPageId']    = (int) $page_id;
		$config['restBase']      = esc_url_raw( rest_url( 'agend-apps/v1' ) );
		$config['fragmentPath']  = '/cards/listings';
		agend_apps_records_directory_catalogue_render_templated( $config, $template_id, $style, $columns );
		return (string) ob_get_clean();
	}
	$config['cardMode'] = 'legacy';
	$config['filterTemplate'] = agend_apps_records_directory_catalogue_filter_template_id( $settings );
	$config['filterPosition'] = (string) ( $settings['filter_position'] ?? 'top' );

	// One complete grid row of skeleton placeholders as the initial state
	// (3 when the layout is a single column or list), so no plain "Loading…"
	// text flashes before the script takes over.
	$skeletons = ( 1 === $columns ) ? 3 : $columns;
	?>
		<div class="agend-directory-catalogue" style="<?php echo esc_attr( $style ); ?>" data-agend-directory-config="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">
			<span class="agend-visually-hidden" role="status"><?php esc_html_e( 'Loading listings…', 'agend-apps-core' ); ?></span>
			<div class="agend-dir-filter-slot"><?php echo agend_apps_records_directory_catalogue_render_filters( (int) $config['filterTemplate'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template output. ?></div>
			<div class="agend-dir-grid agend-dir-grid--<?php echo esc_attr( $config['layout']['style'] ); ?>" style="--agend-dir-cols-desktop:<?php echo (int) $columns; ?>;">
				<?php for ( $i = 0; $i < $skeletons; $i++ ) : ?>
					<article class="agend-dir-card agend-dir-skeleton" aria-hidden="true">
						<div class="agend-dir-card__media"></div>
						<div class="agend-dir-card__body">
							<div class="agend-skel-line" style="width:40%"></div>
							<div class="agend-skel-line" style="width:85%"></div>
							<div class="agend-skel-line" style="width:60%"></div>
						</div>
					</article>
				<?php endfor; ?>
			</div>
		</div>
		<?php
	return (string) ob_get_clean();
}

/**
 * The configured filter template id, or 0.
 *
 * @param array $settings Widget settings.
 * @return int
 */
function agend_apps_records_directory_catalogue_filter_template_id( array $settings ): int {
	$id = (int) ( $settings['filter_template'] ?? 0 );
	return ( $id > 0 && Agend_Apps_Templates::is_valid_template( $id ) ) ? $id : 0;
}

/**
 * Renders the filter template, with the record type in scope so the filter
 * widgets inside know which catalogue they drive.
 *
 * @param int $template_id The filter template id.
 * @return string
 */
function agend_apps_records_directory_catalogue_render_filters( int $template_id ): string {
	if ( 0 === $template_id ) {
		return '';
	}
	Agend_Apps_Records_Filter_Context::set( 'listing' );
	try {
		return Agend_Apps_Templates::render_plain( $template_id );
	} finally {
		Agend_Apps_Records_Filter_Context::reset();
	}
}

/**
 * The colour and radius CSS variables the catalogue styles read.
 *
 * @param array $config The widget config.
 * @return string
 */
function agend_apps_records_directory_catalogue_inline_style( array $config ): string {
	return sprintf(
		'--agend-dir-heading:%1$s;--agend-dir-body:%2$s;--agend-dir-accent:%3$s;--agend-dir-button:%4$s;--agend-dir-button-text:%5$s;--agend-dir-card-radius:%6$dpx;',
		esc_attr( $config['colours']['heading'] ),
		esc_attr( $config['colours']['body'] ),
		esc_attr( $config['colours']['accent'] ),
		esc_attr( $config['colours']['button'] ),
		esc_attr( $config['colours']['buttonText'] ),
		(int) $config['layout']['cardRadius']
	);
}

/**
 * Renders the first page of cards through the card template.
 *
 * The filter bar and pagination stay script-built, so the markup leaves a
 * slot for each; the script adopts this DOM instead of rebuilding it.
 *
 * @param array  $config      The widget config (with card template keys).
 * @param int    $template_id The card template id.
 * @param string $style       Inline CSS variables.
 * @param int    $columns     Desktop column count.
 */
function agend_apps_records_directory_catalogue_render_templated( array $config, int $template_id, string $style, int $columns ): void {
	$list  = agend_apps_records_unwrap_list( agend_apps_records_fetch_list( 'listing', agend_apps_records_listings_list_args( $config, 1 ) ) );
	$cards = agend_apps_records_render_cards(
		'listing',
		$template_id,
		$list['items'],
		array(
			'card_link_whole' => $config['cardLinkWhole'],
			'host_page_id'    => $config['hostPageId'],
		)
	);
	$config['initialPagination'] = $list['pagination'];
	$config['initialError']      = $list['error'];
	?>
		<div class="agend-directory-catalogue agend-directory-catalogue--templated agend-filters-<?php echo esc_attr( $config['filterPosition'] ); ?>" style="<?php echo esc_attr( $style ); ?>" data-agend-directory-config="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">
			<?php if ( ! empty( $config['heading']['show'] ) && ( '' !== $config['heading']['title'] || '' !== $config['heading']['subtitle'] ) ) : ?>
				<div class="agend-dir-heading">
					<?php if ( '' !== $config['heading']['title'] ) : ?>
						<h2 class="agend-dir-heading__title"><?php echo esc_html( $config['heading']['title'] ); ?></h2>
					<?php endif; ?>
					<?php if ( '' !== $config['heading']['subtitle'] ) : ?>
						<p class="agend-dir-heading__subtitle"><?php echo esc_html( $config['heading']['subtitle'] ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>
			<div class="agend-dir-filter-slot"><?php echo agend_apps_records_directory_catalogue_render_filters( (int) $config['filterTemplate'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template output. ?></div>
			<div class="agend-dir-status" <?php echo ( empty( $cards ) ) ? '' : 'style="display:none"'; ?>>
				<?php echo $list['error'] ? esc_html__( 'Unable to load listings.', 'agend-apps-core' ) : esc_html__( 'No listings found.', 'agend-apps-core' ); ?>
			</div>
			<div class="agend-dir-grid agend-dir-grid--<?php echo esc_attr( $config['layout']['style'] ); ?> agend-dir-grid--templated" style="--agend-dir-cols-desktop:<?php echo (int) $columns; ?>;--agend-dir-cols-tablet:<?php echo (int) $config['layout']['tablet']; ?>;--agend-dir-cols-mobile:<?php echo (int) $config['layout']['mobile']; ?>;">
				<?php
			foreach ( $cards as $card ) {
				echo $card['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template output; record values escaped by the field widgets.
			}
			?>
			</div>
			<div class="agend-dir-pager-slot"></div>
		</div>
		<?php
}
