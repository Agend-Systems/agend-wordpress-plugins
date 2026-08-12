<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\EntitlementMirror;

use Agend_Entitlement_Sync;
use Agend_Test_Mirror_Gateway;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use WP_User;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/identity.php';
require_once AGEND_TESTS_ROOT . '/agend-entitlement-mirror/includes/class-entitlement-mirror-settings.php';
require_once AGEND_TESTS_ROOT . '/agend-entitlement-mirror/includes/class-entitlement-collector.php';
require_once AGEND_TESTS_ROOT . '/agend-entitlement-mirror/includes/class-entitlement-sync.php';

/**
 * `Agend_Entitlement_Sync::handle_sso_attributes()`: the
 * `wp_saml_idp_user_attributes_lightsaml` filter handler that reconciles a
 * member's entitlements at SSO-assertion time, before the SAML response is
 * signed and sent -- closing the gap where a WordPress user already logged in
 * before following an Agend SSO link gets JIT-provisioned in Agend with no
 * prior mirror run.
 */
#[CoversClass( Agend_Entitlement_Sync::class )]
final class SsoAttributesReconciliationTest extends TestCase {

	private const MEMBER_ID    = 'MEM-SSO-1';
	private const SP_ENTITY_ID = 'https://sp.example.test/metadata';

	protected function setUp(): void {
		parent::setUp();
		Agend_Test_WP::$options['agend_entitlement_mirror_categories'] = 'Membership';
		Agend_Test_WP::set_filter( 'agend_entitlement_mirror_raw_entitlements', array() );
		Agend_Test_Mirror_Gateway::$get_contacts_response = array(
			'data' => array( array( 'id' => 'contact-existing' ) ),
		);
	}

	/**
	 * @return array<string, string>
	 */
	private function attributes(): array {
		return array(
			'urn:oid:0.9.2342.19200300.100.1.3' => 'jane@example.test',
		);
	}

	#[Test]
	public function returns_attributes_unchanged_and_does_not_sync_when_the_user_has_no_member_id(): void {
		$user = new WP_User( 42 );
		// No agend_apps_external_id_meta_key() user meta set for this user.

		$result = Agend_Entitlement_Sync::handle_sso_attributes( $this->attributes(), $user, self::SP_ENTITY_ID );

		$this->assertSame( $this->attributes(), $result );
		$this->assertCount( 0, Agend_Test_Mirror_Gateway::$reconcile_calls, 'no member id means no sync must be attempted' );
	}

	#[Test]
	public function triggers_a_sync_and_still_returns_attributes_unchanged_when_a_member_id_exists(): void {
		$user = new WP_User( 7 );
		update_user_meta( $user->ID, 'imk_membership_number', self::MEMBER_ID );

		$result = Agend_Entitlement_Sync::handle_sso_attributes( $this->attributes(), $user, self::SP_ENTITY_ID );

		$this->assertSame( $this->attributes(), $result, 'the filter must always return the attributes unchanged' );
		$this->assertCount( 1, Agend_Test_Mirror_Gateway::$reconcile_calls, 'a member id present must trigger a reconcile sync' );
		$this->assertSame( self::MEMBER_ID, Agend_Test_Mirror_Gateway::$reconcile_calls[0]['external_id'] );
	}

	#[Test]
	public function does_not_apply_the_wp_login_throttle_so_a_login_immediately_followed_by_sso_still_syncs(): void {
		$user = new WP_User( 9 );
		update_user_meta( $user->ID, 'imk_membership_number', self::MEMBER_ID );

		// Simulate the wp_login throttle transient already being set, as it
		// would be immediately after handle_login() ran for the same member.
		Agend_Test_WP::$transients[ 'agend_ent_mirror_login_' . md5( self::MEMBER_ID ) ] = 1;

		Agend_Entitlement_Sync::handle_sso_attributes( $this->attributes(), $user, self::SP_ENTITY_ID );

		$this->assertCount( 1, Agend_Test_Mirror_Gateway::$reconcile_calls, 'the wp_login throttle must never gate the SSO-time sync' );
	}

	#[Test]
	public function returns_attributes_unchanged_and_swallows_the_error_when_the_sync_throws(): void {
		$user = new WP_User( 11 );
		update_user_meta( $user->ID, 'imk_membership_number', self::MEMBER_ID );

		// Force sync_member() to raise a real Throwable: an invalid collector
		// result makes Agend_Entitlement_Collector::collect() throw, and a
		// listener on the mirror's own log hook re-throws from inside
		// sync_member()'s catch block, exercising handle_sso_attributes()'s
		// try/catch rather than the collector's own (already-covered) one.
		Agend_Test_WP::set_filter( 'agend_entitlement_mirror_raw_entitlements', 'not-an-array' );
		// Only the collector-failure log call (inside sync_member()'s own catch
		// block) throws, so the throw genuinely escapes sync_member() uncaught.
		// If handle_sso_attributes()'s own catch-block log call also threw, the
		// resulting error would not distinguish "handle_sso_attributes() swallowed
		// it" from "the test's own listener broke".
		Agend_Test_WP::$actions['agend_entitlement_mirror_log'][] = static function ( string $message ): void {
			if ( str_contains( $message, 'Collector failed' ) ) {
				throw new \RuntimeException( 'log sink unavailable' );
			}
		};

		$result = Agend_Entitlement_Sync::handle_sso_attributes( $this->attributes(), $user, self::SP_ENTITY_ID );

		$this->assertSame( $this->attributes(), $result, 'a swallowed sync failure must still return the attributes unchanged' );
	}
}
