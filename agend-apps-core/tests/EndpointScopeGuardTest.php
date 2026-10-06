<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\AppsCore;

use Agend_Apps_API;
use Agend_Apps_Endpoint_Scopes;
use Agend_Apps_Key_Scopes;
use Agend_Apps_Logger;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use WP_Error;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/logging/class-agend-apps-log-redactor.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/logging/class-agend-apps-log-store.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/logging/class-agend-apps-logger.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/health.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-api.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-key-scopes.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-endpoint-scopes.php';
require_once __DIR__ . '/fixtures/memory-log-store.php';

/**
 * The request guard refuses a call the stored API key is known not to be
 * allowed to make, records the refusal in the API log, and never refuses on
 * anything it is not sure of.
 */
#[CoversClass( Agend_Apps_API::class )]
#[CoversClass( Agend_Apps_Key_Scopes::class )]
#[CoversClass( Agend_Apps_Endpoint_Scopes::class )]
final class EndpointScopeGuardTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Agend_Apps_Logger::set_instance( new Agend_Apps_Logger( new MemoryLogStore() ) );
		Agend_Apps_Endpoint_Scopes::set_map( null );
	}

	protected function tearDown(): void {
		Agend_Apps_Endpoint_Scopes::set_map( null );
		parent::tearDown();
	}

	/**
	 * Stores what a `GET /v1/health` response would have reported.
	 *
	 * @param string[] $scopes  Scopes held.
	 * @param string[] $app_ids Apps the key is restricted to; empty for none.
	 * @param int      $age     Seconds since the fetch.
	 */
	private function know( array $scopes, array $app_ids = array(), int $age = 0 ): void {
		Agend_Apps_Key_Scopes::store_from_response( array( 'data' => array( 'scopes' => $scopes, 'app_ids' => $app_ids ) ) );

		if ( $age > 0 ) {
			$stored               = get_option( Agend_Apps_Key_Scopes::OPTION );
			$stored['fetched_at'] = time() - $age;
			update_option( Agend_Apps_Key_Scopes::OPTION, $stored );
		}
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function rows(): array {
		return agend_apps_logger()->pending();
	}

	private function get_events() {
		return ( new Agend_Apps_API() )->request( 'GET', '/events' );
	}

	#[Test]
	public function should_refuse_and_log_a_call_whose_scope_the_key_lacks(): void {
		$this->know( array( 'crm.contacts.browse' ) );

		$result = $this->get_events();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'agend_apps_scope_missing', $result->get_error_code() );
		$this->assertStringContainsString( 'events.events.browse', $result->get_error_message() );
		$data = $result->get_error_data();
		$this->assertSame( 403, $data['status_code'] );
		$this->assertSame( '/events', $data['path'] );
		$this->assertSame( array( 'events.events.browse' ), $data['missing'] );
		$this->assertSame( 'events', $data['app'] );

		$this->assertCount( 0, Agend_Test_WP::$requests, 'nothing is sent' );
		$this->assertCount( 1, $this->rows() );
		$row = $this->rows()[0];
		$this->assertSame( 'blocked', $row['outcome'] );
		$this->assertNull( $row['status'] );
		$this->assertSame( 'agend_apps_scope_missing', $row['error_code'] );
		$this->assertSame( '/v1/events', $row['path'] );
	}

	#[Test]
	public function should_refuse_a_call_to_an_app_the_key_is_not_allowed(): void {
		$this->know( array( 'events.events.browse' ), array( 'crm' ) );

		$result = $this->get_events();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'agend_apps_app_not_allowed', $result->get_error_code() );
		$this->assertSame( 'events', $result->get_error_data()['app'] );
		$this->assertCount( 0, Agend_Test_WP::$requests );
		$this->assertSame( 'blocked', $this->rows()[0]['outcome'] );
		$this->assertSame( 'agend_apps_app_not_allowed', $this->rows()[0]['error_code'] );
	}

	#[Test]
	public function should_send_a_call_when_the_scope_and_app_are_held(): void {
		$this->know( array( 'events.events.browse' ), array( 'events', 'crm' ) );
		Agend_Test_WP::queue_response( 200, array( 'data' => array() ) );

		$result = $this->get_events();

		$this->assertIsArray( $result );
		$this->assertCount( 1, Agend_Test_WP::$requests );
		$this->assertSame( 'success', $this->rows()[0]['outcome'] );
	}

	#[Test]
	public function should_send_a_call_when_the_key_holds_the_wildcard(): void {
		$this->know( array( '*' ) );
		Agend_Test_WP::queue_response( 200, array( 'data' => array() ) );

		$this->assertIsArray( $this->get_events() );
		$this->assertCount( 1, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_send_a_call_when_an_app_wildcard_covers_the_scope(): void {
		$this->know( array( 'events.*' ) );
		Agend_Test_WP::queue_response( 200, array( 'data' => array() ) );

		$this->assertIsArray( $this->get_events() );
	}

	#[Test]
	public function should_treat_an_empty_app_list_as_unrestricted(): void {
		$this->know( array( 'events.events.browse' ), array() );
		Agend_Test_WP::queue_response( 200, array( 'data' => array() ) );

		$this->assertIsArray( $this->get_events() );
		$this->assertTrue( Agend_Apps_Key_Scopes::has_app( 'anything' ) );
		$this->assertSame( array(), Agend_Apps_Key_Scopes::apps() );
	}

	#[Test]
	public function should_accept_any_one_alternative_a_call_lists(): void {
		// POST /crm/leads lists two alternatives; holding the second is enough.
		$this->know( array( 'crm.contacts.create', 'crm.deals.manage' ) );
		Agend_Test_WP::queue_response( 200, array( 'data' => array() ) );

		$this->assertIsArray( ( new Agend_Apps_API() )->request( 'POST', '/crm/leads', array( 'body' => array() ) ) );
	}

	#[Test]
	public function should_name_the_closest_alternative_when_none_is_held(): void {
		$this->know( array( 'crm.contacts.create' ) );

		$result = ( new Agend_Apps_API() )->request( 'POST', '/crm/leads', array( 'body' => array() ) );

		$this->assertSame( 'agend_apps_scope_missing', $result->get_error_code() );
		$this->assertCount( 1, $result->get_error_data()['missing'] );
	}

	#[Test]
	public function should_never_refuse_when_the_scopes_are_not_known(): void {
		Agend_Test_WP::queue_response( 200, array( 'data' => array() ) );

		$this->assertIsArray( $this->get_events() );
		$this->assertCount( 1, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_never_refuse_when_the_stored_scopes_belong_to_another_key(): void {
		$this->know( array( 'crm.contacts.browse' ) );
		update_option( 'agend_apps_environment', 'staging' );
		Agend_Test_WP::queue_response( 200, array( 'data' => array() ) );

		$this->assertFalse( Agend_Apps_Key_Scopes::known() );
		$this->assertIsArray( $this->get_events() );
	}

	#[Test]
	public function should_never_refuse_an_endpoint_that_is_not_in_the_map(): void {
		$this->know( array( 'crm.contacts.browse' ) );
		Agend_Test_WP::queue_response( 200, array( 'data' => array() ) );

		$this->assertIsArray( ( new Agend_Apps_API() )->request( 'GET', '/not-a-real-endpoint' ) );
		$this->assertCount( 1, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_never_refuse_the_health_check(): void {
		$this->know( array() );
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'scopes' => array() ) ) );

		$this->assertIsArray( ( new Agend_Apps_API() )->request( 'GET', '/health' ) );
		$this->assertCount( 1, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_let_a_site_switch_the_guard_off(): void {
		$this->know( array( 'crm.contacts.browse' ) );
		Agend_Test_WP::set_filter( 'agend_apps_api_block_unauthorised', false );
		Agend_Test_WP::queue_response( 200, array( 'data' => array() ) );

		$this->assertIsArray( $this->get_events() );
		$this->assertCount( 1, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_pass_the_method_path_and_missing_scopes_to_the_filter(): void {
		$this->know( array( 'crm.contacts.browse' ) );
		$seen                                                  = null;
		Agend_Test_WP::$filters['agend_apps_api_block_unauthorised'] = static function ( $block, ...$args ) use ( &$seen ) {
			$seen = $args;
			return $block;
		};

		$this->get_events();

		$this->assertSame( array( 'GET', '/events', array( 'events.events.browse' ) ), $seen );
	}

	#[Test]
	public function should_recheck_stale_scopes_once_and_send_when_the_scope_has_appeared(): void {
		$this->know( array( 'crm.contacts.browse' ), array(), 10 * MINUTE_IN_SECONDS );
		// The recheck's own /health call, then the events call itself.
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'scopes' => array( 'events.events.browse' ) ) ) );
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'items' => array() ) ) );

		$result = $this->get_events();

		$this->assertIsArray( $result );
		$this->assertCount( 2, Agend_Test_WP::$requests );
		$this->assertStringEndsWith( '/health', Agend_Test_WP::$requests[0]['url'] );
		$this->assertStringEndsWith( '/events', Agend_Test_WP::$requests[1]['url'] );
		$this->assertSame( array( 'events.events.browse' ), Agend_Apps_Key_Scopes::all() );
	}

	#[Test]
	public function should_still_refuse_when_the_recheck_confirms_the_scope_is_missing(): void {
		$this->know( array( 'crm.contacts.browse' ), array(), 10 * MINUTE_IN_SECONDS );
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'scopes' => array( 'crm.contacts.browse' ) ) ) );

		$result = $this->get_events();

		$this->assertSame( 'agend_apps_scope_missing', $result->get_error_code() );
		$this->assertCount( 1, Agend_Test_WP::$requests, 'only the recheck went out' );
	}

	#[Test]
	public function should_recheck_at_most_once_in_five_minutes(): void {
		$this->know( array( 'crm.contacts.browse' ), array(), 10 * MINUTE_IN_SECONDS );
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'scopes' => array( 'crm.contacts.browse' ) ) ) );

		$this->get_events();
		$this->get_events();

		$this->assertCount( 1, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_not_recheck_scopes_fetched_within_the_last_five_minutes(): void {
		$this->know( array( 'crm.contacts.browse' ), array(), MINUTE_IN_SECONDS );

		$result = $this->get_events();

		$this->assertSame( 'agend_apps_scope_missing', $result->get_error_code() );
		$this->assertCount( 0, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_not_cache_a_refused_call_as_data(): void {
		$this->know( array( 'crm.contacts.browse' ) );

		$result = ( new Agend_Apps_API() )->get_cached( '/events', array(), 'guard_test_events', 300 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertFalse( get_transient( 'agend_apps_guard_test_events' ) );
	}

	#[Test]
	public function should_store_app_ids_and_tolerate_an_old_option_without_them(): void {
		$this->know( array( 'events.events.browse' ), array( 'events' ) );
		$this->assertSame( array( 'events' ), Agend_Apps_Key_Scopes::apps() );
		$this->assertTrue( Agend_Apps_Key_Scopes::has_app( 'events' ) );
		$this->assertFalse( Agend_Apps_Key_Scopes::has_app( 'crm' ) );

		$stored = get_option( Agend_Apps_Key_Scopes::OPTION );
		unset( $stored['app_ids'] );
		update_option( Agend_Apps_Key_Scopes::OPTION, $stored );

		$this->assertSame( array(), Agend_Apps_Key_Scopes::apps() );
		$this->assertTrue( Agend_Apps_Key_Scopes::has_app( 'crm' ) );
	}

	#[Test]
	public function has_should_be_satisfied_by_the_wildcard(): void {
		$this->know( array( '*' ) );

		$this->assertTrue( Agend_Apps_Key_Scopes::has( 'events.events.browse', 'crm.contacts.manage' ) );
	}

	#[Test]
	public function should_match_a_literal_segment_before_a_parameter(): void {
		Agend_Apps_Endpoint_Scopes::set_map(
			array(
				'GET /things/{id}'    => array( 'app' => 'things', 'scopes' => array( array( 'things.by_id' ) ) ),
				'GET /things/search'  => array( 'app' => 'things', 'scopes' => array( array( 'things.search' ) ) ),
				'GET /things/{id}/go' => array( 'app' => 'things', 'scopes' => array( array( 'things.go' ) ) ),
			)
		);

		$this->assertSame( array( array( 'things.search' ) ), Agend_Apps_Endpoint_Scopes::lookup( 'GET', '/things/search' )['scopes'] );
		$this->assertSame( array( array( 'things.by_id' ) ), Agend_Apps_Endpoint_Scopes::lookup( 'GET', '/things/abc' )['scopes'] );
		$this->assertSame( array( array( 'things.go' ) ), Agend_Apps_Endpoint_Scopes::lookup( 'GET', '/things/abc/go?x=1' )['scopes'] );
		$this->assertNull( Agend_Apps_Endpoint_Scopes::lookup( 'POST', '/things/search' ) );
		$this->assertNull( Agend_Apps_Endpoint_Scopes::lookup( 'GET', '/things/abc/other' ) );
	}

	#[Test]
	public function every_generated_endpoint_should_match_itself(): void {
		$map = require AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/endpoint-scopes.php';

		$this->assertNotEmpty( $map );

		foreach ( $map as $key => $entry ) {
			list( $method, $template ) = explode( ' ', $key, 2 );
			$path                      = (string) preg_replace( '/\{[^}]+\}/', 'sample-1', $template );

			$this->assertSame( $entry, Agend_Apps_Endpoint_Scopes::lookup( $method, $path ), $key . ' does not resolve to itself' );
			$this->assertNotEmpty( $entry['app'], $key );
			$this->assertNotSame( '/v1', substr( $template, 0, 3 ), $key . ' must not carry the /v1 prefix' );
		}
	}
}
