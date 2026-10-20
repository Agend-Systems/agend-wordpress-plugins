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
 * Identity-attached responses must never touch the shared transient store.
 *
 * WordPress transients are shared across every visitor to a site, and several
 * gateway endpoints enrich their response when a member bearer is attached:
 * `/events` returns the caller's own registration, `/cart` is per-identity
 * entirely, `/cms/content` carries their access projection. The cache key
 * contains the query but not the member, so caching such a response serves one
 * member's data to whoever loads the page next.
 *
 * This was a live defect, fixed in `get_cached()` centrally rather than at each
 * of the 28 call sites, so the next cached endpoint cannot reintroduce it.
 */
#[CoversClass( Agend_Apps_API::class )]
final class CachedRequestIdentityTest extends TestCase {

	private function api(): Agend_Apps_API {
		return new Agend_Apps_API();
	}

	private function setBearer( string $token ): void {
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', $token );
	}

	#[Test]
	public function anonymous_reads_are_cached_as_before(): void {
		$api = $this->api();

		$first  = $api->get_cached( '/cms/content/x', array(), 'cms_x', 60 );
		$second = $api->get_cached( '/cms/content/x', array(), 'cms_x', 60 );

		$this->assertCount( 1, Agend_Test_WP::$requests, 'the second read should not hit the network' );
		$this->assertSame( $first, $second );
		$this->assertSame( array( 'agend_apps_cms_x' ), $this->cachedContentKeys() );
	}

	#[Test]
	public function a_member_read_never_touches_the_shared_key(): void {
		$this->setBearer( 'member-a-token' );
		$api = $this->api();

		$api->get_cached( '/cms/content/x', array(), 'cms_x', 60 );

		$this->assertNotContains(
			'agend_apps_cms_x',
			$this->cachedContentKeys(),
			'the shared key is what leaked one member to the next'
		);
	}

	#[Test]
	public function a_repeated_member_read_is_served_from_that_members_own_entry(): void {
		$this->setBearer( 'member-a-token' );
		$api = $this->api();

		$first  = $api->get_cached( '/cms/content/x', array(), 'cms_x', 60 );
		$second = $api->get_cached( '/cms/content/x', array(), 'cms_x', 60 );

		$this->assertCount( 1, Agend_Test_WP::$requests, 'the second read should not hit the network' );
		$this->assertSame( $first, $second );
	}

	#[Test]
	public function a_cart_read_still_bypasses_the_store_entirely(): void {
		$this->setBearer( 'member-a-token' );
		$api = $this->api();

		$api->get_cached( '/cart', array(), 'cart_get', 60 );
		$api->get_cached( '/cart', array(), 'cart_get', 60 );

		$this->assertCount( 2, Agend_Test_WP::$requests, 'a cart must never be read from a cache' );
		$this->assertSame( array(), $this->cachedContentKeys() );
	}

	#[Test]
	public function the_bearer_is_forwarded_on_the_bypassed_request(): void {
		$this->setBearer( 'member-a-token' );

		$this->api()->get_cached( '/cms/content/x', array(), 'cms_x', 60 );

		$this->assertSame(
			'Bearer member-a-token',
			Agend_Test_WP::$requests[0]['headers']['Authorization'] ?? null
		);
	}

	/** The leak itself: before the fix, B received A's cached response. */
	#[Test]
	public function one_members_response_never_reaches_another(): void {
		$api = $this->api();

		$this->setBearer( 'member-a-token' );
		$responseA = $api->get_cached( '/events/gala', array(), 'events_gala', 60 );

		$this->setBearer( 'member-b-token' );
		$responseB = $api->get_cached( '/events/gala', array(), 'events_gala', 60 );

		$this->assertNotSame( $responseA, $responseB );
		$this->assertSame(
			'Bearer member-b-token',
			Agend_Test_WP::$requests[1]['headers']['Authorization'] ?? null
		);
	}

	#[Test]
	public function a_member_read_does_not_poison_the_cache_for_a_later_visitor(): void {
		$api = $this->api();

		$this->setBearer( 'member-a-token' );
		$memberResponse = $api->get_cached( '/events/gala', array(), 'events_gala', 60 );

		$this->setBearer( '' );
		$anonResponse = $api->get_cached( '/events/gala', array(), 'events_gala', 60 );

		$this->assertNotSame( $memberResponse, $anonResponse );
		$this->assertContains(
			'agend_apps_events_gala',
			$this->cachedContentKeys(),
			'the anonymous visitor gets their own unsuffixed entry'
		);
		$this->assertSame(
			$anonResponse,
			Agend_Test_WP::$transients['agend_apps_events_gala'] ?? null,
			'the shared entry must hold the anonymous response, never the member one'
		);
	}

	#[Test]
	public function an_explicit_bearer_argument_also_bypasses_the_store(): void {
		$this->api()->get_cached(
			'/cart',
			array( 'bearer_token' => 'explicit-token' ),
			'cart',
			60
		);

		$this->assertSame( array(), $this->cachedContentKeys() );
	}

	#[Test]
	public function should_store_no_transient_when_a_member_read_succeeds(): void {
		$this->setBearer( 'member-a-token' );

		$this->api()->get_cached( '/cms/content/x', array(), 'cms_x', 60 );

		$this->assertSame( array(), $this->transientsStartingWith( 'agend_apps_cms_x' ) );
	}

	#[Test]
	public function should_ignore_a_stored_member_entry_when_a_member_reads(): void {
		$this->setBearer( 'member-a-token' );
		Agend_Test_WP::$transients[ 'agend_apps_cms_x_b' . substr( hash( 'sha256', 'member-a-token' ), 0, 16 ) ] = array( 'stale' => true );

		$response = $this->api()->get_cached( '/cms/content/x', array(), 'cms_x', 60 );

		$this->assertCount( 1, Agend_Test_WP::$requests );
		$this->assertArrayNotHasKey( 'stale', $response );
	}

	#[Test]
	public function should_refetch_a_member_read_when_a_later_request_asks_again(): void {
		$this->setBearer( 'member-a-token' );

		$this->api()->get_cached( '/cms/content/x', array(), 'cms_x', 60 );
		$this->api()->get_cached( '/cms/content/x', array(), 'cms_x', 60 );

		$this->assertCount( 2, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_refetch_a_member_read_when_the_caller_forces_it_fresh(): void {
		$this->setBearer( 'member-a-token' );
		$api = $this->api();

		$api->get_cached( '/cms/content/x', array(), 'cms_x', 60 );
		$api->get_cached( '/cms/content/x', array(), 'cms_x', 60, true );

		$this->assertCount( 2, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_refetch_a_member_read_when_the_first_attempt_failed(): void {
		$this->setBearer( 'member-a-token' );
		Agend_Test_WP::queue_response( 500, array( 'error' => array( 'message' => 'boom' ) ) );
		$api = $this->api();

		$first  = $api->get_cached( '/cms/content/x', array(), 'cms_x', 60 );
		$second = $api->get_cached( '/cms/content/x', array(), 'cms_x', 60 );

		$this->assertInstanceOf( \WP_Error::class, $first );
		$this->assertIsArray( $second );
		$this->assertCount( 2, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_serve_an_anonymous_read_from_the_network_when_a_member_read_was_held_first(): void {
		$api = $this->api();

		$this->setBearer( 'member-a-token' );
		$memberResponse = $api->get_cached( '/cms/content/x', array(), 'cms_x', 60 );

		$this->setBearer( '' );
		$anonResponse = $api->get_cached( '/cms/content/x', array(), 'cms_x', 60 );

		$this->assertCount( 2, Agend_Test_WP::$requests );
		$this->assertNotSame( $memberResponse, $anonResponse );
	}

	#[Test]
	public function should_hold_a_retried_read_under_the_re_minted_bearer_when_the_first_was_rejected(): void {
		$this->setBearerSequence( array( 'revoked-token', 'fresh-token', 'fresh-token', 'revoked-token' ) );
		Agend_Test_WP::queue_response( 401, array( 'error' => array( 'message' => 'unauthorized' ) ) );
		$api = $this->api();

		$retried = $api->get_cached( '/cms/content/x', array(), 'cms_x', 60 );
		$again   = $api->get_cached( '/cms/content/x', array(), 'cms_x', 60 );

		$this->assertCount( 2, Agend_Test_WP::$requests, 'the re-minted bearer is served from the memo' );
		$this->assertSame( $retried, $again );

		$api->get_cached( '/cms/content/x', array(), 'cms_x', 60 );

		$this->assertCount( 3, Agend_Test_WP::$requests, 'nothing is held under the rejected bearer' );
	}

	#[Test]
	public function should_not_hold_a_retried_read_when_the_retry_carried_no_bearer(): void {
		$this->setBearer( 'member-a-token' );
		Agend_Test_WP::queue_response( 403, array( 'error' => array( 'message' => 'forbidden' ) ) );
		$api = $this->api();

		$api->get_cached( '/cms/content/x', array(), 'cms_x', 60 );
		$api->get_cached( '/cms/content/x', array(), 'cms_x', 60 );

		$this->assertCount( 3, Agend_Test_WP::$requests );
		$this->assertSame( array(), $this->transientsStartingWith( 'agend_apps_cms_x' ) );
	}

	#[Test]
	public function should_read_and_write_the_shared_entry_without_resolving_a_token_when_anonymous_is_set(): void {
		$resolved = 0;
		Agend_Test_WP::$filters['agend_apps_bearer_token'] = static function () use ( &$resolved ): string {
			++$resolved;
			return 'member-a-token';
		};
		$api = $this->api();

		$first  = $api->get_cached( '/directory/categories', array( 'anonymous' => true ), 'directory_categories_x', 60 );
		$second = $api->get_cached( '/directory/categories', array( 'anonymous' => true ), 'directory_categories_x', 60 );

		$this->assertSame( 0, $resolved, 'resolving the bearer can mint a token' );
		$this->assertCount( 1, Agend_Test_WP::$requests );
		$this->assertArrayNotHasKey( 'Authorization', Agend_Test_WP::$requests[0]['headers'] );
		$this->assertSame( $first, $second );
		$this->assertSame( $first, Agend_Test_WP::$transients['agend_apps_directory_categories_x'] ?? null );
	}

	#[Test]
	public function should_record_when_a_shared_facets_or_categories_entry_was_fetched(): void {
		$api = $this->api();

		$api->get_cached( '/directory/categories', array(), 'directory_categories_x', 60 );
		$api->get_cached( '/cms/content/x', array(), 'cms_x', 60 );

		$this->assertIsInt( Agend_Test_WP::$transients['agend_apps_directory_categories_x_at'] ?? null );
		$this->assertSame( 60, Agend_Test_WP::$transient_ttls['agend_apps_directory_categories_x_at'] ?? null );
		$this->assertArrayNotHasKey( 'agend_apps_cms_x_at', Agend_Test_WP::$transients );
		$this->assertSame( Agend_Test_WP::$transients['agend_apps_directory_categories_x'], $api->peek_cached( 'directory_categories_x' )['response'] ?? null );
		$this->assertNull( $api->peek_cached( 'cms_x' ) );
	}

	#[Test]
	public function should_store_nothing_when_an_anonymous_read_has_a_zero_ttl(): void {
		$this->api()->get_cached( '/cms/content/x', array(), 'cms_x', 0 );

		$this->assertArrayNotHasKey( 'agend_apps_cms_x', Agend_Test_WP::$transients );
	}

	/**
	 * Makes each resolution of the bearer return the next token, repeating the
	 * last one.
	 *
	 * @param string[] $tokens Tokens in resolution order.
	 */
	private function setBearerSequence( array $tokens ): void {
		$state = new \ArrayObject( array( 'calls' => 0 ) );

		Agend_Test_WP::$filters['agend_apps_bearer_token'] = static function () use ( $tokens, $state ): string {
			$token = $tokens[ min( $state['calls'], count( $tokens ) - 1 ) ];
			++$state['calls'];

			return $token;
		};
	}

	/**
	 * @return string[]
	 */
	private function transientsStartingWith( string $prefix ): array {
		return array_values(
			array_filter(
				array_keys( Agend_Test_WP::$transients ),
				static fn( $key ): bool => 0 === strpos( (string) $key, $prefix )
			)
		);
	}
}
