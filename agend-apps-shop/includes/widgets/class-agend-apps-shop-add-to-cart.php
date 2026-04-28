<?php
/**
 * Elementor Add to Cart widget for Agend Apps Shop.
 *
 * @package Agend_Apps_Shop
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders a quantity stepper and an Add to Cart button for a single product.
 *
 * On click the widget POSTs to the cart/items REST endpoint and stores any
 * returned guestSessionToken in the agend_cart_session cookie. It then
 * dispatches an `agend:cart:updated` event so sibling widgets can refresh.
 */
class Agend_Apps_Shop_Add_To_Cart extends \Elementor\Widget_Base {

	/**
	 * Returns the widget name (unique identifier).
	 *
	 * @return string Widget name.
	 */
	public function get_name(): string {
		return 'agend-apps-add-to-cart';
	}

	/**
	 * Returns the widget display title.
	 *
	 * @return string Widget title.
	 */
	public function get_title(): string {
		return __( 'Add to Cart', 'agend-apps-shop' );
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
			'section_product',
			array(
				'label' => __( 'Product', 'agend-apps-shop' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'product_type',
			array(
				'label'   => __( 'Product Type', 'agend-apps-shop' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => array(
					'event_tickets'        => __( 'Event Tickets', 'agend-apps-shop' ),
					'crm_membership_tiers' => __( 'Membership Tiers', 'agend-apps-shop' ),
					'courses'              => __( 'Courses', 'agend-apps-shop' ),
				),
				'default' => 'event_tickets',
			)
		);

		$this->add_control(
			'product_id',
			array(
				'label'       => __( 'Product ID (UUID)', 'agend-apps-shop' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'placeholder' => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
			)
		);

		$this->add_control(
			'button_label',
			array(
				'label'   => __( 'Button Label', 'agend-apps-shop' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Add to Cart', 'agend-apps-shop' ),
			)
		);

		$this->add_control(
			'quantity',
			array(
				'label'   => __( 'Default Quantity', 'agend-apps-shop' ),
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'min'     => 1,
				'max'     => 100,
				'default' => 1,
			)
		);

		$this->add_control(
			'max_quantity',
			array(
				'label'       => __( 'Max Quantity Override', 'agend-apps-shop' ),
				'type'        => \Elementor\Controls_Manager::NUMBER,
				'min'         => 0,
				'default'     => 0,
				'description' => __( 'Set to 0 to allow the API to enforce its own limit.', 'agend-apps-shop' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Renders the widget HTML on the frontend.
	 */
	protected function render(): void {
		$settings     = $this->get_settings_for_display();
		$product_type = sanitize_text_field( $settings['product_type'] );
		$product_id   = sanitize_text_field( $settings['product_id'] );
		$button_label = sanitize_text_field( $settings['button_label'] );
		$quantity     = (int) $settings['quantity'];
		$max_quantity = (int) $settings['max_quantity'];

		$max_attr = $max_quantity > 0 ? ' max="' . esc_attr( $max_quantity ) . '"' : '';
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
	}
}
