<?php
/**
 * Elementor Directory Catalogue widget for Agend Elementor Widgets.
 *
 * @package Agend_Elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders a searchable, filterable grid or list of published Agend business
 * listings, plus a client-side single-listing detail view with reviews.
 *
 * The widget outputs a configured container; the frontend script
 * (assets/js/directory-catalogue.js) fetches listings from the Agend Apps Core
 * REST proxy (`/wp-json/agend-apps/v1/directory/search`) and renders the
 * catalogue and detail client-side. This is the catalogue, detail, and reviews
 * surface of SPEC-INFRA-20260717 (Epics 2 to 4). Listings are public; there is
 * no auth, payment, or enrolment. The listing email is never rendered.
 */
class Agend_Elementor_Directory_Catalogue extends \Elementor\Widget_Base {

	/**
	 * Returns the widget name (unique identifier).
	 *
	 * @return string Widget name.
	 */
	public function get_name(): string {
		return 'agend-directory-catalogue';
	}

	/**
	 * Returns the widget display title.
	 *
	 * @return string Widget title.
	 */
	public function get_title(): string {
		return __( 'Agend Directory', 'agend-elementor' );
	}

	/**
	 * Returns the Elementor icon class for the widget.
	 *
	 * @return string Icon class.
	 */
	public function get_icon(): string {
		return 'eicon-posts-grid';
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
		return array( 'agend-elementor-directory-catalogue' );
	}

	/**
	 * Returns the frontend style handle this widget depends on.
	 *
	 * @return array Style handles.
	 */
	public function get_style_depends(): array {
		return array( 'agend-elementor-directory-catalogue' );
	}

	/**
	 * Registers all Elementor controls for this widget.
	 */
	protected function register_controls(): void {
		$this->register_content_controls();
		$this->register_style_controls();
	}

	/**
	 * Registers the Content tab controls (US-5.1).
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
				'label'   => __( 'Show heading block', 'agend-elementor' ),
				'type'    => \Elementor\Controls_Manager::SWITCHER,
				'default' => 'yes',
			)
		);

		$this->add_control(
			'heading_text',
			array(
				'label'     => __( 'Heading', 'agend-elementor' ),
				'type'      => \Elementor\Controls_Manager::TEXT,
				'default'   => __( 'Business Directory', 'agend-elementor' ),
				'condition' => array( 'show_heading' => 'yes' ),
			)
		);

		$this->add_control(
			'subheading_text',
			array(
				'label'     => __( 'Subheading', 'agend-elementor' ),
				'type'      => \Elementor\Controls_Manager::TEXTAREA,
				'default'   => __( 'Find member businesses across our community.', 'agend-elementor' ),
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
			'layout_style',
			array(
				'label'   => __( 'Layout style', 'agend-elementor' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => array(
					'grid' => __( 'Grid', 'agend-elementor' ),
					'list' => __( 'List', 'agend-elementor' ),
				),
				'default' => 'grid',
			)
		);

		$this->add_control(
			'columns_desktop',
			array(
				'label'     => __( 'Columns (Desktop)', 'agend-elementor' ),
				'type'      => \Elementor\Controls_Manager::SELECT,
				'options'   => array(
					'2' => '2',
					'3' => '3',
					'4' => '4',
				),
				'default'   => '3',
				'condition' => array( 'layout_style' => 'grid' ),
			)
		);

		$this->add_control(
			'columns_tablet',
			array(
				'label'     => __( 'Columns (Tablet)', 'agend-elementor' ),
				'type'      => \Elementor\Controls_Manager::SELECT,
				'options'   => array(
					'1' => '1',
					'2' => '2',
				),
				'default'   => '2',
				'condition' => array( 'layout_style' => 'grid' ),
			)
		);

		$this->add_control(
			'columns_mobile',
			array(
				'label'     => __( 'Columns (Mobile)', 'agend-elementor' ),
				'type'      => \Elementor\Controls_Manager::SELECT,
				'options'   => array(
					'1' => '1',
					'2' => '2',
				),
				'default'   => '1',
				'condition' => array( 'layout_style' => 'grid' ),
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
			'show_logo',
			array(
				'label'   => __( 'Show logo', 'agend-elementor' ),
				'type'    => \Elementor\Controls_Manager::SWITCHER,
				'default' => 'yes',
			)
		);

		$this->add_control(
			'show_rating',
			array(
				'label'   => __( 'Show rating summary', 'agend-elementor' ),
				'type'    => \Elementor\Controls_Manager::SWITCHER,
				'default' => 'yes',
			)
		);

		$this->add_control(
			'show_category',
			array(
				'label'   => __( 'Show primary category', 'agend-elementor' ),
				'type'    => \Elementor\Controls_Manager::SWITCHER,
				'default' => 'yes',
			)
		);

		$this->add_control(
			'show_location',
			array(
				'label'   => __( 'Show location (city, state)', 'agend-elementor' ),
				'type'    => \Elementor\Controls_Manager::SWITCHER,
				'default' => 'yes',
			)
		);

		$this->add_control(
			'show_badges',
			array(
				'label'   => __( 'Show badges', 'agend-elementor' ),
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

		// Rating.
		$this->add_control(
			'heading_filter_rating',
			array(
				'label'     => __( 'Rating', 'agend-elementor' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'show_rating_filter',
			array(
				'label'   => __( 'Show minimum-rating filter', 'agend-elementor' ),
				'type'    => \Elementor\Controls_Manager::SWITCHER,
				'default' => 'yes',
			)
		);

		$this->end_controls_section();

		// Exclusions section (editor-scoped: applied to every fetch this widget
		// instance makes, so two instances on different pages can show different
		// slices of the catalogue). US-2.3.
		$this->start_controls_section(
			'section_exclusions',
			array(
				'label' => __( 'Exclusions', 'agend-elementor' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'featured_only',
			array(
				'label'       => __( 'Featured listings only', 'agend-elementor' ),
				'type'        => \Elementor\Controls_Manager::SWITCHER,
				'default'     => 'no',
				'description' => __( 'Limit this widget to featured listings.', 'agend-elementor' ),
			)
		);

		$this->add_control(
			'exclusions_note',
			array(
				'type' => \Elementor\Controls_Manager::RAW_HTML,
				'raw'  => __( 'Excluded categories never appear in this widget, and are hidden from the visitor category filter.', 'agend-elementor' ),
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

		$this->end_controls_section();

		// Detail section (US-3.1 / US-4.1).
		$this->start_controls_section(
			'section_detail',
			array(
				'label' => __( 'Detail View', 'agend-elementor' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'show_reviews',
			array(
				'label'       => __( 'Show reviews', 'agend-elementor' ),
				'type'        => \Elementor\Controls_Manager::SWITCHER,
				'default'     => 'yes',
				'description' => __( 'Show the rating summary and approved reviews on the listing detail view.', 'agend-elementor' ),
			)
		);

		$this->add_control(
			'show_review_form',
			array(
				'label'       => __( 'Show review submission form', 'agend-elementor' ),
				'type'        => \Elementor\Controls_Manager::SWITCHER,
				'default'     => 'yes',
				'description' => __( 'Let visitors submit a review. New reviews are held for moderation before publishing.', 'agend-elementor' ),
				'condition'   => array( 'show_reviews' => 'yes' ),
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
				'label'   => __( 'Listings per page', 'agend-elementor' ),
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'default' => 12,
				'min'     => 1,
				'max'     => 100,
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Registers the Style tab controls (US-5.2, colour subset).
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
				'description' => __( 'Drives rating stars, active filter state, and the featured ribbon.', 'agend-elementor' ),
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
	 * Returns an empty list when the API is unreachable; the control still
	 * renders, it just has nothing to offer.
	 *
	 * @return array Options keyed by category id.
	 */
	private function category_options(): array {
		if ( ! function_exists( 'agend_apps_directory_get_categories' ) ) {
			return array();
		}

		$response = agend_apps_directory_get_categories();

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
				'categories' => $this->string_list( $s['exclude_categories'] ?? array() ),
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
	 * Renders the widget container on the frontend.
	 *
	 * The catalogue itself is rendered client-side from the config below by
	 * assets/js/directory-catalogue.js.
	 */
	protected function render(): void {
		$settings = $this->get_settings_for_display();
		$config   = $this->build_config( $settings );

		// US-3.2: path-based detail routing. The directory detail rule
		// (registered in class-agend-elementor-routing.php) exposes the slug on
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
		$config['ssrDetail']   = function_exists( 'agend_elementor_ssr_detail_enabled' )
			&& agend_elementor_ssr_detail_enabled();
		// Member LMS achievements ("Badges & Credentials") are opt-in and require
		// the directory.achievements.browse scope on the account's API key.
		$config['showAchievements'] = function_exists( 'agend_elementor_show_achievements_enabled' )
			&& agend_elementor_show_achievements_enabled();

		$style = sprintf(
			'--agend-dir-heading:%1$s;--agend-dir-body:%2$s;--agend-dir-accent:%3$s;--agend-dir-button:%4$s;--agend-dir-button-text:%5$s;--agend-dir-card-radius:%6$dpx;',
			esc_attr( $config['colours']['heading'] ),
			esc_attr( $config['colours']['body'] ),
			esc_attr( $config['colours']['accent'] ),
			esc_attr( $config['colours']['button'] ),
			esc_attr( $config['colours']['buttonText'] ),
			(int) $config['layout']['cardRadius']
		);
		// One complete grid row of skeleton placeholders as the initial state
		// (3 when the layout is a single column or list), so no plain "Loading…"
		// text flashes before the script takes over.
		$columns   = ( 'list' === $config['layout']['style'] ) ? 1 : max( 1, (int) $config['layout']['desktop'] );
		$skeletons = ( 1 === $columns ) ? 3 : $columns;
		?>
		<div class="agend-directory-catalogue" style="<?php echo esc_attr( $style ); ?>" data-agend-directory-config="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">
			<span class="agend-visually-hidden" role="status"><?php esc_html_e( 'Loading listings…', 'agend-elementor' ); ?></span>
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
	}
}
