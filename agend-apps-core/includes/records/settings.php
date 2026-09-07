<?php
/**
 * Settings for the Agend record layer.
 *
 * Registers a small options page (Settings > Agend Widgets) exposing the
 * server-rendered detail-pages toggle. Loaded unconditionally so the accessor
 * is available on the front-end `wp` hook (before the page-builder plugins
 * bootstrap) and in the admin.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The stored option names keep the pre-rename `agend_elementor_*` prefix on
// purpose: they are live site data, and renaming them would strand every
// existing install's configuration under a key nothing reads any more.

/**
 * Option name storing the server-rendered detail toggle ('1' or '').
 *
 * @var string
 */
const AGEND_APPS_RECORDS_SSR_DETAIL_OPTION = 'agend_elementor_ssr_detail';

/**
 * Option name storing the "show member badges & credentials" toggle ('1'/'').
 *
 * @var string
 */
const AGEND_APPS_RECORDS_SHOW_ACHIEVEMENTS_OPTION = 'agend_elementor_show_achievements';

/**
 * Option name storing the configured Events catalogue/detail page id (0 = unset).
 *
 * @var string
 */
const AGEND_APPS_RECORDS_EVENTS_PAGE_OPTION = 'agend_elementor_events_page_id';

/**
 * Option name storing the configured Courses catalogue/detail page id (0 = unset).
 *
 * @var string
 */
const AGEND_APPS_RECORDS_COURSES_PAGE_OPTION = 'agend_elementor_courses_page_id';

/**
 * Option name storing the configured Directory catalogue/detail page id (0 = unset).
 *
 * @var string
 */
const AGEND_APPS_RECORDS_DIRECTORY_PAGE_OPTION = 'agend_elementor_directory_page_id';

/**
 * Option names storing the saved template used for a type's detail page
 * (template post id, 0 = built-in layout).
 *
 * @var string
 */
const AGEND_APPS_RECORDS_EVENT_DETAIL_TEMPLATE_OPTION  = 'agend_elementor_event_detail_template';
const AGEND_APPS_RECORDS_COURSE_DETAIL_TEMPLATE_OPTION  = 'agend_elementor_course_detail_template';
const AGEND_APPS_RECORDS_LISTING_DETAIL_TEMPLATE_OPTION = 'agend_elementor_listing_detail_template';

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
function agend_apps_records_show_achievements_enabled(): bool {
	$enabled = '1' === get_option( AGEND_APPS_RECORDS_SHOW_ACHIEVEMENTS_OPTION, '' );

	/**
	 * Filters whether member LMS achievements are shown on the detail.
	 *
	 * @param bool $enabled Whether the toggle is on.
	 */
	$enabled = (bool) apply_filters( 'agend_apps_records_show_achievements_enabled', $enabled );

	// A site's existing add_filter() on the pre-rename hook name still applies
	// for one release.
	return (bool) apply_filters_deprecated( 'agend_elementor_show_achievements_enabled', array( $enabled ), '1.8.0', 'agend_apps_records_show_achievements_enabled' );
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
function agend_apps_records_ssr_detail_enabled(): bool {
	$enabled = '1' === get_option( AGEND_APPS_RECORDS_SSR_DETAIL_OPTION, '' );

	/**
	 * Filters whether server-rendered detail pages are enabled.
	 *
	 * @param bool $enabled Whether the toggle is on.
	 */
	$enabled = (bool) apply_filters( 'agend_apps_records_ssr_detail_enabled', $enabled );

	// A site's existing add_filter() on the pre-rename hook name still applies
	// for one release.
	return (bool) apply_filters_deprecated( 'agend_elementor_ssr_detail_enabled', array( $enabled ), '1.8.0', 'agend_apps_records_ssr_detail_enabled' );
}

/**
 * Registers the settings, option, and admin page.
 */
function agend_apps_records_settings_init(): void {
	// Registered before "Detail Pages" so it renders first: choosing the
	// catalogue pages is the setting a new site configures before anything
	// else here matters.
	add_settings_section(
		'agend_elementor_section_pages',
		__( 'Catalogue Pages', 'agend-apps-core' ),
		'agend_apps_records_settings_section_pages',
		'agend-elementor'
	);

	register_setting(
		'agend_elementor_settings',
		AGEND_APPS_RECORDS_EVENTS_PAGE_OPTION,
		array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 0,
		)
	);

	add_settings_field(
		AGEND_APPS_RECORDS_EVENTS_PAGE_OPTION,
		__( 'Events page', 'agend-apps-core' ),
		'agend_apps_records_settings_field_events_page',
		'agend-elementor',
		'agend_elementor_section_pages'
	);

	register_setting(
		'agend_elementor_settings',
		AGEND_APPS_RECORDS_COURSES_PAGE_OPTION,
		array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 0,
		)
	);

	add_settings_field(
		AGEND_APPS_RECORDS_COURSES_PAGE_OPTION,
		__( 'Courses page', 'agend-apps-core' ),
		'agend_apps_records_settings_field_courses_page',
		'agend-elementor',
		'agend_elementor_section_pages'
	);

	register_setting(
		'agend_elementor_settings',
		AGEND_APPS_RECORDS_DIRECTORY_PAGE_OPTION,
		array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 0,
		)
	);

	add_settings_field(
		AGEND_APPS_RECORDS_DIRECTORY_PAGE_OPTION,
		__( 'Directory page', 'agend-apps-core' ),
		'agend_apps_records_settings_field_directory_page',
		'agend-elementor',
		'agend_elementor_section_pages'
	);

	foreach ( array(
		AGEND_APPS_RECORDS_EVENT_DETAIL_TEMPLATE_OPTION   => array( __( 'Event detail template', 'agend-apps-core' ), 'agend_apps_records_settings_field_event_detail_template' ),
		AGEND_APPS_RECORDS_COURSE_DETAIL_TEMPLATE_OPTION  => array( __( 'Course detail template', 'agend-apps-core' ), 'agend_apps_records_settings_field_course_detail_template' ),
		AGEND_APPS_RECORDS_LISTING_DETAIL_TEMPLATE_OPTION => array( __( 'Listing detail template', 'agend-apps-core' ), 'agend_apps_records_settings_field_listing_detail_template' ),
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
		AGEND_APPS_RECORDS_SSR_DETAIL_OPTION,
		array(
			'type'              => 'string',
			'sanitize_callback' => 'agend_apps_records_sanitize_checkbox',
			'default'           => '',
		)
	);

	add_settings_section(
		'agend_elementor_section_detail',
		__( 'Detail Pages', 'agend-apps-core' ),
		'agend_apps_records_settings_section_detail',
		'agend-elementor'
	);

	add_settings_field(
		AGEND_APPS_RECORDS_SSR_DETAIL_OPTION,
		__( 'Server-rendered detail pages', 'agend-apps-core' ),
		'agend_apps_records_settings_field_ssr_detail',
		'agend-elementor',
		'agend_elementor_section_detail'
	);

	register_setting(
		'agend_elementor_settings',
		AGEND_APPS_RECORDS_SHOW_ACHIEVEMENTS_OPTION,
		array(
			'type'              => 'string',
			'sanitize_callback' => 'agend_apps_records_sanitize_checkbox',
			'default'           => '',
		)
	);

	add_settings_field(
		AGEND_APPS_RECORDS_SHOW_ACHIEVEMENTS_OPTION,
		__( 'Show member badges & credentials', 'agend-apps-core' ),
		'agend_apps_records_settings_field_show_achievements',
		'agend-elementor',
		'agend_elementor_section_detail'
	);
}
add_action( 'admin_init', 'agend_apps_records_settings_init' );

/**
 * Normalises a checkbox option to '1' or ''.
 *
 * @param mixed $value Raw submitted value.
 * @return string '1' when checked, '' otherwise.
 */
function agend_apps_records_sanitize_checkbox( $value ): string {
	return ! empty( $value ) ? '1' : '';
}

/**
 * Registers the options page under the Settings menu.
 */
function agend_apps_records_settings_menu(): void {
	add_options_page(
		__( 'Agend Widgets', 'agend-apps-core' ),
		__( 'Agend Widgets', 'agend-apps-core' ),
		'manage_options',
		'agend-elementor',
		'agend_apps_records_settings_page'
	);
}
add_action( 'admin_menu', 'agend_apps_records_settings_menu' );

/**
 * Renders the Catalogue Pages settings section description.
 */
function agend_apps_records_settings_section_pages(): void {
	echo '<p>';
	esc_html_e(
		'The page that shows the full catalogue and item detail pages. A widget placed on any other page links visitors here instead of taking over its own page. Leave unset to keep current behaviour.',
		'agend-apps-core'
	);
	echo '</p>';
}

/**
 * Renders a widget-presence advisory when the selected page does not appear
 * to contain the expected catalogue surface.
 *
 * The answer comes from every registered {@see Agend_Apps_Template_Source},
 * so the advisory renders only when a source can tell AND says the surface is
 * absent; a source that cannot tell (`null`) never triggers a false warning.
 *
 * @param int    $page_id The selected page id (0 = none selected).
 * @param string $surface Agnostic surface kind, e.g. 'events-catalogue'.
 * @param string $message The advisory message to show when the surface is not found.
 */
function agend_apps_records_settings_widget_advisory( int $page_id, string $surface, string $message ): void {
	if ( 0 === $page_id || ! class_exists( 'Agend_Apps_Templates' ) ) {
		return;
	}

	if ( false !== Agend_Apps_Templates::page_contains_surface( $page_id, $surface ) ) {
		return;
	}

	echo '<p class="description notice-warning">' . esc_html( $message ) . '</p>';
}

/**
 * Renders the Events page picker.
 */
function agend_apps_records_settings_field_events_page(): void {
	$selected = absint( get_option( AGEND_APPS_RECORDS_EVENTS_PAGE_OPTION, 0 ) );
	wp_dropdown_pages(
		array(
			'name'              => AGEND_APPS_RECORDS_EVENTS_PAGE_OPTION,
			'show_option_none'  => __( 'Select a page', 'agend-apps-core' ),
			'option_none_value' => '0',
			'selected'          => $selected,
		)
	);
	agend_apps_records_settings_widget_advisory(
		$selected,
		'events-catalogue',
		__( 'This page does not appear to contain an Events Catalogue.', 'agend-apps-core' )
	);
}

/**
 * Renders the Courses page picker.
 */
function agend_apps_records_settings_field_courses_page(): void {
	$selected = absint( get_option( AGEND_APPS_RECORDS_COURSES_PAGE_OPTION, 0 ) );
	wp_dropdown_pages(
		array(
			'name'              => AGEND_APPS_RECORDS_COURSES_PAGE_OPTION,
			'show_option_none'  => __( 'Select a page', 'agend-apps-core' ),
			'option_none_value' => '0',
			'selected'          => $selected,
		)
	);
	agend_apps_records_settings_widget_advisory(
		$selected,
		'courses-catalogue',
		__( 'This page does not appear to contain a Courses Catalogue.', 'agend-apps-core' )
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
function agend_apps_records_settings_template_select( string $option, string $page_option ): void {
	$selected = absint( get_option( $option, 0 ) );
	$has_page = absint( get_option( $page_option, 0 ) ) > 0;
	$options  = class_exists( 'Agend_Apps_Templates' )
		? Agend_Apps_Templates::options( __( 'Built-in detail layout', 'agend-apps-core' ) )
		: array( '' => __( 'Built-in detail layout', 'agend-apps-core' ) );

	echo '<select name="' . esc_attr( $option ) . '"' . ( $has_page ? '' : ' disabled' ) . '>';
	foreach ( $options as $value => $label ) {
		echo '<option value="' . esc_attr( (string) $value ) . '"' . selected( $selected, (int) $value, false ) . '>' . esc_html( $label ) . '</option>';
	}
	echo '</select>';
	echo '<p class="description">';
	if ( $has_page ) {
		esc_html_e( 'A saved template built from the Agend Field, Image, Link and Content Block elements. Rendered server-side on the dedicated page for every item.', 'agend-apps-core' );
	} else {
		esc_html_e( 'Choose the dedicated page above first.', 'agend-apps-core' );
	}
	echo '</p>';
}

/**
 * Renders the Event detail template picker.
 */
function agend_apps_records_settings_field_event_detail_template(): void {
	agend_apps_records_settings_template_select( AGEND_APPS_RECORDS_EVENT_DETAIL_TEMPLATE_OPTION, AGEND_APPS_RECORDS_EVENTS_PAGE_OPTION );
}

/**
 * Renders the Directory page picker.
 */
function agend_apps_records_settings_field_directory_page(): void {
	$selected = absint( get_option( AGEND_APPS_RECORDS_DIRECTORY_PAGE_OPTION, 0 ) );
	wp_dropdown_pages(
		array(
			'name'              => AGEND_APPS_RECORDS_DIRECTORY_PAGE_OPTION,
			'show_option_none'  => __( 'Select a page', 'agend-apps-core' ),
			'option_none_value' => '0',
			'selected'          => $selected,
		)
	);
	agend_apps_records_settings_widget_advisory(
		$selected,
		'directory-catalogue',
		__( 'This page does not appear to contain a Directory Catalogue.', 'agend-apps-core' )
	);
}

/**
 * Renders the Listing detail template picker.
 */
function agend_apps_records_settings_field_listing_detail_template(): void {
	agend_apps_records_settings_template_select( AGEND_APPS_RECORDS_LISTING_DETAIL_TEMPLATE_OPTION, AGEND_APPS_RECORDS_DIRECTORY_PAGE_OPTION );
}

/**
 * Renders the Course detail template picker.
 */
function agend_apps_records_settings_field_course_detail_template(): void {
	agend_apps_records_settings_template_select( AGEND_APPS_RECORDS_COURSE_DETAIL_TEMPLATE_OPTION, AGEND_APPS_RECORDS_COURSES_PAGE_OPTION );
}

/**
 * The three detail-template options, keyed to their admin label.
 *
 * Framework-agnostic: consumed by the builder-specific post-states adapter
 * (adapters/template-post-states.php in the Elementor plugin)
 * so it does not have to know the option names' labels itself.
 *
 * @return array<string, string> Option name => label.
 */
function agend_apps_records_detail_template_labels(): array {
	return array(
		AGEND_APPS_RECORDS_EVENT_DETAIL_TEMPLATE_OPTION   => __( 'Agend Event detail template', 'agend-apps-core' ),
		AGEND_APPS_RECORDS_COURSE_DETAIL_TEMPLATE_OPTION  => __( 'Agend Course detail template', 'agend-apps-core' ),
		AGEND_APPS_RECORDS_LISTING_DETAIL_TEMPLATE_OPTION => __( 'Agend Listing detail template', 'agend-apps-core' ),
	);
}

/**
 * Renders the Detail Pages settings section description.
 */
function agend_apps_records_settings_section_detail(): void {
	echo '<p>';
	esc_html_e(
		'Controls how the Agend catalogue widgets present a single item\'s detail view.',
		'agend-apps-core'
	);
	echo '</p>';
}

/**
 * Renders the server-rendered detail checkbox field.
 */
function agend_apps_records_settings_field_ssr_detail(): void {
	$value = get_option( AGEND_APPS_RECORDS_SSR_DETAIL_OPTION, '' );
	?>
	<label>
		<input type="checkbox" name="<?php echo esc_attr( AGEND_APPS_RECORDS_SSR_DETAIL_OPTION ); ?>" value="1" <?php checked( '1', $value ); ?> />
		<?php esc_html_e( 'Render detail views as server-side child pages of the catalogue page', 'agend-apps-core' ); ?>
	</label>
	<p class="description">
		<?php
		esc_html_e(
			'When on, an item detail URL becomes a virtual child page of the page holding the catalogue widget: the item name is the page title and the catalogue page is its parent, so breadcrumbs natively show Home > Catalogue > Item and the detail is rendered server-side for SEO. When off, the detail is rendered client-side in place on the catalogue page. Applies to the Directory, Events, and Courses widgets. Types with a detail template selected above are always server-rendered.',
			'agend-apps-core'
		);
		?>
	</p>
	<?php
}

/**
 * Renders the show-member-achievements checkbox field.
 */
function agend_apps_records_settings_field_show_achievements(): void {
	$value = get_option( AGEND_APPS_RECORDS_SHOW_ACHIEVEMENTS_OPTION, '' );
	?>
	<label>
		<input type="checkbox" name="<?php echo esc_attr( AGEND_APPS_RECORDS_SHOW_ACHIEVEMENTS_OPTION ); ?>" value="1" <?php checked( '1', $value ); ?> />
		<?php esc_html_e( 'Show a member\'s LMS badges and certificates on the Directory detail view', 'agend-apps-core' ); ?>
	</label>
	<p class="description">
		<?php
		esc_html_e(
			'Adds a "Badges & Credentials" section listing the member\'s course badges and certificates. Requires the connected account\'s API key to hold the directory.achievements.browse scope; leave off if it does not, or listing detail pages will fail to load.',
			'agend-apps-core'
		);
		?>
	</p>
	<?php
}

/**
 * Renders the options page.
 */
function agend_apps_records_settings_page(): void {
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
