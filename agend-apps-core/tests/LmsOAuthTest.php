<?php
/** @package Agend\Tests */
declare( strict_types=1 );
namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use Agend_Apps_LMS_OAuth_Controller;
use Agend_Test_WP;
use PHPUnit\Framework\Attributes\Test;
use WP_REST_Request;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/lms-oauth.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/rest/lms-oauth-routes.php';

final class LmsOAuthTest extends TestCase {
	private $config;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['agend_test_current_user_id'] = 75;
		$GLOBALS['agend_test_current_user_email'] = 'learner@example.test';
		$GLOBALS['agend_test_current_user_name'] = 'Current Learner';
		$GLOBALS['agend_test_session_token'] = 'original-wp-session';
		$this->config = array(
			'enabled' => true,
			'origin' => 'https://lms.example.test',
			'client_id' => 'registered-client',
			'client_secret' => 'server-only-client-secret',
			'redirect_uri' => 'https://example.test/wp-admin/admin-post.php?action=agend_lms_oauth_callback',
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['agend_test_current_user_email'], $GLOBALS['agend_test_current_user_name'], $GLOBALS['agend_test_session_token'] );
		parent::tearDown();
	}

	private function controller( array $override = array() ): Agend_Apps_LMS_OAuth_Controller {
		return new Agend_Apps_LMS_OAuth_Controller( array_merge( $this->config, $override ) );
	}

	private function startRequest(): WP_REST_Request {
		$r = new WP_REST_Request( 'POST' );
		$r->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		return $r;
	}

	private function started(): array {
		Agend_Test_WP::queue_response( 201, array( 'request_uri' => 'urn:agend:oauth:request:' . str_repeat( 'r', 43 ), 'expires_in' => 600 ) );
		$r = $this->controller()->start( $this->startRequest() );
		$this->assertSame( 200, $r->get_status() );
		$wire = json_decode( Agend_Test_WP::$requests[0]['body'], true );
		$key = Agend_Apps_LMS_OAuth_Controller::TRANSACTION_PREFIX . hash( 'sha256', $wire['state'] );
		return array( $wire, $key, $r );
	}

	private function callbackRequest( string $state, string $code = 'authorization-code' ): WP_REST_Request {
		$r = new WP_REST_Request( 'GET' );
		$r->set_param( 'state', $state );
		$r->set_param( 'code', $code );
		return $r;
	}

	private function tokenResponse(): void {
		Agend_Test_WP::queue_response( 200, array( 'access_token' => 'private-access-token', 'refresh_token' => 'private-refresh-token', 'expires_in' => 3600, 'token_type' => 'Bearer' ) );
	}

	#[Test]
	public function should_send_only_the_current_wordpress_identity_and_server_configuration_when_start_is_legitimate(): void {
		list( $wire, $key, $r ) = $this->started();
		$this->assertSame( '75', $wire['wp_user_id'] );
		$this->assertSame( 'learner@example.test', $wire['wp_user_email'] );
		$this->assertSame( 'Current Learner', $wire['wp_user_name'] );
		$this->assertSame( $this->config['client_secret'], $wire['client_secret'] );
		$tx = json_decode( get_option( $key ), true );
		$this->assertSame( 'S256', $wire['code_challenge_method'] );
		$this->assertSame( rtrim( strtr( base64_encode( hash( 'sha256', $tx['verifier'], true ) ), '+/', '-_' ), '=' ), $wire['code_challenge'] );
		$this->assertSame( hash( 'sha256', 'original-wp-session' ), $tx['session_hash'] );
		$this->assertNotSame( $wire['state'], $tx['verifier'] );
		$browser = json_encode( $r->get_data() );
		foreach ( array( $wire['state'], $tx['verifier'], $this->config['client_secret'], $wire['wp_user_email'] ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $browser );
		}
		$query = array();
		parse_str( parse_url( $r->get_data()['authorization_url'], PHP_URL_QUERY ), $query );
		$this->assertSame( array( 'client_id', 'request_uri' ), array_keys( $query ) );
		$this->assertSame( 'no-store', $r->get_headers()['Cache-Control'] );
		$args = Agend_Test_WP::$request_args[0];
		$this->assertSame( 0, $args['redirection'] );
		$this->assertSame( 10, $args['timeout'] );
		$this->assertTrue( $args['sslverify'] );
		$this->assertTrue( $args['reject_unsafe_urls'] );
	}

	#[Test]
	public function should_refuse_forged_identity_or_configuration_parameters_when_start_is_called(): void {
		foreach ( array( 'wp_user_id', 'wp_user_email', 'wp_user_name', 'client_secret', 'redirect_uri', 'state', 'scope', 'origin', 'request_uri', 'code_challenge' ) as $field ) {
			$r = $this->startRequest();
			$r->set_param( $field, 'attacker-value' );
			$this->assertSame( 400, $this->controller()->start( $r )->get_status() );
		}
		$this->assertSame( array(), Agend_Test_WP::$requests );
		$this->assertSame( array(), Agend_Test_WP::$options );
	}

	#[Test]
	public function should_make_no_remote_request_when_nonce_user_or_session_is_missing(): void {
		$r = $this->startRequest();
		$r->set_header( 'X-WP-Nonce', 'forged' );
		$this->assertSame( 403, $this->controller()->start( $r )->get_status() );
		$GLOBALS['agend_test_current_user_id'] = 0;
		$this->assertSame( 403, $this->controller()->start( $this->startRequest() )->get_status() );
		$GLOBALS['agend_test_current_user_id'] = 75;
		$GLOBALS['agend_test_session_token'] = '';
		$this->assertSame( 403, $this->controller()->start( $this->startRequest() )->get_status() );
		$this->assertSame( array(), Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_fail_closed_when_configuration_is_disabled_http_or_not_the_exact_local_callback(): void {
		foreach ( array( array( 'enabled' => false ), array( 'client_secret' => '' ), array( 'origin' => 'http://lms.example.test' ), array( 'origin' => 'https://lms.example.test/path' ), array( 'origin' => 'https://secret@lms.example.test' ), array( 'redirect_uri' => 'https://attacker.test/callback' ), array( 'redirect_uri' => 'http://example.test/wp-admin/admin-post.php?action=agend_lms_oauth_callback' ) ) as $config ) {
			$this->assertSame( 503, $this->controller( $config )->start( $this->startRequest() )->get_status() );
		}
		$this->assertSame( array(), Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_consume_once_before_exchange_and_store_tokens_only_for_the_initiator_when_callback_is_legitimate(): void {
		list( $wire, $key ) = $this->started();
		$tx = json_decode( get_option( $key ), true );
		$this->tokenResponse();
		$r = $this->controller()->callback( $this->callbackRequest( $wire['state'] ) );
		$this->assertSame( 200, $r->get_status() );
		$this->assertSame( array( 'status' => 'connected' ), $r->get_data() );
		$this->assertSame( '', get_option( $key, '' ) );
		$tokens = get_user_meta( 75, Agend_Apps_LMS_OAuth_Controller::TOKEN_META, true );
		$this->assertSame( 'private-access-token', $tokens['access_token'] );
		$this->assertSame( '', get_user_meta( 76, Agend_Apps_LMS_OAuth_Controller::TOKEN_META, true ) );
		parse_str( Agend_Test_WP::$requests[1]['body'], $exchange );
		$this->assertSame( $tx['verifier'], $exchange['code_verifier'] );
		$this->assertSame( $this->config['redirect_uri'], $exchange['redirect_uri'] );
		$this->assertSame( 'application/x-www-form-urlencoded', Agend_Test_WP::$requests[1]['headers']['Content-Type'] );
		$this->assertSame( 400, $this->controller()->callback( $this->callbackRequest( $wire['state'] ) )->get_status() );
		$this->assertCount( 2, Agend_Test_WP::$requests );
		$this->assertCount( 1, $GLOBALS['wpdb']->queries );
		$this->assertStringStartsWith( 'DELETE FROM ', $GLOBALS['wpdb']->queries[0] );
	}

	#[Test]
	public function should_preserve_the_pending_transaction_when_another_user_or_login_session_claims_callback(): void {
		list( $wire, $key ) = $this->started();
		$GLOBALS['agend_test_current_user_id'] = 76;
		$this->assertSame( 400, $this->controller()->callback( $this->callbackRequest( $wire['state'] ) )->get_status() );
		$GLOBALS['agend_test_current_user_id'] = 75;
		$GLOBALS['agend_test_session_token'] = 'different-session-same-user';
		$this->assertSame( 400, $this->controller()->callback( $this->callbackRequest( $wire['state'] ) )->get_status() );
		$this->assertIsString( get_option( $key ) );
		$this->assertCount( 1, Agend_Test_WP::$requests );
		$GLOBALS['agend_test_session_token'] = 'original-wp-session';
		$this->tokenResponse();
		$this->assertSame( 200, $this->controller()->callback( $this->callbackRequest( $wire['state'] ) )->get_status() );
	}

	#[Test]
	public function should_refuse_unknown_missing_or_mismatched_state_without_exchanging(): void {
		list( $wire, $key ) = $this->started();
		foreach ( array( '', 'invalid', str_repeat( 'a', 43 ) ) as $state ) {
			$this->assertSame( 400, $this->controller()->callback( $this->callbackRequest( $state ) )->get_status() );
		}
		$tx = json_decode( get_option( $key ), true );
		$tx['state'] = str_repeat( 'x', 43 );
		update_option( $key, json_encode( $tx ), false );
		$this->assertSame( 400, $this->controller()->callback( $this->callbackRequest( $wire['state'] ) )->get_status() );
		$this->assertCount( 1, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_consume_without_exchange_when_expired_denied_or_missing_code(): void {
		foreach ( array( 'expired', 'denied', 'no-code' ) as $case ) {
			Agend_Test_WP::$requests = array();
			list( $wire, $key ) = $this->started();
			$r = $this->callbackRequest( $wire['state'], 'no-code' === $case ? '' : 'code' );
			if ( 'expired' === $case ) {
				$tx = json_decode( get_option( $key ), true );
				$tx['expires_at'] = time() - 1;
				update_option( $key, json_encode( $tx ), false );
			}
			if ( 'denied' === $case ) {
				$r->set_param( 'error', 'access_denied' );
			}
			$this->assertSame( 400, $this->controller()->callback( $r )->get_status() );
			$this->assertSame( '', get_option( $key, '' ) );
			$this->assertCount( 1, Agend_Test_WP::$requests );
		}
	}

	#[Test]
	public function should_not_exchange_when_a_concurrent_callback_already_consumed_the_cached_transaction(): void {
		list( $wire, $key ) = $this->started();
		$cached = get_option( $key );
		unset( Agend_Test_WP::$options[ $key ] );
		wp_cache_set( $key, $cached, 'options' );
		$this->assertSame( $cached, get_option( $key ) );
		$this->assertSame( 400, $this->controller()->callback( $this->callbackRequest( $wire['state'] ) )->get_status() );
		$this->assertCount( 1, $GLOBALS['wpdb']->queries );
		$this->assertCount( 1, Agend_Test_WP::$requests );
		$this->assertSame( '', get_user_meta( 75, Agend_Apps_LMS_OAuth_Controller::TOKEN_META, true ) );
	}

	#[Test]
	public function should_sanitize_transport_errors_and_prevent_retry_exchange_when_token_request_fails(): void {
		list( $wire, $key ) = $this->started();
		Agend_Test_WP::queue_transport_error( 'failure', 'server-only-client-secret private-access-token' );
		$r = $this->controller()->callback( $this->callbackRequest( $wire['state'] ) );
		$this->assertSame( array( 'status' => 'retry' ), $r->get_data() );
		$this->assertSame( '', get_option( $key, '' ) );
		$this->assertSame( 400, $this->controller()->callback( $this->callbackRequest( $wire['state'] ) )->get_status() );
		$this->assertCount( 2, Agend_Test_WP::$requests );
		$this->assertSame( '', get_user_meta( 75, Agend_Apps_LMS_OAuth_Controller::TOKEN_META, true ) );
	}

	#[Test]
	public function should_delete_start_transaction_without_unsafe_redirect_when_backchannel_rejects_or_returns_malformed_reference(): void {
		foreach ( array( array( 401, array( 'error' => 'invalid_client', 'error_description' => 'secret details' ) ), array( 302, array( 'request_uri' => 'https://attacker.test', 'expires_in' => 600 ) ), array( 201, array( 'request_uri' => 'https://attacker.test', 'expires_in' => 600 ) ), array( 201, array( 'request_uri' => 'urn:agend:oauth:request:' . str_repeat( 'a', 43 ), 'expires_in' => 601 ) ) ) as $case ) {
			Agend_Test_WP::queue_response( $case[0], $case[1] );
			$r = $this->controller()->start( $this->startRequest() );
			$this->assertSame( array( 'status' => 'retry' ), $r->get_data() );
			$this->assertSame( array(), Agend_Test_WP::$options );
		}
	}

	#[Test]
	public function should_refuse_configuration_switch_without_repointing_when_callback_returns(): void {
		list( $wire ) = $this->started();
		$this->assertSame( 400, $this->controller( array( 'client_id' => 'other-client' ) )->callback( $this->callbackRequest( $wire['state'] ) )->get_status() );
		$this->assertCount( 1, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_refuse_callback_without_exchanging_when_the_user_signed_out(): void {
		list( $wire, $key ) = $this->started();
		$GLOBALS['agend_test_current_user_id'] = 0;
		$this->assertSame( 400, $this->controller()->callback( $this->callbackRequest( $wire['state'] ) )->get_status() );
		$this->assertCount( 1, Agend_Test_WP::$requests );
		$this->assertIsString( get_option( $key ) );
	}

	#[Test]
	public function should_not_exchange_when_atomic_database_consumption_fails(): void {
		list( $wire ) = $this->started();
		$GLOBALS['wpdb'] = new class extends \wpdb {
			public function query( string $query ) {
				return false;
			}
		};
		$this->assertSame( 400, $this->controller()->callback( $this->callbackRequest( $wire['state'] ) )->get_status() );
		$this->assertCount( 1, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_not_store_partial_tokens_and_should_not_exchange_twice_when_token_response_is_malformed(): void {
		list( $wire, $key ) = $this->started();
		Agend_Test_WP::queue_response( 200, array( 'access_token' => 'must-not-be-exposed', 'expires_in' => 3600, 'token_type' => 'Bearer' ) );
		$r = $this->controller()->callback( $this->callbackRequest( $wire['state'] ) );
		$this->assertSame( array( 'status' => 'retry' ), $r->get_data() );
		$this->assertSame( '', get_user_meta( 75, Agend_Apps_LMS_OAuth_Controller::TOKEN_META, true ) );
		$this->assertSame( '', get_option( $key, '' ) );
		$this->assertSame( 400, $this->controller()->callback( $this->callbackRequest( $wire['state'] ) )->get_status() );
		$this->assertCount( 2, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_prune_only_expired_owned_transactions_when_expiry_hook_runs(): void {
		list( $wire, $key ) = $this->started();
		Agend_Apps_LMS_OAuth_Controller::expire( $key );
		$this->assertIsString( get_option( $key ) );
		$tx = json_decode( get_option( $key ), true );
		$tx['expires_at'] = time() - 1;
		update_option( $key, json_encode( $tx ), false );
		Agend_Apps_LMS_OAuth_Controller::expire( $key );
		$this->assertSame( '', get_option( $key, '' ) );
		update_option( 'unrelated-option', 'keep' );
		Agend_Apps_LMS_OAuth_Controller::expire( 'unrelated-option' );
		$this->assertSame( 'keep', get_option( 'unrelated-option' ) );
	}
}
