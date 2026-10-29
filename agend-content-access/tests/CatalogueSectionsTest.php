<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Catalogue as Catalogue;
use Agend_Content_Access_Condition_Runtime as Runtime;
use Agend_Content_Access_Policy as Policy;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;
use WP_Error;

require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-policy.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-catalogue.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-viewer-facts.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-runtime.php';

/**
 * The segment and entitlement sections of the editor catalogue.
 *
 * Both lists come from a Core wrapper that may be missing (an older Core) or
 * refused (an API key without the scope), and a PHP function cannot be undefined
 * once declared. Each test therefore runs in its own process and `require`s the
 * double for a wrapper only when it wants Core to have it, which is the only way
 * to show both worlds in one suite.
 *
 * What these protect: a failure is never cached, so a fix shows up at once; an
 * outage serves the last known list rather than emptying a picker; and the
 * message an editor reads names the real cause and its fix, never the transport
 * detail Core reports.
 */
#[CoversClass( Catalogue::class )]
#[RunTestsInSeparateProcesses]
final class CatalogueSectionsTest extends TestCase {

	private const SEG_A = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1';
	private const SEG_B = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa2';

	private function coreSegments( $response ): void {
		require_once __DIR__ . '/fixtures/core-segments-double.php';

		Agend_Test_WP::set_filter( 'agend_apps_crm_get_segments_response', $response );
	}

	private function coreEntitlements( $response ): void {
		require_once __DIR__ . '/fixtures/core-entitlement-types-double.php';

		Agend_Test_WP::set_filter( 'agend_apps_crm_get_entitlement_types_response', $response );
	}

	private function calls( string $url ): int {
		return count(
			array_filter(
				Agend_Test_WP::$requests,
				static fn( array $request ): bool => $url === $request['url']
			)
		);
	}

	private function segmentRows(): array {
		return array(
			'success' => true,
			'data'    => array(
				array(
					'id'           => self::SEG_A,
					'slug'         => 'overdue',
					'name'         => 'Overdue renewals',
					'description'  => 'Members whose renewal date has passed',
					'member_count' => 42,
					'rules'        => array( 'field' => 'renewal_date' ),
				),
				array( 'id' => self::SEG_B, 'slug' => 'volunteers', 'name' => 'Volunteers' ),
			),
		);
	}

	private function entitlementRows(): array {
		return array(
			'success' => true,
			'data'    => array(
				array( 'id' => 'r1', 'gate_key' => 'directory.access', 'name' => 'Directory access', 'description' => 'Browse the directory', 'managed_by_source_id' => 'src-1', 'kind' => 'platform-gate' ),
				array( 'id' => 'r2', 'gate_key' => 'entitlement.early_bird', 'name' => 'Early bird', 'description' => null, 'managed_by_source_id' => null, 'kind' => 'entitlement' ),
			),
		);
	}

	// -----------------------------------------------------------------
	// Segments
	// -----------------------------------------------------------------

	#[Test]
	public function a_segment_carries_exactly_the_three_editor_fields(): void {
		$this->coreSegments( $this->segmentRows() );

		$section = Catalogue::segments();

		$this->assertSame(
			array(
				array( 'id' => self::SEG_A, 'slug' => 'overdue', 'name' => 'Overdue renewals' ),
				array( 'id' => self::SEG_B, 'slug' => 'volunteers', 'name' => 'Volunteers' ),
			),
			$section['items']
		);
		$this->assertFalse( $section['stale'] );
		$this->assertSame( '', $section['error'] );
	}

	/** Reduction is an allow list: what the gateway adds tomorrow is excluded by default. */
	#[Test]
	public function a_segment_leaks_nothing_beyond_its_three_fields(): void {
		$this->coreSegments( $this->segmentRows() );

		$serialised = (string) json_encode( Catalogue::segments()['items'] );

		foreach ( array( 'member_count', '42', 'rules', 'renewal_date', 'description', 'Members whose renewal' ) as $forbidden ) {
			$this->assertStringNotContainsString( $forbidden, $serialised );
		}
	}

	#[Test]
	public function the_segment_list_is_cached_for_the_cache_lifetime_and_reused(): void {
		$this->coreSegments( $this->segmentRows() );

		Catalogue::segments();

		$this->assertSame( Runtime::cache_ttl(), Agend_Test_WP::$transient_ttls[ Catalogue::SEGMENTS_TRANSIENT ] );

		// Were Core asked again it would now say something else.
		Agend_Test_WP::set_filter( 'agend_apps_crm_get_segments_response', array( 'success' => true, 'data' => array() ) );
		$again = Catalogue::segments();

		$this->assertCount( 2, $again['items'] );
		$this->assertFalse( $again['stale'] );
		$this->assertSame( 1, $this->calls( '/crm/segments' ) );
	}

	#[Test]
	public function a_forced_refresh_bypasses_the_segment_cache(): void {
		$this->coreSegments( $this->segmentRows() );
		Catalogue::segments();

		Agend_Test_WP::set_filter( 'agend_apps_crm_get_segments_response', array( 'success' => true, 'data' => array() ) );

		$this->assertSame( array(), Catalogue::segments( true )['items'] );
		$this->assertSame( 2, $this->calls( '/crm/segments' ) );
	}

	#[Test]
	public function a_failed_segment_refresh_serves_the_last_known_list(): void {
		$this->coreSegments( $this->segmentRows() );
		Catalogue::segments();

		Agend_Test_WP::set_filter( 'agend_apps_crm_get_segments_response', new WP_Error( 'http_request_failed', 'Connection refused' ) );
		$outage = Catalogue::segments( true );

		$this->assertCount( 2, $outage['items'], 'an outage must not empty the picker' );
		$this->assertTrue( $outage['stale'] );
		$this->assertNotSame( '', $outage['error'] );
		$this->assertCount( 2, Agend_Test_WP::$transients[ Catalogue::SEGMENTS_TRANSIENT ], 'the good copy is left intact' );
	}

	#[Test]
	public function a_segment_failure_is_not_cached_and_the_next_call_asks_again(): void {
		$this->coreSegments( new WP_Error( 'http_request_failed', 'down' ) );

		$failed = Catalogue::segments();

		$this->assertSame( array(), $failed['items'] );
		$this->assertTrue( $failed['stale'] );
		$this->assertArrayNotHasKey( Catalogue::SEGMENTS_TRANSIENT, Agend_Test_WP::$transients );

		// A recovery fills the list at once rather than after the cache lifetime.
		// On the next request: within this one the failure is remembered in memory
		// only, so the gateway is not asked again.
		Catalogue::reset();
		Agend_Test_WP::set_filter( 'agend_apps_crm_get_segments_response', $this->segmentRows() );

		$this->assertCount( 2, Catalogue::segments()['items'] );
		$this->assertSame( 2, $this->calls( '/crm/segments' ) );
	}

	#[Test]
	public function a_row_with_no_usable_segment_id_is_skipped(): void {
		$this->coreSegments(
			array(
				'success' => true,
				'data'    => array(
					array( 'id' => self::SEG_A, 'slug' => 'ok', 'name' => 'Fine' ),
					array( 'id' => 'not-a-uuid', 'slug' => 'bad', 'name' => 'Cannot be stored' ),
					array( 'slug' => 'noid', 'name' => 'No id' ),
					array( 'id' => null, 'name' => 'Null id' ),
					'not an array',
				),
			)
		);

		$this->assertSame( array( self::SEG_A ), array_column( Catalogue::segments()['items'], 'id' ) );
	}

	/** A label an editor cannot read is not a choice, so a nameless segment falls back to its slug, then its id. */
	#[Test]
	public function a_segment_with_no_name_is_labelled_by_its_slug_then_its_id(): void {
		$this->coreSegments(
			array(
				'success' => true,
				'data'    => array(
					array( 'id' => self::SEG_A, 'slug' => 'overdue' ),
					array( 'id' => self::SEG_B, 'slug' => '', 'name' => '   ' ),
				),
			)
		);

		$this->assertSame( array( 'overdue', self::SEG_B ), array_column( Catalogue::segments()['items'], 'name' ) );
	}

	// -----------------------------------------------------------------
	// Entitlements
	// -----------------------------------------------------------------

	#[Test]
	public function an_entitlement_carries_exactly_the_four_editor_fields(): void {
		$this->coreEntitlements( $this->entitlementRows() );

		$section = Catalogue::entitlements();

		$this->assertSame(
			array(
				array( 'gate_key' => 'directory.access', 'name' => 'Directory access', 'description' => 'Browse the directory', 'kind' => 'platform-gate' ),
				array( 'gate_key' => 'entitlement.early_bird', 'name' => 'Early bird', 'description' => '', 'kind' => 'entitlement' ),
			),
			$section['items']
		);
		$this->assertFalse( $section['stale'] );
		$this->assertSame( '', $section['error'] );
		$this->assertStringNotContainsString( 'src-1', (string) json_encode( $section ), 'the managing source is internal' );
	}

	#[Test]
	public function the_entitlement_list_is_cached_for_the_cache_lifetime_and_reused(): void {
		$this->coreEntitlements( $this->entitlementRows() );

		Catalogue::entitlements();

		$this->assertSame( Runtime::cache_ttl(), Agend_Test_WP::$transient_ttls[ Catalogue::ENTITLEMENTS_TRANSIENT ] );

		Agend_Test_WP::set_filter( 'agend_apps_crm_get_entitlement_types_response', array( 'success' => true, 'data' => array() ) );

		$this->assertCount( 2, Catalogue::entitlements()['items'] );
		$this->assertSame( 1, $this->calls( '/crm/entitlements/types' ) );
	}

	#[Test]
	public function a_forced_refresh_bypasses_the_entitlement_cache(): void {
		$this->coreEntitlements( $this->entitlementRows() );
		Catalogue::entitlements();

		Agend_Test_WP::set_filter( 'agend_apps_crm_get_entitlement_types_response', array( 'success' => true, 'data' => array() ) );

		$this->assertSame( array(), Catalogue::entitlements( true )['items'] );
	}

	#[Test]
	public function a_failed_entitlement_refresh_serves_the_last_known_list(): void {
		$this->coreEntitlements( $this->entitlementRows() );
		Catalogue::entitlements();

		Agend_Test_WP::set_filter( 'agend_apps_crm_get_entitlement_types_response', new WP_Error( 'http_request_failed', 'down' ) );
		$outage = Catalogue::entitlements( true );

		$this->assertCount( 2, $outage['items'] );
		$this->assertTrue( $outage['stale'] );
		$this->assertNotSame( '', $outage['error'] );
		$this->assertCount( 2, Agend_Test_WP::$transients[ Catalogue::ENTITLEMENTS_TRANSIENT ] );
	}

	#[Test]
	public function an_entitlement_failure_is_not_cached_and_the_next_call_asks_again(): void {
		$this->coreEntitlements( new WP_Error( 'http_request_failed', 'down' ) );

		$this->assertSame( array(), Catalogue::entitlements()['items'] );
		$this->assertArrayNotHasKey( Catalogue::ENTITLEMENTS_TRANSIENT, Agend_Test_WP::$transients );

		// The next request. Within this one the failure is held in memory only.
		Catalogue::reset();
		Agend_Test_WP::set_filter( 'agend_apps_crm_get_entitlement_types_response', $this->entitlementRows() );

		$this->assertCount( 2, Catalogue::entitlements()['items'] );
		$this->assertSame( 2, $this->calls( '/crm/entitlements/types' ) );
	}

	// -----------------------------------------------------------------
	// Once per request, a failure included
	// -----------------------------------------------------------------

	/**
	 * A failure is never stored, so the transient cannot stop the next caller
	 * asking again. Without the request memo a listing of gated posts, each naming
	 * the entitlements it admits, would make a failing call per post.
	 */
	#[Test]
	public function an_outage_is_asked_of_agend_once_per_request_whoever_asks(): void {
		$this->coreEntitlements( new WP_Error( 'http_request_failed', 'down' ) );

		for ( $post = 0; $post < 5; ++$post ) {
			$this->assertSame( array(), Catalogue::entitlements()['items'] );
		}

		$sections = Catalogue::sections();

		$this->assertSame( array(), $sections['entitlements']['items'] );
		$this->assertTrue( $sections['entitlements']['stale'], 'the memoised answer still says it failed' );
		$this->assertNotSame( '', $sections['entitlements']['error'] );
		$this->assertSame( 1, $this->calls( '/crm/entitlements/types' ) );
		$this->assertArrayNotHasKey( Catalogue::ENTITLEMENTS_TRANSIENT, Agend_Test_WP::$transients, 'held in memory, never stored' );
	}

	#[Test]
	public function a_segment_outage_is_asked_of_agend_once_per_request(): void {
		$this->coreSegments( new WP_Error( 'http_request_failed', 'down' ) );

		Catalogue::segments();
		Catalogue::segments();
		Catalogue::sections();

		$this->assertSame( 1, $this->calls( '/crm/segments' ) );
		$this->assertArrayNotHasKey( Catalogue::SEGMENTS_TRANSIENT, Agend_Test_WP::$transients );
	}

	/** Plans have no call counter in their double, so what comes back is what shows whether Core was asked. */
	#[Test]
	public function a_plan_outage_is_asked_of_agend_once_per_request(): void {
		Agend_Test_WP::$tiers_response = new WP_Error( 'http_request_failed', 'down' );

		$this->assertSame( array(), Catalogue::get()['plans'] );

		// Core would now answer, but the request has already heard the failure.
		Agend_Test_WP::$tiers_response = array(
			'data' => array( array( 'id' => '11111111-1111-1111-1111-111111111111', 'name' => 'Full Member', 'slug' => 'full', 'tier_type' => 'individual', 'is_active' => true ) ),
		);

		$again = Catalogue::get();

		$this->assertSame( array(), $again['plans'] );
		$this->assertTrue( $again['stale'] );
		$this->assertArrayNotHasKey( Catalogue::TRANSIENT, Agend_Test_WP::$transients );

		Catalogue::reset();

		$this->assertCount( 1, Catalogue::get()['plans'], 'the next request asks again' );
	}

	#[Test]
	public function a_success_is_asked_once_per_request_and_read_from_memory_after(): void {
		$this->coreEntitlements( $this->entitlementRows() );

		Catalogue::entitlements();

		// Neither the transient nor Core is consulted again.
		Agend_Test_WP::$transients = array();
		Agend_Test_WP::set_filter( 'agend_apps_crm_get_entitlement_types_response', array( 'success' => true, 'data' => array() ) );

		$this->assertCount( 2, Catalogue::entitlements()['items'] );
		$this->assertSame( 1, $this->calls( '/crm/entitlements/types' ) );
	}

	/** An editor's refresh must see Agend's answer now: it passes the memo and the transient both. */
	#[Test]
	public function a_forced_refresh_bypasses_the_request_memo_as_well_as_the_transient(): void {
		$this->coreEntitlements( new WP_Error( 'http_request_failed', 'down' ) );

		$this->assertSame( array(), Catalogue::entitlements()['items'] );
		$this->assertSame( 1, $this->calls( '/crm/entitlements/types' ) );

		Agend_Test_WP::set_filter( 'agend_apps_crm_get_entitlement_types_response', $this->entitlementRows() );

		$this->assertSame( array(), Catalogue::entitlements()['items'], 'an ordinary read still hears the remembered failure' );
		$this->assertSame( 1, $this->calls( '/crm/entitlements/types' ) );

		$refreshed = Catalogue::entitlements( true );

		$this->assertCount( 2, $refreshed['items'] );
		$this->assertFalse( $refreshed['stale'] );
		$this->assertSame( 2, $this->calls( '/crm/entitlements/types' ) );

		// What the refresh learned is what the rest of the request reads.
		$this->assertCount( 2, Catalogue::entitlements()['items'] );
		$this->assertSame( 2, $this->calls( '/crm/entitlements/types' ) );
	}

	#[Test]
	public function a_forced_refresh_of_every_section_asks_each_list_again(): void {
		$this->coreSegments( $this->segmentRows() );
		$this->coreEntitlements( $this->entitlementRows() );
		Agend_Test_WP::$tiers_response = array( 'data' => array() );

		Catalogue::sections();
		Catalogue::sections();

		$this->assertSame( 1, $this->calls( '/crm/segments' ) );
		$this->assertSame( 1, $this->calls( '/crm/entitlements/types' ) );

		Catalogue::sections( true );

		$this->assertSame( 2, $this->calls( '/crm/segments' ) );
		$this->assertSame( 2, $this->calls( '/crm/entitlements/types' ) );
	}

	/** A key the policy cannot store could never be chosen, so it is not offered. */
	#[Test]
	public function a_row_without_a_well_formed_gate_key_is_dropped(): void {
		$this->coreEntitlements(
			array(
				'success' => true,
				'data'    => array(
					array( 'gate_key' => 'good.key', 'name' => 'Good' ),
					array( 'gate_key' => 'Bad Key', 'name' => 'Spaces' ),
					array( 'gate_key' => "newline.key\n", 'name' => 'Newline' ),
					array( 'gate_key' => '', 'name' => 'Empty' ),
					array( 'gate_key' => null, 'name' => 'Null' ),
					array( 'name' => 'No key at all' ),
					'not an array',
				),
			)
		);

		$this->assertSame( array( 'good.key' ), array_column( Catalogue::entitlements()['items'], 'gate_key' ) );
	}

	#[Test]
	public function an_entitlement_with_no_name_is_labelled_by_its_key_and_an_unknown_kind_is_blank(): void {
		$this->coreEntitlements(
			array(
				'success' => true,
				'data'    => array(
					array( 'gate_key' => 'no.name', 'kind' => 'something-new' ),
					array( 'gate_key' => 'blank.name', 'name' => '   ', 'kind' => 'unrecognised' ),
				),
			)
		);

		$items = Catalogue::entitlements()['items'];

		$this->assertSame( array( 'no.name', 'blank.name' ), array_column( $items, 'name' ) );
		$this->assertSame( array( '', 'unrecognised' ), array_column( $items, 'kind' ) );
	}

	// -----------------------------------------------------------------
	// A body that is not the documented answer is a failure, not an empty list
	// -----------------------------------------------------------------

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function unusableBodies(): array {
		return array(
			'success false'      => array( array( 'success' => false, 'data' => array( array( 'id' => self::SEG_A ) ) ) ),
			'no data list'       => array( array( 'success' => true ) ),
			'data is not a list' => array( array( 'success' => true, 'data' => 'nope' ) ),
			'not an array'       => array( 'garbage' ),
			'null'               => array( null ),
		);
	}

	#[Test]
	#[DataProvider( 'unusableBodies' )]
	public function an_unusable_body_is_a_failure_and_is_never_cached( $body ): void {
		$this->coreSegments( $body );
		$this->coreEntitlements( $body );

		foreach ( array( Catalogue::segments(), Catalogue::entitlements() ) as $section ) {
			$this->assertSame( array(), $section['items'] );
			$this->assertTrue( $section['stale'] );
			$this->assertStringContainsString( 'could not be loaded', $section['error'] );
		}

		$this->assertArrayNotHasKey( Catalogue::SEGMENTS_TRANSIENT, Agend_Test_WP::$transients );
		$this->assertArrayNotHasKey( Catalogue::ENTITLEMENTS_TRANSIENT, Agend_Test_WP::$transients );
	}

	/** Zero segments is a real answer, not a failure, and is cached like one. */
	#[Test]
	public function a_successful_empty_list_is_cached_and_carries_no_error(): void {
		$this->coreSegments( array( 'success' => true, 'data' => array() ) );

		$section = Catalogue::segments();

		$this->assertSame( array(), $section['items'] );
		$this->assertFalse( $section['stale'] );
		$this->assertSame( '', $section['error'] );
		$this->assertArrayHasKey( Catalogue::SEGMENTS_TRANSIENT, Agend_Test_WP::$transients );
	}

	// -----------------------------------------------------------------
	// What the editor is told
	// -----------------------------------------------------------------

	/** Core older than the wrapper: the fix is updating Core, and nothing is cached so the list fills the moment it is. */
	#[Test]
	public function a_core_without_the_entitlement_wrapper_says_which_core_is_needed(): void {
		$this->assertFalse( function_exists( 'agend_apps_crm_get_entitlement_types' ) );

		$section = Catalogue::entitlements();

		$this->assertSame( array(), $section['items'] );
		$this->assertSame(
			'Agend Apps Core 1.48.0 or later is needed to list entitlements. Update Agend Apps Core on this site.',
			$section['error']
		);
		$this->assertArrayNotHasKey( Catalogue::ENTITLEMENTS_TRANSIENT, Agend_Test_WP::$transients );
	}

	#[Test]
	public function a_core_without_the_segments_wrapper_says_which_core_is_needed(): void {
		$this->assertFalse( function_exists( 'agend_apps_crm_get_segments' ) );

		$section = Catalogue::segments();

		$this->assertSame(
			'Agend Apps Core 1.48.0 or later is needed to list segments. Update Agend Apps Core on this site.',
			$section['error']
		);
		$this->assertSame( array(), Agend_Test_WP::$transients );
	}

	/** Core reports HTTP status as `status_code` in the error data, for a live 403 and its own local refusal alike. */
	#[Test]
	public function a_403_names_the_scope_the_entitlement_list_needs(): void {
		$this->coreEntitlements( new WP_Error( 'agend_api_error', 'Forbidden', array( 'status_code' => 403, 'path' => '/crm/entitlements/types' ) ) );

		$this->assertSame(
			'This site\'s Agend API key needs the crm.entitlements.browse scope before entitlements can be listed. Add the scope to the key in Agend.',
			Catalogue::entitlements()['error']
		);
	}

	#[Test]
	public function a_403_names_the_scope_the_segment_list_needs(): void {
		$this->coreSegments( new WP_Error( 'agend_api_error', 'Forbidden', array( 'status_code' => 403 ) ) );

		$this->assertSame(
			'This site\'s Agend API key needs the crm.segments.browse scope before segments can be listed. Add the scope to the key in Agend.',
			Catalogue::segments()['error']
		);
	}

	/** Core's pre-flight refusal of a call the key is known not to be allowed to make carries its own code and the same status. */
	#[Test]
	public function a_local_scope_refusal_reads_as_a_403_too(): void {
		$this->coreEntitlements(
			new WP_Error(
				'agend_apps_scope_missing',
				'The stored API key lacks crm.entitlements.browse.',
				array( 'status_code' => 403, 'path' => '/crm/entitlements/types', 'missing' => array( 'crm.entitlements.browse' ), 'app' => null )
			)
		);

		$this->assertStringContainsString( 'crm.entitlements.browse scope', Catalogue::entitlements()['error'] );
	}

	/**
	 * @return array<string, array{0: WP_Error}>
	 */
	public static function otherFailures(): array {
		return array(
			'a transport failure'     => array( new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) ),
			'a rate limit'            => array( new WP_Error( 'agend_apps_rate_limited', 'Slow down', array( 'status_code' => 429 ) ) ),
			'a gateway error'         => array( new WP_Error( 'agend_api_error', 'Boom', array( 'status_code' => 500, 'path' => '/crm/segments' ) ) ),
			'a 401'                   => array( new WP_Error( 'agend_api_error', 'Unauthorised', array( 'status_code' => 401 ) ) ),
			'data that is not an array' => array( new WP_Error( 'agend_apps_invalid_response', 'Invalid JSON', 'a string' ) ),
		);
	}

	#[Test]
	#[DataProvider( 'otherFailures' )]
	public function anything_else_says_the_list_could_not_be_loaded_without_the_transport_detail( WP_Error $error ): void {
		$this->coreSegments( $error );
		$this->coreEntitlements( $error );

		$this->assertSame(
			'The list of segments could not be loaded from Agend. Try again in a few minutes.',
			Catalogue::segments()['error']
		);
		$this->assertSame(
			'The list of entitlements could not be loaded from Agend. Try again in a few minutes.',
			Catalogue::entitlements()['error']
		);
	}

	// -----------------------------------------------------------------
	// Three sections, failing independently
	// -----------------------------------------------------------------

	#[Test]
	public function the_sections_share_one_shape_and_fail_independently(): void {
		Agend_Test_WP::$tiers_response = array(
			'data' => array(
				array( 'id' => '11111111-1111-1111-1111-111111111111', 'name' => 'Full Member', 'slug' => 'full', 'tier_type' => 'individual', 'is_active' => true ),
				array( 'id' => '22222222-2222-2222-2222-222222222222', 'name' => 'Retired', 'slug' => 'retired', 'tier_type' => 'individual', 'is_active' => false ),
			),
		);
		$this->coreSegments( $this->segmentRows() );
		$this->coreEntitlements( new WP_Error( 'agend_api_error', 'Forbidden', array( 'status_code' => 403 ) ) );

		$sections = Catalogue::sections();

		$this->assertSame( array( 'plans', 'segments', 'entitlements' ), array_keys( $sections ) );

		foreach ( $sections as $section ) {
			$this->assertSame( array( 'items', 'stale', 'error' ), array_keys( $section ) );
		}

		$this->assertCount( 2, $sections['plans']['items'], 'every plan, with its active flag, so an inactive one can be recognised' );
		$this->assertSame( array( true, false ), array_column( $sections['plans']['items'], 'active' ) );
		$this->assertFalse( $sections['plans']['stale'] );
		$this->assertCount( 2, $sections['segments']['items'] );
		$this->assertSame( '', $sections['segments']['error'] );

		// The one section that failed says so, and the others are untouched.
		$this->assertSame( array(), $sections['entitlements']['items'] );
		$this->assertTrue( $sections['entitlements']['stale'] );
		$this->assertStringContainsString( 'crm.entitlements.browse', $sections['entitlements']['error'] );
	}

	#[Test]
	public function a_forced_refresh_refreshes_all_three_sections(): void {
		Agend_Test_WP::$tiers_response = array( 'data' => array() );
		$this->coreSegments( $this->segmentRows() );
		$this->coreEntitlements( $this->entitlementRows() );

		Catalogue::sections();
		Catalogue::sections();
		$this->assertSame( 1, $this->calls( '/crm/segments' ) );
		$this->assertSame( 1, $this->calls( '/crm/entitlements/types' ) );

		Catalogue::sections( true );
		$this->assertSame( 2, $this->calls( '/crm/segments' ) );
		$this->assertSame( 2, $this->calls( '/crm/entitlements/types' ) );
	}

	// -----------------------------------------------------------------
	// Pickers: one option shape for all three lists
	// -----------------------------------------------------------------

	#[Test]
	public function a_platform_gate_is_labelled_and_the_other_kinds_are_not(): void {
		$this->assertSame( 'Platform: Directory access', Catalogue::entitlement_label( array( 'gate_key' => 'directory.access', 'name' => 'Directory access', 'kind' => 'platform-gate' ) ) );
		$this->assertSame( 'Early bird', Catalogue::entitlement_label( array( 'gate_key' => 'entitlement.early_bird', 'name' => 'Early bird', 'kind' => 'entitlement' ) ) );
		$this->assertSame( 'Leadership library', Catalogue::entitlement_label( array( 'gate_key' => 'library.leadership', 'name' => 'Leadership library', 'kind' => 'unrecognised' ) ) );
		$this->assertSame( 'no.name', Catalogue::entitlement_label( array( 'gate_key' => 'no.name' ) ) );
	}

	#[Test]
	public function options_put_each_list_in_one_shape(): void {
		$plans = Catalogue::options(
			Policy::MODE_TIERS,
			array(
				array( 'id' => 'p1', 'name' => 'Full', 'active' => true ),
				array( 'id' => 'p2', 'name' => 'Retired', 'active' => false ),
			)
		);

		$this->assertSame( array( true, false ), array_column( $plans, 'available' ), 'only an active plan may be chosen new' );
		$this->assertSame( array( 'p1', 'p2' ), array_column( $plans, 'value' ) );

		$segments = Catalogue::options( Policy::MODE_SEGMENTS, array( array( 'id' => self::SEG_A, 'slug' => 'x', 'name' => 'Overdue' ) ) );

		$this->assertSame( array( array( 'value' => self::SEG_A, 'label' => 'Overdue', 'description' => '', 'available' => true ) ), $segments );

		$entitlements = Catalogue::options(
			Policy::MODE_ENTITLEMENTS,
			array( array( 'gate_key' => 'directory.access', 'name' => 'Directory access', 'description' => 'Browse', 'kind' => 'platform-gate' ) )
		);

		$this->assertSame(
			array( array( 'value' => 'directory.access', 'label' => 'Platform: Directory access', 'description' => 'Browse', 'available' => true ) ),
			$entitlements
		);
	}

	/** A stored value that is no longer offered is kept and flagged, never dropped. */
	#[Test]
	public function a_stored_selection_is_annotated_never_dropped(): void {
		$options = Catalogue::options( Policy::MODE_SEGMENTS, array( array( 'id' => self::SEG_A, 'slug' => 'x', 'name' => 'Overdue' ) ) );

		$annotated = Catalogue::annotate_options( array( self::SEG_A, self::SEG_B ), $options );

		$this->assertSame(
			array(
				array( 'value' => self::SEG_A, 'label' => 'Overdue', 'available' => true ),
				array( 'value' => self::SEG_B, 'label' => '', 'available' => false ),
			),
			$annotated
		);
	}
}
