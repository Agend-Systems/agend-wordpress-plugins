<?php
/**
 * Elementor Events Catalogue widget for Agend Elementor Widgets.
 *
 * @package Agend_Elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders a searchable, filterable grid of published Agend events.
 *
 * The widget outputs a configured container; the frontend script
 * (assets/js/events-catalogue.js) fetches events from the Agend Apps Core
 * REST proxy (`/wp-json/agend-apps/v1/events`) and renders the catalogue
 * client-side. This is the catalogue surface of SPEC-INFRA-EVT-001 (US-EVT.1,
 * US-EVT.2, US-EVT.7, US-EVT.8); detail and registration are separate stories.
 */
class Agend_Elementor_Events_Catalogue extends \Elementor\Widget_Base {

	/**
	 * Returns the widget name (unique identifier).
	 *
	 * @return string Widget name.
	 */
	public function get_name(): string {
		return 'agend-events-catalogue';
	}

	/**
	 * Returns the widget display title.
	 *
	 * @return string Widget title.
	 */
	public function get_title(): string {
		return __( 'Agend Events', 'agend-elementor' );
	}

	/**
	 * Returns the Elementor icon class for the widget.
	 *
	 * @return string Icon class.
	 */
	public function get_icon(): string {
		return 'eicon-calendar';
	}

	/**
	 * Returns the Elementor categories this widget belongs to.
	 *
	 * @return array List of category slugs.
	 */
	public function get_categories(): array {
		return array( Agend_Elementor::CATEGORY );
	}

	/**
	 * Returns the frontend script handle this widget depends on.
	 *
	 * @return array Script handles.
	 */
	public function get_script_depends(): array {
		return array( 'agend-apps-records-events-catalogue' );
	}

	/**
	 * Returns the frontend style handle this widget depends on.
	 *
	 * @return array Style handles.
	 */
	public function get_style_depends(): array {
		return array( 'agend-apps-records-events-catalogue' );
	}

	/**
	 * Registers all Elementor controls for this widget.
	 */
	protected function register_controls(): void {
		$this->register_content_controls();
		$this->register_style_controls();
	}

	/**
	 * Registers the Content tab controls (US-EVT.7).
	 */
	private function register_content_controls(): void {
		Agend_Elementor_Schema_Controls::register( $this, agend_apps_records_surface_schema( 'events-catalogue' ) );
	}

	/**
	 * Registers the Style tab controls (US-EVT.8, colour subset).
	 */
	private function register_style_controls(): void {
		// Typography section.
		$this->start_controls_section(
			'section_style_typography',
			array(
				'label' => __( 'Typography', 'agend-elementor' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'inherit_fonts',
			array(
				'label'       => __( 'Inherit site theme fonts', 'agend-elementor' ),
				'type'        => \Elementor\Controls_Manager::SWITCHER,
				'default'     => 'yes',
				'description' => __( 'Pull heading and body fonts from the connected Agend account\'s site config.', 'agend-elementor' ),
			)
		);

		$this->end_controls_section();

		// Colours section.
		$this->start_controls_section(
			'section_style_colours',
			array(
				'label' => __( 'Colours', 'agend-elementor' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'inherit_colours',
			array(
				'label'       => __( 'Inherit site theme colours', 'agend-elementor' ),
				'type'        => \Elementor\Controls_Manager::SWITCHER,
				'default'     => 'yes',
				'description' => __( 'Pull heading, body, and accent colours from the connected account\'s site config. Turn off to set them manually below.', 'agend-elementor' ),
			)
		);

		$this->add_control(
			'heading_colour',
			array(
				'label'   => __( 'Heading colour', 'agend-elementor' ),
				'type'    => \Elementor\Controls_Manager::COLOR,
				'default' => '#1E2A4A',
			)
		);

		$this->add_control(
			'body_colour',
			array(
				'label'   => __( 'Body text colour', 'agend-elementor' ),
				'type'    => \Elementor\Controls_Manager::COLOR,
				'default' => '#26304D',
			)
		);

		$this->add_control(
			'accent_colour',
			array(
				'label'       => __( 'Highlight / accent colour', 'agend-elementor' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				'default'     => '#FF6B55',
				'description' => __( 'Drives FREE price text, active filter state, and the date badge month label.', 'agend-elementor' ),
			)
		);

		$this->add_control(
			'button_colour',
			array(
				'label'   => __( 'Button colour', 'agend-elementor' ),
				'type'    => \Elementor\Controls_Manager::COLOR,
				'default' => '#FF6B55',
			)
		);

		$this->add_control(
			'button_text_colour',
			array(
				'label'   => __( 'Button text colour', 'agend-elementor' ),
				'type'    => \Elementor\Controls_Manager::COLOR,
				'default' => '#FFFFFF',
			)
		);

		$this->end_controls_section();
	}
	/**
	 * Normalises a SELECT2 multiple value to a clean string list.
	 *
	 * @param mixed $value Raw setting value.
	 * @return array List of non-empty strings.
	 */
	private function string_list( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'strval', $value ), 'strlen' ) );
	}

	/**
	 * Builds the client-side config object from the widget settings.
	 *
	 * @param array $s Settings for display.
	 * @return array Config passed to the frontend script as JSON.
	 */
	private function build_config( array $s ): array {
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
				'categories'    => $this->string_list( $s['exclude_categories'] ?? array() ),
				'venueTypes'    => $this->string_list( $s['exclude_venue_types'] ?? array() ),
				'cities'        => $this->string_list( $s['exclude_cities'] ?? array() ),
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
	 * Renders the widget container on the frontend.
	 *
	 * The catalogue itself is rendered client-side from the config below by
	 * assets/js/events-catalogue.js.
	 */
	protected function render(): void {
		// A catalogue inside a card template would fetch the list once per
		// card; nothing sensible can come of it.
		if ( class_exists( 'Agend_Apps_Records_Record_Context' ) && Agend_Apps_Records_Record_Context::has() ) {
			if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
				echo '<div class="elementor-alert elementor-alert-warning">' . esc_html__( 'An Events Catalogue cannot be placed inside a card or detail template.', 'agend-elementor' ) . '</div>';
			}
			return;
		}

		$settings = $this->get_settings_for_display();
		$config   = $this->build_config( $settings );

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

		$style   = $this->inline_style( $config );
		$columns = max( 1, (int) $config['layout']['desktop'] );

		// Card template mode: the first page is rendered here through the
		// template and later pages arrive as fragments from
		// /agend-apps/v1/cards/events.
		$template_id = (int) ( $settings['card_template'] ?? 0 );
		if ( $template_id > 0 && Agend_Elementor_Template_Renderer::is_valid_template( $template_id ) ) {
			$config['cardMode']      = 'template';
			$config['filterTemplate'] = $this->filter_template_id( $settings );
			$config['filterPosition'] = (string) ( $settings['filter_position'] ?? 'top' );
			$config['cardTemplate']  = $template_id;
			$config['cardLinkWhole'] = 'yes' === ( $settings['card_link_whole'] ?? 'yes' );
			$config['hostPageId']    = (int) $page_id;
			$config['restBase']      = esc_url_raw( rest_url( 'agend-apps/v1' ) );
			$config['fragmentPath']  = '/cards/events';
			$this->render_templated( $config, $template_id, $style, $columns );
			return;
		}
		$config['cardMode'] = 'legacy';
		$config['filterTemplate'] = $this->filter_template_id( $settings );
		$config['filterPosition'] = (string) ( $settings['filter_position'] ?? 'top' );

		// One complete grid row of skeleton placeholders as the initial state
		// (3 when the layout is a single column), so no plain "Loading…" text
		// flashes before the script takes over.
		$skeletons = ( 1 === $columns ) ? 3 : $columns;
		?>
		<div class="agend-events-catalogue" style="<?php echo esc_attr( $style ); ?>" data-agend-events-config="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">
			<span class="agend-visually-hidden" role="status"><?php esc_html_e( 'Loading events…', 'agend-elementor' ); ?></span>
			<div class="agend-ev-filter-slot"><?php echo $this->render_filters( (int) $config['filterTemplate'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Elementor template output. ?></div>
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
	}

	/**
	 * The configured filter template id, or 0.
	 *
	 * @param array $settings Widget settings.
	 * @return int
	 */
	private function filter_template_id( array $settings ): int {
		$id = (int) ( $settings['filter_template'] ?? 0 );
		return ( $id > 0 && Agend_Elementor_Template_Renderer::is_valid_template( $id ) ) ? $id : 0;
	}

	/**
	 * Renders the filter template, with the record type in scope so the filter
	 * widgets inside know which catalogue they drive.
	 *
	 * @param int $template_id The filter template id.
	 * @return string
	 */
	private function render_filters( int $template_id ): string {
		if ( 0 === $template_id ) {
			return '';
		}
		Agend_Apps_Records_Filter_Context::set( 'event' );
		try {
			return Agend_Elementor_Template_Renderer::render_plain( $template_id );
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
	private function inline_style( array $config ): string {
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
	private function render_templated( array $config, int $template_id, string $style, int $columns ): void {
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
			<div class="agend-ev-filter-slot"><?php echo $this->render_filters( (int) $config['filterTemplate'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Elementor template output. ?></div>
			<div class="agend-ev-status" <?php echo ( empty( $cards ) ) ? '' : 'style="display:none"'; ?>>
				<?php echo $list['error'] ? esc_html__( 'Unable to load events.', 'agend-elementor' ) : esc_html__( 'No events found.', 'agend-elementor' ); ?>
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
}
