<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace {
	if ( ! class_exists( 'WooCommerce' ) ) {
		class WooCommerce {}
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

			$this->assertSame( array( 'dashboard', 'orders', 'jobs', 'edit-account', 'customer-logout' ), array_keys( $menu ) );
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

			$this->assertSame( array( 'dashboard', 'jobs', 'customer-logout' ), array_keys( $menu ) );
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
