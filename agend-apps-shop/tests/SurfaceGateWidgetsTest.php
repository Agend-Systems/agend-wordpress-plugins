<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Shop;

use Agend_Apps_Key_Scopes;
use Agend_Apps_Shop_Add_To_Cart;
use Agend_Apps_Shop_Cart_Header;
use Agend_Apps_Shop_Cart_View;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/health.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-key-scopes.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/features.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/surface-gate.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-shop/includes/class-agend-apps-shop-elementor.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-shop/includes/records/render/add-to-cart.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-shop/includes/records/render/cart-header.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-shop/includes/records/render/cart-view.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-shop/includes/widgets/class-agend-apps-shop-add-to-cart.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-shop/includes/widgets/class-agend-apps-shop-cart-header.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-shop/includes/widgets/class-agend-apps-shop-cart-view.php';

/**
 * The cart widgets stay in the panel and canvas while the API key lacks their
 * scopes, draw nothing for a visitor, and load no script.
 */
final class SurfaceGateWidgetsTest extends TestCase {

	/** Widget class, surface, the scope it needs, and the handle it loads. */
	private const WIDGETS = array(
		array( Agend_Apps_Shop_Add_To_Cart::class, 'add-to-cart', 'cart.items.manage', 'agend-apps-shop-add-to-cart' ),
		array( Agend_Apps_Shop_Cart_Header::class, 'cart-header', 'cart.items.browse', 'agend-apps-shop-cart-header' ),
		array( Agend_Apps_Shop_Cart_View::class, 'cart-view', 'cart.items.browse', 'agend-apps-shop-cart-view' ),
	);

	private function know( array $scopes ): void {
		Agend_Apps_Key_Scopes::store_from_response( array( 'data' => array( 'scopes' => $scopes ) ) );
	}

	private function render( string $class ): string {
		$widget = new $class( array(), null, array() );

		ob_start();
		( function () {
			$this->render();
		} )->call( $widget );

		return (string) ob_get_clean();
	}

	#[Test]
	public function should_render_as_normal_when_the_key_holds_the_scopes_or_is_unknown(): void {
		foreach ( self::WIDGETS as list( $class, , , $handle ) ) {
			self::assertNotSame( '', $this->render( $class ), $class . ' unknown' );
			self::assertSame( array( $handle ), ( new $class() )->get_script_depends() );
		}

		$this->know( array( 'cart.items.browse', 'cart.items.manage' ) );

		foreach ( self::WIDGETS as list( $class ) ) {
			self::assertStringNotContainsString( 'agend-surface-gate', $this->render( $class ), $class );
		}
	}

	#[Test]
	public function should_render_nothing_for_a_visitor_and_load_no_assets_when_gated(): void {
		$this->know( array( 'crm.contacts.browse' ) );

		foreach ( self::WIDGETS as list( $class ) ) {
			self::assertSame( '', $this->render( $class ), $class );
			self::assertSame( array(), ( new $class() )->get_script_depends(), $class );
			self::assertSame( array(), ( new $class() )->get_style_depends(), $class );
		}

		self::assertSame( '', agend_apps_records_render_cart_view( array() ), 'the core renderer applies the same gate to a block' );
		self::assertCount( 0, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_render_a_disabled_box_naming_the_scope_in_the_editor(): void {
		$this->know( array( 'crm.contacts.browse' ) );
		\Elementor\Plugin::$instance->editor->is_edit_mode = true;

		foreach ( self::WIDGETS as list( $class, , $scope ) ) {
			$html = $this->render( $class );

			self::assertStringContainsString( 'agend-surface-gate', $html, $class );
			self::assertStringContainsString( $scope, $html, $class );
		}
	}

	#[Test]
	public function should_lead_the_panel_with_the_notice_only_while_gated(): void {
		foreach ( self::WIDGETS as list( $class ) ) {
			$widget = new $class();
			( function () {
				$this->register_controls();
			} )->call( $widget );

			self::assertNotContains( 'agend_surface_gate_notice', array_column( $widget->recordings, 'id' ), $class . ' open' );
		}

		$this->know( array( 'crm.contacts.browse' ) );

		foreach ( self::WIDGETS as list( $class, $surface ) ) {
			$widget = new $class();
			( function () {
				$this->register_controls();
			} )->call( $widget );

			$controls = array_values( array_filter( $widget->recordings, static fn( array $r ): bool => 'add_control' === $r['method'] ) );

			self::assertSame( 'agend_surface_gate_notice', $controls[0]['id'], $class );
			self::assertSame( agend_apps_records_surface_notice( $surface ), html_entity_decode( $controls[0]['args']['raw'] ) );
		}
	}

	#[Test]
	public function should_gate_the_cart_view_on_both_of_its_scopes(): void {
		$this->know( array( 'cart.items.browse' ) );

		self::assertSame( '', $this->render( Agend_Apps_Shop_Cart_View::class ) );
		self::assertNotSame( '', $this->render( Agend_Apps_Shop_Cart_Header::class ), 'the header only needs browse' );
	}
}
