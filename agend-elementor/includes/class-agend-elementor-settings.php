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
 * When enabled, a catalogue widget's detail URL (/{page}/listing/{slug}/, and in
 * future /event/ and /course/) resolves to a virtual child page of the listings
 * page: the listing name becomes the page title and the listings page its
 * parent, so any breadcrumb system natively shows Home > Listings > Item, and
 * the detail body is rendered server-side for SEO. Filterable so a site can
 * force it on/off in code.
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
		<?php esc_html_e( 'Render detail views as server-side child pages of the listings page', 'agend-elementor' ); ?>
	</label>
	<p class="description">
		<?php
		esc_html_e(
			'When on, a listing detail URL becomes a virtual child page of the page holding the catalogue widget: the listing name is the page title and the listings page is its parent, so breadcrumbs natively show Home > Listings > Item and the detail is rendered server-side for SEO. When off, the detail is rendered client-side in place on the catalogue page. Applies to the Directory widget.',
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
