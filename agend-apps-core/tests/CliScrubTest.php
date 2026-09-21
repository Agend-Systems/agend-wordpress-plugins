<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use Agend_Apps_Secret_Store;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-secret-store.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/connect-site.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-cli.php';

/**
 * `wp agend-apps scrub-secrets`'s pure decision/reporting logic
 * ({@see \agend_apps_cli_scrub_secrets_plan()}). The `WP_CLI`-calling shell in
 * `Agend_Apps_CLI::scrub_secrets()` is deliberately NOT covered here: this
 * suite has no real `WP_CLI` runtime (see `includes/class-agend-apps-cli.php`'s
 * header and the same pure/thin split `agend_apps_connect_preflight()` and its
 * thin `WP_User_Query` wrappers use), so only the extracted pure function --
 * which options to delete and what to tell the operator -- is unit tested.
 */
final class CliScrubTest extends TestCase {

	#[Test]
	public function should_always_include_both_this_plugins_options(): void {
		$plan = \agend_apps_cli_scrub_secrets_plan( false, false );

		$this->assertSame(
			array( Agend_Apps_Secret_Store::OPTION_SECRETS, \AGEND_APPS_CONNECT_OPTION ),
			$plan['options_to_delete']
		);
	}

	#[Test]
	public function should_advise_running_the_idp_command_separately_when_available_and_not_chained(): void {
		$plan = \agend_apps_cli_scrub_secrets_plan( true, false );

		$this->assertTrue( $plan['ok'] );
		$this->assertFalse( $plan['should_chain_idp'] );
		$this->assertStringContainsString( 'wp saml-idp scrub-secrets', $plan['idp_advisory'] );
	}

	#[Test]
	public function should_say_there_is_nothing_to_scrub_when_the_idp_plugin_is_not_active(): void {
		$plan = \agend_apps_cli_scrub_secrets_plan( false, false );

		$this->assertTrue( $plan['ok'] );
		$this->assertFalse( $plan['should_chain_idp'] );
		$this->assertStringContainsString( 'not active', $plan['idp_advisory'] );
	}

	#[Test]
	public function should_chain_the_idp_command_when_requested_and_available(): void {
		$plan = \agend_apps_cli_scrub_secrets_plan( true, true );

		$this->assertTrue( $plan['ok'] );
		$this->assertTrue( $plan['should_chain_idp'] );
	}

	#[Test]
	public function should_fail_when_include_idp_is_requested_but_the_idp_plugin_is_not_available(): void {
		$plan = \agend_apps_cli_scrub_secrets_plan( false, true );

		$this->assertFalse( $plan['ok'] );
		$this->assertFalse( $plan['should_chain_idp'] );
		$this->assertStringContainsString( 'not active', $plan['idp_advisory'] );
	}

	#[Test]
	public function should_always_report_the_per_user_data_notice(): void {
		$plan = \agend_apps_cli_scrub_secrets_plan( true, false );

		$this->assertNotSame( '', $plan['per_user_notice'] );
		$this->assertStringContainsString( 'NOT deleted', $plan['per_user_notice'] );
	}
}
