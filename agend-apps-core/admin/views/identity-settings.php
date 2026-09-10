<?php
/**
 * Admin "Identity and SSO" settings page view.
 *
 * Renders the plain (non-tabbed) Settings API form for the Identity and SSO
 * page. Required by Agend_Apps_Identity_Admin::render_page(), which has
 * already checked `manage_options`.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap agend-apps-admin">
	<h1><?php esc_html_e( 'Identity and SSO', 'agend-apps-core' ); ?></h1>

	<form method="post" action="options.php">
		<?php
		settings_fields( Agend_Apps_Identity_Admin::OPTION_GROUP );
		do_settings_sections( Agend_Apps_Identity_Admin::PAGE_SLUG );
		submit_button();
		?>
	</form>
</div>
