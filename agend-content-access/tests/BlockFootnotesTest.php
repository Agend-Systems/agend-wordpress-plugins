<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Blocks;
use Agend_Content_Access_Frontend;
use Agend_Test_WP;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use WP_REST_Request;
use WP_REST_Response;

require_once __DIR__ . '/BlockEnforcementTestCase.php';

/**
 * A restricted block's footnotes must not be readable when the block is not.
 *
 * The paragraph that cites a footnote holds only an anchor. The text lives in the
 * post's `footnotes` meta, which the Footnotes block prints in full and which is
 * public in REST as `meta.footnotes`. Both read it with a meta read, so one
 * filter on that read is what is tested here, through both of their shapes: a
 * single read, which is the block's, and a list read, which is REST's.
 */
#[CoversClass( Agend_Content_Access_Blocks::class )]
#[CoversClass( Agend_Content_Access_Frontend::class )]
final class BlockFootnotesTest extends BlockEnforcementTestCase {

	private const POST   = 700;
	private const NOTE_A = 'aaaaaaaa-0000-4000-8000-00000000000a';
	private const NOTE_B = 'bbbbbbbb-0000-4000-8000-00000000000b';
	private const NOTE_C = 'cccccccc-0000-4000-8000-00000000000c';

	private const MEMBERS = array( 'mode' => 'active_member' );

	/** The anchor core writes into a paragraph for a footnote. */
	private function anchor( string $id ): string {
		return '<sup data-fn="' . $id . '" class="fn"><a href="#' . $id . '" id="' . $id . '-link">1</a></sup>';
	}

	/** @param array<int, array{id?: mixed, content: string}> $notes */
	private function notes( array $notes ): string {
		return (string) wp_json_encode( $notes );
	}

	private function allNotes(): string {
		return $this->notes(
			array(
				array( 'id' => self::NOTE_A, 'content' => 'PUBLIC NOTE' ),
				array( 'id' => self::NOTE_B, 'content' => 'SECRET NOTE' ),
			)
		);
	}

	/** A post with a public cited paragraph and a members-only one, and the footnotes of both. */
	private function documentWithSecretNote( string $notes = '' ): void {
		$this->registerPost(
			self::POST,
			$this->paragraph( 'Public claim' . $this->anchor( self::NOTE_A ) )
			. $this->paragraph( 'Secret claim' . $this->anchor( self::NOTE_B ), self::MEMBERS )
		);
		$GLOBALS['agend_test_post_meta'][ self::POST ]['footnotes'] = '' === $notes ? $this->allNotes() : $notes;
	}

	/** The ids a read returns, from the single (block) shape. */
	private function visibleIds( bool $single = true ): array {
		$value = get_post_meta( self::POST, 'footnotes', $single );
		$value = $single ? $value : $value[0];

		return array_column( (array) json_decode( (string) $value, true ), 'id' );
	}

	// -----------------------------------------------------------------------
	// The leak
	// -----------------------------------------------------------------------

	/** The premise: with no filter the note of a block the visitor cannot see is in the meta. */
	#[Test]
	public function without_the_filter_the_hidden_blocks_note_is_readable(): void {
		$this->documentWithSecretNote();
		remove_filter( 'get_post_metadata' );

		$this->assertSame( array( self::NOTE_A, self::NOTE_B ), $this->visibleIds() );
	}

	#[Test]
	public function an_anonymous_read_loses_the_note_of_a_restricted_block(): void {
		$this->documentWithSecretNote();

		$this->assertSame( array( self::NOTE_A ), $this->visibleIds() );
	}

	/** REST reads the field as a list. */
	#[Test]
	public function the_rest_shaped_read_loses_it_too(): void {
		$this->documentWithSecretNote();

		$this->assertSame( array( self::NOTE_A ), $this->visibleIds( false ) );
	}

	#[Test]
	public function the_footnotes_block_prints_only_what_the_viewer_may_read(): void {
		$this->documentWithSecretNote();
		$this->registerFootnotesBlock();

		$this->inTheLoopOf( self::POST );
		$html = do_blocks( '<!-- wp:footnotes /-->' );

		$this->assertStringContainsString( 'PUBLIC NOTE', $html );
		$this->assertStringNotContainsString( 'SECRET NOTE', $html );
	}

	private function registerFootnotesBlock(): void {
		register_block_type(
			'core/footnotes',
			array(
				'render_callback' => static function ( array $attributes, string $content, $block ): string {
					$notes = json_decode( (string) get_post_meta( (int) ( $block->context['postId'] ?? 0 ), 'footnotes', true ), true );
					$html  = '';

					foreach ( (array) $notes as $note ) {
						$html .= '<li id="' . $note['id'] . '">' . $note['content'] . '</li>';
					}

					return '<ol>' . $html . '</ol>';
				},
			)
		);
	}

	/** An entitled member may read the block, so its note stays. */
	#[Test]
	public function an_entitled_member_keeps_every_note(): void {
		$this->asMember( self::TIER_A );
		$this->documentWithSecretNote();

		$this->assertSame( array( self::NOTE_A, self::NOTE_B ), $this->visibleIds() );
	}

	#[Test]
	public function the_footnotes_block_prints_every_note_for_an_entitled_member(): void {
		$this->asMember( self::TIER_A );
		$this->documentWithSecretNote();
		$this->registerFootnotesBlock();

		$this->inTheLoopOf( self::POST );
		$html = do_blocks( '<!-- wp:footnotes /-->' );

		$this->assertStringContainsString( 'PUBLIC NOTE', $html );
		$this->assertStringContainsString( 'SECRET NOTE', $html );
	}

	/** Each ruled block is judged for itself: the member sees the one they hold and not the one they do not. */
	#[Test]
	public function each_ruled_block_is_judged_for_itself(): void {
		$this->asMember( self::TIER_A );
		$this->registerPost(
			self::POST,
			$this->paragraph( 'Open to members' . $this->anchor( self::NOTE_A ), self::MEMBERS )
			. $this->paragraph( 'Plan B only' . $this->anchor( self::NOTE_B ), array( 'mode' => 'selected_tiers', 'tierIds' => array( self::TIER_B ) ) )
		);
		$GLOBALS['agend_test_post_meta'][ self::POST ]['footnotes'] = $this->allNotes();

		$this->assertSame( array( self::NOTE_A ), $this->visibleIds() );
	}

	// -----------------------------------------------------------------------
	// Where an anchor can be
	// -----------------------------------------------------------------------

	/** The anchor is in a paragraph inside a restricted group, not in the group's own markup. */
	#[Test]
	public function a_note_anchored_inside_a_restricted_wrapper_is_removed(): void {
		$this->registerPost(
			self::POST,
			$this->paragraph( 'Public' . $this->anchor( self::NOTE_A ) )
			. $this->group( self::MEMBERS, $this->group( null, $this->paragraph( 'Deep' . $this->anchor( self::NOTE_B ) ) ) )
		);
		$GLOBALS['agend_test_post_meta'][ self::POST ]['footnotes'] = $this->allNotes();

		$this->assertSame( array( self::NOTE_A ), $this->visibleIds() );
	}

	/** A restriction on a block inside a plain container still hides its own note. */
	#[Test]
	public function a_note_anchored_in_a_restricted_leaf_inside_a_plain_group_is_removed(): void {
		$this->registerPost(
			self::POST,
			$this->group( null, $this->paragraph( 'Public' . $this->anchor( self::NOTE_A ) ), $this->paragraph( 'Secret' . $this->anchor( self::NOTE_B ), self::MEMBERS ) )
		);
		$GLOBALS['agend_test_post_meta'][ self::POST ]['footnotes'] = $this->allNotes();

		$this->assertSame( array( self::NOTE_A ), $this->visibleIds() );
	}

	/** Rich text kept in an attribute, not in the markup, is still found. */
	#[Test]
	public function a_note_anchored_in_a_block_attribute_is_removed(): void {
		$this->registerPost(
			self::POST,
			$this->paragraph( 'Public' . $this->anchor( self::NOTE_A ) )
			. '<!-- wp:acme/card {"body":"Text ' . self::NOTE_B . '","agendAccess":{"mode":"active_member"}} /-->'
		);
		$GLOBALS['agend_test_post_meta'][ self::POST ]['footnotes'] = $this->allNotes();

		$this->assertSame( array( self::NOTE_A ), $this->visibleIds() );
	}

	/** Display conditions hide a block exactly as a policy does, and so hide its note. */
	#[Test]
	public function a_note_in_a_block_whose_conditions_fail_is_removed(): void {
		$this->registerPost(
			self::POST,
			$this->paragraph( 'Public' . $this->anchor( self::NOTE_A ) )
			. $this->paragraph( 'Signed in' . $this->anchor( self::NOTE_B ), array( 'conditionsEnabled' => true, 'conditions' => array( 'agend_wordpress:logged_in' ) ) )
		);
		$GLOBALS['agend_test_post_meta'][ self::POST ]['footnotes'] = $this->allNotes();

		$this->assertSame( array( self::NOTE_A ), $this->visibleIds() );

		$GLOBALS['agend_test_current_user_id'] = 9;

		$this->assertSame( array( self::NOTE_A, self::NOTE_B ), $this->visibleIds() );
	}

	/** A note whose anchor is in no ruled block, or in no block at all, is not touched. */
	#[Test]
	public function a_note_with_no_anchor_in_a_ruled_block_stays(): void {
		$this->registerPost(
			self::POST,
			$this->paragraph( 'Public' . $this->anchor( self::NOTE_A ) ) . $this->paragraph( 'Secret, with no note', self::MEMBERS )
		);
		$GLOBALS['agend_test_post_meta'][ self::POST ]['footnotes'] = $this->notes(
			array(
				array( 'id' => self::NOTE_A, 'content' => 'PUBLIC NOTE' ),
				array( 'id' => self::NOTE_C, 'content' => 'ORPHAN NOTE' ),
			)
		);

		$this->assertSame( array( self::NOTE_A, self::NOTE_C ), $this->visibleIds() );
	}

	// -----------------------------------------------------------------------
	// Failing closed
	// -----------------------------------------------------------------------

	/** The mapping is by id, so a list that cannot be read cannot be mapped: no notes. */
	#[Test]
	public function an_unreadable_list_on_a_document_with_a_ruled_block_returns_no_notes(): void {
		$this->documentWithSecretNote( '{not json' );

		$this->assertSame( '[]', get_post_meta( self::POST, 'footnotes', true ) );
	}

	#[Test]
	public function an_entry_with_no_id_goes_when_something_was_denied(): void {
		$this->documentWithSecretNote(
			$this->notes(
				array(
					array( 'id' => self::NOTE_A, 'content' => 'PUBLIC NOTE' ),
					array( 'content' => 'NO ID NOTE' ),
					array( 'id' => self::NOTE_B, 'content' => 'SECRET NOTE' ),
				)
			)
		);

		$this->assertSame( array( self::NOTE_A ), array_values( array_filter( $this->visibleIds() ) ) );
		$this->assertStringNotContainsString( 'NO ID NOTE', (string) get_post_meta( self::POST, 'footnotes', true ) );
	}

	/** With nothing denied there is nothing the entry could belong to. */
	#[Test]
	public function an_entry_with_no_id_stays_when_nothing_was_denied(): void {
		$this->asMember( self::TIER_A );
		$this->documentWithSecretNote( $this->notes( array( array( 'content' => 'NO ID NOTE' ), array( 'id' => self::NOTE_B, 'content' => 'SECRET NOTE' ) ) ) );

		$this->assertStringContainsString( 'NO ID NOTE', (string) get_post_meta( self::POST, 'footnotes', true ) );
	}

	/** A block that fails its check is a denial, and so is its note. */
	#[Test]
	public function a_failing_access_check_hides_the_note(): void {
		Agend_Test_WP::$filters['agend_apps_bearer_token'] = static function (): string {
			throw new \RuntimeException( 'the gateway client fell over' );
		};
		$this->documentWithSecretNote();

		$this->assertSame( array( self::NOTE_A ), $this->visibleIds() );
	}

	// -----------------------------------------------------------------------
	// Whose post, and who is looking
	// -----------------------------------------------------------------------

	/**
	 * The read is for ANY post, not the one in context: REST lists many. The
	 * block is judged against the post whose meta it is, so a post restricted to
	 * another plan hides a member's note though the page in context is public.
	 */
	#[Test]
	public function the_block_is_judged_against_the_post_whose_meta_is_read(): void {
		$this->asMember( self::TIER_A );
		$this->inTheLoopOf( self::PUBLIC_PAGE );
		$this->registerPost(
			self::POST,
			$this->paragraph( 'Public' . $this->anchor( self::NOTE_A ) ) . $this->paragraph( 'Secret' . $this->anchor( self::NOTE_B ), self::MEMBERS ),
			array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_B ) )
		);
		$GLOBALS['agend_test_post_meta'][ self::POST ]['footnotes'] = $this->allNotes();

		$this->assertSame( array( self::NOTE_A ), $this->visibleIds() );
	}

	/** An author loads the notes to edit them, and a filtered read would delete the hidden ones on save. */
	#[Test]
	public function an_authors_read_is_not_filtered(): void {
		$this->asEditor();
		$GLOBALS['agend_test_is_admin'] = true;
		$this->documentWithSecretNote();

		$this->assertSame( $this->allNotes(), get_post_meta( self::POST, 'footnotes', true ) );
	}

	#[Test]
	public function a_read_for_the_block_editors_own_rest_request_is_not_filtered(): void {
		$this->asEditor();
		$GLOBALS['agend_test_rest_request'] = true;
		$GLOBALS['wp']                      = (object) array( 'query_vars' => array( 'rest_route' => '/wp/v2/posts/' . self::POST ) );
		$_GET['context']                    = 'edit';
		$this->documentWithSecretNote();
		$this->inTheLoopOf( self::POST );

		$this->assertSame( $this->allNotes(), get_post_meta( self::POST, 'footnotes', true ) );
	}

	// -----------------------------------------------------------------------
	// What is left alone
	// -----------------------------------------------------------------------

	#[Test]
	public function a_document_with_no_ruled_block_returns_its_footnotes_exactly_as_stored(): void {
		$this->registerPost( self::POST, $this->paragraph( 'Plain' . $this->anchor( self::NOTE_A ) ) );
		$GLOBALS['agend_test_post_meta'][ self::POST ]['footnotes'] = '{not json, and not touched';

		$this->assertSame( '{not json, and not touched', get_post_meta( self::POST, 'footnotes', true ) );
		$this->assertSame( 0, $this->identity_lookups );
	}

	#[Test]
	public function other_meta_keys_are_not_touched(): void {
		$this->documentWithSecretNote();
		$GLOBALS['agend_test_post_meta'][ self::POST ]['colour'] = 'red';

		$this->assertSame( 'red', get_post_meta( self::POST, 'colour', true ) );
		$this->assertSame( array(), $this->uncacheable );
	}

	#[Test]
	public function a_post_with_no_footnotes_reads_as_it_did(): void {
		$this->registerPost( self::POST, $this->paragraph( 'Secret', self::MEMBERS ) );

		$this->assertSame( '', get_post_meta( self::POST, 'footnotes', true ) );
		$this->assertSame( array( '' ), get_post_meta( self::POST, 'footnotes', false ) );
	}

	/** A ruled block with no footnote anchor in it is not worth a decision, so it costs no identity lookup. */
	#[Test]
	public function a_ruled_block_with_no_anchor_in_it_is_not_decided(): void {
		$this->registerPost(
			self::POST,
			$this->paragraph( 'Public' . $this->anchor( self::NOTE_A ) ) . $this->paragraph( 'Secret, no note', self::MEMBERS )
		);
		$GLOBALS['agend_test_post_meta'][ self::POST ]['footnotes'] = $this->allNotes();

		get_post_meta( self::POST, 'footnotes', true );

		$this->assertSame( 0, $this->identity_lookups );
	}

	/** The read is the viewer's, so the page that shows it is per-viewer. */
	#[Test]
	public function a_decision_made_for_a_note_marks_the_response_uncacheable(): void {
		$this->documentWithSecretNote();

		get_post_meta( self::POST, 'footnotes', true );

		$this->assertSame( array( self::POST ), $this->uncacheable );
	}

	// -----------------------------------------------------------------------
	// REST for a document that is denied outright
	// -----------------------------------------------------------------------

	private function restDataFor( int $post_id ): array {
		$response = new WP_REST_Response(
			array(
				'id'      => $post_id,
				'content' => array( 'rendered' => '<p>BODY</p>' ),
				'excerpt' => array( 'rendered' => '<p>BODY</p>' ),
				'meta'    => array( 'footnotes' => $this->allNotes(), 'colour' => 'red' ),
			)
		);

		return ( new Agend_Content_Access_Frontend() )->filter_rest_response(
			$response,
			get_post( $post_id ),
			new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id )
		)->get_data();
	}

	/** The body is withheld, and a note on it is as much of the body as a paragraph. */
	#[Test]
	public function the_rest_response_for_a_denied_document_carries_no_footnotes(): void {
		$data = $this->restDataFor( self::TIER_B_POST );

		$this->assertArrayNotHasKey( 'footnotes', $data['meta'] );
		$this->assertSame( 'red', $data['meta']['colour'], 'the rest of the meta is as it was' );
	}

	#[Test]
	public function the_rest_response_for_a_granted_document_keeps_them(): void {
		$data = $this->restDataFor( self::PUBLIC_PAGE );

		$this->assertSame( $this->allNotes(), $data['meta']['footnotes'] );
	}

	// -----------------------------------------------------------------------
	// Registration
	// -----------------------------------------------------------------------

	#[Test]
	public function the_filter_is_registered_with_every_argument_it_reads(): void {
		$entry = Agend_Test_WP::$filter_registry['get_post_metadata'][0];

		$this->assertSame( array( $this->blocks, 'filter_footnotes_meta' ), $entry['callback'] );
		$this->assertSame( 4, $entry['args'] );
	}

	/** An answer an earlier callback already gave is not overridden. */
	#[Test]
	public function a_value_an_earlier_callback_chose_is_returned_untouched(): void {
		$this->documentWithSecretNote();

		$this->assertSame( 'CHOSEN', $this->blocks->filter_footnotes_meta( 'CHOSEN', self::POST, 'footnotes', true ) );
		$this->assertSame( 'red', $this->blocks->filter_footnotes_meta( 'red', self::POST, 'colour', true ) );
	}
}
