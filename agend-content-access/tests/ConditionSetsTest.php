<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Catalogue as Catalogue;
use Agend_Content_Access_Condition_Providers as Providers;
use Agend_Content_Access_Condition_Runtime as Runtime;
use Agend_Content_Access_Condition_Sets as Sets;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;
use WP_Error;

require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-policy.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-catalogue.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-viewer-facts.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-conditions.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-providers.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-runtime.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-sets.php';

/**
 * The condition vocabulary an editor chooses from, Entitlements group.
 *
 * Core's `agend_apps_crm_get_entitlement_types()` may or may not exist, and a
 * PHP function cannot be undefined once declared, so each test runs in its own
 * process and the tests that want Core to have it require the double first.
 * That is the only way to show both worlds in one suite.
 *
 * What these protect: a Core without the wrapper must leave the group present
 * but empty (so the editor sees the list could not be fetched, not that the
 * site has no entitlements), and a failure must never be cached.
 */
#[CoversClass( Sets::class )]
#[RunTestsInSeparateProcesses]
final class ConditionSetsTest extends TestCase {

	/** Makes Core "have" the wrapper, answering with the given response. */
	private function coreAnswers( $response ): void {
		require_once __DIR__ . '/fixtures/core-entitlement-types-double.php';

		Agend_Test_WP::set_filter( 'agend_apps_crm_get_entitlement_types_response', $response );
	}

	/** Makes Core "have" the segments wrapper, answering with the given response. */
	private function coreAnswersSegments( $response ): void {
		require_once __DIR__ . '/fixtures/core-segments-double.php';

		Agend_Test_WP::set_filter( 'agend_apps_crm_get_segments_response', $response );
	}

	private function segmentCalls(): int {
		return count(
			array_filter(
				Agend_Test_WP::$requests,
				static fn( array $request ): bool => '/crm/segments' === $request['url']
			)
		);
	}

	private function segmentRows(): array {
		return array(
			'success' => true,
			'data'    => array(
				array( 'id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1', 'slug' => 'overdue', 'name' => 'Overdue renewals' ),
				array( 'id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa2', 'slug' => 'volunteers', 'name' => 'Volunteers' ),
				array( 'id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa3', 'name' => 'No slug' ),
			),
		);
	}

	private function typeCalls(): int {
		return count(
			array_filter(
				Agend_Test_WP::$requests,
				static fn( array $request ): bool => '/crm/entitlements/types' === $request['url']
			)
		);
	}

	private function rows(): array {
		return array(
			'success' => true,
			'data'    => array(
				array( 'id' => 'r1', 'gate_key' => 'directory.access', 'name' => 'Directory access', 'description' => 'Browse the directory', 'managed_by_source_id' => null, 'kind' => 'platform-gate' ),
				array( 'id' => 'r2', 'gate_key' => 'entitlement.early_bird', 'name' => 'Early bird', 'description' => null, 'managed_by_source_id' => null, 'kind' => 'entitlement' ),
				array( 'id' => 'r3', 'gate_key' => 'library.leadership', 'name' => 'Leadership library', 'description' => null, 'managed_by_source_id' => null, 'kind' => 'unrecognised' ),
			),
		);
	}

	// -----------------------------------------------------------------
	// Core without the wrapper
	// -----------------------------------------------------------------

	#[Test]
	public function the_group_is_present_and_empty_without_the_core_function(): void {
		$this->assertFalse( function_exists( 'agend_apps_crm_get_entitlement_types' ) );

		$sets = Sets::all();

		$this->assertArrayHasKey( Providers::ENTITLEMENTS, $sets );
		$this->assertSame( 'Entitlements', $sets[ Providers::ENTITLEMENTS ]['label'] );
		$this->assertSame( array(), $sets[ Providers::ENTITLEMENTS ]['conditions'] );
	}

	/** An older Core is not an outcome to remember: the group must fill the moment Core is updated. */
	#[Test]
	public function a_missing_core_function_is_not_cached(): void {
		Sets::entitlement_conditions();

		$this->assertArrayNotHasKey( Catalogue::ENTITLEMENTS_TRANSIENT, Agend_Test_WP::$transients );
		$this->assertSame( array(), Agend_Test_WP::$transients );
	}

	#[Test]
	public function the_group_sits_after_segments_and_the_others_are_untouched(): void {
		$this->assertSame(
			array( Providers::WORDPRESS, Providers::MEMBERSHIP, Providers::SEGMENTS, Providers::ENTITLEMENTS ),
			array_keys( Sets::all() )
		);
		$this->assertArrayHasKey( 'logged_in', Sets::all()[ Providers::WORDPRESS ]['conditions'] );
		$this->assertArrayHasKey( 'is_member', Sets::all()[ Providers::MEMBERSHIP ]['conditions'] );
	}

	// -----------------------------------------------------------------
	// Core with the wrapper
	// -----------------------------------------------------------------

	#[Test]
	public function the_group_is_populated_by_gate_key_and_platform_gates_are_labelled(): void {
		$this->coreAnswers( $this->rows() );

		$conditions = Sets::all()[ Providers::ENTITLEMENTS ]['conditions'];

		$this->assertSame(
			array(
				'directory.access'       => 'Platform: Directory access',
				'entitlement.early_bird' => 'Early bird',
				'library.leadership'     => 'Leadership library',
			),
			$conditions
		);
	}

	/** An editor picks `agend_entitlements:<gate_key>`, so the key is the stored value. */
	#[Test]
	public function the_flattened_options_carry_the_provider_and_the_group_name(): void {
		$this->coreAnswers( $this->rows() );

		$options = Sets::as_options();

		$this->assertSame( 'Entitlements / Platform: Directory access', $options['agend_entitlements:directory.access'] );
		$this->assertSame( 'Entitlements / Early bird', $options['agend_entitlements:entitlement.early_bird'] );
	}

	#[Test]
	public function a_row_without_a_usable_name_is_labelled_by_its_key(): void {
		$this->coreAnswers(
			array(
				'success' => true,
				'data'    => array(
					array( 'gate_key' => 'no.name' ),
					array( 'gate_key' => 'blank.name', 'name' => '   ' ),
					array( 'gate_key' => 'platform.nameless', 'kind' => 'platform-gate' ),
				),
			)
		);

		$this->assertSame(
			array(
				'no.name'           => 'no.name',
				'blank.name'        => 'blank.name',
				'platform.nameless' => 'Platform: platform.nameless',
			),
			Sets::entitlement_conditions()
		);
	}

	/** A key that fails the gate key pattern could never match a held key, so offering it is a choice that cannot pass. */
	#[Test]
	public function a_row_without_a_well_formed_gate_key_is_skipped(): void {
		$this->coreAnswers(
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

		$this->assertSame( array( 'good.key' => 'Good' ), Sets::entitlement_conditions() );
	}

	#[Test]
	public function the_vocabulary_is_cached_for_the_cache_lifetime_and_not_refetched(): void {
		$this->coreAnswers( $this->rows() );

		$first = Sets::entitlement_conditions();

		$this->assertSame( 1, $this->typeCalls() );
		$this->assertArrayHasKey( Catalogue::ENTITLEMENTS_TRANSIENT, Agend_Test_WP::$transients );
		$this->assertSame( Runtime::cache_ttl(), Agend_Test_WP::$transient_ttls[ Catalogue::ENTITLEMENTS_TRANSIENT ] );

		// Were Core asked again it would now say something else.
		Agend_Test_WP::set_filter( 'agend_apps_crm_get_entitlement_types_response', array( 'success' => true, 'data' => array() ) );

		$this->assertSame( $first, Sets::entitlement_conditions() );
		$this->assertSame( 1, $this->typeCalls() );
	}

	// -----------------------------------------------------------------
	// Failure is never cached
	// -----------------------------------------------------------------

	#[Test]
	public function a_core_error_leaves_the_group_empty_and_is_not_cached(): void {
		$this->coreAnswers( new WP_Error( 'missing_scope', 'crm.entitlements.browse is required' ) );

		$sets = Sets::all();

		$this->assertSame( array(), $sets[ Providers::ENTITLEMENTS ]['conditions'], 'present but empty' );
		$this->assertArrayNotHasKey( Catalogue::ENTITLEMENTS_TRANSIENT, Agend_Test_WP::$transients, 'a failure must write nothing' );

		// The next call asks again, and a recovery fills the group at once.
		Agend_Test_WP::set_filter( 'agend_apps_crm_get_entitlement_types_response', $this->rows() );

		$this->assertCount( 3, Sets::entitlement_conditions() );
		$this->assertSame( 2, $this->typeCalls() );
	}

	/** The gateway can answer 200 with `success: false`; that is a failure too. */
	#[Test]
	public function an_unsuccessful_response_is_a_failure_and_is_not_cached(): void {
		$this->coreAnswers( array( 'success' => false, 'data' => $this->rows()['data'] ) );

		$this->assertSame( array(), Sets::entitlement_conditions() );
		$this->assertSame( array(), Agend_Test_WP::$transients );
	}

	#[Test]
	public function a_non_array_response_is_a_failure_and_is_not_cached(): void {
		$this->coreAnswers( 'garbage' );

		$this->assertSame( array(), Sets::entitlement_conditions() );
		$this->assertSame( array(), Agend_Test_WP::$transients );
	}

	/** Zero entitlement types is a real answer, not a failure, and is cached like one. */
	#[Test]
	public function a_successful_empty_list_is_cached(): void {
		$this->coreAnswers( array( 'success' => true, 'data' => array() ) );

		$this->assertSame( array(), Sets::entitlement_conditions() );
		$this->assertArrayHasKey( Catalogue::ENTITLEMENTS_TRANSIENT, Agend_Test_WP::$transients );
	}

	// -----------------------------------------------------------------
	// Segments, and one cache per list
	// -----------------------------------------------------------------

	/** The vocabulary keeps its slug keys, which are what stored conditions name. */
	#[Test]
	public function the_segment_group_is_keyed_by_slug_and_a_segment_with_no_slug_is_left_out(): void {
		$this->coreAnswersSegments( $this->segmentRows() );

		$this->assertSame(
			array(
				'overdue'    => 'Overdue renewals',
				'volunteers' => 'Volunteers',
			),
			Sets::all()[ Providers::SEGMENTS ]['conditions']
		);
	}

	#[Test]
	public function the_segment_group_is_present_and_empty_when_the_list_cannot_be_loaded(): void {
		$this->coreAnswersSegments( new WP_Error( 'agend_api_error', 'down' ) );

		$sets = Sets::all();

		$this->assertArrayHasKey( Providers::SEGMENTS, $sets );
		$this->assertSame( array(), $sets[ Providers::SEGMENTS ]['conditions'] );
		$this->assertArrayNotHasKey( Catalogue::SEGMENTS_TRANSIENT, Agend_Test_WP::$transients, 'a failure must write nothing' );
	}

	/**
	 * There is one cache per list. The vocabulary and the editor pickers read the
	 * same catalogue section, so the first reader pays for the gateway call and
	 * every other reader, in either order, is served from it.
	 */
	#[Test]
	public function one_gateway_call_serves_the_segment_vocabulary_and_the_catalogue(): void {
		$this->coreAnswersSegments( $this->segmentRows() );

		Sets::segment_conditions();
		$section = Catalogue::segments();

		$this->assertSame( 1, $this->segmentCalls() );
		$this->assertCount( 3, $section['items'], 'the catalogue offers the segment the vocabulary left out' );
		$this->assertSame(
			array( Catalogue::SEGMENTS_TRANSIENT ),
			array_keys( Agend_Test_WP::$transients ),
			'one transient, not one per consumer'
		);
	}

	#[Test]
	public function one_gateway_call_serves_the_entitlement_vocabulary_and_the_catalogue(): void {
		$this->coreAnswers( $this->rows() );

		Catalogue::entitlements();
		$conditions = Sets::entitlement_conditions();

		$this->assertSame( 1, $this->typeCalls() );
		$this->assertCount( 3, $conditions );
		$this->assertSame(
			array( Catalogue::ENTITLEMENTS_TRANSIENT ),
			array_keys( Agend_Test_WP::$transients )
		);
	}

	/** The retired vocabulary caches held another shape and must never be read. */
	#[Test]
	public function an_old_vocabulary_cache_is_not_read(): void {
		Agend_Test_WP::$transients['agend_content_access_segment_vocabulary']     = array( 'stale' => 'Old label' );
		Agend_Test_WP::$transients['agend_content_access_entitlement_vocabulary'] = array( 'old.key' => 'Old label' );

		$this->assertSame( array(), Sets::segment_conditions() );
		$this->assertSame( array(), Sets::entitlement_conditions() );
	}
}
