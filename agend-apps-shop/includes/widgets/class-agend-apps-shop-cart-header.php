<?php
/**
 * Elementor Cart Header widget for Agend Apps Shop.
 *
 * @package Agend_Apps_Shop
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders a cart icon with a live item-count badge.
 *
 * The badge count is fetched from the REST API on page load and refreshes
 * whenever the `agend:cart:updated` custom event fires on the document.
 */
class Agend_Apps_Shop_Cart_Header extends \Elementor\Widget_Base {

	/**
	 * Returns the widget name (unique identifier).
	 *
	 * @return string Widget name.
	 */
	public function get_name(): string {
		return 'agend-apps-cart-header';
	}

	/**
	 * Returns the widget display title.
	 *
	 * @return string Widget title.
	 */
	public function get_title(): string {
		return __( 'Cart Header', 'agend-apps-shop' );
	}

	/**
	 * Returns the Elementor icon class for the widget.
	 *
	 * @return string Icon class.
	 */
	public function get_icon(): string {
		return 'eicon-cart';
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
			'section_cart_header',
			array(
				'label' => __( 'Cart Header', 'agend-apps-shop' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'cart_icon',
			array(
				'label'   => __( 'Cart Icon', 'agend-apps-shop' ),
				'type'    => \Elementor\Controls_Manager::ICONS,
				'default' => array(
					'value'   => 'fas fa-shopping-cart',
					'library' => 'fa-solid',
				),
			)
		);

		$this->add_control(
			'cart_page_url',
			array(
				'label'       => __( 'Cart Page URL', 'agend-apps-shop' ),
				'type'        => \Elementor\Controls_Manager::URL,
				'description' => __( 'If left blank, falls back to the Cart Page URL set in Agend Apps Shop settings.', 'agend-apps-shop' ),
			)
		);

		$this->add_control(
			'badge_color',
			array(
				'label'   => __( 'Badge Background Color', 'agend-apps-shop' ),
				'type'    => \Elementor\Controls_Manager::COLOR,
				'default' => '#e74c3c',
			)
		);

		$this->add_control(
			'badge_text_color',
			array(
				'label'   => __( 'Badge Text Color', 'agend-apps-shop' ),
				'type'    => \Elementor\Controls_Manager::COLOR,
				'default' => '#ffffff',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Renders the widget HTML on the frontend.
	 */
	protected function render(): void {
		$settings = $this->get_settings_for_display();

		// Resolve cart page URL: widget setting overrides global option.
		$cart_url = '';
		if ( ! empty( $settings['cart_page_url']['url'] ) ) {
			$cart_url = esc_url( $settings['cart_page_url']['url'] );
		} else {
			$cart_url = esc_url( get_option( 'agend_apps_shop_cart_page_url', '' ) );
		}

		$badge_color      = sanitize_hex_color( $settings['badge_color'] ) ?: '#e74c3c';
		$badge_text_color = sanitize_hex_color( $settings['badge_text_color'] ) ?: '#ffffff';
		$badge_style      = sprintf(
			'background-color: %s; color: %s;',
			esc_attr( $badge_color ),
			esc_attr( $badge_text_color )
		);
		?>
		<div class="agend-apps-shop-cart-header elementor-widget-container">
			<a class="agend-shop-cart-header-link" href="<?php echo $cart_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" aria-label="<?php esc_attr_e( 'View cart', 'agend-apps-shop' ); ?>">
				<span class="agend-shop-cart-icon">
					<?php \Elementor\Icons_Manager::render_icon( $settings['cart_icon'], array( 'aria-hidden' => 'true' ) ); ?>
				</span>
				<span class="agend-shop-cart-badge" aria-live="polite" aria-atomic="true" hidden style="<?php echo esc_attr( $badge_style ); ?>">0</span>
			</a>
		</div>
		<?php
	}
}
