<?php
/**
 * Settings for Agend Elementor Widgets.
 *
 * Registers a small options page (Settings > Agend Widgets) exposing the
 * server-rendered detail-pages toggle. Loaded unconditionally so the accessor
 * is available on the front-end `wp` hook (before Elementor/core bootstrap) and
 * in the admin.
 *
 * @package Agend_Elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Option name storing the server-rendered detail toggle ('1' or '').
 *
 * @var string
 */
const AGEND_ELEMENTOR_SSR_DETAIL_OPTION = 'agend_elementor_ssr_detail';

/**
 * Option name storing the "show member badges & credentials" toggle ('1'/'').
 *
 * @var string
 */
const AGEND_ELEMENTOR_SHOW_ACHIEVEMENTS_OPTION = 'agend_elementor_show_achievements';

/**
 * Option name storing the configured Events catalogue/detail page id (0 = unset).
 *
 * @var string
 */
const AGEND_ELEMENTOR_EVENTS_PAGE_OPTION = 'agend_elementor_events_page_id';

/**
 * Option name storing the configured Courses catalogue/detail page id (0 = unset).
 *
 * @var string
 */
const AGEND_ELEMENTOR_COURSES_PAGE_OPTION = 'agend_elementor_courses_page_id';

/**
 * Option names storing the Elementor template used for a type's detail page
 * (elementor_library post id, 0 = built-in layout).
 *
 * @var string
 */
const AGEND_ELEMENTOR_EVENT_DETAIL_TEMPLATE_OPTION  = 'agend_elementor_event_detail_template';
const AGEND_ELEMENTOR_COURSE_DETAIL_TEMPLATE_OPTION = 'agend_elementor_course_detail_template';

/**
 * Whether the Directory widget requests and renders member LMS achievements
 * ("Badges & Credentials") on the detail view.
 *
 * The detail request only sends `include=achievements` when this is on, because
 * the gateway rejects that param (403) for API keys without the
 * `directory.achievements.browse` scope. Enable it only when the connected
 * account's API key holds that scope. Filterable.
 *
 * @return bool True when member achievements are shown.
 */
function agend_elementor_show_achievements_enabled(): bool {
	$enabled = '1' === get_option( AGEND_ELEMENTOR_SHOW_ACHIEVEMENTS_OPTION, '' );

	/**
	 * Filters whether member LMS achievements are shown on the detail.
	 *
	 * @param bool $enabled Whether the toggle is on.
	 */
	return (bool) apply_filters( 'agend_elementor_show_achievements_enabled', $enabled );
}

/**
 * Whether server-rendered detail pages are enabled.
 *
 * When enabled, a catalogue widget's detail URL (/{page}/listing/{slug}/,
 * /{page}/event/{slug}/, /{page}/course/{slug}/) resolves to a virtual child
 * page of the catalogue page: the item name becomes the page title and the
 * catalogue page its parent, so any breadcrumb system natively shows
 * Home > Catalogue > Item, and the detail body is rendered server-side for SEO.
 * Filterable so a site can force it on/off in code.
 *
 * @return bool True when server-rendered detail pages are enabled.
 */
function agend_elementor_ssr_detail_enabled(): bool {
	$enabled = '1' === get_option( AGEND_ELEMENTOR_SSR_DETAIL_OPTION, '' );

	/**
	 * Filters whether server-rendered detail pages are enabled.
	 *
	 * @param bool $enabled Whether the toggle is on.
	 */
	return (bool) apply_filters( 'agend_elementor_ssr_detail_enabled', $enabled );
}

/**
 * Registers the settings, option, and admin page.
 */
function agend_elementor_settings_init(): void {
	// Registered before "Detail Pages" so it renders first: choosing the
	// catalogue pages is the setting a new site configures before anything
	// else here matters.
	add_settings_section(
		'agend_elementor_section_pages',
		__( 'Catalogue Pages', 'agend-elementor' ),
		'agend_elementor_settings_section_pages',
		'agend-elementor'
	);

	register_setting(
		'agend_elementor_settings',
		AGEND_ELEMENTOR_EVENTS_PAGE_OPTION,
		array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 0,
		)
	);

	add_settings_field(
		AGEND_ELEMENTOR_EVENTS_PAGE_OPTION,
		__( 'Events page', 'agend-elementor' ),
		'agend_elementor_settings_field_events_page',
		'agend-elementor',
		'agend_elementor_section_pages'
	);

	register_setting(
		'agend_elementor_settings',
		AGEND_ELEMENTOR_COURSES_PAGE_OPTION,
		array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 0,
		)
	);

	add_settings_field(
		AGEND_ELEMENTOR_COURSES_PAGE_OPTION,
		__( 'Courses page', 'agend-elementor' ),
		'agend_elementor_settings_field_courses_page',
		'agend-elementor',
		'agend_elementor_section_pages'
	);

	foreach ( array(
		AGEND_ELEMENTOR_EVENT_DETAIL_TEMPLATE_OPTION  => array( __( 'Event detail template', 'agend-elementor' ), 'agend_elementor_settings_field_event_detail_template' ),
		AGEND_ELEMENTOR_COURSE_DETAIL_TEMPLATE_OPTION => array( __( 'Course detail template', 'agend-elementor' ), 'agend_elementor_settings_field_course_detail_template' ),
	) as $option => $field ) {
		register_setting(
			'agend_elementor_settings',
			$option,
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 0,
			)
		);
		add_settings_field( $option, $field[0], $field[1], 'agend-elementor', 'agend_elementor_section_pages' );
	}

	register_setting(
		'agend_elementor_settings',
		AGEND_ELEMENTOR_SSR_DETAIL_OPTION,
		array(
			'type'              => 'string',
			'sanitize_callback' => 'agend_elementor_sanitize_checkbox',
			'default'           => '',
		)
	);

	add_settings_section(
		'agend_elementor_section_detail',
		__( 'Detail Pages', 'agend-elementor' ),
		'agend_elementor_settings_section_detail',
		'agend-elementor'
	);

	add_settings_field(
		AGEND_ELEMENTOR_SSR_DETAIL_OPTION,
		__( 'Server-rendered detail pages', 'agend-elementor' ),
		'agend_elementor_settings_field_ssr_detail',
		'agend-elementor',
		'agend_elementor_section_detail'
	);

	register_setting(
		'agend_elementor_settings',
		AGEND_ELEMENTOR_SHOW_ACHIEVEMENTS_OPTION,
		array(
			'type'              => 'string',
			'sanitize_callback' => 'agend_elementor_sanitize_checkbox',
			'default'           => '',
		)
	);

	add_settings_field(
		AGEND_ELEMENTOR_SHOW_ACHIEVEMENTS_OPTION,
		__( 'Show member badges & credentials', 'agend-elementor' ),
		'agend_elementor_settings_field_show_achievements',
		'agend-elementor',
		'agend_elementor_section_detail'
	);
}
add_action( 'admin_init', 'agend_elementor_settings_init' );

/**
 * Normalises a checkbox option to '1' or ''.
 *
 * @param mixed $value Raw submitted value.
 * @return string '1' when checked, '' otherwise.
 */
function agend_elementor_sanitize_checkbox( $value ): string {
	return ! empty( $value ) ? '1' : '';
}

/**
 * Registers the options page under the Settings menu.
 */
function agend_elementor_settings_menu(): void {
	add_options_page(
		__( 'Agend Widgets', 'agend-elementor' ),
		__( 'Agend Widgets', 'agend-elementor' ),
		'manage_options',
		'agend-elementor',
		'agend_elementor_settings_page'
	);
}
add_action( 'admin_menu', 'agend_elementor_settings_menu' );

/**
 * Renders the Catalogue Pages settings section description.
 */
function agend_elementor_settings_section_pages(): void {
	echo '<p>';
	esc_html_e(
		'The page that shows the full catalogue and item detail pages. A widget placed on any other page links visitors here instead of taking over its own page. Leave unset to keep current behaviour.',
		'agend-elementor'
	);
	echo '</p>';
}

/**
 * Renders a widget-presence advisory when the selected page's Elementor
 * content does not appear to contain the expected catalogue widget.
 *
 * `_elementor_data` is the raw JSON Elementor stores for the page; matching
 * the widget type string directly is a cheap heuristic (no JSON parse) and is
 * advisory only, so a false negative (e.g. the widget nested unusually) never
 * blocks saving the setting.
 *
 * @param int    $page_id     The selected page id (0 = none selected).
 * @param string $widget_type The expected widgetType value, e.g. 'agend-events-catalogue'.
 * @param string $message     The advisory message to show when the widget is not found.
 */
function agend_elementor_settings_widget_advisory( int $page_id, string $widget_type, string $message ): void {
	if ( 0 === $page_id ) {
		return;
	}

	$data = get_post_meta( $page_id, '_elementor_data', true );
	if ( false !== strpos( (string) $data, '"widgetType":"' . $widget_type . '"' ) ) {
		return;
	}

	echo '<p class="description notice-warning">' . esc_html( $message ) . '</p>';
}

/**
 * Renders the Events page picker.
 */
function agend_elementor_settings_field_events_page(): void {
	$selected = absint( get_option( AGEND_ELEMENTOR_EVENTS_PAGE_OPTION, 0 ) );
	wp_dropdown_pages(
		array(
			'name'              => AGEND_ELEMENTOR_EVENTS_PAGE_OPTION,
			'show_option_none'  => __( 'Select a page', 'agend-elementor' ),
			'option_none_value' => '0',
			'selected'          => $selected,
		)
	);
	agend_elementor_settings_widget_advisory(
		$selected,
		'agend-events-catalogue',
		__( 'This page does not appear to contain the Events Catalogue widget.', 'agend-elementor' )
	);
}

/**
 * Renders the Courses page picker.
 */
function agend_elementor_settings_field_courses_page(): void {
	$selected = absint( get_option( AGEND_ELEMENTOR_COURSES_PAGE_OPTION, 0 ) );
	wp_dropdown_pages(
		array(
			'name'              => AGEND_ELEMENTOR_COURSES_PAGE_OPTION,
			'show_option_none'  => __( 'Select a page', 'agend-elementor' ),
			'option_none_value' => '0',
			'selected'          => $selected,
		)
	);
	agend_elementor_settings_widget_advisory(
		$selected,
		'agend-courses-catalogue',
		__( 'This page does not appear to contain the Courses Catalogue widget.', 'agend-elementor' )
	);
}

/**
 * Renders a detail template picker for a type.
 *
 * Disabled until the type's dedicated page is chosen: the template renders
 * the detail on that page, so without one it has nowhere to show.
 *
 * @param string $option      The template option name.
 * @param string $page_option The dedicated page option name.
 */
function agend_elementor_settings_template_select( string $option, string $page_option ): void {
	$selected = absint( get_option( $option, 0 ) );
	$has_page = absint( get_option( $page_option, 0 ) ) > 0;
	$options  = class_exists( 'Agend_Elementor_Templates' )
		? Agend_Elementor_Templates::options( __( 'Built-in detail layout', 'agend-elementor' ) )
		: array( '' => __( 'Built-in detail layout', 'agend-elementor' ) );

	echo '<select name="' . esc_attr( $option ) . '"' . ( $has_page ? '' : ' disabled' ) . '>';
	foreach ( $options as $value => $label ) {
		echo '<option value="' . esc_attr( (string) $value ) . '"' . selected( $selected, (int) $value, false ) . '>' . esc_html( $label ) . '</option>';
	}
	echo '</select>';
	echo '<p class="description">';
	if ( $has_page ) {
		esc_html_e( 'A saved Elementor template built from the Agend Field, Image, Link and Content Block widgets. Rendered server-side on the dedicated page for every item.', 'agend-elementor' );
	} else {
		esc_html_e( 'Choose the dedicated page above first.', 'agend-elementor' );
	}
	echo '</p>';
}

/**
 * Renders the Event detail template picker.
 */
function agend_elementor_settings_field_event_detail_template(): void {
	agend_elementor_settings_template_select( AGEND_ELEMENTOR_EVENT_DETAIL_TEMPLATE_OPTION, AGEND_ELEMENTOR_EVENTS_PAGE_OPTION );
}

/**
 * Renders the Course detail template picker.
 */
function agend_elementor_settings_field_course_detail_template(): void {
	agend_elementor_settings_template_select( AGEND_ELEMENTOR_COURSE_DETAIL_TEMPLATE_OPTION, AGEND_ELEMENTOR_COURSES_PAGE_OPTION );
}

/**
 * Marks the templates in use as detail layouts in the Saved Templates list,
 * so nobody deletes the live detail layout by accident.
 *
 * @param array   $states Post states.
 * @param WP_Post $post   The list row's post.
 * @return array
 */
function agend_elementor_template_post_states( array $states, $post ): array {
	if ( ! ( $post instanceof WP_Post ) || 'elementor_library' !== $post->post_type ) {
		return $states;
	}
	if ( $post->ID === absint( get_option( AGEND_ELEMENTOR_EVENT_DETAIL_TEMPLATE_OPTION, 0 ) ) ) {
		$states['agend_event_detail'] = __( 'Agend Event detail template', 'agend-elementor' );
	}
	if ( $post->ID === absint( get_option( AGEND_ELEMENTOR_COURSE_DETAIL_TEMPLATE_OPTION, 0 ) ) ) {
		$states['agend_course_detail'] = __( 'Agend Course detail template', 'agend-elementor' );
	}
	return $states;
}
add_filter( 'display_post_states', 'agend_elementor_template_post_states', 10, 2 );

/**
 * Renders the Detail Pages settings section description.
 */
function agend_elementor_settings_section_detail(): void {
	echo '<p>';
	esc_html_e(
		'Controls how the Agend catalogue widgets present a single item\'s detail view.',
		'agend-elementor'
	);
	echo '</p>';
}

/**
 * Renders the server-rendered detail checkbox field.
 */
function agend_elementor_settings_field_ssr_detail(): void {
	$value = get_option( AGEND_ELEMENTOR_SSR_DETAIL_OPTION, '' );
	?>
	<label>
		<input type="checkbox" name="<?php echo esc_attr( AGEND_ELEMENTOR_SSR_DETAIL_OPTION ); ?>" value="1" <?php checked( '1', $value ); ?> />
		<?php esc_html_e( 'Render detail views as server-side child pages of the catalogue page', 'agend-elementor' ); ?>
	</label>
	<p class="description">
		<?php
		esc_html_e(
			'When on, an item detail URL becomes a virtual child page of the page holding the catalogue widget: the item name is the page title and the catalogue page is its parent, so breadcrumbs natively show Home > Catalogue > Item and the detail is rendered server-side for SEO. When off, the detail is rendered client-side in place on the catalogue page. Applies to the Directory, Events, and Courses widgets. Types with a detail template selected above are always server-rendered.',
			'agend-elementor'
		);
		?>
	</p>
	<?php
}

/**
 * Renders the show-member-achievements checkbox field.
 */
function agend_elementor_settings_field_show_achievements(): void {
	$value = get_option( AGEND_ELEMENTOR_SHOW_ACHIEVEMENTS_OPTION, '' );
	?>
	<label>
		<input type="checkbox" name="<?php echo esc_attr( AGEND_ELEMENTOR_SHOW_ACHIEVEMENTS_OPTION ); ?>" value="1" <?php checked( '1', $value ); ?> />
		<?php esc_html_e( 'Show a member\'s LMS badges and certificates on the Directory detail view', 'agend-elementor' ); ?>
	</label>
	<p class="description">
		<?php
		esc_html_e(
			'Adds a "Badges & Credentials" section listing the member\'s course badges and certificates. Requires the connected account\'s API key to hold the directory.achievements.browse scope; leave off if it does not, or listing detail pages will fail to load.',
			'agend-elementor'
		);
		?>
	</p>
	<?php
}

/**
 * Renders the options page.
 */
function agend_elementor_settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<div class="wrap">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
		<form action="options.php" method="post">
			<?php
			settings_fields( 'agend_elementor_settings' );
			do_settings_sections( 'agend-elementor' );
			submit_button();
			?>
		</form>
	</div>
	<?php
}
