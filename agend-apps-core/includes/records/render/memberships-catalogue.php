<?php
/**
 * Server render of the memberships catalogue surface.
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

// This renderer reads a `url` field through the shared normaliser. The plugin
// bootstrap already loads format.php ahead of every renderer, so this require
// is for the callers that load a single renderer on its own, the unit tests
// among them, rather than for production.
require_once __DIR__ . '/../format.php';

/**
 * Normalises a repeater value to a clean object keyed by tier slug.
 *
 * @param array $repeater_data Raw repeater setting value.
 * @return array Keyed by tier slug, values are 'application' or 'direct'.
 */
function agend_apps_records_memberships_catalogue_build_tier_mode_overrides( array $repeater_data ): array {
	$result = array();
	foreach ( $repeater_data as $row ) {
		$slug = isset( $row['tier_slug'] ) ? (string) $row['tier_slug'] : '';
		$mode = isset( $row['tier_mode'] ) ? (string) $row['tier_mode'] : '';
		if ( '' !== $slug && ( 'application' === $mode || 'direct' === $mode ) ) {
			$result[ $slug ] = $mode;
		}
	}
	return $result;
}

/**
 * Builds the client-side config object from the widget settings.
 *
 * @param array $s Settings for display.
 * @return array Config passed to the frontend script as JSON.
 */
function agend_apps_records_memberships_catalogue_build_config( array $s ): array {
	// `success_url` is a `url` field: Elementor's URL control hands us
	// array( 'url' => ... ), a block a plain string. Reading only the array
	// shape, as this did before the block surface existed, silently dropped a
	// block's value and rendered no redirect at all.
	$success_url = agend_apps_records_normalise_url_setting( $s['success_url'] ?? null );

	// This surface has no `inherit_colours` control (US-1.2 spec-vs-code
	// finding), so `$s` is passed through unmerged: a missing key resolves to
	// not-'yes' and the source is always 'custom'.
	$colours = agend_apps_records_resolve_colours(
		$s,
		array(
			'heading' => AGEND_APPS_RECORDS_COLOUR_DEFAULTS['heading'],
			'body'    => AGEND_APPS_RECORDS_COLOUR_DEFAULTS['body'],
			'accent'  => '#F76B4F',
		)
	);

	return array(
		'heading'          => (string) ( $s['heading_text'] ?? '' ),
		// '' (both), 'individual', or 'corporate' — forwarded to the tiers
		// API as the tierType query param (SPEC-CORE-20260722).
		'membershipType'   => (string) ( $s['membership_type'] ?? '' ),
		'columns'          => array(
			'desktop' => (int) ( $s['columns_desktop'] ?? 3 ),
			'tablet'  => (int) ( $s['columns_tablet'] ?? 2 ),
			'mobile'  => (int) ( $s['columns_mobile'] ?? 1 ),
		),
		'cardRadius'       => (int) ( $s['card_radius'] ?? 10 ),
		'fields'           => array(
			'description' => 'yes' === ( $s['show_description'] ?? 'yes' ),
			'benefits'    => 'yes' === ( $s['show_benefits'] ?? 'yes' ),
			'price'       => 'yes' === ( $s['show_price'] ?? 'yes' ),
		),
		'signupMode'       => (string) ( $s['global_signup_mode'] ?? 'application' ),
		'tierModeOverrides' => agend_apps_records_memberships_catalogue_build_tier_mode_overrides( (array) ( $s['tier_mode_overrides'] ?? array() ) ),
		'successUrl'       => $success_url,
		'colours'          => array(
			'accent'  => $colours['colours']['accent'],
			'heading' => $colours['colours']['heading'],
			'body'    => $colours['colours']['body'],
		),
		// No `inherit_fonts` control on this surface either; fonts always
		// follow the account theme, unaffected by this story (Section 6.2).
		'theme'            => array(
			'inheritFonts' => false,
			'colourSource' => $colours['source'],
		),
	);
}

/**
 * Renders the surface container on the front end, returned as HTML.
 *
 * The catalogue and form are rendered client-side by
 * assets/js/memberships-catalogue.js.
 */
function agend_apps_records_render_memberships_catalogue( array $settings ): string {
	ob_start();
	$config   = agend_apps_records_memberships_catalogue_build_config( $settings );

	$style = sprintf(
		'--agend-mem-accent:%1$s;--agend-mem-heading:%2$s;--agend-mem-body:%3$s;--agend-mem-radius:%4$dpx;--agend-mem-cols-desktop:%5$d;--agend-mem-cols-tablet:%6$d;--agend-mem-cols-mobile:%7$d;',
		esc_attr( $config['colours']['accent'] ),
		esc_attr( $config['colours']['heading'] ),
		esc_attr( $config['colours']['body'] ),
		(int) $config['cardRadius'],
		(int) $config['columns']['desktop'],
		(int) $config['columns']['tablet'],
		(int) $config['columns']['mobile']
	);
	?>
		<div class="agend-memberships" style="<?php echo esc_attr( $style ); ?>" data-agend-memberships-config="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">
			<span class="agend-visually-hidden" role="status"><?php esc_html_e( 'Loading membership options…', 'agend-apps-core' ); ?></span>
			<?php if ( '' !== $config['heading'] ) : ?>
				<h2 class="agend-mem-heading"><?php echo esc_html( $config['heading'] ); ?></h2>
			<?php endif; ?>
			<div class="agend-mem-container">
				<div class="agend-mem-grid">
					<?php for ( $i = 0; $i < 3; $i++ ) : ?>
						<article class="agend-mem-card agend-mem-skeleton" aria-hidden="true">
							<div class="agend-skel-line" style="width:60%"></div>
							<div class="agend-skel-line" style="width:40%"></div>
							<div class="agend-skel-line" style="width:85%"></div>
						</article>
					<?php endfor; ?>
				</div>
			</div>
		</div>
		<?php

	return (string) ob_get_clean();
}
