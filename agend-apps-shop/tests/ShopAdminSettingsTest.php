<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Shop;

use Agend\Tests\TestCase;
use Agend_Apps_Shop_Admin;
use Agend_Test_WP;
use PHPUnit\Framework\Attributes\Test;

/**
 * The Checkout section of the Agend Apps Shop settings: Guest checkout
 * (sign-in required by default) and Pay on Invoice (on by default).
 */
final class ShopAdminSettingsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/agend-apps-shop/includes/checkout-settings.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-shop/admin/class-agend-apps-shop-admin.php';
	}

	private function render( string $method ): string {
		ob_start();
		( new Agend_Apps_Shop_Admin() )->$method();
		return (string) ob_get_clean();
	}

	#[Test]
	public function should_add_both_checkout_fields_to_the_checkout_section(): void {
		( new Agend_Apps_Shop_Admin() )->register_settings();
		$fields = Agend_Test_WP::$settings_sections[ Agend_Apps_Shop_Admin::PAGE_SLUG ]['agend_apps_shop_checkout_section']['fields'];
		self::assertSame( array( 'agend_apps_shop_guest_checkout', 'agend_apps_shop_pay_on_invoice', 'agend_apps_shop_discount_codes' ), array_keys( $fields ) );
		self::assertSame( 'Guest checkout', $fields['agend_apps_shop_guest_checkout']['title'] );
		self::assertSame( 'Pay on Invoice', $fields['agend_apps_shop_pay_on_invoice']['title'] );
		self::assertSame( 'Discount codes', $fields['agend_apps_shop_discount_codes']['title'] );
	}

	#[Test]
	public function should_require_sign_in_when_the_guest_setting_was_never_saved(): void {
		$html = $this->render( 'render_guest_checkout_field' );
		self::assertStringContainsString( 'value="require" checked=\'checked\'', $html );
		self::assertStringContainsString( 'Require sign-in to check out', $html );
		self::assertStringContainsString( 'Allow guests to check out with a name and email', $html );
		self::assertFalse( agend_apps_shop_guest_checkout_allowed() );
	}

	#[Test]
	public function should_allow_guests_only_for_the_explicit_choice(): void {
		$admin = new Agend_Apps_Shop_Admin();
		self::assertSame( 'allow', $admin->sanitize_guest_checkout( 'allow' ) );
		self::assertSame( 'require', $admin->sanitize_guest_checkout( 'everyone' ) );
		self::assertSame( 'require', $admin->sanitize_guest_checkout( null ) );
		update_option( 'agend_apps_shop_guest_checkout', 'allow' );
		self::assertTrue( agend_apps_shop_guest_checkout_allowed() );
	}

	#[Test]
	public function should_offer_pay_on_invoice_when_the_setting_was_never_saved(): void {
		$html = $this->render( 'render_pay_on_invoice_field' );
		self::assertStringContainsString( 'name="agend_apps_shop_pay_on_invoice" value="1" checked=\'checked\'', $html );
		self::assertStringContainsString( 'Offer Pay on Invoice when the organisation allows it', $html );
		self::assertTrue( agend_apps_shop_pay_on_invoice_enabled() );
	}

	#[Test]
	public function should_store_an_unticked_pay_on_invoice_box_as_off(): void {
		$admin = new Agend_Apps_Shop_Admin();
		self::assertSame( '1', $admin->sanitize_pay_on_invoice( '1' ) );
		self::assertSame( '0', $admin->sanitize_pay_on_invoice( '0' ) );
		self::assertSame( '0', $admin->sanitize_pay_on_invoice( null ) );
		update_option( 'agend_apps_shop_pay_on_invoice', '0' );
		self::assertFalse( agend_apps_shop_pay_on_invoice_enabled() );
		self::assertStringNotContainsString( 'checked', $this->render( 'render_pay_on_invoice_field' ) );
	}

	#[\PHPUnit\Framework\Attributes\Test]
	public function should_leave_discount_codes_off_until_a_site_turns_them_on(): void {
		require_once dirname( __DIR__ ) . '/includes/checkout-settings.php';
		self::assertFalse( agend_apps_shop_discount_codes_enabled() );
		update_option( 'agend_apps_shop_discount_codes', '1' );
		self::assertTrue( agend_apps_shop_discount_codes_enabled() );
	}
}
