<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend_Apps_Cache;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/member-transient-purge.php';

/**
 * The first request after the update removes every transient an earlier
 * version stored for a read carrying a member token, and never one a visitor
 * without a token shares.
 */
final class MemberEntryPurgeTest extends TestCase {

	private function wpdb(): \wpdb {
		return $GLOBALS['wpdb'];
	}

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb()->queries       = array();
		$this->wpdb()->query_results = array();
	}

	private function isMemberEntry( string $option_name ): bool {
		return 1 === preg_match( '/' . AGEND_APPS_MEMBER_TRANSIENT_PATTERN . '/', $option_name );
	}

	#[Test]
	public function should_match_the_rows_a_member_read_was_stored_under(): void {
		$member = Agend_Apps_Cache::build_key( 'directory_search', array( 'q' => '' ) ) . '_b' . substr( hash( 'sha256', 'member-token' ), 0, 16 );

		$this->assertTrue( $this->isMemberEntry( '_transient_agend_apps_' . $member ) );
		$this->assertTrue( $this->isMemberEntry( '_transient_timeout_agend_apps_' . $member ) );
	}

	#[Test]
	public function should_not_match_a_shared_entry_its_timeout_or_its_fetch_time(): void {
		$shared = Agend_Apps_Cache::build_key( 'directory_facets', array( 'fields' => 'tags' ) );

		$this->assertFalse( $this->isMemberEntry( '_transient_agend_apps_' . $shared ) );
		$this->assertFalse( $this->isMemberEntry( '_transient_timeout_agend_apps_' . $shared ) );
		$this->assertFalse( $this->isMemberEntry( '_transient_agend_apps_' . $shared . '_at' ) );
		$this->assertFalse( $this->isMemberEntry( '_transient_agend_apps_rate_limit_remaining' ) );
		$this->assertFalse( $this->isMemberEntry( '_transient_other_plugin_x_b0123456789abcdef' ) );
	}

	#[Test]
	public function should_delete_the_member_rows_once_and_record_it(): void {
		$this->wpdb()->query_results = array( 6 );

		agend_apps_maybe_purge_member_transients();
		agend_apps_maybe_purge_member_transients();

		$this->assertCount( 1, $this->wpdb()->queries );
		$this->assertStringStartsWith( 'DELETE FROM wp_options WHERE', $this->wpdb()->queries[0] );
		$this->assertStringContainsString( "REGEXP '" . addslashes( AGEND_APPS_MEMBER_TRANSIENT_PATTERN ) . "'", $this->wpdb()->queries[0] );
		$this->assertSame( '1', Agend_Test_WP::$options[ AGEND_APPS_MEMBER_TRANSIENTS_PURGED_OPTION ] ?? null );
	}

	#[Test]
	public function should_try_again_on_the_next_request_when_the_delete_fails(): void {
		$this->wpdb()->query_results = array( false, 0 );

		agend_apps_maybe_purge_member_transients();

		$this->assertArrayNotHasKey( AGEND_APPS_MEMBER_TRANSIENTS_PURGED_OPTION, Agend_Test_WP::$options );

		agend_apps_maybe_purge_member_transients();

		$this->assertCount( 2, $this->wpdb()->queries );
		$this->assertSame( '1', Agend_Test_WP::$options[ AGEND_APPS_MEMBER_TRANSIENTS_PURGED_OPTION ] ?? null );
	}
}
