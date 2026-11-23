<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend_Apps_Key_Scopes;
use Agend_Apps_Loop_My_Communities;
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
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/loop-my-communities.php';

/**
 * The member's communities: the one gateway read that lists them, the states it
 * can end in, the cache, and the single sign-on link each community gets.
 */
#[CoversClass( Agend_Apps_Loop_My_Communities::class )]
final class LoopMyCommunitiesTest extends TestCase {

	private const USER = 42;

	protected function setUp(): void {
		parent::setUp();
		update_option( 'agend_apps_member_auth_mode', Agend_Apps_Settings::MEMBER_AUTH_CREDENTIALS );
		update_option( 'agend_apps_account_slug', 'smsf' );
		update_option( 'agend_apps_root_url_for_tests', 'https://api.agend.test' );
		update_user_meta( self::USER, 'imk_membership_number', 'M1001' );
		$GLOBALS['agend_test_current_user_id'] = self::USER;
		// A member bearer is available, so the tests prove it is never sent.
		add_filter( 'agend_apps_bearer_token', static fn() => 'member-token' );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['agend_test_current_user_id'] );
		parent::tearDown();
	}

	private function channel( string $slug = 'fellows', array $more = array() ): array {
		return $more + array(
			'id'           => 'id-' . $slug,
			'name'         => ucfirst( $slug ),
			'slug'         => $slug,
			'description'  => 'About ' . $slug,
			'channel_type' => 'channel',
			'unique_id'    => null,
		);
	}

	private function answer( array $channels, bool $resolved = true ): void {
		Agend_Test_WP::queue_response(
			200,
			array(
				'success' => true,
				'data'    => array(
					'in_active_channel' => array() !== $channels,
					'resolved'          => $resolved,
					'channels'          => $channels,
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

	// -----------------------------------------------------------------
	// The gateway read
	// -----------------------------------------------------------------

	#[Test]
	public function the_site_asks_with_its_own_key_for_the_channel_list_by_contact_pair(): void {
		$this->answer( array( $this->channel() ) );

		Agend_Apps_Loop_My_Communities::for_current_user();

		$request = Agend_Test_WP::$requests[0];
		$this->assertSame( 'GET', $request['method'] );
		$this->assertStringContainsString( '/loop/integration/members/channels?', $request['url'] );
		$this->assertStringContainsString( 'external_source=upbeat-contacts', $request['url'] );
		$this->assertStringContainsString( 'external_id=M1001', $request['url'] );
		$this->assertStringContainsString( 'include=channels', $request['url'] );
		$this->assertStringNotContainsString( '/loop/me/', $request['url'] );
		$this->assertArrayNotHasKey( 'Authorization', $request['headers'] );
	}

	#[Test]
	public function a_filter_cannot_put_the_member_bearer_back(): void {
		Agend_Test_WP::set_filter(
			'agend_apps_loop_get_member_channel_list_args',
			array(
				'unattended' => false,
				'query'      => array(
					'external_source' => 'upbeat-contacts',
					'external_id'     => 'M1001',
					'include'         => 'channels',
				),
			)
		);
		$this->answer( array( $this->channel() ) );

		Agend_Apps_Loop_My_Communities::for_current_user();

		$this->assertArrayNotHasKey( 'Authorization', Agend_Test_WP::$requests[0]['headers'] );
	}

	#[Test]
	public function the_plain_membership_check_still_asks_without_include(): void {
		Agend_Test_WP::queue_response( 200, array( 'success' => true, 'data' => array( 'in_active_channel' => true, 'resolved' => true ) ) );

		agend_apps_loop_get_member_channels( array( 'external_source' => 'upbeat-contacts', 'external_id' => 'M1001' ) );

		$this->assertStringNotContainsString( 'include=', Agend_Test_WP::$requests[0]['url'] );
	}

	// -----------------------------------------------------------------
	// States
	// -----------------------------------------------------------------

	#[Test]
	public function a_signed_out_visitor_is_not_asked_about(): void {
		$GLOBALS['agend_test_current_user_id'] = 0;

		$result = Agend_Apps_Loop_My_Communities::for_current_user();

		$this->assertSame( 'signed_out', $result['state'] );
		$this->assertSame( array(), $result['communities'] );
		$this->assertCount( 0, Agend_Test_WP::$requests );
	}

	#[Test]
	public function a_member_without_a_membership_number_is_not_asked_about(): void {
		update_user_meta( self::USER, 'imk_membership_number', '' );

		$this->assertSame( 'no_contact', Agend_Apps_Loop_My_Communities::for_current_user()['state'] );
		$this->assertCount( 0, Agend_Test_WP::$requests );
	}

	#[Test]
	public function a_member_in_channels_gets_them_normalised_with_a_link_each(): void {
		$this->answer( array( $this->channel( 'fellows' ), $this->channel( 'smsf-news', array( 'channel_type' => 'feed', 'description' => null ) ) ) );

		$result = Agend_Apps_Loop_My_Communities::for_current_user();

		$this->assertSame( 'ok', $result['state'] );
		$this->assertTrue( $result['links'] );
		$this->assertCount( 2, $result['communities'] );
		$this->assertSame(
			array(
				'id'           => 'id-fellows',
				'name'         => 'Fellows',
				'description'  => 'About fellows',
				'channel_type' => 'channel',
				'access_url'   => 'https://api.agend.test/sso/smsf/loop-channel/fellows',
			),
			$result['communities'][0]
		);
		$this->assertSame( '', $result['communities'][1]['description'] );
		$this->assertSame( 'feed', $result['communities'][1]['channel_type'] );
	}

	#[Test]
	public function a_member_agend_cannot_match_yet_has_an_empty_list_not_an_error(): void {
		$this->answer( array(), false );

		$result = Agend_Apps_Loop_My_Communities::for_current_user();

		$this->assertSame( 'ok', $result['state'] );
		$this->assertSame( array(), $result['communities'] );
	}

	#[Test]
	public function a_member_in_no_channels_has_an_empty_list(): void {
		$this->answer( array() );

		$result = Agend_Apps_Loop_My_Communities::for_current_user();

		$this->assertSame( 'ok', $result['state'] );
		$this->assertSame( array(), $result['communities'] );
	}

	#[Test]
	public function an_older_gateway_with_no_channels_key_is_unavailable_not_empty(): void {
		Agend_Test_WP::queue_response( 200, array( 'success' => true, 'data' => array( 'in_active_channel' => true, 'resolved' => true ) ) );

		$result = Agend_Apps_Loop_My_Communities::for_current_user();

		$this->assertSame( 'unavailable', $result['state'] );
		$this->assertSame( array(), $result['communities'] );
		$this->assertContains( Agend_Apps_Loop_My_Communities::FAILURE_TTL, Agend_Test_WP::$transient_ttls );
	}

	#[Test]
	public function a_channels_value_that_is_not_a_list_is_unavailable(): void {
		Agend_Test_WP::queue_response( 200, array( 'success' => true, 'data' => array( 'resolved' => true, 'channels' => 'oops' ) ) );

		$this->assertSame( 'unavailable', Agend_Apps_Loop_My_Communities::for_current_user()['state'] );
	}

	#[Test]
	public function malformed_channel_entries_are_skipped(): void {
		$this->answer(
			array(
				'nonsense',
				array( 'id' => 'x', 'name' => 'No slug' ),
				array( 'id' => '', 'name' => 'No id', 'slug' => 'no-id' ),
				array( 'id' => 'y', 'name' => '   ', 'slug' => 'blank-name' ),
				array( 'id' => array( 'nested' ), 'name' => 'Array id', 'slug' => 'array-id' ),
				$this->channel( 'good' ),
			)
		);

		$result = Agend_Apps_Loop_My_Communities::for_current_user();

		$this->assertSame( 'ok', $result['state'] );
		$this->assertSame( array( 'Good' ), array_column( $result['communities'], 'name' ) );
	}

	#[Test]
	public function each_refusal_is_a_refused_state_remembered_for_a_while(): void {
		foreach ( array( 'SOURCE_NOT_ALLOWED', 'INSUFFICIENT_SCOPES', 'APP_NOT_ALLOWED' ) as $i => $code ) {
			update_user_meta( self::USER, 'imk_membership_number', 'M10' . $i );
			$this->error_response( 403, $code );

			$this->assertSame( 'refused', Agend_Apps_Loop_My_Communities::for_current_user()['state'], $code );
			$this->assertSame( 'refused', Agend_Apps_Loop_My_Communities::for_current_user()['state'], $code );
		}

		$this->assertCount( 3, Agend_Test_WP::$requests );
		$this->assertContains( Agend_Apps_Loop_My_Communities::REFUSED_TTL, Agend_Test_WP::$transient_ttls );
	}

	#[Test]
	public function a_key_known_to_lack_the_scope_sends_nothing(): void {
		Agend_Apps_Key_Scopes::store_from_response( array( 'data' => array( 'scopes' => array( 'jobs.submissions.browse' ), 'app_ids' => array() ) ) );

		$this->assertSame( 'refused', Agend_Apps_Loop_My_Communities::for_current_user()['state'] );
		$this->assertCount( 0, Agend_Test_WP::$requests );
	}

	#[Test]
	public function a_server_error_is_unavailable_and_remembered_briefly(): void {
		$this->error_response( 500, 'DATABASE_ERROR' );

		$this->assertSame( 'unavailable', Agend_Apps_Loop_My_Communities::for_current_user()['state'] );
		$this->assertSame( 'unavailable', Agend_Apps_Loop_My_Communities::for_current_user()['state'] );
		$this->assertCount( 1, Agend_Test_WP::$requests );
		$this->assertContains( Agend_Apps_Loop_My_Communities::FAILURE_TTL, Agend_Test_WP::$transient_ttls );
	}

	#[Test]
	public function a_rate_limit_and_a_validation_error_are_unavailable(): void {
		$this->error_response( 429, 'RATE_LIMITED' );
		$this->assertSame( 'unavailable', Agend_Apps_Loop_My_Communities::for_current_user()['state'] );

		update_user_meta( self::USER, 'imk_membership_number', 'M2' );
		$this->error_response( 400, 'VALIDATION_ERROR' );
		$this->assertSame( 'unavailable', Agend_Apps_Loop_My_Communities::for_current_user()['state'] );
	}

	// -----------------------------------------------------------------
	// Caching
	// -----------------------------------------------------------------

	#[Test]
	public function the_answer_is_cached_for_five_minutes_per_contact_pair(): void {
		$this->answer( array( $this->channel( 'one' ) ) );
		$this->answer( array( $this->channel( 'two' ) ) );
		$this->answer( array( $this->channel( 'three' ) ) );

		$this->assertSame( array( 'One' ), array_column( Agend_Apps_Loop_My_Communities::for_current_user()['communities'], 'name' ) );
		$this->assertSame( array( 'One' ), array_column( Agend_Apps_Loop_My_Communities::for_current_user()['communities'], 'name' ) );
		$this->assertCount( 1, Agend_Test_WP::$requests );
		$this->assertContains( Agend_Apps_Loop_My_Communities::CACHE_TTL, Agend_Test_WP::$transient_ttls );
		$this->assertSame( 300, Agend_Apps_Loop_My_Communities::CACHE_TTL );

		update_user_meta( self::USER, 'imk_membership_number', 'M2002' );
		$this->assertSame( array( 'Two' ), array_column( Agend_Apps_Loop_My_Communities::for_current_user()['communities'], 'name' ) );

		update_option( 'agend_apps_jobs_contact_source', 'other-crm' );
		$this->assertSame( array( 'Three' ), array_column( Agend_Apps_Loop_My_Communities::for_current_user()['communities'], 'name' ) );

		$this->assertCount( 3, Agend_Test_WP::$requests );
	}

	#[Test]
	public function the_cache_is_per_member(): void {
		$this->answer( array( $this->channel( 'mine' ) ) );
		$this->answer( array( $this->channel( 'theirs' ) ) );

		Agend_Apps_Loop_My_Communities::for_current_user();

		update_user_meta( 43, 'imk_membership_number', 'M1001' );
		$GLOBALS['agend_test_current_user_id'] = 43;

		$this->assertSame( array( 'Theirs' ), array_column( Agend_Apps_Loop_My_Communities::for_current_user()['communities'], 'name' ) );
		$this->assertCount( 2, Agend_Test_WP::$requests );
	}

	#[Test]
	public function a_setting_change_changes_the_links_without_waiting_for_the_cache(): void {
		$this->answer( array( $this->channel( 'fellows' ) ) );

		$before = Agend_Apps_Loop_My_Communities::for_current_user()['communities'][0]['access_url'];

		update_option( 'agend_apps_community_sso_connection', 'wp-saml-abc' );
		$after = Agend_Apps_Loop_My_Communities::for_current_user()['communities'][0]['access_url'];

		$this->assertSame( 'https://api.agend.test/sso/smsf/loop-channel/fellows', $before );
		$this->assertSame( 'https://api.agend.test/sso/smsf/loop-channel/fellows?idp=wp-saml-abc', $after );
		$this->assertCount( 1, Agend_Test_WP::$requests );
	}

	// -----------------------------------------------------------------
	// Access links
	// -----------------------------------------------------------------

	#[Test]
	public function the_link_is_the_agend_host_the_account_and_the_channel_slug_encoded(): void {
		$url = Agend_Apps_Loop_My_Communities::access_url( array( 'slug' => 'a b/c' ), self::USER );

		$this->assertSame( 'https://api.agend.test/sso/smsf/loop-channel/a%20b%2Fc', $url );
	}

	#[Test]
	public function the_connection_slug_is_added_as_idp_and_cleaned(): void {
		update_option( 'agend_apps_community_sso_connection', 'wp saml&x=1' );

		$this->assertSame( 'https://api.agend.test/sso/smsf/loop-channel/f?idp=wpsamlx1', Agend_Apps_Loop_My_Communities::access_url( array( 'slug' => 'f' ), self::USER ) );
	}

	#[Test]
	public function the_sso_base_comes_from_the_configured_gateway_and_a_filter_can_change_it(): void {
		update_option( 'agend_apps_root_url_for_tests', 'https://api.agend.test/' );
		$this->assertSame( 'https://api.agend.test', Agend_Apps_Loop_My_Communities::sso_base_url() );

		Agend_Test_WP::set_filter( 'agend_apps_loop_sso_base_url', 'https://sso.example.org/' );
		$this->assertSame( 'https://sso.example.org', Agend_Apps_Loop_My_Communities::sso_base_url() );
		$this->assertSame( 'https://sso.example.org/sso/smsf/loop-channel/f', Agend_Apps_Loop_My_Communities::access_url( array( 'slug' => 'f' ), self::USER ) );
	}

	#[Test]
	public function without_an_account_slug_there_is_no_link_and_the_list_still_shows(): void {
		update_option( 'agend_apps_account_slug', '' );
		$this->answer( array( $this->channel( 'fellows' ) ) );

		$result = Agend_Apps_Loop_My_Communities::for_current_user();

		$this->assertSame( 'ok', $result['state'] );
		$this->assertFalse( $result['links'] );
		$this->assertSame( '', $result['communities'][0]['access_url'] );
		$this->assertSame( 'Fellows', $result['communities'][0]['name'] );
	}

	#[Test]
	public function the_access_url_filter_gets_the_url_the_channel_and_the_user(): void {
		Agend_Test_WP::$filters['agend_apps_loop_channel_access_url'] = static function ( string $url, array $channel, int $user_id ): string {
			return $url . '#' . $channel['slug'] . '-' . $user_id;
		};

		$this->assertSame( 'https://api.agend.test/sso/smsf/loop-channel/f#f-42', Agend_Apps_Loop_My_Communities::access_url( array( 'slug' => 'f' ), self::USER ) );
	}

	#[Test]
	public function channel_types_can_be_excluded_by_filter_and_none_are_by_default(): void {
		$this->answer(
			array(
				$this->channel( 'a', array( 'channel_type' => 'channel' ) ),
				$this->channel( 'b', array( 'channel_type' => 'committee' ) ),
				$this->channel( 'c', array( 'channel_type' => 'space' ) ),
			)
		);

		$this->assertCount( 3, Agend_Apps_Loop_My_Communities::for_current_user()['communities'] );

		Agend_Test_WP::set_filter( 'agend_apps_loop_my_communities_excluded_types', array( 'committee', 'space' ) );

		$this->assertSame( array( 'A' ), array_column( Agend_Apps_Loop_My_Communities::for_current_user()['communities'], 'name' ) );
	}

	#[Test]
	public function the_connection_slug_sanitiser_keeps_only_slug_characters(): void {
		$this->assertSame( 'wp-saml_1', agend_apps_community_clean_connection_slug( ' wp-saml_1 !' ) );
		$this->assertSame( '', agend_apps_community_clean_connection_slug( array( 'x' ) ) );
		$this->assertSame( 100, strlen( agend_apps_community_clean_connection_slug( str_repeat( 'a', 150 ) ) ) );
	}
}
