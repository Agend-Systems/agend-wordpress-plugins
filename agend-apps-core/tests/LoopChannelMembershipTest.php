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
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/loop.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/loop-channel-membership.php';

/**
 * Agend says which Loop channels a member belongs to; the site only caches
 * the answer and offers it to ESAC rules and If Menu.
 */
#[CoversClass( Agend_Apps_Loop_Channel_Membership::class )]
final class LoopChannelMembershipTest extends TestCase {

	private const USER = 42;

	protected function setUp(): void {
		parent::setUp();
		update_option( 'agend_apps_member_auth_mode', Agend_Apps_Settings::MEMBER_AUTH_CREDENTIALS );
		update_user_meta( self::USER, 'imk_membership_number', 'M1001' );
		$GLOBALS['agend_test_current_user_id'] = self::USER;
		add_filter( 'agend_apps_bearer_token', static fn() => 'member-token' );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['agend_test_current_user_id'] );
		parent::tearDown();
	}

	private function channels_response( array $channels ): void {
		Agend_Test_WP::queue_response(
			200,
			array(
				'success' => true,
				'data'    => $channels,
			)
		);
	}

	private function channel( string $type, bool $archived = false ): array {
		return array(
			'id'           => 'c-' . $type,
			'name'         => $type,
			'slug'         => $type,
			'channel_type' => $type,
			'is_archived'  => $archived,
			'role'         => 'member',
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
		$this->channels_response( array( $this->channel( 'channel' ) ) );

		$this->assertTrue( Agend_Apps_Loop_Channel_Membership::status() );
		$this->assertTrue( Agend_Apps_Loop_Channel_Membership::in_active_channel() );

		$this->assertCount( 1, Agend_Test_WP::$requests );
		$this->assertStringContainsString( '/loop/me/channels', Agend_Test_WP::$requests[0]['url'] );
		$this->assertSame( 'Bearer member-token', Agend_Test_WP::$requests[0]['headers']['Authorization'] ?? '' );
		$this->assertContains( Agend_Apps_Loop_Channel_Membership::CACHE_TTL, Agend_Test_WP::$transient_ttls );
	}

	#[Test]
	public function posts_committee_and_space_channels_count_as_active(): void {
		foreach ( array( 'feed', 'committee', 'space' ) as $type ) {
			$this->assertTrue( Agend_Apps_Loop_Channel_Membership::has_active_channel( array( $this->channel( $type ) ) ), $type );
		}
	}

	#[Test]
	public function direct_messages_categories_and_archived_channels_do_not_count(): void {
		$this->channels_response(
			array(
				$this->channel( 'dm' ),
				$this->channel( 'category' ),
				$this->channel( 'channel', true ),
				array( 'channel_type' => '' ),
				'not a channel',
			)
		);

		$this->assertFalse( Agend_Apps_Loop_Channel_Membership::status() );
		$this->assertFalse( Agend_Apps_Loop_Channel_Membership::in_active_channel() );
		$this->assertCount( 1, Agend_Test_WP::$requests );
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
	public function a_member_with_no_agend_token_is_not_linked_and_is_not_asked_about(): void {
		add_filter( 'agend_apps_bearer_token', static fn() => '' );

		$this->assertSame( 'agend_loop_not_linked', Agend_Apps_Loop_Channel_Membership::status()->get_error_code() );
		$this->assertCount( 0, Agend_Test_WP::$requests );
		$this->assertContains( Agend_Apps_Loop_Channel_Membership::UNLINKED_TTL, Agend_Test_WP::$transient_ttls );
	}

	#[Test]
	public function a_member_agend_does_not_recognise_is_not_linked(): void {
		// The client retries a rejected bearer once without it.
		$this->error_response( 401, 'INVALID_USER_TOKEN' );
		$this->error_response( 401, 'MISSING_USER_TOKEN' );

		$this->assertSame( 'agend_loop_not_linked', Agend_Apps_Loop_Channel_Membership::status()->get_error_code() );
		$this->assertSame( 'agend_loop_not_linked', Agend_Apps_Loop_Channel_Membership::status()->get_error_code() );
		$this->assertCount( 2, Agend_Test_WP::$requests );
	}

	#[Test]
	public function a_site_that_cannot_check_is_told_so_and_remembers_it(): void {
		$this->error_response( 403, 'APP_NOT_ALLOWED' );
		$this->error_response( 403, 'APP_NOT_ALLOWED' );

		$this->assertSame( 'agend_loop_not_available', Agend_Apps_Loop_Channel_Membership::status()->get_error_code() );
		$this->assertSame( 'agend_loop_not_available', Agend_Apps_Loop_Channel_Membership::status()->get_error_code() );
		$this->assertContains( Agend_Apps_Loop_Channel_Membership::REFUSED_TTL, Agend_Test_WP::$transient_ttls );
	}

	#[Test]
	public function an_answer_that_cannot_be_read_is_an_error_remembered_briefly(): void {
		$this->error_response( 500, 'INTERNAL_ERROR' );

		$this->assertSame( 'agend_loop_unavailable', Agend_Apps_Loop_Channel_Membership::status()->get_error_code() );
		$this->assertSame( 'agend_loop_unavailable', Agend_Apps_Loop_Channel_Membership::status()->get_error_code() );
		$this->assertCount( 1, Agend_Test_WP::$requests );
		$this->assertContains( Agend_Apps_Loop_Channel_Membership::FAILURE_TTL, Agend_Test_WP::$transient_ttls );
	}

	#[Test]
	public function an_answer_without_a_list_is_not_read_as_no_channels(): void {
		Agend_Test_WP::queue_response( 200, array( 'success' => true, 'data' => 'none' ) );

		$this->assertSame( 'agend_loop_unavailable', Agend_Apps_Loop_Channel_Membership::status()->get_error_code() );
	}

	#[Test]
	public function the_answer_is_cached_per_member(): void {
		$this->channels_response( array( $this->channel( 'channel' ) ) );
		$this->channels_response( array() );

		$this->assertTrue( Agend_Apps_Loop_Channel_Membership::status() );

		update_user_meta( self::USER, 'imk_membership_number', 'M2002' );
		$this->assertFalse( Agend_Apps_Loop_Channel_Membership::status() );
		$this->assertCount( 2, Agend_Test_WP::$requests );
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

		$this->channels_response( array( $this->channel( 'channel' ) ) );
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

		$this->channels_response( array( $this->channel( 'dm' ) ) );
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
