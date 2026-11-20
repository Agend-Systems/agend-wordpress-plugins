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
 * Registers the Settings API fields and sections for cart and checkout URLs
 * and the checkout options (guest checkout, Pay on Invoice), and adds a
 * submenu under the existing Agend Apps settings group.
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
	 * Guest checkout choices, stored value => label.
	 *
	 * `require` is the default: a visitor signs in as a member before checking
	 * out. Agend Apps Core's cart checkout route enforces the same setting.
	 *
	 * @return array<string, string>
	 */
	public static function guest_checkout_choices(): array {
		return array(
			'require' => __( 'Require sign-in to check out', 'agend-apps-shop' ),
			'allow'   => __( 'Allow guests to check out with a name and email', 'agend-apps-shop' ),
		);
	}

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
			'agend_apps_shop_continue_shopping_url',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'esc_url_raw',
				'default'           => '',
			)
		);

		add_settings_field(
			'agend_apps_shop_continue_shopping_url',
			__( 'Keep browsing URL', 'agend-apps-shop' ),
			array( $this, 'render_continue_shopping_url_field' ),
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

		add_settings_section(
			'agend_apps_shop_checkout_section',
			__( 'Checkout', 'agend-apps-shop' ),
			array( $this, 'render_checkout_section' ),
			self::PAGE_SLUG
		);

		register_setting(
			self::OPTION_GROUP,
			'agend_apps_shop_guest_checkout',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_guest_checkout' ),
				'default'           => 'require',
			)
		);

		add_settings_field(
			'agend_apps_shop_guest_checkout',
			__( 'Guest checkout', 'agend-apps-shop' ),
			array( $this, 'render_guest_checkout_field' ),
			self::PAGE_SLUG,
			'agend_apps_shop_checkout_section'
		);

		register_setting(
			self::OPTION_GROUP,
			'agend_apps_shop_pay_on_invoice',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_pay_on_invoice' ),
				'default'           => '1',
			)
		);

		add_settings_field(
			'agend_apps_shop_pay_on_invoice',
			__( 'Pay on Invoice', 'agend-apps-shop' ),
			array( $this, 'render_pay_on_invoice_field' ),
			self::PAGE_SLUG,
			'agend_apps_shop_checkout_section'
		);
	}

	/**
	 * Keeps the guest checkout setting to a known choice; anything else
	 * requires sign-in.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return string `require` or `allow`.
	 */
	public function sanitize_guest_checkout( $value ): string {
		$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';

		return array_key_exists( $value, self::guest_checkout_choices() ) ? $value : 'require';
	}

	/**
	 * Stores the Pay on Invoice checkbox as `1` or `0` (an unticked box
	 * submits nothing, which means off).
	 *
	 * @param mixed $value Raw submitted value.
	 * @return string `1` or `0`.
	 */
	public function sanitize_pay_on_invoice( $value ): string {
		return '1' === (string) $value ? '1' : '0';
	}

	/**
	 * Renders the Checkout section description.
	 */
	public function render_checkout_section(): void {
		echo '<p>' . esc_html__( 'Choose who can check out from the cart and how they can pay.', 'agend-apps-shop' ) . '</p>';
	}

	/**
	 * Renders the Guest checkout radio choice.
	 */
	public function render_guest_checkout_field(): void {
		$value = $this->sanitize_guest_checkout( get_option( 'agend_apps_shop_guest_checkout', 'require' ) );
		echo '<fieldset>';
		foreach ( self::guest_checkout_choices() as $choice => $label ) {
			printf(
				'<label><input type="radio" name="agend_apps_shop_guest_checkout" value="%s"%s /> %s</label><br />',
				esc_attr( $choice ),
				checked( $value, $choice, false ),
				esc_html( $label )
			);
		}
		echo '</fieldset>';
		echo '<p class="description">' . esc_html__( 'With sign-in required, visitors can still add to the cart, then sign in to pay. Guests pay by card only.', 'agend-apps-shop' ) . '</p>';
	}

	/**
	 * Renders the Pay on Invoice checkbox.
	 */
	public function render_pay_on_invoice_field(): void {
		printf(
			'<input type="hidden" name="agend_apps_shop_pay_on_invoice" value="0" /><label><input type="checkbox" id="agend_apps_shop_pay_on_invoice" name="agend_apps_shop_pay_on_invoice" value="1"%s /> %s</label>',
			checked( '0' !== (string) get_option( 'agend_apps_shop_pay_on_invoice', '1' ), true, false ),
			esc_html__( 'Offer Pay on Invoice when the organisation allows it', 'agend-apps-shop' )
		);
		echo '<p class="description">' . esc_html__( 'Signed-in members see Pay on Invoice only when your invoice settings in Agend allow it for them and for everything in their cart.', 'agend-apps-shop' ) . '</p>';
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

	/** Renders the optional browse destination for visitors who open the cart directly. */
	public function render_continue_shopping_url_field(): void {
		$value = get_option( 'agend_apps_shop_continue_shopping_url', '' );
		printf(
			'<input type="url" id="agend_apps_shop_continue_shopping_url" name="agend_apps_shop_continue_shopping_url" value="%s" class="regular-text" />',
			esc_attr( $value )
		);
		echo '<p class="description">' . esc_html__( 'Enter a page on this site for the Keep browsing link. Visitors who added event tickets can return to their event listing.', 'agend-apps-shop' ) . '</p>';
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
