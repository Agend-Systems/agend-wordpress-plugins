<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace {
	if ( ! class_exists( 'WooCommerce' ) ) {
		class WooCommerce {}
	}

	if ( ! function_exists( 'WC' ) ) {
		/** The test's WooCommerce: `$GLOBALS['agend_test_wc']`, an object with a `cart`. */
		function WC() {
			return $GLOBALS['agend_test_wc'];
		}
	}

	if ( ! function_exists( 'wc_get_checkout_url' ) ) {
		function wc_get_checkout_url() {
			return 'https://example.test/checkout/';
		}
	}

	if ( ! function_exists( 'wc_get_cart_url' ) ) {
		function wc_get_cart_url() {
			return 'https://example.test/cart/';
		}
	}

	if ( ! function_exists( 'wc_get_account_endpoint_url' ) ) {
		function wc_get_account_endpoint_url( $endpoint ) {
			return 'https://example.test/my-account/' . $endpoint . '/';
		}
	}

	if ( ! function_exists( 'wc_get_product' ) ) {
		/** Products from `$GLOBALS['agend_test_wc_products']` (id => object). */
		function wc_get_product( $id ) {
			return $GLOBALS['agend_test_wc_products'][ (int) $id ] ?? false;
		}
	}
}

namespace Agend\Tests\Core {

	use Agend_Apps_Jobs_Credit_Provider;
	use Agend\Tests\TestCase;
	use PHPUnit\Framework\Attributes\Test;

	require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/jobs-credits.php';
	require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/my-account-jobs.php';

	/**
	 * My Account > Jobs: offered only with job credits on, placed after
	 * Orders, and its Buy buttons sell only the products chosen in settings.
	 */
	final class MyAccountJobsTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();
			$GLOBALS['agend_test_wc_products'] = array();
		}

		private function credits_on(): void {
			add_filter(
				'agend_apps_jobs_credit_provider',
				static function () {
					return new class() implements Agend_Apps_Jobs_Credit_Provider {
						public function get_key(): string {
							return 'fake';
						}
						public function is_available(): bool {
							return true;
						}
						public function get_available_credits( int $user_id ) {
							return array();
						}
						public function spend( int $user_id, string $ref ) {
							return true;
						}
						public function restore( int $user_id, string $ref ) {
							return true;
						}
					};
				}
			);
		}

		private function product( int $id, string $name, bool $purchasable = true ): void {
			$GLOBALS['agend_test_wc_products'][ $id ] = new class( $name, $purchasable ) {
				private $name;
				private $purchasable;
				public function __construct( string $name, bool $purchasable ) {
					$this->name        = $name;
					$this->purchasable = $purchasable;
				}
				public function get_name(): string {
					return $this->name;
				}
				public function is_purchasable(): bool {
					return $this->purchasable;
				}
			};
		}

		/** A cart holding `$ids`, recording each add; `$refuse` makes add_to_cart fail. */
		private function cart( array $ids = array(), bool $refuse = false ) {
			$cart = new class( $ids, $refuse ) {
				public $added = array();
				private $ids;
				private $refuse;
				public function __construct( array $ids, bool $refuse ) {
					$this->ids    = $ids;
					$this->refuse = $refuse;
				}
				public function get_cart() {
					return array_map(
						static function ( $id ) {
							return array( 'product_id' => $id );
						},
						$this->ids
					);
				}
				public function add_to_cart( $id, $qty ) {
					if ( $this->refuse ) {
						return false;
					}
					$this->added[] = $id;
					return 'key';
				}
			};
			$GLOBALS['agend_test_wc'] = (object) array( 'cart' => $cart );
			return $cart;
		}

		private function sell( int ...$ids ): void {
			update_option(
				AGEND_APPS_MEMBER_JOBS_BUY_OPTION,
				array_map(
					static function ( $id ) {
						return array( 'product_id' => $id, 'label' => '' );
					},
					$ids
				)
			);
		}

		#[Test]
		public function only_a_configured_product_is_bought(): void {
			$this->sell( 11 );
			wp_set_current_user( 7 );
			$cart = $this->cart();

			$this->assertSame( '', agend_apps_member_jobs_buy( 99 ) );
			$this->assertSame( 'https://example.test/checkout/', agend_apps_member_jobs_buy( 11 ) );
			$this->assertSame( array( 11 ), $cart->added );
		}

		#[Test]
		public function a_signed_out_visitor_signs_in_first_and_nothing_is_added(): void {
			$this->sell( 11 );
			wp_set_current_user( 0 );
			$cart = $this->cart();

			$url = agend_apps_member_jobs_buy( 11 );

			$this->assertStringContainsString( 'wp-login.php', $url );
			$this->assertStringContainsString( AGEND_APPS_MEMBER_JOBS_BUY_ARG, $url, 'returns to the buy link after sign-in' );
			$this->assertSame( array(), $cart->added );
		}

		#[Test]
		public function a_product_already_in_the_cart_is_not_added_twice(): void {
			$this->sell( 11 );
			wp_set_current_user( 7 );
			$cart = $this->cart( array( 11 ) );

			$this->assertSame( 'https://example.test/checkout/', agend_apps_member_jobs_buy( 11 ) );
			$this->assertSame( array(), $cart->added );
		}

		#[Test]
		public function woocommerces_add_to_cart_checks_apply_and_a_refusal_goes_back_to_my_jobs(): void {
			$this->credits_on();
			$this->sell( 11 );
			wp_set_current_user( 7 );
			$cart = $this->cart();
			add_filter(
				'woocommerce_add_to_cart_validation',
				static function () {
					return false;
				}
			);

			$this->assertSame( 'https://example.test/my-account/my-jobs/', agend_apps_member_jobs_buy( 11 ) );
			$this->assertSame( array(), $cart->added );
		}

		#[Test]
		public function a_failed_add_goes_back_to_my_jobs_not_to_checkout(): void {
			$this->credits_on();
			$this->sell( 11 );
			wp_set_current_user( 7 );
			$this->cart( array(), true );

			$this->assertSame( 'https://example.test/my-account/my-jobs/', agend_apps_member_jobs_buy( 11 ) );
		}

		#[Test]
		public function nothing_changes_without_job_credits(): void {
			$items = array(
				'dashboard'       => 'Dashboard',
				'orders'          => 'Orders',
				'customer-logout' => 'Log out',
			);

			$this->assertSame( $items, agend_apps_my_account_jobs_menu_item( $items ) );
		}

		#[Test]
		public function jobs_sits_after_orders(): void {
			$this->credits_on();

			$menu = agend_apps_my_account_jobs_menu_item(
				array(
					'dashboard'       => 'Dashboard',
					'orders'          => 'Orders',
					'edit-account'    => 'Account details',
					'customer-logout' => 'Log out',
				)
			);

			$this->assertSame( array( 'dashboard', 'orders', 'my-jobs', 'edit-account', 'customer-logout' ), array_keys( $menu ) );
		}

		#[Test]
		public function without_orders_jobs_sits_before_log_out(): void {
			$this->credits_on();

			$menu = agend_apps_my_account_jobs_menu_item(
				array(
					'dashboard'       => 'Dashboard',
					'customer-logout' => 'Log out',
				)
			);

			$this->assertSame( array( 'dashboard', 'my-jobs', 'customer-logout' ), array_keys( $menu ) );
		}

		#[Test]
		public function the_buy_setting_keeps_three_distinct_products_in_order(): void {
			$this->assertSame(
				array(
					array(
						'product_id' => 12,
						'label'      => 'Buy a 5 pack',
					),
					array(
						'product_id' => 11,
						'label'      => '',
					),
					array(
						'product_id' => 14,
						'label'      => 'Ten',
					),
				),
				agend_apps_member_jobs_sanitize_buy_products(
					array(
						array( 'product_id' => '12', 'label' => ' Buy a 5 pack <b>' ),
						array( 'product_id' => '0', 'label' => 'none' ),
						array( 'product_id' => '11' ),
						array( 'product_id' => '12', 'label' => 'again' ),
						array( 'product_id' => '14', 'label' => 'Ten' ),
						array( 'product_id' => '15', 'label' => 'fourth' ),
					)
				)
			);
		}

		#[Test]
		public function buy_buttons_name_the_product_and_skip_one_that_cannot_be_bought(): void {
			$this->product( 11, 'Jobs Board Credits' );
			$this->product( 12, 'Jobs Board Credits 5 Pack' );
			$this->product( 13, 'Retired pack', false );
			update_option(
				AGEND_APPS_MEMBER_JOBS_BUY_OPTION,
				array(
					array( 'product_id' => 11, 'label' => 'Buy 1 credit' ),
					array( 'product_id' => 13, 'label' => '' ),
					array( 'product_id' => 12, 'label' => '' ),
				)
			);

			$options = agend_apps_member_jobs_buy_options();

			$this->assertSame( array( 'Buy 1 credit', 'Buy Jobs Board Credits 5 Pack' ), array_column( $options, 'label' ) );
			$this->assertStringContainsString( AGEND_APPS_MEMBER_JOBS_BUY_ARG . '=11', $options[0]['url'] );
		}
	}
}
