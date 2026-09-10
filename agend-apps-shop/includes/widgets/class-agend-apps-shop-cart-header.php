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
	 * Returns the frontend script handle this widget depends on.
	 *
	 * @return array Script handles.
	 */
	public function get_script_depends(): array {
		return array( 'agend-apps-shop-cart-header' );
	}

	/**
	 * Returns the frontend style handle this widget depends on.
	 *
	 * @return array Style handles.
	 */
	public function get_style_depends(): array {
		return array( 'agend-apps-shop-cart-header' );
	}

	/**
	 * Registers all Elementor controls for this widget.
	 *
	 * Hand-declared rather than built from
	 * agend_apps_records_surface_schema( 'cart-header' ): Agend_Elementor_Schema_Controls
	 * lives in the separate agend-elementor plugin, and this plugin hard-depends
	 * only on agend-apps-core (see agend_apps_shop_bootstrap()'s agend_apps_api
	 * check), so it must keep registering controls with Elementor directly even
	 * when agend-elementor is inactive. The schema is the single source of
	 * truth for the block editor only; a parity test
	 * (SchemaWidgetParityTest) keeps the two declarations from drifting apart.
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
	 * Echoes the surface, rendered by Agend Apps Core from this widget's settings.
	 */
	protected function render(): void {
		echo agend_apps_records_render_cart_header( $this->get_settings_for_display() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the core renderer.
	}
}
