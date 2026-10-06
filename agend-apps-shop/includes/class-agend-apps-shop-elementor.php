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
	 * Whether a surface is known to be unusable with the API key's scopes.
	 * False on an Agend Apps Core that predates the surface gate: this plugin
	 * checks only that core is present, not which version.
	 *
	 * @param string $surface Surface id, e.g. 'cart-view'.
	 * @return bool
	 */
	public static function surface_gated( string $surface ): bool {
		return function_exists( 'agend_apps_records_surface_gated' ) && agend_apps_records_surface_gated( $surface );
	}

	/**
	 * The gate a widget asks at the top of render(): null to render as normal;
	 * else markup to echo and stop (the disabled box in the editor and its
	 * preview, '' for a visitor).
	 *
	 * @param string $surface Surface id.
	 * @return string|null
	 */
	public static function render_gate( string $surface ): ?string {
		if ( ! function_exists( 'agend_apps_records_surface_gate' ) ) {
			return null;
		}

		$plugin = \Elementor\Plugin::$instance;

		return agend_apps_records_surface_gate( $surface, $plugin->editor->is_edit_mode() || $plugin->preview->is_preview_mode() );
	}

	/**
	 * A widget's script or style handles, or none while its surface is gated:
	 * the disabled box and an empty frontend need neither.
	 *
	 * @param string   $surface Surface id.
	 * @param string[] $handles Handles the widget depends on.
	 * @return string[]
	 */
	public static function depends( string $surface, array $handles ): array {
		return self::surface_gated( $surface ) ? array() : $handles;
	}

	/**
	 * Adds the "why is this disabled" notice to a widget's panel, when its
	 * surface is gated. Call right after opening the first Content section so
	 * it leads the tab.
	 *
	 * @param \Elementor\Widget_Base $widget  Widget being registered.
	 * @param string                  $surface Surface id.
	 * @return void
	 */
	public static function add_gate_notice( \Elementor\Widget_Base $widget, string $surface ): void {
		if ( ! self::surface_gated( $surface ) ) {
			return;
		}

		$widget->add_control(
			'agend_surface_gate_notice',
			array(
				'type'            => \Elementor\Controls_Manager::RAW_HTML,
				'raw'             => esc_html( agend_apps_records_surface_notice( $surface ) ),
				'content_classes' => 'elementor-panel-alert elementor-panel-alert-warning',
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
