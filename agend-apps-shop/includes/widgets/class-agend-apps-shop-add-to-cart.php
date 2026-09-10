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
	 * Returns the frontend script handle this widget depends on.
	 *
	 * @return array Script handles.
	 */
	public function get_script_depends(): array {
		return array( 'agend-apps-shop-add-to-cart' );
	}

	/**
	 * Returns the frontend style handle this widget depends on.
	 *
	 * @return array Style handles.
	 */
	public function get_style_depends(): array {
		return array( 'agend-apps-shop-add-to-cart' );
	}

	/**
	 * Registers all Elementor controls for this widget.
	 *
	 * Hand-declared rather than built from
	 * agend_apps_records_surface_schema( 'add-to-cart' ): Agend_Elementor_Schema_Controls
	 * lives in the separate agend-elementor plugin, and this plugin hard-depends
	 * only on agend-apps-core (see agend_apps_shop_bootstrap()'s agend_apps_api
	 * check), so it must keep registering controls with Elementor directly even
	 * when agend-elementor is inactive. The schema is the single source of
	 * truth for the block editor only; a parity test
	 * (SchemaWidgetParityTest) keeps the two declarations from drifting apart.
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
	 * Echoes the surface, rendered by Agend Apps Core from this widget's settings.
	 */
	protected function render(): void {
		echo agend_apps_records_render_add_to_cart( $this->get_settings_for_display() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the core renderer.
	}
}
