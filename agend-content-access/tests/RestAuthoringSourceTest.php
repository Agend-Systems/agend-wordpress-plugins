<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Blocks;
use Agend_Content_Access_Decision;
use Agend_Content_Access_Frontend;
use Agend_Content_Access_Policy;
use Agend_Test_WP;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use WP_REST_Request;
use WP_REST_Response;

require_once __DIR__ . '/BlockEnforcementTestCase.php';

/**
 * The REST edit context must not hand the raw body to people who cannot edit it.
 *
 * WordPress lets anybody holding `edit_posts` ask for `context=edit` on a
 * COLLECTION, and a contributor holds it. The edit context carries the raw
 * `post_content`, so `/wp/v2/posts?context=edit` returned the raw body of every
 * published post, restricted ones included, and the gate stood aside for it
 * because the context was `edit`. The context is only trusted for a post the
 * requester may edit.
 *
 * A public post that carries a rule on one of its blocks or sections is covered
 * too: the raw body of a block document holds the text of every block, ruled ones
 * included, and an Elementor document's holds the text of every section.
 */
#[CoversClass( Agend_Content_Access_Frontend::class )]
#[CoversClass( Agend_Content_Access_Blocks::class )]
final class RestAuthoringSourceTest extends BlockEnforcementTestCase {

	private const MEMBERS_ONLY  = 61;
	private const PUBLIC_POST   = 62;
	private const SECTION_RULES = 63;
	private const SECTION_PLAIN = 64;
	private const TIER_A_ONLY   = 65;
	private const UNREADABLE    = 66;
	private const BLOCK_RULES   = 67;
	private const BLOCK_PLAIN   = 68;
	private const BLOCK_PATTERN = 69;
	private const BLOCK_COND    = 70;
	private const PATTERN       = 920;

	private const NOTE_PUBLIC = 'aaaaaaaa-0000-4000-8000-00000000000a';
	private const NOTE_SECRET = 'bbbbbbbb-0000-4000-8000-00000000000b';

	protected function setUp(): void {
		parent::setUp();

		$this->post( self::MEMBERS_ONLY, 'MEMBERS RAW BODY', array( 'mode' => 'active_member' ) );
		$this->post( self::PUBLIC_POST, 'PUBLIC RAW BODY', array( 'mode' => 'public' ) );
		$this->post( self::SECTION_RULES, 'PUBLIC PART AND SECTION SECRET', null, $this->elementorData( true ) );
		$this->post( self::SECTION_PLAIN, 'ELEMENTOR BODY WITH NO RULES', null, $this->elementorData( false ) );
		$this->post( self::TIER_A_ONLY, 'TIER A RAW BODY', array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A ) ) );
		$this->post( self::UNREADABLE, 'UNREADABLE RAW BODY', array( 'mode' => 'from_a_later_release' ) );

		$this->post(
			self::BLOCK_RULES,
			$this->paragraph( 'BLOCK PUBLIC PART' )
			. $this->paragraph( 'BLOCK SECRET' . $this->anchor( self::NOTE_SECRET ), array( 'mode' => 'active_member' ) )
			. $this->paragraph( 'BLOCK CITED' . $this->anchor( self::NOTE_PUBLIC ) ),
			null
		);
		$GLOBALS['agend_test_post_meta'][ self::BLOCK_RULES ]['footnotes'] = (string) wp_json_encode(
			array(
				array( 'id' => self::NOTE_PUBLIC, 'content' => 'PUBLIC NOTE' ),
				array( 'id' => self::NOTE_SECRET, 'content' => 'SECRET NOTE' ),
			)
		);

		$this->post( self::BLOCK_PLAIN, $this->paragraph( 'BLOCK BODY WITH NO RULES' ) . $this->paragraph( 'INHERITS', array( 'mode' => 'inherit' ) ), null );
		$this->post( self::BLOCK_COND, $this->paragraph( 'BLOCK CONDITIONED', array( 'conditionsEnabled' => true ) ), null );

		$this->registerPattern( self::PATTERN, $this->paragraph( 'PATTERN SECRET', array( 'mode' => 'active_member' ) ) );
		$this->post( self::BLOCK_PATTERN, $this->paragraph( 'BEFORE' ) . $this->reference( self::PATTERN ), null );

		$this->asContributor();
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', '' );
	}

	// -----------------------------------------------------------------------
	// Fixtures
	// -----------------------------------------------------------------------

	private function post( int $id, string $body, ?array $policy, ?string $elementor = null ): void {
		$this->registerPost( $id, $body, $policy );

		if ( null !== $elementor ) {
			$GLOBALS['agend_test_post_meta'][ $id ]['_elementor_data'] = $elementor;
		}
	}

	/** The anchor core writes into a paragraph for a footnote. */
	private function anchor( string $id ): string {
		return '<sup data-fn="' . $id . '" class="fn"><a href="#' . $id . '" id="' . $id . '-link">1</a></sup>';
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

	private function canEdit( int ...$ids ): void {
		foreach ( $ids as $id ) {
			$GLOBALS['agend_test_current_user_can_for']['edit_post'][ $id ] = true;
		}
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
		$ids  = array(
			self::MEMBERS_ONLY,
			self::PUBLIC_POST,
			self::SECTION_RULES,
			self::SECTION_PLAIN,
			self::TIER_A_ONLY,
			self::UNREADABLE,
			self::BLOCK_RULES,
			self::BLOCK_PLAIN,
			self::BLOCK_PATTERN,
			self::BLOCK_COND,
		);
		$body = '';

		foreach ( $ids as $id ) {
			$body .= $this->everything( $this->filtered( $id ) );
		}

		foreach ( array( 'MEMBERS RAW BODY', 'TIER A RAW BODY', 'UNREADABLE RAW BODY', 'SECTION SECRET', 'BLOCK SECRET', 'BLOCK CONDITIONED', 'PATTERN SECRET' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $body );
		}

		$this->assertStringContainsString( 'PUBLIC RAW BODY', $body, 'a plainly public post keeps what is public anyway' );
		$this->assertStringContainsString( 'ELEMENTOR BODY WITH NO RULES', $body );
		$this->assertStringContainsString( 'BLOCK BODY WITH NO RULES', $body );
		$this->assertStringContainsString( 'RENDERED ' . self::SECTION_RULES, $body, 'and a post with section rules still renders' );
		$this->assertStringContainsString( 'RENDERED ' . self::BLOCK_RULES, $body );
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
		$this->asEditor();

		foreach ( array( self::MEMBERS_ONLY, self::SECTION_RULES, self::UNREADABLE, self::BLOCK_RULES, self::BLOCK_PATTERN ) as $id ) {
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

	// -----------------------------------------------------------------------
	// The same for a block document
	// -----------------------------------------------------------------------

	/**
	 * A block's rule is in its comment and its text is in the raw body with
	 * everybody else's, so the raw body of a public block document with a ruled
	 * block is a copy of what the block was hiding.
	 */
	#[Test]
	public function a_public_post_carrying_a_block_rule_loses_its_raw_body_but_not_its_rendered_one(): void {
		$data = $this->filtered( self::BLOCK_RULES );

		$this->assertArrayNotHasKey( 'raw', $data['content'] );
		$this->assertArrayNotHasKey( 'block_version', $data['content'] );
		$this->assertArrayNotHasKey( 'raw', $data['excerpt'] );
		$this->assertSame( '<p>RENDERED ' . self::BLOCK_RULES . '</p>', $data['content']['rendered'] );
		$this->assertFalse( $data['content']['protected'] );
		$this->assertStringNotContainsString( 'BLOCK SECRET', $this->everything( $data ) );
	}

	#[Test]
	public function display_conditions_on_a_block_count_as_rules_too(): void {
		$this->assertArrayNotHasKey( 'raw', $this->filtered( self::BLOCK_COND )['content'] );
	}

	#[Test]
	public function a_rule_inside_a_synced_pattern_counts_for_the_page_that_uses_it(): void {
		$this->assertArrayNotHasKey( 'raw', $this->filtered( self::BLOCK_PATTERN )['content'] );
	}

	#[Test]
	public function a_block_post_with_no_rules_keeps_its_raw_body(): void {
		$data = $this->filtered( self::BLOCK_PLAIN );

		$this->assertSame( get_post( self::BLOCK_PLAIN )->post_content, $data['content']['raw'] );
	}

	#[Test]
	public function the_editor_of_a_block_post_with_rules_keeps_its_raw_body(): void {
		$this->canEdit( self::BLOCK_RULES );

		$this->assertSame( get_post( self::BLOCK_RULES )->post_content, $this->filtered( self::BLOCK_RULES )['content']['raw'] );
	}

	/** The saved flag answers too, for a document the live scan cannot see into. */
	#[Test]
	public function a_flagged_post_loses_its_raw_body_whatever_the_scan_says(): void {
		$GLOBALS['agend_test_post_meta'][ self::BLOCK_PLAIN ][ Agend_Content_Access_Frontend::FRAGMENT_RULES_META_KEY ] = '1';

		$this->assertArrayNotHasKey( 'raw', $this->filtered( self::BLOCK_PLAIN )['content'] );
	}

	/** A block document in a restricted post: stripped as every restricted post is. */
	#[Test]
	public function a_restricted_block_post_is_withheld_as_before(): void {
		$this->registerPost( 71, $this->paragraph( 'RESTRICTED BLOCK BODY' ), array( 'mode' => 'active_member' ) );

		$data = $this->filtered( 71 );

		$this->assertStringNotContainsString( 'RESTRICTED BLOCK BODY', $this->everything( $data ) );
		$this->assertSame( '', $data['content']['rendered'] );
	}

	// -----------------------------------------------------------------------
	// Footnotes, which the block driver filters as the response is built
	// -----------------------------------------------------------------------

	/** The `meta.footnotes` core would put in the item for the requester, read as REST reads it. */
	private function itemWithMeta( int $id, string $context ): WP_REST_Response {
		$response = $this->item( $id, $context );
		$data     = $response->get_data();

		$data['meta']['footnotes'] = get_post_meta( $id, 'footnotes', true );

		$response->set_data( $data );

		return $response;
	}

	private function filteredWithFootnotes( int $id ): array {
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$request->set_param( 'context', 'edit' );

		return ( new Agend_Content_Access_Frontend() )->filter_rest_response(
			$this->itemWithMeta( $id, 'edit' ),
			get_post( $id ),
			$request
		)->get_data();
	}

	#[Test]
	public function a_contributor_gets_neither_the_raw_body_nor_the_note_of_a_block_they_may_not_read(): void {
		$GLOBALS['agend_test_rest_request'] = true;
		$GLOBALS['wp']                      = (object) array( 'query_vars' => array( 'rest_route' => '/wp/v2/posts' ) );
		$_GET['context']                    = 'edit';
		$this->inTheLoopOf( self::BLOCK_RULES );

		$data = $this->filteredWithFootnotes( self::BLOCK_RULES );

		$this->assertArrayNotHasKey( 'raw', $data['content'] );
		$this->assertStringContainsString( 'PUBLIC NOTE', (string) $data['meta']['footnotes'], 'the note of a block they may read stays' );
		$this->assertStringNotContainsString( 'SECRET NOTE', $this->everything( $data ) );
		$this->assertStringNotContainsString( 'BLOCK SECRET', $this->everything( $data ) );
	}

	#[Test]
	public function the_editor_of_the_post_gets_the_raw_body_and_every_note(): void {
		$GLOBALS['agend_test_rest_request'] = true;
		$GLOBALS['wp']                      = (object) array( 'query_vars' => array( 'rest_route' => '/wp/v2/posts/' . self::BLOCK_RULES ) );
		$_GET['context']                    = 'edit';
		$this->canEdit( self::BLOCK_RULES );
		$this->inTheLoopOf( self::BLOCK_RULES );

		$data = $this->filteredWithFootnotes( self::BLOCK_RULES );

		$this->assertSame( get_post( self::BLOCK_RULES )->post_content, $data['content']['raw'] );
		$this->assertStringContainsString( 'SECRET NOTE', (string) $data['meta']['footnotes'] );
	}

	#[Test]
	public function a_denied_document_loses_its_footnotes_with_its_raw_body(): void {
		$this->registerPost( 72, $this->paragraph( 'DENIED' ), array( 'mode' => 'active_member' ) );
		$GLOBALS['agend_test_post_meta'][ 72 ]['footnotes'] = '[{"id":"x","content":"DENIED NOTE"}]';

		$data = $this->filteredWithFootnotes( 72 );

		$this->assertArrayNotHasKey( 'footnotes', $data['meta'] );
		$this->assertStringNotContainsString( 'DENIED NOTE', $this->everything( $data ) );
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
		$this->assertSame( $this->item( self::BLOCK_RULES, 'view' )->get_data(), $this->filtered( self::BLOCK_RULES, 'view' ), 'no raw source in the response, so nothing to strip' );
	}

	#[Test]
	public function a_public_post_costs_no_identity_lookup_in_the_edit_context(): void {
		$this->filtered( self::PUBLIC_POST );
		$this->filtered( self::SECTION_PLAIN );
		$this->filtered( self::BLOCK_PLAIN );
		$this->filtered( self::BLOCK_RULES );

		$this->assertSame( 0, $this->identity_lookups );
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
