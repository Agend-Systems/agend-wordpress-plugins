<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Catalogue as Catalogue;
use Agend_Content_Access_Policy as Policy;
use Agend_Content_Access_Viewer_Facts as ViewerFacts;
use Agend_Content_Access_Webhooks as Webhooks;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-policy.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-viewer-facts.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-catalogue.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-webhooks.php';

/**
 * Webhook invalidation: an event says whose cached facts to forget, and nothing
 * else it carries is ever believed.
 *
 * What these tests protect, in order of how badly it goes wrong: a payload field
 * treated as a fact (a forged `granted` event would then grant access), an event
 * that forgets the wrong member (or nobody), and a malformed delivery that throws
 * (Core would answer 500 and Agend would retry it for ever).
 */
#[CoversClass( Webhooks::class )]
final class WebhooksTest extends TestCase {

	private const CONTACT_A = '11111111-aaaa-4aaa-8aaa-111111111111';
	private const CONTACT_B = '22222222-bbbb-4bbb-8bbb-222222222222';
	private const BEARER    = 'member-token';

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['agend_test_current_user_id'] = 0;
	}

	/** Links a WordPress user to an Agend contact, as Core does at login. */
	private function link( int $userId, string $contactId ): void {
		$GLOBALS['agend_test_user_meta'][ $userId ][ Webhooks::CONTACT_ID_META ] = $contactId;
	}

	/** The transient name a user's membership facts are stored under right now. */
	private function key( int $userId ): string {
		return ViewerFacts::member_cache_key( $userId, self::BEARER );
	}

	/** Reads a user's facts as that user, so their entry is in the transient store. */
	private function warm( int $userId, array $data = array() ): void {
		$GLOBALS['agend_test_current_user_id'] = $userId;

		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', self::BEARER );
		Agend_Test_WP::set_filter(
			'agend_apps_crm_get_my_entitlements_response',
			array(
				'data' => $data + array(
					'is_member'  => true,
					'tier_ids'   => array( '33333333-3333-4333-8333-333333333333' ),
					'tier_slugs' => array( 'gold' ),
				),
			)
		);

		ViewerFacts::member();
		ViewerFacts::reset();

		$GLOBALS['agend_test_current_user_id'] = 0;
	}

	/** Whether a user's facts are still served from the cache. */
	private function isCached( int $userId ): bool {
		return false !== get_transient( $this->key( $userId ) );
	}

	/** How many times the entitlements endpoint was called. */
	private function gatewayCalls(): int {
		return count(
			array_filter(
				Agend_Test_WP::$requests,
				static fn( array $request ): bool => '/crm/me/entitlements' === $request['url']
			)
		);
	}

	/** Every recorded `agend_content_access_facts_invalidated` firing. */
	private function recordInvalidations(): \ArrayObject {
		$seen = new \ArrayObject();

		add_action(
			'agend_content_access_facts_invalidated',
			static function ( $scope, $type, $user_ids ) use ( $seen ): void {
				$seen->append( array( $scope, $type, $user_ids ) );
			},
			10,
			3
		);

		return $seen;
	}

	/** Puts a value in all three editor catalogue transients. */
	private function fillCatalogues(): void {
		set_transient( Catalogue::TRANSIENT, array( 'plan' ), 600 );
		set_transient( Catalogue::SEGMENTS_TRANSIENT, array( 'segment' ), 600 );
		set_transient( Catalogue::ENTITLEMENTS_TRANSIENT, array( 'entitlement' ), 600 );
	}

	/** @return string[] The catalogue transients still present, by constant name. */
	private function cataloguesLeft(): array {
		$left = array();

		foreach (
			array(
				'plans'        => Catalogue::TRANSIENT,
				'segments'     => Catalogue::SEGMENTS_TRANSIENT,
				'entitlements' => Catalogue::ENTITLEMENTS_TRANSIENT,
			) as $name => $transient
		) {
			if ( false !== get_transient( $transient ) ) {
				$left[] = $name;
			}
		}

		return $left;
	}

	private function envelope( string $type, array $data ): array {
		return array(
			'id'             => 'evt_1',
			'type'           => $type,
			'schema_version' => 1,
			'sequence'       => 1,
			'account_id'     => '44444444-4444-4444-8444-444444444444',
			'environment'    => 'live',
			'created_at'     => '2026-10-27T00:00:00Z',
			'data'           => $data,
		);
	}

	// -----------------------------------------------------------------
	// Registration
	// -----------------------------------------------------------------

	#[Test]
	public function it_listens_to_the_core_receiver_action(): void {
		Webhooks::init();

		$this->assertContains(
			array( 'Agend_Content_Access_Webhooks', 'handle' ),
			Agend_Test_WP::$actions['agend_apps_webhook_received'] ?? array()
		);
	}

	#[Test]
	public function a_delivery_through_the_core_action_reaches_the_handler_with_both_arguments(): void {
		Webhooks::init();
		$this->link( 7, self::CONTACT_A );
		$this->warm( 7 );

		do_action(
			'agend_apps_webhook_received',
			'crm.membership.lapsed',
			$this->envelope( 'crm.membership.lapsed', array( 'contact_id' => self::CONTACT_A ) )
		);

		$this->assertFalse( $this->isCached( 7 ) );
	}

	// -----------------------------------------------------------------
	// Contact-scoped events
	// -----------------------------------------------------------------

	/** @return array<string, array{0: string}> */
	public static function contactEventTypes(): array {
		$types = array(
			'crm.membership.created',
			'crm.membership.updated',
			'crm.membership.cancelled',
			'crm.membership.renewed',
			'crm.membership.reinstated',
			'crm.membership.lapsed',
			'crm.seat.added',
			'crm.seat.updated',
			'crm.seat.removed',
			'crm.entitlement.granted',
			'crm.entitlement.revoked',
			'crm.contact.attributes_changed',
		);

		return array_combine( $types, array_map( static fn( string $type ): array => array( $type ), $types ) );
	}

	/**
	 * Exactly the users whose contact id matches are forgotten: both users linked
	 * to contact A, and neither the user linked to contact B nor one with no link.
	 */
	#[Test]
	#[DataProvider( 'contactEventTypes' )]
	public function a_contact_event_forgets_exactly_the_users_linked_to_that_contact( string $type ): void {
		$this->link( 7, self::CONTACT_A );
		$this->link( 8, self::CONTACT_B );
		$this->link( 9, self::CONTACT_A );

		foreach ( array( 7, 8, 9, 10 ) as $userId ) {
			$this->warm( $userId );
			$this->assertTrue( $this->isCached( $userId ), 'user ' . $userId . ' starts cached' );
		}

		$seen = $this->recordInvalidations();

		Webhooks::handle( $type, $this->envelope( $type, array( 'contact_id' => self::CONTACT_A ) ) );

		$this->assertFalse( $this->isCached( 7 ), 'a linked user misses' );
		$this->assertFalse( $this->isCached( 9 ), 'a second user linked to the same contact misses' );
		$this->assertTrue( $this->isCached( 8 ), 'another contact\'s user keeps their entry' );
		$this->assertTrue( $this->isCached( 10 ), 'an unlinked user keeps their entry' );
		$this->assertSame( array( array( 'user', $type, array( 7, 9 ) ) ), $seen->getArrayCopy() );
		$this->assertSame( 0, ViewerFacts::generation(), 'a contact event never bumps the global generation' );
	}

	#[Test]
	public function a_contact_event_is_a_real_miss_for_the_user_and_a_hit_for_everyone_else(): void {
		$this->link( 7, self::CONTACT_A );
		$this->link( 8, self::CONTACT_B );
		$this->warm( 7 );
		$this->warm( 8 );
		$this->assertSame( 2, $this->gatewayCalls() );

		Webhooks::handle(
			'crm.entitlement.revoked',
			$this->envelope( 'crm.entitlement.revoked', array( 'contact_id' => self::CONTACT_A ) )
		);

		// Re-read as each user: 7 asks Agend again, 8 is served from the cache.
		foreach ( array( 7, 8 ) as $userId ) {
			$GLOBALS['agend_test_current_user_id'] = $userId;
			ViewerFacts::member();
			ViewerFacts::reset();
		}

		$this->assertSame( 3, $this->gatewayCalls() );
	}

	#[Test]
	public function no_user_for_the_contact_is_a_normal_outcome(): void {
		$this->link( 8, self::CONTACT_B );
		$this->warm( 8 );
		$seen = $this->recordInvalidations();

		Webhooks::handle(
			'crm.membership.updated',
			$this->envelope( 'crm.membership.updated', array( 'contact_id' => self::CONTACT_A ) )
		);

		$this->assertTrue( $this->isCached( 8 ) );
		$this->assertSame( array(), $seen->getArrayCopy(), 'nothing was forgotten, so nothing is announced' );
		$this->assertSame( 0, ViewerFacts::generation() );
	}

	#[Test]
	public function the_lookup_is_by_contact_meta_with_ids_only_and_a_cap(): void {
		$captured = null;

		$GLOBALS['agend_test_get_users'] = static function ( array $args ) use ( &$captured ): array {
			$captured = $args;

			return array();
		};

		Webhooks::handle(
			'crm.membership.updated',
			$this->envelope( 'crm.membership.updated', array( 'contact_id' => self::CONTACT_A ) )
		);

		$this->assertSame( '_agend_apps_contact_id', $captured['meta_key'] );
		$this->assertSame( self::CONTACT_A, $captured['meta_value'] );
		$this->assertSame( 'ID', $captured['fields'] );
		$this->assertSame( 50, $captured['number'] );
		$this->assertSame( 50, Webhooks::MAX_USERS );
	}

	// -----------------------------------------------------------------
	// Account-wide events
	// -----------------------------------------------------------------

	/** @return array<string, array{0: string, 1: string[]}> Event type, catalogues that survive. */
	public static function accountEventTypes(): array {
		return array(
			'crm.segment.updated'      => array( 'crm.segment.updated', array( 'plans', 'entitlements' ) ),
			'crm.benefit.changed'      => array( 'crm.benefit.changed', array( 'plans', 'segments' ) ),
			'crm.contact.bulk_updated' => array( 'crm.contact.bulk_updated', array( 'plans', 'segments', 'entitlements' ) ),
		);
	}

	#[Test]
	#[DataProvider( 'accountEventTypes' )]
	public function an_account_event_forgets_every_user_and_clears_only_its_own_catalogue( string $type, array $survives ): void {
		foreach ( array( 7, 8, 10 ) as $userId ) {
			$this->warm( $userId );
		}

		$this->fillCatalogues();
		$seen = $this->recordInvalidations();

		Webhooks::handle( $type, $this->envelope( $type, array( 'change' => 'criteria_changed' ) ) );

		$this->assertSame( 1, ViewerFacts::generation() );

		foreach ( array( 7, 8, 10 ) as $userId ) {
			$this->assertFalse( $this->isCached( $userId ), 'user ' . $userId . ' misses' );
		}

		$this->assertSame( $survives, $this->cataloguesLeft() );
		$this->assertSame( array( array( 'account', $type, array() ) ), $seen->getArrayCopy() );
	}

	#[Test]
	public function an_account_event_needs_no_data_because_it_never_reads_it(): void {
		$this->warm( 7 );

		Webhooks::handle( 'crm.benefit.changed', array( 'type' => 'crm.benefit.changed' ) );

		$this->assertFalse( $this->isCached( 7 ) );
		$this->assertSame( 1, ViewerFacts::generation() );
	}

	// -----------------------------------------------------------------
	// Things that must do nothing
	// -----------------------------------------------------------------

	/** @return array<string, array{0: mixed}> */
	public static function ignoredTypes(): array {
		return array(
			'a tier event no gateway sends' => array( 'crm.tier.benefits_changed' ),
			'an unlisted contact event'     => array( 'crm.contact.updated' ),
			'a prefix without its dot'      => array( 'crm.membership' ),
			'a prefix inside the id'        => array( 'x.crm.membership.created' ),
			'wrong case'                    => array( 'CRM.ENTITLEMENT.GRANTED' ),
			'an unlisted segment change'    => array( 'crm.segment.deleted' ),
			'an unrelated event'            => array( 'crm.invoice.paid' ),
			'an empty type'                 => array( '' ),
			'a null type'                   => array( null ),
			'an integer type'               => array( 42 ),
			'an array type'                 => array( array( 'crm.membership.lapsed' ) ),
		);
	}

	/** @param mixed $type */
	#[Test]
	#[DataProvider( 'ignoredTypes' )]
	public function an_unknown_type_does_nothing( $type ): void {
		$this->link( 7, self::CONTACT_A );
		$this->warm( 7 );
		$this->fillCatalogues();
		$seen = $this->recordInvalidations();

		Webhooks::handle( $type, $this->envelope( 'x', array( 'contact_id' => self::CONTACT_A ) ) );

		$this->assertTrue( $this->isCached( 7 ) );
		$this->assertSame( 0, ViewerFacts::generation() );
		$this->assertSame( array( 'plans', 'segments', 'entitlements' ), $this->cataloguesLeft() );
		$this->assertSame( array(), $seen->getArrayCopy() );
	}

	/** @return array<string, array{0: mixed}> */
	public static function badContactIds(): array {
		return array(
			'not a uuid'          => array( 'not-a-uuid' ),
			'empty'               => array( '' ),
			'a wildcard'          => array( '%' ),
			'a trailing newline'  => array( self::CONTACT_A . "\n" ),
			'a leading space'     => array( ' ' . self::CONTACT_A ),
			'too long'            => array( self::CONTACT_A . '0' ),
			'non-hex digits'      => array( 'gggggggg-aaaa-4aaa-8aaa-111111111111' ),
			'an integer'          => array( 7 ),
			'null'                => array( null ),
			'a list of one uuid'  => array( array( self::CONTACT_A ) ),
			'a boolean'           => array( true ),
		);
	}

	/**
	 * The user is linked under the very string the event carries, so the only
	 * thing that can keep them cached is the UUID check, not a failed match.
	 *
	 * @param mixed $contactId
	 */
	#[Test]
	#[DataProvider( 'badContactIds' )]
	public function a_contact_id_that_is_not_a_uuid_does_nothing( $contactId ): void {
		if ( is_string( $contactId ) ) {
			$this->link( 7, $contactId );
		}

		$this->warm( 7 );
		$seen = $this->recordInvalidations();

		Webhooks::handle( 'crm.membership.lapsed', $this->envelope( 'crm.membership.lapsed', array( 'contact_id' => $contactId ) ) );

		$this->assertTrue( $this->isCached( 7 ) );
		$this->assertSame( array(), $seen->getArrayCopy() );
	}

	/** @return array<string, array{0: mixed}> */
	public static function malformedEnvelopes(): array {
		return array(
			'a string'                 => array( 'crm.membership.lapsed' ),
			'null'                     => array( null ),
			'an integer'               => array( 1 ),
			'an empty array'           => array( array() ),
			'no data'                  => array( array( 'type' => 'crm.membership.lapsed' ) ),
			'null data'                => array( array( 'data' => null ) ),
			'string data'              => array( array( 'data' => self::CONTACT_A ) ),
			'data without a contact'   => array( array( 'data' => array( 'other' => 1 ) ) ),
			'contact id one level up'  => array( array( 'contact_id' => self::CONTACT_A ) ),
		);
	}

	/** @param mixed $envelope */
	#[Test]
	#[DataProvider( 'malformedEnvelopes' )]
	public function a_malformed_envelope_is_ignored_without_throwing( $envelope ): void {
		$this->link( 7, self::CONTACT_A );
		$this->warm( 7 );
		$seen = $this->recordInvalidations();

		Webhooks::handle( 'crm.membership.lapsed', $envelope );
		Webhooks::handle( 'crm.entitlement.granted', $envelope );

		$this->assertTrue( $this->isCached( 7 ) );
		$this->assertSame( array(), $seen->getArrayCopy() );
		$this->assertSame( 0, ViewerFacts::generation() );
	}

	#[Test]
	public function calling_the_handler_with_nothing_does_not_throw(): void {
		Webhooks::handle();

		$this->assertSame( 0, ViewerFacts::generation() );
	}

	#[Test]
	public function a_failing_user_lookup_is_swallowed_and_forgets_nothing(): void {
		$this->link( 7, self::CONTACT_A );
		$this->warm( 7 );

		$GLOBALS['agend_test_get_users'] = static function (): array {
			throw new \RuntimeException( 'database has gone away' );
		};

		Webhooks::handle(
			'crm.membership.lapsed',
			$this->envelope( 'crm.membership.lapsed', array( 'contact_id' => self::CONTACT_A ) )
		);

		$this->assertTrue( $this->isCached( 7 ), 'the cache lifetime remains the backstop' );
	}

	#[Test]
	public function a_user_lookup_that_returns_junk_flushes_only_real_ids(): void {
		$this->warm( 7 );
		$this->warm( 8 );

		$GLOBALS['agend_test_get_users'] = static fn(): array => array( 'x', 0, -3, null, '7', 7.0, array( 8 ) );

		Webhooks::handle(
			'crm.membership.lapsed',
			$this->envelope( 'crm.membership.lapsed', array( 'contact_id' => self::CONTACT_A ) )
		);

		$this->assertFalse( $this->isCached( 7 ) );
		$this->assertTrue( $this->isCached( 8 ) );

		$GLOBALS['agend_test_get_users'] = static fn() => 'not a list';

		Webhooks::handle(
			'crm.membership.lapsed',
			$this->envelope( 'crm.membership.lapsed', array( 'contact_id' => self::CONTACT_A ) )
		);

		$this->assertTrue( $this->isCached( 8 ) );
	}

	// -----------------------------------------------------------------
	// The payload is a locator, never a fact
	// -----------------------------------------------------------------

	/**
	 * A forged or replayed `granted` event names a key the gateway does not
	 * return. The member is forgotten, re-read from Agend, and still does not
	 * hold the key: the event granted nothing.
	 */
	#[Test]
	public function a_granted_event_for_a_key_the_gateway_does_not_return_grants_nothing(): void {
		$this->link( 7, self::CONTACT_A );
		$this->warm( 7 );

		Webhooks::handle(
			'crm.entitlement.granted',
			$this->envelope(
				'crm.entitlement.granted',
				array(
					'contact_id' => self::CONTACT_A,
					'gate_keys'  => array( 'members.premium' ),
					'source_key' => 'manual',
					'granted_via' => 'staff',
				)
			)
		);

		$this->assertFalse( $this->isCached( 7 ), 'the member was forgotten' );

		// The re-read: the gateway still reports no capabilities.
		$GLOBALS['agend_test_current_user_id'] = 7;
		$calls                                 = $this->gatewayCalls();
		$facts                                 = ViewerFacts::member();

		$this->assertSame( $calls + 1, $this->gatewayCalls(), 'the facts were asked of Agend again' );
		$this->assertNotContains( 'members.premium', $facts['entitlement_keys'] );
		$this->assertSame( array(), $facts['entitlement_keys'] );
	}

	#[Test]
	public function nothing_from_the_payload_is_stored(): void {
		$this->link( 7, self::CONTACT_A );
		$this->warm( 7 );

		$before = serialize( array( Agend_Test_WP::$transients, Agend_Test_WP::$options, $GLOBALS['agend_test_user_meta'] ) );

		Webhooks::handle(
			'crm.entitlement.granted',
			$this->envelope(
				'crm.entitlement.granted',
				array(
					'contact_id'     => self::CONTACT_A,
					'gate_keys'      => array( 'members.premium' ),
					'source_key'     => 'stripe-sub-secret',
					'changed_fields' => array( 'tags' ),
				)
			)
		);

		$after = serialize( array( Agend_Test_WP::$transients, Agend_Test_WP::$options, $GLOBALS['agend_test_user_meta'] ) );

		foreach ( array( 'members.premium', 'stripe-sub-secret', 'changed_fields', 'tags' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $after, $needle . ' must not be written anywhere' );
		}

		$this->assertNotSame( $before, $after, 'only the user generation moved' );
		$this->assertSame( 1, (int) $GLOBALS['agend_test_user_meta'][7][ ViewerFacts::USER_GENERATION_META ] );
	}

	// -----------------------------------------------------------------
	// Filters
	// -----------------------------------------------------------------

	#[Test]
	public function the_defaults_are_the_documented_event_lists(): void {
		$this->assertSame(
			array(
				'exact'    => array( 'crm.entitlement.granted', 'crm.entitlement.revoked', 'crm.contact.attributes_changed' ),
				'prefixes' => array( 'crm.membership.', 'crm.seat.' ),
			),
			Webhooks::contact_events()
		);
		$this->assertSame(
			array( 'crm.segment.updated', 'crm.benefit.changed', 'crm.contact.bulk_updated' ),
			Webhooks::account_events()
		);
	}

	#[Test]
	public function a_filter_can_add_an_exact_contact_event(): void {
		$this->link( 7, self::CONTACT_A );
		$this->warm( 7 );

		Agend_Test_WP::set_filter(
			'agend_content_access_webhook_contact_events',
			array(
				'exact'    => array( 'crm.entitlement.granted', 'crm.contact.merged' ),
				'prefixes' => array( 'crm.membership.', 'crm.seat.' ),
			)
		);

		Webhooks::handle( 'crm.contact.merged', $this->envelope( 'crm.contact.merged', array( 'contact_id' => self::CONTACT_A ) ) );

		$this->assertFalse( $this->isCached( 7 ) );
	}

	#[Test]
	public function a_filter_can_add_a_contact_event_prefix(): void {
		$this->link( 7, self::CONTACT_A );
		$this->warm( 7 );

		Agend_Test_WP::set_filter(
			'agend_content_access_webhook_contact_events',
			array(
				'exact'    => array(),
				'prefixes' => array( 'crm.consent.' ),
			)
		);

		Webhooks::handle( 'crm.consent.withdrawn', $this->envelope( 'crm.consent.withdrawn', array( 'contact_id' => self::CONTACT_A ) ) );

		$this->assertFalse( $this->isCached( 7 ) );
	}

	#[Test]
	public function a_filter_can_add_an_account_event_which_clears_no_catalogue(): void {
		$this->warm( 7 );
		$this->fillCatalogues();

		Agend_Test_WP::set_filter(
			'agend_content_access_webhook_account_events',
			array( 'crm.segment.updated', 'crm.tier.benefits_changed' )
		);

		Webhooks::handle( 'crm.tier.benefits_changed', $this->envelope( 'crm.tier.benefits_changed', array() ) );

		$this->assertFalse( $this->isCached( 7 ) );
		$this->assertSame( array( 'plans', 'segments', 'entitlements' ), $this->cataloguesLeft() );
	}

	/** @return array<string, array{0: mixed}> */
	public static function junkContactFilterResults(): array {
		return array(
			'null'           => array( null ),
			'a string'       => array( 'crm.membership.' ),
			'an integer'     => array( 7 ),
			'an empty array' => array( array() ),
			'a bare list'    => array( array( 'crm.membership.' ) ),
			'lists of junk'  => array( array( 'exact' => 'crm.membership.created', 'prefixes' => 7 ) ),
		);
	}

	/**
	 * A filter that returns something unusable must not switch invalidation
	 * off for the default events.
	 *
	 * @param mixed $result
	 */
	#[Test]
	#[DataProvider( 'junkContactFilterResults' )]
	public function a_contact_filter_that_returns_junk_leaves_the_defaults_in_force( $result ): void {
		$this->link( 7, self::CONTACT_A );
		$this->warm( 7 );

		Agend_Test_WP::set_filter( 'agend_content_access_webhook_contact_events', $result );

		$this->assertSame( array( 'crm.entitlement.granted', 'crm.entitlement.revoked', 'crm.contact.attributes_changed' ), Webhooks::contact_events()['exact'] );
		$this->assertSame( array( 'crm.membership.', 'crm.seat.' ), Webhooks::contact_events()['prefixes'] );

		Webhooks::handle( 'crm.membership.lapsed', $this->envelope( 'crm.membership.lapsed', array( 'contact_id' => self::CONTACT_A ) ) );

		$this->assertFalse( $this->isCached( 7 ) );
	}

	/** @param mixed $result */
	#[Test]
	#[DataProvider( 'junkAccountFilterResults' )]
	public function an_account_filter_that_returns_junk_leaves_the_defaults_in_force( $result ): void {
		$this->warm( 7 );

		Agend_Test_WP::set_filter( 'agend_content_access_webhook_account_events', $result );

		$this->assertSame( array( 'crm.segment.updated', 'crm.benefit.changed', 'crm.contact.bulk_updated' ), Webhooks::account_events() );

		Webhooks::handle( 'crm.segment.updated', $this->envelope( 'crm.segment.updated', array() ) );

		$this->assertFalse( $this->isCached( 7 ) );
	}

	/** @return array<string, array{0: mixed}> */
	public static function junkAccountFilterResults(): array {
		return array(
			'null'       => array( null ),
			'a string'   => array( 'crm.segment.updated' ),
			'an integer' => array( 7 ),
			'false'      => array( false ),
		);
	}

	/**
	 * An empty list is not junk: it is how a site that does not want an event
	 * turns it off, the one deliberate way to forget less.
	 */
	#[Test]
	public function a_filter_can_deliberately_remove_events(): void {
		$this->warm( 7 );

		Agend_Test_WP::set_filter( 'agend_content_access_webhook_account_events', array() );

		Webhooks::handle( 'crm.segment.updated', $this->envelope( 'crm.segment.updated', array() ) );

		$this->assertTrue( $this->isCached( 7 ) );
	}

	#[Test]
	public function a_type_listed_as_both_account_wide_and_contact_scoped_forgets_everyone(): void {
		$this->link( 7, self::CONTACT_A );
		$this->warm( 7 );
		$this->warm( 8 );

		Agend_Test_WP::set_filter( 'agend_content_access_webhook_account_events', array( 'crm.entitlement.granted' ) );

		Webhooks::handle( 'crm.entitlement.granted', $this->envelope( 'crm.entitlement.granted', array( 'contact_id' => self::CONTACT_A ) ) );

		$this->assertFalse( $this->isCached( 7 ) );
		$this->assertFalse( $this->isCached( 8 ), 'the broader invalidation wins' );
	}
}
