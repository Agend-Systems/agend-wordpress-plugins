<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Decision;
use Agend_Content_Access_Elementor as ElementorControl;
use Agend_Content_Access_Exporter;
use Agend_Content_Access_Frontend;
use Agend_Content_Access_Meta_Box;
use Agend_Content_Access_Policy;
use Agend_Test_WP;
use Agend_Test_WP_Die_Exception;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-policy.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-catalogue.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-meta-box.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-decision.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-frontend.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-conditions.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-providers.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-runtime.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-elementor.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-elementor-parser.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-exporter.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/rest/connector-routes.php';

// The panel view is required relative to this constant, which the plugin's main
// file defines and the unit suite does not load.
if ( ! defined( 'AGEND_CONTENT_ACCESS_DIR' ) ) {
	define( 'AGEND_CONTENT_ACCESS_DIR', AGEND_TESTS_ROOT . '/agend-content-access/' );
}

/**
 * A stored policy this version cannot read must restrict, everywhere.
 *
 * 0.1.0 read such a policy as "none set" and served the page, its REST body, its
 * Elementor sections and its fallback to anyone. The shapes that trigger it are
 * not exotic: the gateway has an `authenticated` mode this plugin does not know,
 * and 0.2.0 stores `segments` and `selected_entitlements`, so rolling a site back
 * from 0.2.0 would have published every page using them. These tests walk each
 * door a visitor, or the connector, can come through.
 */
#[CoversClass( Agend_Content_Access_Frontend::class )]
#[CoversClass( Agend_Content_Access_Exporter::class )]
#[CoversClass( Agend_Content_Access_Meta_Box::class )]
#[CoversClass( ElementorControl::class )]
final class UnreadablePolicyEnforcementTest extends TestCase {

	private const POST_ID = 31;
	private const TIER_A  = '11111111-1111-1111-1111-111111111111';

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['agend_test_posts']           = array();
		$GLOBALS['agend_test_post_meta']       = array();
		$GLOBALS['agend_test_is_singular']     = false;
		$GLOBALS['agend_test_queried_object']  = null;
		$_POST                                 = array();

		Agend_Test_WP::$queried_object_id = self::POST_ID;

		$this->registerPost();
		$this->asVisitor();
	}

	protected function tearDown(): void {
		$_POST = array();
		unset( $GLOBALS['agend_test_is_singular'], $GLOBALS['agend_test_queried_object'] );

		parent::tearDown();
	}

	/**
	 * Stored values this version cannot read.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function unreadablePolicies(): array {
		return array(
			'authenticated, a gateway mode this plugin lacks' => array( array( 'mode' => 'authenticated' ) ),
			'segments'                                        => array(
				array( 'mode' => 'segments', 'segment_ids' => array( self::TIER_A ) ),
			),
			'selected entitlements'                           => array(
				array( 'mode' => 'selected_entitlements', 'entitlement_ids' => array( self::TIER_A ) ),
			),
			'a plan id that is not a uuid'                    => array(
				array( 'mode' => 'selected_tiers', 'tier_ids' => array( 'not-a-uuid' ) ),
			),
			'an extra top level key'                          => array(
				array( 'mode' => 'active_member', 'note' => 'smuggled' ),
			),
		);
	}

	private function registerPost( array $fields = array() ): void {
		$GLOBALS['agend_test_posts'][ self::POST_ID ] = array_merge(
			array(
				'ID'                => self::POST_ID,
				'post_status'       => 'publish',
				'post_type'         => 'page',
				'post_title'        => 'Members update',
				'post_content'      => 'THE PROTECTED BODY',
				'post_modified_gmt' => '2026-10-01 00:00:00',
			),
			$fields
		);
	}

	private function storePolicy( $value ): void {
		$GLOBALS['agend_test_post_meta'][ self::POST_ID ] = array(
			Agend_Content_Access_Policy::META_KEY => $value,
		);
	}

	/** The visitor cannot edit the post, which is what makes them a visitor. */
	private function asVisitor(): void {
		$GLOBALS['agend_test_current_user_can']['edit_post'] = false;
	}

	private function asMember( string ...$tiers ): void {
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'member-token' );
		Agend_Test_WP::set_filter(
			'agend_apps_crm_get_my_entitlements_response',
			array( 'data' => array( 'tier_ids' => $tiers ) )
		);
	}

	// -----------------------------------------------------------------------
	// Direct request: the page gate
	// -----------------------------------------------------------------------

	/**
	 * Names of the methods registered on a hook, in registration order.
	 *
	 * Asked by method rather than by hook name because the constructor now
	 * registers the render filters on `the_content` too, so that hook being
	 * occupied no longer says whether the singular gate replaced the body.
	 *
	 * @return string[]
	 */
	private function registeredMethods( string $hook ): array {
		return array_map(
			static fn( array $entry ): string => is_array( $entry['callback'] ) ? (string) $entry['callback'][1] : '(closure)',
			Agend_Test_WP::$filter_registry[ $hook ] ?? array()
		);
	}

	private function requestThePage(): Agend_Content_Access_Frontend {
		$GLOBALS['agend_test_is_singular']    = true;
		$GLOBALS['agend_test_queried_object'] = get_post( self::POST_ID );

		return new Agend_Content_Access_Frontend();
	}

	#[Test]
	#[DataProvider( 'unreadablePolicies' )]
	public function the_page_gate_refuses_an_anonymous_visitor_when_the_stored_policy_is_unreadable( $value ): void {
		$this->storePolicy( $value );

		$this->expectException( Agend_Test_WP_Die_Exception::class );

		$this->requestThePage()->gate_request();
	}

	/** With a hand-written excerpt the gate shows a teaser instead of a bare 403, and still withholds the body. */
	#[Test]
	public function the_teaser_path_replaces_the_body_when_the_stored_policy_is_unreadable(): void {
		$this->storePolicy( array( 'mode' => 'segments', 'segment_ids' => array( self::TIER_A ) ) );
		$this->registerPost( array( 'post_excerpt' => 'A public summary.' ) );

		$this->requestThePage()->gate_request();

		$this->assertContains( 'filter_the_content', $this->registeredMethods( 'the_content' ), 'the body must be replaced by the gate' );
		$this->assertArrayHasKey( 'get_post_metadata', Agend_Test_WP::$filters, 'builder data must be blanked' );
	}

	#[Test]
	public function the_page_gate_lets_a_post_with_no_stored_policy_through(): void {
		$this->requestThePage()->gate_request();

		$this->assertNotContains( 'filter_the_content', $this->registeredMethods( 'the_content' ) );
	}

	#[Test]
	public function the_page_gate_lets_an_explicitly_public_post_through(): void {
		$this->storePolicy( array( 'mode' => 'public' ) );

		$this->requestThePage()->gate_request();

		$this->assertNotContains( 'filter_the_content', $this->registeredMethods( 'the_content' ) );
	}

	// -----------------------------------------------------------------------
	// REST representation
	// -----------------------------------------------------------------------

	private function restResponse(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'id'      => self::POST_ID,
				'content' => array( 'rendered' => '<p>THE PROTECTED BODY</p>', 'raw' => 'THE PROTECTED BODY' ),
				'excerpt' => array( 'rendered' => '<p>THE PROTECTED BODY</p>' ),
			)
		);
	}

	#[Test]
	#[DataProvider( 'unreadablePolicies' )]
	public function the_rest_filter_strips_the_body_when_the_stored_policy_is_unreadable( $value ): void {
		$this->storePolicy( $value );

		$filtered = ( new Agend_Content_Access_Frontend() )->filter_rest_response(
			$this->restResponse(),
			get_post( self::POST_ID ),
			new WP_REST_Request( 'GET', '/wp/v2/pages/' . self::POST_ID )
		)->get_data();

		$this->assertSame( '', $filtered['content']['rendered'] );
		$this->assertSame( '', $filtered['excerpt']['rendered'] );
		$this->assertTrue( $filtered['content']['protected'] );
		$this->assertArrayNotHasKey( 'raw', $filtered['content'] );
		$this->assertSame( 'authentication_required', $filtered['agend_access']['state'] );
		$this->assertStringNotContainsString( 'THE PROTECTED BODY', (string) wp_json_encode( $filtered ) );
	}

	#[Test]
	public function the_rest_filter_leaves_a_post_with_no_stored_policy_alone(): void {
		$filtered = ( new Agend_Content_Access_Frontend() )->filter_rest_response(
			$this->restResponse(),
			get_post( self::POST_ID ),
			new WP_REST_Request( 'GET', '/wp/v2/pages/' . self::POST_ID )
		)->get_data();

		$this->assertSame( '<p>THE PROTECTED BODY</p>', $filtered['content']['rendered'] );
		$this->assertArrayNotHasKey( 'agend_access', $filtered );
	}

	// -----------------------------------------------------------------------
	// Elementor sections
	// -----------------------------------------------------------------------

	/**
	 * @param array<string, mixed> $settings Element settings.
	 */
	private function element( array $settings ): object {
		return new class( $settings ) {
			/** @var array<string, mixed> */
			private array $settings;

			public function __construct( array $settings ) {
				$this->settings = $settings;
			}

			public function get_settings_for_display(): array {
				return $this->settings;
			}
		};
	}

	/**
	 * The member is the case that distinguishes the fix. With the document policy
	 * read as null, a members-only SECTION on an unreadable PAGE policy was shown
	 * to any member, because the fragment applied alone.
	 */
	#[Test]
	#[DataProvider( 'unreadablePolicies' )]
	public function a_restricted_section_is_suppressed_when_the_document_policy_is_unreadable( $value ): void {
		$this->storePolicy( $value );
		$element = $this->element( array( ElementorControl::MODE_KEY => 'active_member' ) );

		$this->assertFalse( ( new ElementorControl() )->maybe_suppress( true, $element ), 'anonymous visitor' );

		Agend_Content_Access_Decision::reset_cache();
		$this->asMember( self::TIER_A );

		$this->assertFalse( ( new ElementorControl() )->maybe_suppress( true, $element ), 'member holding a plan' );
	}

	#[Test]
	public function a_plan_section_is_suppressed_for_a_matching_member_when_the_document_policy_is_unreadable(): void {
		$this->storePolicy( array( 'mode' => 'segments', 'segment_ids' => array( self::TIER_A ) ) );
		$this->asMember( self::TIER_A );

		$element = $this->element(
			array(
				ElementorControl::MODE_KEY  => 'selected_tiers',
				ElementorControl::TIERS_KEY => array( self::TIER_A ),
			)
		);

		$this->assertFalse( ( new ElementorControl() )->maybe_suppress( true, $element ) );
	}

	/** The control: the same section on a page with no policy still shows to a member. */
	#[Test]
	public function a_restricted_section_still_shows_to_a_member_when_no_document_policy_is_stored(): void {
		$this->asMember( self::TIER_A );
		$element = $this->element( array( ElementorControl::MODE_KEY => 'active_member' ) );

		$this->assertTrue( ( new ElementorControl() )->maybe_suppress( true, $element ) );
	}

	// -----------------------------------------------------------------------
	// Connector export
	// -----------------------------------------------------------------------

	#[Test]
	#[DataProvider( 'unreadablePolicies' )]
	public function the_exporter_refuses_a_post_whose_stored_policy_is_unreadable( $value ): void {
		$this->storePolicy( $value );

		try {
			Agend_Content_Access_Exporter::record( get_post( self::POST_ID ) );
			$this->fail( 'An unreadable policy must not be exported, as null would mean public.' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( (string) self::POST_ID, $e->getMessage() );
			$this->assertStringContainsString( 'unreadable', $e->getMessage() );
		}
	}

	#[Test]
	public function the_connector_route_answers_422_for_a_post_whose_stored_policy_is_unreadable(): void {
		$this->storePolicy( array( 'mode' => 'segments', 'segment_ids' => array( self::TIER_A ) ) );

		$request = new WP_REST_Request( 'GET', '/agend-content-access/v1/connector/documents/' . self::POST_ID );
		$request->set_param( 'id', self::POST_ID );

		$result = agend_content_access_connector_get( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'agend_content_access_unparseable', $result->get_error_code() );
		$this->assertSame( 422, $result->get_error_data()['status'] );
		$this->assertStringContainsString( (string) self::POST_ID, $result->get_error_message() );
	}

	#[Test]
	public function a_post_with_no_stored_policy_still_exports_with_a_null_policy(): void {
		$record = Agend_Content_Access_Exporter::record( get_post( self::POST_ID ) );

		$this->assertNull( $record['access_policy'] );
		$this->assertSame( (string) self::POST_ID, $record['source']['id'] );
	}

	#[Test]
	public function a_readable_policy_still_exports_as_stored(): void {
		$policy = array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A ) );
		$this->storePolicy( $policy );

		$request = new WP_REST_Request( 'GET', '/agend-content-access/v1/connector/documents/' . self::POST_ID );
		$request->set_param( 'id', self::POST_ID );

		$result = agend_content_access_connector_get( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $result );
		$this->assertSame( $policy, $result->get_data()['access_policy'] );
	}

	/**
	 * The listing hashes the policy to build a revision. It must not throw, or one
	 * bad policy would stop every other document syncing, but an unreadable policy
	 * must not hash like an absent one either, or Agend would never re-fetch the
	 * document and never see the 422 that reports it.
	 */
	#[Test]
	public function the_listing_revision_changes_when_the_policy_becomes_unreadable_without_throwing(): void {
		$post   = get_post( self::POST_ID );
		$absent = Agend_Content_Access_Exporter::revision_of( $post );

		$this->storePolicy( array( 'mode' => 'authenticated' ) );
		$unreadable = Agend_Content_Access_Exporter::revision_of( $post );

		$this->assertNotSame( $absent, $unreadable );
	}

	/** Revisions Agend already holds must not churn for the overwhelmingly common case. */
	#[Test]
	public function the_revision_of_a_post_with_no_policy_is_unchanged(): void {
		$this->assertSame(
			hash( 'sha256', implode( '|', array( '2026-10-01 00:00:00', 'publish', 'null', '', 'THE PROTECTED BODY' ) ) ),
			Agend_Content_Access_Exporter::revision_of( get_post( self::POST_ID ) )
		);
	}

	// -----------------------------------------------------------------------
	// Editor panel
	// -----------------------------------------------------------------------

	private function renderPanel(): string {
		Agend_Test_WP::$tiers_response = array(
			'data' => array(
				array( 'id' => self::TIER_A, 'name' => 'Full Member', 'slug' => 'full', 'tier_type' => 'individual', 'is_active' => true ),
			),
		);

		ob_start();
		( new Agend_Content_Access_Meta_Box() )->render( get_post( self::POST_ID ) );

		return (string) ob_get_clean();
	}

	#[Test]
	#[DataProvider( 'unreadablePolicies' )]
	public function the_panel_warns_that_an_unreadable_policy_hides_the_page( $value ): void {
		$this->storePolicy( $value );

		$html = $this->renderPanel();

		$this->assertStringContainsString( 'agend-access-unreadable', $html );
		$this->assertStringContainsString( 'could not be read', $html );
		$this->assertStringContainsString( 'hidden from all visitors', $html );
	}

	/** The repair flow is unchanged: no audience is pre-selected and all three are on offer. */
	#[Test]
	public function the_panel_keeps_the_choose_an_audience_flow_for_an_unreadable_policy(): void {
		$this->storePolicy( array( 'mode' => 'segments', 'segment_ids' => array( self::TIER_A ) ) );

		$html = $this->renderPanel();

		$this->assertSame( 3, substr_count( $html, 'type="radio"' ) );
		$this->assertStringNotContainsString( "checked='checked'", $html );
	}

	#[Test]
	public function rendering_the_panel_does_not_rewrite_an_unreadable_policy(): void {
		$value = array( 'mode' => 'segments', 'segment_ids' => array( self::TIER_A ) );
		$this->storePolicy( $value );

		$this->renderPanel();

		$this->assertSame( $value, $GLOBALS['agend_test_post_meta'][ self::POST_ID ][ Agend_Content_Access_Policy::META_KEY ] );
	}

	#[Test]
	public function the_panel_shows_no_warning_when_nothing_is_stored_or_the_policy_is_readable(): void {
		$this->assertStringNotContainsString( 'agend-access-unreadable', $this->renderPanel() );

		$this->storePolicy( array( 'mode' => 'active_member' ) );

		$this->assertStringNotContainsString( 'agend-access-unreadable', $this->renderPanel() );
	}

	#[Test]
	public function the_payload_flags_an_unreadable_policy_separately_from_an_unset_one(): void {
		$catalogue = array( 'plans' => array(), 'stale' => false );

		$this->assertFalse( Agend_Content_Access_Meta_Box::payload( null, $catalogue )['unreadable'] );
		$this->assertTrue( Agend_Content_Access_Meta_Box::payload( null, $catalogue, array(), true )['unreadable'] );
	}

	// -----------------------------------------------------------------------
	// Saving the page
	// -----------------------------------------------------------------------

	private function submit( array $fields ): void {
		// An editor is the one who saves, and the save path checks the capability
		// before it reads anything else.
		$GLOBALS['agend_test_current_user_can']['edit_post'] = true;

		$_POST = array_merge(
			array( Agend_Content_Access_Meta_Box::NONCE_FIELD => wp_create_nonce( Agend_Content_Access_Meta_Box::NONCE_ACTION ) ),
			$fields
		);

		( new Agend_Content_Access_Meta_Box() )->save( self::POST_ID, get_post( self::POST_ID ) );
	}

	/**
	 * The panel shows no audience selected for an unreadable policy, so a routine
	 * save of the page (a typo fix) submits an empty mode. Clearing the stored
	 * value then would turn a page that is hidden from everyone into a public one
	 * without anybody choosing that.
	 */
	#[Test]
	public function saving_the_page_without_choosing_an_audience_keeps_an_unreadable_policy(): void {
		$value = array( 'mode' => 'segments', 'segment_ids' => array( self::TIER_A ) );
		$this->storePolicy( $value );

		$this->submit( array() );

		$this->assertSame( Agend_Content_Access_Policy::STORED_INVALID, Agend_Content_Access_Policy::stored_state( self::POST_ID ) );
		$this->assertSame( $value, $GLOBALS['agend_test_post_meta'][ self::POST_ID ][ Agend_Content_Access_Policy::META_KEY ] );
	}

	#[Test]
	public function choosing_an_audience_replaces_an_unreadable_policy(): void {
		$this->storePolicy( array( 'mode' => 'segments', 'segment_ids' => array( self::TIER_A ) ) );

		$this->submit( array( Agend_Content_Access_Meta_Box::FIELD_MODE => 'active_member' ) );

		$this->assertSame( Agend_Content_Access_Policy::STORED_VALID, Agend_Content_Access_Policy::stored_state( self::POST_ID ) );
		$this->assertSame( array( 'mode' => 'active_member' ), Agend_Content_Access_Policy::enforced_for_post( self::POST_ID ) );
	}

	#[Test]
	public function saving_with_no_audience_on_a_post_with_nothing_stored_stays_unset(): void {
		$this->submit( array() );

		$this->assertSame( Agend_Content_Access_Policy::STORED_ABSENT, Agend_Content_Access_Policy::stored_state( self::POST_ID ) );
	}

	/**
	 * With the plan catalogue unreachable the plans radio is rendered disabled,
	 * and a browser does not submit a disabled control, so saving the page sends
	 * no mode. That used to delete a stored plans policy and publish the page.
	 */
	#[Test]
	public function saving_with_the_catalogue_unavailable_keeps_a_stored_plans_policy(): void {
		$policy = array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A ) );
		$this->storePolicy( $policy );

		Agend_Test_WP::$tiers_response = new WP_Error( 'http_request_failed', 'down' );

		ob_start();
		( new Agend_Content_Access_Meta_Box() )->render( get_post( self::POST_ID ) );
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression(
			"/value=\"selected_tiers\"\\s+checked='checked'\\s+disabled='disabled'/",
			$html,
			'the stored mode is checked but disabled, so it is not submitted'
		);

		$this->submit( array() );

		$this->assertSame( $policy, $GLOBALS['agend_test_post_meta'][ self::POST_ID ][ Agend_Content_Access_Policy::META_KEY ] );
		$this->assertSame( $policy, Agend_Content_Access_Policy::enforced_for_post( self::POST_ID ) );
	}

	#[Test]
	public function saving_with_no_mode_keeps_any_stored_policy(): void {
		foreach ( array( array( 'mode' => 'active_member' ), array( 'mode' => 'public' ) ) as $policy ) {
			$this->storePolicy( $policy );

			$this->submit( array() );

			$this->assertSame( $policy, Agend_Content_Access_Policy::enforced_for_post( self::POST_ID ) );
		}
	}

	#[Test]
	public function saving_an_unrecognised_mode_keeps_the_stored_policy(): void {
		$policy = array( 'mode' => 'active_member' );
		$this->storePolicy( $policy );

		$this->submit( array( Agend_Content_Access_Meta_Box::FIELD_MODE => 'segments' ) );

		$this->assertSame( $policy, Agend_Content_Access_Policy::enforced_for_post( self::POST_ID ) );
	}

	/** Removing a restriction stays possible, as an explicit choice. */
	#[Test]
	public function explicitly_choosing_public_still_clears_a_restriction(): void {
		$this->storePolicy( array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A ) ) );

		$this->submit( array( Agend_Content_Access_Meta_Box::FIELD_MODE => 'public' ) );

		$this->assertSame( array( 'mode' => 'public' ), Agend_Content_Access_Policy::enforced_for_post( self::POST_ID ) );
	}

	/** The existing refusal is untouched: plans with nothing selected keeps the old policy. */
	#[Test]
	public function selected_plans_with_nothing_selected_is_still_refused(): void {
		$policy = array( 'mode' => 'active_member' );
		$this->storePolicy( $policy );

		$this->submit( array( Agend_Content_Access_Meta_Box::FIELD_MODE => 'selected_tiers' ) );

		$this->assertSame( $policy, Agend_Content_Access_Policy::enforced_for_post( self::POST_ID ) );
	}
}
