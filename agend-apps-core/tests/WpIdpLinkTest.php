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
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/wp-idp-link.php';

/**
 * The WordPress-IdP link STATE vocabulary that survives the retirement of the
 * server-to-server link step: the recorded state read/write, the per-state
 * backoff, the throttle check, and the `user_register` handler's remaining
 * job (mint the external id; never attempt a link).
 *
 * The link step itself -- posting a SAML assertion through the site's IdP and
 * recording `asserted`/`linked` -- is covered by `WpIdpSamlLinkTest`.
 */
final class WpIdpLinkTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		// agend_apps_user_external_id()'s minted-GUID fallback
		// (AGEND_APPS_EXTERNAL_ID_META) only engages in `wordpress` mode; the
		// user_register handler under test mints unconditionally, so mode is
		// set here purely so the assertions below can read the id back
		// through the normal resolver rather than the raw meta key.
		update_option( 'agend_apps_member_auth_mode', Agend_Apps_Settings::MEMBER_AUTH_WORDPRESS );
	}

	// -----------------------------------------------------------------
	// Recorded state read/write
	// -----------------------------------------------------------------

	#[Test]
	public function should_default_to_an_empty_state_when_nothing_is_recorded(): void {
		$this->assertSame(
			array(
				'state'      => '',
				'error_code' => '',
				'timestamp'  => 0,
			),
			\agend_apps_wp_idp_link_state( 1 )
		);
	}

	#[Test]
	public function should_default_to_an_empty_state_for_user_id_zero(): void {
		update_user_meta( 0, \AGEND_APPS_LINK_STATE_META, array( 'state' => \AGEND_APPS_LINK_STATE_LINKED ) );

		$this->assertSame( '', \agend_apps_wp_idp_link_state( 0 )['state'] );
	}

	#[Test]
	public function should_round_trip_a_recorded_state_and_error_code(): void {
		\agend_apps_wp_idp_record_link_state( 5, \AGEND_APPS_LINK_STATE_CONFLICT, 'IDENTITY_ALREADY_LINKED' );

		$stored = \agend_apps_wp_idp_link_state( 5 );

		$this->assertSame( \AGEND_APPS_LINK_STATE_CONFLICT, $stored['state'] );
		$this->assertSame( 'IDENTITY_ALREADY_LINKED', $stored['error_code'] );
		$this->assertGreaterThan( 0, $stored['timestamp'] );
	}

	// -----------------------------------------------------------------
	// Per-state backoff and throttle
	// -----------------------------------------------------------------

	#[Test]
	public function should_report_no_backoff_for_an_empty_or_unrecognised_state(): void {
		$this->assertSame( 0, \agend_apps_wp_idp_link_backoff_seconds( '' ) );
		$this->assertSame( 0, \agend_apps_wp_idp_link_backoff_seconds( 'some_future_state' ) );
	}

	#[Test]
	public function should_report_the_short_backoff_for_pending(): void {
		$this->assertSame( \AGEND_APPS_LINK_BACKOFF_PENDING, \agend_apps_wp_idp_link_backoff_seconds( \AGEND_APPS_LINK_STATE_PENDING ) );
	}

	#[Test]
	public function should_report_the_short_backoff_for_asserted(): void {
		$this->assertSame( \AGEND_APPS_LINK_BACKOFF_ASSERTED, \agend_apps_wp_idp_link_backoff_seconds( \AGEND_APPS_LINK_STATE_ASSERTED ) );
		$this->assertSame( 5 * MINUTE_IN_SECONDS, \agend_apps_wp_idp_link_backoff_seconds( \AGEND_APPS_LINK_STATE_ASSERTED ) );
	}

	#[Test]
	public function should_report_the_short_backoff_for_error(): void {
		$this->assertSame( \AGEND_APPS_LINK_BACKOFF_ERROR, \agend_apps_wp_idp_link_backoff_seconds( \AGEND_APPS_LINK_STATE_ERROR ) );
	}

	#[Test]
	public function should_report_the_human_backoff_for_conflict_no_contact_and_forbidden(): void {
		$this->assertSame( \AGEND_APPS_LINK_BACKOFF_HUMAN, \agend_apps_wp_idp_link_backoff_seconds( \AGEND_APPS_LINK_STATE_CONFLICT ) );
		$this->assertSame( \AGEND_APPS_LINK_BACKOFF_HUMAN, \agend_apps_wp_idp_link_backoff_seconds( \AGEND_APPS_LINK_STATE_NO_CONTACT ) );
		$this->assertSame( \AGEND_APPS_LINK_BACKOFF_HUMAN, \agend_apps_wp_idp_link_backoff_seconds( \AGEND_APPS_LINK_STATE_FORBIDDEN ) );
	}

	#[Test]
	public function should_never_throttle_an_empty_state(): void {
		$this->assertFalse(
			\agend_apps_wp_idp_link_is_throttled(
				array(
					'state'      => '',
					'error_code' => '',
					'timestamp'  => time(),
				)
			)
		);
	}

	#[Test]
	public function should_throttle_a_state_within_its_backoff_window(): void {
		$this->assertTrue(
			\agend_apps_wp_idp_link_is_throttled(
				array(
					'state'      => \AGEND_APPS_LINK_STATE_ASSERTED,
					'error_code' => '',
					'timestamp'  => time(),
				)
			)
		);
	}

	#[Test]
	public function should_not_throttle_a_state_whose_backoff_window_has_elapsed(): void {
		$this->assertFalse(
			\agend_apps_wp_idp_link_is_throttled(
				array(
					'state'      => \AGEND_APPS_LINK_STATE_ASSERTED,
					'error_code' => '',
					'timestamp'  => time() - ( 2 * HOUR_IN_SECONDS ),
				)
			)
		);
	}

	// -----------------------------------------------------------------
	// user_register: mints the external id, never attempts a link
	// -----------------------------------------------------------------

	#[Test]
	public function should_mint_an_external_id_on_user_register(): void {
		\agend_apps_wp_idp_handle_user_register( 100 );

		$this->assertNotSame( '', \agend_apps_user_external_id( 100 ) );
	}

	#[Test]
	public function should_never_let_a_thrown_exception_propagate_out_of_user_register(): void {
		add_filter(
			'agend_apps_current_user_external_id',
			static function () {
				throw new \RuntimeException( 'boom' );
			}
		);

		// If the exception escapes the handler's try/catch, PHPUnit reports
		// this as an ERROR, not a failed assertion -- reaching the assertion
		// below is itself the proof.
		\agend_apps_wp_idp_handle_user_register( 101 );

		$this->addToAssertionCount( 1 );
	}
}
