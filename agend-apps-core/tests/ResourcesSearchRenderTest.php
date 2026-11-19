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
 * The resource search surface: a plain form that sends a visitor to the public
 * search page, with no gateway call and no script needed, the same page for
 * every viewer, and the few things a script reads from it.
 */
final class ResourcesSearchRenderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		agend_res_test_reset();
	}

	private function render( array $overrides = array(), array $opts = array() ): string {
		return agend_apps_records_render_resources_search( agend_res_test_settings( 'resources-search', $overrides ), $opts );
	}

	/** @return array The client config the script reads. */
	private function client( string $html ): array {
		self::assertSame( 1, preg_match( '#data-agend-resources-search="([^"]*)"#', $html, $m ) );

		return json_decode( html_entity_decode( $m[1] ), true );
	}

	// -----------------------------------------------------------------
	// Config
	// -----------------------------------------------------------------

	#[Test]
	public function should_default_to_six_results_with_visitors_sent_to_the_public_search_page(): void {
		$config = agend_apps_records_resources_search_build_config( array() );

		self::assertSame( 6, $config['results']['max'] );
		self::assertTrue( $config['results']['excerpt'] );
		self::assertFalse( $config['visitorLive'], 'free by default: visitors are sent to the public search page' );
		self::assertSame( array(), $config['suggestions'] );
		self::assertTrue( $config['note']['show'] );
	}

	#[Test]
	public function should_keep_every_number_within_its_bounds(): void {
		self::assertSame( AGEND_APPS_RECORDS_RESOURCES_SEARCH_RESULTS_MAX, agend_apps_records_resources_search_build_config( array( 'max_results' => 500 ) )['results']['max'] );
		self::assertSame( 1, agend_apps_records_resources_search_build_config( array( 'max_results' => 0 ) )['results']['max'] );
		self::assertSame( 48, agend_apps_records_resources_search_build_config( array( 'card_radius' => 99 ) )['radius'] );
		self::assertSame( 0, agend_apps_records_resources_search_build_config( array( 'card_radius' => -5 ) )['radius'] );
	}

	#[Test]
	public function should_treat_anything_but_live_as_the_free_visitor_mode(): void {
		self::assertTrue( agend_apps_records_resources_search_build_config( array( 'visitor_mode' => 'live' ) )['visitorLive'] );
		self::assertFalse( agend_apps_records_resources_search_build_config( array( 'visitor_mode' => 'link_out' ) )['visitorLive'] );
		self::assertFalse( agend_apps_records_resources_search_build_config( array( 'visitor_mode' => 'anything else' ) )['visitorLive'] );
	}

	#[Test]
	public function should_hold_suggestions_to_the_limit_and_use_them_only_when_asked_for(): void {
		$csv = 'a, b, c, d, e, f, g, h';

		self::assertSame( array(), agend_apps_records_resources_search_build_config( array( 'suggestions' => $csv ) )['suggestions'] );
		self::assertSame( array( 'a', 'b', 'c', 'd', 'e', 'f' ), agend_apps_records_resources_search_build_config( array( 'show_suggestions' => 'yes', 'suggestions' => $csv ) )['suggestions'] );
	}

	// -----------------------------------------------------------------
	// A form that works with no script, and no gateway call
	// -----------------------------------------------------------------

	#[Test]
	public function should_draw_a_plain_get_form_to_the_public_search_page(): void {
		$html = $this->render();

		self::assertStringContainsString( '<form class="agend-resources-search__form" role="search" method="get" action="https://atlas.agend.info/knowledge/wdaa/search">', $html );
		self::assertStringContainsString( 'type="search" name="q"', $html );
		self::assertStringContainsString( '<button type="submit" class="agend-resources-search__button">Search</button>', $html );
	}

	#[Test]
	public function should_label_the_box_and_point_the_results_at_it(): void {
		$html = $this->render();

		self::assertMatchesRegularExpression( '#<label class="agend-res__sr" for="(agend-resources-search-\d+)">Search guides, policies and how-tos</label>#', $html );
		preg_match( '#<label[^>]* for="([^"]+)"#', $html, $label );
		self::assertStringContainsString( 'id="' . $label[1] . '"', $html );
		self::assertStringContainsString( 'aria-controls="' . $label[1] . '-results"', $html );
		self::assertStringContainsString( 'id="' . $label[1] . '-results" aria-live="polite" hidden', $html );
	}

	#[Test]
	public function should_give_each_box_on_a_page_its_own_id(): void {
		preg_match( '#for="([^"]+)"#', $this->render(), $first );
		preg_match( '#for="([^"]+)"#', $this->render(), $second );

		self::assertNotSame( $first[1], $second[1] );
	}

	#[Test]
	public function should_make_no_gateway_call_to_draw_the_page(): void {
		$this->render( array( 'visitor_mode' => 'live', 'show_suggestions' => 'yes', 'suggestions' => 'a, b' ) );

		self::assertSame( array(), Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_draw_the_same_page_for_a_visitor_and_a_signed_in_member(): void {
		$visitor = $this->render();

		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'member-token' );
		wp_set_current_user( 7 );
		$member = $this->render();

		// Only the box's own number differs between two draws on one page.
		$strip = static fn( string $html ): string => (string) preg_replace( '/agend-resources-search-\d+/', 'agend-resources-search-N', $html );

		self::assertSame( $strip( $visitor ), $strip( $member ), 'a page held in a cache for visitors is the page a member gets' );
	}

	#[Test]
	public function should_draw_suggested_searches_as_links_to_the_public_search_page(): void {
		$html = $this->render( array( 'show_suggestions' => 'yes', 'suggestions' => 'Code of conduct, Fish & Chips' ) );

		self::assertStringContainsString( 'href="https://atlas.agend.info/knowledge/wdaa/search?q=Code%20of%20conduct" data-agend-suggest="Code of conduct">Code of conduct</a>', $html );
		self::assertStringContainsString( 'data-agend-suggest="Fish &amp; Chips">Fish &amp; Chips</a>', $html );
		self::assertStringContainsString( 'q=Fish%20%26%20Chips', $html );
	}

	#[Test]
	public function should_draw_no_suggestions_by_default(): void {
		self::assertStringNotContainsString( 'agend-resources-search__suggestions', $this->render() );
	}

	#[Test]
	public function should_use_the_editors_words_escaped(): void {
		$html = $this->render( array( 'placeholder' => 'Find "x" <b>now</b>', 'button_label' => 'Go & find', 'heading_text' => 'A <i>title</i>', 'members_note' => '<script>x()</script>Note' ) );

		self::assertStringContainsString( 'placeholder="Find &quot;x&quot; &lt;b&gt;now&lt;/b&gt;"', $html );
		self::assertStringContainsString( '>Go &amp; find</button>', $html );
		self::assertStringContainsString( 'A &lt;i&gt;title&lt;/i&gt;', $html );
		self::assertStringNotContainsString( '<script>', $html );
	}

	#[Test]
	public function should_fall_back_to_the_default_words_when_the_editor_clears_them(): void {
		$html = $this->render( array( 'placeholder' => '', 'button_label' => '' ) );

		self::assertStringContainsString( 'placeholder="Search guides, policies and how-tos"', $html );
		self::assertStringContainsString( '>Search</button>', $html );
	}

	#[Test]
	public function should_draw_the_note_about_members_only_when_asked_and_with_words(): void {
		self::assertStringContainsString( '<p class="agend-res__note agend-resources-search__note">Members see results from the whole library as they type.</p>', $this->render() );
		self::assertStringNotContainsString( 'agend-resources-search__note', $this->render( array( 'show_members_note' => '' ) ) );
		self::assertStringNotContainsString( 'agend-resources-search__note', $this->render( array( 'members_note' => '' ) ) );
	}

	#[Test]
	public function should_draw_the_heading_only_when_asked(): void {
		self::assertStringContainsString( '<h2 class="agend-res__title">Search our resources</h2>', $this->render() );
		self::assertStringNotContainsString( 'agend-res__heading', $this->render( array( 'show_heading' => '' ) ) );
	}

	#[Test]
	public function should_carry_the_corner_and_colours_as_css_variables(): void {
		$html = $this->render( array( 'card_radius' => 4 ) );

		self::assertStringContainsString( '--agend-res-radius:4px;', $html );
		self::assertStringContainsString( '--agend-res-heading:#1E2A4A;--agend-res-body:#26304D;--agend-res-accent:#FF6B55;', $html );
	}

	// -----------------------------------------------------------------
	// What the script reads
	// -----------------------------------------------------------------

	#[Test]
	public function should_give_the_script_the_route_the_limits_and_the_public_search_page(): void {
		$client = $this->client( $this->render( array( 'max_results' => 4, 'see_all_label' => 'All of them' ) ) );

		self::assertSame( 'https://example.test/wp-json/agend-apps/v1/resources/search', $client['endpoint'] );
		self::assertSame( 3, $client['minChars'] );
		self::assertSame( 4, $client['limit'] );
		self::assertTrue( $client['excerpt'] );
		self::assertSame( 'All of them', $client['seeAllLabel'] );
		self::assertSame( 'https://atlas.agend.info/knowledge/wdaa/search', $client['seeAllUrl'] );
		self::assertFalse( $client['visitorLive'] );
	}

	#[Test]
	public function should_hold_a_message_for_every_state_the_script_draws(): void {
		$messages = $this->client( $this->render( array( 'no_results_text' => 'Zip.', 'error_text' => 'Broken.' ) ) )['messages'];

		self::assertSame( 'Zip.', $messages['none'] );
		self::assertSame( 'Broken.', $messages['error'] );

		foreach ( array( 'busy', 'searching', 'tooShort', 'signInNote', 'notLinked' ) as $key ) {
			self::assertNotSame( '', $messages[ $key ], $key );
		}

		self::assertStringContainsString( '3 characters', $messages['tooShort'] );
	}

	#[Test]
	public function should_fall_back_to_the_default_messages_when_the_editor_clears_them(): void {
		$messages = $this->client( $this->render( array( 'no_results_text' => '', 'error_text' => '' ) ) )['messages'];

		self::assertSame( 'Nothing matched your search. Try different words.', $messages['none'] );
		self::assertSame( 'Search is not available right now. Please try again in a moment.', $messages['error'] );
	}

	#[Test]
	public function should_keep_the_visitor_proof_out_of_the_page_unless_live_results_are_chosen(): void {
		self::assertArrayNotHasKey( 'visitorToken', $this->client( $this->render() ) );

		$live = $this->client( $this->render( array( 'visitor_mode' => 'live' ) ) );

		self::assertTrue( $live['visitorLive'] );
		self::assertSame( agend_apps_records_resources_visitor_token(), $live['visitorToken'] );
	}

	// -----------------------------------------------------------------
	// Without a search page to send visitors to
	// -----------------------------------------------------------------

	#[Test]
	public function should_hide_the_surface_from_visitors_but_keep_it_for_members_when_there_is_no_search_page(): void {
		update_option( 'agend_apps_account_slug', '' );

		$html = $this->render();

		self::assertMatchesRegularExpression( '#<div class="agend-res agend-resources-search"[^>]* hidden>#', $html, 'a member\'s script brings it back' );
		self::assertSame( '', $this->client( $html )['seeAllUrl'] );
	}

	#[Test]
	public function should_tell_an_editor_why_a_visitor_sees_nothing(): void {
		update_option( 'agend_apps_account_slug', '' );

		$html = $this->render( array(), array( 'preview' => true ) );

		self::assertStringContainsString( 'agend-res__editor-note', $html );
		self::assertStringContainsString( 'Connect your Agend account', $html );
		self::assertDoesNotMatchRegularExpression( '#<div class="agend-res agend-resources-search"[^>]* hidden>#', $html );
	}

	#[Test]
	public function should_leave_the_surface_showing_for_visitors_when_live_results_need_no_search_page(): void {
		update_option( 'agend_apps_account_slug', '' );

		self::assertDoesNotMatchRegularExpression( '#<div class="agend-res agend-resources-search"[^>]* hidden>#', $this->render( array( 'visitor_mode' => 'live' ) ) );
	}

	// -----------------------------------------------------------------
	// Gate and context
	// -----------------------------------------------------------------

	#[Test]
	public function should_hold_the_surface_and_its_route_to_the_search_scope(): void {
		self::assertSame( array( 'atlas.pages.search' ), agend_apps_records_surface_requirements( 'resources-search' )['scopes'] );
		self::assertSame( 'resources-search', agend_apps_records_rest_route_surface( '/agend-apps/v1/resources/search' ) );
		self::assertSame( '', agend_apps_records_rest_route_surface( '/agend-apps/v1/resources/search/extra' ) );
	}

	#[Test]
	public function should_draw_nothing_when_the_key_lacks_the_scope_and_a_disabled_box_for_an_editor(): void {
		agend_res_test_know( array( 'atlas.collections.browse' ) );

		self::assertSame( '', $this->render() );

		$box = $this->render( array(), array( 'preview' => true ) );
		self::assertStringContainsString( 'agend-surface-gate', $box );
		self::assertStringContainsString( 'atlas.pages.search', $box );
	}

	#[Test]
	public function should_draw_nothing_inside_a_card_template(): void {
		Agend_Apps_Records_Record_Context::push( 'course', array( 'slug' => 'a-course' ) );

		self::assertSame( '', $this->render() );

		Agend_Apps_Records_Record_Context::reset();
	}

	// -----------------------------------------------------------------
	// Editor words, block
	// -----------------------------------------------------------------

	#[Test]
	public function should_word_every_setting_for_an_association_editor(): void {
		$words = array();

		foreach ( agend_apps_records_surface_schema( 'resources-search' )['sections'] as $section ) {
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

		$text = implode( "\n", $words );

		self::assertDoesNotMatchRegularExpression( '/\b(requests?|cach(e|ed|ing)|api|slugs?|ids?|endpoints?|gateway|tokens?|proxy|rest|json)\b/i', $text, 'no developer-speak in what an editor reads' );
		self::assertStringContainsString( 'AI search allowance', $text, 'the editor is warned what live results for visitors cost' );
		self::assertStringContainsString( 'free', $text, 'and that the other choice is free' );
	}

	#[Test]
	public function should_warn_in_the_description_of_the_visitor_choice_and_not_default_to_it(): void {
		$field = null;

		foreach ( agend_apps_records_surface_schema( 'resources-search' )['sections'] as $section ) {
			foreach ( $section['fields'] as $candidate ) {
				if ( 'visitor_mode' === $candidate['name'] ) {
					$field = $candidate;
				}
			}
		}

		self::assertNotNull( $field );
		self::assertSame( 'link_out', $field['default'] );
		self::assertSame( array( 'link_out', 'live' ), array_keys( $field['options'] ) );
		self::assertStringContainsString( 'use your association\'s AI search allowance every time someone types', $field['description'] );
	}

	#[Test]
	public function should_render_the_block_the_same_as_the_core_renderer(): void {
		$schema   = agend_apps_records_surface_schema( 'resources-search' );
		$defaults = array_map( static fn( array $a ) => $a['default'], agend_apps_records_block_attributes( $schema ) );

		$html = agend_apps_records_render_block( 'resources-search', $defaults );

		$strip = static fn( string $h ): string => (string) preg_replace( '/agend-resources-search-\d+/', 'N', $h );

		self::assertSame( $strip( agend_apps_records_render_resources_search( agend_apps_records_settings_from_attributes( $schema, $defaults ) ) ), $strip( $html ) );
		self::assertStringContainsString( 'Search our resources', $html );
	}

	#[Test]
	public function should_draw_nothing_from_a_gated_block(): void {
		agend_res_test_know( array( 'atlas.collections.browse' ) );

		self::assertSame( '', agend_apps_records_render_block( 'resources-search', array() ) );
	}

	#[Test]
	public function should_list_the_surface_as_a_block_and_ship_its_built_block(): void {
		self::assertContains( 'resources-search', AGEND_APPS_RECORDS_BLOCK_SURFACES );

		$src   = json_decode( (string) file_get_contents( AGEND_TESTS_ROOT . '/agend-apps-core/src/blocks/resources-search/block.json' ), true );
		$build = json_decode( (string) file_get_contents( AGEND_TESTS_ROOT . '/agend-apps-core/build/blocks/resources-search/block.json' ), true );

		self::assertSame( $src, $build );
		self::assertSame( 'agend-apps/resources-search', $build['name'] );
		self::assertSame( 'Agend Resources Search', $build['title'] );
		self::assertSame( array( 'agend-apps-records-resources-search' ), $build['viewScript'] );
		self::assertSame( array( 'agend-apps-records-resources' ), $build['style'] );
		self::assertFileExists( AGEND_TESTS_ROOT . '/agend-apps-core/build/blocks/resources-search/index.js' );
		self::assertFileExists( AGEND_TESTS_ROOT . '/agend-apps-core/assets/js/resources-search.js' );
	}
}
