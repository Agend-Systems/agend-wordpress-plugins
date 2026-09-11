<?php
/**
 * Server render of the account link surface.
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
 * Builds the client-side config object from the surface settings.
 *
 * @param array $s Surface settings (Elementor-shaped values: toggles are 'yes'/'').
 * @return array Config passed to the frontend script as JSON.
 */
function agend_apps_records_account_link_build_config( array $s ): array {
	return array(
		'showHeading' => 'yes' === ( $s['show_heading'] ?? 'yes' ),
		'messages'    => array(
			'heading'    => (string) ( $s['heading_text'] ?? '' ),
			'linked'     => (string) ( $s['linked_message'] ?? '' ),
			'unlinked'   => (string) ( $s['unlinked_message'] ?? '' ),
			'button'     => (string) ( $s['button_label'] ?? '' ),
			'portalLink' => (string) ( $s['portal_link_label'] ?? '' ),
			'loggedOut'  => (string) ( $s['logged_out_message'] ?? '' ),
		),
		'colours'     => array(
			'heading'    => (string) ( $s['heading_colour'] ?? '#1E2A4A' ),
			'body'       => (string) ( $s['body_colour'] ?? '#26304D' ),
			'accent'     => (string) ( $s['accent_colour'] ?? '#FF6B55' ),
			'button'     => (string) ( $s['button_colour'] ?? '#FF6B55' ),
			'buttonText' => (string) ( $s['button_text_colour'] ?? '#FFFFFF' ),
		),
		'theme'       => array(
			'inheritFonts'   => 'yes' === ( $s['inherit_fonts'] ?? 'yes' ),
			'inheritColours' => 'yes' === ( $s['inherit_colours'] ?? 'yes' ),
		),
	);
}

/**
 * Renders the account link surface.
 *
 * The status card is rendered client-side from the config below by
 * assets/js/account-link.js.
 *
 * @param array $settings Surface settings (see agend_apps_records_surface_schema( 'account-link' )).
 * @return string The rendered markup.
 */
function agend_apps_records_render_account_link( array $settings ): string {
	$config = agend_apps_records_account_link_build_config( $settings );

	$style = sprintf(
		'--agend-al-heading:%1$s;--agend-al-body:%2$s;--agend-al-accent:%3$s;--agend-al-button:%4$s;--agend-al-button-text:%5$s;',
		esc_attr( $config['colours']['heading'] ),
		esc_attr( $config['colours']['body'] ),
		esc_attr( $config['colours']['accent'] ),
		esc_attr( $config['colours']['button'] ),
		esc_attr( $config['colours']['buttonText'] )
	);

	ob_start();
	?>
		<div class="agend-account-link" style="<?php echo esc_attr( $style ); ?>" data-agend-account-link-config="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">
			<div class="agend-al-status" role="status"><?php esc_html_e( 'Checking your account…', 'agend-apps-core' ); ?></div>
		</div>
		<?php
	return (string) ob_get_clean();
}
