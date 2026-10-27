<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Frontend as Frontend;
use Agend_Content_Access_Page_Rules as PageRules;
use Agend_Content_Access_Policy as Policy;
use Agend_Content_Access_Rules as Rules;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

require_once __DIR__ . '/has-elementor-double.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-policy.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-catalogue.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-meta-box.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-fragment-rules.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-block-attributes.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-conditions.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-providers.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-runtime.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-sets.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-rules.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-access.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-viewer-facts.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-decision.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-destination.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-placeholder.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-frontend.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-page-rules.php';

/**
 * A page's rules: how they are read for the editor, validated, saved and kept
 * private.
 *
 * The values of the setting are what decides who reads a members page, so the tests
 * are mostly about what must NOT happen: a bad value never replaces a good one, an
 * empty list never clears a page by accident, the 0.1.x policy never outlives the
 * rules that replaced it, and nobody who cannot edit the page can read or write them.
 */
#[CoversClass( PageRules::class )]
final class PageRulesTest extends TestCase {

	private const POST     = 40;
	private const PAGE     = 41;
	private const DRAFT    = 42;
	private const PROTECT  = 43;
	private const MEMBERS  = 44;
	private const PATTERN  = 45;
	private const TEMPLATE = 46;
	private const PRODUCT  = 47;
	private const MISSING  = 999;

	private const TIER = '11111111-1111-1111-1111-111111111111';
	private const SEG  = '22222222-2222-2222-2222-222222222222';
	private const MEMBER = 'agend_membership:is_member';

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['agend_test_current_user_can']['edit_post'] = true;
		$GLOBALS['agend_test_post_types']                    = array( 'post' => 'post', 'page' => 'page', 'wp_block' => 'wp_block', 'elementor_library' => 'elementor_library' );

		$this->post( self::POST, 'page', 'The page being edited' );
		$this->post( self::PAGE, 'page', 'A public page' );
		$this->post( self::DRAFT, 'page', 'A draft', array( 'post_status' => 'draft' ) );
		$this->post( self::PROTECT, 'page', 'A password page', array( 'post_password' => 'hunter2' ) );
		$this->post( self::MEMBERS, 'page', 'A members page' );
		$GLOBALS['agend_test_post_meta'][ self::MEMBERS ][ Rules::META_KEY ] = array( 'rules' => array( self::MEMBER ), 'match' => 'any', 'invert' => false );
		$this->post( self::PATTERN, 'wp_block', 'A pattern' );
		$this->post( self::TEMPLATE, 'elementor_library', 'A template' );
		$this->post( self::PRODUCT, 'product', 'Not an offered type' );
	}

	// -----------------------------------------------------------------------
	// Fixtures
	// -----------------------------------------------------------------------

	private function post( int $id, string $type, string $title, array $extra = array() ): void {
		$GLOBALS['agend_test_posts'][ $id ] = array_merge(
			array(
				'ID'           => $id,
				'post_status'  => 'publish',
				'post_type'    => $type,
				'post_title'   => $title,
				'post_content' => '',
				'post_excerpt' => '',
			),
			$extra
		);
	}

	/** The setting an author's panel sends. */
	private function setting( array $rules = array( self::MEMBER ), array $over = array() ): array {
		return array_merge(
			array(
				'rules'  => $rules,
				'match'  => 'any',
				'invert' => false,
				'denied' => array( 'action' => 'placeholder', 'target_id' => 0, 'fallback_id' => 0 ),
			),
			$over
		);
	}

	private function denied( string $action, int $target = 0, int $fallback = 0 ): array {
		return $this->setting( array( self::MEMBER ), array( 'denied' => array( 'action' => $action, 'target_id' => $target, 'fallback_id' => $fallback ) ) );
	}

	private function storeRules( array $value ): void {
		$GLOBALS['agend_test_post_meta'][ self::POST ][ Rules::META_KEY ] = $value;
	}

	private function storePolicy( $value ): void {
		$GLOBALS['agend_test_post_meta'][ self::POST ][ Policy::META_KEY ] = $value;
	}

	/** @return mixed What the page holds under a meta key, or null when it holds none. */
	private function meta( string $key ) {
		return $GLOBALS['agend_test_post_meta'][ self::POST ][ $key ] ?? null;
	}

	private function error( $input ): ?string {
		return PageRules::validate( $input, self::POST )['error'];
	}

	private function value( $input ): ?array {
		return PageRules::validate( $input, self::POST )['value'];
	}

	// -----------------------------------------------------------------------
	// Who can read and write it: registration
	// -----------------------------------------------------------------------

	#[Test]
	public function the_meta_is_registered_for_every_supported_type_without_rest(): void {
		( new PageRules() )->register_meta();

		$registered = array_values( array_filter( Agend_Test_WP::$registered_post_meta, static fn( array $entry ): bool => Rules::META_KEY === $entry['key'] ) );

		$this->assertSame( array( 'post', 'page', 'wp_block', 'elementor_library' ), array_column( $registered, 'post_type' ) );

		foreach ( $registered as $entry ) {
			$this->assertFalse( $entry['args']['show_in_rest'], 'in no meta object, so not readable through one and not writable through one' );
			$this->assertTrue( $entry['args']['single'] );
		}
	}

	#[Test]
	public function writing_the_meta_needs_edit_rights_on_that_post(): void {
		( new PageRules() )->register_meta();

		$callback = Agend_Test_WP::$registered_post_meta[0]['args']['auth_callback'];

		$GLOBALS['agend_test_current_user_can']['edit_post']          = false;
		$GLOBALS['agend_test_current_user_can_for']['edit_post'][ 7 ] = true;

		$this->assertTrue( $callback( false, Rules::META_KEY, 7 ) );
		$this->assertFalse( $callback( true, Rules::META_KEY, 8 ), 'even where something else said yes' );
	}

	#[Test]
	public function the_rest_field_is_registered_in_the_edit_context_only_and_checked_before_each_post_is_written(): void {
		( new PageRules() )->register_rest();

		$fields = array_values( array_filter( Agend_Test_WP::$registered_rest_fields, static fn( array $entry ): bool => PageRules::FIELD === $entry['name'] ) );

		$this->assertSame( array( 'post', 'page', 'wp_block', 'elementor_library' ), array_column( $fields, 'object_type' ) );

		foreach ( $fields as $entry ) {
			$this->assertSame( array( 'edit' ), $entry['args']['schema']['context'], 'never in a view response' );
			$this->assertFalse( $entry['args']['schema']['additionalProperties'] );
			$this->assertIsCallable( $entry['args']['get_callback'] );
			$this->assertIsCallable( $entry['args']['update_callback'] );
		}

		foreach ( array( 'post', 'page', 'wp_block', 'elementor_library' ) as $type ) {
			$this->assertArrayHasKey( "rest_pre_insert_{$type}", Agend_Test_WP::$filter_registry, $type );
		}
	}

	#[Test]
	public function the_field_name_is_the_one_the_response_filter_removes(): void {
		$this->assertSame( Rules::REST_FIELD, PageRules::FIELD );
	}

	#[Test]
	public function the_schema_is_strict_about_every_part_of_the_setting(): void {
		$schema = PageRules::rest_schema();

		$this->assertSame( array( 'any', 'all' ), $schema['properties']['match']['enum'] );
		$this->assertSame( array( 'placeholder', 'redirect', 'back' ), $schema['properties']['denied']['properties']['action']['enum'] );
		$this->assertFalse( $schema['properties']['denied']['additionalProperties'] );
		$this->assertTrue( $schema['properties']['legacy']['readonly'] );
		$this->assertTrue( $schema['properties']['unreadable']['readonly'] );
	}

	// -----------------------------------------------------------------------
	// Who can read and write it: the callbacks
	// -----------------------------------------------------------------------

	#[Test]
	public function somebody_who_can_edit_the_post_reads_its_setting(): void {
		$this->storeRules( array( 'rules' => array( self::MEMBER ), 'match' => 'any', 'invert' => false ) );

		$value = ( new PageRules() )->rest_get( array( 'id' => self::POST ) );

		$this->assertSame( array( self::MEMBER ), $value['rules'] );
	}

	#[Test]
	public function somebody_who_cannot_edit_the_post_reads_nothing(): void {
		$this->storeRules( array( 'rules' => array( self::MEMBER ), 'match' => 'any', 'invert' => false ) );
		$GLOBALS['agend_test_current_user_can']['edit_post'] = false;

		$this->assertNull( ( new PageRules() )->rest_get( array( 'id' => self::POST ) ) );
		$this->assertNull( ( new PageRules() )->rest_get( array() ), 'and a response with no id is not somebody' );
	}

	/** WordPress lets anybody who can edit posts at all ask for the edit context of a collection. */
	#[Test]
	public function a_contributor_reads_the_setting_of_their_own_post_and_not_of_anyone_elses(): void {
		$GLOBALS['agend_test_current_user_can']['edit_post']               = false;
		$GLOBALS['agend_test_current_user_can_for']['edit_post'][ self::PAGE ] = true;
		$this->storeRules( array( 'rules' => array( self::MEMBER ), 'match' => 'any', 'invert' => false ) );

		$this->assertNotNull( ( new PageRules() )->rest_get( array( 'id' => self::PAGE ) ) );
		$this->assertNull( ( new PageRules() )->rest_get( array( 'id' => self::POST ) ) );
	}

	#[Test]
	public function somebody_who_cannot_edit_the_post_cannot_write_its_setting(): void {
		$GLOBALS['agend_test_current_user_can']['edit_post'] = false;
		$this->storePolicy( array( 'mode' => 'active_member' ) );

		$result = ( new PageRules() )->rest_update( $this->setting( array() ), get_post( self::POST ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'agend_content_access_forbidden', $result->get_error_code() );
		$this->assertSame( array( 'mode' => 'active_member' ), $this->meta( Policy::META_KEY ), 'nothing was cleared' );
		$this->assertNull( $this->meta( Rules::META_KEY ) );
	}

	#[Test]
	public function the_setting_is_written_by_the_update_callback_for_an_editor(): void {
		$result = ( new PageRules() )->rest_update( $this->setting(), get_post( self::POST ) );

		$this->assertTrue( $result );
		$this->assertSame( array( self::MEMBER ), $this->meta( Rules::META_KEY )['rules'] );
	}

	#[Test]
	public function a_refused_value_is_a_400_with_a_code_the_editor_can_show_and_keeps_what_was_stored(): void {
		$this->storeRules( $this->setting() );

		$result = ( new PageRules() )->rest_update( $this->setting( array( 'nonsense' ) ), get_post( self::POST ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( PageRules::ERROR_CODE, $result->get_error_code() );
		$this->assertSame( array( 'status' => 400 ), $result->get_error_data() );
		$this->assertSame( $this->setting(), $this->meta( Rules::META_KEY ) );
	}

	// -----------------------------------------------------------------------
	// Nobody who cannot edit the page ever sees it in a response
	// -----------------------------------------------------------------------

	/** A response as core builds it, with the field present as it would be were the getter to let it through. */
	private function response( int $id ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'id'                => $id,
				'content'           => array( 'rendered' => '<p>BODY</p>', 'protected' => false ),
				'excerpt'           => array( 'rendered' => '<p>excerpt</p>', 'protected' => false ),
				'meta'              => array(),
				PageRules::FIELD    => array( 'rules' => array( 'agend_segments:' . self::SEG ) ),
			)
		);
	}

	private function filtered( int $id, string $context ): array {
		$request = new WP_REST_Request( 'GET', '/wp/v2/pages/' . $id );
		$request->set_param( 'context', $context );

		return ( new Frontend() )->filter_rest_response( $this->response( $id ), get_post( $id ), $request )->get_data();
	}

	#[Test]
	public function a_visitor_to_an_open_page_never_gets_the_rules_of_its_blocks_page_or_anything_else_in_a_response(): void {
		$GLOBALS['agend_test_current_user_can']['edit_post'] = false;

		// A page nobody restricts: the response is granted, and left alone but for this.
		$data = $this->filtered( self::PAGE, 'view' );

		$this->assertArrayNotHasKey( PageRules::FIELD, $data );
		$this->assertStringNotContainsString( self::SEG, (string) wp_json_encode( $data ) );
		$this->assertSame( '<p>BODY</p>', $data['content']['rendered'], 'and the open page is still served' );
	}

	#[Test]
	public function a_member_who_is_let_in_never_gets_the_rules_either(): void {
		$GLOBALS['agend_test_current_user_can']['edit_post'] = false;
		$GLOBALS['agend_test_current_user_id']               = 7;
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'member-token' );
		Agend_Test_WP::set_filter(
			'agend_apps_crm_get_my_entitlements_response',
			array( 'data' => array( 'is_member' => true, 'tier_ids' => array( self::TIER ) ) )
		);

		$data = $this->filtered( self::MEMBERS, 'view' );

		$this->assertSame( '<p>BODY</p>', $data['content']['rendered'], 'granted' );
		$this->assertArrayNotHasKey( PageRules::FIELD, $data );
	}

	#[Test]
	public function a_visitor_who_is_denied_never_gets_the_rules(): void {
		$GLOBALS['agend_test_current_user_can']['edit_post'] = false;
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', '' );

		$data = $this->filtered( self::MEMBERS, 'view' );

		$this->assertSame( '', $data['content']['rendered'], 'denied' );
		$this->assertArrayNotHasKey( PageRules::FIELD, $data );
	}

	/** The edit context of a collection is open to anybody who can edit any post. */
	#[Test]
	public function somebody_who_cannot_edit_this_post_never_gets_the_rules_in_the_edit_context_either(): void {
		$GLOBALS['agend_test_current_user_can']['edit_post'] = false;

		$this->assertArrayNotHasKey( PageRules::FIELD, $this->filtered( self::PAGE, 'edit' ) );
		$this->assertArrayNotHasKey( PageRules::FIELD, $this->filtered( self::MEMBERS, 'edit' ) );
	}

	#[Test]
	public function the_editor_of_the_post_keeps_the_field_in_the_edit_context(): void {
		$GLOBALS['agend_test_current_user_can']['edit_post'] = true;

		$this->assertArrayHasKey( PageRules::FIELD, $this->filtered( self::PAGE, 'edit' ) );
	}

	/** An editor reading the public view of a page is a reader like any other: the field is for the editor only. */
	#[Test]
	public function even_an_editor_who_asks_for_the_view_context_does_not_get_it(): void {
		$GLOBALS['agend_test_current_user_can']['edit_post'] = true;

		$this->assertArrayNotHasKey( PageRules::FIELD, $this->filtered( self::PAGE, 'view' ) );
	}

	// -----------------------------------------------------------------------
	// Reading: what the panel shows
	// -----------------------------------------------------------------------

	#[Test]
	public function a_page_with_nothing_stored_is_a_page_with_no_rules(): void {
		$this->assertSame(
			array(
				'rules'      => array(),
				'match'      => 'any',
				'invert'     => false,
				'denied'     => array( 'action' => 'placeholder', 'target_id' => 0, 'fallback_id' => 0 ),
				'legacy'     => false,
				'unreadable' => false,
			),
			PageRules::state( self::POST )
		);
	}

	#[Test]
	public function stored_rules_are_shown_as_they_are_with_their_denial_setting(): void {
		$this->storeRules( array( 'rules' => array( self::MEMBER, 'agend_segments:' . self::SEG ), 'match' => 'all', 'invert' => true, 'denied' => array( 'action' => 'redirect', 'target_id' => self::PAGE, 'fallback_id' => 0 ) ) );

		$state = PageRules::state( self::POST );

		$this->assertSame( array( self::MEMBER, 'agend_segments:' . self::SEG ), $state['rules'] );
		$this->assertSame( 'all', $state['match'] );
		$this->assertTrue( $state['invert'] );
		$this->assertSame( array( 'action' => 'redirect', 'target_id' => self::PAGE, 'fallback_id' => 0 ), $state['denied'] );
		$this->assertFalse( $state['legacy'] );
		$this->assertFalse( $state['unreadable'] );
	}

	/**
	 * The 0.1.x setting, shown as the rules it reads as, with a flag so the panel can
	 * say so.
	 *
	 * @return array<string, array{0: array, 1: string[]}>
	 */
	public static function legacyPolicies(): array {
		return array(
			'any member'     => array( array( 'mode' => 'active_member' ), array( 'agend_membership:is_member' ) ),
			'selected plans' => array( array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER ) ), array( 'agend_membership:' . self::TIER ) ),
			'segments'       => array( array( 'mode' => 'segments', 'segment_ids' => array( self::SEG ) ), array( 'agend_segments:' . self::SEG ) ),
			'entitlements'   => array( array( 'mode' => 'selected_entitlements', 'entitlement_keys' => array( 'directory.access' ) ), array( 'agend_entitlements:directory.access' ) ),
		);
	}

	#[Test]
	#[DataProvider( 'legacyPolicies' )]
	public function a_page_with_only_a_0_1_policy_is_shown_as_its_converted_rules_and_flagged_as_older( array $policy, array $rules ): void {
		$this->storePolicy( $policy );

		$state = PageRules::state( self::POST );

		$this->assertSame( $rules, $state['rules'] );
		$this->assertTrue( $state['legacy'] );
		$this->assertFalse( $state['unreadable'] );
		$this->assertSame( 'placeholder', $state['denied']['action'], 'and it gets the default denial setting, as the gate gives it' );
	}

	#[Test]
	public function a_policy_that_says_public_is_no_rules_and_needs_no_notice(): void {
		$this->storePolicy( array( 'mode' => 'public' ) );

		$state = PageRules::state( self::POST );

		$this->assertSame( array(), $state['rules'] );
		$this->assertFalse( $state['legacy'] );
	}

	#[Test]
	public function the_new_setting_wins_over_the_old_policy_and_is_not_called_older(): void {
		$this->storePolicy( array( 'mode' => 'active_member' ) );
		$this->storeRules( array( 'rules' => array( 'agend_wordpress:logged_in' ), 'match' => 'any', 'invert' => false ) );

		$state = PageRules::state( self::POST );

		$this->assertSame( array( 'agend_wordpress:logged_in' ), $state['rules'] );
		$this->assertFalse( $state['legacy'] );
	}

	/**
	 * What cannot be shown as rules: the server hides the page from everyone, and the
	 * panel offers a reset and nothing else.
	 *
	 * @return array<string, array{0: string, 1: mixed}>
	 */
	public static function unreadableSettings(): array {
		return array(
			'a policy with a mode this version lacks' => array( Policy::META_KEY, array( 'mode' => 'authenticated' ) ),
			'a policy with an empty list'             => array( Policy::META_KEY, array( 'mode' => 'segments', 'segment_ids' => array() ) ),
			'rules that are not a list of strings'    => array( Rules::META_KEY, array( 'rules' => array( 7 ), 'match' => 'any', 'invert' => false ) ),
			'a match that is neither any nor all'     => array( Rules::META_KEY, array( 'rules' => array( self::MEMBER ), 'match' => 'some', 'invert' => false ) ),
			'a reverse that is not a boolean'         => array( Rules::META_KEY, array( 'rules' => array( self::MEMBER ), 'match' => 'any', 'invert' => 'yes' ) ),
			'a value that is not an array'            => array( Rules::META_KEY, 'members' ),
			'rules reversed with nothing to match'    => array( Rules::META_KEY, array( 'rules' => array(), 'match' => 'any', 'invert' => true ) ),
			'a set made of several parts'             => array(
				Rules::META_KEY,
				array(
					'rules'  => array(),
					'match'  => 'any',
					'invert' => false,
					'all_of' => array(
						array( 'rules' => array( self::MEMBER ), 'match' => 'any', 'invert' => false ),
						array( 'rules' => array( 'agend_wordpress:logged_in' ), 'match' => 'any', 'invert' => false ),
					),
				),
			),
		);
	}

	#[Test]
	#[DataProvider( 'unreadableSettings' )]
	public function a_setting_that_cannot_be_shown_is_flagged_unreadable_with_nothing_in_it( string $key, $value ): void {
		$GLOBALS['agend_test_post_meta'][ self::POST ][ $key ] = $value;

		$state = PageRules::state( self::POST );

		$this->assertTrue( $state['unreadable'] );
		$this->assertSame( array(), $state['rules'] );
		$this->assertFalse( $state['invert'] );
		$this->assertFalse( $state['legacy'] );
	}

	#[Test]
	public function reading_a_setting_never_writes_it(): void {
		$this->storePolicy( array( 'mode' => 'active_member' ) );
		$before = $GLOBALS['agend_test_post_meta'];

		PageRules::state( self::POST );

		$this->assertSame( $before, $GLOBALS['agend_test_post_meta'], 'opening a page does not convert it' );
	}

	// -----------------------------------------------------------------------
	// Validation: what is accepted
	// -----------------------------------------------------------------------

	#[Test]
	public function a_valid_setting_comes_back_in_the_stored_shape_and_nothing_else(): void {
		$this->assertSame(
			array(
				'rules'  => array( self::MEMBER, 'agend_segments:' . self::SEG ),
				'match'  => 'all',
				'invert' => true,
				'denied' => array( 'action' => 'placeholder', 'target_id' => 0, 'fallback_id' => 0 ),
			),
			$this->value( $this->setting( array( self::MEMBER, 'agend_segments:' . self::SEG ), array( 'match' => 'all', 'invert' => true ) ) )
		);
	}

	/** @return array<string, array{0: string}> */
	public static function everyKindOfRule(): array {
		return array(
			'signed in'      => array( 'agend_wordpress:logged_in' ),
			'a role'         => array( 'agend_wordpress:role_editor' ),
			'a member'       => array( 'agend_membership:is_member' ),
			'a plan'         => array( 'agend_membership:' . self::TIER ),
			'a segment'      => array( 'agend_segments:' . self::SEG ),
			'an entitlement' => array( 'agend_entitlements:directory.access' ),
			'an old ESAC rule, which the engine still reads' => array( 'iugo_esac_wordpress:logged_in' ),
			'a value with a colon in it' => array( 'agend_membership:a:b' ),
		);
	}

	#[Test]
	#[DataProvider( 'everyKindOfRule' )]
	public function every_kind_of_rule_the_picker_offers_is_accepted( string $rule ): void {
		$this->assertNull( $this->error( $this->setting( array( $rule ) ) ) );
		$this->assertSame( array( $rule ), $this->value( $this->setting( array( $rule ) ) )['rules'] );
	}

	#[Test]
	public function a_rule_is_trimmed_and_a_repeat_is_dropped(): void {
		$this->assertSame(
			array( self::MEMBER, 'agend_wordpress:logged_in' ),
			$this->value( $this->setting( array( ' ' . self::MEMBER . ' ', 'agend_wordpress:logged_in', self::MEMBER ) ) )['rules']
		);
	}

	#[Test]
	public function match_is_any_below_two_rules_whatever_was_sent(): void {
		$this->assertSame( 'any', $this->value( $this->setting( array( self::MEMBER ), array( 'match' => 'all' ) ) )['match'] );
		$this->assertSame( 'all', $this->value( $this->setting( array( self::MEMBER, 'agend_wordpress:logged_in' ), array( 'match' => 'all' ) ) )['match'] );
	}

	#[Test]
	public function match_reverse_and_the_denial_setting_may_be_left_out(): void {
		$value = $this->value( array( 'rules' => array( self::MEMBER ) ) );

		$this->assertSame( 'any', $value['match'] );
		$this->assertFalse( $value['invert'] );
		$this->assertSame( array( 'action' => 'placeholder', 'target_id' => 0, 'fallback_id' => 0 ), $value['denied'] );
		$this->assertSame( array( 'action' => 'placeholder', 'target_id' => 0, 'fallback_id' => 0 ), $this->value( $this->setting( array( self::MEMBER ), array( 'denied' => array() ) ) )['denied'] );
	}

	#[Test]
	public function what_the_server_said_about_what_it_read_may_come_back_and_is_ignored(): void {
		$value = $this->value( $this->setting( array( self::MEMBER ), array( 'legacy' => true, 'unreadable' => false ) ) );

		$this->assertSame( array( 'rules', 'match', 'invert', 'denied' ), array_keys( $value ) );
	}

	#[Test]
	public function a_site_s_own_provider_is_accepted_when_it_offers_one(): void {
		$this->assertNotNull( $this->error( $this->setting( array( 'acme_crm:vip' ) ) ) );

		// The stub answers a filter with the value it is given, so this is the whole
		// vocabulary a site's filter would leave.
		Agend_Test_WP::set_filter(
			'agend_content_access_condition_sets',
			array( 'acme_crm' => array( 'label' => 'Acme CRM', 'conditions' => array( 'vip' => 'VIP' ) ) )
		);

		$this->assertNull( $this->error( $this->setting( array( 'acme_crm:vip' ) ) ) );
	}

	/** The denial settings, each accepted and narrowed to what its action uses. */
	#[Test]
	public function a_placeholder_may_be_the_built_in_one_a_public_page_a_pattern_or_a_template(): void {
		Agend_Test_WP::$did_action['elementor/loaded'] = 1;

		foreach ( array( 0, self::PAGE, self::PATTERN, self::TEMPLATE ) as $target ) {
			$this->assertSame(
				array( 'action' => 'placeholder', 'target_id' => $target, 'fallback_id' => 0 ),
				$this->value( $this->denied( 'placeholder', $target ) )['denied'],
				(string) $target
			);
		}
	}

	#[Test]
	public function a_redirect_goes_to_a_published_page(): void {
		$this->assertSame(
			array( 'action' => 'redirect', 'target_id' => self::PAGE, 'fallback_id' => 0 ),
			$this->value( $this->denied( 'redirect', self::PAGE ) )['denied']
		);
	}

	/** The page it goes to may itself be for members: a dashboard is a good place to send members. */
	#[Test]
	public function a_redirect_may_go_to_a_page_that_has_rules_of_its_own(): void {
		$this->assertNull( $this->error( $this->denied( 'redirect', self::MEMBERS ) ) );
	}

	#[Test]
	public function the_way_back_may_have_a_fallback_page_or_none(): void {
		$this->assertSame(
			array( 'action' => 'back', 'target_id' => 0, 'fallback_id' => self::PAGE ),
			$this->value( $this->denied( 'back', 0, self::PAGE ) )['denied']
		);
		$this->assertSame(
			array( 'action' => 'back', 'target_id' => 0, 'fallback_id' => 0 ),
			$this->value( $this->denied( 'back' ) )['denied'],
			'with none, a visitor who did not come from this site sees the placeholder'
		);
	}

	/** A number the action does not use is dropped. That narrows and never widens: it is not somewhere anybody is sent. */
	#[Test]
	public function a_number_the_action_does_not_use_is_dropped(): void {
		$this->assertSame( array( 'action' => 'redirect', 'target_id' => self::PAGE, 'fallback_id' => 0 ), $this->value( $this->denied( 'redirect', self::PAGE, self::DRAFT ) )['denied'] );
		$this->assertSame( array( 'action' => 'back', 'target_id' => 0, 'fallback_id' => self::PAGE ), $this->value( $this->denied( 'back', self::DRAFT, self::PAGE ) )['denied'] );
		$this->assertSame( array( 'action' => 'placeholder', 'target_id' => self::PAGE, 'fallback_id' => 0 ), $this->value( $this->denied( 'placeholder', self::PAGE, self::DRAFT ) )['denied'] );
	}

	#[Test]
	public function a_page_number_may_arrive_as_a_string_of_digits(): void {
		$this->assertSame( self::PAGE, $this->value( $this->setting( array( self::MEMBER ), array( 'denied' => array( 'action' => 'redirect', 'target_id' => (string) self::PAGE ) ) ) )['denied']['target_id'] );
	}

	// -----------------------------------------------------------------------
	// Validation: what is refused
	// -----------------------------------------------------------------------

	/** @return array<string, array{0: mixed}> */
	public static function notASetting(): array {
		return array(
			'null'                 => array( null ),
			'a string'             => array( 'members' ),
			'a number'             => array( 7 ),
			'an empty array'       => array( array() ),
			'a list'               => array( array( 'agend_membership:is_member' ) ),
			'a key it does not know' => array( array( 'rules' => array( 'agend_wordpress:logged_in' ), 'mode' => 'public' ) ),
			'no rules key'         => array( array( 'match' => 'any', 'invert' => false ) ),
			'a flagged unreadable' => array( array( 'rules' => array(), 'unreadable' => true ) ),
		);
	}

	#[Test]
	#[DataProvider( 'notASetting' )]
	public function a_value_that_is_not_a_setting_is_refused( $input ): void {
		$result = PageRules::validate( $input, self::POST );

		$this->assertNull( $result['value'] );
		$this->assertIsString( $result['error'] );
		$this->assertNotSame( '', $result['error'] );
	}

	/** An unreadable setting echoed back would clear it, and open a page that is hidden from everyone. */
	#[Test]
	public function an_unreadable_flag_sent_back_never_clears_the_page(): void {
		$this->storeRules( array( 'rules' => array( 'a_provider_that_does_not_exist:x' ), 'match' => 'any', 'invert' => false ) );

		$this->assertNotNull( $this->error( array( 'rules' => array(), 'match' => 'any', 'invert' => false, 'unreadable' => true ) ) );
	}

	/** @return array<string, array{0: mixed}> */
	public static function notARuleList(): array {
		return array(
			'a string'             => array( 'agend_membership:is_member' ),
			'an object'            => array( array( 'a' => 'agend_membership:is_member' ) ),
			'a number'             => array( 7 ),
			'null'                 => array( null ),
		);
	}

	#[Test]
	#[DataProvider( 'notARuleList' )]
	public function rules_that_are_not_a_list_are_refused( $rules ): void {
		$this->assertNotNull( $this->error( $this->setting( array(), array( 'rules' => $rules ) ) ) );
	}

	/** @return array<string, array{0: mixed}> */
	public static function badRules(): array {
		return array(
			'a number'                   => array( 7 ),
			'null'                       => array( null ),
			'an array'                   => array( array( 'agend_membership:is_member' ) ),
			'an empty string'            => array( '' ),
			'only spaces'                => array( '   ' ),
			'no colon'                   => array( 'agend_membership' ),
			'nothing before the colon'   => array( ':is_member' ),
			'nothing after the colon'    => array( 'agend_membership:' ),
			'a space after the colon'    => array( 'agend_membership: is_member' ),
			'a provider that starts with a digit' => array( '1agend:is_member' ),
			'a provider with a dash'     => array( 'agend-membership:is_member' ),
			'a new line in the value'    => array( "agend_membership:is\nmember" ),
			'a control character'        => array( "agend_membership:is\x07member" ),
			'a value that is far too long' => array( 'agend_membership:' . str_repeat( 'a', 300 ) ),
			'a provider this site does not know' => array( 'made_up_provider:value' ),
		);
	}

	#[Test]
	#[DataProvider( 'badRules' )]
	public function a_malformed_or_unknown_rule_is_refused_whole( $rule ): void {
		$result = PageRules::validate( $this->setting( array( self::MEMBER, $rule ) ), self::POST );

		$this->assertNull( $result['value'], 'the good rule beside it is not saved alone, which would be a different page from the one asked for' );
		$this->assertNotNull( $result['error'] );
	}

	#[Test]
	public function too_many_rules_are_refused(): void {
		$rules = array();

		for ( $n = 0; $n <= PageRules::MAX_RULES; $n++ ) {
			$rules[] = 'agend_segments:s' . $n;
		}

		$this->assertNotNull( $this->error( $this->setting( $rules ) ) );
		$this->assertNull( $this->error( $this->setting( array_slice( $rules, 0, PageRules::MAX_RULES ) ) ) );
	}

	/** @return array<string, array{0: mixed}> */
	public static function badMatches(): array {
		return array(
			'some'       => array( 'some' ),
			'in capitals' => array( 'ALL' ),
			'a boolean'  => array( true ),
			'a number'   => array( 1 ),
			'a list'     => array( array( 'any' ) ),
		);
	}

	#[Test]
	#[DataProvider( 'badMatches' )]
	public function a_match_that_is_not_any_or_all_is_refused( $match ): void {
		$this->assertNotNull( $this->error( $this->setting( array( self::MEMBER ), array( 'match' => $match ) ) ) );
	}

	/** @return array<string, array{0: mixed}> */
	public static function badReverses(): array {
		return array(
			'a string'    => array( 'yes' ),
			'a number'    => array( 1 ),
			'null'        => array( null ),
			'a list'      => array( array( true ) ),
		);
	}

	#[Test]
	#[DataProvider( 'badReverses' )]
	public function a_reverse_that_is_not_a_boolean_is_refused( $invert ): void {
		$this->assertNotNull( $this->error( $this->setting( array( self::MEMBER ), array( 'invert' => $invert ) ) ) );
	}

	/** Reversed with nothing to match would be nobody, which an author who removed the last rule did not choose. */
	#[Test]
	public function reversing_a_list_with_no_rules_is_refused_rather_than_hiding_the_page_from_everyone(): void {
		$result = PageRules::validate( $this->setting( array(), array( 'invert' => true ) ), self::POST );

		$this->assertNull( $result['value'] );
		$this->assertNotNull( $result['error'] );
	}

	/** @return array<string, array{0: mixed}> */
	public static function badDenials(): array {
		return array(
			'a string'               => array( 'redirect' ),
			'a list'                 => array( array( 'redirect' ) ),
			'an action that is not one' => array( array( 'action' => 'block', 'target_id' => 0, 'fallback_id' => 0 ) ),
			'a null action'          => array( array( 'action' => null ) ),
			'an action in capitals'  => array( array( 'action' => 'REDIRECT', 'target_id' => 41 ) ),
			'a key it does not know' => array( array( 'action' => 'placeholder', 'url' => 'https://evil.test/' ) ),
			'a negative page'        => array( array( 'action' => 'redirect', 'target_id' => -41 ) ),
			'a page that is a float' => array( array( 'action' => 'redirect', 'target_id' => 41.5 ) ),
			'a page that is text'    => array( array( 'action' => 'redirect', 'target_id' => 'page' ) ),
			'a page that is a url'   => array( array( 'action' => 'redirect', 'target_id' => 'https://evil.test/' ) ),
			'a page that is a list'  => array( array( 'action' => 'redirect', 'target_id' => array( 41 ) ) ),
		);
	}

	#[Test]
	#[DataProvider( 'badDenials' )]
	public function a_denial_setting_that_is_not_one_is_refused( $denied ): void {
		$this->assertNotNull( $this->error( $this->setting( array( self::MEMBER ), array( 'denied' => $denied ) ) ) );
	}

	/** @return array<string, array{0: int}> */
	public static function pagesNobodyCanBeSentTo(): array {
		return array(
			'nothing chosen'           => array( 0 ),
			'a page that is missing'   => array( self::MISSING ),
			'a draft'                  => array( self::DRAFT ),
			'a page with a password'   => array( self::PROTECT ),
			'a pattern, which has no address' => array( self::PATTERN ),
			'an Elementor template, which has none either' => array( self::TEMPLATE ),
			'a type the panel is not offered on' => array( self::PRODUCT ),
			'the page itself, which would loop' => array( self::POST ),
		);
	}

	#[Test]
	#[DataProvider( 'pagesNobodyCanBeSentTo' )]
	public function a_redirect_to_somewhere_nobody_can_be_sent_is_refused( int $target ): void {
		$this->assertNotNull( $this->error( $this->denied( 'redirect', $target ) ) );
	}

	#[Test]
	#[DataProvider( 'pagesNobodyCanBeSentTo' )]
	public function a_fallback_page_nobody_can_be_sent_to_is_refused( int $fallback ): void {
		if ( 0 === $fallback ) {
			$this->assertNull( $this->error( $this->denied( 'back', 0, 0 ) ), 'none is allowed, a redirect needs one' );

			return;
		}

		$this->assertNotNull( $this->error( $this->denied( 'back', 0, $fallback ) ) );
	}

	/** @return array<string, array{0: int}> */
	public static function contentThatCannotBeAPlaceholder(): array {
		return array(
			'content that is missing'       => array( self::MISSING ),
			'a draft'                       => array( self::DRAFT ),
			'content with a password'       => array( self::PROTECT ),
			'a page with rules of its own'  => array( self::MEMBERS ),
			'a type that cannot be shown'   => array( self::PRODUCT ),
			'the page itself'               => array( self::POST ),
		);
	}

	#[Test]
	#[DataProvider( 'contentThatCannotBeAPlaceholder' )]
	public function a_placeholder_that_cannot_be_shown_to_everyone_is_refused( int $target ): void {
		$this->assertNotNull( $this->error( $this->denied( 'placeholder', $target ) ) );
	}

	#[Test]
	public function an_elementor_template_is_only_a_placeholder_when_elementor_is_active(): void {
		$this->assertNotNull( $this->error( $this->denied( 'placeholder', self::TEMPLATE ) ), 'it renders nothing without Elementor' );

		Agend_Test_WP::$did_action['elementor/loaded'] = 1;

		$this->assertNull( $this->error( $this->denied( 'placeholder', self::TEMPLATE ) ) );
	}

	// -----------------------------------------------------------------------
	// Saving
	// -----------------------------------------------------------------------

	#[Test]
	public function a_valid_setting_is_stored_in_the_new_shape(): void {
		$this->assertNull( PageRules::save( self::POST, $this->denied( 'redirect', self::PAGE ) ) );

		$this->assertSame( $this->denied( 'redirect', self::PAGE ), $this->meta( Rules::META_KEY ) );
	}

	#[Test]
	public function saving_new_rules_deletes_the_old_policy(): void {
		$this->storePolicy( array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER ) ) );

		$this->assertNull( PageRules::save( self::POST, $this->setting() ) );

		$this->assertNull( $this->meta( Policy::META_KEY ), 'it could otherwise come back the day the new rules are removed' );
		$this->assertSame( array( self::MEMBER ), $this->meta( Rules::META_KEY )['rules'] );
	}

	/** The page's own enforcement agrees: it follows the new rules, and nothing of the old policy is left to intersect. */
	#[Test]
	public function after_saving_the_page_is_judged_by_the_new_rules_alone(): void {
		$this->storePolicy( array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER ) ) );

		PageRules::save( self::POST, $this->setting( array( 'agend_wordpress:logged_in' ) ) );

		$rules = Rules::from_post( self::POST );

		$this->assertSame( array( 'agend_wordpress:logged_in' ), $rules->rules() );
		$this->assertCount( 1, $rules->leaves() );
	}

	#[Test]
	public function clearing_every_rule_deletes_the_rules_and_the_old_policy_so_the_page_is_open(): void {
		$this->storeRules( $this->setting() );
		$this->storePolicy( array( 'mode' => 'active_member' ) );

		$this->assertNull( PageRules::save( self::POST, $this->setting( array() ) ) );

		$this->assertNull( $this->meta( Rules::META_KEY ) );
		$this->assertNull( $this->meta( Policy::META_KEY ) );
		$this->assertTrue( Rules::from_post( self::POST )->is_empty(), 'open to everyone, which the author chose' );
	}

	#[Test]
	public function clearing_a_page_that_only_has_an_old_policy_removes_it_as_well(): void {
		$this->storePolicy( array( 'mode' => 'active_member' ) );

		PageRules::save( self::POST, $this->setting( array() ) );

		$this->assertNull( $this->meta( Policy::META_KEY ) );
		$this->assertTrue( Rules::from_post( self::POST )->is_empty() );
	}

	#[Test]
	public function clearing_removes_an_unreadable_setting_too(): void {
		$this->storeRules( array( 'rules' => array( 7 ) ) );
		$this->storePolicy( array( 'mode' => 'authenticated' ) );

		PageRules::save( self::POST, $this->setting( array() ) );

		$this->assertNull( $this->meta( Rules::META_KEY ) );
		$this->assertNull( $this->meta( Policy::META_KEY ) );
	}

	/**
	 * A refused value changes nothing at all: not the rules, not the old policy.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function refusedValues(): array {
		return array(
			'a rule that is not one'       => array( array( 'rules' => array( 'nonsense' ), 'match' => 'any', 'invert' => false ) ),
			'a match that is not one'      => array( array( 'rules' => array( self::MEMBER ), 'match' => 'some', 'invert' => false ) ),
			'an action that is not one'    => array( array( 'rules' => array( self::MEMBER ), 'denied' => array( 'action' => 'block' ) ) ),
			'a redirect to a draft'        => array( array( 'rules' => array( self::MEMBER ), 'denied' => array( 'action' => 'redirect', 'target_id' => self::DRAFT ) ) ),
			'a placeholder that is missing' => array( array( 'rules' => array( self::MEMBER ), 'denied' => array( 'action' => 'placeholder', 'target_id' => self::MISSING ) ) ),
			'nothing at all'               => array( array() ),
		);
	}

	#[Test]
	#[DataProvider( 'refusedValues' )]
	public function a_refused_value_keeps_the_previous_rules_and_the_old_policy_untouched( $input ): void {
		$rules  = $this->setting( array( 'agend_wordpress:logged_in' ) );
		$policy = array( 'mode' => 'active_member' );

		$this->storeRules( $rules );
		$this->storePolicy( $policy );

		$error = PageRules::save( self::POST, $input );

		$this->assertIsString( $error );
		$this->assertSame( $rules, $this->meta( Rules::META_KEY ) );
		$this->assertSame( $policy, $this->meta( Policy::META_KEY ) );
	}

	/** Never coerced into something wider: a refused value on a page with an old policy leaves the old policy enforcing. */
	#[Test]
	public function a_refused_value_never_makes_a_restricted_page_open(): void {
		$this->storePolicy( array( 'mode' => 'active_member' ) );

		PageRules::save( self::POST, $this->setting( array( 'not a rule' ) ) );

		$this->assertFalse( Rules::from_post( self::POST )->is_empty() );
		$this->assertSame( array( self::MEMBER ), Rules::from_post( self::POST )->rules() );
	}

	// -----------------------------------------------------------------------
	// REST: the check before the post is written
	// -----------------------------------------------------------------------

	private function request( $value, int $id = self::POST ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/wp/v2/pages/' . $id );
		$request->set_param( 'id', (string) $id );

		if ( null !== $value ) {
			$request->set_param( PageRules::FIELD, $value );
		}

		return $request;
	}

	#[Test]
	public function a_save_that_does_not_carry_the_field_is_not_touched(): void {
		$prepared = new \stdClass();

		$this->assertSame( $prepared, ( new PageRules() )->rest_pre_insert( $prepared, $this->request( null ) ) );
	}

	#[Test]
	public function a_valid_value_lets_the_post_be_written(): void {
		$prepared = new \stdClass();

		$this->assertSame( $prepared, ( new PageRules() )->rest_pre_insert( $prepared, $this->request( $this->setting() ) ) );
	}

	/** Before the post, not after: a page that saved while its rules were refused would tell its author the save failed. */
	#[Test]
	public function a_bad_value_refuses_the_whole_save_with_a_400_before_the_post_is_written(): void {
		$result = ( new PageRules() )->rest_pre_insert( new \stdClass(), $this->request( $this->setting( array( 'nonsense' ) ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( PageRules::ERROR_CODE, $result->get_error_code() );
		$this->assertSame( array( 'status' => 400 ), $result->get_error_data() );
		$this->assertNotSame( '', $result->get_error_message() );
	}

	#[Test]
	public function the_page_being_saved_is_never_sent_to_itself(): void {
		$result = ( new PageRules() )->rest_pre_insert( new \stdClass(), $this->request( $this->denied( 'redirect', self::POST ), self::POST ) );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	#[Test]
	public function an_error_already_on_the_save_is_passed_on_unchanged(): void {
		$error = new WP_Error( 'something_else', 'Another plugin refused it.' );

		$this->assertSame( $error, ( new PageRules() )->rest_pre_insert( $error, $this->request( $this->setting() ) ) );
	}
}
