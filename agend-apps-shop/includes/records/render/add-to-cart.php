<?php
/**
 * Server render of the add-to-cart surface: a quantity stepper and an Add to
 * Cart button for a single product.
 *
 * Page-builder agnostic: the same markup whichever editor placed the
 * surface. An adapter passes the surface's settings (see
 * agend_apps_records_surface_schema( 'add-to-cart' )) and echoes the returned
 * HTML. All interactivity (the click handler that POSTs to the cart/items
 * REST endpoint and stores the guest session token) lives in
 * assets/js/agend-apps-shop-add-to-cart.js; this function only ever builds
 * the static shell that script binds to.
 *
 * @package Agend_Apps_Shop
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the add-to-cart surface.
 *
 * @param array $settings Surface settings (see agend_apps_records_surface_schema( 'add-to-cart' )).
 * @return string The rendered markup.
 */
function agend_apps_records_render_add_to_cart( array $settings ): string {
	$product_type = sanitize_text_field( (string) ( $settings['product_type'] ?? 'event_tickets' ) );
	$product_id   = sanitize_text_field( (string) ( $settings['product_id'] ?? '' ) );
	$button_label = sanitize_text_field( (string) ( $settings['button_label'] ?? __( 'Add to Cart', 'agend-apps-shop' ) ) );
	$quantity     = (int) ( $settings['quantity'] ?? 1 );
	$max_quantity = (int) ( $settings['max_quantity'] ?? 0 );

	$max_attr = $max_quantity > 0 ? ' max="' . esc_attr( $max_quantity ) . '"' : '';

	// elementor-widget-container/elementor-button are kept even though this
	// markup now also renders inside a Gutenberg block: a site's own custom
	// CSS may already target these classes from the Elementor era, and
	// silently dropping them here would be a visual regression on those sites.
	ob_start();
	?>
	<div class="agend-apps-shop-add-to-cart elementor-widget-container">
		<div class="agend-shop-atc-quantity">
			<button class="agend-shop-atc-qty-btn agend-shop-atc-qty-decrement" aria-label="<?php esc_attr_e( 'Decrease quantity', 'agend-apps-shop' ); ?>">&#8722;</button>
			<input name="agend-shop-product-<?php echo esc_attr( $product_id ); ?>" class="agend-shop-atc-qty-input" type="number" value="<?php echo esc_attr( $quantity ); ?>" min="1"<?php echo $max_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
			<button class="agend-shop-atc-qty-btn agend-shop-atc-qty-increment" aria-label="<?php esc_attr_e( 'Increase quantity', 'agend-apps-shop' ); ?>">&#43;</button>
		</div>
		<button class="agend-shop-add-to-cart-btn elementor-button"
				data-product-type="<?php echo esc_attr( $product_type ); ?>"
				data-product-id="<?php echo esc_attr( $product_id ); ?>"
				data-max-quantity="<?php echo esc_attr( $max_quantity ); ?>">
			<?php echo esc_html( $button_label ); ?>
		</button>
		<div class="agend-shop-atc-message" aria-live="polite"></div>
	</div>
	<?php
	return (string) ob_get_clean();
}
