<?php
/**
 * Server render of the events catalogue surface.
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
function agend_apps_records_events_catalogue_string_list( $value ): array {
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
function agend_apps_records_events_catalogue_build_config( array $s ): array {
	return array(
		'heading'        => array(
			'show'       => 'yes' === ( $s['show_heading'] ?? 'yes' ),
			'title'      => (string) ( $s['heading_text'] ?? '' ),
			'subtitle'   => (string) ( $s['subheading_text'] ?? '' ),
		),
		'layout'         => array(
			'desktop'    => (int) ( $s['columns_desktop'] ?? 3 ),
			'tablet'     => (int) ( $s['columns_tablet'] ?? 2 ),
			'mobile'     => (int) ( $s['columns_mobile'] ?? 1 ),
			'cardRadius' => (int) ( $s['card_radius'] ?? 10 ),
		),
		'card'           => array(
			'image'         => 'yes' === ( $s['show_image'] ?? 'yes' ),
			'dateBadge'     => 'yes' === ( $s['show_date_badge'] ?? 'yes' ),
			'pills'         => 'yes' === ( $s['show_pills'] ?? 'yes' ),
			'description'   => 'yes' === ( $s['show_description'] ?? 'yes' ),
			'excerptLength' => (int) ( $s['excerpt_length'] ?? 110 ),
			'pricing'       => 'yes' === ( $s['show_pricing'] ?? 'yes' ),
		),
		'filters'        => array(
			'search'   => 'yes' === ( $s['show_search'] ?? 'yes' ),
			'category' => 'yes' === ( $s['show_category_filter'] ?? 'yes' ),
			'type'          => 'yes' === ( $s['show_type_filter'] ?? 'yes' ),
			'city'          => 'yes' === ( $s['show_city_filter'] ?? 'yes' ),
			'date'          => 'yes' === ( $s['show_date_filter'] ?? 'yes' ),
			'categoryMulti' => 'yes' === ( $s['multi_category_filter'] ?? 'no' ),
			'typeMulti'     => 'yes' === ( $s['multi_type_filter'] ?? 'no' ),
			'cityMulti'     => 'yes' === ( $s['multi_city_filter'] ?? 'no' ),
			'categoryMatch' => (string) ( $s['category_match_mode'] ?? 'any' ),
		),
		'exclusions'     => array(
			'categories'    => agend_apps_records_events_catalogue_string_list( $s['exclude_categories'] ?? array() ),
			'venueTypes'    => agend_apps_records_events_catalogue_string_list( $s['exclude_venue_types'] ?? array() ),
			'cities'        => agend_apps_records_events_catalogue_string_list( $s['exclude_cities'] ?? array() ),
			'categoryMatch' => (string) ( $s['exclude_category_match_mode'] ?? 'any' ),
		),
		'timeframe'      => (string) ( $s['event_timeframe'] ?? 'upcoming' ),
		// Event times are shown in the organisation timezone, not the
		// viewer's browser timezone, so they match the server-rendered
		// detail. An IANA name, or a manual "+hh:mm" offset the client
		// falls back to local time for.
		'timezone'       => wp_timezone_string(),
		'pagination'     => array(
			'style'   => (string) ( $s['pagination_style'] ?? 'numbered' ),
			'perPage' => (int) ( $s['per_page'] ?? 9 ),
		),
		'colours'        => array(
			'heading'    => (string) ( $s['heading_colour'] ?? '#1E2A4A' ),
			'body'       => (string) ( $s['body_colour'] ?? '#26304D' ),
			'accent'     => (string) ( $s['accent_colour'] ?? '#FF6B55' ),
			'button'     => (string) ( $s['button_colour'] ?? '#FF6B55' ),
			'buttonText' => (string) ( $s['button_text_colour'] ?? '#FFFFFF' ),
		),
		'theme'          => array(
			'inheritFonts'   => 'yes' === ( $s['inherit_fonts'] ?? 'yes' ),
			'inheritColours' => 'yes' === ( $s['inherit_colours'] ?? 'yes' ),
		),
	);
}

/**
 * Renders the surface container on the front end, returned as HTML.
 *
 * The catalogue itself is rendered client-side from the config below by
 * assets/js/events-catalogue.js.
 */
function agend_apps_records_render_events_catalogue( array $settings ): string {
	ob_start();
	// A catalogue inside a card template would fetch the list once per
	// card; nothing sensible can come of it.
	if ( Agend_Apps_Records_Record_Context::has() ) {
		return (string) ob_get_clean();
	}

	$config   = agend_apps_records_events_catalogue_build_config( $settings );

	// US-1.2: path-based detail routing. The `event` rewrite endpoint
	// (registered in routing.php) exposes the slug on
	// the current page URL as /{page}/event/{slug}/. The slug is injected
	// server-side so a direct load renders the detail with no catalogue
	// flash; the base page path lets the script build pretty links, and it
	// falls back to the ?agend_event= query param when pretty permalinks are
	// off or the base path is unavailable.
	$page_id            = get_queried_object_id();
	$base_path          = $page_id ? get_permalink( $page_id ) : '';
	$config['deepLink']    = sanitize_title( (string) get_query_var( 'event' ) );
	$config['prettyLinks'] = (bool) get_option( 'permalink_structure' );
	$config['basePath']    = is_string( $base_path ) ? $base_path : '';

	// Dedicated Events page (fixes the host-page hijack): with one
	// configured, a catalogue elsewhere never opens a detail in place:
	// deepLink is only honoured on the dedicated page, or, with no
	// dedicated page configured, on whatever page hosts the widget
	// (unchanged from before this setting existed).
	$config['detailBase']   = Agend_Apps_Records_Pages::page_url( 'event' );
	$config['onDetailPage'] = '' === $config['detailBase']
		|| Agend_Apps_Records_Pages::is_dedicated_page( 'event', $page_id );
	if ( ! $config['onDetailPage'] ) {
		$config['deepLink'] = '';
	}

	// Cart mode: when the Agend Apps Shop plugin is active, the registration
	// flow adds tickets to the cart instead of registering + paying straight
	// away. The cart page URL (if configured) drives the post-add "View Cart"
	// link on the confirmation screen.
	$config['cartEnabled'] = agend_apps_records_shop_cart_enabled();
	$config['cartPageUrl'] = agend_apps_records_shop_cart_page_url();

	$style   = agend_apps_records_events_catalogue_inline_style( $config );
	$columns = max( 1, (int) $config['layout']['desktop'] );

	// Card template mode: the first page is rendered here through the
	// template and later pages arrive as fragments from
	// /agend-apps/v1/cards/events.
	$template_id = (int) ( $settings['card_template'] ?? 0 );
	if ( $template_id > 0 && Agend_Apps_Templates::is_valid_template( $template_id ) ) {
		$config['cardMode']      = 'template';
		$config['filterTemplate'] = agend_apps_records_events_catalogue_filter_template_id( $settings );
		$config['filterPosition'] = (string) ( $settings['filter_position'] ?? 'top' );
		$config['cardTemplate']  = $template_id;
		$config['cardLinkWhole'] = 'yes' === ( $settings['card_link_whole'] ?? 'yes' );
		$config['hostPageId']    = (int) $page_id;
		$config['restBase']      = esc_url_raw( rest_url( 'agend-apps/v1' ) );
		$config['fragmentPath']  = '/cards/events';
		agend_apps_records_events_catalogue_render_templated( $config, $template_id, $style, $columns );
		return (string) ob_get_clean();
	}
	$config['cardMode'] = 'legacy';
	$config['filterTemplate'] = agend_apps_records_events_catalogue_filter_template_id( $settings );
	$config['filterPosition'] = (string) ( $settings['filter_position'] ?? 'top' );

	// One complete grid row of skeleton placeholders as the initial state
	// (3 when the layout is a single column), so no plain "Loading…" text
	// flashes before the script takes over.
	$skeletons = ( 1 === $columns ) ? 3 : $columns;
	?>
		<div class="agend-events-catalogue" style="<?php echo esc_attr( $style ); ?>" data-agend-events-config="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">
			<span class="agend-visually-hidden" role="status"><?php esc_html_e( 'Loading events…', 'agend-apps-core' ); ?></span>
			<div class="agend-ev-filter-slot"><?php echo agend_apps_records_events_catalogue_render_filters( (int) $config['filterTemplate'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template output. ?></div>
			<div class="agend-ev-grid" style="--agend-ev-cols-desktop:<?php echo (int) $columns; ?>;">
				<?php for ( $i = 0; $i < $skeletons; $i++ ) : ?>
					<article class="agend-ev-card agend-ev-skeleton" aria-hidden="true">
						<div class="agend-ev-card__media"></div>
						<div class="agend-ev-card__body">
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
function agend_apps_records_events_catalogue_filter_template_id( array $settings ): int {
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
function agend_apps_records_events_catalogue_render_filters( int $template_id ): string {
	if ( 0 === $template_id ) {
		return '';
	}
	Agend_Apps_Records_Filter_Context::set( 'event' );
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
function agend_apps_records_events_catalogue_inline_style( array $config ): string {
	return sprintf(
		'--agend-ev-heading:%1$s;--agend-ev-body:%2$s;--agend-ev-accent:%3$s;--agend-ev-button:%4$s;--agend-ev-button-text:%5$s;--agend-ev-card-radius:%6$dpx;',
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
 * The filter bar and pagination stay script-built (they need the category
 * and venue lists), so the markup leaves a slot for each; the script adopts
 * this DOM instead of rebuilding it.
 *
 * @param array  $config      The widget config (with card template keys).
 * @param int    $template_id The card template id.
 * @param string $style       Inline CSS variables.
 * @param int    $columns     Desktop column count.
 */
function agend_apps_records_events_catalogue_render_templated( array $config, int $template_id, string $style, int $columns ): void {
	$list = agend_apps_records_unwrap_list(
		function_exists( 'agend_apps_events_get_events' )
			? agend_apps_events_get_events( agend_apps_records_events_list_args( $config, 1 ) )
			: null
	);
	$cards = agend_apps_records_render_cards(
		'event',
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
		<div class="agend-events-catalogue agend-events-catalogue--templated agend-filters-<?php echo esc_attr( $config['filterPosition'] ); ?>" style="<?php echo esc_attr( $style ); ?>" data-agend-events-config="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">
			<?php if ( ! empty( $config['heading']['show'] ) && ( '' !== $config['heading']['title'] || '' !== $config['heading']['subtitle'] ) ) : ?>
				<div class="agend-ev-heading">
					<?php if ( '' !== $config['heading']['title'] ) : ?>
						<h2 class="agend-ev-heading__title"><?php echo esc_html( $config['heading']['title'] ); ?></h2>
					<?php endif; ?>
					<?php if ( '' !== $config['heading']['subtitle'] ) : ?>
						<p class="agend-ev-heading__subtitle"><?php echo esc_html( $config['heading']['subtitle'] ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>
			<div class="agend-ev-filter-slot"><?php echo agend_apps_records_events_catalogue_render_filters( (int) $config['filterTemplate'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template output. ?></div>
			<div class="agend-ev-status" <?php echo ( empty( $cards ) ) ? '' : 'style="display:none"'; ?>>
				<?php echo $list['error'] ? esc_html__( 'Unable to load events.', 'agend-apps-core' ) : esc_html__( 'No events found.', 'agend-apps-core' ); ?>
			</div>
			<div class="agend-ev-grid agend-ev-grid--templated" style="--agend-ev-cols-desktop:<?php echo (int) $columns; ?>;--agend-ev-cols-tablet:<?php echo (int) $config['layout']['tablet']; ?>;--agend-ev-cols-mobile:<?php echo (int) $config['layout']['mobile']; ?>;">
				<?php
			foreach ( $cards as $card ) {
				echo $card['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template output; record values escaped by the field widgets.
			}
			?>
			</div>
			<div class="agend-ev-pager-slot"></div>
		</div>
		<?php
}
