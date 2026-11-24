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
 * The bulk user sync helper sends `provision` only when asked, so a site that
 * does not provision sends exactly what it sent before.
 *
 * Separate processes: loading the real helpers defines the global functions
 * Loop Sync's suite replaces with doubles, which would then never load.
 */
#[RunTestsInSeparateProcesses]
final class LoopUserSyncProvisionTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-api.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-key-scopes.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-endpoint-scopes.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/loop-integration.php';
	}

	private function ok(): void {
		Agend_Test_WP::queue_response(
			200,
			array(
				'success' => true,
				'data'    => array(
					'total'     => 1,
					'succeeded' => 1,
					'failed'    => 0,
					'results'   => array(),
				),
			)
		);
	}

	/**
	 * @return array<string, mixed> The decoded body of the only request sent.
	 */
	private function sent_body(): array {
		$this->assertCount( 1, Agend_Test_WP::$requests );
		$this->assertStringEndsWith( '/loop/integration/users/bulk-sync', Agend_Test_WP::$requests[0]['url'] );

		return (array) json_decode( (string) Agend_Test_WP::$requests[0]['body'], true );
	}

	private function users(): array {
		return array(
			array(
				'external_id' => 1001,
				'email'       => 'member@example.com',
				'roles'       => array(),
			),
		);
	}

	#[Test]
	public function without_the_argument_no_provision_field_is_sent(): void {
		$this->ok();

		agend_apps_loop_bulk_sync_users( $this->users(), 'https://idp.example.com/saml/metadata' );

		$body = $this->sent_body();
		$this->assertArrayNotHasKey( 'provision', $body );
		$this->assertSame( 'https://idp.example.com/saml/metadata', $body['idp_entity_id'] );
		$this->assertSame( 1001, $body['users'][0]['external_id'] );
	}

	#[Test]
	public function with_it_provision_true_is_sent_beside_the_users(): void {
		$this->ok();

		agend_apps_loop_bulk_sync_users( $this->users(), 'https://idp.example.com/saml/metadata', true );

		$this->assertTrue( $this->sent_body()['provision'] );
	}

	#[Test]
	public function the_args_filter_still_sees_the_whole_body(): void {
		$this->ok();
		$seen = null;
		add_filter(
			'agend_apps_loop_bulk_sync_users_args',
			static function ( $args ) use ( &$seen ) {
				$seen = $args;
				return $args;
			}
		);

		agend_apps_loop_bulk_sync_users( $this->users(), 'https://idp.example.com/saml/metadata', true );

		$this->assertTrue( $seen['body']['provision'] );
		$this->assertCount( 1, $seen['body']['users'] );
	}
}
