<?php
/**
 * Server render of the cart-header surface: a cart icon with a live
 * item-count badge.
 *
 * Page-builder agnostic: the same markup whichever editor placed the
 * surface. An adapter passes the surface's settings (see
 * agend_apps_records_surface_schema( 'cart-header' )) and echoes the returned
 * HTML. The badge count itself is fetched from the REST API and kept live by
 * assets/js/agend-apps-shop-cart-header.js; this function only ever builds
 * the static shell that script updates.
 *
 * @package Agend_Apps_Shop
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The cart_icon control's own default, matching
 * Agend_Apps_Shop_Cart_Header::register_controls()'s ICONS control default.
 *
 * @var array{value: string, library: string}
 */
const AGEND_APPS_SHOP_CART_HEADER_DEFAULT_ICON = array(
	'value'   => 'fas fa-shopping-cart',
	'library' => 'fa-solid',
);

/**
 * Renders the cart icon.
 *
 * `cart_icon` is an Elementor ICONS control -- a builder-specific `adapter`
 * field in the schema (see agend_apps_records_schema_cart_header()) -- so it
 * carries no value at all when this surface is placed as a block: the icon
 * is not editable from the block inspector in this pass, and the fallback
 * markup below is what a block placement always shows. The same fallback
 * also covers an Elementor page with Elementor itself deactivated (so
 * \Elementor\Icons_Manager no longer exists), which is exactly the situation
 * this move to a page-builder-agnostic renderer has to survive.
 *
 * @param mixed $icon The `cart_icon` setting, or unset/empty.
 * @return string
 */
function agend_apps_records_cart_header_icon_markup( $icon ): string {
	if ( is_array( $icon ) && ! empty( $icon['value'] ) && class_exists( '\\Elementor\\Icons_Manager' ) ) {
		ob_start();
		\Elementor\Icons_Manager::render_icon( $icon, array( 'aria-hidden' => 'true' ) );
		return (string) ob_get_clean();
	}

	// Plain shopping-cart glyph (Google Material Symbols "shopping_cart",
	// Apache-2.0): no external icon-library dependency, so it still renders
	// correctly with no Elementor loaded at all.
	return '<svg viewBox="0 0 24 24" width="1em" height="1em" aria-hidden="true" focusable="false"><path fill="currentColor" d="M7 18c-1.1 0-1.99.9-1.99 2S5.9 22 7 22s2-.9 2-2-.9-2-2-2zM1 2v2h2l3.6 7.59-1.35 2.45c-.16.28-.25.61-.25.96 0 1.1.9 2 2 2h12v-2H7.42c-.14 0-.25-.11-.25-.25l.03-.12L8.1 13h7.45c.75 0 1.41-.41 1.75-1.03l3.58-6.49c.08-.14.12-.31.12-.48 0-.55-.45-1-1-1H5.21l-.94-2H1zm16 16c-1.1 0-1.99.9-1.99 2s.89 2 1.99 2 2-.9 2-2-.9-2-2-2z"/></svg>';
}

/**
 * Renders the cart-header surface.
 *
 * @param array $settings Surface settings (see agend_apps_records_surface_schema( 'cart-header' )).
 * @return string The rendered markup.
 */
function agend_apps_records_render_cart_header( array $settings ): string {
	// cart_page_url is a `url` field, so it arrives as Elementor's
	// array( 'url' => ... ) or as the plain string a block stores. Core's
	// shared normaliser reads either, but it only exists from Agend Apps Core
	// 1.15.0 and this plugin checks only that core is present, not which
	// version, so fall back to reading both shapes locally rather than
	// fataling on an older core.
	$url_setting = $settings['cart_page_url'] ?? null;
	$chosen_url  = function_exists( 'agend_apps_records_normalise_url_setting' )
		? agend_apps_records_normalise_url_setting( $url_setting )
		: ( is_array( $url_setting ) ? (string) ( $url_setting['url'] ?? '' ) : (string) ( $url_setting ?? '' ) );

	$cart_url = '' !== $chosen_url
		? esc_url( $chosen_url )
		: esc_url( (string) get_option( 'agend_apps_shop_cart_page_url', '' ) );

	$badge_color      = sanitize_hex_color( (string) ( $settings['badge_color'] ?? '' ) ) ?: '#e74c3c';
	$badge_text_color = sanitize_hex_color( (string) ( $settings['badge_text_color'] ?? '' ) ) ?: '#ffffff';
	$badge_style      = sprintf(
		'background-color: %s; color: %s;',
		esc_attr( $badge_color ),
		esc_attr( $badge_text_color )
	);

	// elementor-widget-container is kept even though this markup now also
	// renders inside a Gutenberg block: a site's own custom CSS may already
	// target it from the Elementor era, and silently dropping it here would
	// be a visual regression on those sites.
	ob_start();
	?>
	<div class="agend-apps-shop-cart-header elementor-widget-container">
		<a class="agend-shop-cart-header-link" href="<?php echo $cart_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" aria-label="<?php esc_attr_e( 'View cart', 'agend-apps-shop' ); ?>">
			<span class="agend-shop-cart-icon">
				<?php echo agend_apps_records_cart_header_icon_markup( $settings['cart_icon'] ?? null ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by Icons_Manager, or the fixed markup above. ?>
			</span>
			<span class="agend-shop-cart-badge" aria-live="polite" aria-atomic="true" hidden style="<?php echo esc_attr( $badge_style ); ?>">0</span>
		</a>
	</div>
	<?php
	return (string) ob_get_clean();
}
