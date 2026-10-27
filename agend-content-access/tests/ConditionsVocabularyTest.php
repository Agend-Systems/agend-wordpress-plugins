<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Condition_Providers as Providers;
use Agend_Content_Access_Condition_Sets as Sets;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;
use WP_Error;
use WP_REST_Request;

require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-policy.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-catalogue.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-meta-box.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-viewer-facts.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-conditions.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-providers.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-runtime.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-fragment-rules.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-sets.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/rest/conditions-routes.php';

/**
 * The rule vocabulary the block editor's picker reads.
 *
 * What these protect: every rule the picker offers is already in the form a
 * block stores, with plans and segments named by ID and not slug, so choosing
 * one can never write something the server reads differently; a slug that an
 * older block holds is still recognised, as an alias, without becoming a second
 * choice; a list that could not load is present and says why, so the picker can
 * show the reason and not "this site has none"; and nothing a plan, segment or
 * entitlement carries beyond its name reaches the browser.
 *
 * Separate processes, because the segment and entitlement lists come from Core
 * functions that either exist or do not, and cannot be undefined once declared.
 */
#[CoversFunction( 'agend_content_access_conditions_payload' )]
#[CoversFunction( 'agend_content_access_get_conditions' )]
#[RunTestsInSeparateProcesses]
final class ConditionsVocabularyTest extends TestCase {

	private const PLAN_GOLD = '11111111-1111-4111-8111-111111111111';
	private const PLAN_OLD  = '22222222-2222-4222-8222-222222222222';
	private const PLAN_BARE = '33333333-3333-4333-8333-333333333333';
	private const SEG_BOARD = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1';
	private const SEG_BARE  = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa2';

	/** Core answers, with a full set of everything. */
	private function seed(): void {
		require_once __DIR__ . '/fixtures/core-segments-double.php';
		require_once __DIR__ . '/fixtures/core-entitlement-types-double.php';

		Agend_Test_WP::$tiers_response = array(
			'data' => array(
				array( 'id' => self::PLAN_GOLD, 'name' => 'Gold', 'slug' => 'gold', 'tier_type' => 'individual', 'is_active' => true, 'price_cents' => 45000, 'member_count' => 317 ),
				array( 'id' => self::PLAN_OLD, 'name' => 'Retired', 'slug' => 'retired', 'tier_type' => 'individual', 'is_active' => false ),
				array( 'id' => self::PLAN_BARE, 'name' => 'No slug', 'tier_type' => 'individual', 'is_active' => true ),
			),
		);

		Agend_Test_WP::set_filter(
			'agend_apps_crm_get_segments_response',
			array(
				'success' => true,
				'data'    => array(
					array( 'id' => self::SEG_BOARD, 'slug' => 'board', 'name' => 'Board', 'member_count' => 12, 'rules' => array( 'secret' => 'x' ) ),
					array( 'id' => self::SEG_BARE, 'name' => 'No slug' ),
				),
			)
		);

		Agend_Test_WP::set_filter(
			'agend_apps_crm_get_entitlement_types_response',
			array(
				'success' => true,
				'data'    => array(
					array( 'id' => 'r2', 'gate_key' => 'premium.directory', 'name' => 'Premium directory', 'description' => null, 'kind' => 'entitlement' ),
					array( 'id' => 'r1', 'gate_key' => 'directory.access', 'name' => 'Directory access', 'description' => 'Browse the directory', 'kind' => 'platform-gate' ),
					array( 'id' => 'r3', 'gate_key' => 'library.leadership', 'name' => 'Leadership library', 'description' => null, 'kind' => 'unrecognised' ),
				),
			)
		);
	}

	/** @return array<string, array> The groups of the payload, by key. */
	private function groupsByKey( bool $refresh = false ): array {
		$groups = array();

		foreach ( agend_content_access_conditions_payload( $refresh )['groups'] as $group ) {
			$groups[ $group['key'] ] = $group;
		}

		return $groups;
	}

	// -----------------------------------------------------------------
	// Shape
	// -----------------------------------------------------------------

	#[Test]
	public function the_payload_is_groups_fallbacks_and_errors_and_the_groups_come_in_the_pickers_order(): void {
		$this->seed();

		$payload = agend_content_access_conditions_payload();

		$this->assertSame( array( 'groups', 'fallbacks', 'errors' ), array_keys( $payload ) );
		$this->assertSame(
			array( Providers::MEMBERSHIP, Providers::SEGMENTS, Providers::ENTITLEMENTS, Providers::WORDPRESS ),
			array_column( $payload['groups'], 'key' )
		);
		$this->assertSame( array( 'Membership', 'Segments', 'Entitlements', 'WordPress' ), array_column( $payload['groups'], 'label' ) );
		$this->assertSame( array( false, false, false, false ), array_column( $payload['groups'], 'stale' ) );
		$this->assertSame( array(), $payload['errors'] );
	}

	#[Test]
	public function every_group_and_its_items_are_lists_in_json(): void {
		$this->seed();

		$json = (string) json_encode( agend_content_access_conditions_payload() );

		$this->assertStringStartsWith( '{"groups":[', $json );
		$this->assertStringContainsString( '"errors":[]', $json );
		$this->assertStringContainsString( '"fallbacks":[]', $json );

		foreach ( agend_content_access_conditions_payload()['groups'] as $group ) {
			$this->assertTrue( array_is_list( $group['items'] ), $group['key'] );
		}
	}

	// -----------------------------------------------------------------
	// Membership
	// -----------------------------------------------------------------

	#[Test]
	public function membership_is_the_standing_rule_then_every_plan_by_id(): void {
		$this->seed();

		$items = $this->groupsByKey()[ Providers::MEMBERSHIP ]['items'];

		$this->assertSame(
			array(
				array( 'value' => 'agend_membership:is_member', 'label' => 'Any current member', 'kind' => 'member' ),
				array( 'value' => 'agend_membership:' . self::PLAN_GOLD, 'label' => 'Gold', 'kind' => 'plan', 'aliases' => array( 'agend_membership:gold' ) ),
				array( 'value' => 'agend_membership:' . self::PLAN_OLD, 'label' => 'Retired', 'kind' => 'plan', 'aliases' => array( 'agend_membership:retired' ), 'inactive' => true ),
				array( 'value' => 'agend_membership:' . self::PLAN_BARE, 'label' => 'No slug', 'kind' => 'plan', 'aliases' => array() ),
			),
			$items
		);
	}

	/** New rules name a plan by ID. A slug is only ever an alias. */
	#[Test]
	public function no_plan_value_is_a_slug(): void {
		$this->seed();

		$values = array_column( $this->groupsByKey()[ Providers::MEMBERSHIP ]['items'], 'value' );

		$this->assertNotContains( 'agend_membership:gold', $values );
		$this->assertNotContains( 'agend_membership:retired', $values );
	}

	#[Test]
	public function a_plan_whose_slug_is_its_id_has_no_alias(): void {
		$this->seed();

		Agend_Test_WP::$tiers_response = array(
			'data' => array( array( 'id' => 'same', 'name' => 'Same', 'slug' => 'same', 'tier_type' => 'individual', 'is_active' => true ) ),
		);

		$this->assertSame( array(), $this->groupsByKey()[ Providers::MEMBERSHIP ]['items'][1]['aliases'] );
	}

	// -----------------------------------------------------------------
	// Segments
	// -----------------------------------------------------------------

	#[Test]
	public function segments_are_by_id_with_the_slug_as_an_alias(): void {
		$this->seed();

		$this->assertSame(
			array(
				array( 'value' => 'agend_segments:' . self::SEG_BOARD, 'label' => 'Board', 'kind' => 'segment', 'aliases' => array( 'agend_segments:board' ) ),
				array( 'value' => 'agend_segments:' . self::SEG_BARE, 'label' => 'No slug', 'kind' => 'segment', 'aliases' => array() ),
			),
			$this->groupsByKey()[ Providers::SEGMENTS ]['items'],
			'a segment with no slug is offered too: it is named by id'
		);
	}

	// -----------------------------------------------------------------
	// Entitlements
	// -----------------------------------------------------------------

	#[Test]
	public function entitlements_are_by_gate_key_with_platform_gates_first_and_named_plainly(): void {
		$this->seed();

		$this->assertSame(
			array(
				array( 'value' => 'agend_entitlements:directory.access', 'label' => 'Directory access', 'kind' => 'platform', 'description' => 'Browse the directory' ),
				array( 'value' => 'agend_entitlements:premium.directory', 'label' => 'Premium directory', 'kind' => 'entitlement' ),
				array( 'value' => 'agend_entitlements:library.leadership', 'label' => 'Leadership library', 'kind' => 'entitlement' ),
			),
			$this->groupsByKey()[ Providers::ENTITLEMENTS ]['items'],
			'the label carries no "Platform:" prefix: the picker composes it from the kind'
		);
	}

	// -----------------------------------------------------------------
	// WordPress
	// -----------------------------------------------------------------

	#[Test]
	public function wordpress_is_signed_in_signed_out_and_every_role(): void {
		$this->seed();

		$items = $this->groupsByKey()[ Providers::WORDPRESS ]['items'];

		$this->assertSame(
			array( 'agend_wordpress:logged_in', 'agend_wordpress:logged_out', 'agend_wordpress:role_administrator', 'agend_wordpress:role_editor', 'agend_wordpress:role_author', 'agend_wordpress:role_contributor', 'agend_wordpress:role_subscriber' ),
			array_column( $items, 'value' )
		);
		$this->assertSame( array( 'login', 'login', 'role', 'role', 'role', 'role', 'role' ), array_column( $items, 'kind' ) );
		$this->assertSame( 'Signed in', $items[0]['label'] );
		$this->assertSame( 'Editor', $items[3]['label'], 'the role name, with no "Role:" prefix' );
	}

	// -----------------------------------------------------------------
	// Errors
	// -----------------------------------------------------------------

	#[Test]
	public function a_list_that_could_not_load_is_present_and_says_why(): void {
		$this->seed();

		Agend_Test_WP::set_filter( 'agend_apps_crm_get_entitlement_types_response', new WP_Error( 'forbidden', 'Refused', array( 'status' => 403 ) ) );

		$payload = agend_content_access_conditions_payload();
		$group   = array_column( $payload['groups'], null, 'key' )[ Providers::ENTITLEMENTS ];

		$this->assertSame( array(), $group['items'], 'present, and empty: not left out' );
		$this->assertTrue( $group['stale'] );
		$this->assertCount( 1, $payload['errors'] );
		$this->assertSame( Providers::ENTITLEMENTS, $payload['errors'][0]['group'] );
		$this->assertNotSame( '', $payload['errors'][0]['message'] );
		$this->assertStringNotContainsString( 'Refused', $payload['errors'][0]['message'], 'a transport detail is not shown to an editor' );
		$this->assertNotEmpty( array_column( $payload['groups'], null, 'key' )[ Providers::SEGMENTS ]['items'], 'the other lists are not hidden' );
	}

	#[Test]
	public function a_core_without_the_entitlement_wrapper_leaves_the_group_empty_with_its_message(): void {
		require_once __DIR__ . '/fixtures/core-segments-double.php';

		Agend_Test_WP::set_filter( 'agend_apps_crm_get_segments_response', array( 'success' => true, 'data' => array() ) );

		$this->assertFalse( function_exists( 'agend_apps_crm_get_entitlement_types' ) );

		$payload = agend_content_access_conditions_payload();

		$this->assertSame( array(), array_column( $payload['groups'], null, 'key' )[ Providers::ENTITLEMENTS ]['items'] );
		$this->assertSame( array( Providers::ENTITLEMENTS ), array_column( $payload['errors'], 'group' ) );
	}

	// -----------------------------------------------------------------
	// Extensions and leaks
	// -----------------------------------------------------------------

	#[Test]
	public function a_group_a_site_adds_follows_the_built_in_ones(): void {
		$this->seed();

		Agend_Test_WP::set_filter(
			'agend_content_access_condition_sets',
			array(
				'acme_crm' => array(
					'label'      => 'Acme CRM',
					'conditions' => array( 'vip' => 'VIP', 2024 => 'Class of 2024' ),
				),
				'broken'   => 'not a set',
				'bare'     => array( 'conditions' => array( 'a' => 'A' ) ),
			)
		);

		$groups = $this->groupsByKey();

		$this->assertSame(
			array( Providers::MEMBERSHIP, Providers::SEGMENTS, Providers::ENTITLEMENTS, Providers::WORDPRESS, 'acme_crm', 'bare' ),
			array_keys( $groups )
		);
		$this->assertSame(
			array(
				array( 'value' => 'acme_crm:vip', 'label' => 'VIP', 'kind' => 'other' ),
				array( 'value' => 'acme_crm:2024', 'label' => 'Class of 2024', 'kind' => 'other' ),
			),
			$groups['acme_crm']['items']
		);
		$this->assertSame( 'bare', $groups['bare']['label'], 'labelled by its key when it has none' );
	}

	#[Test]
	public function the_filter_cannot_swap_in_slug_values_for_the_built_in_groups(): void {
		$this->seed();

		Agend_Test_WP::set_filter(
			'agend_content_access_condition_sets',
			array(
				Providers::MEMBERSHIP => array( 'label' => 'Membership', 'conditions' => array( 'gold' => 'Plan: Gold' ) ),
			)
		);

		$this->assertNotContains( 'agend_membership:gold', array_column( $this->groupsByKey()[ Providers::MEMBERSHIP ]['items'], 'value' ) );
	}

	/** Reduction is an allow list; what the catalogue carries beyond a name never reaches the browser. */
	#[Test]
	public function nothing_beyond_names_ids_slugs_and_descriptions_reaches_the_browser(): void {
		$this->seed();

		$json = (string) json_encode( agend_content_access_conditions_payload() );

		foreach ( array( '45000', 'price_cents', 'member_count', '317', 'secret', 'rules', 'tier_type', 'individual' ) as $forbidden ) {
			$this->assertStringNotContainsString( $forbidden, $json, $forbidden );
		}
	}

	// -----------------------------------------------------------------
	// Refresh
	// -----------------------------------------------------------------

	private function gatewayCalls( string $url ): int {
		return count(
			array_filter(
				Agend_Test_WP::$requests,
				static fn( array $request ): bool => $url === $request['url']
			)
		);
	}

	#[Test]
	public function the_lists_are_read_once_and_a_refresh_reads_them_again(): void {
		$this->seed();

		agend_content_access_conditions_payload();

		$this->assertSame( 1, $this->gatewayCalls( '/crm/segments' ) );

		\Agend_Content_Access_Catalogue::reset();

		// Cached: a second request does not ask the gateway.
		agend_content_access_conditions_payload();

		$this->assertSame( 1, $this->gatewayCalls( '/crm/segments' ) );

		\Agend_Content_Access_Catalogue::reset();
		agend_content_access_conditions_payload( true );

		$this->assertSame( 2, $this->gatewayCalls( '/crm/segments' ) );
		$this->assertSame( 2, $this->gatewayCalls( '/crm/entitlements/types' ) );
	}

	#[Test]
	public function the_route_passes_the_refresh_argument_through(): void {
		$this->seed();

		agend_content_access_get_conditions();

		$this->assertSame( 1, $this->gatewayCalls( '/crm/segments' ) );

		$request = new WP_REST_Request( 'GET', '/agend-content-access/v1/conditions' );
		$request->set_param( 'refresh', true );

		agend_content_access_get_conditions( $request );

		$this->assertSame( 2, $this->gatewayCalls( '/crm/segments' ), 'the memo is bypassed, as ?refresh=1 asks' );

		$plain = new WP_REST_Request( 'GET', '/agend-content-access/v1/conditions' );

		agend_content_access_get_conditions( $plain );

		$this->assertSame( 2, $this->gatewayCalls( '/crm/segments' ), 'without it the lists are served from what was just read' );
	}

	// -----------------------------------------------------------------
	// The same vocabulary, flattened for Elementor
	// -----------------------------------------------------------------

	/** The route is a view of the shared vocabulary, so the two editors cannot offer different rules. */
	#[Test]
	public function the_route_payload_is_the_shared_vocabulary(): void {
		$this->seed();

		$this->assertSame( Sets::vocabulary(), agend_content_access_conditions_payload() );
	}

	#[Test]
	public function the_flattened_options_say_what_kind_of_rule_each_is_and_keep_the_groups_in_order(): void {
		$this->seed();

		$this->assertSame(
			array(
				'agend_membership:is_member'            => 'Any current member',
				'agend_membership:' . self::PLAN_GOLD   => 'Plan: Gold',
				'agend_membership:' . self::PLAN_BARE   => 'Plan: No slug',
				'agend_segments:' . self::SEG_BOARD     => 'Segment: Board',
				'agend_segments:' . self::SEG_BARE      => 'Segment: No slug',
				'agend_entitlements:directory.access'   => 'Platform: Directory access',
				'agend_entitlements:premium.directory'  => 'Entitlement: Premium directory',
				'agend_entitlements:library.leadership' => 'Entitlement: Leadership library',
				'agend_wordpress:logged_in'             => 'Signed in',
				'agend_wordpress:logged_out'            => 'Signed out',
				'agend_wordpress:role_administrator'    => 'Role: Administrator',
				'agend_wordpress:role_editor'           => 'Role: Editor',
				'agend_wordpress:role_author'           => 'Role: Author',
				'agend_wordpress:role_contributor'      => 'Role: Contributor',
				'agend_wordpress:role_subscriber'       => 'Role: Subscriber',
			),
			Sets::rule_options()
		);
	}

	#[Test]
	public function a_plan_that_was_switched_off_is_not_offered_and_no_slug_is_a_second_choice(): void {
		$this->seed();

		$options = Sets::rule_options();

		$this->assertArrayNotHasKey( 'agend_membership:' . self::PLAN_OLD, $options );
		$this->assertArrayNotHasKey( 'agend_membership:retired', $options );
		$this->assertArrayNotHasKey( 'agend_membership:gold', $options, 'a slug is only an alias of the id rule' );
		$this->assertArrayNotHasKey( 'agend_segments:board', $options );
	}

	/** A platform capability and an authored entitlement can share a name, and must not read alike. */
	#[Test]
	public function a_platform_gate_and_an_entitlement_of_the_same_name_read_differently(): void {
		$this->seed();

		Agend_Test_WP::set_filter(
			'agend_apps_crm_get_entitlement_types_response',
			array(
				'success' => true,
				'data'    => array(
					array( 'id' => 'a', 'gate_key' => 'directory.access', 'name' => 'Directory access', 'kind' => 'platform-gate' ),
					array( 'id' => 'b', 'gate_key' => 'directory.listing', 'name' => 'Directory access', 'kind' => 'entitlement' ),
				),
			)
		);

		$options = Sets::rule_options();

		$this->assertSame( 'Platform: Directory access', $options['agend_entitlements:directory.access'] );
		$this->assertSame( 'Entitlement: Directory access', $options['agend_entitlements:directory.listing'] );
	}

	#[Test]
	public function a_rule_a_site_adds_is_labelled_with_its_group(): void {
		$this->seed();

		Agend_Test_WP::set_filter(
			'agend_content_access_condition_sets',
			array( 'acme_crm' => array( 'label' => 'Acme CRM', 'conditions' => array( 'vip' => 'VIP' ) ) )
		);

		$this->assertSame( 'Acme CRM: VIP', Sets::rule_options()['acme_crm:vip'] );
	}

	/** A list that failed to load leaves the rules of the other lists on offer. */
	#[Test]
	public function a_list_that_could_not_load_leaves_the_others_on_offer(): void {
		$this->seed();

		Agend_Test_WP::set_filter( 'agend_apps_crm_get_entitlement_types_response', new WP_Error( 'forbidden', 'Refused', array( 'status' => 403 ) ) );

		$options = Sets::rule_options();

		$this->assertSame( 'Segment: Board', $options[ 'agend_segments:' . self::SEG_BOARD ] );
		$this->assertSame( array(), array_filter( array_keys( $options ), static fn( string $rule ): bool => str_starts_with( $rule, 'agend_entitlements:' ) ) );
	}
}
