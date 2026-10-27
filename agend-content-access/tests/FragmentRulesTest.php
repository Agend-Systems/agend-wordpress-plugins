<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Elementor;
use Agend_Content_Access_Fragment_Rules;
use Agend_Content_Access_Frontend;
use Agend_Content_Access_Policy;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use WP_Post;
use WP_Query;

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
 * Which posts hold section-level rules.
 *
 * The raw body of such a post carries the text of every section, restricted ones
 * included, so the answer decides whether it may be searched, excerpted or
 * returned by the REST edit context. Wrongly answering "none" publishes a
 * section; wrongly answering "some" only costs a search result, so every doubt
 * resolves to "some".
 */
#[CoversClass( Agend_Content_Access_Fragment_Rules::class )]
#[CoversClass( Agend_Content_Access_Elementor::class )]
final class FragmentRulesTest extends TestCase {

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

	private function store( $value ): void {
		$GLOBALS['agend_test_post_meta'][ self::POST_ID ]['_elementor_data'] = $value;
	}

	// -----------------------------------------------------------------------
	// What counts as carrying rules
	// -----------------------------------------------------------------------

	#[Test]
	public function a_document_with_a_fragment_policy_carries_rules(): void {
		$this->store( (string) wp_json_encode( array( $this->node( array( 'agend_access_mode' => 'active_member' ) ) ) ) );

		$this->assertTrue( Agend_Content_Access_Fragment_Rules::carries( self::POST_ID ) );
	}

	#[Test]
	public function a_document_with_enabled_display_conditions_carries_rules(): void {
		$this->store( (string) wp_json_encode( array( $this->node( array( 'agend_conditions_enabled' => 'yes' ) ) ) ) );

		$this->assertTrue( Agend_Content_Access_Fragment_Rules::carries( self::POST_ID ) );
	}

	#[Test]
	public function a_rule_on_a_deeply_nested_widget_is_found(): void {
		$deep = $this->node(
			array(),
			array(
				$this->node(
					array(),
					array( $this->node( array( 'agend_access_mode' => 'selected_tiers', 'agend_access_tier_ids' => array() ), array(), 'widget' ) ),
					'column'
				),
			)
		);

		$this->store( (string) wp_json_encode( array( $this->node(), $deep ) ) );

		$this->assertTrue( Agend_Content_Access_Fragment_Rules::carries( self::POST_ID ), 'a later top level node, three levels down' );
	}

	#[Test]
	public function an_unrecognised_mode_still_counts_because_it_is_enforced_as_a_restriction(): void {
		$this->store( (string) wp_json_encode( array( $this->node( array( 'agend_access_mode' => 'something_new' ) ) ) ) );

		$this->assertTrue( Agend_Content_Access_Fragment_Rules::carries( self::POST_ID ) );
	}

	/** @return array<string, array{0: mixed}> */
	public static function documentsWithNoRules(): array {
		return array(
			'no document stored'                  => array( '' ),
			'an empty document'                   => array( '[]' ),
			'inherit, which is the explicit opt out' => array(
				(string) wp_json_encode( array( array( 'id' => 'a', 'elType' => 'section', 'settings' => array( 'agend_access_mode' => 'inherit' ), 'elements' => array() ) ) ),
			),
			'conditions switched off'             => array(
				(string) wp_json_encode( array( array( 'id' => 'a', 'elType' => 'section', 'settings' => array( 'agend_conditions_enabled' => '' ), 'elements' => array() ) ) ),
			),
			'already decoded, none'               => array( array( array( 'id' => 'a', 'elType' => 'section', 'settings' => array(), 'elements' => array() ) ) ),
		);
	}

	#[Test]
	#[DataProvider( 'documentsWithNoRules' )]
	public function a_document_with_no_rules_does_not_carry_any( $stored ): void {
		$this->store( $stored );

		$this->assertFalse( Agend_Content_Access_Fragment_Rules::carries( self::POST_ID ) );
	}

	#[Test]
	public function a_post_with_no_document_at_all_carries_none(): void {
		$this->assertFalse( Agend_Content_Access_Fragment_Rules::carries( self::POST_ID ) );
		$this->assertFalse( Agend_Content_Access_Fragment_Rules::carries( 0 ) );
	}

	#[Test]
	public function an_already_decoded_document_is_read_too(): void {
		$this->store( array( $this->node( array( 'agend_access_mode' => 'active_member' ) ) ) );

		$this->assertTrue( Agend_Content_Access_Fragment_Rules::carries( self::POST_ID ) );
	}

	// -----------------------------------------------------------------------
	// Fail closed
	// -----------------------------------------------------------------------

	/** @return array<string, array{0: mixed}> */
	public static function unreadableDocuments(): array {
		return array(
			'truncated json'   => array( '[{"id":"a","elType":"section","settings":{"agend_access_mode":' ),
			'not json at all'  => array( 'not a document' ),
			'a json scalar'    => array( '42' ),
			'json null'        => array( 'null' ),
			'a non array value' => array( 7 ),
		);
	}

	/**
	 * "I could not tell" is not "there are none". A document that is stored but
	 * unreadable is exactly where a rule could be hiding.
	 */
	#[Test]
	#[DataProvider( 'unreadableDocuments' )]
	public function a_stored_document_that_cannot_be_read_is_treated_as_carrying_rules( $stored ): void {
		$this->store( $stored );

		$this->assertTrue( Agend_Content_Access_Fragment_Rules::carries( self::POST_ID ) );
	}

	#[Test]
	public function a_post_object_is_accepted_as_well_as_an_id(): void {
		$GLOBALS['agend_test_posts'][ self::POST_ID ] = array( 'ID' => self::POST_ID, 'post_type' => 'post' );
		$this->store( (string) wp_json_encode( array( $this->node( array( 'agend_access_mode' => 'active_member' ) ) ) ) );

		$this->assertTrue( Agend_Content_Access_Fragment_Rules::carries( get_post( self::POST_ID ) ) );
	}

	// -----------------------------------------------------------------------
	// Other editors
	// -----------------------------------------------------------------------

	#[Test]
	public function another_editors_answer_can_widen_but_the_default_stands_when_there_is_none(): void {
		$this->assertFalse( Agend_Content_Access_Fragment_Rules::carries( self::POST_ID ) );

		Agend_Test_WP::set_filter( 'agend_content_access_post_carries_fragment_rules', true );

		$this->assertTrue( Agend_Content_Access_Fragment_Rules::carries( self::POST_ID ) );
	}

	#[Test]
	public function the_filter_is_handed_the_answer_so_far_and_the_post_id(): void {
		$seen = array();
		Agend_Test_WP::$filters['agend_content_access_post_carries_fragment_rules'] = static function ( $carries, $post_id ) use ( &$seen ) {
			$seen = array( $carries, $post_id );

			return $carries;
		};
		$this->store( (string) wp_json_encode( array( $this->node( array( 'agend_access_mode' => 'active_member' ) ) ) ) );

		Agend_Content_Access_Fragment_Rules::carries( self::POST_ID );

		$this->assertSame( array( true, self::POST_ID ), $seen );
	}
	// -----------------------------------------------------------------------
	// Fixtures for what follows
	// -----------------------------------------------------------------------

	private function withRules(): string {
		return (string) wp_json_encode( array( $this->node( array( 'agend_access_mode' => 'active_member' ) ) ) );
	}

	private function postWithRules( int $id, string $body = 'THE OPENING AND THE SECTION SECRET' ): void {
		$GLOBALS['agend_test_posts'][ $id ] = array(
			'ID'           => $id,
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_title'   => 'Page ' . $id,
			'post_content' => $body,
			'post_excerpt' => '',
		);

		$GLOBALS['agend_test_post_meta'][ $id ]['_elementor_data'] = $this->withRules();
	}

	private function flag( int $id ) {
		return $GLOBALS['agend_test_post_meta'][ $id ][ Agend_Content_Access_Fragment_Rules::META_KEY ] ?? null;
	}

	/** @return string[] Callback method names on a hook, "method@priority". */
	private function registrations( string $hook ): array {
		return array_map(
			static fn( array $entry ): string => ( is_array( $entry['callback'] ) ? $entry['callback'][1] : '(closure)' ) . '@' . $entry['priority'],
			Agend_Test_WP::$filter_registry[ $hook ] ?? array()
		);
	}

	// -----------------------------------------------------------------------
	// Registration
	// -----------------------------------------------------------------------

	#[Test]
	public function every_reader_is_hooked_at_the_priority_the_design_depends_on(): void {
		Agend_Content_Access_Fragment_Rules::init();

		$this->assertContains( 'filter_excerpt@11', $this->registrations( 'get_the_excerpt' ), 'after wp_trim_excerpt at 10' );
		$this->assertSame( 2, Agend_Test_WP::$filter_registry['get_the_excerpt'][0]['args'] );

		$this->assertSame( array( 'Agend_Content_Access_Fragment_Rules', 'exclude_from_search' ), Agend_Test_WP::$actions['pre_get_posts'][0] );
		$this->assertSame( array( PHP_INT_MAX ), Agend_Test_WP::$action_priorities['pre_get_posts'], 'last, so no other pre_get_posts can undo it' );

		$this->assertSame( array( 20 ), Agend_Test_WP::$action_priorities['save_post'] );
		$this->assertArrayHasKey( 'elementor/document/after_save', Agend_Test_WP::$actions );
		$this->assertArrayHasKey( 'admin_init', Agend_Test_WP::$actions );
		$this->assertArrayHasKey( 'updated_post_meta', Agend_Test_WP::$actions );
	}

	#[Test]
	public function the_flag_is_registered_protected_and_out_of_rest_for_every_supported_type(): void {
		Agend_Content_Access_Fragment_Rules::register_meta();

		foreach ( array( 'post', 'page' ) as $type ) {
			$args = $GLOBALS['agend_test_registered_post_meta'][ $type ][ Agend_Content_Access_Fragment_Rules::META_KEY ];

			$this->assertFalse( $args['show_in_rest'] );
			$this->assertTrue( $args['single'] );
			$this->assertSame( '__return_false', $args['auth_callback'], 'no request may clear it to bring a post back into search' );
		}

		$this->assertArrayNotHasKey( 'attachment', $GLOBALS['agend_test_registered_post_meta'] );
		$this->assertTrue( Agend_Content_Access_Fragment_Rules::protect_meta( false, Agend_Content_Access_Fragment_Rules::META_KEY ) );
		$this->assertFalse( Agend_Content_Access_Fragment_Rules::protect_meta( false, 'some_other_key' ) );
		$this->assertTrue( Agend_Content_Access_Fragment_Rules::protect_meta( true, 'some_other_key' ) );
	}

	// -----------------------------------------------------------------------
	// The flag
	// -----------------------------------------------------------------------

	#[Test]
	public function saving_a_post_that_carries_rules_flags_it(): void {
		$this->postWithRules( self::POST_ID );

		Agend_Content_Access_Fragment_Rules::sync_on_save( self::POST_ID );

		$this->assertSame( '1', $this->flag( self::POST_ID ) );
	}

	#[Test]
	public function saving_after_the_last_rule_is_removed_clears_the_flag(): void {
		$this->postWithRules( self::POST_ID );
		Agend_Content_Access_Fragment_Rules::sync_on_save( self::POST_ID );

		$this->store( (string) wp_json_encode( array( $this->node() ) ) );
		Agend_Content_Access_Fragment_Rules::sync_on_save( self::POST_ID );

		$this->assertNull( $this->flag( self::POST_ID ) );
		$this->assertArrayNotHasKey( Agend_Content_Access_Fragment_Rules::META_KEY, $GLOBALS['agend_test_post_meta'][ self::POST_ID ] );
	}

	#[Test]
	public function a_post_with_no_rules_is_never_flagged_and_nothing_is_written_for_it(): void {
		$this->store( (string) wp_json_encode( array( $this->node() ) ) );

		Agend_Content_Access_Fragment_Rules::sync_on_save( self::POST_ID );

		$this->assertNull( $this->flag( self::POST_ID ) );
	}

	#[Test]
	public function a_revision_is_not_flagged(): void {
		$this->postWithRules( self::POST_ID );
		$GLOBALS['agend_test_revisions'][ self::POST_ID ] = true;

		Agend_Content_Access_Fragment_Rules::sync_on_save( self::POST_ID );

		$this->assertNull( $this->flag( self::POST_ID ) );
	}

	#[Test]
	public function an_autosave_is_not_flagged(): void {
		$this->postWithRules( self::POST_ID );
		$GLOBALS['agend_test_autosaves'][ self::POST_ID ] = true;

		Agend_Content_Access_Fragment_Rules::sync_on_save( self::POST_ID );

		$this->assertNull( $this->flag( self::POST_ID ) );
	}

	/**
	 * Elementor fires `save_post` from its settings save, before the document is
	 * written, so that pass sees the old document. The flag has to follow its own
	 * save action.
	 */
	#[Test]
	public function an_elementor_save_flags_the_post_once_the_document_is_written(): void {
		$GLOBALS['agend_test_post_meta'][ self::POST_ID ]['_elementor_data'] = '[]';
		Agend_Content_Access_Fragment_Rules::sync_on_save( self::POST_ID );
		$this->assertNull( $this->flag( self::POST_ID ), 'the save_post pass saw the document before the restricted section was added' );

		$this->store( $this->withRules() );
		Agend_Content_Access_Fragment_Rules::sync_on_elementor_save(
			new class( self::POST_ID ) {
				private int $id;

				public function __construct( int $id ) {
					$this->id = $id;
				}

				public function get_main_id(): int {
					return $this->id;
				}
			}
		);

		$this->assertSame( '1', $this->flag( self::POST_ID ) );
	}

	#[Test]
	public function an_elementor_save_that_hands_over_something_unexpected_does_nothing(): void {
		$this->store( $this->withRules() );

		Agend_Content_Access_Fragment_Rules::sync_on_elementor_save( 'not a document' );
		Agend_Content_Access_Fragment_Rules::sync_on_elementor_save( new \stdClass() );
		Agend_Content_Access_Fragment_Rules::sync_on_elementor_save( null );

		$this->assertNull( $this->flag( self::POST_ID ) );
	}

	#[Test]
	public function a_document_written_without_a_save_is_picked_up_from_the_meta_change(): void {
		$this->store( $this->withRules() );

		Agend_Content_Access_Fragment_Rules::sync_on_meta_change( 1, self::POST_ID, '_some_other_key' );
		$this->assertNull( $this->flag( self::POST_ID ), 'only the document key matters' );

		Agend_Content_Access_Fragment_Rules::sync_on_meta_change( 1, self::POST_ID, '_elementor_data' );
		$this->assertSame( '1', $this->flag( self::POST_ID ) );
	}

	// -----------------------------------------------------------------------
	// Search
	// -----------------------------------------------------------------------

	private function searchGuard(): array {
		return array( 'key' => Agend_Content_Access_Fragment_Rules::META_KEY, 'compare' => 'NOT EXISTS' );
	}

	/** Somebody who cannot read the raw body of everyone's posts. */
	private function asVisitorWhoCannotEditOthers(): void {
		$GLOBALS['agend_test_current_user_can']['edit_others_posts'] = false;
	}

	#[Test]
	public function a_search_by_a_visitor_requires_the_flag_to_be_absent(): void {
		$this->asVisitorWhoCannotEditOthers();
		$query = new WP_Query( array( 's' => 'secret' ) );

		Agend_Content_Access_Fragment_Rules::exclude_from_search( $query );

		$this->assertSame( array( $this->searchGuard() ), $query->get( 'meta_query' ) );
	}

	/** A theme's own meta query becomes one clause inside an AND, so it cannot widen the guard. */
	#[Test]
	public function an_existing_meta_query_is_wrapped_not_replaced_or_extended(): void {
		$this->asVisitorWhoCannotEditOthers();
		$theirs = array(
			'relation' => 'OR',
			array( 'key' => 'featured', 'value' => '1' ),
			array( 'key' => 'promoted', 'value' => '1' ),
		);
		$query  = new WP_Query( array( 's' => 'secret', 'meta_query' => $theirs ) );

		Agend_Content_Access_Fragment_Rules::exclude_from_search( $query );

		$this->assertSame( array( 'relation' => 'AND', $this->searchGuard(), $theirs ), $query->get( 'meta_query' ) );
	}

	#[Test]
	public function a_query_with_no_search_term_is_left_alone(): void {
		$this->asVisitorWhoCannotEditOthers();

		foreach ( array( array(), array( 's' => '' ), array( 's' => '   ' ), array( 's' => array() ) ) as $vars ) {
			$query = new WP_Query( $vars );

			Agend_Content_Access_Fragment_Rules::exclude_from_search( $query );

			$this->assertSame( '', $query->get( 'meta_query' ), 'untouched' );
		}
	}

	#[Test]
	public function a_search_term_in_any_shape_counts(): void {
		$this->asVisitorWhoCannotEditOthers();

		foreach ( array( '0', 'a', array( 'a' ) ) as $term ) {
			$query = new WP_Query( array( 's' => $term ) );

			Agend_Content_Access_Fragment_Rules::exclude_from_search( $query );

			$this->assertSame( array( $this->searchGuard() ), $query->get( 'meta_query' ), is_array( $term ) ? 'array' : $term );
		}
	}

	#[Test]
	public function somebody_who_can_edit_others_posts_searches_everything(): void {
		$GLOBALS['agend_test_current_user_can']['edit_others_posts'] = true;
		$query = new WP_Query( array( 's' => 'secret' ) );

		Agend_Content_Access_Fragment_Rules::exclude_from_search( $query );

		$this->assertSame( '', $query->get( 'meta_query' ) );
	}

	#[Test]
	public function a_site_can_choose_to_leave_such_posts_searchable(): void {
		$this->asVisitorWhoCannotEditOthers();
		Agend_Test_WP::set_filter( 'agend_content_access_search_excludes_fragment_rules', false );
		$query = new WP_Query( array( 's' => 'secret' ) );

		Agend_Content_Access_Fragment_Rules::exclude_from_search( $query );

		$this->assertSame( '', $query->get( 'meta_query' ) );
	}

	#[Test]
	public function the_exclusion_is_on_by_default(): void {
		$this->asVisitorWhoCannotEditOthers();
		$seen = null;
		Agend_Test_WP::$filters['agend_content_access_search_excludes_fragment_rules'] = static function ( $excludes ) use ( &$seen ) {
			$seen = $excludes;

			return $excludes;
		};

		Agend_Content_Access_Fragment_Rules::exclude_from_search( new WP_Query( array( 's' => 'secret' ) ) );

		$this->assertTrue( $seen );
	}

	#[Test]
	public function something_that_is_not_a_query_is_ignored(): void {
		$this->asVisitorWhoCannotEditOthers();

		Agend_Content_Access_Fragment_Rules::exclude_from_search( null );
		Agend_Content_Access_Fragment_Rules::exclude_from_search( new \stdClass() );

		$this->addToAssertionCount( 1 );
	}

	// -----------------------------------------------------------------------
	// Excerpts
	// -----------------------------------------------------------------------

	/** Core's `wp_trim_excerpt()` at 10, the filter this one has to follow. */
	private function installCoreTrim(): void {
		add_filter(
			'get_the_excerpt',
			static function ( $excerpt, $post = null ) {
				return '' !== trim( (string) $excerpt ) || ! $post instanceof WP_Post ? $excerpt : $post->post_content;
			},
			10,
			2
		);
	}

	private function excerptOf( int $id ): string {
		$post = get_post( $id );

		return (string) Agend_Test_WP::apply_registered( 'get_the_excerpt', (string) $post->post_excerpt, $post );
	}

	#[Test]
	public function an_auto_excerpt_of_an_elementor_post_with_rules_is_empty(): void {
		$this->postWithRules( self::POST_ID );
		Agend_Content_Access_Fragment_Rules::init();
		$this->installCoreTrim();

		$this->assertSame( '', $this->excerptOf( self::POST_ID ), 'the opening of the body would carry the restricted section' );
	}

	/** The live check, not the flag: a post saved before the flag existed is covered. */
	#[Test]
	public function the_excerpt_does_not_depend_on_the_flag(): void {
		$this->postWithRules( self::POST_ID );
		Agend_Content_Access_Fragment_Rules::init();
		$this->installCoreTrim();

		$this->assertNull( $this->flag( self::POST_ID ) );
		$this->assertSame( '', $this->excerptOf( self::POST_ID ) );
	}

	#[Test]
	public function a_written_excerpt_is_left_alone(): void {
		$this->postWithRules( self::POST_ID );
		$GLOBALS['agend_test_posts'][ self::POST_ID ]['post_excerpt'] = 'A summary the editor wrote.';
		Agend_Content_Access_Fragment_Rules::init();
		$this->installCoreTrim();

		$this->assertSame( 'A summary the editor wrote.', $this->excerptOf( self::POST_ID ) );
	}

	#[Test]
	public function a_post_with_no_rules_keeps_its_auto_excerpt(): void {
		$GLOBALS['agend_test_posts'][ self::POST_ID ] = array( 'ID' => self::POST_ID, 'post_type' => 'page', 'post_content' => 'AN ORDINARY BODY', 'post_excerpt' => '' );
		$this->store( (string) wp_json_encode( array( $this->node() ) ) );
		Agend_Content_Access_Fragment_Rules::init();
		$this->installCoreTrim();

		$this->assertSame( 'AN ORDINARY BODY', $this->excerptOf( self::POST_ID ) );
	}

	/** The seam the block editor integration supplies a rebuilt excerpt through. */
	#[Test]
	public function a_document_in_another_format_may_supply_a_safe_excerpt(): void {
		$GLOBALS['agend_test_posts'][ self::POST_ID ] = array( 'ID' => self::POST_ID, 'post_type' => 'page', 'post_content' => 'RAW BLOCKS WITH A SECTION SECRET', 'post_excerpt' => '' );
		Agend_Test_WP::set_filter( 'agend_content_access_post_carries_fragment_rules', true );
		$handed = array();
		Agend_Test_WP::$filters['agend_content_access_safe_generated_excerpt'] = static function ( $safe, $post ) use ( &$handed ) {
			$handed = array( $safe, $post->ID );

			return 'THE PART THIS VISITOR MAY READ';
		};
		Agend_Content_Access_Fragment_Rules::init();
		$this->installCoreTrim();

		$this->assertSame( 'THE PART THIS VISITOR MAY READ', $this->excerptOf( self::POST_ID ) );
		$this->assertSame( array( '', self::POST_ID ), $handed, 'handed an empty string and the post' );
	}

	#[Test]
	public function with_nobody_to_supply_one_a_post_in_another_format_gets_none(): void {
		$GLOBALS['agend_test_posts'][ self::POST_ID ] = array( 'ID' => self::POST_ID, 'post_type' => 'page', 'post_content' => 'RAW BLOCKS WITH A SECTION SECRET', 'post_excerpt' => '' );
		Agend_Test_WP::set_filter( 'agend_content_access_post_carries_fragment_rules', true );
		Agend_Content_Access_Fragment_Rules::init();
		$this->installCoreTrim();

		$this->assertSame( '', $this->excerptOf( self::POST_ID ) );
	}

	#[Test]
	public function an_excerpt_that_is_not_a_string_is_discarded(): void {
		$GLOBALS['agend_test_posts'][ self::POST_ID ] = array( 'ID' => self::POST_ID, 'post_type' => 'page', 'post_content' => 'RAW', 'post_excerpt' => '' );
		Agend_Test_WP::set_filter( 'agend_content_access_post_carries_fragment_rules', true );
		Agend_Test_WP::set_filter( 'agend_content_access_safe_generated_excerpt', array( 'x' ) );

		$this->assertSame( '', Agend_Content_Access_Fragment_Rules::filter_excerpt( 'RAW', get_post( self::POST_ID ) ) );
	}

	/** An Elementor document cannot be rebuilt: its stored text is every section's. */
	#[Test]
	public function an_elementor_document_is_not_given_a_rebuilt_excerpt_even_if_asked(): void {
		$this->postWithRules( self::POST_ID );
		Agend_Test_WP::set_filter( 'agend_content_access_safe_generated_excerpt', 'SUPPLIED' );

		$this->assertSame( '', Agend_Content_Access_Fragment_Rules::filter_excerpt( 'RAW', get_post( self::POST_ID ) ) );
	}

	#[Test]
	public function a_wp_admin_screen_sees_the_excerpt_as_core_built_it(): void {
		$this->postWithRules( self::POST_ID );
		$GLOBALS['agend_test_is_admin'] = true;

		$this->assertSame( 'RAW', Agend_Content_Access_Fragment_Rules::filter_excerpt( 'RAW', get_post( self::POST_ID ) ) );
	}

	#[Test]
	public function an_excerpt_asked_for_without_a_post_is_left_alone(): void {
		$this->assertSame( 'RAW', Agend_Content_Access_Fragment_Rules::filter_excerpt( 'RAW' ) );
		$this->assertSame( 'RAW', Agend_Content_Access_Fragment_Rules::filter_excerpt( 'RAW', self::POST_ID ) );
	}

	/** A post with an audience AND section rules: this filter blanks it, and the gate still has the last word. */
	#[Test]
	public function the_gate_for_a_restricted_post_still_runs_after_this_filter(): void {
		$this->postWithRules( self::POST_ID );
		$GLOBALS['agend_test_posts'][ self::POST_ID ]['post_excerpt']                       = 'A public teaser.';
		$GLOBALS['agend_test_post_meta'][ self::POST_ID ][ Agend_Content_Access_Policy::META_KEY ] = array( 'mode' => 'active_member' );
		$GLOBALS['agend_test_current_user_can']['edit_post']                                = false;
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', '' );

		new Agend_Content_Access_Frontend();
		Agend_Content_Access_Fragment_Rules::init();
		$this->installCoreTrim();

		$this->assertSame( 'A public teaser.', $this->excerptOf( self::POST_ID ) );
	}

	// -----------------------------------------------------------------------
	// Backfill
	// -----------------------------------------------------------------------

	/** @return int[] Ids a fetcher serves, ascending, after a given id. */
	private function ids( int ...$all ): callable {
		return static fn( int $after, int $limit ): array => array_slice( array_values( array_filter( $all, static fn( int $id ): bool => $id > $after ) ), 0, $limit );
	}

	#[Test]
	public function a_batch_flags_the_posts_that_carry_rules_and_reports_where_it_got_to(): void {
		$this->postWithRules( 10 );
		$this->postWithRules( 30 );
		$GLOBALS['agend_test_post_meta'][ 20 ]['_elementor_data'] = '[]';

		$result = Agend_Content_Access_Fragment_Rules::backfill_batch( 0, 100, $this->ids( 10, 20, 30 ) );

		$this->assertSame( '1', $this->flag( 10 ) );
		$this->assertNull( $this->flag( 20 ) );
		$this->assertSame( '1', $this->flag( 30 ) );
		$this->assertSame( array( 'processed' => 3, 'flagged' => 2, 'last_id' => 30, 'done' => true ), $result );
	}

	#[Test]
	public function a_full_batch_is_not_the_end_and_the_next_one_starts_after_it(): void {
		$this->postWithRules( 10 );
		$this->postWithRules( 20 );
		$this->postWithRules( 30 );
		$fetch = $this->ids( 10, 20, 30 );

		$first  = Agend_Content_Access_Fragment_Rules::backfill_batch( 0, 2, $fetch );
		$second = Agend_Content_Access_Fragment_Rules::backfill_batch( $first['last_id'], 2, $fetch );

		$this->assertFalse( $first['done'] );
		$this->assertSame( 20, $first['last_id'] );
		$this->assertSame( array( 'processed' => 1, 'flagged' => 1, 'last_id' => 30, 'done' => true ), $second );
	}

	#[Test]
	public function the_backfill_also_clears_a_flag_that_is_no_longer_right(): void {
		$GLOBALS['agend_test_post_meta'][ 10 ][ Agend_Content_Access_Fragment_Rules::META_KEY ] = '1';
		$GLOBALS['agend_test_post_meta'][ 10 ]['_elementor_data']                               = '[]';

		Agend_Content_Access_Fragment_Rules::backfill_batch( 0, 100, $this->ids( 10 ) );

		$this->assertNull( $this->flag( 10 ) );
	}

	#[Test]
	public function an_empty_table_is_done_at_once(): void {
		$this->assertSame(
			array( 'processed' => 0, 'flagged' => 0, 'last_id' => 0, 'done' => true ),
			Agend_Content_Access_Fragment_Rules::backfill_batch( 0, 100, $this->ids() )
		);
	}

	#[Test]
	public function the_posts_are_read_in_id_order_after_the_last_one_with_the_limit_bound(): void {
		$db      = new PostsTableDouble();
		$db->ids = array( 5, 7, 9, 11 );
		$GLOBALS['wpdb'] = $db;

		$ids = Agend_Content_Access_Fragment_Rules::post_ids_after( 5, 2 );

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
		$this->postWithRules( 40 );

		Agend_Content_Access_Fragment_Rules::maybe_backfill();

		$this->assertSame( array( 'last_id' => 100, 'done' => false ), Agend_Content_Access_Fragment_Rules::backfill_state() );
		$this->assertSame( '1', $this->flag( 40 ) );
	}

	#[Test]
	public function the_one_off_pass_converges_over_successive_page_loads_and_then_stops(): void {
		$this->asAdministrator();
		$db = $this->table( ...range( 1, 250 ) );
		$this->postWithRules( 240 );

		foreach ( range( 1, 3 ) as $_ ) {
			Agend_Content_Access_Fragment_Rules::maybe_backfill();
		}

		$this->assertSame( array( 'last_id' => 250, 'done' => true ), Agend_Content_Access_Fragment_Rules::backfill_state() );
		$this->assertSame( '1', $this->flag( 240 ), 'a post saved before the flag existed is reached without anyone running anything' );
		$this->assertCount( 3, $db->queries );

		Agend_Content_Access_Fragment_Rules::maybe_backfill();

		$this->assertCount( 3, $db->queries, 'once done it costs nothing' );
	}

	#[Test]
	public function the_one_off_pass_is_for_people_who_can_manage_options_only(): void {
		$GLOBALS['agend_test_current_user_can']['manage_options'] = false;
		$db = $this->table( 1, 2, 3 );

		Agend_Content_Access_Fragment_Rules::maybe_backfill();

		$this->assertSame( array(), $db->queries );
		$this->assertSame( array( 'last_id' => 0, 'done' => false ), Agend_Content_Access_Fragment_Rules::backfill_state() );
	}

	#[Test]
	public function the_one_off_pass_does_not_run_on_a_heartbeat(): void {
		$this->asAdministrator();
		$GLOBALS['agend_test_doing_ajax'] = true;
		$db                               = $this->table( 1, 2, 3 );

		Agend_Content_Access_Fragment_Rules::maybe_backfill();

		$this->assertSame( array(), $db->queries );
	}

	#[Test]
	public function an_unreadable_progress_record_starts_again_rather_than_skipping_posts(): void {
		Agend_Test_WP::$options[ Agend_Content_Access_Fragment_Rules::BACKFILL_OPTION ] = 'garbage';

		$this->assertSame( array( 'last_id' => 0, 'done' => false ), Agend_Content_Access_Fragment_Rules::backfill_state() );

		Agend_Test_WP::$options[ Agend_Content_Access_Fragment_Rules::BACKFILL_OPTION ] = array( 'last_id' => -5, 'done' => 0 );

		$this->assertSame( array( 'last_id' => 0, 'done' => false ), Agend_Content_Access_Fragment_Rules::backfill_state() );
	}

	// The command.

	#[Test]
	public function the_command_runs_every_batch_from_the_start_and_marks_the_one_off_done(): void {
		$this->postWithRules( 10 );
		$this->postWithRules( 30 );
		$passes = array();

		$total = Agend_Content_Access_Fragment_Rules::backfill_all(
			2,
			static function ( array $pass ) use ( &$passes ): void {
				$passes[] = $pass['last_id'];
			},
			$this->ids( 10, 20, 30 )
		);

		$this->assertSame( array( 'processed' => 3, 'flagged' => 2 ), $total );
		$this->assertSame( array( 20, 30 ), $passes );
		$this->assertSame( array( 'last_id' => 30, 'done' => true ), Agend_Content_Access_Fragment_Rules::backfill_state() );
	}

	#[Test]
	public function the_command_starts_from_the_first_post_even_when_the_one_off_has_finished(): void {
		Agend_Test_WP::$options[ Agend_Content_Access_Fragment_Rules::BACKFILL_OPTION ] = array( 'last_id' => 999, 'done' => true );
		$this->postWithRules( 10 );

		Agend_Content_Access_Fragment_Rules::backfill_all( 100, null, $this->ids( 10 ) );

		$this->assertSame( '1', $this->flag( 10 ) );
	}
}
