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
	public function a_member_read_never_touches_the_shared_store(): void {
		$this->setBearer( 'member-a-token' );
		$api = $this->api();

		$api->get_cached( '/cms/content/x', array(), 'cms_x', 60 );
		$api->get_cached( '/cms/content/x', array(), 'cms_x', 60 );

		$this->assertCount( 2, Agend_Test_WP::$requests, 'every member read should hit the network' );
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
		$this->assertSame( array( 'agend_apps_events_gala' ), $this->cachedContentKeys() );
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
}
