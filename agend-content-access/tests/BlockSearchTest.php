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
use WP_Query;

require_once __DIR__ . '/BlockEnforcementTestCase.php';

/**
 * Core search must not tell a visitor that protected text exists.
 *
 * Core search matches the raw `post_content` with `LIKE` in SQL and never
 * touches the render pipeline, so a public page with a members-only block
 * matches a search for the protected words, and the hit alone is the
 * disclosure. Removing the block from the render cannot help.
 */
#[CoversClass( Agend_Content_Access_Blocks::class )]
final class BlockSearchTest extends BlockEnforcementTestCase {

	private const FLAG = Agend_Content_Access_Blocks::SEARCH_FLAG_META_KEY;

	private function secret(): string {
		return $this->paragraph( 'SECRET', array( 'mode' => 'active_member' ) );
	}

	private function flagged( int $id ): bool {
		return '1' === get_post_meta( $id, self::FLAG, true );
	}

	/** Saves a post the way WordPress does: the row changes, then `save_post` fires. */
	private function save( int $id, string $content ): void {
		$GLOBALS['agend_test_posts'][ $id ]['post_content'] = $content;

		do_action( 'save_post', $id, get_post( $id ), true );
	}

	/** A search query for the term, run through `pre_get_posts` as WordPress runs it. */
	private function search( array $vars = array( 's' => 'secret' ), bool $main = true ): WP_Query {
		$query          = new WP_Query( $vars );
		$query->is_main = $main;

		do_action( 'pre_get_posts', $query );

		return $query;
	}

	/**
	 * Whether the query, as filtered, still returns the post: a small evaluator
	 * for the `meta_query` shapes in play, over the `agend_test_post_meta` store.
	 * Enough to tell a clause that NARROWS from one that widens.
	 *
	 * @param WP_Query $query Filtered query.
	 * @param int      $id    Post id.
	 */
	private function returns( WP_Query $query, int $id ): bool {
		$meta_query = $query->get( 'meta_query' );

		return ! is_array( $meta_query ) || array() === $meta_query || $this->queryMatches( $meta_query, $id );
	}

	private function queryMatches( array $node, int $id ): bool {
		if ( isset( $node['key'] ) ) {
			$exists = metadata_exists( 'post', $id, (string) $node['key'] );

			switch ( $node['compare'] ?? '=' ) {
				case 'NOT EXISTS':
					return ! $exists;
				case 'EXISTS':
					return $exists;
				default:
					throw new \LogicException( 'Unmodelled compare ' . $node['compare'] );
			}
		}

		$relation = $node['relation'] ?? 'AND';
		$results  = array();

		foreach ( $node as $key => $child ) {
			if ( 'relation' !== $key && is_array( $child ) ) {
				$results[] = $this->queryMatches( $child, $id );
			}
		}

		return 'OR' === $relation ? in_array( true, $results, true ) : ! in_array( false, $results, true );
	}

	// -----------------------------------------------------------------------
	// The flag
	// -----------------------------------------------------------------------

	#[Test]
	public function the_flag_is_a_protected_meta_key(): void {
		$this->assertSame( '_agend_access_block_rules', self::FLAG );
		$this->assertStringStartsWith( '_', self::FLAG );
	}

	#[Test]
	public function saving_a_post_with_a_restricted_block_sets_the_flag(): void {
		$this->registerPost( 500, '' );

		$this->save( 500, $this->paragraph( 'PUBLIC' ) . $this->secret() );

		$this->assertTrue( $this->flagged( 500 ) );
	}

	#[Test]
	public function removing_the_restricted_block_and_saving_clears_the_flag(): void {
		$this->registerPost( 501, '' );
		$this->save( 501, $this->secret() );
		$this->assertTrue( $this->flagged( 501 ) );

		$this->save( 501, $this->paragraph( 'PUBLIC' ) );

		$this->assertFalse( $this->flagged( 501 ) );
		$this->assertFalse( metadata_exists( 'post', 501, self::FLAG ), 'the row is deleted, not blanked, so NOT EXISTS matches' );
	}

	#[Test]
	public function a_post_with_no_restricted_block_is_never_flagged(): void {
		$this->registerPost( 502, '' );

		$this->save( 502, $this->paragraph( 'PLAIN' ) . $this->paragraph( 'INHERITS', array( 'mode' => 'inherit' ) ) );

		$this->assertFalse( metadata_exists( 'post', 502, self::FLAG ) );
	}

	#[Test]
	public function a_conditioned_block_sets_the_flag(): void {
		$this->registerPost( 503, '' );

		$this->save( 503, $this->paragraph( 'NOTE', array( 'conditionsEnabled' => true ) ) );

		$this->assertTrue( $this->flagged( 503 ) );
	}

	#[Test]
	public function a_restriction_inside_a_synced_pattern_sets_the_flag(): void {
		$this->registerPattern( 920, $this->secret() );
		$this->registerPost( 504, '' );

		$this->save( 504, $this->reference( 920 ) );

		$this->assertTrue( $this->flagged( 504 ) );
	}

	/**
	 * The documented limit: editing a pattern later does not reach the pages that
	 * use it, so the flag is stale until each is saved again.
	 */
	#[Test]
	public function a_later_edit_to_a_synced_pattern_does_not_update_pages_that_use_it(): void {
		$this->registerPattern( 921, $this->paragraph( 'PLAIN' ) );
		$this->registerPost( 505, '' );
		$this->save( 505, $this->reference( 921 ) );
		$this->assertFalse( $this->flagged( 505 ) );

		$this->save( 921, $this->secret() );

		$this->assertFalse( $this->flagged( 505 ), 'stale until the page is saved again' );

		$this->save( 505, $this->reference( 921 ) );

		$this->assertTrue( $this->flagged( 505 ) );
	}

	#[Test]
	public function a_revision_does_not_touch_the_flag(): void {
		$this->registerPost( 506, '' );
		$this->registerPost( 507, $this->secret() );
		$GLOBALS['agend_test_revisions'][ 507 ] = 506;

		do_action( 'save_post', 507, get_post( 507 ), false );

		$this->assertFalse( metadata_exists( 'post', 507, self::FLAG ) );
		$this->assertFalse( metadata_exists( 'post', 506, self::FLAG ) );
	}

	#[Test]
	public function an_autosave_does_not_touch_the_flag(): void {
		$this->registerPost( 508, $this->secret() );
		$GLOBALS['agend_test_autosaves'][ 508 ] = 506;

		do_action( 'save_post', 508, get_post( 508 ), false );

		$this->assertFalse( metadata_exists( 'post', 508, self::FLAG ) );
	}

	#[Test]
	public function saving_a_post_that_no_longer_exists_does_nothing(): void {
		do_action( 'save_post', 99999, null, false );

		$this->assertFalse( metadata_exists( 'post', 99999, self::FLAG ) );
	}

	// -----------------------------------------------------------------------
	// The query
	// -----------------------------------------------------------------------

	#[Test]
	public function the_main_search_query_for_a_visitor_excludes_flagged_posts(): void {
		$this->registerPost( 510, '' );
		$this->registerPost( 511, '' );
		$this->save( 510, $this->secret() );
		$this->save( 511, $this->paragraph( 'PLAIN' ) );

		$query = $this->search();

		$this->assertFalse( $this->returns( $query, 510 ), 'the flagged post is excluded' );
		$this->assertTrue( $this->returns( $query, 511 ), 'an ordinary post is still searchable' );
	}

	#[Test]
	public function the_exclusion_is_a_not_exists_clause_on_the_flag(): void {
		$query = $this->search();

		$this->assertSame(
			array( array( 'key' => self::FLAG, 'compare' => 'NOT EXISTS' ) ),
			$query->get( 'meta_query' )
		);
	}

	/** The REST API runs the same query for `?search=`, and it is not the main query. */
	#[Test]
	public function a_secondary_search_query_is_excluded_too(): void {
		$this->registerPost( 512, '' );
		$this->save( 512, $this->secret() );

		$this->assertFalse( $this->returns( $this->search( array( 's' => 'secret' ), false ), 512 ) );
	}

	/** An unauthenticated REST search is a visitor's. */
	#[Test]
	public function an_anonymous_search_is_excluded(): void {
		$GLOBALS['agend_test_current_user_id'] = 0;
		$this->registerPost( 513, '' );
		$this->save( 513, $this->secret() );

		$this->assertFalse( $this->returns( $this->search(), 513 ) );
	}

	#[Test]
	public function a_signed_in_member_who_cannot_edit_is_filtered_too(): void {
		$this->asMember( self::TIER_A );
		$this->registerPost( 514, '' );
		$this->save( 514, $this->secret() );

		$this->assertFalse( $this->returns( $this->search(), 514 ), 'the hit would still disclose text the member may not be allowed to read' );
	}

	#[Test]
	public function editors_are_not_filtered(): void {
		$this->asEditor();
		$this->registerPost( 515, '' );
		$this->save( 515, $this->secret() );

		$query = $this->search();

		$this->assertTrue( $this->returns( $query, 515 ) );
		$this->assertSame( '', $query->get( 'meta_query' ), 'the query is untouched' );
	}

	#[Test]
	public function the_filter_turns_the_exclusion_off(): void {
		$this->registerPost( 516, '' );
		$this->save( 516, $this->secret() );
		Agend_Test_WP::set_filter( 'agend_content_access_search_excludes_block_rules', false );

		$query = $this->search();

		$this->assertTrue( $this->returns( $query, 516 ) );
		$this->assertSame( '', $query->get( 'meta_query' ) );
	}

	#[Test]
	public function the_filter_defaults_to_excluding_and_is_handed_the_query(): void {
		$seen = null;

		Agend_Test_WP::$filters['agend_content_access_search_excludes_block_rules'] = static function ( $exclude, $query ) use ( &$seen ) {
			$seen = array( $exclude, $query );

			return $exclude;
		};

		$query = $this->search();

		$this->assertTrue( $seen[0] );
		$this->assertSame( $query, $seen[1] );
		$this->assertNotSame( '', $query->get( 'meta_query' ) );
	}

	/** A query that searches for nothing is not a search. */
	#[Test]
	public function a_query_with_no_search_term_is_untouched(): void {
		foreach ( array( array(), array( 's' => '' ), array( 'post_type' => 'page' ), array( 's' => null ) ) as $vars ) {
			$query = $this->search( $vars );

			$this->assertSame( '', $query->get( 'meta_query' ), wp_json_encode( $vars ) );
		}
	}

	/** @return array<string, array{0: mixed}> */
	public static function odd_search_terms(): array {
		return array(
			'zero'           => array( '0' ),
			'a space'        => array( ' ' ),
			'a list'         => array( array( 'secret' ) ),
			'a number'       => array( 7 ),
			'a quoted phrase' => array( '"secret plan"' ),
		);
	}

	/** Anything that is not plainly empty may still be matched against the content. */
	#[Test]
	#[DataProvider( 'odd_search_terms' )]
	public function a_term_of_an_odd_kind_still_counts_as_a_search( $term ): void {
		$query = $this->search( array( 's' => $term ) );

		$this->assertNotSame( '', $query->get( 'meta_query' ) );
	}

	/** Something that is not a query is left alone. */
	#[Test]
	public function a_value_that_is_not_a_query_is_ignored(): void {
		$this->blocks->exclude_from_search( new \stdClass() );
		$this->blocks->exclude_from_search( null );

		$this->addToAssertionCount( 1 );
	}

	// -----------------------------------------------------------------------
	// Composing with a meta query that is already there
	// -----------------------------------------------------------------------

	/**
	 * The clause is wrapped in an AND, never appended. Appended to a top-level
	 * `OR` it would read "has a, or has b, or has no flag", which WIDENS the
	 * result, and a flagged post carrying `a` would be found again.
	 */
	#[Test]
	public function an_existing_or_meta_query_is_wrapped_and_not_widened(): void {
		$this->registerPost( 520, '' );
		$this->save( 520, $this->secret() );
		$GLOBALS['agend_test_post_meta'][ 520 ]['colour'] = 'red';

		$existing = array(
			'relation' => 'OR',
			array( 'key' => 'colour', 'compare' => 'EXISTS' ),
			array( 'key' => 'size', 'compare' => 'EXISTS' ),
		);

		$this->assertTrue( $this->queryMatches( $existing, 520 ), 'it matches the existing query on its own' );

		$query = $this->search( array( 's' => 'secret', 'meta_query' => $existing ) );

		$this->assertFalse( $this->returns( $query, 520 ), 'and is still excluded by the flag' );
		$this->assertSame( 'AND', $query->get( 'meta_query' )['relation'] );
		$this->assertSame( $existing, $query->get( 'meta_query' )[0] );
	}

	#[Test]
	public function an_existing_meta_query_still_applies_to_posts_that_are_not_flagged(): void {
		$this->registerPost( 521, '' );
		$this->registerPost( 522, '' );
		$GLOBALS['agend_test_post_meta'][ 521 ]['colour'] = 'red';

		$query = $this->search(
			array(
				's'          => 'plain',
				'meta_query' => array( array( 'key' => 'colour', 'compare' => 'EXISTS' ) ),
			)
		);

		$this->assertTrue( $this->returns( $query, 521 ) );
		$this->assertFalse( $this->returns( $query, 522 ), 'the caller\'s own filter is kept' );
	}

	/** A meta query that is not a list is ignored by WordPress, so it is replaced rather than nested. */
	#[Test]
	public function a_meta_query_that_is_not_an_array_is_replaced(): void {
		$query = $this->search( array( 's' => 'secret', 'meta_query' => 'junk' ) );

		$this->assertSame( array( array( 'key' => self::FLAG, 'compare' => 'NOT EXISTS' ) ), $query->get( 'meta_query' ) );
	}

	// -----------------------------------------------------------------------
	// Registration
	// -----------------------------------------------------------------------

	#[Test]
	public function the_hooks_are_registered(): void {
		$this->assertContains( array( $this->blocks, 'exclude_from_search' ), Agend_Test_WP::$actions['pre_get_posts'] );
		$this->assertContains( array( $this->blocks, 'sync_search_flag' ), Agend_Test_WP::$actions['save_post'] );

		$index = array_search( array( $this->blocks, 'sync_search_flag' ), Agend_Test_WP::$actions['save_post'], true );

		$this->assertSame( 20, Agend_Test_WP::$action_priorities['save_post'][ $index ] );
	}
}
