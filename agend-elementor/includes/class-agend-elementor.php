<?php
/**
 * Elementor widget loader for Agend Elementor Widgets.
 *
 * @package Agend_Elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the shared Agend Apps widget category and the Agend Elementor
 * widgets. Reuses the same `agend-apps` category slug established by
 * agend-apps-shop so all Agend widgets group together in the editor.
 */
class Agend_Elementor {

	/**
	 * Elementor widget category slug (shared across Agend plugins).
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
	 * Adding a category that already exists is a no-op in Elementor, so this is
	 * safe alongside agend-apps-shop.
	 *
	 * @param \Elementor\Elements_Manager $elements_manager Elementor elements manager.
	 */
	public function register_category( $elements_manager ): void {
		$elements_manager->add_category(
			self::CATEGORY,
			array(
				'title' => __( 'Agend Apps', 'agend-elementor' ),
				'icon'  => 'fa fa-plug',
			)
		);
	}

	/**
	 * Registers the Agend Elementor widgets.
	 *
	 * @param \Elementor\Widgets_Manager $widgets_manager Elementor widgets manager.
	 */
	public function register_widgets( $widgets_manager ): void {
		require_once AGEND_ELEMENTOR_DIR . 'includes/widgets/class-agend-elementor-events-catalogue.php';

		$widgets_manager->register( new Agend_Elementor_Events_Catalogue() );
	}
}

new Agend_Elementor();
