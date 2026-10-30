<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Webhook_Gateway as Gateway;
use Agend_Content_Access_Webhook_Panel as Panel;
use Agend_Content_Access_Webhook_Setup as Setup;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use WP_Error;

require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-webhook-gateway.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-webhook-setup.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/admin/class-agend-content-access-webhook-panel.php';

/**
 * A gateway that records what it was asked and answers from a script.
 */
final class FakeWebhookGateway implements Gateway {

	public bool $available = true;

	public ?bool $can_manage = true;

	/** @var array|WP_Error */
	public $list = array( 'data' => array() );

	/** @var array<int, array|WP_Error> Answers to successive create calls. */
	public array $create_answers = array();

	/** @var array<int, array|WP_Error> Answers to successive update calls. */
	public array $update_answers = array();

	/** @var array|WP_Error */
	public $rotate = array( 'data' => array( 'secret' => 'rotated-secret' ) );

	/** @var array|WP_Error */
	public $test = array( 'data' => array( 'status' => 'sent' ) );

	/** @var array<int, array> */
	public array $creates = array();

	/** @var array<int, array{0: string, 1: array}> */
	public array $updates = array();

	/** @var string[] */
	public array $rotates = array();

	/** @var string[] */
	public array $tests = array();

	public int $lists = 0;

	public function available(): bool {
		return $this->available;
	}

	public function can_manage(): ?bool {
		return $this->can_manage;
	}

	public function list_subscriptions() {
		++$this->lists;

		return $this->list;
	}

	public function create( array $payload ) {
		$this->creates[] = $payload;

		return array_shift( $this->create_answers ) ?? array(
			'data' => array(
				'id'     => 'sub-new',
				'secret' => 'created-secret',
			),
		);
	}

	public function update( string $id, array $payload ) {
		$this->updates[] = array( $id, $payload );

		return array_shift( $this->update_answers ) ?? array( 'data' => array( 'id' => $id ) );
	}

	public function rotate( string $id ) {
		$this->rotates[] = $id;

		return $this->rotate;
	}

	public function test( string $id ) {
		$this->tests[] = $id;

		return $this->test;
	}
}

/**
 * The one-click webhook setup.
 *
 * What these tests protect, in order of how badly it goes wrong: a second
 * subscription for the same site (every delivery would arrive twice and Agend's
 * retry load would double), a signing secret that leaks into a result or is
 * overwritten without consent (deliveries would then fail verification), and an
 * action taken when the account cannot be read or has duplicates.
 */
#[CoversClass( Setup::class )]
#[CoversClass( Panel::class )]
final class WebhookSetupTest extends TestCase {

	private const RECEIVER = 'https://example.test/wp-json/agend-apps/v1/webhooks/incoming';

	private FakeWebhookGateway $gateway;

	protected function setUp(): void {
		parent::setUp();

		$this->gateway = new FakeWebhookGateway();

		unset( $GLOBALS['agend_test_current_user_can']['manage_options'] );
	}

	protected function tearDown(): void {
		unset( $_POST[ Panel::FIELD ], $_POST['agend_content_access_include_access'], $GLOBALS['agend_test_current_user_can']['manage_options'] );

		parent::tearDown();
	}

	private function setup_under_test(): Setup {
		return new Setup( $this->gateway );
	}

	/** @param string[] $events */
	private function subscription( string $id, string $url, array $events, bool $active = true ): array {
		return array(
			'id'     => $id,
			'url'    => $url,
			'events' => $events,
			'active' => $active,
		);
	}

	private function listing( array ...$subscriptions ): void {
		$this->gateway->list = array( 'data' => $subscriptions );
	}

	private function complete_events(): array {
		return $this->setup_under_test()->wanted_events( true );
	}

	/** @return array<string, array{0: string, 1: string, 2: bool}> */
	public static function receiverPairs(): array {
		return array(
			'identical'                 => array( self::RECEIVER, self::RECEIVER, true ),
			'trailing slash'            => array( self::RECEIVER, self::RECEIVER . '/', true ),
			'http against https'        => array( 'http://example.test/wp-json/agend-apps/v1/webhooks/incoming', self::RECEIVER, true ),
			'host case'                 => array( 'https://EXAMPLE.test/wp-json/agend-apps/v1/webhooks/incoming', self::RECEIVER, true ),
			'default port'              => array( 'https://example.test:443/wp-json/agend-apps/v1/webhooks/incoming', self::RECEIVER, true ),
			'plain permalink spelling'  => array( 'https://example.test/?rest_route=/agend-apps/v1/webhooks/incoming', self::RECEIVER, true ),
			'index.php spelling'        => array( 'https://example.test/index.php?rest_route=/agend-apps/v1/webhooks/incoming', self::RECEIVER, true ),
			'different route'           => array( 'https://example.test/wp-json/agend-apps/v1/webhooks/other', self::RECEIVER, false ),
			'different host'            => array( 'https://other.test/wp-json/agend-apps/v1/webhooks/incoming', self::RECEIVER, false ),
			'different site base path'  => array( 'https://example.test/blog/wp-json/agend-apps/v1/webhooks/incoming', self::RECEIVER, false ),
			'non default port'          => array( 'https://example.test:8443/wp-json/agend-apps/v1/webhooks/incoming', self::RECEIVER, false ),
			'not a url'                 => array( 'not a url', self::RECEIVER, false ),
			'empty'                     => array( '', '', false ),
		);
	}

	#[Test]
	#[DataProvider( 'receiverPairs' )]
	public function two_urls_name_the_same_receiver_only_when_host_site_path_and_route_match( string $a, string $b, bool $expected ): void {
		$this->assertSame( $expected, Setup::same_receiver( $a, $b ) );
	}

	#[Test]
	public function the_receiver_is_cores_incoming_webhook_route(): void {
		$this->assertSame( self::RECEIVER, $this->setup_under_test()->receiver_url() );
	}

	#[Test]
	public function the_lifecycle_events_are_always_wanted_and_the_access_events_only_when_chosen(): void {
		$setup = $this->setup_under_test();

		$this->assertSame( Setup::LIFECYCLE_EVENTS, $setup->wanted_events( false ) );
		$this->assertSame( array_merge( Setup::LIFECYCLE_EVENTS, Setup::ACCESS_EVENTS ), $setup->wanted_events( true ) );
		$this->assertContains( 'crm.membership.lapsed', $setup->wanted_events( false ) );
		$this->assertContains( 'crm.seat.removed', $setup->wanted_events( false ) );
	}

	#[Test]
	public function the_access_events_are_wanted_by_default_and_stay_off_once_turned_off(): void {
		$setup = $this->setup_under_test();

		$this->assertTrue( $setup->include_access_events() );

		update_option( Setup::ACCESS_OPTION, '0' );

		$this->assertFalse( $setup->include_access_events() );
	}

	#[Test]
	public function status_is_unavailable_without_calling_the_gateway_when_core_cannot_manage_subscriptions(): void {
		$this->gateway->available = false;

		$status = $this->setup_under_test()->status();

		$this->assertSame( 'unavailable', $status['state'] );
		$this->assertSame( 0, $this->gateway->lists );
	}

	#[Test]
	public function status_is_cannot_manage_without_calling_the_gateway_when_the_key_lacks_the_scope(): void {
		$this->gateway->can_manage = false;

		$status = $this->setup_under_test()->status();

		$this->assertSame( 'cannot_manage', $status['state'] );
		$this->assertSame( 0, $this->gateway->lists );
	}

	#[Test]
	public function an_unknown_scope_state_does_not_block_the_attempt(): void {
		$this->gateway->can_manage = null;
		$this->listing();

		$this->assertSame( 'not_subscribed', $this->setup_under_test()->status()['state'] );
	}

	#[Test]
	public function status_reports_an_error_with_the_gateways_text_and_http_status_when_the_list_cannot_be_read(): void {
		$this->gateway->list = new WP_Error( 'agend_api_error', 'Forbidden', array( 'status_code' => 403 ) );

		$status = $this->setup_under_test()->status();

		$this->assertSame( 'error', $status['state'] );
		$this->assertSame( 'Forbidden (HTTP 403)', $status['message'] );
	}

	#[Test]
	public function status_is_not_subscribed_when_no_subscription_points_at_this_site(): void {
		$this->listing( $this->subscription( 'other', 'https://other.test/hook', array( 'crm.membership.lapsed' ) ) );

		$this->assertSame( 'not_subscribed', $this->setup_under_test()->status()['state'] );
	}

	#[Test]
	public function status_is_subscribed_when_one_active_subscription_carries_every_wanted_event(): void {
		$this->listing( $this->subscription( 'sub-1', self::RECEIVER, $this->complete_events() ) );

		$status = $this->setup_under_test()->status();

		$this->assertSame( 'subscribed', $status['state'] );
		$this->assertSame( 'sub-1', $status['subscription']['id'] );
		$this->assertSame( array(), $status['missing_events'] );
	}

	#[Test]
	public function status_is_incomplete_and_names_the_missing_events_when_the_subscription_lacks_some(): void {
		$this->listing( $this->subscription( 'sub-1', self::RECEIVER, Setup::LIFECYCLE_EVENTS ) );

		$status = $this->setup_under_test()->status();

		$this->assertSame( 'incomplete', $status['state'] );
		$this->assertSame( Setup::ACCESS_EVENTS, $status['missing_events'] );
	}

	#[Test]
	public function status_is_complete_for_a_lifecycle_only_subscription_once_the_access_events_are_turned_off(): void {
		update_option( Setup::ACCESS_OPTION, '0' );
		$this->listing( $this->subscription( 'sub-1', self::RECEIVER, Setup::LIFECYCLE_EVENTS ) );

		$this->assertSame( 'subscribed', $this->setup_under_test()->status()['state'] );
	}

	#[Test]
	public function status_is_incomplete_when_a_complete_subscription_is_paused(): void {
		$this->listing( $this->subscription( 'sub-1', self::RECEIVER, $this->complete_events(), false ) );

		$status = $this->setup_under_test()->status();

		$this->assertSame( 'incomplete', $status['state'] );
		$this->assertFalse( $status['subscription']['active'] );
	}

	#[Test]
	public function status_lists_the_ids_and_names_no_subscription_when_more_than_one_points_here(): void {
		$this->listing(
			$this->subscription( 'sub-1', self::RECEIVER, $this->complete_events() ),
			$this->subscription( 'sub-2', self::RECEIVER . '/', $this->complete_events() )
		);

		$status = $this->setup_under_test()->status();

		$this->assertSame( 'duplicates', $status['state'] );
		$this->assertSame( array( 'sub-1', 'sub-2' ), $status['duplicates'] );
		$this->assertNull( $status['subscription'] );
	}

	#[Test]
	public function status_says_whether_a_secret_is_stored_and_never_returns_it(): void {
		$this->listing();

		$this->assertFalse( $this->setup_under_test()->status()['secret_stored'] );

		update_option( Setup::SECRET_OPTION, 'a-stored-secret' );
		$status = $this->setup_under_test()->status();

		$this->assertTrue( $status['secret_stored'] );
		$this->assertStringNotContainsString( 'a-stored-secret', (string) json_encode( $status ) );
	}

	#[Test]
	public function subscribe_creates_one_subscription_for_this_receiver_with_every_wanted_event(): void {
		$this->listing();

		$result = $this->setup_under_test()->subscribe( true );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'created', $result['code'] );
		$this->assertCount( 1, $this->gateway->creates );
		$this->assertSame( self::RECEIVER, $this->gateway->creates[0]['url'] );
		$this->assertSame( Setup::DESCRIPTION, $this->gateway->creates[0]['description'] );
		$this->assertSame( $this->complete_events(), $this->gateway->creates[0]['events'] );
	}

	#[Test]
	public function subscribe_stores_the_secret_where_cores_receiver_reads_it(): void {
		$this->listing();

		$this->setup_under_test()->subscribe( true );

		$this->assertSame( 'created-secret', get_option( 'agend_apps_webhook_secret' ) );
	}

	#[Test]
	public function subscribe_never_puts_the_secret_in_its_result(): void {
		$this->listing();

		$result = $this->setup_under_test()->subscribe( true );

		$this->assertStringNotContainsString( 'created-secret', (string) json_encode( $result ) );
	}

	#[Test]
	public function subscribe_remembers_a_lifecycle_only_choice(): void {
		$this->listing();

		$this->setup_under_test()->subscribe( false );

		$this->assertSame( Setup::LIFECYCLE_EVENTS, $this->gateway->creates[0]['events'] );
		$this->assertFalse( $this->setup_under_test()->include_access_events() );
	}

	#[Test]
	public function subscribe_creates_nothing_when_a_subscription_already_points_at_this_site(): void {
		$this->listing( $this->subscription( 'sub-1', self::RECEIVER, $this->complete_events() ) );

		$result = $this->setup_under_test()->subscribe( true );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'already_subscribed', $result['code'] );
		$this->assertSame( array(), $this->gateway->creates );
	}

	#[Test]
	public function subscribe_finds_an_existing_subscription_written_in_the_other_url_spelling(): void {
		$this->listing( $this->subscription( 'sub-1', 'https://example.test/?rest_route=/agend-apps/v1/webhooks/incoming', Setup::LIFECYCLE_EVENTS ) );

		$result = $this->setup_under_test()->subscribe( true );

		$this->assertSame( 'already_subscribed', $result['code'] );
		$this->assertSame( array(), $this->gateway->creates );
	}

	#[Test]
	public function subscribe_creates_nothing_when_more_than_one_subscription_already_points_here(): void {
		$this->listing(
			$this->subscription( 'sub-1', self::RECEIVER, $this->complete_events() ),
			$this->subscription( 'sub-2', self::RECEIVER, $this->complete_events() )
		);

		$result = $this->setup_under_test()->subscribe( true );

		$this->assertSame( 'duplicates', $result['code'] );
		$this->assertSame( array(), $this->gateway->creates );
	}

	#[Test]
	public function a_second_subscribe_after_a_successful_one_does_not_create_again(): void {
		$this->listing();
		$setup = $this->setup_under_test();

		$setup->subscribe( true );
		$this->listing( $this->subscription( 'sub-new', self::RECEIVER, $this->complete_events() ) );
		$again = $setup->subscribe( true );

		$this->assertSame( 'already_subscribed', $again['code'] );
		$this->assertCount( 1, $this->gateway->creates );
	}

	#[Test]
	public function subscribe_refuses_to_overwrite_a_stored_secret_unless_told_to(): void {
		$this->listing();
		update_option( Setup::SECRET_OPTION, 'working-secret' );

		$result = $this->setup_under_test()->subscribe( true );

		$this->assertSame( 'secret_stored', $result['code'] );
		$this->assertSame( array(), $this->gateway->creates );
		$this->assertSame( 'working-secret', get_option( Setup::SECRET_OPTION ) );
	}

	#[Test]
	public function subscribe_overwrites_a_stored_secret_when_told_to(): void {
		$this->listing();
		update_option( Setup::SECRET_OPTION, 'old-secret' );

		$result = $this->setup_under_test()->subscribe( true, true );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'created-secret', get_option( Setup::SECRET_OPTION ) );
	}

	#[Test]
	public function subscribe_drops_the_access_events_and_says_so_when_the_gateway_rejects_them(): void {
		$this->listing();
		$this->gateway->create_answers = array(
			new WP_Error( 'agend_api_error', 'Unknown event', array( 'status_code' => 422 ) ),
		);

		$result = $this->setup_under_test()->subscribe( true );

		$this->assertTrue( $result['ok'] );
		$this->assertCount( 2, $this->gateway->creates );
		$this->assertSame( Setup::LIFECYCLE_EVENTS, $this->gateway->creates[1]['events'] );
		$this->assertSame( Setup::ACCESS_EVENTS, $result['omitted'] );
	}

	#[Test]
	public function subscribe_does_not_retry_when_the_gateway_fails_for_another_reason(): void {
		$this->listing();
		$this->gateway->create_answers = array(
			new WP_Error( 'agend_api_error', 'Server error', array( 'status_code' => 500 ) ),
		);

		$result = $this->setup_under_test()->subscribe( true );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'create_failed', $result['code'] );
		$this->assertCount( 1, $this->gateway->creates );
		$this->assertFalse( get_option( Setup::SECRET_OPTION, false ) );
	}

	#[Test]
	public function subscribe_reports_a_missing_secret_without_touching_a_stored_one(): void {
		$this->listing();
		$this->gateway->create_answers = array( array( 'data' => array( 'id' => 'sub-new' ) ) );

		$result = $this->setup_under_test()->subscribe( true );

		$this->assertSame( 'secret_missing', $result['code'] );
		$this->assertFalse( get_option( Setup::SECRET_OPTION, false ) );
	}

	#[Test]
	public function subscribe_does_nothing_while_another_setup_action_holds_the_lock(): void {
		$this->listing();
		set_transient( Setup::LOCK, '1', 30 );

		$result = $this->setup_under_test()->subscribe( true );

		$this->assertSame( 'busy', $result['code'] );
		$this->assertSame( array(), $this->gateway->creates );
	}

	#[Test]
	public function the_lock_is_released_when_an_action_finishes(): void {
		$this->listing();

		$this->setup_under_test()->subscribe( true );

		$this->assertFalse( get_transient( Setup::LOCK ) );
	}

	#[Test]
	public function subscribe_stops_at_cannot_manage_before_creating_anything(): void {
		$this->gateway->can_manage = false;

		$result = $this->setup_under_test()->subscribe( true );

		$this->assertSame( 'cannot_manage', $result['code'] );
		$this->assertSame( array(), $this->gateway->creates );
	}

	#[Test]
	public function complete_adds_only_what_is_missing_and_keeps_events_it_does_not_know(): void {
		$this->listing( $this->subscription( 'sub-1', self::RECEIVER, array( 'crm.membership.lapsed', 'events.event.published' ) ) );

		$result = $this->setup_under_test()->complete();

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'updated', $result['code'] );
		$this->assertCount( 1, $this->gateway->updates );
		$this->assertSame( 'sub-1', $this->gateway->updates[0][0] );
		$this->assertContains( 'events.event.published', $this->gateway->updates[0][1]['events'] );
		$this->assertSame( $this->gateway->updates[0][1]['events'], array_values( array_unique( $this->gateway->updates[0][1]['events'] ) ) );
		$this->assertEqualsCanonicalizing(
			array_merge( array( 'events.event.published' ), $this->complete_events() ),
			$this->gateway->updates[0][1]['events']
		);
	}

	#[Test]
	public function complete_switches_a_paused_subscription_back_on(): void {
		$this->listing( $this->subscription( 'sub-1', self::RECEIVER, $this->complete_events(), false ) );

		$this->setup_under_test()->complete();

		$this->assertTrue( $this->gateway->updates[0][1]['active'] );
	}

	#[Test]
	public function complete_changes_nothing_when_the_subscription_is_already_complete(): void {
		$this->listing( $this->subscription( 'sub-1', self::RECEIVER, $this->complete_events() ) );

		$result = $this->setup_under_test()->complete();

		$this->assertSame( 'nothing_to_add', $result['code'] );
		$this->assertSame( array(), $this->gateway->updates );
	}

	#[Test]
	public function complete_changes_nothing_when_there_is_no_subscription_or_there_are_duplicates(): void {
		$this->listing();
		$this->assertSame( 'not_subscribed', $this->setup_under_test()->complete()['code'] );

		$this->listing(
			$this->subscription( 'sub-1', self::RECEIVER, array() ),
			$this->subscription( 'sub-2', self::RECEIVER, array() )
		);
		$this->assertSame( 'duplicates', $this->setup_under_test()->complete()['code'] );
		$this->assertSame( array(), $this->gateway->updates );
	}

	#[Test]
	public function complete_leaves_out_access_events_the_gateway_rejects_and_says_so(): void {
		$this->listing( $this->subscription( 'sub-1', self::RECEIVER, Setup::LIFECYCLE_EVENTS ) );
		$this->gateway->update_answers = array(
			new WP_Error( 'agend_api_error', 'Unknown event', array( 'status_code' => 400 ) ),
		);

		$result = $this->setup_under_test()->complete();

		$this->assertTrue( $result['ok'] );
		$this->assertCount( 2, $this->gateway->updates );
		$this->assertSame( Setup::LIFECYCLE_EVENTS, $this->gateway->updates[1][1]['events'] );
		$this->assertSame( Setup::ACCESS_EVENTS, $result['omitted'] );
	}

	#[Test]
	public function complete_can_switch_the_access_events_off_and_remembers_it(): void {
		$this->listing( $this->subscription( 'sub-1', self::RECEIVER, Setup::LIFECYCLE_EVENTS ) );

		$result = $this->setup_under_test()->complete( false );

		$this->assertSame( 'nothing_to_add', $result['code'] );
		$this->assertFalse( $this->setup_under_test()->include_access_events() );
	}

	#[Test]
	public function rotate_secret_stores_the_new_secret_for_the_one_subscription_here(): void {
		$this->listing( $this->subscription( 'sub-1', self::RECEIVER, $this->complete_events() ) );

		$result = $this->setup_under_test()->rotate_secret();

		$this->assertTrue( $result['ok'] );
		$this->assertSame( array( 'sub-1' ), $this->gateway->rotates );
		$this->assertSame( 'rotated-secret', get_option( Setup::SECRET_OPTION ) );
		$this->assertStringNotContainsString( 'rotated-secret', (string) json_encode( $result ) );
	}

	#[Test]
	public function rotate_secret_does_nothing_without_exactly_one_subscription_here(): void {
		$this->listing();
		$this->assertSame( 'not_subscribed', $this->setup_under_test()->rotate_secret()['code'] );

		$this->listing(
			$this->subscription( 'sub-1', self::RECEIVER, array() ),
			$this->subscription( 'sub-2', self::RECEIVER, array() )
		);
		$this->assertSame( 'duplicates', $this->setup_under_test()->rotate_secret()['code'] );
		$this->assertSame( array(), $this->gateway->rotates );
	}

	#[Test]
	public function rotate_secret_keeps_the_stored_secret_when_the_gateway_fails(): void {
		$this->listing( $this->subscription( 'sub-1', self::RECEIVER, $this->complete_events() ) );
		update_option( Setup::SECRET_OPTION, 'working-secret' );
		$this->gateway->rotate = new WP_Error( 'agend_api_error', 'Nope', array( 'status_code' => 500 ) );

		$result = $this->setup_under_test()->rotate_secret();

		$this->assertSame( 'rotate_failed', $result['code'] );
		$this->assertSame( 'working-secret', get_option( Setup::SECRET_OPTION ) );
	}

	#[Test]
	public function send_test_asks_the_gateway_to_test_the_one_subscription_here(): void {
		$this->listing( $this->subscription( 'sub-1', self::RECEIVER, $this->complete_events() ) );

		$result = $this->setup_under_test()->send_test();

		$this->assertTrue( $result['ok'] );
		$this->assertSame( array( 'sub-1' ), $this->gateway->tests );
	}

	#[Test]
	public function send_test_does_nothing_without_a_subscription(): void {
		$this->listing();

		$result = $this->setup_under_test()->send_test();

		$this->assertSame( 'not_subscribed', $result['code'] );
		$this->assertSame( array(), $this->gateway->tests );
	}

	/** @return array<string, array{0: string}> */
	public static function resultCodes(): array {
		$codes = array(
			'created', 'updated', 'rotated', 'tested', 'unavailable', 'cannot_manage', 'error', 'duplicates',
			'already_subscribed', 'nothing_to_add', 'not_subscribed', 'secret_stored', 'secret_missing', 'busy',
			'confirm_required', 'create_failed', 'update_failed', 'rotate_failed', 'test_failed',
		);

		return array_combine( $codes, array_map( static fn ( string $code ): array => array( $code ), $codes ) );
	}

	#[Test]
	#[DataProvider( 'resultCodes' )]
	public function every_result_code_has_its_own_sentence( string $code ): void {
		$message = Panel::message( array( 'code' => $code, 'message' => 'detail', 'omitted' => array() ) );

		$this->assertNotSame( '', $message );
		$this->assertNotSame( Panel::message( array( 'code' => 'no-such-code' ) ), $message );
	}

	#[Test]
	public function an_unknown_result_code_still_gets_a_sentence(): void {
		$this->assertSame( 'The request could not be completed.', Panel::message( array( 'code' => 'no-such-code' ) ) );
	}

	#[Test]
	public function the_message_for_a_created_webhook_names_events_the_gateway_did_not_recognise(): void {
		$message = Panel::message( array( 'code' => 'created', 'omitted' => array( 'crm.segment.updated' ) ) );

		$this->assertStringContainsString( 'crm.segment.updated', $message );
	}

	#[Test]
	public function the_panel_runs_no_action_for_someone_without_manage_options(): void {
		$GLOBALS['agend_test_current_user_can']['manage_options'] = false;
		$_POST[ Panel::FIELD ]                                    = 'subscribe';
		$this->listing();

		( new Panel( $this->setup_under_test() ) )->handle_action();

		$this->assertSame( 0, $this->gateway->lists );
		$this->assertSame( array(), $this->gateway->creates );
	}

	#[Test]
	public function the_panel_runs_the_subscribe_action_for_an_administrator(): void {
		$_POST[ Panel::FIELD ] = 'subscribe';
		$this->listing();

		( new Panel( $this->setup_under_test() ) )->handle_action();

		$this->assertCount( 1, $this->gateway->creates );
	}

	#[Test]
	public function the_panel_refuses_to_replace_a_secret_without_the_confirmation_box(): void {
		$_POST[ Panel::FIELD ] = 'rotate';
		$this->listing( $this->subscription( 'sub-1', self::RECEIVER, $this->complete_events() ) );

		( new Panel( $this->setup_under_test() ) )->handle_action();

		$this->assertSame( array(), $this->gateway->rotates );
	}
}
