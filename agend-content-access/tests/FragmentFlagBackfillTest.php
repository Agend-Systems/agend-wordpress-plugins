<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Blocks;
use Agend_Test_WP;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use WP_Post;

require_once __DIR__ . '/BlockEnforcementTestCase.php';

/**
 * The posts table, as far as the backfill reads it: ids in order, after a given
 * id, a limit. Anything else the real query does is not modelled.
 */
final class PostsTableDouble extends \wpdb {

	public string $posts = 'wp_posts';

	/** @var int[] Every post id, ascending. */
	public array $ids = array();

	public function get_col( string $query ): array {
		$this->queries[] = $query;

		preg_match( '/ID > (\d+)/', $query, $after );
		preg_match( '/LIMIT (\d+)/', $query, $limit );

		$rows = array_values( array_filter( $this->ids, static fn( int $id ): bool => $id > (int) ( $after[1] ?? 0 ) ) );

		return array_map( 'strval', array_slice( $rows, 0, (int) ( $limit[1] ?? 0 ) ) );
	}
}

/**
 * Keeping the rules flag right for posts nobody saved through the editor.
 *
 * The flag is what a search query filters on, and it is set when a post is saved.
 * Three things leave it wrong. A document written without a save (an importer,
 * WP-CLI) changes only the meta. A post saved before the flag existed has none.
 * And a stored document that cannot be read must read as carrying rules, because
 * wrongly answering "none" publishes the text of a restricted block or section,
 * where wrongly answering "some" only costs a search result.
 */
#[CoversClass( Agend_Content_Access_Blocks::class )]
final class FragmentFlagBackfillTest extends BlockEnforcementTestCase {

	private const FLAG    = Agend_Content_Access_Blocks::SEARCH_FLAG_META_KEY;
	private const POST_ID = 77;

	/** @param array<string, mixed> $settings */
	private function node( array $settings = array(), array $children = array(), string $type = 'section' ): array {
		return array(
			'id'       => 'n' . count( $children ) . count( $settings ),
			'elType'   => $type,
			'settings' => $settings,
			'elements' => $children,
		);
	}

	private function withRules(): string {
		return (string) wp_json_encode( array( $this->node( array( 'agend_access_mode' => 'active_member' ) ) ) );
	}

	private function store( $value, int $id = self::POST_ID ): void {
		$GLOBALS['agend_test_post_meta'][ $id ]['_elementor_data'] = $value;
	}

	/** A post whose document is an Elementor one carrying a rule. */
	private function elementorPostWithRules( int $id ): void {
		$this->registerPost( $id, 'THE OPENING AND THE SECTION SECRET' );
		$this->store( $this->withRules(), $id );
	}

	/** A post whose document is a block one carrying a rule. */
	private function blockPostWithRules( int $id ): void {
		$this->registerPost( $id, $this->paragraph( 'OPENING' ) . $this->paragraph( 'SECRET', array( 'mode' => 'active_member' ) ) );
	}

	private function flag( int $id ) {
		return $GLOBALS['agend_test_post_meta'][ $id ][ self::FLAG ] ?? null;
	}

	private function carries( int $id = self::POST_ID ): bool {
		return Agend_Content_Access_Blocks::document_carries_rules( get_post( $id ) );
	}

	// -----------------------------------------------------------------------
	// Fail closed: a document that cannot be read carries rules
	// -----------------------------------------------------------------------

	/** @return array<string, array{0: mixed}> */
	public static function unreadableDocuments(): array {
		return array(
			'truncated json'    => array( '[{"id":"a","elType":"section","settings":{"agend_access_mode":' ),
			'not json at all'   => array( 'not a document' ),
			'a json scalar'     => array( '42' ),
			'json null'         => array( 'null' ),
			'a non array value' => array( 7 ),
			'an object'         => array( new \stdClass() ),
			'a list holding a scalar' => array( array( 'not a node' ) ),
		);
	}

	/**
	 * "I could not tell" is not "there are none". A document that is stored but
	 * unreadable is exactly where a rule could be hiding.
	 */
	#[Test]
	#[DataProvider( 'unreadableDocuments' )]
	public function a_stored_document_that_cannot_be_read_is_treated_as_carrying_rules( $stored ): void {
		$this->registerPost( self::POST_ID, 'BODY' );
		$this->store( $stored );

		$this->assertTrue( $this->carries() );
	}

	/** @return array<string, array{0: mixed}> */
	public static function documentsWithNoRules(): array {
		return array(
			'no document stored'                     => array( '' ),
			'whitespace only'                        => array( "  \n" ),
			'an empty document'                      => array( '[]' ),
			'inherit, which is the explicit opt out' => array(
				(string) wp_json_encode( array( array( 'id' => 'a', 'elType' => 'section', 'settings' => array( 'agend_access_mode' => 'inherit' ), 'elements' => array() ) ) ),
			),
			'conditions switched off'                => array(
				(string) wp_json_encode( array( array( 'id' => 'a', 'elType' => 'section', 'settings' => array( 'agend_conditions_enabled' => '' ), 'elements' => array() ) ) ),
			),
			'already decoded, none'                  => array( array( array( 'id' => 'a', 'elType' => 'section', 'settings' => array(), 'elements' => array() ) ) ),
		);
	}

	#[Test]
	#[DataProvider( 'documentsWithNoRules' )]
	public function a_document_with_no_rules_does_not_carry_any( $stored ): void {
		$this->registerPost( self::POST_ID, $this->paragraph( 'PLAIN' ) );
		$this->store( $stored );

		$this->assertFalse( $this->carries() );
	}

	/**
	 * Elementor stores a JSON string, but a read can come back as the decoded list
	 * (an import, a filter on the meta), and that must neither raise a notice nor
	 * be misread as unreadable.
	 */
	#[Test]
	public function an_already_decoded_document_is_read_too(): void {
		$this->registerPost( self::POST_ID, 'BODY' );
		$this->store( array( $this->node( array( 'agend_access_mode' => 'active_member' ) ) ) );

		$this->assertTrue( $this->carries() );
	}

	/**
	 * The empty list is what this plugin itself answers for a document it is
	 * withholding. It is "no document", so the block tree is what is read.
	 */
	#[Test]
	public function an_empty_decoded_list_is_no_document_and_the_block_tree_is_read(): void {
		$this->blockPostWithRules( self::POST_ID );
		$this->store( array() );

		$this->assertTrue( $this->carries(), 'the ruled block is found' );

		$this->registerPost( 78, $this->paragraph( 'PLAIN' ) );
		$this->store( array(), 78 );

		$this->assertFalse( $this->carries( 78 ) );
	}

	// -----------------------------------------------------------------------
	// The flag follows a document written without a save
	// -----------------------------------------------------------------------

	#[Test]
	public function the_meta_hooks_are_registered_for_the_document(): void {
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			$this->assertContains( array( $this->blocks, 'sync_search_flag_on_meta_change' ), Agend_Test_WP::$actions[ $hook ], $hook );
		}

		$this->assertContains( array( 'Agend_Content_Access_Blocks', 'maybe_backfill' ), Agend_Test_WP::$actions['admin_init'] );
	}

	#[Test]
	public function a_document_written_without_a_save_is_picked_up_from_the_meta_change(): void {
		$this->registerPost( self::POST_ID, 'BODY' );
		$this->store( $this->withRules() );

		do_action( 'updated_post_meta', 1, self::POST_ID, '_some_other_key', 'x' );
		$this->assertNull( $this->flag( self::POST_ID ), 'only the document key matters' );

		do_action( 'updated_post_meta', 1, self::POST_ID, '_elementor_data', 'x' );
		$this->assertSame( '1', $this->flag( self::POST_ID ) );
	}

	#[Test]
	public function a_document_added_by_an_import_is_picked_up(): void {
		$this->registerPost( self::POST_ID, 'BODY' );
		$this->store( $this->withRules() );

		do_action( 'added_post_meta', 1, self::POST_ID, '_elementor_data', 'x' );

		$this->assertSame( '1', $this->flag( self::POST_ID ) );
	}

	#[Test]
	public function a_document_removed_without_a_save_clears_the_flag(): void {
		$this->registerPost( self::POST_ID, $this->paragraph( 'PLAIN' ) );
		$this->store( $this->withRules() );
		do_action( 'updated_post_meta', 1, self::POST_ID, '_elementor_data', 'x' );
		$this->assertSame( '1', $this->flag( self::POST_ID ) );

		unset( $GLOBALS['agend_test_post_meta'][ self::POST_ID ]['_elementor_data'] );
		do_action( 'deleted_post_meta', array( 1 ), self::POST_ID, '_elementor_data', 'x' );

		$this->assertNull( $this->flag( self::POST_ID ) );
	}

	#[Test]
	public function a_rule_removed_without_a_save_clears_the_flag(): void {
		$this->registerPost( self::POST_ID, 'BODY' );
		$this->store( $this->withRules() );
		do_action( 'updated_post_meta', 1, self::POST_ID, '_elementor_data', 'x' );

		$this->store( (string) wp_json_encode( array( $this->node() ) ) );
		do_action( 'updated_post_meta', 1, self::POST_ID, '_elementor_data', 'x' );

		$this->assertNull( $this->flag( self::POST_ID ) );
	}

	#[Test]
	public function a_revisions_document_is_not_flagged(): void {
		$this->registerPost( self::POST_ID, 'BODY' );
		$this->store( $this->withRules() );
		$GLOBALS['agend_test_revisions'][ self::POST_ID ] = 5;

		do_action( 'updated_post_meta', 1, self::POST_ID, '_elementor_data', 'x' );

		$this->assertNull( $this->flag( self::POST_ID ) );
	}

	#[Test]
	public function a_meta_change_for_a_post_that_does_not_exist_does_nothing(): void {
		do_action( 'updated_post_meta', 1, 999999, '_elementor_data', 'x' );

		$this->assertNull( $this->flag( 999999 ) );
	}

	/** Written only when it is wrong: a row with any other value is put right, and a clean post gets no row. */
	#[Test]
	public function a_flag_with_the_wrong_value_is_put_right(): void {
		$this->elementorPostWithRules( self::POST_ID );
		$GLOBALS['agend_test_post_meta'][ self::POST_ID ][ self::FLAG ] = '0';

		$this->assertTrue( Agend_Content_Access_Blocks::sync_flag( self::POST_ID ) );
		$this->assertSame( '1', $this->flag( self::POST_ID ) );

		$this->registerPost( 78, $this->paragraph( 'PLAIN' ) );
		$GLOBALS['agend_test_post_meta'][ 78 ][ self::FLAG ] = '';

		$this->assertFalse( Agend_Content_Access_Blocks::sync_flag( 78 ) );
		$this->assertArrayNotHasKey( self::FLAG, $GLOBALS['agend_test_post_meta'][ 78 ], 'a blank row would keep it out of search while saying nothing' );
	}

	// -----------------------------------------------------------------------
	// Backfill
	// -----------------------------------------------------------------------

	/** @return callable Serves ids ascending, after a given id. */
	private function ids( int ...$all ): callable {
		return static fn( int $after, int $limit ): array => array_slice( array_values( array_filter( $all, static fn( int $id ): bool => $id > $after ) ), 0, $limit );
	}

	#[Test]
	public function a_batch_flags_the_posts_that_carry_rules_and_reports_where_it_got_to(): void {
		$this->elementorPostWithRules( 10 );
		$this->elementorPostWithRules( 30 );
		$this->registerPost( 20, '' );
		$this->store( '[]', 20 );

		$result = Agend_Content_Access_Blocks::backfill_batch( 0, 100, $this->ids( 10, 20, 30 ) );

		$this->assertSame( '1', $this->flag( 10 ) );
		$this->assertNull( $this->flag( 20 ) );
		$this->assertSame( '1', $this->flag( 30 ) );
		$this->assertSame( array( 'processed' => 3, 'flagged' => 2, 'last_id' => 30, 'done' => true ), $result );
	}

	/** The stack's check, not an Elementor-only one: a block document is flagged too. */
	#[Test]
	public function a_batch_flags_block_documents_as_well_as_elementor_ones(): void {
		$this->blockPostWithRules( 10 );
		$this->elementorPostWithRules( 20 );
		$this->registerPattern( 920, $this->paragraph( 'IN A PATTERN', array( 'mode' => 'active_member' ) ) );
		$this->registerPost( 30, $this->reference( 920 ) );
		$this->registerPost( 40, $this->paragraph( 'PLAIN' ) );
		$this->registerPost( 50, $this->paragraph( 'CONDITIONED', array( 'conditionsEnabled' => true ) ) );

		$result = Agend_Content_Access_Blocks::backfill_batch( 0, 100, $this->ids( 10, 20, 30, 40, 50 ) );

		$this->assertSame( '1', $this->flag( 10 ), 'a block with a policy' );
		$this->assertSame( '1', $this->flag( 20 ), 'an Elementor section with a policy' );
		$this->assertSame( '1', $this->flag( 30 ), 'a rule inside a synced pattern' );
		$this->assertNull( $this->flag( 40 ) );
		$this->assertSame( '1', $this->flag( 50 ), 'display conditions on a block' );
		$this->assertSame( 4, $result['flagged'] );
	}

	#[Test]
	public function a_document_that_cannot_be_read_is_flagged_by_the_backfill(): void {
		$this->registerPost( 10, 'BODY' );
		$this->store( '[{"truncated"', 10 );

		Agend_Content_Access_Blocks::backfill_batch( 0, 100, $this->ids( 10 ) );

		$this->assertSame( '1', $this->flag( 10 ) );
	}

	#[Test]
	public function a_full_batch_is_not_the_end_and_the_next_one_starts_after_it(): void {
		$this->elementorPostWithRules( 10 );
		$this->elementorPostWithRules( 20 );
		$this->elementorPostWithRules( 30 );
		$fetch = $this->ids( 10, 20, 30 );

		$first  = Agend_Content_Access_Blocks::backfill_batch( 0, 2, $fetch );
		$second = Agend_Content_Access_Blocks::backfill_batch( $first['last_id'], 2, $fetch );

		$this->assertFalse( $first['done'] );
		$this->assertSame( 20, $first['last_id'] );
		$this->assertSame( array( 'processed' => 1, 'flagged' => 1, 'last_id' => 30, 'done' => true ), $second );
	}

	#[Test]
	public function the_backfill_also_clears_a_flag_that_is_no_longer_right(): void {
		$this->registerPost( 10, $this->paragraph( 'PLAIN' ) );
		$GLOBALS['agend_test_post_meta'][ 10 ][ self::FLAG ] = '1';
		$this->store( '[]', 10 );

		Agend_Content_Access_Blocks::backfill_batch( 0, 100, $this->ids( 10 ) );

		$this->assertNull( $this->flag( 10 ) );
	}

	#[Test]
	public function a_revision_in_the_range_is_not_flagged_or_counted(): void {
		$this->elementorPostWithRules( 10 );
		$GLOBALS['agend_test_revisions'][ 10 ] = 5;

		$result = Agend_Content_Access_Blocks::backfill_batch( 0, 100, $this->ids( 10 ) );

		$this->assertNull( $this->flag( 10 ) );
		$this->assertSame( 0, $result['flagged'] );
	}

	#[Test]
	public function an_empty_table_is_done_at_once(): void {
		$this->assertSame(
			array( 'processed' => 0, 'flagged' => 0, 'last_id' => 0, 'done' => true ),
			Agend_Content_Access_Blocks::backfill_batch( 0, 100, $this->ids() )
		);
	}

	#[Test]
	public function the_posts_are_read_in_id_order_after_the_last_one_with_the_limit_bound(): void {
		$db              = new PostsTableDouble();
		$db->ids         = array( 5, 7, 9, 11 );
		$GLOBALS['wpdb'] = $db;

		$ids = Agend_Content_Access_Blocks::post_ids_after( 5, 2 );

		$this->assertSame( array( 7, 9 ), $ids );
		$this->assertCount( 1, $db->queries );
		$this->assertStringContainsString( 'ID > 5', $db->queries[0] );
		$this->assertStringContainsString( 'ORDER BY ID ASC LIMIT 2', $db->queries[0] );
		$this->assertStringContainsString( "post_type IN ( 'post', 'page' )", $db->queries[0] );
		$this->assertStringContainsString( "post_status <> 'auto-draft'", $db->queries[0] );
		$this->assertStringNotContainsString( 'attachment', $db->queries[0] );
	}

	// The one-off pass on admin_init.

	private function asAdministrator(): void {
		$GLOBALS['agend_test_current_user_can']['manage_options'] = true;
	}

	private function table( int ...$ids ): PostsTableDouble {
		$db              = new PostsTableDouble();
		$db->ids         = $ids;
		$GLOBALS['wpdb'] = $db;

		return $db;
	}

	#[Test]
	public function the_one_off_pass_does_a_hundred_posts_and_remembers_where_it_stopped(): void {
		$this->asAdministrator();
		$this->table( ...range( 1, 250 ) );
		$this->elementorPostWithRules( 40 );

		Agend_Content_Access_Blocks::maybe_backfill();

		$this->assertSame( array( 'last_id' => 100, 'done' => false ), Agend_Content_Access_Blocks::backfill_state() );
		$this->assertSame( '1', $this->flag( 40 ) );
	}

	#[Test]
	public function the_one_off_pass_converges_over_successive_page_loads_and_then_stops(): void {
		$this->asAdministrator();
		$db = $this->table( ...range( 1, 250 ) );
		$this->blockPostWithRules( 240 );

		foreach ( range( 1, 3 ) as $_ ) {
			Agend_Content_Access_Blocks::maybe_backfill();
		}

		$this->assertSame( array( 'last_id' => 250, 'done' => true ), Agend_Content_Access_Blocks::backfill_state() );
		$this->assertSame( '1', $this->flag( 240 ), 'a post saved before the flag existed is reached without anyone running anything' );
		$this->assertCount( 3, $db->queries );

		Agend_Content_Access_Blocks::maybe_backfill();

		$this->assertCount( 3, $db->queries, 'once done it costs nothing' );
	}

	#[Test]
	public function the_one_off_pass_is_for_people_who_can_manage_options_only(): void {
		$GLOBALS['agend_test_current_user_can']['manage_options'] = false;
		$db = $this->table( 1, 2, 3 );

		Agend_Content_Access_Blocks::maybe_backfill();

		$this->assertSame( array(), $db->queries );
		$this->assertSame( array( 'last_id' => 0, 'done' => false ), Agend_Content_Access_Blocks::backfill_state() );
	}

	#[Test]
	public function the_one_off_pass_does_not_run_on_a_heartbeat(): void {
		$this->asAdministrator();
		$GLOBALS['agend_test_doing_ajax'] = true;
		$db                               = $this->table( 1, 2, 3 );

		Agend_Content_Access_Blocks::maybe_backfill();

		$this->assertSame( array(), $db->queries );
	}

	/** The registered callback is the one that runs, on the hook wp-admin fires. */
	#[Test]
	public function admin_init_runs_the_one_off_pass(): void {
		$this->asAdministrator();
		$db = $this->table( 1, 2, 3 );

		do_action( 'admin_init' );

		$this->assertCount( 1, $db->queries );
		$this->assertSame( array( 'last_id' => 3, 'done' => true ), Agend_Content_Access_Blocks::backfill_state() );
	}

	#[Test]
	public function an_unreadable_progress_record_starts_again_rather_than_skipping_posts(): void {
		Agend_Test_WP::$options[ Agend_Content_Access_Blocks::BACKFILL_OPTION ] = 'garbage';

		$this->assertSame( array( 'last_id' => 0, 'done' => false ), Agend_Content_Access_Blocks::backfill_state() );

		Agend_Test_WP::$options[ Agend_Content_Access_Blocks::BACKFILL_OPTION ] = array( 'last_id' => -5, 'done' => 0 );

		$this->assertSame( array( 'last_id' => 0, 'done' => false ), Agend_Content_Access_Blocks::backfill_state() );
	}

	// The command.

	#[Test]
	public function the_command_runs_every_batch_from_the_start_and_marks_the_one_off_done(): void {
		$this->elementorPostWithRules( 10 );
		$this->blockPostWithRules( 30 );
		$passes = array();

		$total = Agend_Content_Access_Blocks::backfill_all(
			2,
			static function ( array $pass ) use ( &$passes ): void {
				$passes[] = $pass['last_id'];
			},
			$this->ids( 10, 20, 30 )
		);

		$this->assertSame( array( 'processed' => 3, 'flagged' => 2 ), $total );
		$this->assertSame( array( 20, 30 ), $passes );
		$this->assertSame( array( 'last_id' => 30, 'done' => true ), Agend_Content_Access_Blocks::backfill_state() );
	}

	#[Test]
	public function the_command_starts_from_the_first_post_even_when_the_one_off_has_finished(): void {
		Agend_Test_WP::$options[ Agend_Content_Access_Blocks::BACKFILL_OPTION ] = array( 'last_id' => 999, 'done' => true );
		$this->elementorPostWithRules( 10 );

		Agend_Content_Access_Blocks::backfill_all( 100, null, $this->ids( 10 ) );

		$this->assertSame( '1', $this->flag( 10 ) );
	}

	/** A fetcher that never says "done" cannot hold the command in a loop. */
	#[Test]
	public function the_command_ends_when_a_pass_examines_nothing(): void {
		$calls = 0;
		$fetch = static function () use ( &$calls ): array {
			++$calls;

			return array();
		};

		$total = Agend_Content_Access_Blocks::backfill_all( 100, null, $fetch );

		$this->assertSame( array( 'processed' => 0, 'flagged' => 0 ), $total );
		$this->assertSame( 1, $calls );
	}
}
