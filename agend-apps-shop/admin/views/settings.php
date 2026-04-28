<?php
/**
 * Admin settings page view for Agend Apps Shop.
 *
 * @package Agend_Apps_Shop
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
	<form method="post" action="options.php">
		<?php
		settings_fields( Agend_Apps_Shop_Admin::OPTION_GROUP );
		do_settings_sections( Agend_Apps_Shop_Admin::PAGE_SLUG );
		submit_button();
		?>
	</form>
</div>
