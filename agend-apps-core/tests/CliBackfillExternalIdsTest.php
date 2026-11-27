<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use Agend_Apps_Settings;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/identity.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-secret-store.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/connect-site.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-cli.php';

/**
 * `wp agend-apps backfill-external-ids`: its refusal
 * ({@see \agend_apps_cli_backfill_refusal()}) and its paged walk
 * ({@see \agend_apps_cli_backfill_external_ids()}), driven by an in-memory
 * page source in place of `WP_User_Query`.
 */
final class CliBackfillExternalIdsTest extends TestCase {

	/** @var array<int, array{0: int, 1: int}> Each page request, as [page, batch size]. */
	private array $page_requests = array();

	protected function setUp(): void {
		parent::setUp();
		$this->page_requests = array();
		update_option( 'agend_apps_member_auth_mode', Agend_Apps_Settings::MEMBER_AUTH_WORDPRESS );
	}

	#[Test]
	public function should_allow_the_backfill_when_in_wordpress_mode_with_no_meta_key_configured(): void {
		$this->assertSame( '', \agend_apps_cli_backfill_refusal() );
	}

	#[Test]
	public function should_refuse_when_in_credentials_mode(): void {
		update_option( 'agend_apps_member_auth_mode', Agend_Apps_Settings::MEMBER_AUTH_CREDENTIALS );

		$this->assertStringContainsString( 'not in WordPress account sign-in mode', \agend_apps_cli_backfill_refusal() );
	}

	#[Test]
	public function should_refuse_when_in_sso_mode(): void {
		update_option( 'agend_apps_member_auth_mode', Agend_Apps_Settings::MEMBER_AUTH_SSO );

		$this->assertStringContainsString( 'not in WordPress account sign-in mode', \agend_apps_cli_backfill_refusal() );
	}

	#[Test]
	public function should_refuse_when_a_meta_key_is_configured_in_wordpress_mode(): void {
		update_option( 'agend_apps_external_id_meta_key', 'member_number' );

		$this->assertStringContainsString( 'meta key is configured', \agend_apps_cli_backfill_refusal() );
	}

	#[Test]
	public function should_mint_for_users_without_one_and_count_existing_ones_when_run(): void {
		$GLOBALS['agend_test_user_meta'][2][ \AGEND_APPS_EXTERNAL_ID_META ] = 'existing-guid';

		$counts = \agend_apps_cli_backfill_external_ids( $this->pages( array( 1, 2, 3, 4, 5 ) ), false, 2 );

		$this->assertSame(
			array(
				'scanned'  => 5,
				'existing' => 1,
				'minted'   => 4,
				'failed'   => 0,
			),
			$counts
		);
		$this->assertSame( 'existing-guid', $GLOBALS['agend_test_user_meta'][2][ \AGEND_APPS_EXTERNAL_ID_META ] );

		foreach ( array( 1, 3, 4, 5 ) as $user_id ) {
			$this->assertMatchesRegularExpression( '/^[0-9a-f-]{36}$/', $GLOBALS['agend_test_user_meta'][ $user_id ][ \AGEND_APPS_EXTERNAL_ID_META ] );
		}
	}

	#[Test]
	public function should_request_pages_until_a_short_page_when_run(): void {
		\agend_apps_cli_backfill_external_ids( $this->pages( array( 1, 2, 3, 4, 5 ) ), false, 2 );

		$this->assertSame( array( array( 1, 2 ), array( 2, 2 ), array( 3, 2 ) ), $this->page_requests );
	}

	#[Test]
	public function should_stop_after_an_empty_page_when_the_user_count_fills_the_last_page(): void {
		$counts = \agend_apps_cli_backfill_external_ids( $this->pages( array( 1, 2, 3, 4 ) ), false, 2 );

		$this->assertSame( 4, $counts['scanned'] );
		$this->assertCount( 3, $this->page_requests );
	}

	#[Test]
	public function should_mint_nothing_and_change_no_value_when_run_a_second_time(): void {
		\agend_apps_cli_backfill_external_ids( $this->pages( array( 1, 2, 3 ) ), false, 2 );
		$after_first = $GLOBALS['agend_test_user_meta'];

		$counts = \agend_apps_cli_backfill_external_ids( $this->pages( array( 1, 2, 3 ) ), false, 2 );

		$this->assertSame( 0, $counts['minted'] );
		$this->assertSame( 3, $counts['existing'] );
		$this->assertSame( $after_first, $GLOBALS['agend_test_user_meta'] );
	}

	#[Test]
	public function should_write_nothing_and_count_would_mint_users_when_a_dry_run(): void {
		$GLOBALS['agend_test_user_meta'][2][ \AGEND_APPS_EXTERNAL_ID_META ] = 'existing-guid';
		$before = $GLOBALS['agend_test_user_meta'];

		$counts = \agend_apps_cli_backfill_external_ids( $this->pages( array( 1, 2, 3 ) ), true, 2 );

		$this->assertSame(
			array(
				'scanned'  => 3,
				'existing' => 1,
				'minted'   => 2,
				'failed'   => 0,
			),
			$counts
		);
		$this->assertSame( $before, $GLOBALS['agend_test_user_meta'] );
	}

	#[Test]
	public function should_count_a_non_positive_user_id_as_failed_when_run(): void {
		$counts = \agend_apps_cli_backfill_external_ids( $this->pages( array( 0, 1 ) ), false, 5 );

		$this->assertSame( 1, $counts['failed'] );
		$this->assertSame( 1, $counts['minted'] );
		$this->assertArrayNotHasKey( 0, $GLOBALS['agend_test_user_meta'] );
	}

	#[Test]
	public function should_count_a_user_resolving_on_the_default_member_number_key_as_existing_when_run(): void {
		$GLOBALS['agend_test_user_meta'][1]['imk_membership_number'] = 'M-1';

		$counts = \agend_apps_cli_backfill_external_ids( $this->pages( array( 1 ) ), false, 5 );

		$this->assertSame( 1, $counts['existing'] );
		$this->assertSame( 0, $counts['minted'] );
		$this->assertArrayNotHasKey( \AGEND_APPS_EXTERNAL_ID_META, $GLOBALS['agend_test_user_meta'][1] );
	}

	/**
	 * A page source over a fixed list of user ids, recording each request.
	 *
	 * @param int[] $user_ids Every user id, in order.
	 */
	private function pages( array $user_ids ): callable {
		return function ( int $page, int $batch_size ) use ( $user_ids ): array {
			$this->page_requests[] = array( $page, $batch_size );

			return array_slice( $user_ids, ( $page - 1 ) * $batch_size, $batch_size );
		};
	}
}
