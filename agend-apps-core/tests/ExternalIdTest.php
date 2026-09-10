<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use Agend_Apps_Settings;
use PHPUnit\Framework\Attributes\Test;

// Agend_Apps_Settings is a bootstrap-time test double (tests/doubles.php);
// requiring the real includes/class-agend-apps-settings.php here would
// redeclare the class and fatal. The double's get_member_auth_mode() /
// wordpress_idp_enabled() read the same option as the real class, so this
// test exercises the same gate the real class enforces.
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/identity.php';

/**
 * Per-user Agend external id resolution and minting
 * (docs/PLAN-wordpress-idp-option-b.md section 5).
 *
 * `agend_apps_user_external_id()` is the resolver both
 * `agend_apps_current_user_external_id()` and the (not-yet-built) WordPress
 * IdP linking step read; `agend_apps_ensure_external_id()` is the only
 * function allowed to mint and store a GUID, and only when nothing else
 * already resolves.
 */
final class ExternalIdTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		update_option( 'agend_apps_member_auth_mode', Agend_Apps_Settings::MEMBER_AUTH_CREDENTIALS );
	}

	// -----------------------------------------------------------------
	// agend_apps_user_external_id()
	// -----------------------------------------------------------------

	#[Test]
	public function should_return_empty_string_for_user_id_zero_without_applying_the_filter(): void {
		$filter_called = false;
		add_filter(
			'agend_apps_current_user_external_id',
			function ( $value, $user_id ) use ( &$filter_called ) {
				$filter_called = true;
				return $value;
			},
			10,
			2
		);

		$this->assertSame( '', \agend_apps_user_external_id( 0 ) );
		$this->assertFalse( $filter_called );
	}

	#[Test]
	public function should_resolve_the_configured_meta_key_when_populated(): void {
		update_user_meta( 10, 'imk_membership_number', 'member-10' );

		$this->assertSame( 'member-10', \agend_apps_user_external_id( 10 ) );
	}

	#[Test]
	public function should_not_fall_back_to_the_guid_meta_in_credentials_mode_when_the_configured_meta_is_empty(): void {
		update_option( 'agend_apps_member_auth_mode', Agend_Apps_Settings::MEMBER_AUTH_CREDENTIALS );
		update_user_meta( 11, '_agend_apps_external_id', 'guid-11' );

		$this->assertSame( '', \agend_apps_user_external_id( 11 ) );
	}

	#[Test]
	public function should_not_fall_back_to_the_guid_meta_in_sso_mode_when_the_configured_meta_is_empty(): void {
		update_option( 'agend_apps_member_auth_mode', Agend_Apps_Settings::MEMBER_AUTH_SSO );
		update_user_meta( 12, '_agend_apps_external_id', 'guid-12' );

		$this->assertSame( '', \agend_apps_user_external_id( 12 ) );
	}

	#[Test]
	public function should_fall_back_to_the_guid_meta_only_in_wordpress_mode_when_the_configured_meta_is_empty(): void {
		update_option( 'agend_apps_member_auth_mode', Agend_Apps_Settings::MEMBER_AUTH_WORDPRESS );
		update_user_meta( 13, '_agend_apps_external_id', 'guid-13' );

		$this->assertSame( 'guid-13', \agend_apps_user_external_id( 13 ) );
	}

	#[Test]
	public function should_prefer_the_configured_meta_key_over_the_guid_meta_in_wordpress_mode(): void {
		update_option( 'agend_apps_member_auth_mode', Agend_Apps_Settings::MEMBER_AUTH_WORDPRESS );
		update_user_meta( 14, 'imk_membership_number', 'member-14' );
		update_user_meta( 14, '_agend_apps_external_id', 'guid-14' );

		$this->assertSame( 'member-14', \agend_apps_user_external_id( 14 ) );
	}

	#[Test]
	public function should_let_the_filter_override_everything_else(): void {
		update_option( 'agend_apps_member_auth_mode', Agend_Apps_Settings::MEMBER_AUTH_WORDPRESS );
		update_user_meta( 15, 'imk_membership_number', 'member-15' );

		add_filter(
			'agend_apps_current_user_external_id',
			function ( $value, $user_id ) {
				return 'filtered-' . $user_id;
			},
			10,
			2
		);

		$this->assertSame( 'filtered-15', \agend_apps_user_external_id( 15 ) );
	}

	// -----------------------------------------------------------------
	// agend_apps_current_user_external_id() unchanged for non-wordpress modes
	// -----------------------------------------------------------------

	#[Test]
	public function should_resolve_the_current_user_exactly_as_before_in_credentials_mode(): void {
		update_option( 'agend_apps_member_auth_mode', Agend_Apps_Settings::MEMBER_AUTH_CREDENTIALS );
		$GLOBALS['agend_test_current_user_id'] = 20;
		update_user_meta( 20, '_agend_apps_external_id', 'guid-20' );

		$this->assertSame( '', \agend_apps_current_user_external_id() );
	}

	#[Test]
	public function should_resolve_the_current_user_exactly_as_before_in_sso_mode(): void {
		update_option( 'agend_apps_member_auth_mode', Agend_Apps_Settings::MEMBER_AUTH_SSO );
		$GLOBALS['agend_test_current_user_id'] = 21;
		update_user_meta( 21, '_agend_apps_external_id', 'guid-21' );

		$this->assertSame( '', \agend_apps_current_user_external_id() );
	}

	#[Test]
	public function should_resolve_the_current_users_configured_meta_key_in_wordpress_mode(): void {
		update_option( 'agend_apps_member_auth_mode', Agend_Apps_Settings::MEMBER_AUTH_WORDPRESS );
		$GLOBALS['agend_test_current_user_id'] = 22;
		update_user_meta( 22, 'imk_membership_number', 'member-22' );

		$this->assertSame( 'member-22', \agend_apps_current_user_external_id() );
	}

	// -----------------------------------------------------------------
	// agend_apps_ensure_external_id()
	// -----------------------------------------------------------------

	#[Test]
	public function should_return_empty_string_for_user_id_zero_and_mint_nothing(): void {
		$this->assertSame( '', \agend_apps_ensure_external_id( 0 ) );
		$this->assertSame( '', get_user_meta( 0, '_agend_apps_external_id', true ) );
	}

	#[Test]
	public function should_mint_a_guid_once_and_return_the_same_value_on_a_second_call(): void {
		update_option( 'agend_apps_member_auth_mode', Agend_Apps_Settings::MEMBER_AUTH_WORDPRESS );

		$first  = \agend_apps_ensure_external_id( 30 );
		$second = \agend_apps_ensure_external_id( 30 );

		$this->assertNotSame( '', $first );
		$this->assertSame( $first, $second );
		$this->assertSame( $first, get_user_meta( 30, '_agend_apps_external_id', true ) );
	}

	#[Test]
	public function should_not_mint_when_the_configured_meta_already_resolves(): void {
		update_option( 'agend_apps_member_auth_mode', Agend_Apps_Settings::MEMBER_AUTH_WORDPRESS );
		update_user_meta( 31, 'imk_membership_number', 'member-31' );

		$this->assertSame( 'member-31', \agend_apps_ensure_external_id( 31 ) );
		$this->assertSame( '', get_user_meta( 31, '_agend_apps_external_id', true ) );
	}

	#[Test]
	public function should_give_two_different_users_two_different_minted_ids(): void {
		update_option( 'agend_apps_member_auth_mode', Agend_Apps_Settings::MEMBER_AUTH_WORDPRESS );

		$user_a = \agend_apps_ensure_external_id( 40 );
		$user_b = \agend_apps_ensure_external_id( 41 );

		$this->assertNotSame( '', $user_a );
		$this->assertNotSame( '', $user_b );
		$this->assertNotSame( $user_a, $user_b );
	}

	#[Test]
	public function should_not_overwrite_an_id_written_between_resolve_and_mint(): void {
		update_option( 'agend_apps_member_auth_mode', Agend_Apps_Settings::MEMBER_AUTH_WORDPRESS );

		// add_user_meta()'s $unique guard is what makes this safe: seed the
		// value directly to simulate a concurrent caller winning the race.
		add_user_meta( 50, '_agend_apps_external_id', 'raced-in-first', true );

		$this->assertSame( 'raced-in-first', \agend_apps_ensure_external_id( 50 ) );
	}
}
