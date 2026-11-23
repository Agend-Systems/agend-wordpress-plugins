<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Shop;

use Agend\Tests\TestCase;
use Agend_Apps_Shop_Cart_View;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;

/**
 * Pins the cart-view surface's markup, and proves the widget's render() (now
 * a thin delegate) produces exactly what agend_apps_records_render_cart_view()
 * produces directly.
 *
 * There is only one scenario here, unlike the other two cart surfaces: this
 * surface's only control is an info-only notice that stores no value (see
 * agend_apps_records_schema_cart_view()), so its render output never varies
 * by settings, and there is no "renders nothing" guard to cover either -- the
 * shell always renders; only its dynamic content (fetched by
 * assets/js/agend-apps-shop-cart-view.js) varies at runtime.
 */
final class CartViewRenderTest extends TestCase {

	#[Test]
	public function should_render_the_recorded_markup_when_core_renders_the_surface_directly(): void {
		self::assertSame( $this->expected(), agend_apps_records_render_cart_view( array() ) );
	}

	#[Test]
	public function should_render_the_recorded_markup_when_the_elementor_widget_delegates_to_core(): void {
		$widget = new Agend_Apps_Shop_Cart_View( array(), null, array() );

		ob_start();
		( function () {
			$this->render();
		} )->call( $widget );

		self::assertSame( $this->expected(), (string) ob_get_clean() );
	}

	#[Test]
	public function should_show_cancel_without_testing_controls_when_the_visitor_is_not_an_admin(): void {
		$html = agend_apps_records_render_cart_view( array() );
		self::assertStringNotContainsString( 'Testing only:', $html );
		self::assertStringNotContainsString( 'agend-shop-btn-complete-checkout', $html );
		self::assertStringContainsString( 'class="agend-shop-btn-cancel-checkout" hidden disabled', $html );
		self::assertStringContainsString( 'Cancel checkout and edit cart', $html );
	}

	#[Test]
	public function should_show_both_checkout_controls_when_the_visitor_is_an_admin(): void {
		$GLOBALS['agend_test_current_user_can']['manage_options'] = true;
		$html = agend_apps_records_render_cart_view( array() );
		self::assertStringContainsString( 'Testing only:', $html );
		self::assertStringContainsString( 'agend-shop-btn-complete-checkout', $html );
		self::assertStringContainsString( 'agend-shop-btn-cancel-checkout', $html );
	}

	#[Test]
	public function should_prompt_for_sign_in_without_buyer_fields_or_checkout_when_sign_in_is_required(): void {
		$_SERVER['REQUEST_URI'] = '/cart/?continue=%2Fevents%2F';
		try {
			$html = agend_apps_records_render_cart_view( array() );
		} finally {
			unset( $_SERVER['REQUEST_URI'] );
		}
		self::assertStringContainsString( 'data-member-signed-in="false"', $html );
		self::assertStringContainsString( 'Sign in to check out. Your cart will be kept.', $html );
		self::assertStringContainsString( 'class="agend-shop-cart-signin-link elementor-button" href="' . esc_url( wp_login_url( home_url( '/cart/?continue=%2Fevents%2F' ) ) ) . '"', $html );
		self::assertStringNotContainsString( 'agend-shop-buyer-name', $html );
		self::assertStringNotContainsString( 'agend-shop-btn-proceed-checkout', $html );
		self::assertStringNotContainsString( 'agend-shop-cart-payment', $html );
	}

	#[Test]
	#[RunInSeparateProcess]
	public function should_send_sign_in_to_the_event_checkout_sign_in_page_when_one_is_configured(): void {
		self::assertSame( wp_login_url( 'https://example.test/cart/' ), agend_apps_shop_cart_sign_in_url( 'https://example.test/cart/' ) );
		// The core helper is only defined when Agend Apps Core's events catalogue
		// is loaded; in credentials mode it returns the configured page.
		if ( ! function_exists( 'agend_apps_records_events_checkout_signin_url' ) ) {
			eval( 'function agend_apps_records_events_checkout_signin_url(): string { return "https://example.test/login/"; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
		}
		self::assertSame( 'https://example.test/login/?agend_cart_signin=1', agend_apps_shop_cart_sign_in_url( 'https://example.test/cart/' ) );
	}

	#[Test]
	public function should_show_required_buyer_fields_when_guests_may_check_out(): void {
		update_option( 'agend_apps_shop_guest_checkout', 'allow' );
		$html = agend_apps_records_render_cart_view( array() );
		self::assertStringContainsString( 'agend-shop-btn-proceed-checkout', $html );
		self::assertStringNotContainsString( 'agend-shop-cart-signin', $html );
		self::assertStringContainsString( 'data-member-signed-in="false"', $html );
		self::assertStringContainsString( 'Your name', $html );
		self::assertStringContainsString( 'Your email', $html );
		self::assertStringContainsString( 'maxlength="200" required', $html );
		self::assertStringContainsString( 'type="email" autocomplete="email" required', $html );
	}

	#[Test]
	public function should_hide_buyer_fields_when_the_visitor_has_a_member_bearer(): void {
		\Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'member-token' );
		$html = agend_apps_records_render_cart_view( array() );
		self::assertStringContainsString( 'data-member-signed-in="true"', $html );
		self::assertStringNotContainsString( 'agend-shop-buyer-name', $html );
		self::assertStringNotContainsString( 'agend-shop-buyer-email', $html );
		self::assertStringNotContainsString( 'agend-shop-cart-signin', $html );
		self::assertStringContainsString( '<div class="agend-shop-cart-payment" hidden></div>', $html );
		self::assertStringContainsString( '<div class="agend-shop-invoice-confirmation" tabindex="-1" hidden></div>', $html );
		self::assertStringContainsString( 'agend-shop-btn-proceed-checkout', $html );
	}

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['agend_test_current_user_can']['manage_options'] = false;
		require_once AGEND_TESTS_ROOT . '/agend-apps-shop/includes/checkout-settings.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-shop/includes/records/render/cart-view.php';
		// The widgets ask this loader class for the surface gate and for their category.
		require_once AGEND_TESTS_ROOT . '/agend-apps-shop/includes/class-agend-apps-shop-elementor.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-shop/includes/widgets/class-agend-apps-shop-cart-view.php';
	}

	private function expected(): string {
		$fixtures = json_decode(
			(string) file_get_contents( AGEND_TESTS_ROOT . '/agend-apps-shop/tests/fixtures/cart-view-render.json' ),
			true
		);

		return $fixtures['defaults'];
	}
}
