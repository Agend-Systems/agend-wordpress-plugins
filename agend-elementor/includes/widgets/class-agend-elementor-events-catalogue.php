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
		return array( 'agend-elementor-events-catalogue' );
	}

	/**
	 * Returns the frontend style handle this widget depends on.
	 *
	 * @return array Style handles.
	 */
	public function get_style_depends(): array {
		return array( 'agend-elementor-events-catalogue' );
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
		// Heading section.
		$this->start_controls_section(
			'section_heading',
			array(
				'label' => __( 'Heading', 'agend-elementor' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'show_heading',
			array(
				'label'        => __( 'Show heading block', 'agend-elementor' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'heading_text',
			array(
				'label'     => __( 'Heading', 'agend-elementor' ),
				'type'      => \Elementor\Controls_Manager::TEXT,
				'default'   => __( 'Upcoming Events', 'agend-elementor' ),
				'condition' => array( 'show_heading' => 'yes' ),
			)
		);

		$this->add_control(
			'subheading_text',
			array(
				'label'     => __( 'Subheading', 'agend-elementor' ),
				'type'      => \Elementor\Controls_Manager::TEXTAREA,
				'default'   => __( 'Conferences, workshops, and networking events across Australia.', 'agend-elementor' ),
				'condition' => array( 'show_heading' => 'yes' ),
			)
		);

		$this->end_controls_section();

		// Layout section.
		$this->start_controls_section(
			'section_layout',
			array(
				'label' => __( 'Layout', 'agend-elementor' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'columns_desktop',
			array(
				'label'   => __( 'Columns (Desktop)', 'agend-elementor' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => array(
					'2' => '2',
					'3' => '3',
					'4' => '4',
				),
				'default' => '3',
			)
		);

		$this->add_control(
			'columns_tablet',
			array(
				'label'   => __( 'Columns (Tablet)', 'agend-elementor' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => array(
					'1' => '1',
					'2' => '2',
				),
				'default' => '2',
			)
		);

		$this->add_control(
			'columns_mobile',
			array(
				'label'   => __( 'Columns (Mobile)', 'agend-elementor' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => array(
					'1' => '1',
					'2' => '2',
				),
				'default' => '1',
			)
		);

		$this->add_control(
			'card_radius',
			array(
				'label'   => __( 'Card Corner Radius (px)', 'agend-elementor' ),
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'default' => 10,
				'min'     => 0,
				'max'     => 48,
			)
		);

		$this->end_controls_section();

		// Card fields section.
		$this->start_controls_section(
			'section_card_fields',
			array(
				'label' => __( 'Card Fields', 'agend-elementor' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'show_image',
			array(
				'label'   => __( 'Show event image', 'agend-elementor' ),
				'type'    => \Elementor\Controls_Manager::SWITCHER,
				'default' => 'yes',
			)
		);

		$this->add_control(
			'show_date_badge',
			array(
				'label'   => __( 'Show date badge', 'agend-elementor' ),
				'type'    => \Elementor\Controls_Manager::SWITCHER,
				'default' => 'yes',
			)
		);

		$this->add_control(
			'show_pills',
			array(
				'label'   => __( 'Show category / type pills', 'agend-elementor' ),
				'type'    => \Elementor\Controls_Manager::SWITCHER,
				'default' => 'yes',
			)
		);

		$this->add_control(
			'show_description',
			array(
				'label'   => __( 'Show description excerpt', 'agend-elementor' ),
				'type'    => \Elementor\Controls_Manager::SWITCHER,
				'default' => 'yes',
			)
		);

		$this->add_control(
			'excerpt_length',
			array(
				'label'     => __( 'Excerpt length (characters)', 'agend-elementor' ),
				'type'      => \Elementor\Controls_Manager::NUMBER,
				'default'   => 110,
				'min'       => 20,
				'max'       => 400,
				'condition' => array( 'show_description' => 'yes' ),
			)
		);

		$this->add_control(
			'show_pricing',
			array(
				'label'   => __( 'Show Member / Non-Member pricing', 'agend-elementor' ),
				'type'    => \Elementor\Controls_Manager::SWITCHER,
				'default' => 'yes',
			)
		);

		$this->end_controls_section();

		// Visitor filter bar section.
		$this->start_controls_section(
			'section_filters',
			array(
				'label' => __( 'Visitor Filter Bar', 'agend-elementor' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		// Search.
		$this->add_control(
			'heading_filter_search',
			array(
				'label' => __( 'Search', 'agend-elementor' ),
				'type'  => \Elementor\Controls_Manager::HEADING,
			)
		);

		$this->add_control(
			'show_search',
			array(
				'label'   => __( 'Show search box', 'agend-elementor' ),
				'type'    => \Elementor\Controls_Manager::SWITCHER,
				'default' => 'yes',
			)
		);

		// Category.
		$this->add_control(
			'heading_filter_category',
			array(
				'label'     => __( 'Category', 'agend-elementor' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'show_category_filter',
			array(
				'label'   => __( 'Show category filter', 'agend-elementor' ),
				'type'    => \Elementor\Controls_Manager::SWITCHER,
				'default' => 'yes',
			)
		);

		$this->add_control(
			'multi_category_filter',
			array(
				'label'     => __( 'Allow multiple categories', 'agend-elementor' ),
				'type'      => \Elementor\Controls_Manager::SWITCHER,
				'default'   => 'no',
				'condition' => array( 'show_category_filter' => 'yes' ),
			)
		);

		$this->add_control(
			'category_match_mode',
			array(
				'label'     => __( 'Multiple categories match', 'agend-elementor' ),
				'type'      => \Elementor\Controls_Manager::SELECT,
				'options'   => array(
					'any' => __( 'Any (in any selected category)', 'agend-elementor' ),
					'all' => __( 'All (in every selected category)', 'agend-elementor' ),
				),
				'default'   => 'any',
				'condition' => array(
					'show_category_filter'  => 'yes',
					'multi_category_filter' => 'yes',
				),
			)
		);

		// Type.
		$this->add_control(
			'heading_filter_type',
			array(
				'label'     => __( 'Type', 'agend-elementor' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'show_type_filter',
			array(
				'label'   => __( 'Show type filter', 'agend-elementor' ),
				'type'    => \Elementor\Controls_Manager::SWITCHER,
				'default' => 'yes',
			)
		);

		$this->add_control(
			'multi_type_filter',
			array(
				'label'     => __( 'Allow multiple types', 'agend-elementor' ),
				'type'      => \Elementor\Controls_Manager::SWITCHER,
				'default'   => 'no',
				'condition' => array( 'show_type_filter' => 'yes' ),
			)
		);

		// City.
		$this->add_control(
			'heading_filter_city',
			array(
				'label'     => __( 'City', 'agend-elementor' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'show_city_filter',
			array(
				'label'   => __( 'Show city filter', 'agend-elementor' ),
				'type'    => \Elementor\Controls_Manager::SWITCHER,
				'default' => 'yes',
			)
		);

		$this->add_control(
			'multi_city_filter',
			array(
				'label'     => __( 'Allow multiple cities', 'agend-elementor' ),
				'type'      => \Elementor\Controls_Manager::SWITCHER,
				'default'   => 'no',
				'condition' => array( 'show_city_filter' => 'yes' ),
			)
		);

		// Date.
		$this->add_control(
			'heading_filter_date',
			array(
				'label'     => __( 'Date', 'agend-elementor' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'show_date_filter',
			array(
				'label'       => __( 'Show date filter', 'agend-elementor' ),
				'type'        => \Elementor\Controls_Manager::SWITCHER,
				'default'     => 'yes',
				'description' => __( 'A visitor dropdown to filter by event start date (starting after / before).', 'agend-elementor' ),
			)
		);

		$this->end_controls_section();

		// Exclusions section (editor-scoped: applied to every fetch this
		// widget instance makes, so two instances on different pages can show
		// different slices of the catalogue).
		$this->start_controls_section(
			'section_exclusions',
			array(
				'label' => __( 'Exclusions', 'agend-elementor' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'event_timeframe',
			array(
				'label'       => __( 'Events to show', 'agend-elementor' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'options'     => array(
					'upcoming' => __( 'Upcoming', 'agend-elementor' ),
					'past'     => __( 'Past', 'agend-elementor' ),
					'all'      => __( 'All', 'agend-elementor' ),
				),
				'default'     => 'upcoming',
				'description' => __( 'Which events this widget lists, by time. Defaults to Upcoming.', 'agend-elementor' ),
			)
		);

		$this->add_control(
			'exclusions_note',
			array(
				'type' => \Elementor\Controls_Manager::RAW_HTML,
				'raw'  => __( 'Excluded items never appear in this widget, and excluded categories are hidden from the visitor category filter.', 'agend-elementor' ),
			)
		);

		$this->add_control(
			'exclude_categories',
			array(
				'label'       => __( 'Exclude categories', 'agend-elementor' ),
				'type'        => \Elementor\Controls_Manager::SELECT2,
				'multiple'    => true,
				'label_block' => true,
				'options'     => $this->category_options(),
			)
		);

		$this->add_control(
			'exclude_category_match_mode',
			array(
				'label'   => __( 'Category exclusion match', 'agend-elementor' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => array(
					'any' => __( 'Any (exclude if in any selected)', 'agend-elementor' ),
					'all' => __( 'All (exclude only if in every selected)', 'agend-elementor' ),
				),
				'default' => 'any',
			)
		);

		$this->add_control(
			'exclude_venue_types',
			array(
				'label'       => __( 'Exclude event types', 'agend-elementor' ),
				'type'        => \Elementor\Controls_Manager::SELECT2,
				'multiple'    => true,
				'label_block' => true,
				'options'     => array(
					'physical' => __( 'In-Person', 'agend-elementor' ),
					'virtual'  => __( 'Online', 'agend-elementor' ),
					'hybrid'   => __( 'Hybrid', 'agend-elementor' ),
				),
			)
		);

		$this->add_control(
			'exclude_cities',
			array(
				'label'       => __( 'Exclude cities', 'agend-elementor' ),
				'type'        => \Elementor\Controls_Manager::SELECT2,
				'multiple'    => true,
				'label_block' => true,
				'options'     => $this->city_options(),
			)
		);

		$this->end_controls_section();

		// Pagination section.
		$this->start_controls_section(
			'section_pagination',
			array(
				'label' => __( 'Pagination', 'agend-elementor' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'pagination_style',
			array(
				'label'   => __( 'Pagination style', 'agend-elementor' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => array(
					'numbered'  => __( 'Numbered pages', 'agend-elementor' ),
					'load_more' => __( 'Load more button', 'agend-elementor' ),
					'none'      => __( 'None (show all fetched)', 'agend-elementor' ),
				),
				'default' => 'numbered',
			)
		);

		$this->add_control(
			'per_page',
			array(
				'label'   => __( 'Events per page', 'agend-elementor' ),
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'default' => 9,
				'min'     => 1,
				'max'     => 100,
			)
		);

		$this->end_controls_section();
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
	 * Builds the category exclusion options from the live catalogue.
	 *
	 * Uses the cached core wrapper, so editor loads do not hammer the gateway.
	 * Returns an empty list when the API is unreachable — the control still
	 * renders, it just has nothing to offer.
	 *
	 * @return array Options keyed by category id.
	 */
	private function category_options(): array {
		if ( ! function_exists( 'agend_apps_events_get_categories' ) ) {
			return array();
		}

		$response = agend_apps_events_get_categories();

		if ( is_wp_error( $response ) || empty( $response['data'] ) || ! is_array( $response['data'] ) ) {
			return array();
		}

		$options = array();

		foreach ( $response['data'] as $category ) {
			if ( ! empty( $category['id'] ) && ! empty( $category['name'] ) ) {
				$options[ (string) $category['id'] ] = (string) $category['name'];
			}
		}

		return $options;
	}

	/**
	 * Builds the city exclusion options from the venues catalogue.
	 *
	 * @return array Options keyed by city name (the gateway filters on the
	 *               city string, not a venue id).
	 */
	private function city_options(): array {
		if ( ! function_exists( 'agend_apps_events_get_venues' ) ) {
			return array();
		}

		$response = agend_apps_events_get_venues( array( 'limit' => 100 ) );

		if ( is_wp_error( $response ) || empty( $response['data'] ) || ! is_array( $response['data'] ) ) {
			return array();
		}

		$options = array();

		foreach ( $response['data'] as $venue ) {
			$city = '';
			if ( ! empty( $venue['city'] ) ) {
				$city = (string) $venue['city'];
			} elseif ( ! empty( $venue['venue_city'] ) ) {
				$city = (string) $venue['venue_city'];
			}
			if ( '' !== $city ) {
				$options[ $city ] = $city;
			}
		}

		return $options;
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
		$settings = $this->get_settings_for_display();
		$config   = $this->build_config( $settings );

		// US-1.2: path-based detail routing. The `event` rewrite endpoint
		// (registered in class-agend-elementor-routing.php) exposes the slug on
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

		$style = sprintf(
			'--agend-ev-heading:%1$s;--agend-ev-body:%2$s;--agend-ev-accent:%3$s;--agend-ev-button:%4$s;--agend-ev-button-text:%5$s;--agend-ev-card-radius:%6$dpx;',
			esc_attr( $config['colours']['heading'] ),
			esc_attr( $config['colours']['body'] ),
			esc_attr( $config['colours']['accent'] ),
			esc_attr( $config['colours']['button'] ),
			esc_attr( $config['colours']['buttonText'] ),
			(int) $config['layout']['cardRadius']
		);
		// One complete grid row of skeleton placeholders as the initial state
		// (3 when the layout is a single column), so no plain "Loading…" text
		// flashes before the script takes over.
		$columns   = max( 1, (int) $config['layout']['desktop'] );
		$skeletons = ( 1 === $columns ) ? 3 : $columns;
		?>
		<div class="agend-events-catalogue" style="<?php echo esc_attr( $style ); ?>" data-agend-events-config="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">
			<span class="agend-visually-hidden" role="status"><?php esc_html_e( 'Loading events…', 'agend-elementor' ); ?></span>
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
}
