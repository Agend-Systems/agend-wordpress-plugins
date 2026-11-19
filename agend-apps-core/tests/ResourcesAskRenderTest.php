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
 * The resource Ask surface: members only, the same page for everyone so a page
 * cache can hold it, no gateway call to draw it, and what it does when the
 * account is known not to answer.
 */
final class ResourcesAskRenderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		agend_res_test_reset();
	}

	private function render( array $overrides = array(), array $opts = array() ): string {
		return agend_apps_records_render_resources_ask( agend_res_test_settings( 'resources-ask', $overrides ), $opts );
	}

	private function strip( string $html ): string {
		return (string) preg_replace( '/agend-resources-ask-\d+/', 'agend-resources-ask-N', $html );
	}

	/** @return array The client config the script reads. */
	private function client( string $html ): array {
		self::assertSame( 1, preg_match( '#data-agend-resources-ask="([^"]*)"#', $html, $m ) );

		return json_decode( html_entity_decode( $m[1] ), true );
	}

	// -----------------------------------------------------------------
	// Config
	// -----------------------------------------------------------------

	#[Test]
	public function should_default_to_a_sign_in_prompt_for_visitors_with_sources_shown(): void {
		$config = agend_apps_records_resources_ask_build_config( array() );

		self::assertTrue( $config['signedOut']['show'] );
		self::assertTrue( $config['sources'] );
		self::assertSame( array(), $config['suggestions'] );
	}

	#[Test]
	public function should_hide_the_prompt_only_when_the_editor_chooses_nothing(): void {
		self::assertFalse( agend_apps_records_resources_ask_build_config( array( 'signed_out_behaviour' => 'hide' ) )['signedOut']['show'] );
		self::assertTrue( agend_apps_records_resources_ask_build_config( array( 'signed_out_behaviour' => 'prompt' ) )['signedOut']['show'] );
		self::assertTrue( agend_apps_records_resources_ask_build_config( array( 'signed_out_behaviour' => 'whatever' ) )['signedOut']['show'] );
	}

	#[Test]
	public function should_take_one_suggestion_per_line_up_to_the_limit_and_only_when_asked_for(): void {
		$lines = "One?\nTwo?\n\nThree?\nFour?\nFive?\nOne?";

		self::assertSame( array(), agend_apps_records_resources_ask_build_config( array( 'suggestions' => $lines ) )['suggestions'] );
		self::assertSame( array( 'One?', 'Two?', 'Three?', 'Four?' ), agend_apps_records_resources_ask_build_config( array( 'show_suggestions' => 'yes', 'suggestions' => $lines ) )['suggestions'] );
	}

	// -----------------------------------------------------------------
	// One page for everyone, no gateway call
	// -----------------------------------------------------------------

	#[Test]
	public function should_make_no_gateway_call_to_draw_the_page(): void {
		$this->render( array( 'show_suggestions' => 'yes', 'suggestions' => 'One?' ) );

		self::assertSame( array(), Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_draw_the_same_page_for_a_visitor_and_a_signed_in_member(): void {
		$visitor = $this->render();

		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'member-token' );
		wp_set_current_user( 7 );

		self::assertSame( $this->strip( $visitor ), $this->strip( $this->render() ), 'the box and the prompt are both in the page; the script chooses' );
	}

	#[Test]
	public function should_show_the_prompt_and_hold_the_box_hidden_for_a_visitor(): void {
		$html = $this->render();

		self::assertStringContainsString( '<div class="agend-resources-ask__signed-out">', $html );
		self::assertStringContainsString( 'Sign in to ask a question.', $html );
		self::assertStringContainsString( '<div class="agend-resources-ask__panel" hidden>', $html );
		self::assertDoesNotMatchRegularExpression( '#<section class="agend-res agend-resources-ask"[^>]* hidden>#', $html );
	}

	#[Test]
	public function should_hide_the_whole_surface_for_a_visitor_when_the_editor_chooses_nothing(): void {
		$html = $this->render( array( 'signed_out_behaviour' => 'hide' ) );

		self::assertMatchesRegularExpression( '#<section class="agend-res agend-resources-ask"[^>]* hidden>#', $html, 'a member\'s script brings it back' );
		self::assertStringNotContainsString( 'agend-resources-ask__signed-out', $html );
		self::assertStringContainsString( '<div class="agend-resources-ask__panel" hidden>', $html );
	}

	#[Test]
	public function should_send_the_prompt_button_to_the_editors_link_or_the_site_sign_in_page(): void {
		self::assertStringContainsString( 'href="https://example.test/join">Sign in</a>', $this->render( array( 'sign_in_url' => 'https://example.test/join' ) ) );
		self::assertStringContainsString( 'href="https://example.test/wp-login.php?redirect_to=', $this->render() );
		self::assertStringNotContainsString( 'javascript', $this->render( array( 'sign_in_url' => 'javascript:alert(1)' ) ), 'an unsafe link falls back to the site sign-in page' );
	}

	#[Test]
	public function should_use_the_editors_words_escaped_and_fall_back_when_they_are_cleared(): void {
		$html = $this->render( array( 'signed_out_message' => 'Members <b>only</b> & friends', 'sign_in_label' => 'Log in', 'placeholder' => 'Try "this"', 'button_label' => 'Send & ask' ) );

		self::assertStringContainsString( 'Members &lt;b&gt;only&lt;/b&gt; &amp; friends', $html );
		self::assertStringContainsString( '>Log in</a>', $html );
		self::assertStringContainsString( 'placeholder="Try &quot;this&quot;"', $html );
		self::assertStringContainsString( '<span>Send &amp; ask</span>', $html );

		$blank = $this->render( array( 'signed_out_message' => '', 'sign_in_label' => '', 'placeholder' => '', 'button_label' => '' ) );

		self::assertStringContainsString( 'Sign in to ask a question.', $blank );
		self::assertStringContainsString( '>Sign in</a>', $blank );
		self::assertStringContainsString( '<span>Ask</span>', $blank );
		self::assertStringContainsString( 'placeholder="For example: How do I make a complaint?"', $blank );
	}

	#[Test]
	public function should_label_the_question_box_and_cap_its_length(): void {
		$html = $this->render();

		self::assertMatchesRegularExpression( '#<label class="agend-res__sr" for="(agend-resources-ask-\d+)">Your question</label>#', $html );
		self::assertStringContainsString( 'maxlength="500"', $html );
		self::assertStringContainsString( '<textarea id="', $html );
	}

	#[Test]
	public function should_draw_suggested_questions_as_buttons_inside_the_hidden_box(): void {
		$html = $this->render( array( 'show_suggestions' => 'yes', 'suggestions' => "How do I complain?\nFish & Chips?" ) );

		self::assertStringContainsString( '<button type="button" class="agend-resources-ask__chip" data-agend-suggest="How do I complain?">How do I complain?</button>', $html );
		self::assertStringContainsString( 'data-agend-suggest="Fish &amp; Chips?">Fish &amp; Chips?</button>', $html );
		self::assertGreaterThan( strpos( $html, 'agend-resources-ask__panel' ), strpos( $html, 'agend-resources-ask__suggestions' ), 'members only: they are inside the panel' );
		self::assertStringNotContainsString( 'agend-resources-ask__suggestions', $this->render() );
	}

	// -----------------------------------------------------------------
	// What the script reads
	// -----------------------------------------------------------------

	#[Test]
	public function should_give_the_script_the_route_the_length_and_every_message_it_draws(): void {
		$client = $this->client( $this->render() );

		self::assertSame( 'https://example.test/wp-json/agend-apps/v1/resources/ask', $client['endpoint'] );
		self::assertSame( 500, $client['maxChars'] );
		self::assertTrue( $client['sources'] );

		foreach ( array( 'asked', 'thinking', 'tooShort', 'error', 'busy', 'unavailable', 'notLinked', 'lowConfidence', 'sources', 'signInNote', 'noSources', 'matches', 'contact', 'refusalTitle', 'source' ) as $key ) {
			self::assertNotSame( '', $client['messages'][ $key ] ?? '', $key );
		}
	}

	#[Test]
	public function should_tell_the_script_to_leave_out_the_sources_when_the_editor_does(): void {
		self::assertFalse( $this->client( $this->render( array( 'show_sources' => '' ) ) )['sources'] );
	}

	#[Test]
	public function should_never_give_the_script_a_member_or_a_token(): void {
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'member-token' );
		wp_set_current_user( 7 );

		self::assertStringNotContainsString( 'member-token', $this->render() );
	}

	// -----------------------------------------------------------------
	// What the site has learned about Ask
	// -----------------------------------------------------------------

	#[Test]
	public function should_draw_the_box_while_ask_is_unknown_or_known_to_work(): void {
		self::assertStringContainsString( 'agend-resources-ask__panel', $this->render() );

		agend_apps_atlas_record_ask_verdict( 'answer' );
		self::assertStringContainsString( 'agend-resources-ask__panel', $this->render() );
	}

	#[Test]
	public function should_draw_nothing_for_a_visitor_or_a_member_while_the_account_is_known_not_to_answer(): void {
		agend_apps_atlas_record_ask_verdict( 'not_entitled' );
		$GLOBALS['agend_test_current_user_can']['edit_posts'] = false;

		self::assertSame( '', $this->render() );

		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'member-token' );
		wp_set_current_user( 7 );

		self::assertSame( '', $this->render(), 'a member sees nothing either' );
		self::assertSame( array(), Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_explain_it_to_an_editor_in_the_page_builder_and_to_a_user_who_can_edit(): void {
		agend_apps_atlas_record_ask_verdict( 'not_entitled' );
		$GLOBALS['agend_test_current_user_can']['edit_posts'] = false;

		$preview = $this->render( array(), array( 'preview' => true ) );

		self::assertStringContainsString( 'agend-res__editor-note', $preview );
		self::assertStringContainsString( 'Ask is not part of your Agend plan yet', $preview );
		self::assertStringNotContainsString( 'agend-resources-ask__panel', $preview );

		$GLOBALS['agend_test_current_user_can']['edit_posts'] = true;
		self::assertStringContainsString( 'Ask is not part of your Agend plan yet', $this->render() );
	}

	#[Test]
	public function should_never_name_the_plan_or_the_reason_to_anyone(): void {
		agend_apps_atlas_record_ask_verdict( 'not_entitled' );
		$GLOBALS['agend_test_current_user_can']['edit_posts'] = true;

		$html = $this->render();

		foreach ( array( 'free', 'no_subscription', 'tier', 'starter', 'professional', 'enterprise' ) as $word ) {
			self::assertStringNotContainsStringIgnoringCase( $word, $html, $word );
		}
	}

	#[Test]
	public function should_bring_the_box_back_once_the_verdict_is_forgotten(): void {
		update_option( AGEND_APPS_ATLAS_ASK_STATE_OPTION, array( 'state' => 'not_entitled', 'checked_at' => time() - 6 * 3600 - 60 ) );
		$GLOBALS['agend_test_current_user_can']['edit_posts'] = false;

		self::assertStringContainsString( 'agend-resources-ask__panel', $this->render() );
	}

	// -----------------------------------------------------------------
	// Gate and context
	// -----------------------------------------------------------------

	#[Test]
	public function should_hold_the_surface_and_its_route_to_the_ask_scope(): void {
		self::assertSame( array( 'atlas.questions.create' ), agend_apps_records_surface_requirements( 'resources-ask' )['scopes'] );
		self::assertSame( 'resources-ask', agend_apps_records_rest_route_surface( '/agend-apps/v1/resources/ask' ) );
	}

	#[Test]
	public function should_draw_nothing_when_the_key_lacks_the_scope_and_a_disabled_box_for_an_editor(): void {
		agend_res_test_know( array( 'atlas.pages.search' ) );

		self::assertSame( '', $this->render() );

		$box = $this->render( array(), array( 'preview' => true ) );
		self::assertStringContainsString( 'agend-surface-gate', $box );
		self::assertStringContainsString( 'atlas.questions.create', $box );
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

		foreach ( agend_apps_records_surface_schema( 'resources-ask' )['sections'] as $section ) {
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

		self::assertDoesNotMatchRegularExpression( '/\b(requests?|cach(e|ed|ing)|api|slugs?|ids?|endpoints?|gateway|tokens?|proxy|rest|json|entitle(d|ment)s?)\b/i', $text, 'no developer-speak in what an editor reads' );
		self::assertStringContainsString( 'monthly AI allowance', $text, 'the editor is told why visitors never get the box' );
	}

	#[Test]
	public function should_render_the_block_the_same_as_the_core_renderer(): void {
		$schema   = agend_apps_records_surface_schema( 'resources-ask' );
		$defaults = array_map( static fn( array $a ) => $a['default'], agend_apps_records_block_attributes( $schema ) );

		$html = agend_apps_records_render_block( 'resources-ask', $defaults );

		self::assertSame( $this->strip( agend_apps_records_render_resources_ask( agend_apps_records_settings_from_attributes( $schema, $defaults ) ) ), $this->strip( $html ) );
		self::assertStringContainsString( 'Ask a question', $html );
	}

	#[Test]
	public function should_draw_nothing_from_a_gated_block(): void {
		agend_res_test_know( array( 'atlas.collections.browse' ) );

		self::assertSame( '', agend_apps_records_render_block( 'resources-ask', array() ) );
	}

	#[Test]
	public function should_list_the_surface_as_a_block_and_ship_its_built_block(): void {
		self::assertContains( 'resources-ask', AGEND_APPS_RECORDS_BLOCK_SURFACES );

		$src   = json_decode( (string) file_get_contents( AGEND_TESTS_ROOT . '/agend-apps-core/src/blocks/resources-ask/block.json' ), true );
		$build = json_decode( (string) file_get_contents( AGEND_TESTS_ROOT . '/agend-apps-core/build/blocks/resources-ask/block.json' ), true );

		self::assertSame( $src, $build );
		self::assertSame( 'agend-apps/resources-ask', $build['name'] );
		self::assertSame( 'Agend Resources Ask', $build['title'] );
		self::assertSame( array( 'agend-apps-records-resources-ask' ), $build['viewScript'], 'a site without Ask never loads its script' );
		self::assertFileExists( AGEND_TESTS_ROOT . '/agend-apps-core/build/blocks/resources-ask/index.js' );
		self::assertFileExists( AGEND_TESTS_ROOT . '/agend-apps-core/assets/js/resources-ask.js' );
	}
}
