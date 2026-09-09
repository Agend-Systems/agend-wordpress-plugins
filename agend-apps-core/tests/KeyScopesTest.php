<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use Agend_Apps_Key_Scopes;
use Agend_Test_WP;
use PHPUnit\Framework\Attributes\Test;
use WP_Error;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/health.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-key-scopes.php';

/**
 * `Agend_Apps_Key_Scopes`: the cached record of which gateway scopes the
 * connected API key holds (SPEC-CORE-20260908 scope-gated features), backing
 * the optional-feature registry.
 */
final class KeyScopesTest extends TestCase {

	/**
	 * Queues the next `GET /v1/health` call to return the given scopes.
	 *
	 * @param string[] $scopes Scopes the response reports.
	 */
	private function queue_scopes( array $scopes ): void {
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'scopes' => $scopes ) ) );
	}

	#[Test]
	public function should_report_unknown_before_the_first_fetch(): void {
		$this->assertFalse( Agend_Apps_Key_Scopes::known() );
	}

	#[Test]
	public function should_store_the_scopes_a_refresh_fetches(): void {
		$this->queue_scopes( array( 'directory.achievements.browse', 'directory.reviews.manage' ) );

		$result = Agend_Apps_Key_Scopes::refresh();

		$this->assertSame( array( 'directory.achievements.browse', 'directory.reviews.manage' ), $result );
		$this->assertTrue( Agend_Apps_Key_Scopes::known() );
		$this->assertSame( array( 'directory.achievements.browse', 'directory.reviews.manage' ), Agend_Apps_Key_Scopes::all() );
	}

	#[Test]
	public function has_should_be_true_only_when_every_scope_is_held(): void {
		$this->queue_scopes( array( 'directory.achievements.browse' ) );
		Agend_Apps_Key_Scopes::refresh();

		$this->assertTrue( Agend_Apps_Key_Scopes::has( 'directory.achievements.browse' ) );
		$this->assertFalse( Agend_Apps_Key_Scopes::has( 'directory.reviews.manage' ) );
		$this->assertFalse( Agend_Apps_Key_Scopes::has( 'directory.achievements.browse', 'directory.reviews.manage' ) );
	}

	#[Test]
	public function has_should_be_true_for_an_empty_scope_list(): void {
		$this->assertTrue( Agend_Apps_Key_Scopes::has() );
	}

	#[Test]
	public function all_should_trigger_a_lazy_refresh_when_never_fetched(): void {
		$this->queue_scopes( array( 'sso.identities.read' ) );

		$scopes = Agend_Apps_Key_Scopes::all();

		$this->assertSame( array( 'sso.identities.read' ), $scopes );
		$this->assertTrue( Agend_Apps_Key_Scopes::known() );
	}

	#[Test]
	public function maybe_refresh_should_do_nothing_when_the_cache_is_fresh_and_unchanged(): void {
		$this->queue_scopes( array( 'directory.achievements.browse' ) );
		Agend_Apps_Key_Scopes::refresh();

		$before = count( Agend_Test_WP::$requests );

		Agend_Apps_Key_Scopes::maybe_refresh();

		$this->assertCount( $before, Agend_Test_WP::$requests );
		$this->assertSame( array( 'directory.achievements.browse' ), Agend_Apps_Key_Scopes::all() );
	}

	#[Test]
	public function a_changed_environment_should_trigger_a_refresh(): void {
		update_option( 'agend_apps_environment', 'production' );
		$this->queue_scopes( array( 'directory.achievements.browse' ) );
		Agend_Apps_Key_Scopes::refresh();

		$this->assertTrue( Agend_Apps_Key_Scopes::known() );

		// The key hash is derived from the key + environment; changing the
		// environment option alone is enough to invalidate it, without a
		// fresh fetch happening yet.
		update_option( 'agend_apps_environment', 'staging' );

		$this->assertFalse( Agend_Apps_Key_Scopes::known() );

		$this->queue_scopes( array( 'directory.reviews.manage' ) );
		Agend_Apps_Key_Scopes::maybe_refresh();

		$this->assertTrue( Agend_Apps_Key_Scopes::known() );
		$this->assertSame( array( 'directory.reviews.manage' ), Agend_Apps_Key_Scopes::all() );
	}

	#[Test]
	public function a_failed_refresh_should_keep_the_stale_value(): void {
		$this->queue_scopes( array( 'directory.achievements.browse' ) );
		Agend_Apps_Key_Scopes::refresh();

		Agend_Test_WP::queue_response(
			500,
			array(
				'error' => array(
					'code'    => 'SERVER_ERROR',
					'message' => 'boom',
				),
			)
		);

		$result = Agend_Apps_Key_Scopes::refresh();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( array( 'directory.achievements.browse' ), Agend_Apps_Key_Scopes::all() );
	}

	#[Test]
	public function store_from_response_should_cache_scopes_without_a_second_gateway_call(): void {
		$before = count( Agend_Test_WP::$requests );

		$scopes = Agend_Apps_Key_Scopes::store_from_response(
			array( 'data' => array( 'scopes' => array( 'directory.export_reports.browse' ) ) )
		);

		$this->assertSame( array( 'directory.export_reports.browse' ), $scopes );
		$this->assertSame( array( 'directory.export_reports.browse' ), Agend_Apps_Key_Scopes::all() );
		// all() itself performs a lazy maybe_refresh(), but the cache is now
		// fresh and current, so it should not have made a gateway call.
		$this->assertCount( $before, Agend_Test_WP::$requests );
	}
}
