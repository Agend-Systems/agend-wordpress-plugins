<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;

/**
 * Committee ids reach the gateway with their colons intact. The gateway reads
 * the id from the raw path without decoding it, so `rule:<slug>` sent as
 * `rule%3A<slug>` was never found and a removed rule's channel never archived.
 *
 * Separate processes: loading the real helpers defines the global functions
 * Loop Sync's suite replaces with doubles, which would then never load.
 */
#[RunTestsInSeparateProcesses]
final class LoopCommitteePathTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-api.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-key-scopes.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-endpoint-scopes.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/loop-integration.php';
	}

	private function ok( array $data = array() ): void {
		Agend_Test_WP::queue_response(
			200,
			array(
				'success' => true,
				'data'    => $data,
			)
		);
	}

	/**
	 * The path of the only request sent, without the base URL or query.
	 */
	private function sent_path(): string {
		$this->assertCount( 1, Agend_Test_WP::$requests );
		$path = (string) wp_parse_url( (string) Agend_Test_WP::$requests[0]['url'], PHP_URL_PATH );

		return substr( $path, (int) strpos( $path, '/loop/' ) );
	}

	#[Test]
	public function a_rule_channel_id_keeps_its_colon(): void {
		$this->assertSame( '/loop/integration/committees/rule:all-fellows', agend_apps_loop_committee_path( 'rule:all-fellows' ) );
		$this->assertSame( '/loop/integration/committees/rule:all-fellows/members', agend_apps_loop_committee_path( 'rule:all-fellows', '/members' ) );
	}

	#[Test]
	public function other_reserved_characters_are_still_encoded(): void {
		$this->assertSame( '/loop/integration/committees/a%2Fb%3Fc%23d%20e', agend_apps_loop_committee_path( 'a/b?c#d e' ) );
		$this->assertSame( '/loop/integration/committees/ABC123', agend_apps_loop_committee_path( 'ABC123' ) );
	}

	#[Test]
	public function archiving_a_rule_channel_sends_the_colon(): void {
		$this->ok( array( 'status' => 'archived' ) );

		agend_apps_loop_archive_committee( 'rule:provision-test' );

		$this->assertSame( 'DELETE', Agend_Test_WP::$requests[0]['method'] );
		$this->assertSame( '/loop/integration/committees/rule:provision-test', $this->sent_path() );
	}

	#[Test]
	public function reading_a_rule_channel_and_its_members_sends_the_colon(): void {
		$this->ok();
		agend_apps_loop_get_committee( 'rule:provision-test' );
		$this->assertSame( '/loop/integration/committees/rule:provision-test', $this->sent_path() );

		Agend_Test_WP::$requests = array();
		$this->ok( array( 'members' => array() ) );
		agend_apps_loop_get_committee_members( 'rule:provision-test', 'https://idp.example.com/saml/metadata' );
		$this->assertSame( '/loop/integration/committees/rule:provision-test/members', $this->sent_path() );
	}

	#[Test]
	public function setting_a_moderator_sends_the_colon(): void {
		$this->ok();

		agend_apps_loop_set_committee_moderator( 'rule:provision-test', 'moderator', 42 );

		$this->assertSame( '/loop/integration/committees/rule:provision-test/moderator', $this->sent_path() );
	}
}
