<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Shop;

use Agend\Tests\TestCase;
use Agend_Apps_Shop_Cart_Header;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Pins the cart-header surface's markup at its schema/control defaults and at
 * a fully custom set of values, and proves the widget's render() (now a thin
 * delegate) produces exactly what agend_apps_records_render_cart_header()
 * produces directly, for both scenarios.
 *
 * `cart_icon` and `cart_page_url` are Elementor-shaped (an array) in the
 * `custom` scenario, matching what get_settings_for_display() actually hands
 * the widget; \Elementor\Icons_Manager is not stubbed for this unit suite (see
 * tests/elementor-stubs.php), so both scenarios exercise the same plain-SVG
 * icon fallback a block placement always uses.
 */
final class CartHeaderRenderTest extends TestCase {

	private const CUSTOM = array(
		'cart_icon'        => array(
			'value'   => 'fas fa-shopping-bag',
			'library' => 'fa-solid',
		),
		'cart_page_url'    => array(
			'url'         => 'https://example.test/my-cart/',
			'is_external' => '',
			'nofollow'    => '',
		),
		'badge_color'      => '#123456',
		'badge_text_color' => '#abcdef',
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
			agend_apps_records_render_cart_header( $this->settings( $scenario ) )
		);
	}

	#[Test]
	#[DataProvider( 'scenarios' )]
	public function should_render_the_recorded_markup_when_the_elementor_widget_delegates_to_core( string $scenario ): void {
		$widget = new Agend_Apps_Shop_Cart_Header( array(), null, $this->settings( $scenario ) );

		ob_start();
		( function () {
			$this->render();
		} )->call( $widget );

		self::assertSame( $this->expected( $scenario ), (string) ob_get_clean() );
	}

	/**
	 * `cart_page_url` is a `url` field, so Elementor hands the renderer
	 * array( 'url' => ... ) while a block stores a plain string. Both must
	 * resolve to the same link, or the cart icon on a block-built header would
	 * quietly fall back to the site setting and ignore what the author typed.
	 *
	 * This suite does not load core's format.php, so the renderer's local
	 * fallback branch is what runs here. Core's own normaliser is covered by
	 * MembershipsCatalogueUrlShapeTest in the agend-apps-core suite, and the
	 * two read the same two shapes by construction.
	 */
	#[Test]
	public function should_resolve_the_same_cart_link_from_either_url_shape(): void {
		$as_string = self::CUSTOM;
		unset( $as_string['cart_page_url'] );
		$as_string['cart_page_url'] = 'https://example.test/my-cart/';

		self::assertSame(
			agend_apps_records_render_cart_header( self::CUSTOM ),
			agend_apps_records_render_cart_header( $as_string )
		);
	}

	/**
	 * Sets the option inside the test rather than in setUp(): the two
	 * fixture-backed scenarios above were recorded with it unset, so seeding it
	 * for every test would rewrite their expected href.
	 */
	#[Test]
	public function should_fall_back_to_the_site_setting_when_no_cart_page_url_is_set(): void {
		update_option( 'agend_apps_shop_cart_page_url', 'https://example.test/shop-cart/' );

		$without = self::CUSTOM;
		unset( $without['cart_page_url'] );

		self::assertStringContainsString(
			'https://example.test/shop-cart/',
			agend_apps_records_render_cart_header( $without ),
			'an unset cart_page_url should use the agend_apps_shop_cart_page_url option'
		);
	}

	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/agend-apps-shop/includes/records/render/cart-header.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-shop/includes/widgets/class-agend-apps-shop-cart-header.php';
	}

	private function settings( string $scenario ): array {
		return 'custom' === $scenario ? self::CUSTOM : array();
	}

	private function expected( string $scenario ): string {
		$fixtures = json_decode(
			(string) file_get_contents( AGEND_TESTS_ROOT . '/agend-apps-shop/tests/fixtures/cart-header-render.json' ),
			true
		);

		return $fixtures[ $scenario ];
	}
}
