<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Decision;
use Agend_Content_Access_Frontend;
use Agend_Content_Access_Policy;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use WP_REST_Request;
use WP_REST_Response;

require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-policy.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-catalogue.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-meta-box.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-decision.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-fragment-rules.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-frontend.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-conditions.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-providers.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-runtime.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-elementor.php';

/**
 * The REST edit context must not hand the raw body to people who cannot edit it.
 *
 * WordPress lets anybody holding `edit_posts` ask for `context=edit` on a
 * COLLECTION, and a contributor holds it. The edit context carries the raw
 * `post_content`, so `/wp/v2/posts?context=edit` returned the raw body of every
 * published post, restricted ones included, and the gate stood aside for it
 * because the context was `edit`. The context is only trusted for a post the
 * requester may edit.
 */
#[CoversClass( Agend_Content_Access_Frontend::class )]
final class RestAuthoringSourceTest extends TestCase {

	private const TIER_A = '11111111-1111-1111-1111-111111111111';

	private const MEMBERS_ONLY   = 61;
	private const PUBLIC_POST    = 62;
	private const SECTION_RULES  = 63;
	private const SECTION_PLAIN  = 64;
	private const TIER_A_ONLY    = 65;
	private const UNREADABLE     = 66;

	protected function setUp(): void {
		parent::setUp();

		$this->post( self::MEMBERS_ONLY, 'MEMBERS RAW BODY', array( 'mode' => 'active_member' ) );
		$this->post( self::PUBLIC_POST, 'PUBLIC RAW BODY', array( 'mode' => 'public' ) );
		$this->post( self::SECTION_RULES, 'PUBLIC PART AND SECTION SECRET', null, $this->elementorData( true ) );
		$this->post( self::SECTION_PLAIN, 'ELEMENTOR BODY WITH NO RULES', null, $this->elementorData( false ) );
		$this->post( self::TIER_A_ONLY, 'TIER A RAW BODY', array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A ) ) );
		$this->post( self::UNREADABLE, 'UNREADABLE RAW BODY', array( 'mode' => 'segments', 'segment_ids' => array( self::TIER_A ) ) );

		$this->asContributor();
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', '' );
	}

	// -----------------------------------------------------------------------
	// Fixtures
	// -----------------------------------------------------------------------

	private function post( int $id, string $body, ?array $policy, ?string $elementor = null ): void {
		$GLOBALS['agend_test_posts'][ $id ] = array(
			'ID'           => $id,
			'post_status'  => 'publish',
			'post_type'    => 'post',
			'post_title'   => 'Post ' . $id,
			'post_content' => $body,
		);

		if ( null !== $policy ) {
			$GLOBALS['agend_test_post_meta'][ $id ][ Agend_Content_Access_Policy::META_KEY ] = $policy;
		}

		if ( null !== $elementor ) {
			$GLOBALS['agend_test_post_meta'][ $id ]['_elementor_data'] = $elementor;
		}
	}

	/** An Elementor document, with or without a restricted section in it. */
	private function elementorData( bool $with_rule ): string {
		return (string) wp_json_encode(
			array(
				array(
					'id'       => 'section-1',
					'elType'   => 'section',
					'settings' => array(),
					'elements' => array(
						array(
							'id'       => 'column-1',
							'elType'   => 'column',
							'settings' => $with_rule ? array( 'agend_access_mode' => 'active_member' ) : array(),
							'elements' => array(),
						),
					),
				),
			)
		);
	}

	/** May edit posts in general (so may ask for `context=edit` on a collection), but none of these. */
	private function asContributor(): void {
		$GLOBALS['agend_test_current_user_can']['edit_posts'] = true;
		$GLOBALS['agend_test_current_user_can']['edit_post']  = false;
	}

	private function canEdit( int ...$ids ): void {
		foreach ( $ids as $id ) {
			$GLOBALS['agend_test_current_user_can_for']['edit_post'][ $id ] = true;
		}
	}

	private function asMember( string ...$tiers ): void {
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'member-token' );
		Agend_Test_WP::set_filter(
			'agend_apps_crm_get_my_entitlements_response',
			array( 'data' => array( 'tier_ids' => $tiers ) )
		);
	}

	/** The item as core builds it for the given context. */
	private function item( int $id, string $context ): WP_REST_Response {
		$post = get_post( $id );
		$edit = 'edit' === $context;

		// What the page shows, which is gated per section and so is not the raw body.
		$content = array(
			'rendered'  => '<p>RENDERED ' . $id . '</p>',
			'protected' => false,
		);
		$excerpt = array(
			'rendered'  => '<p>excerpt</p>',
			'protected' => false,
		);

		if ( $edit ) {
			$content['raw']           = $post->post_content;
			$content['block_version'] = 0;
			$excerpt['raw']           = '';
		}

		$data = array(
			'id'      => $id,
			'content' => $content,
			'excerpt' => $excerpt,
			'meta'    => array( Agend_Content_Access_Policy::META_KEY => array( 'mode' => 'active_member' ) ),
		);

		return new WP_REST_Response( $data );
	}

	/** Runs the REST filter as core does for one item of a request. */
	private function filtered( int $id, string $context = 'edit' ): array {
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$request->set_param( 'context', $context );

		$response = ( new Agend_Content_Access_Frontend() )->filter_rest_response(
			$this->item( $id, $context ),
			get_post( $id ),
			$request
		);

		return $response->get_data();
	}

	/** @return string The whole response as one string, for "nowhere in it" assertions. */
	private function everything( array $data ): string {
		return (string) wp_json_encode( $data );
	}

	// -----------------------------------------------------------------------
	// The leak
	// -----------------------------------------------------------------------

	#[Test]
	public function a_contributor_cannot_read_the_raw_body_of_a_restricted_post_in_the_edit_context(): void {
		$data = $this->filtered( self::MEMBERS_ONLY );

		$this->assertStringNotContainsString( 'MEMBERS RAW BODY', $this->everything( $data ) );
		$this->assertArrayNotHasKey( 'raw', $data['content'] );
		$this->assertArrayNotHasKey( 'raw', $data['excerpt'] );
		$this->assertArrayNotHasKey( 'block_version', $data['content'] );
		$this->assertSame( '', $data['content']['rendered'] );
		$this->assertTrue( $data['content']['protected'] );
		$this->assertSame( array( 'state' => Agend_Content_Access_Decision::STATE_AUTH ), $data['agend_access'] );
		$this->assertArrayNotHasKey( Agend_Content_Access_Policy::META_KEY, $data['meta'], 'the plan configuration is not exposed either' );
	}

	/** The request that was open: the whole collection, one filter pass per item. */
	#[Test]
	public function a_collection_in_the_edit_context_is_stripped_item_by_item(): void {
		$ids  = array( self::MEMBERS_ONLY, self::PUBLIC_POST, self::SECTION_RULES, self::SECTION_PLAIN, self::TIER_A_ONLY, self::UNREADABLE );
		$body = '';

		foreach ( $ids as $id ) {
			$body .= $this->everything( $this->filtered( $id ) );
		}

		foreach ( array( 'MEMBERS RAW BODY', 'TIER A RAW BODY', 'UNREADABLE RAW BODY', 'SECTION SECRET' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $body );
		}

		$this->assertStringContainsString( 'PUBLIC RAW BODY', $body, 'a plainly public post keeps what is public anyway' );
		$this->assertStringContainsString( 'ELEMENTOR BODY WITH NO RULES', $body );
		$this->assertStringContainsString( 'RENDERED ' . self::SECTION_RULES, $body, 'and a post with section rules still renders' );
	}

	/** The capability that exempts a request is the one for THAT post, not edit_posts in general. */
	#[Test]
	public function editing_one_post_does_not_open_another(): void {
		$this->canEdit( self::MEMBERS_ONLY );

		$mine   = $this->filtered( self::MEMBERS_ONLY );
		$theirs = $this->filtered( self::TIER_A_ONLY );

		$this->assertSame( 'MEMBERS RAW BODY', $mine['content']['raw'], 'the author keeps their own raw body' );
		$this->assertSame( 0, $mine['content']['block_version'] );
		$this->assertArrayNotHasKey( 'agend_access', $mine );
		$this->assertArrayNotHasKey( 'raw', $theirs['content'] );
		$this->assertSame( '', $theirs['content']['rendered'] );
	}

	#[Test]
	public function an_editor_who_may_edit_the_post_gets_everything_so_the_block_editor_still_loads(): void {
		$GLOBALS['agend_test_current_user_can']['edit_post'] = true;

		foreach ( array( self::MEMBERS_ONLY, self::SECTION_RULES, self::UNREADABLE ) as $id ) {
			$data = $this->filtered( $id );

			$this->assertSame( get_post( $id )->post_content, $data['content']['raw'] );
			$this->assertArrayHasKey( Agend_Content_Access_Policy::META_KEY, $data['meta'] );
		}
	}

	// -----------------------------------------------------------------------
	// A reader who may read the post, but not edit it
	// -----------------------------------------------------------------------

	#[Test]
	public function a_member_entitled_to_the_post_gets_the_rendered_body_and_not_the_raw_one(): void {
		$this->asMember( self::TIER_A );

		$data = $this->filtered( self::TIER_A_ONLY );

		$this->assertSame( '<p>RENDERED ' . self::TIER_A_ONLY . '</p>', $data['content']['rendered'], 'what they may read, they read' );
		$this->assertArrayNotHasKey( 'raw', $data['content'] );
		$this->assertArrayNotHasKey( 'block_version', $data['content'] );
		$this->assertArrayNotHasKey( 'raw', $data['excerpt'] );
		$this->assertFalse( $data['content']['protected'] );
		$this->assertArrayNotHasKey( 'agend_access', $data );
	}

	// -----------------------------------------------------------------------
	// A public page whose raw body holds a restricted section
	// -----------------------------------------------------------------------

	/**
	 * The page builder writes the text of every section into the raw body, so a
	 * public page with one members-only section has that section's text in
	 * `content.raw`.
	 */
	#[Test]
	public function a_public_post_carrying_section_rules_loses_its_raw_body_but_not_its_rendered_one(): void {
		$data = $this->filtered( self::SECTION_RULES );

		$this->assertArrayNotHasKey( 'raw', $data['content'] );
		$this->assertArrayNotHasKey( 'block_version', $data['content'] );
		$this->assertArrayNotHasKey( 'raw', $data['excerpt'] );
		$this->assertSame( '<p>RENDERED ' . self::SECTION_RULES . '</p>', $data['content']['rendered'], 'the render is already gated per section' );
		$this->assertFalse( $data['content']['protected'] );
		$this->assertArrayHasKey( Agend_Content_Access_Policy::META_KEY, $data['meta'], 'the document is public, so nothing else changes' );
	}

	#[Test]
	public function an_elementor_post_with_no_section_rules_keeps_its_raw_body(): void {
		$data = $this->filtered( self::SECTION_PLAIN );

		$this->assertSame( 'ELEMENTOR BODY WITH NO RULES', $data['content']['raw'] );
	}

	#[Test]
	public function the_editor_of_a_post_with_section_rules_keeps_its_raw_body(): void {
		$this->canEdit( self::SECTION_RULES );

		$this->assertSame( 'PUBLIC PART AND SECTION SECRET', $this->filtered( self::SECTION_RULES )['content']['raw'] );
	}

	/** An integration that builds documents another way answers through the filter. */
	#[Test]
	public function a_document_another_editor_marks_as_carrying_rules_loses_its_raw_body_too(): void {
		Agend_Test_WP::set_filter( 'agend_content_access_post_carries_fragment_rules', true );

		$this->assertArrayNotHasKey( 'raw', $this->filtered( self::PUBLIC_POST )['content'] );
	}

	// -----------------------------------------------------------------------
	// Unchanged
	// -----------------------------------------------------------------------

	#[Test]
	public function the_view_context_still_withholds_a_restricted_post(): void {
		$data = $this->filtered( self::MEMBERS_ONLY, 'view' );

		$this->assertSame( '', $data['content']['rendered'] );
		$this->assertTrue( $data['content']['protected'] );
		$this->assertStringNotContainsString( 'MEMBERS RAW BODY', $this->everything( $data ) );
	}

	#[Test]
	public function a_public_post_in_the_view_context_is_untouched(): void {
		$before = $this->item( self::PUBLIC_POST, 'view' )->get_data();

		$this->assertSame( $before, $this->filtered( self::PUBLIC_POST, 'view' ) );
	}

	#[Test]
	public function a_public_post_costs_no_identity_lookup_in_the_edit_context(): void {
		$lookups = 0;
		Agend_Test_WP::$filters['agend_apps_bearer_token'] = static function () use ( &$lookups ): string {
			++$lookups;

			return '';
		};

		$this->filtered( self::PUBLIC_POST );
		$this->filtered( self::SECTION_PLAIN );

		$this->assertSame( 0, $lookups );
	}

	// -----------------------------------------------------------------------
	// Every post type the plugin supports
	// -----------------------------------------------------------------------

	#[Test]
	public function the_filter_is_registered_for_every_supported_post_type(): void {
		$GLOBALS['agend_test_post_types'] = array(
			'post'       => 'post',
			'page'       => 'page',
			'event'      => 'event',
			'attachment' => 'attachment',
		);

		( new Agend_Content_Access_Frontend() )->register_rest_filters();

		unset( $GLOBALS['agend_test_post_types'] );

		foreach ( array( 'post', 'page', 'event' ) as $type ) {
			$this->assertArrayHasKey( 'rest_prepare_' . $type, Agend_Test_WP::$filter_registry );
		}

		$this->assertArrayNotHasKey( 'rest_prepare_attachment', Agend_Test_WP::$filter_registry );
	}
}
