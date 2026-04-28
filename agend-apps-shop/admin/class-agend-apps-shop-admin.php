<?php
/**
 * Admin controller for Agend Apps Shop.
 *
 * @package Agend_Apps_Shop
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles all WordPress admin UI for Agend Apps Shop.
 *
 * Registers the Settings API fields and sections for cart and checkout URLs,
 * and adds a submenu under the existing Agend Apps settings group.
 */
class Agend_Apps_Shop_Admin {

	/**
	 * Settings page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'agend-apps-shop';

	/**
	 * Option group name used with `settings_fields()`.
	 *
	 * @var string
	 */
	const OPTION_GROUP = 'agend_apps_shop_settings';

	/**
	 * Registers all admin hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_submenu_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Adds the Agend Apps Shop submenu page under Settings.
	 */
	public function add_submenu_page(): void {
		add_options_page(
			__( 'Agend Apps Shop', 'agend-apps-shop' ),
			__( 'Agend Apps Shop', 'agend-apps-shop' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Registers all settings, sections, and fields via the Settings API.
	 */
	public function register_settings(): void {
		add_settings_section(
			'agend_apps_shop_urls_section',
			__( 'Cart & Checkout URLs', 'agend-apps-shop' ),
			array( $this, 'render_urls_section' ),
			self::PAGE_SLUG
		);

		register_setting(
			self::OPTION_GROUP,
			'agend_apps_shop_cart_page_url',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'esc_url_raw',
				'default'           => '',
			)
		);

		add_settings_field(
			'agend_apps_shop_cart_page_url',
			__( 'Cart Page URL', 'agend-apps-shop' ),
			array( $this, 'render_cart_page_url_field' ),
			self::PAGE_SLUG,
			'agend_apps_shop_urls_section'
		);

		register_setting(
			self::OPTION_GROUP,
			'agend_apps_shop_checkout_success_url',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'esc_url_raw',
				'default'           => '',
			)
		);

		add_settings_field(
			'agend_apps_shop_checkout_success_url',
			__( 'Checkout Success URL', 'agend-apps-shop' ),
			array( $this, 'render_checkout_success_url_field' ),
			self::PAGE_SLUG,
			'agend_apps_shop_urls_section'
		);

		register_setting(
			self::OPTION_GROUP,
			'agend_apps_shop_checkout_cancel_url',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_cancel_url' ),
				'default'           => '',
			)
		);

		add_settings_field(
			'agend_apps_shop_checkout_cancel_url',
			__( 'Checkout Cancel URL', 'agend-apps-shop' ),
			array( $this, 'render_checkout_cancel_url_field' ),
			self::PAGE_SLUG,
			'agend_apps_shop_urls_section'
		);
	}

	/**
	 * Sanitizes the cancel URL, falling back to the cart page URL if left blank.
	 *
	 * @param string $value Raw submitted value.
	 * @return string Sanitized URL.
	 */
	public function sanitize_cancel_url( string $value ): string {
		$value = esc_url_raw( $value );

		if ( '' === $value ) {
			$value = esc_url_raw( get_option( 'agend_apps_shop_cart_page_url', '' ) );
		}

		return $value;
	}

	/**
	 * Renders the settings page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		require_once AGEND_APPS_SHOP_DIR . 'admin/views/settings.php';
	}

	/**
	 * Renders the URLs section description.
	 */
	public function render_urls_section(): void {
		echo '<p>' . esc_html__( 'Configure the URLs used for cart navigation and checkout redirects.', 'agend-apps-shop' ) . '</p>';
	}

	/**
	 * Renders the Cart Page URL field.
	 */
	public function render_cart_page_url_field(): void {
		$value = get_option( 'agend_apps_shop_cart_page_url', '' );
		printf(
			'<input type="url" id="agend_apps_shop_cart_page_url" name="agend_apps_shop_cart_page_url" value="%s" class="regular-text" placeholder="https://example.com/cart" />',
			esc_attr( $value )
		);
		echo '<p class="description">' . esc_html__( 'Page users are sent to from the cart header icon and after a cancelled checkout.', 'agend-apps-shop' ) . '</p>';
	}

	/**
	 * Renders the Checkout Success URL field.
	 */
	public function render_checkout_success_url_field(): void {
		$value = get_option( 'agend_apps_shop_checkout_success_url', '' );
		printf(
			'<input type="url" id="agend_apps_shop_checkout_success_url" name="agend_apps_shop_checkout_success_url" value="%s" class="regular-text" placeholder="https://example.com/checkout/success" />',
			esc_attr( $value )
		);
		echo '<p class="description">' . esc_html__( 'Passed as the successUrl when initiating a checkout session.', 'agend-apps-shop' ) . '</p>';
	}

	/**
	 * Renders the Checkout Cancel URL field.
	 */
	public function render_checkout_cancel_url_field(): void {
		$value = get_option( 'agend_apps_shop_checkout_cancel_url', '' );
		printf(
			'<input type="url" id="agend_apps_shop_checkout_cancel_url" name="agend_apps_shop_checkout_cancel_url" value="%s" class="regular-text" placeholder="https://example.com/cart" />',
			esc_attr( $value )
		);
		echo '<p class="description">' . esc_html__( 'Passed as the cancelUrl. Defaults to Cart Page URL if left blank.', 'agend-apps-shop' ) . '</p>';
	}
}
