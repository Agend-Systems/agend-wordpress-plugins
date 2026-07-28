<?php
/**
 * Elementor Courses (Learning Hub) widget for Agend Elementor Widgets.
 *
 * @package Agend_Elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders a searchable, filterable grid of published Agend LMS courses, with a
 * connected client-side detail view. Catalogue + detail surfaces of
 * SPEC-INFRA-LMS-001 (US-LMS.1/2/4/6/8/9). Enrolment-state sidebars (US-LMS.5)
 * and the delivery-mode facet depend on later platform work and are not built
 * here; the detail shows the anonymous pricing/enrol shell.
 */
class Agend_Elementor_Courses_Catalogue extends \Elementor\Widget_Base {

	/**
	 * Returns the widget name (unique identifier).
	 *
	 * @return string Widget name.
	 */
	public function get_name(): string {
		return 'agend-courses-catalogue';
	}

	/**
	 * Returns the widget display title.
	 *
	 * @return string Widget title.
	 */
	public function get_title(): string {
		return __( 'Agend Courses', 'agend-elementor' );
	}

	/**
	 * Returns the Elementor icon class for the widget.
	 *
	 * @return string Icon class.
	 */
	public function get_icon(): string {
		return 'eicon-graduation-cap';
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
		return array( 'agend-elementor-courses-catalogue' );
	}

	/**
	 * Returns the frontend style handle this widget depends on.
	 *
	 * @return array Style handles.
	 */
	public function get_style_depends(): array {
		return array( 'agend-elementor-courses-catalogue' );
	}

	/**
	 * Registers all Elementor controls for this widget.
	 */
	protected function register_controls(): void {
		$this->register_content_controls();
		$this->register_style_controls();
	}

	/**
	 * Registers the Content tab controls (US-LMS.8).
	 */
	private function register_content_controls(): void {
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
				'default'   => __( 'Learning Hub', 'agend-elementor' ),
				'condition' => array( 'show_heading' => 'yes' ),
			)
		);

		$this->add_control(
			'subheading_text',
			array(
				'label'     => __( 'Subheading', 'agend-elementor' ),
				'type'      => \Elementor\Controls_Manager::TEXTAREA,
				'default'   => __( 'Self-paced courses and live workshops to level up your skills.', 'agend-elementor' ),
				'condition' => array( 'show_heading' => 'yes' ),
			)
		);

		$this->end_controls_section();

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

		$this->start_controls_section(
			'section_card_fields',
			array(
				'label' => __( 'Card Fields', 'agend-elementor' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		foreach ( array(
			'show_image'         => __( 'Show course image', 'agend-elementor' ),
			'show_difficulty'    => __( 'Show difficulty badge', 'agend-elementor' ),
			'show_delivery_mode' => __( 'Show delivery mode pill', 'agend-elementor' ),
			'show_category'      => __( 'Show category label', 'agend-elementor' ),
			'show_description'   => __( 'Show description excerpt', 'agend-elementor' ),
			'show_meta'          => __( 'Show duration and module count', 'agend-elementor' ),
			'show_price'         => __( 'Show price tag', 'agend-elementor' ),
		) as $key => $label ) {
			$this->add_control(
				$key,
				array(
					'label'   => $label,
					'type'    => \Elementor\Controls_Manager::SWITCHER,
					'default' => 'yes',
				)
			);
		}

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

		$this->start_controls_section(
			'section_filters',
			array(
				'label' => __( 'Visitor Filter Bar', 'agend-elementor' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
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

		$this->add_control(
			'show_category_filter',
			array(
				'label'   => __( 'Show category filter', 'agend-elementor' ),
				'type'    => \Elementor\Controls_Manager::SWITCHER,
				'default' => 'yes',
			)
		);

		$this->add_control(
			'show_difficulty_filter',
			array(
				'label'   => __( 'Show difficulty filter', 'agend-elementor' ),
				'type'    => \Elementor\Controls_Manager::SWITCHER,
				'default' => 'yes',
			)
		);

		$this->add_control(
			'show_delivery_filter',
			array(
				'label'   => __( 'Show delivery mode filter', 'agend-elementor' ),
				'type'    => \Elementor\Controls_Manager::SWITCHER,
				'default' => 'yes',
			)
		);

		$this->end_controls_section();

		// Exclusions section (editor-scoped: applied to every fetch this
		// widget instance makes).
		$this->start_controls_section(
			'section_exclusions',
			array(
				'label' => __( 'Exclusions', 'agend-elementor' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'exclusions_note',
			array(
				'type' => \Elementor\Controls_Manager::RAW_HTML,
				'raw'  => __( 'Excluded items never appear in this widget, and excluded values are hidden from the visitor filters.', 'agend-elementor' ),
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
			'exclude_difficulties',
			array(
				'label'       => __( 'Exclude difficulty levels', 'agend-elementor' ),
				'type'        => \Elementor\Controls_Manager::SELECT2,
				'multiple'    => true,
				'label_block' => true,
				'options'     => array(
					'beginner'     => __( 'Beginner', 'agend-elementor' ),
					'intermediate' => __( 'Intermediate', 'agend-elementor' ),
					'advanced'     => __( 'Advanced', 'agend-elementor' ),
				),
			)
		);

		$this->add_control(
			'exclude_delivery_modes',
			array(
				'label'       => __( 'Exclude delivery modes', 'agend-elementor' ),
				'type'        => \Elementor\Controls_Manager::SELECT2,
				'multiple'    => true,
				'label_block' => true,
				'options'     => array(
					'self_paced'  => __( 'Self-paced', 'agend-elementor' ),
					'live_online' => __( 'Live Online', 'agend-elementor' ),
					'in_person'   => __( 'In-Person', 'agend-elementor' ),
					'blended'     => __( 'Blended', 'agend-elementor' ),
				),
			)
		);

		$this->end_controls_section();

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
				'label'   => __( 'Courses per page', 'agend-elementor' ),
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'default' => 9,
				'min'     => 1,
				'max'     => 100,
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Registers the Style tab controls (US-LMS.9, colour subset).
	 */
	private function register_style_controls(): void {
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
				'label'   => __( 'Inherit site theme fonts', 'agend-elementor' ),
				'type'    => \Elementor\Controls_Manager::SWITCHER,
				'default' => 'yes',
			)
		);

		$this->end_controls_section();

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
				'label'   => __( 'Inherit site theme colours', 'agend-elementor' ),
				'type'    => \Elementor\Controls_Manager::SWITCHER,
				'default' => 'yes',
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
				'description' => __( 'Drives the difficulty badge, free price text, and active filter state.', 'agend-elementor' ),
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
	 * Builds the client-side config object from the widget settings.
	 *
	 * @param array $s Settings for display.
	 * @return array Config passed to the frontend script as JSON.
	 */
	/**
	 * Builds the category exclusion options from the live catalogue.
	 *
	 * Course categories are free text on the course row (no categories
	 * endpoint), so the distinct set is derived from a wide, cached list
	 * fetch. Returns an empty list when the API is unreachable.
	 *
	 * @return array Options keyed by category name.
	 */
	private function category_options(): array {
		if ( ! function_exists( 'agend_apps_lms_get_courses' ) ) {
			return array();
		}

		$response = agend_apps_lms_get_courses( array( 'limit' => 100 ) );

		if ( is_wp_error( $response ) || empty( $response['data'] ) || ! is_array( $response['data'] ) ) {
			return array();
		}

		$options = array();

		foreach ( $response['data'] as $course ) {
			if ( ! empty( $course['category'] ) ) {
				$options[ (string) $course['category'] ] = (string) $course['category'];
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

	private function build_config( array $s ): array {
		return array(
			'heading'    => array(
				'show'     => 'yes' === ( $s['show_heading'] ?? 'yes' ),
				'title'    => (string) ( $s['heading_text'] ?? '' ),
				'subtitle' => (string) ( $s['subheading_text'] ?? '' ),
			),
			'layout'     => array(
				'desktop'    => (int) ( $s['columns_desktop'] ?? 3 ),
				'tablet'     => (int) ( $s['columns_tablet'] ?? 2 ),
				'mobile'     => (int) ( $s['columns_mobile'] ?? 1 ),
				'cardRadius' => (int) ( $s['card_radius'] ?? 10 ),
			),
			'card'       => array(
				'image'         => 'yes' === ( $s['show_image'] ?? 'yes' ),
				'difficulty'    => 'yes' === ( $s['show_difficulty'] ?? 'yes' ),
				'deliveryMode'  => 'yes' === ( $s['show_delivery_mode'] ?? 'yes' ),
				'category'      => 'yes' === ( $s['show_category'] ?? 'yes' ),
				'description'   => 'yes' === ( $s['show_description'] ?? 'yes' ),
				'meta'          => 'yes' === ( $s['show_meta'] ?? 'yes' ),
				'price'         => 'yes' === ( $s['show_price'] ?? 'yes' ),
				'excerptLength' => (int) ( $s['excerpt_length'] ?? 110 ),
			),
			'filters'    => array(
				'search'       => 'yes' === ( $s['show_search'] ?? 'yes' ),
				'category'     => 'yes' === ( $s['show_category_filter'] ?? 'yes' ),
				'difficulty'   => 'yes' === ( $s['show_difficulty_filter'] ?? 'yes' ),
				'deliveryMode' => 'yes' === ( $s['show_delivery_filter'] ?? 'yes' ),
			),
			'exclusions' => array(
				'categories'    => $this->string_list( $s['exclude_categories'] ?? array() ),
				'difficulties'  => $this->string_list( $s['exclude_difficulties'] ?? array() ),
				'deliveryModes' => $this->string_list( $s['exclude_delivery_modes'] ?? array() ),
			),
			'pagination' => array(
				'style'   => (string) ( $s['pagination_style'] ?? 'numbered' ),
				'perPage' => (int) ( $s['per_page'] ?? 9 ),
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
	 */
	protected function render(): void {
		$settings = $this->get_settings_for_display();
		$config   = $this->build_config( $settings );

		// US-1.3: path-based detail routing via the `course` rewrite endpoint
		// (/{page}/course/{slug}/). The slug is injected server-side so a direct
		// load renders the detail with no catalogue flash; the base page path
		// lets the script build pretty links, and it falls back to the
		// ?agend_course= query param when pretty permalinks are off.
		$page_id                = get_queried_object_id();
		$base_path              = $page_id ? get_permalink( $page_id ) : '';
		$config['deepLink']    = sanitize_title( (string) get_query_var( 'course' ) );
		$config['prettyLinks'] = (bool) get_option( 'permalink_structure' );
		$config['basePath']    = is_string( $base_path ) ? $base_path : '';

		// Cart mode: mirror the Events catalogue so a signed-in member's enrol
		// CTA adds the course to the shop cart when the Agend Apps Shop is
		// active (SPEC-CORE-20260722 US-2.4). Runtime site state, not a widget
		// setting, so it is injected here rather than in build_config().
		$config['cartEnabled'] = agend_elementor_shop_cart_enabled();
		$config['cartPageUrl'] = agend_elementor_shop_cart_page_url();

		$style = sprintf(
			'--agend-lms-heading:%1$s;--agend-lms-body:%2$s;--agend-lms-accent:%3$s;--agend-lms-button:%4$s;--agend-lms-button-text:%5$s;--agend-lms-card-radius:%6$dpx;',
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
		<div class="agend-courses-catalogue" style="<?php echo esc_attr( $style ); ?>" data-agend-courses-config="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">
			<span class="agend-visually-hidden" role="status"><?php esc_html_e( 'Loading courses…', 'agend-elementor' ); ?></span>
			<div class="agend-lms-grid" style="--agend-lms-cols-desktop:<?php echo (int) $columns; ?>;">
				<?php for ( $i = 0; $i < $skeletons; $i++ ) : ?>
					<article class="agend-lms-card agend-lms-skeleton" aria-hidden="true">
						<div class="agend-lms-card__media"></div>
						<div class="agend-lms-card__body">
							<div class="agend-skel-line" style="width:35%"></div>
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
