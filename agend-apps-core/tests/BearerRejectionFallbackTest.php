<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\AppsCore;

use Agend_Apps_API;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-api.php';

/**
 * A logged-in WordPress user's member session bearer is not always accepted
 * by the gateway for public catalogue resources (e.g. GET /events, GET
 * /lms/courses). Since every logged-in user now carries a member session,
 * a 401/403 there must not break the catalogue for them; the request is
 * retried exactly once with the Authorization header dropped so the read
 * falls back to the same unattended (API-key-only) request an anonymous
 * visitor gets.
 */
#[CoversClass( Agend_Apps_API::class )]
final class BearerRejectionFallbackTest extends TestCase {

	private function api(): Agend_Apps_API {
		return new Agend_Apps_API();
	}

	private function setBearer( string $token ): void {
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', $token );
	}

	#[Test]
	public function a_401_on_get_retries_once_unattended_and_succeeds(): void {
		$this->setBearer( 'member-token' );
		Agend_Test_WP::queue_response( 401, array( 'error' => array( 'message' => 'unauthorized' ) ) );
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'a', 'b' ) ) );

		$result = $this->api()->request( 'GET', '/events' );

		$this->assertSame( array( 'data' => array( 'a', 'b' ), 'status_code' => 200 ), $result );
		$this->assertCount( 2, Agend_Test_WP::$requests );
		$this->assertSame(
			'Bearer member-token',
			Agend_Test_WP::$requests[0]['headers']['Authorization'] ?? null
		);
		$this->assertArrayNotHasKey( 'Authorization', Agend_Test_WP::$requests[1]['headers'] );
	}

	#[Test]
	public function a_cached_read_with_the_resolved_bearer_also_retries_unattended(): void {
		$this->setBearer( 'member-token' );
		Agend_Test_WP::queue_response( 401, array( 'error' => array( 'message' => 'unauthorized' ) ) );
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'cat' ) ) );

		$result = $this->api()->get_cached( '/events/categories', array(), 'events_categories', 60 );

		$this->assertSame( array( 'data' => array( 'cat' ), 'status_code' => 200 ), $result );
		$this->assertCount( 2, Agend_Test_WP::$requests );
		$this->assertSame( 'Bearer member-token', Agend_Test_WP::$requests[0]['headers']['Authorization'] ?? null );
		$this->assertArrayNotHasKey( 'Authorization', Agend_Test_WP::$requests[1]['headers'] );
		$this->assertArrayNotHasKey( 'agend_apps_events_categories', Agend_Test_WP::$transients, 'the fallback response must not enter the shared cache' );
	}

	#[Test]
	public function a_cached_read_with_a_caller_supplied_bearer_is_never_retried(): void {
		Agend_Test_WP::queue_response( 401, array( 'error' => array( 'message' => 'unauthorized' ) ) );

		$result = $this->api()->get_cached( '/events/categories', array( 'bearer_token' => 'explicit' ), 'events_categories', 60 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertCount( 1, Agend_Test_WP::$requests );
	}

	#[Test]
	public function a_403_on_get_retries_once_unattended_and_succeeds(): void {
		$this->setBearer( 'member-token' );
		Agend_Test_WP::queue_response( 403, array( 'error' => array( 'message' => 'forbidden' ) ) );
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'a' ) ) );

		$result = $this->api()->request( 'GET', '/lms/courses' );

		$this->assertSame( array( 'data' => array( 'a' ), 'status_code' => 200 ), $result );
		$this->assertCount( 2, Agend_Test_WP::$requests );
		$this->assertSame(
			'Bearer member-token',
			Agend_Test_WP::$requests[0]['headers']['Authorization'] ?? null
		);
		$this->assertArrayNotHasKey( 'Authorization', Agend_Test_WP::$requests[1]['headers'] );
	}

	#[Test]
	public function a_second_401_on_the_retry_does_not_retry_again(): void {
		$this->setBearer( 'member-token' );
		Agend_Test_WP::queue_response( 401, array( 'error' => array( 'message' => 'unauthorized' ) ) );
		Agend_Test_WP::queue_response( 401, array( 'error' => array( 'message' => 'still unauthorized' ) ) );

		$result = $this->api()->request( 'GET', '/events' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertCount( 2, Agend_Test_WP::$requests, 'must not retry more than once' );
	}

	#[Test]
	public function a_caller_supplied_bearer_is_never_retried(): void {
		Agend_Test_WP::queue_response( 401, array( 'error' => array( 'message' => 'unauthorized' ) ) );

		$result = $this->api()->request( 'GET', '/events', array( 'bearer_token' => 'explicit-token' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertCount( 1, Agend_Test_WP::$requests );
	}

	#[Test]
	public function non_get_methods_are_never_retried(): void {
		$this->setBearer( 'member-token' );
		Agend_Test_WP::queue_response( 401, array( 'error' => array( 'message' => 'unauthorized' ) ) );

		$result = $this->api()->request( 'POST', '/cart/items' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertCount( 1, Agend_Test_WP::$requests );
	}

	#[Test]
	public function a_successful_get_with_auto_bearer_is_unchanged(): void {
		$this->setBearer( 'member-token' );
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'x' ) ) );

		$result = $this->api()->request( 'GET', '/events' );

		$this->assertSame( array( 'data' => array( 'x' ), 'status_code' => 200 ), $result );
		$this->assertCount( 1, Agend_Test_WP::$requests );
		$this->assertSame(
			'Bearer member-token',
			Agend_Test_WP::$requests[0]['headers']['Authorization'] ?? null
		);
	}
}
