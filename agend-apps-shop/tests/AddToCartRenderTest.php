<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Shop;

use Agend\Tests\TestCase;
use Agend_Apps_Shop_Add_To_Cart;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Pins the add-to-cart surface's markup at its schema/control defaults and at
 * a fully custom set of values, and proves the widget's render() (now a thin
 * delegate) produces exactly what agend_apps_records_render_add_to_cart()
 * produces directly, for both scenarios.
 */
final class AddToCartRenderTest extends TestCase {

	private const CUSTOM = array(
		'product_type' => 'courses',
		'product_id'   => 'abc-123',
		'button_label' => 'Buy Now',
		'quantity'     => 3,
		'max_quantity' => 10,
	);

	/** @return array<string, array{string}> */
	public static function scenarios(): array {
		return array(
			'defaults' => array( 'defaults' ),
			'custom'   => array( 'custom' ),
		);
	}

	#[Test]
	#[DataProvider( 'scenarios' )]
	public function should_render_the_recorded_markup_when_core_renders_the_surface_directly( string $scenario ): void {
		self::assertSame(
			$this->expected( $scenario ),
			agend_apps_records_render_add_to_cart( $this->settings( $scenario ) )
		);
	}

	#[Test]
	#[DataProvider( 'scenarios' )]
	public function should_render_the_recorded_markup_when_the_elementor_widget_delegates_to_core( string $scenario ): void {
		$widget = new Agend_Apps_Shop_Add_To_Cart( array(), null, $this->settings( $scenario ) );

		ob_start();
		( function () {
			$this->render();
		} )->call( $widget );

		self::assertSame( $this->expected( $scenario ), (string) ob_get_clean() );
	}

	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/agend-apps-shop/includes/records/render/add-to-cart.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-shop/includes/widgets/class-agend-apps-shop-add-to-cart.php';
	}

	private function settings( string $scenario ): array {
		return 'custom' === $scenario ? self::CUSTOM : array();
	}

	private function expected( string $scenario ): string {
		$fixtures = json_decode(
			(string) file_get_contents( AGEND_TESTS_ROOT . '/agend-apps-shop/tests/fixtures/add-to-cart-render.json' ),
			true
		);

		return $fixtures[ $scenario ];
	}
}
