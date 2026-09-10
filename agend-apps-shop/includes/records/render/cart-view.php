<?php
/**
 * Server render of the cart-view surface: the full shopping cart shell.
 *
 * Page-builder agnostic: the same markup whichever editor placed the
 * surface. All cart content (items, totals, the checkout/testing actions,
 * the clear-cart confirmation) is fetched and rendered client-side by
 * assets/js/agend-apps-shop-cart-view.js; this function only ever builds the
 * static shell that script populates and shows/hides via the `hidden`
 * attribute.
 *
 * @package Agend_Apps_Shop
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the cart-view surface.
 *
 * @param array $settings Surface settings (see agend_apps_records_surface_schema( 'cart-view' )).
 *                        Unused: this surface's only control is a
 *                        Content-tab notice that stores no value (see the
 *                        schema function's docblock). Accepted anyway so
 *                        every surface renderer shares one call shape.
 * @return string The rendered markup.
 */
function agend_apps_records_render_cart_view( array $settings ): string {
	// elementor-widget-container/elementor-button are kept even though this
	// markup now also renders inside a Gutenberg block: a site's own custom
	// CSS may already target these classes from the Elementor era, and
	// silently dropping them here would be a visual regression on those sites.
	ob_start();
	?>
	<div class="agend-apps-shop-cart-view elementor-widget-container">
		<div class="agend-shop-cart-loading" aria-live="polite"><?php esc_html_e( 'Loading cart&hellip;', 'agend-apps-shop' ); ?></div>

		<div class="agend-shop-cart-empty" hidden>
			<p><?php esc_html_e( 'Your cart is empty.', 'agend-apps-shop' ); ?></p>
		</div>

		<div class="agend-shop-cart-locked-notice" hidden>
			<p><?php esc_html_e( 'Your cart is currently locked while a checkout is in progress. Cancel the checkout below to make changes.', 'agend-apps-shop' ); ?></p>
		</div>

		<div class="agend-shop-cart-content" hidden>
			<table class="agend-shop-cart-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Item', 'agend-apps-shop' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Unit Price', 'agend-apps-shop' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Quantity', 'agend-apps-shop' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Subtotal', 'agend-apps-shop' ); ?></th>
						<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'agend-apps-shop' ); ?></span></th>
					</tr>
				</thead>
				<tbody class="agend-shop-cart-items"></tbody>
				<tfoot>
					<tr>
						<td colspan="3" class="agend-shop-cart-total-label"><?php esc_html_e( 'Total', 'agend-apps-shop' ); ?></td>
						<td class="agend-shop-cart-total-amount"></td>
						<td></td>
					</tr>
				</tfoot>
			</table>

			<div class="agend-shop-cart-actions">
				<button class="agend-shop-btn-clear-cart"><?php esc_html_e( 'Clear Cart', 'agend-apps-shop' ); ?></button>
				<div class="agend-shop-cart-primary-actions">
					<button class="agend-shop-btn-proceed-checkout elementor-button"><?php esc_html_e( 'Proceed to Checkout', 'agend-apps-shop' ); ?></button>
				</div>
			</div>

			<div class="agend-shop-cart-test-actions">
				<p><strong><?php esc_html_e( 'Testing only:', 'agend-apps-shop' ); ?></strong></p>
				<button class="agend-shop-btn-complete-checkout"><?php esc_html_e( 'Complete Checkout', 'agend-apps-shop' ); ?></button>
				<button class="agend-shop-btn-cancel-checkout"><?php esc_html_e( 'Cancel Checkout', 'agend-apps-shop' ); ?></button>
			</div>

			<div class="agend-shop-cart-message" aria-live="polite"></div>
		</div>

		<!-- Confirmation modal for clear cart -->
		<div class="agend-shop-confirm-modal" role="dialog" aria-modal="true" aria-labelledby="agend-shop-confirm-title" hidden>
			<div class="agend-shop-confirm-modal-inner">
				<p id="agend-shop-confirm-title"><?php esc_html_e( 'Are you sure you want to clear your cart? This cannot be undone.', 'agend-apps-shop' ); ?></p>
				<button class="agend-shop-btn-confirm-yes"><?php esc_html_e( 'Yes, clear cart', 'agend-apps-shop' ); ?></button>
				<button class="agend-shop-btn-confirm-no"><?php esc_html_e( 'Cancel', 'agend-apps-shop' ); ?></button>
			</div>
		</div>
	</div>
	<?php
	return (string) ob_get_clean();
}
