<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend_Apps_Key_Scopes;
use Agend_Apps_Loop_Channel_Membership;
use Agend_Apps_Settings;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-api.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-key-scopes.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-endpoint-scopes.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/identity.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/loop-memberships.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/loop-channel-membership.php';

/**
 * Agend says whether a member, named by contact pair, belongs to an active
 * Loop channel; the site only caches the answer and offers it to ESAC rules
 * and If Menu.
 */
#[CoversClass( Agend_Apps_Loop_Channel_Membership::class )]
final class LoopChannelMembershipTest extends TestCase {

	private const USER = 42;

	protected function setUp(): void {
		parent::setUp();
		update_option( 'agend_apps_member_auth_mode', Agend_Apps_Settings::MEMBER_AUTH_CREDENTIALS );
		update_user_meta( self::USER, 'imk_membership_number', 'M1001' );
		$GLOBALS['agend_test_current_user_id'] = self::USER;
		// A member bearer is available, so the tests prove it is never sent.
		add_filter( 'agend_apps_bearer_token', static fn() => 'member-token' );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['agend_test_current_user_id'] );
		parent::tearDown();
	}

	private function answer( bool $in_active_channel, bool $resolved = true ): void {
		Agend_Test_WP::queue_response(
			200,
			array(
				'success' => true,
				'data'    => array(
					'in_active_channel' => $in_active_channel,
					'resolved'          => $resolved,
				),
			)
		);
	}

	private function error_response( int $status, string $code ): void {
		Agend_Test_WP::queue_response(
			$status,
			array(
				'success' => false,
				'error'   => array(
					'code'    => $code,
					'message' => 'Refused',
				),
			)
		);
	}

	private function know_scopes( array $scopes ): void {
		Agend_Apps_Key_Scopes::store_from_response( array( 'data' => array( 'scopes' => $scopes, 'app_ids' => array() ) ) );
	}

	#[Test]
	public function a_member_in_an_active_channel_passes_and_the_answer_is_cached(): void {
		$this->answer( true );

		$this->assertTrue( Agend_Apps_Loop_Channel_Membership::status() );
		$this->assertTrue( Agend_Apps_Loop_Channel_Membership::in_active_channel() );

		$this->assertCount( 1, Agend_Test_WP::$requests );
		$this->assertContains( Agend_Apps_Loop_Channel_Membership::CACHE_TTL, Agend_Test_WP::$transient_ttls );
	}

	#[Test]
	public function the_site_asks_with_its_own_key_naming_the_member_by_contact_pair(): void {
		$this->answer( true );

		Agend_Apps_Loop_Channel_Membership::status();

		$request = Agend_Test_WP::$requests[0];
		$this->assertStringContainsString( '/loop/integration/members/channels?', $request['url'] );
		$this->assertStringContainsString( 'external_source=upbeat-contacts', $request['url'] );
		$this->assertStringContainsString( 'external_id=M1001', $request['url'] );
		$this->assertStringNotContainsString( '/loop/me/', $request['url'] );
		$this->assertArrayNotHasKey( 'Authorization', $request['headers'] );
	}

	#[Test]
	public function a_filter_cannot_put_the_member_bearer_back(): void {
		Agend_Test_WP::set_filter( 'agend_apps_loop_get_member_channels_args', array( 'unattended' => false, 'query' => array( 'external_source' => 'upbeat-contacts', 'external_id' => 'M1001' ) ) );
		$this->answer( true );

		Agend_Apps_Loop_Channel_Membership::status();

		$this->assertArrayNotHasKey( 'Authorization', Agend_Test_WP::$requests[0]['headers'] );
	}

	#[Test]
	public function a_member_in_no_active_channel_does_not_pass(): void {
		$this->answer( false );

		$this->assertFalse( Agend_Apps_Loop_Channel_Membership::status() );
		$this->assertFalse( Agend_Apps_Loop_Channel_Membership::in_active_channel() );
		$this->assertCount( 1, Agend_Test_WP::$requests );
		$this->assertContains( Agend_Apps_Loop_Channel_Membership::CACHE_TTL, Agend_Test_WP::$transient_ttls );
	}

	#[Test]
	public function a_member_agend_cannot_match_yet_is_a_plain_no(): void {
		$this->answer( false, false );

		$this->assertFalse( Agend_Apps_Loop_Channel_Membership::status() );
		$this->assertFalse( Agend_Apps_Loop_Channel_Membership::status() );
		$this->assertCount( 1, Agend_Test_WP::$requests );
	}

	#[Test]
	public function the_site_contact_source_names_the_member(): void {
		update_option( 'agend_apps_jobs_contact_source', 'other-crm' );
		$this->answer( true );

		Agend_Apps_Loop_Channel_Membership::status();

		$this->assertStringContainsString( 'external_source=other-crm', Agend_Test_WP::$requests[0]['url'] );
	}

	#[Test]
	public function a_signed_out_visitor_or_a_member_without_an_external_id_is_not_asked_about(): void {
		update_user_meta( self::USER, 'imk_membership_number', '' );
		$this->assertSame( 'agend_loop_no_member', Agend_Apps_Loop_Channel_Membership::status()->get_error_code() );

		$GLOBALS['agend_test_current_user_id'] = 0;
		$this->assertFalse( Agend_Apps_Loop_Channel_Membership::in_active_channel() );

		$this->assertCount( 0, Agend_Test_WP::$requests );
	}

	#[Test]
	public function a_site_that_cannot_check_is_told_so_and_remembers_it(): void {
		foreach ( array( 'SOURCE_NOT_ALLOWED', 'INSUFFICIENT_SCOPES', 'APP_NOT_ALLOWED' ) as $i => $code ) {
			update_user_meta( self::USER, 'imk_membership_number', 'M10' . $i );
			$this->error_response( 403, $code );

			$this->assertSame( 'agend_loop_not_available', Agend_Apps_Loop_Channel_Membership::status()->get_error_code(), $code );
			$this->assertSame( 'agend_loop_not_available', Agend_Apps_Loop_Channel_Membership::status()->get_error_code(), $code );
		}

		$this->assertCount( 3, Agend_Test_WP::$requests );
		$this->assertContains( Agend_Apps_Loop_Channel_Membership::REFUSED_TTL, Agend_Test_WP::$transient_ttls );
	}

	#[Test]
	public function an_answer_that_cannot_be_read_is_an_error_remembered_briefly(): void {
		$this->error_response( 500, 'DATABASE_ERROR' );

		$this->assertSame( 'agend_loop_unavailable', Agend_Apps_Loop_Channel_Membership::status()->get_error_code() );
		$this->assertSame( 'agend_loop_unavailable', Agend_Apps_Loop_Channel_Membership::status()->get_error_code() );
		$this->assertCount( 1, Agend_Test_WP::$requests );
		$this->assertContains( Agend_Apps_Loop_Channel_Membership::FAILURE_TTL, Agend_Test_WP::$transient_ttls );
	}

	#[Test]
	public function an_answer_without_a_flag_is_not_read_as_no(): void {
		Agend_Test_WP::queue_response( 200, array( 'success' => true, 'data' => array( 'resolved' => true ) ) );

		$this->assertSame( 'agend_loop_unavailable', Agend_Apps_Loop_Channel_Membership::status()->get_error_code() );
	}

	#[Test]
	public function the_answer_is_cached_per_contact_pair(): void {
		$this->answer( true );
		$this->answer( false );
		$this->answer( true );

		$this->assertTrue( Agend_Apps_Loop_Channel_Membership::status() );

		update_user_meta( self::USER, 'imk_membership_number', 'M2002' );
		$this->assertFalse( Agend_Apps_Loop_Channel_Membership::status() );

		update_option( 'agend_apps_jobs_contact_source', 'other-crm' );
		$this->assertTrue( Agend_Apps_Loop_Channel_Membership::status() );

		$this->assertCount( 3, Agend_Test_WP::$requests );
	}

	#[Test]
	public function a_key_known_to_lack_the_scope_sends_nothing_and_offers_no_conditions(): void {
		$this->know_scopes( array( 'jobs.submissions.browse' ) );

		$this->assertSame( 'agend_loop_not_available', Agend_Apps_Loop_Channel_Membership::status()->get_error_code() );
		$this->assertCount( 0, Agend_Test_WP::$requests );
		$this->assertSame( array(), Agend_Apps_Loop_Channel_Membership::esac_condition_sets( array() ) );
		$this->assertSame( array(), Agend_Apps_Loop_Channel_Membership::if_menu_conditions( array() ) );
	}

	#[Test]
	public function the_esac_condition_is_offered_and_passes_only_in_an_active_channel(): void {
		$sets = Agend_Apps_Loop_Channel_Membership::esac_condition_sets( array( 'other' => array() ) );
		$this->assertArrayHasKey( 'other', $sets );
		$this->assertArrayHasKey( 'in_active_channel', $sets['agend_loop']['conditions'] );

		$this->answer( true );
		$this->assertTrue( Agend_Apps_Loop_Channel_Membership::esac_check( true, 'in_active_channel' ) );

		// An earlier filter's fail stands, and other conditions pass through.
		$this->assertFalse( Agend_Apps_Loop_Channel_Membership::esac_check( false, 'in_active_channel' ) );
		$this->assertTrue( Agend_Apps_Loop_Channel_Membership::esac_check( true, 'something_else' ) );
		$this->assertFalse( Agend_Apps_Loop_Channel_Membership::esac_check( false, 'something_else' ) );
	}

	#[Test]
	public function the_if_menu_condition_is_offered_and_checks_the_signed_in_member(): void {
		$conditions = Agend_Apps_Loop_Channel_Membership::if_menu_conditions( array( array( 'id' => 'other' ) ) );

		$this->assertCount( 2, $conditions );
		$this->assertSame( 'agend-loop-in-active-channel', $conditions[1]['id'] );
		$this->assertSame( 'Agend Loop', $conditions[1]['group'] );
		$this->assertIsCallable( $conditions[1]['condition'] );

		$this->answer( false );
		$this->assertFalse( call_user_func( $conditions[1]['condition'], (object) array( 'ID' => 1 ) ) );
	}

	#[Test]
	public function register_hooks_esac_and_if_menu(): void {
		Agend_Apps_Loop_Channel_Membership::register();

		$this->assertArrayHasKey( 'iugo_esac_filter_condition_sets', Agend_Test_WP::$filter_registry );
		$this->assertArrayHasKey( 'iugo_esac_filter_check_condition_agend_loop', Agend_Test_WP::$filter_registry );
		$this->assertArrayHasKey( 'if_menu_conditions', Agend_Test_WP::$filter_registry );
	}
}
