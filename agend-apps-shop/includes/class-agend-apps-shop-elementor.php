<?php
/**
 * Elementor widget loader for Agend Apps Shop.
 *
 * @package Agend_Apps_Shop
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers a custom Elementor widget category and all Agend Apps Shop widgets.
 *
 * Hooks on `elementor/widgets/register` and bails silently if Elementor is
 * not loaded. The custom category is registered on
 * `elementor/elements/categories_registered`.
 */
class Agend_Apps_Shop_Elementor {

	/**
	 * Elementor widget category slug.
	 *
	 * @var string
	 */
	const CATEGORY = 'agend-apps';

	/**
	 * Registers Elementor hooks.
	 */
	public function __construct() {
		add_action( 'elementor/elements/categories_registered', array( $this, 'register_category' ) );
		add_action( 'elementor/widgets/register', array( $this, 'register_widgets' ) );
	}

	/**
	 * Registers the Agend Apps widget category with Elementor.
	 *
	 * @param \Elementor\Elements_Manager $elements_manager Elementor elements manager.
	 */
	public function register_category( $elements_manager ): void {
		$elements_manager->add_category(
			self::CATEGORY,
			array(
				'title' => __( 'Agend Apps', 'agend-apps-shop' ),
				'icon'  => 'fa fa-plug',
			)
		);
	}

	/**
	 * Registers all Agend Apps Shop widgets with Elementor.
	 *
	 * @param \Elementor\Widgets_Manager $widgets_manager Elementor widgets manager.
	 */
	public function register_widgets( $widgets_manager ): void {
		require_once AGEND_APPS_SHOP_DIR . 'includes/widgets/class-agend-apps-shop-add-to-cart.php';
		require_once AGEND_APPS_SHOP_DIR . 'includes/widgets/class-agend-apps-shop-cart-header.php';
		require_once AGEND_APPS_SHOP_DIR . 'includes/widgets/class-agend-apps-shop-cart-view.php';

		$widgets_manager->register( new Agend_Apps_Shop_Add_To_Cart() );
		$widgets_manager->register( new Agend_Apps_Shop_Cart_Header() );
		$widgets_manager->register( new Agend_Apps_Shop_Cart_View() );
	}
}

new Agend_Apps_Shop_Elementor();
