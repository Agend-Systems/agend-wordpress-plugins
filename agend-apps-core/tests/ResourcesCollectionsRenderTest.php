<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend_Apps_Records_Record_Context;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

require_once __DIR__ . '/fixtures/resources-fixtures.php';

/**
 * The resource collections surface: what it publishes (only what an editor
 * chose, and only the parts meant to be public), what it never draws, what a
 * signed-in member gets added after the page loads, and what it does when
 * there is nothing to draw.
 *
 * The page is the same for every viewer, so a full-page cache can hold it: the
 * tests render it with and without a member signed in and compare.
 */
final class ResourcesCollectionsRenderTest extends TestCase {

	private const A = '09600000-0000-0000-0004-000000000001';
	private const B = '09600000-0000-0000-0004-000000000002';
	private const C = '09600000-0000-0000-0004-000000000003';

	protected function setUp(): void {
		parent::setUp();
		agend_res_test_reset();
	}

	/**
	 * Renders the surface from the defaults plus changes.
	 *
	 * @param array $overrides Settings to change.
	 * @param array $opts      Render opts.
	 * @return string
	 */
	private function render( array $overrides = array(), array $opts = array() ): string {
		return agend_apps_records_render_resources_collections( agend_res_test_settings( 'resources-collections', $overrides ), $opts );
	}

	/** Three collections, with the gateway's answer queued, and the first two chosen. */
	private function seed(): array {
		agend_res_test_queue_collections(
			array(
				agend_res_test_collection( self::A, array( 'title' => 'New Member Starter Pack', 'description' => 'Five practical pages.', 'topics' => array( agend_res_test_topic( 'Onboarding' ), agend_res_test_topic( 'Conduct' ) ) ) ),
				agend_res_test_collection( self::B, array( 'title' => 'Professional Development Toolkit', 'description' => 'Six linked resources.' ) ),
				agend_res_test_collection( self::C, array( 'title' => 'Staff Only Notes', 'description' => 'Not for the website.' ) ),
			)
		);

		return array( 'collection_ids' => array( self::A, self::B ) );
	}

	/** @return string[] The titles drawn, in order. */
	private function titles( string $html ): array {
		preg_match_all( '#<h3 class="agend-resources-collections__name">([^<]*)</h3>#', $html, $m );

		return array_map( 'html_entity_decode', $m[1] );
	}

	// -----------------------------------------------------------------
	// Config
	// -----------------------------------------------------------------

	#[Test]
	public function should_default_to_six_in_three_columns_with_everything_on_and_members_added_below(): void {
		$config = agend_apps_records_resources_collections_build_config( array() );

		self::assertSame( array(), $config['ids'], 'nothing is published until an editor chooses' );
		self::assertSame( 6, $config['max'] );
		self::assertSame( 3, $config['layout']['desktop'] );
		self::assertSame( 2, $config['layout']['tablet'] );
		self::assertSame( 1, $config['layout']['mobile'] );
		self::assertTrue( $config['card']['description'] );
		self::assertTrue( $config['card']['topics'] );
		self::assertTrue( $config['members']['enabled'] );
		self::assertFalse( $config['members']['replace'] );
		self::assertSame( 4, $config['members']['pages'] );
	}

	#[Test]
	public function should_keep_every_number_within_its_bounds(): void {
		$config = agend_apps_records_resources_collections_build_config(
			array(
				'max_collections'    => 500,
				'columns_desktop'    => '9',
				'columns_tablet'     => '0',
				'columns_mobile'     => '9',
				'card_radius'        => 99,
				'excerpt_length'     => 5,
				'member_pages_shown' => 99,
			)
		);

		self::assertSame( AGEND_APPS_RECORDS_RESOURCES_COLLECTIONS_MAX, $config['max'] );
		self::assertSame( 4, $config['layout']['desktop'] );
		self::assertSame( 1, $config['layout']['tablet'] );
		self::assertSame( 2, $config['layout']['mobile'] );
		self::assertSame( 48, $config['layout']['radius'] );
		self::assertSame( 20, $config['card']['excerptLength'] );
		self::assertSame( 12, $config['members']['pages'] );
		self::assertSame( 1, agend_apps_records_resources_collections_build_config( array( 'max_collections' => 0 ) )['max'] );
		self::assertSame( 0, agend_apps_records_resources_collections_build_config( array( 'member_pages_shown' => -3 ) )['members']['pages'] );
	}

	#[Test]
	public function should_read_toggles_stored_as_yes_or_empty(): void {
		$config = agend_apps_records_resources_collections_build_config(
			array(
				'show_heading'            => '',
				'show_description'        => '',
				'show_topics'             => '',
				'show_member_collections' => '',
				'member_view'             => 'replace',
			)
		);

		self::assertFalse( $config['heading']['show'] );
		self::assertFalse( $config['card']['description'] );
		self::assertFalse( $config['card']['topics'] );
		self::assertFalse( $config['members']['enabled'] );
		self::assertTrue( $config['members']['replace'] );
	}

	#[Test]
	public function should_clean_the_chosen_ids_and_hold_them_to_the_limit(): void {
		$ids    = array_merge( array( 'a', 'a', '<b>', '' ), array_map( static fn( int $n ): string => 'id' . $n, range( 1, 40 ) ) );
		$config = agend_apps_records_resources_collections_build_config( array( 'collection_ids' => $ids ) );

		self::assertCount( AGEND_APPS_RECORDS_RESOURCES_COLLECTIONS_MAX, $config['ids'] );
		self::assertSame( array( 'a', 'b', 'id1' ), array_slice( $config['ids'], 0, 3 ) );
	}

	#[Test]
	public function should_take_only_web_addresses_for_the_visitor_button(): void {
		self::assertSame( '', agend_apps_records_resources_collections_build_config( array( 'visitor_cta_url' => 'javascript:alert(1)' ) )['visitor']['ctaUrl'] );
		self::assertSame( 'https://example.test/join', agend_apps_records_resources_collections_build_config( array( 'visitor_cta_url' => array( 'url' => 'https://example.test/join' ) ) )['visitor']['ctaUrl'] );
	}

	// -----------------------------------------------------------------
	// What is published
	// -----------------------------------------------------------------

	#[Test]
	public function should_draw_only_the_collections_chosen_in_the_order_chosen(): void {
		$seed = $this->seed();

		$html = $this->render( array( 'collection_ids' => array( self::B, self::A ) ) + $seed );

		self::assertSame( array( 'Professional Development Toolkit', 'New Member Starter Pack' ), $this->titles( $html ) );
		self::assertStringNotContainsString( 'Staff Only Notes', $html, 'a collection nobody chose is never drawn' );
		self::assertStringNotContainsString( 'Not for the website.', $html );
	}

	#[Test]
	public function should_publish_the_title_the_description_and_the_topics_and_nothing_else(): void {
		$html = $this->render( $this->seed() );

		self::assertStringContainsString( 'New Member Starter Pack', $html );
		self::assertStringContainsString( 'Five practical pages.', $html );
		self::assertStringContainsString( 'Onboarding', $html );
		self::assertStringContainsString( 'Conduct', $html );

		// The item count and the dates are the parts that give away how much a
		// members-only collection holds, so they never reach the page.
		foreach ( array( '4721', '2026-03-05', '2026-06-01', 'item_count', 'created_at', self::A, self::B ) as $private ) {
			self::assertStringNotContainsString( $private, $html, $private );
		}
	}

	#[Test]
	public function should_draw_a_card_as_a_plain_article_with_no_link(): void {
		$html = $this->render( $this->seed() + array( 'visitor_cta_url' => '' ) );

		self::assertSame( 2, substr_count( $html, '<article class="agend-resources-collections__card">' ) );
		self::assertStringNotContainsString( '<a ', $html, 'a collection has no page of its own to open' );
	}

	#[Test]
	public function should_leave_out_a_chosen_collection_that_no_longer_exists(): void {
		$seed = $this->seed();

		$html = $this->render( array( 'collection_ids' => array( 'gone', self::A ) ) + $seed );

		self::assertSame( array( 'New Member Starter Pack' ), $this->titles( $html ) );
	}

	#[Test]
	public function should_hold_the_chosen_list_to_the_number_asked_for(): void {
		$seed = $this->seed();

		$html = $this->render( array( 'max_collections' => 1 ) + $seed );

		self::assertSame( array( 'New Member Starter Pack' ), $this->titles( $html ) );
	}

	#[Test]
	public function should_escape_everything_the_gateway_sends(): void {
		agend_res_test_queue_collections(
			array(
				agend_res_test_collection(
					self::A,
					array(
						'title'       => 'Fish & <i>Chips</i> "x"',
						'description' => '<script>alert(1)</script>Plain <b>text</b> & more',
						'topics'      => array( agend_res_test_topic( '<img src=x onerror=alert(1)>' ) ),
					)
				),
			)
		);

		$html = $this->render( array( 'collection_ids' => array( self::A ) ) );

		self::assertStringContainsString( 'Fish &amp; Chips', $html );
		self::assertStringNotContainsString( '<script>', $html );
		self::assertStringNotContainsString( '<img', $html );
		self::assertStringNotContainsString( '<b>', $html );
		self::assertStringContainsString( 'Plain text &amp; more', $html );
	}

	#[Test]
	public function should_cut_a_long_description_at_a_word(): void {
		agend_res_test_queue_collections( array( agend_res_test_collection( self::A, array( 'description' => str_repeat( 'word ', 100 ) ) ) ) );

		$html = $this->render( array( 'collection_ids' => array( self::A ), 'excerpt_length' => 40 ) );

		self::assertMatchesRegularExpression( '#<p class="agend-resources-collections__description">(word ){7}word…</p>#', $html );
	}

	#[Test]
	public function should_leave_out_whatever_the_card_settings_switch_off(): void {
		$html = $this->render( $this->seed() + array( 'show_description' => '', 'show_topics' => '' ) );

		self::assertStringNotContainsString( 'Five practical pages.', $html );
		self::assertStringNotContainsString( 'agend-resources-collections__description', $html );
		self::assertStringNotContainsString( 'Onboarding', $html );
		self::assertStringNotContainsString( 'agend-resources-collections__topics', $html );
	}

	// -----------------------------------------------------------------
	// The card foot: Members only, and the number of pages
	// -----------------------------------------------------------------

	#[Test]
	public function should_label_every_card_members_only_by_default_with_no_page_count(): void {
		$config = agend_apps_records_resources_collections_build_config( array() );

		self::assertTrue( $config['card']['membersOnly'] );
		self::assertFalse( $config['card']['pageCount'] );

		$html = $this->render( $this->seed() );

		self::assertSame( 2, substr_count( $html, '<span class="agend-resources-collections__members-only">' ) );
		self::assertSame( 2, substr_count( $html, '<div class="agend-resources-collections__foot">' ) );
		self::assertMatchesRegularExpression( '#<span class="agend-resources-collections__members-only"><svg class="agend-resources-collections__lock"[^>]*stroke="currentColor"[^>]*aria-hidden="true"[^>]*>.*?</svg><span>Members only</span></span>#s', $html );
		self::assertStringNotContainsString( 'agend-resources-collections__count', $html );
		self::assertStringNotContainsString( '4721', $html );
		self::assertStringNotContainsString( '4,721', $html );
	}

	#[Test]
	public function should_leave_the_foot_out_altogether_when_both_labels_are_off(): void {
		$html = $this->render( $this->seed() + array( 'show_members_only' => '' ) );

		self::assertStringNotContainsString( 'members-only', $html );
		self::assertStringNotContainsString( 'agend-resources-collections__foot', $html );
		self::assertStringNotContainsString( 'Members only', $html );
		self::assertStringContainsString( 'Members can open these collections after signing in.', $html, 'the note under the grid is separate' );
	}

	#[Test]
	public function should_show_the_number_of_pages_only_when_asked_and_say_one_page_in_the_singular(): void {
		agend_res_test_queue_collections(
			array(
				agend_res_test_collection( self::A, array( 'title' => 'Starter', 'item_count' => 5 ) ),
				agend_res_test_collection( self::B, array( 'title' => 'Single', 'item_count' => 1 ) ),
				agend_res_test_collection( self::C, array( 'title' => 'Empty', 'item_count' => 0 ) ),
			)
		);

		$html = $this->render( array( 'collection_ids' => array( self::A, self::B, self::C ), 'show_page_count' => 'yes' ) );

		self::assertStringContainsString( '<span class="agend-resources-collections__count">5 pages</span>', $html );
		self::assertStringContainsString( '<span class="agend-resources-collections__count">1 page</span>', $html );
		self::assertSame( 2, substr_count( $html, 'agend-resources-collections__count' ), 'a collection with no pages has nothing to count' );
	}

	#[Test]
	public function should_read_the_item_count_only_when_the_setting_is_on(): void {
		$row = agend_res_test_collection( self::A, array( 'item_count' => '12' ) );

		self::assertNull( agend_apps_records_resources_collections_normalise( $row )['count'], 'dropped by default' );
		self::assertSame( 12, agend_apps_records_resources_collections_normalise( $row, true )['count'] );
		self::assertNull( agend_apps_records_resources_collections_normalise( agend_res_test_collection( self::A, array( 'item_count' => 'lots' ) ), true )['count'] );
		self::assertNull( agend_apps_records_resources_collections_normalise( array( 'id' => self::A, 'title' => 'No count' ), true )['count'], 'the read may not return one' );
		self::assertSame( 0, agend_apps_records_resources_collections_normalise( agend_res_test_collection( self::A, array( 'item_count' => -4 ) ), true )['count'] );
	}

	#[Test]
	public function should_keep_the_dates_out_of_the_page_whatever_the_foot_shows(): void {
		$html = $this->render( $this->seed() + array( 'show_page_count' => 'yes' ) );

		self::assertStringContainsString( '4,721 pages', $html );

		foreach ( array( '2026-03-05', '2026-06-01', 'created_at' ) as $private ) {
			self::assertStringNotContainsString( $private, $html, $private );
		}
	}

	#[Test]
	public function should_hold_the_card_in_two_blocks_so_the_foot_stays_at_the_bottom(): void {
		$html = $this->render( $this->seed() );

		self::assertMatchesRegularExpression( '#<article class="agend-resources-collections__card"><div class="agend-resources-collections__body"><span class="agend-resources-collections__icon".*?</div><div class="agend-resources-collections__foot">#s', $html );

		$css = (string) file_get_contents( AGEND_TESTS_ROOT . '/agend-apps-core/assets/css/resources.css' );

		self::assertStringContainsString( 'min-height: calc(min(1, var(--agend-res-cols) - 1) * 2.5em)', $css, 'room for two title lines when cards sit side by side' );
		self::assertMatchesRegularExpression( '#\.agend-resources-collections__card:not\(\.agend-resources-collections__card--member\) > \.agend-resources-collections__body \{\s*flex: 1 1 auto;#', $css );
		self::assertMatchesRegularExpression( '#\.agend-resources-collections__foot \{[^}]*margin-top: 1\.25rem;#', $css );
	}

	#[Test]
	public function should_not_put_the_foot_on_a_members_own_card(): void {
		$drawn = agend_apps_records_resources_collections_member_html(
			array( array( 'id' => self::A, 'title' => 'Mine', 'item_count' => 9, 'items' => array( array( 'page_id' => 'p1', 'title' => 'First page' ) ) ) ),
			array(),
			array( 'card' => array( 'description' => true, 'excerptLength' => 140, 'topics' => true ), 'pages' => 4, 'level' => 'h3' )
		);

		self::assertStringContainsString( 'agend-resources-collections__card--member', $drawn['html'] );
		self::assertStringContainsString( 'agend-resources-collections__body', $drawn['html'] );
		self::assertStringNotContainsString( 'agend-resources-collections__foot', $drawn['html'] );
		self::assertStringNotContainsString( 'Members only', $drawn['html'], 'they can already open it' );
	}

	#[Test]
	public function should_carry_the_layout_and_colours_as_css_variables(): void {
		$html = $this->render( $this->seed() + array( 'columns_desktop' => '2', 'columns_tablet' => '1', 'columns_mobile' => '2', 'card_radius' => 4 ) );

		self::assertStringContainsString( '--agend-res-cols-desktop:2;--agend-res-cols-tablet:1;--agend-res-cols-mobile:2;', $html );
		self::assertStringContainsString( '--agend-res-radius:4px;', $html );
		self::assertStringContainsString( '--agend-res-heading:#1E2A4A;--agend-res-body:#26304D;--agend-res-accent:#FF6B55;', $html );
	}

	#[Test]
	public function should_take_manual_colours_when_not_inheriting(): void {
		$html = $this->render( $this->seed() + array( 'inherit_colours' => '', 'accent_colour' => '#00AA55' ) );

		self::assertStringContainsString( '--agend-res-accent:#00AA55;', $html );
	}

	// -----------------------------------------------------------------
	// Visitors
	// -----------------------------------------------------------------

	#[Test]
	public function should_say_members_can_open_the_collections_with_a_button_when_a_link_is_set(): void {
		$html = $this->render( $this->seed() + array( 'visitor_cta_url' => array( 'url' => 'https://example.test/join' ), 'visitor_cta_label' => 'Join us' ) );

		self::assertStringContainsString( 'Members can open these collections after signing in.', $html );
		self::assertStringContainsString( '<a class="agend-res__button exclude-agend-theme-link" href="https://example.test/join">Join us</a>', $html );
	}

	#[Test]
	public function should_show_the_note_with_no_button_when_no_link_is_set_or_it_is_unsafe(): void {
		foreach ( array( '', 'javascript:alert(1)' ) as $url ) {
			agend_res_test_reset();
			$html = $this->render( $this->seed() + array( 'visitor_cta_url' => $url ) );

			self::assertStringContainsString( 'agend-resources-collections__visitor', $html, $url );
			self::assertStringNotContainsString( 'agend-res__button', $html, $url );
			self::assertStringNotContainsString( 'javascript', $html, $url );
		}
	}

	#[Test]
	public function should_leave_out_the_note_when_the_editor_clears_it(): void {
		$html = $this->render( $this->seed() + array( 'visitor_note' => '' ) );

		self::assertStringNotContainsString( 'agend-resources-collections__visitor', $html );
	}

	// -----------------------------------------------------------------
	// One page for everyone
	// -----------------------------------------------------------------

	#[Test]
	public function should_draw_the_same_page_for_a_visitor_and_a_signed_in_member(): void {
		$seed    = $this->seed();
		$visitor = $this->render( $seed );

		agend_res_test_reset();
		agend_res_test_queue_collections(
			array(
				agend_res_test_collection( self::A, array( 'title' => 'New Member Starter Pack', 'description' => 'Five practical pages.', 'topics' => array( agend_res_test_topic( 'Onboarding' ), agend_res_test_topic( 'Conduct' ) ) ) ),
				agend_res_test_collection( self::B, array( 'title' => 'Professional Development Toolkit', 'description' => 'Six linked resources.' ) ),
				agend_res_test_collection( self::C, array( 'title' => 'Staff Only Notes' ) ),
			)
		);
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'member-token' );
		wp_set_current_user( 7 );

		self::assertSame( $visitor, $this->render( $seed ), 'a cached page for visitors is the page a member gets' );
		self::assertNull( Agend_Test_WP::$requests[0]['headers']['Authorization'] ?? null, 'read as an anonymous visitor, so it stays in the shared cache' );
	}

	#[Test]
	public function should_make_one_anonymous_gateway_read_for_the_whole_surface(): void {
		$this->render( $this->seed() );

		self::assertCount( 1, Agend_Test_WP::$requests );
		self::assertStringContainsString( '/atlas/collections?limit=100', Agend_Test_WP::$requests[0]['url'] );
	}

	// -----------------------------------------------------------------
	// Nothing to draw
	// -----------------------------------------------------------------

	#[Test]
	public function should_draw_nothing_for_a_visitor_when_nothing_is_chosen_and_make_no_call(): void {
		self::assertSame( '', $this->render( array( 'show_member_collections' => '' ) ) );
		self::assertSame( array(), Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_draw_nothing_for_a_visitor_when_the_read_fails_so_a_failure_is_never_cached(): void {
		Agend_Test_WP::queue_response( 502, array( 'error' => array( 'message' => 'Bad gateway' ) ) );

		self::assertSame( '', $this->render( array( 'collection_ids' => array( self::A ), 'show_member_collections' => '' ) ) );
	}

	#[Test]
	public function should_draw_nothing_for_a_visitor_when_every_chosen_collection_is_gone(): void {
		$this->seed();

		self::assertSame( '', $this->render( array( 'collection_ids' => array( 'gone' ), 'show_member_collections' => '' ) ) );
	}

	#[Test]
	public function should_tell_an_editor_in_the_canvas_when_nothing_is_chosen(): void {
		$html = $this->render( array( 'show_member_collections' => '' ), array( 'preview' => true ) );

		self::assertStringContainsString( 'agend-res__editor-note', $html );
		self::assertStringContainsString( 'Choose the collections to show', $html );
		self::assertSame( array(), Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_tell_an_editor_in_the_canvas_when_the_chosen_ones_cannot_be_read(): void {
		Agend_Test_WP::queue_response( 502, array( 'error' => array( 'message' => 'Bad gateway' ) ) );

		$html = $this->render( array( 'collection_ids' => array( self::A ) ), array( 'preview' => true ) );

		self::assertStringContainsString( 'could not be loaded just now', $html );
	}

	// -----------------------------------------------------------------
	// Members, after the page loads
	// -----------------------------------------------------------------

	#[Test]
	public function should_hold_a_hidden_place_for_the_members_collections_with_what_the_script_needs(): void {
		$html = $this->render( $this->seed() );

		self::assertStringContainsString( '<div class="agend-resources-collections__mine" hidden>', $html );
		self::assertStringContainsString( '<div class="agend-resources-collections__mine-body" aria-live="polite"></div>', $html );
		self::assertMatchesRegularExpression( '#data-agend-resources-collections="([^"]*)"#', $html );

		preg_match( '#data-agend-resources-collections="([^"]*)"#', $html, $m );
		$client = json_decode( html_entity_decode( $m[1] ), true );

		self::assertSame( 'https://example.test/wp-json/agend-apps/v1/resources/collections/mine', $client['endpoint'] );
		self::assertTrue( $client['description'] );
		self::assertTrue( $client['topics'] );
		self::assertSame( 4, $client['pages'] );
		self::assertFalse( $client['replace'] );
		self::assertSame( 4, $client['level'], 'card titles sit under the members heading, which sits under the page heading' );
	}

	#[Test]
	public function should_not_hold_a_place_or_load_a_script_target_when_members_are_switched_off(): void {
		$html = $this->render( $this->seed() + array( 'show_member_collections' => '' ) );

		self::assertStringNotContainsString( 'agend-resources-collections__mine', $html );
		self::assertStringNotContainsString( 'data-agend-resources-collections', $html );
	}

	#[Test]
	public function should_draw_the_members_heading_the_editor_wrote_one_level_under_the_page_heading(): void {
		$html = $this->render( $this->seed() + array( 'member_heading' => 'Your library' ) );

		self::assertStringContainsString( '<h3 class="agend-res__title agend-resources-collections__mine-title">Your library</h3>', $html );
	}

	#[Test]
	public function should_make_the_members_heading_the_page_heading_when_it_replaces_the_chosen_ones(): void {
		$html = $this->render( $this->seed() + array( 'member_view' => 'replace' ) );

		self::assertStringContainsString( '<h2 class="agend-res__title agend-resources-collections__mine-title">', $html );

		preg_match( '#data-agend-resources-collections="([^"]*)"#', $html, $m );
		$client = json_decode( html_entity_decode( $m[1] ), true );

		self::assertTrue( $client['replace'] );
		self::assertSame( 3, $client['level'] );
	}

	#[Test]
	public function should_keep_the_page_for_members_alone_hidden_from_visitors_until_the_script_finds_a_member(): void {
		// Nothing chosen, members on: nothing for a visitor, a place for a member.
		$html = $this->render();

		self::assertStringContainsString( ' hidden', substr( $html, 0, 600 ), 'the whole surface is hidden' );
		self::assertStringNotContainsString( 'agend-resources-collections__public', $html );
		self::assertStringContainsString( '<h2 class="agend-res__title agend-resources-collections__mine-title">Your collections</h2>', $html );
		self::assertSame( array(), Agend_Test_WP::$requests, 'and no gateway call for the chosen ones, since there are none' );
	}

	// -----------------------------------------------------------------
	// A member's collections, drawn on the server
	// -----------------------------------------------------------------

	/** The card options the route passes. */
	private function opts( array $over = array() ): array {
		return $over + array(
			'card'  => array(
				'description'   => true,
				'excerptLength' => 140,
				'topics'        => true,
			),
			'pages' => 4,
			'level' => 'h4',
		);
	}

	#[Test]
	public function should_draw_a_members_collections_with_descriptions_from_the_shared_list(): void {
		$mine = array(
			agend_res_test_member_collection( self::A, 'New Member Starter Pack', array( 'Code of conduct', 'Profile settings' ) ),
		);

		$drawn = agend_apps_records_resources_collections_member_html( $mine, array( self::A => 'Five practical pages.' ), $this->opts() );

		self::assertSame( 1, $drawn['count'] );
		self::assertStringContainsString( '<h4 class="agend-resources-collections__name">New Member Starter Pack</h4>', $drawn['html'] );
		self::assertStringContainsString( 'Five practical pages.', $drawn['html'] );
		self::assertStringContainsString( 'Code of conduct', $drawn['html'] );
		self::assertStringContainsString( 'Profile settings', $drawn['html'] );
	}

	#[Test]
	public function should_link_a_members_pages_when_this_site_is_the_identity_provider(): void {
		update_option( 'agend_apps_member_auth_mode', 'wordpress' );
		$mine = array( agend_res_test_member_collection( self::A, 'Starter', array( 'Code of conduct' ) ) );

		$drawn = agend_apps_records_resources_collections_member_html( $mine, array(), $this->opts() );

		self::assertStringContainsString( 'agend-resources-collections__page--link', $drawn['html'] );
		self::assertStringContainsString( 'class="agend-resources-collections__page-link exclude-agend-theme-link" href="https://api.agend.info/api/auth/sso/wdaa/initiate?relayState=', $drawn['html'] );
		self::assertStringContainsString( 'agend-res__icon--chevron', $drawn['html'] );
		self::assertStringNotContainsString( 'Sign in to Atlas', $drawn['html'] );
	}

	#[Test]
	public function should_list_a_members_pages_without_links_and_say_why_once_on_any_other_sign_in_mode(): void {
		update_option( 'agend_apps_member_auth_mode', 'credentials' );
		$mine = array(
			agend_res_test_member_collection( self::A, 'Starter', array( 'Code of conduct', 'Profile settings' ) ),
			agend_res_test_member_collection( self::B, 'Toolkit', array( 'Leadership' ) ),
		);

		$drawn = agend_apps_records_resources_collections_member_html( $mine, array(), $this->opts() );

		self::assertStringNotContainsString( '<a ', $drawn['html'] );
		self::assertStringNotContainsString( 'agend-res__icon--chevron', $drawn['html'] );
		self::assertSame( 1, substr_count( $drawn['html'], 'Sign in to Atlas to read these pages.' ) );
		self::assertSame( 3, substr_count( $drawn['html'], 'agend-resources-collections__page-title' ) );
	}

	#[Test]
	public function should_list_only_as_many_pages_as_asked_and_count_the_rest(): void {
		update_option( 'agend_apps_member_auth_mode', 'wordpress' );
		$pages = array( 'One', 'Two', 'Three', 'Four', 'Five', 'Six' );
		$mine  = array( agend_res_test_member_collection( self::A, 'Starter', $pages ) );

		$drawn = agend_apps_records_resources_collections_member_html( $mine, array(), $this->opts( array( 'pages' => 4 ) ) );

		self::assertSame( 4, substr_count( $drawn['html'], 'agend-resources-collections__page-link ' ) );
		self::assertStringContainsString( 'and 2 more pages', $drawn['html'] );
		self::assertStringNotContainsString( 'Five', $drawn['html'] );

		$one = agend_apps_records_resources_collections_member_html( array( agend_res_test_member_collection( self::A, 'Starter', array( 'A', 'B', 'C', 'D', 'E' ) ) ), array(), $this->opts() );
		self::assertStringContainsString( 'and 1 more page<', $one['html'] );
	}

	#[Test]
	public function should_list_no_pages_at_all_when_set_to_zero(): void {
		$mine  = array( agend_res_test_member_collection( self::A, 'Starter', array( 'One', 'Two' ) ) );
		$drawn = agend_apps_records_resources_collections_member_html( $mine, array(), $this->opts( array( 'pages' => 0 ) ) );

		self::assertStringContainsString( 'Starter', $drawn['html'] );
		self::assertStringNotContainsString( 'agend-resources-collections__pages', $drawn['html'] );
		self::assertStringNotContainsString( 'more page', $drawn['html'] );
		self::assertStringNotContainsString( 'Sign in to Atlas', $drawn['html'] );
	}

	#[Test]
	public function should_follow_the_card_settings_and_the_title_level_for_a_members_cards(): void {
		$mine = array( agend_res_test_member_collection( self::A, 'Starter' ) );
		$mine[0]['topics'] = array( agend_res_test_topic( 'Conduct' ) );

		$off = agend_apps_records_resources_collections_member_html( $mine, array( self::A => 'Described.' ), $this->opts( array( 'card' => array( 'description' => false, 'excerptLength' => 140, 'topics' => false ), 'level' => 'h3' ) ) );

		self::assertStringContainsString( '<h3 class="agend-resources-collections__name">Starter</h3>', $off['html'] );
		self::assertStringNotContainsString( 'Described.', $off['html'] );
		self::assertStringNotContainsString( 'Conduct', $off['html'] );
	}

	#[Test]
	public function should_skip_a_member_collection_with_no_id_or_title_and_a_page_with_no_title(): void {
		$mine = array(
			'not a collection',
			array( 'id' => '', 'title' => 'No id' ),
			array( 'id' => self::A, 'title' => '' ),
			array(
				'id'    => self::B,
				'title' => 'Kept',
				'items' => array(
					array( 'page_id' => 'p1', 'title' => '' ),
					array( 'page_id' => '', 'title' => 'No id' ),
					'junk',
					array( 'page_id' => 'p2abcdef01', 'title' => 'Real page' ),
				),
			),
		);

		$drawn = agend_apps_records_resources_collections_member_html( $mine, array(), $this->opts() );

		self::assertSame( 1, $drawn['count'] );
		self::assertStringContainsString( 'Kept', $drawn['html'] );
		self::assertStringContainsString( 'Real page', $drawn['html'] );
		self::assertStringNotContainsString( 'No id', $drawn['html'] );
	}

	#[Test]
	public function should_escape_a_members_collection_and_page_titles(): void {
		update_option( 'agend_apps_member_auth_mode', 'wordpress' );
		$mine = array( agend_res_test_member_collection( self::A, '<script>x</script>Fish & Chips', array( '<img src=x>Page & more' ) ) );

		$drawn = agend_apps_records_resources_collections_member_html( $mine, array(), $this->opts() );

		self::assertStringNotContainsString( '<script', $drawn['html'] );
		self::assertStringNotContainsString( '<img', $drawn['html'] );
		self::assertStringContainsString( 'Fish &amp; Chips', $drawn['html'] );
		self::assertStringContainsString( 'Page &amp; more', $drawn['html'] );
	}

	#[Test]
	public function should_draw_nothing_for_a_member_with_no_collections(): void {
		self::assertSame( array( 'html' => '', 'count' => 0 ), agend_apps_records_resources_collections_member_html( array(), array(), $this->opts() ) );
	}

	#[Test]
	public function should_hold_a_members_list_to_the_collection_limit(): void {
		$mine = array();

		for ( $i = 1; $i <= 40; $i++ ) {
			$mine[] = agend_res_test_member_collection( 'c' . $i, 'Collection ' . $i );
		}

		self::assertSame( AGEND_APPS_RECORDS_RESOURCES_COLLECTIONS_MAX, agend_apps_records_resources_collections_member_html( $mine, array(), $this->opts() )['count'] );
	}

	// -----------------------------------------------------------------
	// Gate and context
	// -----------------------------------------------------------------

	#[Test]
	public function should_hold_the_surface_and_its_routes_to_the_collections_scope(): void {
		self::assertSame( array( 'atlas.collections.browse' ), agend_apps_records_surface_requirements( 'resources-collections' )['scopes'] );
		self::assertSame( 'resources-collections', agend_apps_records_rest_route_surface( '/agend-apps/v1/resources/collections/mine' ) );
	}

	#[Test]
	public function should_draw_nothing_and_call_nothing_when_the_key_lacks_the_scope(): void {
		$seed = $this->seed();
		agend_res_test_know( array( 'atlas.pages.search' ) );

		self::assertSame( '', $this->render( $seed ) );
		self::assertSame( array(), Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_draw_a_disabled_box_naming_the_scope_for_an_editor_when_gated(): void {
		agend_res_test_know( array( 'atlas.pages.search' ) );

		$html = $this->render( array(), array( 'preview' => true ) );

		self::assertStringContainsString( 'agend-surface-gate', $html );
		self::assertStringContainsString( 'atlas.collections.browse', $html );
	}

	#[Test]
	public function should_render_when_the_key_holds_the_scope(): void {
		$seed = $this->seed();
		agend_res_test_know( array( 'atlas.collections.browse' ) );

		self::assertStringContainsString( 'New Member Starter Pack', $this->render( $seed ) );
	}

	#[Test]
	public function should_draw_nothing_inside_a_card_template(): void {
		$seed = $this->seed();
		Agend_Apps_Records_Record_Context::push( 'course', array( 'slug' => 'a-course' ) );

		self::assertSame( '', $this->render( $seed ) );
		self::assertSame( array(), Agend_Test_WP::$requests );

		Agend_Apps_Records_Record_Context::reset();
	}

	// -----------------------------------------------------------------
	// Editor words, block
	// -----------------------------------------------------------------

	#[Test]
	public function should_word_every_setting_for_an_association_editor(): void {
		$text = $this->editorWords( 'resources-collections' );

		self::assertDoesNotMatchRegularExpression( '/\b(requests?|cach(e|ed|ing)|api|slugs?|ids?|endpoints?|gateway|tokens?|proxy|rest|json)\b/i', $text, 'no developer-speak in what an editor reads' );
		self::assertStringContainsString( 'Resource collections', $text, 'the default heading' );
		self::assertStringContainsString( 'Everyone who visits the page can read the title, description and topics', $text, 'the editor is told what choosing publishes' );
	}

	/**
	 * Every label, description, section name, option and text default an editor reads.
	 *
	 * @param string $surface Surface id.
	 * @return string
	 */
	private function editorWords( string $surface ): string {
		$words = array();

		foreach ( agend_apps_records_surface_schema( $surface )['sections'] as $section ) {
			$words[] = (string) $section['label'];

			foreach ( $section['fields'] as $field ) {
				$words[] = (string) ( $field['label'] ?? '' );
				$words[] = (string) ( $field['description'] ?? '' );
				$words[] = (string) ( $field['placeholder'] ?? '' );
				$words[] = is_string( $field['default'] ?? null ) ? $field['default'] : '';

				if ( isset( $field['options'] ) && is_array( $field['options'] ) ) {
					$words = array_merge( $words, array_map( 'strval', array_values( $field['options'] ) ) );
				}
			}
		}

		return implode( "\n", $words );
	}

	#[Test]
	public function should_derive_the_block_attributes_from_the_schema(): void {
		$attributes = agend_apps_records_block_attributes( agend_apps_records_surface_schema( 'resources-collections' ) );

		self::assertSame( 'array', $attributes['collection_ids']['type'] );
		self::assertSame( array(), $attributes['collection_ids']['default'] );
		self::assertSame( array( 'type' => 'number', 'default' => 6 ), $attributes['max_collections'] );
		self::assertSame( array( 'type' => 'boolean', 'default' => true ), $attributes['show_member_collections'] );
		self::assertSame( array( 'type' => 'string', 'default' => 'additional' ), $attributes['member_view'] );
		self::assertSame( array( 'type' => 'string', 'default' => '3' ), $attributes['columns_desktop'] );
		self::assertSame( array( 'type' => 'string', 'default' => '' ), $attributes['visitor_cta_url'] );
	}

	#[Test]
	public function should_render_the_block_the_same_as_the_core_renderer(): void {
		$seed     = $this->seed();
		$schema   = agend_apps_records_surface_schema( 'resources-collections' );
		$defaults = array_map( static fn( array $a ) => $a['default'], agend_apps_records_block_attributes( $schema ) );
		$attrs    = array( 'collection_ids' => $seed['collection_ids'] ) + $defaults;

		$html = agend_apps_records_render_block( 'resources-collections', $attrs );

		self::assertSame( agend_apps_records_render_resources_collections( agend_apps_records_settings_from_attributes( $schema, $attrs ) ), $html );
		self::assertStringContainsString( 'New Member Starter Pack', $html );
	}

	#[Test]
	public function should_draw_nothing_from_a_gated_block(): void {
		$this->seed();
		agend_res_test_know( array( 'atlas.pages.search' ) );

		self::assertSame( '', agend_apps_records_render_block( 'resources-collections', array( 'collection_ids' => array( self::A ) ) ) );
		self::assertSame( array(), Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_list_the_surface_as_a_block_and_ship_its_built_block(): void {
		self::assertContains( 'resources-collections', AGEND_APPS_RECORDS_BLOCK_SURFACES );

		$src   = json_decode( (string) file_get_contents( AGEND_TESTS_ROOT . '/agend-apps-core/src/blocks/resources-collections/block.json' ), true );
		$build = json_decode( (string) file_get_contents( AGEND_TESTS_ROOT . '/agend-apps-core/build/blocks/resources-collections/block.json' ), true );

		self::assertSame( $src, $build );
		self::assertSame( 'agend-apps/resources-collections', $build['name'] );
		self::assertSame( 'Agend Resources Collections', $build['title'] );
		self::assertSame( array( 'agend-apps-records-resources' ), $build['style'] );
		self::assertSame( array( 'agend-apps-records-resources-collections' ), $build['viewScript'] );
		self::assertFileExists( AGEND_TESTS_ROOT . '/agend-apps-core/build/blocks/resources-collections/index.js' );
		self::assertFileExists( AGEND_TESTS_ROOT . '/agend-apps-core/assets/css/resources.css' );
		self::assertFileExists( AGEND_TESTS_ROOT . '/agend-apps-core/assets/js/resources-collections.js' );
	}
}
