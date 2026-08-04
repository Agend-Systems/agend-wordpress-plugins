<?php
/**
 * Admin settings page view.
 *
 * Renders the tabbed settings interface for Agend Apps Core. The
 * `$active_tab` variable is set by Agend_Apps_Admin::render_page().
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap agend-apps-admin">
	<h1><?php esc_html_e( 'Agend Apps', 'agend-apps-core' ); ?></h1>

	<nav class="nav-tab-wrapper">
		<a href="<?php echo esc_url( admin_url( 'options-general.php?page=' . Agend_Apps_Admin::PAGE_SLUG . '&tab=settings' ) ); ?>"
		   class="nav-tab<?php echo 'settings' === $active_tab ? ' nav-tab-active' : ''; ?>">
			<?php esc_html_e( 'Settings', 'agend-apps-core' ); ?>
		</a>
		<a href="<?php echo esc_url( admin_url( 'options-general.php?page=' . Agend_Apps_Admin::PAGE_SLUG . '&tab=cache' ) ); ?>"
		   class="nav-tab<?php echo 'cache' === $active_tab ? ' nav-tab-active' : ''; ?>">
			<?php esc_html_e( 'Cache', 'agend-apps-core' ); ?>
		</a>
		<a href="<?php echo esc_url( admin_url( 'options-general.php?page=' . Agend_Apps_Admin::PAGE_SLUG . '&tab=entitlement-mirror' ) ); ?>"
		   class="nav-tab<?php echo 'entitlement-mirror' === $active_tab ? ' nav-tab-active' : ''; ?>">
			<?php esc_html_e( 'Entitlement Mirror', 'agend-apps-core' ); ?>
		</a>
	</nav>
	<?php if ( 'cache' === $active_tab ) : ?>

		<?php require_once AGEND_APPS_CORE_DIR . 'admin/views/cache.php'; ?>

	<?php elseif ( 'entitlement-mirror' === $active_tab ) : ?>

		<?php require_once AGEND_APPS_CORE_DIR . 'admin/views/entitlement-mirror.php'; ?>

	<?php else : ?>

		<form method="post" action="options.php">
			<?php
			settings_fields( Agend_Apps_Admin::OPTION_GROUP );
			do_settings_sections( Agend_Apps_Admin::PAGE_SLUG );
			submit_button();
			?>
		</form>

	<?php endif; ?>
</div>
