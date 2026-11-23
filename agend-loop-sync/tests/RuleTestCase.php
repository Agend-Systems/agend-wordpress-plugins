<?php
/**
 * Shared base for the community rule tests.
 *
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\LoopSync;

use Agend_Loop_Sync_Rule_Sync;
use Agend_Loop_Sync_Settings;
use Loop_Sync_Test_Member;
use Loop_Sync_Test_State;
use Loop_Sync_Test_Upbeat;

require_once __DIR__ . '/LoopSyncTestCase.php';

/**
 * Turns community rule sync on, and gives the tests short ways to add a rule, a
 * member and the Loop channels that already exist.
 */
abstract class RuleTestCase extends LoopSyncTestCase {

	protected function setUp(): void {
		parent::setUp();

		update_option( 'agend_loop_sync_rule_sync_enabled', '1' );
		Agend_Loop_Sync_Settings::sanitize_rules( array() );
	}

	protected function rule_sync(): Agend_Loop_Sync_Rule_Sync {
		return new Agend_Loop_Sync_Rule_Sync( new Loop_Sync_Test_Upbeat() );
	}

	/**
	 * Saves a rule and returns it. Enabled, with no criteria, unless overridden.
	 *
	 * @param string               $name      Rule name; the slug is made from it.
	 * @param array<string, mixed> $overrides Raw rule fields.
	 * @return array<string, mixed>
	 */
	protected function add_rule( string $name, array $overrides = array() ): array {
		$saved = Agend_Loop_Sync_Settings::save_rule( array_merge( array( 'name' => $name, 'enabled' => true ), $overrides ) );
		$this->assertIsArray( $saved, 'the test rule must save' );

		return $saved;
	}

	/**
	 * Registers a local member and their Upbeat record.
	 *
	 * @param array<int, object> $registrations Registrations; null for none set.
	 */
	protected function add_member( int $id, string $number, string $status = 'Financial', string $type = '', string $level = '', ?array $registrations = null ): void {
		loop_sync_test_user( $id, $number );
		Loop_Sync_Test_State::$upbeat_members[ $number ] = new Loop_Sync_Test_Member( $status, $type, $level );

		if ( null !== $registrations ) {
			Loop_Sync_Test_State::$upbeat_registrations[ $number ] = $registrations;
		}
	}

	/**
	 * A Loop channel as the list endpoint returns it.
	 *
	 * @return array<string, mixed>
	 */
	protected function loop_channel( string $unique_id, bool $archived = false ): array {
		return array(
			'unique_id'   => $unique_id,
			'is_archived' => $archived,
		);
	}

	/**
	 * Every roster payload sent, keyed by unique id.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function payloads(): array {
		return array_column( array_column( Loop_Sync_Test_State::calls_to( 'agend_apps_loop_sync_committee' ), 0 ), null, 'unique_id' );
	}

	/**
	 * @return int[] The external ids in a payload's roster.
	 */
	protected function roster_ids( array $payload ): array {
		return array_column( $payload['roster'], 'external_id' );
	}

	/**
	 * @return string[] The unique ids archived, in order.
	 */
	protected function archived_ids(): array {
		return array_column( Loop_Sync_Test_State::calls_to( 'agend_apps_loop_archive_committee' ), 0 );
	}
}
