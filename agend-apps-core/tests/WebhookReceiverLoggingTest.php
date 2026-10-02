<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\AppsCore;

use Agend_Apps_Logger;
use Agend_Apps_Webhook_Receiver_REST_Controller;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use WP_REST_Request;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/logging/class-agend-apps-log-redactor.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/logging/class-agend-apps-log-store.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/logging/class-agend-apps-logger.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/rest/class-agend-apps-rest-controller.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/rest/webhook-receiver-routes.php';
require_once __DIR__ . '/fixtures/memory-log-store.php';

/**
 * Every delivery to the webhook receiver records one inbound row, whatever
 * its outcome, and the signature never reaches it.
 */
#[CoversClass( Agend_Apps_Webhook_Receiver_REST_Controller::class )]
final class WebhookReceiverLoggingTest extends TestCase {

	private const SECRET = 'whsec_test';

	protected function setUp(): void {
		parent::setUp();
		Agend_Apps_Logger::set_instance( new Agend_Apps_Logger( new MemoryLogStore() ) );
		update_option( 'agend_apps_webhook_secret', self::SECRET );
	}

	private function delivery( string $body, string $event_id, ?string $signature = null ): WP_REST_Request {
		$timestamp = (string) time();
		$request   = new WP_REST_Request( 'POST', '/agend-apps/v1/webhooks/incoming' );
		$request->set_body( $body );
		$request->set_header( 'X-Agend-Event-Id', $event_id );
		$request->set_header( 'X-Agend-Signature', $signature ?? 't=' . $timestamp . ',v1=' . hash_hmac( 'sha256', $timestamp . '.' . $body, self::SECRET ) );

		return $request;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function onlyRow(): array {
		$rows = agend_apps_logger()->pending();
		$this->assertCount( 1, $rows );

		return $rows[0];
	}

	#[Test]
	public function should_record_an_inbound_success_with_the_event_type_and_id_when_a_delivery_is_verified(): void {
		$body = (string) json_encode( array( 'type' => 'crm.other.thing', 'data' => array( 'email' => 'jane@example.com' ) ) );

		$response = ( new Agend_Apps_Webhook_Receiver_REST_Controller() )->ingest( $this->delivery( $body, 'evt_1' ) );

		$this->assertSame( 200, $response->get_status() );
		$row = $this->onlyRow();
		$this->assertSame( 'inbound', $row['direction'] );
		$this->assertSame( 'success', $row['outcome'] );
		$this->assertSame( 'crm.other.thing', $row['event_type'] );
		$this->assertSame( 'evt_1', $row['gateway_request_id'] );
		$this->assertSame( 'signature', $row['auth_mode'] );
		$this->assertStringNotContainsString( 'jane@example.com', $row['request_body'] );
	}

	#[Test]
	public function should_record_a_signature_failure_without_storing_the_signature(): void {
		$body = (string) json_encode( array( 'type' => 'crm.membership.lapsed' ) );

		$response = ( new Agend_Apps_Webhook_Receiver_REST_Controller() )->ingest( $this->delivery( $body, 'evt_2', 't=1,v1=forged-digest' ) );

		$this->assertSame( 400, $response->get_status() );
		$row = $this->onlyRow();
		$this->assertSame( 'signature_invalid', $row['outcome'] );
		$this->assertSame( 'invalid_signature', $row['error_code'] );
		$this->assertStringNotContainsString( 'forged-digest', (string) json_encode( $row ) );
	}

	#[Test]
	public function should_record_a_repeat_delivery_as_a_duplicate(): void {
		$body       = (string) json_encode( array( 'type' => 'crm.other.thing' ) );
		$controller = new Agend_Apps_Webhook_Receiver_REST_Controller();

		$controller->ingest( $this->delivery( $body, 'evt_3' ) );
		$controller->ingest( $this->delivery( $body, 'evt_3' ) );

		$rows = agend_apps_logger()->pending();
		$this->assertSame( array( 'success', 'duplicate' ), array_column( $rows, 'outcome' ) );
		$this->assertSame( array( 'evt_3', 'evt_3' ), array_column( $rows, 'gateway_request_id' ) );
	}

	#[Test]
	public function should_record_an_unconfigured_receiver_as_not_configured(): void {
		delete_option( 'agend_apps_webhook_secret' );

		$response = ( new Agend_Apps_Webhook_Receiver_REST_Controller() )->ingest( $this->delivery( '{}', 'evt_4' ) );

		$this->assertSame( 503, $response->get_status() );
		$this->assertSame( 'not_configured', $this->onlyRow()['outcome'] );
	}
}
