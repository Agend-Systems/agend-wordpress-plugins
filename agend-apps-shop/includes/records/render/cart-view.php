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
	// The core gate may be absent on an older Agend Apps Core; the call is then
	// left to the gateway, which stays the authority.
	if ( function_exists( 'agend_apps_records_surface_gate' ) ) {
		$gate = agend_apps_records_surface_gate( 'cart-view', false );
		if ( null !== $gate ) {
			return $gate;
		}
	}

	// elementor-widget-container/elementor-button are kept even though this
	// markup now also renders inside a Gutenberg block: a site's own custom
	// CSS may already target these classes from the Elementor era, and
	// silently dropping them here would be a visual regression on those sites.
	$is_member     = '' !== agend_apps_get_bearer_token();
	$guest_allowed = function_exists( 'agend_apps_shop_guest_checkout_allowed' ) && agend_apps_shop_guest_checkout_allowed();
	$sign_in_url   = '';
	if ( ! $is_member && ! $guest_allowed ) {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- passed to home_url(), which builds and escapes the return path.
		$return_url  = home_url( isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/' );
		$sign_in_url = function_exists( 'agend_apps_shop_cart_sign_in_url' ) ? agend_apps_shop_cart_sign_in_url( $return_url ) : wp_login_url( $return_url );
	}
	ob_start();
	?>
	<div class="agend-apps-shop-cart-view elementor-widget-container" data-member-signed-in="<?php echo $is_member ? 'true' : 'false'; ?>">
		<div class="agend-shop-cart-loading" aria-live="polite"><?php esc_html_e( 'Loading cart&hellip;', 'agend-apps-shop' ); ?></div>

		<div class="agend-shop-cart-empty" hidden>
			<p><?php esc_html_e( 'Your cart is empty.', 'agend-apps-shop' ); ?></p>
			<a class="agend-shop-keep-browsing" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Keep browsing', 'agend-apps-shop' ); ?></a>
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

			<?php if ( '' !== $sign_in_url ) : ?>
				<div class="agend-shop-cart-signin">
					<p><?php esc_html_e( 'Sign in to check out. Your cart will be kept.', 'agend-apps-shop' ); ?></p>
					<a class="agend-shop-cart-signin-link elementor-button" href="<?php echo esc_url( $sign_in_url ); ?>"><?php esc_html_e( 'Sign in to check out', 'agend-apps-shop' ); ?></a>
				</div>
			<?php elseif ( ! $is_member ) : ?>
				<div class="agend-shop-cart-buyer">
					<label><?php esc_html_e( 'Your name', 'agend-apps-shop' ); ?>
						<input class="agend-shop-buyer-name" type="text" autocomplete="name" maxlength="200" required>
					</label>
					<label><?php esc_html_e( 'Your email', 'agend-apps-shop' ); ?>
						<input class="agend-shop-buyer-email" type="email" autocomplete="email" required>
					</label>
				</div>
			<?php else : ?>
				<div class="agend-shop-cart-payment" hidden></div>
			<?php endif; ?>

			<div class="agend-shop-cart-actions">
				<a class="agend-shop-keep-browsing" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Keep browsing', 'agend-apps-shop' ); ?></a>
				<button class="agend-shop-btn-clear-cart"><?php esc_html_e( 'Clear Cart', 'agend-apps-shop' ); ?></button>
				<div class="agend-shop-cart-primary-actions">
					<?php if ( '' === $sign_in_url ) : ?>
						<button class="agend-shop-btn-proceed-checkout elementor-button"><?php esc_html_e( 'Proceed to Checkout', 'agend-apps-shop' ); ?></button>
					<?php endif; ?>
					<button class="agend-shop-btn-cancel-checkout" hidden disabled><?php esc_html_e( 'Cancel checkout and edit cart', 'agend-apps-shop' ); ?></button>
				</div>
			</div>

			<?php if ( current_user_can( 'manage_options' ) ) : ?>
			<div class="agend-shop-cart-test-actions">
				<p><strong><?php esc_html_e( 'Testing only:', 'agend-apps-shop' ); ?></strong></p>
				<button class="agend-shop-btn-complete-checkout"><?php esc_html_e( 'Complete Checkout', 'agend-apps-shop' ); ?></button>
			</div>

			<?php endif; ?>

			<div class="agend-shop-cart-message" aria-live="polite"></div>
		</div>

		<?php if ( $is_member ) : ?>
			<div class="agend-shop-invoice-confirmation" tabindex="-1" hidden></div>
		<?php endif; ?>

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
