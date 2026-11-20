<?php
/** @package Agend\Tests */
declare( strict_types=1 );

namespace Agend\Tests\Shop\AssetUrls;

use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

// Namespace-local doubles exercise the real registration function without
// loading the plugin entrypoint's unrelated bootstrap hooks and constants.
const AGEND_APPS_SHOP_URL = 'https://example.test/plugins/agend-apps-shop/';
const AGEND_APPS_SHOP_VERSION = 'test';
function wp_register_script( ...$args ): void {}
function wp_register_style( ...$args ): void {}
function wp_localize_script( $handle, $name, $data ): void {
	$GLOBALS['agend_test_shop_localized'][ $handle ][ $name ] = $data;
}

final class AssetUrlsTest extends TestCase {
	#[Test]
	public function should_preserve_query_separators_in_every_localized_shop_url(): void {
		// The localized data also carries the checkout settings, read through
		// these helpers.
		require_once AGEND_TESTS_ROOT . '/agend-apps-shop/includes/checkout-settings.php';
		$source = (string) file_get_contents( AGEND_TESTS_ROOT . '/agend-apps-shop/agend-apps-shop.php' );
		self::assertSame( 1, preg_match( '/^function agend_apps_shop_register_assets\(.*?^}/ms', $source, $match ) );
		if ( ! function_exists( __NAMESPACE__ . '\\agend_apps_shop_register_assets' ) ) {
			// Same source-extraction approach as CartRoutesTest: isolate the
			// function under test from plugin bootstrapping.
			eval( 'namespace ' . __NAMESPACE__ . ';' . $match[0] );
		}
		$urls = array(
			'continueShoppingUrl' => 'continue_shopping',
			'cartPageUrl' => 'cart_page',
			'checkoutSuccessUrl' => 'checkout_success',
			'checkoutCancelUrl' => 'checkout_cancel',
		);
		$expected = array();
		foreach ( $urls as $key => $option ) {
			$url = 'https://example.test/?page_id=8&lang=en&destination=' . $option;
			update_option( 'agend_apps_shop_' . $option . '_url', $url );
			$expected[ $key ] = $url;
		}
		$GLOBALS['agend_test_shop_localized'] = array();
		agend_apps_shop_register_assets();
		foreach ( array( 'agend-apps-shop-cart-view', 'agend-apps-shop-cart-header' ) as $handle ) {
			self::assertSame( $expected, array_intersect_key( $GLOBALS['agend_test_shop_localized'][ $handle ]['agendAppsShop'], $expected ) );
		}
	}
}
