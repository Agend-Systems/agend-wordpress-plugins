<?php
/**
 * Elementor Cart View widget for Agend Apps Shop.
 *
 * @package Agend_Apps_Shop
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the full cart table, action buttons, and confirmation modal.
 *
 * All cart content is fetched and rendered client-side by
 * agend-apps-shop-cart-view.js. This class outputs only the server-side shell.
 */
class Agend_Apps_Shop_Cart_View extends \Elementor\Widget_Base {

	/**
	 * Returns the widget name (unique identifier).
	 *
	 * @return string Widget name.
	 */
	public function get_name(): string {
		return 'agend-apps-cart-view';
	}

	/**
	 * Returns the widget display title.
	 *
	 * @return string Widget title.
	 */
	public function get_title(): string {
		return __( 'Cart View', 'agend-apps-shop' );
	}

	/**
	 * Returns the Elementor icon class for the widget.
	 *
	 * @return string Icon class.
	 */
	public function get_icon(): string {
		return 'eicon-table';
	}

	/**
	 * Returns the Elementor categories this widget belongs to.
	 *
	 * @return array List of category slugs.
	 */
	public function get_categories(): array {
		return array( Agend_Apps_Shop_Elementor::CATEGORY );
	}

	/**
	 * Registers all Elementor controls for this widget.
	 */
	protected function register_controls(): void {
		$this->start_controls_section(
			'section_cart_view_info',
			array(
				'label' => __( 'Cart View', 'agend-apps-shop' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'cart_view_notice',
			array(
				'label'     => '',
				'type'      => \Elementor\Controls_Manager::RAW_HTML,
				'raw'       => __( 'This widget renders the full shopping cart and is intended to be placed on a dedicated cart page. No configuration is required — cart contents are loaded dynamically from the API.', 'agend-apps-shop' ),
				'separator' => 'after',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Renders the widget shell HTML on the frontend.
	 *
	 * JavaScript in agend-apps-shop-cart-view.js populates the dynamic content.
	 */
	protected function render(): void {
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
	}
}
