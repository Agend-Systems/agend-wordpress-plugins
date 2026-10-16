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
 *
 * A 401 is the one rejection a new token can cure (a Supabase session is
 * revoked server side whenever the member signs out anywhere), so before the
 * unattended fallback the bearer is resolved once more, after
 * `agend_apps_bearer_rejected` has let its provider discard a dead token.
 */
#[CoversClass( Agend_Apps_API::class )]
final class BearerRejectionFallbackTest extends TestCase {

	private function api(): Agend_Apps_API {
		return new Agend_Apps_API();
	}

	private function setBearer( string $token ): void {
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', $token );
	}

	/**
	 * Makes each resolution of the bearer return the next token, repeating the
	 * last one, and counts how many times it was resolved.
	 *
	 * @param string[] $tokens Tokens in resolution order.
	 * @return \ArrayObject<string, int> Holds the resolution count under `calls`.
	 */
	private function setBearerSequence( array $tokens ): \ArrayObject {
		$state = new \ArrayObject( array( 'calls' => 0 ) );

		Agend_Test_WP::$filters['agend_apps_bearer_token'] = static function () use ( $tokens, $state ): string {
			$token = $tokens[ min( $state['calls'], count( $tokens ) - 1 ) ];
			++$state['calls'];

			return $token;
		};

		return $state;
	}

	private function authorisationOf( int $request_index ): ?string {
		return Agend_Test_WP::$requests[ $request_index ]['headers']['Authorization'] ?? null;
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

	#[Test]
	public function a_401_on_get_retries_once_with_a_re_resolved_bearer_when_it_differs(): void {
		$resolutions = $this->setBearerSequence( array( 'revoked-token', 'fresh-token' ) );
		Agend_Test_WP::queue_response( 401, array( 'error' => array( 'message' => 'unauthorized' ) ) );
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'member-view' ) ) );

		$result = $this->api()->request( 'GET', '/events' );

		$this->assertSame( array( 'data' => array( 'member-view' ), 'status_code' => 200 ), $result );
		$this->assertCount( 2, Agend_Test_WP::$requests );
		$this->assertSame( 'Bearer revoked-token', $this->authorisationOf( 0 ) );
		$this->assertSame( 'Bearer fresh-token', $this->authorisationOf( 1 ), 'the retry must carry the new token, not drop to the public view' );
		$this->assertSame( 2, $resolutions['calls'] );
	}

	#[Test]
	public function a_cached_read_whose_bearer_was_resolved_by_get_cached_also_retries_with_a_re_resolved_bearer(): void {
		$resolutions = $this->setBearerSequence( array( 'revoked-token', 'fresh-token' ) );
		Agend_Test_WP::queue_response( 401, array( 'error' => array( 'message' => 'unauthorized' ) ) );
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'member-view' ) ) );

		$result = $this->api()->get_cached( '/events/categories', array(), 'events_categories', 60 );

		$this->assertSame( array( 'data' => array( 'member-view' ), 'status_code' => 200 ), $result );
		$this->assertCount( 2, Agend_Test_WP::$requests );
		$this->assertSame( 'Bearer revoked-token', $this->authorisationOf( 0 ) );
		$this->assertSame( 'Bearer fresh-token', $this->authorisationOf( 1 ) );
		$this->assertSame( 2, $resolutions['calls'], 'get_cached() resolves once, the retry once more' );
	}

	#[Test]
	public function a_401_whose_re_resolved_bearer_is_unchanged_retries_unattended(): void {
		$resolutions = $this->setBearerSequence( array( 'member-token' ) );
		Agend_Test_WP::queue_response( 401, array( 'error' => array( 'message' => 'unauthorized' ) ) );
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'public-view' ) ) );

		$result = $this->api()->request( 'GET', '/events' );

		$this->assertSame( array( 'data' => array( 'public-view' ), 'status_code' => 200 ), $result );
		$this->assertCount( 2, Agend_Test_WP::$requests );
		$this->assertSame( 'Bearer member-token', $this->authorisationOf( 0 ) );
		$this->assertNull( $this->authorisationOf( 1 ) );
		$this->assertSame( 2, $resolutions['calls'] );
	}

	#[Test]
	public function a_401_whose_re_resolved_bearer_is_empty_retries_unattended(): void {
		$this->setBearerSequence( array( 'member-token', '' ) );
		Agend_Test_WP::queue_response( 401, array( 'error' => array( 'message' => 'unauthorized' ) ) );
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'public-view' ) ) );

		$this->api()->request( 'GET', '/events' );

		$this->assertCount( 2, Agend_Test_WP::$requests );
		$this->assertNull( $this->authorisationOf( 1 ) );
	}

	#[Test]
	public function a_403_never_re_resolves_the_bearer_and_retries_unattended(): void {
		$resolutions = $this->setBearerSequence( array( 'member-token', 'fresh-token' ) );
		Agend_Test_WP::queue_response( 403, array( 'error' => array( 'message' => 'forbidden' ) ) );
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'public-view' ) ) );

		$result = $this->api()->request( 'GET', '/lms/courses' );

		$this->assertSame( array( 'data' => array( 'public-view' ), 'status_code' => 200 ), $result );
		$this->assertCount( 2, Agend_Test_WP::$requests );
		$this->assertSame( 'Bearer member-token', $this->authorisationOf( 0 ) );
		$this->assertNull( $this->authorisationOf( 1 ) );
		$this->assertSame( 1, $resolutions['calls'], 'a new token cannot cure a 403, so none is asked for' );
	}

	#[Test]
	public function a_re_resolved_bearer_that_is_also_rejected_falls_back_to_unattended_without_looping(): void {
		$resolutions = $this->setBearerSequence( array( 'revoked-token', 'fresh-token', 'third-token' ) );
		Agend_Test_WP::queue_response( 401, array( 'error' => array( 'message' => 'unauthorized' ) ) );
		Agend_Test_WP::queue_response( 401, array( 'error' => array( 'message' => 'still unauthorized' ) ) );
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'public-view' ) ) );

		$result = $this->api()->request( 'GET', '/events' );

		$this->assertSame( array( 'data' => array( 'public-view' ), 'status_code' => 200 ), $result );
		$this->assertCount( 3, Agend_Test_WP::$requests );
		$this->assertSame( 'Bearer revoked-token', $this->authorisationOf( 0 ) );
		$this->assertSame( 'Bearer fresh-token', $this->authorisationOf( 1 ) );
		$this->assertNull( $this->authorisationOf( 2 ) );
		$this->assertSame( 2, $resolutions['calls'], 'the loop guard stops a third resolution' );
	}

	#[Test]
	public function every_attempt_being_rejected_stops_at_three_requests(): void {
		$this->setBearerSequence( array( 'revoked-token', 'fresh-token' ) );
		Agend_Test_WP::queue_response( 401, array( 'error' => array( 'message' => 'unauthorized' ) ) );
		Agend_Test_WP::queue_response( 401, array( 'error' => array( 'message' => 'unauthorized' ) ) );
		Agend_Test_WP::queue_response( 401, array( 'error' => array( 'message' => 'unauthorized' ) ) );

		$result = $this->api()->request( 'GET', '/events' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertCount( 3, Agend_Test_WP::$requests, 'must not retry beyond one re-resolved and one unattended attempt' );
	}

	#[Test]
	public function the_rejection_is_announced_with_the_rejected_bearer_and_status(): void {
		$this->setBearerSequence( array( 'revoked-token', 'fresh-token' ) );
		$announced = array();
		add_action(
			'agend_apps_bearer_rejected',
			static function ( ...$args ) use ( &$announced ): void {
				$announced[] = $args;
			},
			10,
			3
		);
		Agend_Test_WP::queue_response( 401, array( 'error' => array( 'message' => 'unauthorized' ) ) );
		Agend_Test_WP::queue_response( 200, array( 'data' => array() ) );

		$this->api()->request( 'GET', '/events' );

		$this->assertSame( array( array( 'revoked-token', 401, '/events' ) ), $announced );
	}

	#[Test]
	public function a_403_is_announced_too(): void {
		$this->setBearer( 'member-token' );
		$announced = array();
		add_action(
			'agend_apps_bearer_rejected',
			static function ( ...$args ) use ( &$announced ): void {
				$announced[] = $args;
			},
			10,
			3
		);
		Agend_Test_WP::queue_response( 403, array( 'error' => array( 'message' => 'forbidden' ) ) );
		Agend_Test_WP::queue_response( 200, array( 'data' => array() ) );

		$this->api()->request( 'GET', '/lms/courses' );

		$this->assertSame( array( array( 'member-token', 403, '/lms/courses' ) ), $announced );
	}

	#[Test]
	public function a_caller_supplied_bearer_is_not_announced(): void {
		Agend_Test_WP::queue_response( 401, array( 'error' => array( 'message' => 'unauthorized' ) ) );

		$this->api()->request( 'GET', '/events', array( 'bearer_token' => 'explicit-token' ) );

		$this->assertSame( 0, did_action( 'agend_apps_bearer_rejected' ), 'only a bearer this client resolved is the provider\'s to discard' );
	}
}
