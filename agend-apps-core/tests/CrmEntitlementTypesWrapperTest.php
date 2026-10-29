<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\AppsCore;

use Agend\Tests\TestCase;
use Agend_Test_WP;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;
use WP_Error;

/**
 * `agend_apps_crm_get_entitlement_types()`: the account's entitlement type
 * catalogue, which Agend Content Access reads to fill an editor picker.
 *
 * The real crm.php cannot be required here: it declares its wrappers without
 * `function_exists()` guards, and the bootstrap already declares doubles for
 * several of them (see the note on `agend_apps_crm_get_my_memberships()` in
 * tests/doubles.php). So the one function under test is lifted out of the real
 * file and evaluated, which keeps the real source and the real
 * `Agend_Apps_API::request()` in play without touching the doubles.
 *
 * Runs in separate processes so the function never exists for any other test,
 * which would otherwise make a `function_exists()` guard elsewhere skip its
 * double.
 */
#[RunTestsInSeparateProcesses]
final class CrmEntitlementTypesWrapperTest extends TestCase {

	private const CATALOGUE = array(
		'success' => true,
		'data'    => array(
			array(
				'id'                   => 'b1',
				'gate_key'             => 'directory.access',
				'name'                 => 'Directory access',
				'description'          => null,
				'managed_by_source_id' => null,
				'kind'                 => 'platform-gate',
			),
			array(
				'id'                   => 'b2',
				'gate_key'             => 'events.workshop',
				'name'                 => 'Workshop',
				'description'          => 'Member workshops',
				'managed_by_source_id' => 's1',
				'kind'                 => 'unrecognised',
			),
		),
	);

	/** The decoded catalogue as `Agend_Apps_API::request()` hands it back: the body plus the HTTP status. */
	private const DECODED = self::CATALOGUE + array( 'status_code' => 200 );

	protected function setUp(): void {
		parent::setUp();

		$source = (string) file_get_contents( AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/crm.php' );

		if ( 1 !== preg_match( '/^function agend_apps_crm_get_entitlement_types\(.*?^}$/ms', $source, $match ) ) {
			throw new \RuntimeException( 'agend_apps_crm_get_entitlement_types() is not declared in crm.php.' );
		}

		// phpcs:ignore Squiz.PHP.Eval.Discouraged
		eval( $match[0] );
	}

	#[Test]
	public function should_request_the_entitlement_type_catalogue_with_a_get(): void {
		$method = null;
		add_filter(
			'agend_apps_api_request_args',
			static function ( array $request_args ) use ( &$method ): array {
				$method = $request_args['method'] ?? null;
				return $request_args;
			},
			10,
			3
		);
		Agend_Test_WP::queue_response( 200, self::CATALOGUE );

		$response = \agend_apps_crm_get_entitlement_types();

		$this->assertCount( 1, Agend_Test_WP::$requests );
		$this->assertSame( 'GET', $method );
		$this->assertStringEndsWith( '/crm/entitlements/types', Agend_Test_WP::$requests[0]['url'] );
		$this->assertSame( self::DECODED, $response );
	}

	#[Test]
	public function should_send_the_query_it_is_given(): void {
		Agend_Test_WP::queue_response( 200, self::CATALOGUE );

		\agend_apps_crm_get_entitlement_types( array( 'search' => 'work shop' ) );

		$this->assertStringEndsWith( '/crm/entitlements/types?search=work%20shop', Agend_Test_WP::$requests[0]['url'] );
	}

	#[Test]
	public function should_return_a_gateway_error_unchanged_and_skip_the_response_filter(): void {
		$filtered = false;
		add_filter(
			'agend_apps_crm_get_entitlement_types_response',
			static function ( $response ) use ( &$filtered ) {
				$filtered = true;
				return $response;
			},
			10,
			2
		);
		Agend_Test_WP::queue_response( 403, array( 'error' => array( 'message' => 'The API key lacks the crm.entitlements.browse scope' ) ) );

		$response = \agend_apps_crm_get_entitlement_types();

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 403, $response->get_error_data()['status_code'] );
		$this->assertFalse( $filtered, 'an error is returned as is, never filtered as a response' );
	}

	#[Test]
	public function should_return_the_same_transport_error_instance(): void {
		Agend_Test_WP::queue_transport_error( 'http_request_failed', 'cURL error 28' );
		$queued = Agend_Test_WP::$canned_responses[0]['error'];

		$response = \agend_apps_crm_get_entitlement_types();

		$this->assertSame( $queued, $response );
	}

	#[Test]
	public function should_pass_the_default_args_and_the_original_query_to_the_args_filter(): void {
		$seen = null;
		add_filter(
			'agend_apps_crm_get_entitlement_types_args',
			static function ( array $args, array $query ) use ( &$seen ): array {
				$seen = array( $args, $query );
				return $args;
			},
			10,
			2
		);
		Agend_Test_WP::queue_response( 200, self::CATALOGUE );

		\agend_apps_crm_get_entitlement_types( array( 'search' => 'gold' ) );

		$this->assertSame(
			array( array( 'query' => array( 'search' => 'gold' ) ), array( 'search' => 'gold' ) ),
			$seen
		);
	}

	#[Test]
	public function should_send_the_args_the_args_filter_returns(): void {
		add_filter(
			'agend_apps_crm_get_entitlement_types_args',
			static function ( array $args ): array {
				$args['query']['search'] = 'overridden';
				return $args;
			},
			10,
			2
		);
		Agend_Test_WP::queue_response( 200, self::CATALOGUE );

		\agend_apps_crm_get_entitlement_types( array( 'search' => 'gold' ) );

		$this->assertStringEndsWith( '/crm/entitlements/types?search=overridden', Agend_Test_WP::$requests[0]['url'] );
	}

	#[Test]
	public function should_return_what_the_response_filter_returns_and_hand_it_the_query(): void {
		$seen = null;
		add_filter(
			'agend_apps_crm_get_entitlement_types_response',
			static function ( $response, array $query ) use ( &$seen ) {
				$seen = array( $response, $query );
				return array( 'filtered' => true );
			},
			10,
			2
		);
		Agend_Test_WP::queue_response( 200, self::CATALOGUE );

		$response = \agend_apps_crm_get_entitlement_types( array( 'search' => 'gold' ) );

		$this->assertSame( array( 'filtered' => true ), $response );
		$this->assertSame( array( self::DECODED, array( 'search' => 'gold' ) ), $seen );
	}

	#[Test]
	public function should_not_cache_the_catalogue_in_core(): void {
		Agend_Test_WP::queue_response( 200, self::CATALOGUE );
		Agend_Test_WP::queue_response( 200, self::CATALOGUE );

		\agend_apps_crm_get_entitlement_types();
		\agend_apps_crm_get_entitlement_types();

		$this->assertCount( 2, Agend_Test_WP::$requests, 'the caller caches the vocabulary, Core does not' );
		$this->assertSame(
			array(),
			array_values( array_filter( array_keys( Agend_Test_WP::$transients ), static fn ( string $key ): bool => 0 !== strpos( $key, 'agend_apps_rate_limit_' ) ) ),
			'no response is stored in a transient'
		);
	}
}
