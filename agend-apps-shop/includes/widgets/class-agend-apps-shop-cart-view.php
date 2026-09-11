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
	 * Returns the frontend script handle this widget depends on.
	 *
	 * @return array Script handles.
	 */
	public function get_script_depends(): array {
		return array( 'agend-apps-shop-cart-view' );
	}

	/**
	 * Returns the frontend style handle this widget depends on.
	 *
	 * @return array Style handles.
	 */
	public function get_style_depends(): array {
		return array( 'agend-apps-shop-cart-view' );
	}

	/**
	 * Registers all Elementor controls for this widget.
	 *
	 * Hand-declared rather than built from
	 * agend_apps_records_surface_schema( 'cart-view' ): Agend_Elementor_Schema_Controls
	 * lives in the separate agend-elementor plugin, and this plugin hard-depends
	 * only on agend-apps-core (see agend_apps_shop_bootstrap()'s agend_apps_api
	 * check), so it must keep registering controls with Elementor directly even
	 * when agend-elementor is inactive. The schema is the single source of
	 * truth for the block editor only; a parity test
	 * (SchemaWidgetParityTest) keeps the two declarations from drifting apart.
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
	 * Echoes the surface, rendered by Agend Apps Core.
	 *
	 * JavaScript in agend-apps-shop-cart-view.js populates the dynamic content.
	 */
	protected function render(): void {
		echo agend_apps_records_render_cart_view( $this->get_settings_for_display() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the core renderer.
	}
}
