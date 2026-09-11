<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Shop;

use Agend\Tests\TestCase;
use Agend_Apps_Shop_Cart_View;
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

	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/agend-apps-shop/includes/records/render/cart-view.php';
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
