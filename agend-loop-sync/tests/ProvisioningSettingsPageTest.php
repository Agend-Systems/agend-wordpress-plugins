<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\LoopSync;

use Agend_Loop_Sync_Provision_Retry;
use Agend_Loop_Sync_Settings;
use Agend_Test_WP;
use PHPUnit\Framework\Attributes\Test;

require_once __DIR__ . '/LoopSyncTestCase.php';

if ( ! defined( 'AGEND_LOOP_SYNC_DIR' ) ) {
	define( 'AGEND_LOOP_SYNC_DIR', AGEND_TESTS_ROOT . '/agend-loop-sync/' );
}

/**
 * The settings page offers "Create Loop accounts", says when it cannot take
 * effect, and reports what the last backfill created and who is still waiting.
 */
final class ProvisioningSettingsPageTest extends LoopSyncTestCase {

	protected function tearDown(): void {
		Agend_Test_WP::reset();
		parent::tearDown();
	}

	private function render_page(): string {
		ob_start();
		require AGEND_LOOP_SYNC_DIR . 'templates/admin-settings.php';

		return (string) ob_get_clean();
	}

	#[Test]
	public function the_setting_is_offered_and_off_by_default(): void {
		$html = $this->render_page();

		$this->assertMatchesRegularExpression( '/<input type="checkbox" name="agend_loop_sync_provision_members" value="1"\s*\/>/', $html );
		$this->assertStringNotContainsString( 'no accounts are being created', $html );
	}

	#[Test]
	public function a_key_without_the_scope_is_called_out(): void {
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-key-scopes.php';
		update_option( Agend_Loop_Sync_Settings::OPT_PROVISION, '1' );
		\Agend_Apps_Key_Scopes::store_from_response( array( 'data' => array( 'scopes' => array( 'loop.users.sync' ), 'app_ids' => array( 'loop' ) ) ) );

		$html = $this->render_page();

		$this->assertStringContainsString( 'The site key does not hold loop.users.provision, so no accounts are being created.', $html );
	}

	#[Test]
	public function the_last_backfill_reports_accounts_and_reasons_in_words(): void {
		set_transient(
			'agend_loop_sync_user_backfill',
			array(
				'total'        => 5,
				'sent'         => 5,
				'succeeded'    => 4,
				'failed'       => 1,
				'batches'      => 1,
				'dry_run'      => false,
				'provision'    => true,
				'errors'       => array(),
				'provisioning' => array(
					'provisioned'    => 2,
					'linked'         => 1,
					'already_linked' => 1,
					'skipped'        => 1,
					'reasons'        => array( 'restricted_role' => 1 ),
					'queued'         => 0,
					'deferred'       => 0,
					'refused'        => false,
				),
			),
			300
		);

		$html = $this->render_page();

		$this->assertStringContainsString( 'Loop accounts: 2 created, 1 existing logins linked, 1 already in Loop, 1 not created.', $html );
		$this->assertStringContainsString( 'existing login with a staff role, left for the member to confirm: 1', $html );
	}

	#[Test]
	public function members_waiting_for_a_retry_are_counted_with_the_next_try(): void {
		Agend_Loop_Sync_Provision_Retry::add(
			array(
				1 => 'rate_limited',
				2 => 'rate_limited',
			)
		);

		$html = $this->render_page();

		$this->assertMatchesRegularExpression( '/2 members are waiting for their Loop account to be created\. Next try: \d{4}-\d{2}-\d{2} \d{2}:\d{2}\./', $html );
	}

	#[Test]
	public function a_member_who_left_is_described_as_such(): void {
		ob_start();
		require AGEND_LOOP_SYNC_DIR . 'templates/admin-settings.php';
		ob_end_clean();

		$this->assertSame(
			'Left the channel in Loop. The sync does not add them back unless they rejoin there.',
			$als_describe_hold( array( 'kind' => 'member_left' ) )
		);
	}
}
