<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

require_once __DIR__ . '/fixtures/resources-fixtures.php';

/**
 * The helpers the three Resources surfaces share: the plain-text and highlight
 * helpers that sit between the gateway's text and the page, and the small
 * parsers for what an editor types.
 */
final class ResourcesCommonTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		agend_res_test_reset();
	}

	// -----------------------------------------------------------------
	// Highlight
	// -----------------------------------------------------------------

	#[Test]
	public function should_mark_the_words_that_matched_whatever_their_case(): void {
		self::assertSame(
			'A <mark>Complaint</mark> about <mark>conduct</mark>.',
			agend_apps_records_resources_highlight( 'A Complaint about conduct.', 'complaint CONDUCT' )
		);
	}

	#[Test]
	public function should_escape_the_text_before_and_after_a_match_and_inside_it(): void {
		self::assertSame(
			'&lt;b&gt;x&lt;/b&gt; <mark>a&amp;b</mark> &lt;script&gt;alert(1)&lt;/script&gt;',
			agend_apps_records_resources_highlight( '<b>x</b> a&b <script>alert(1)</script>', 'a&b' )
		);
	}

	#[Test]
	public function should_never_let_a_search_word_become_markup_or_a_pattern(): void {
		self::assertSame( 'a (b) c', agend_apps_records_resources_highlight( 'a (b) c', '(' ), 'a single character is not marked' );
		self::assertSame( 'a <mark>(b</mark> c', agend_apps_records_resources_highlight( 'a (b c', '(b' ), 'regular expression characters are literal' );
		self::assertSame( '&lt;mark&gt;', agend_apps_records_resources_highlight( '<mark>', '<x>' ) );
		self::assertSame( 'x <mark>.*</mark> y', agend_apps_records_resources_highlight( 'x .* y', '.*' ) );
	}

	#[Test]
	public function should_mark_the_longest_word_whole_and_no_more_than_six_words(): void {
		self::assertSame( '<mark>complaints</mark>', agend_apps_records_resources_highlight( 'complaints', 'complaint complaints' ) );

		$marked = agend_apps_records_resources_highlight( 'aa bb cc dd ee ff gg hh', 'aa bb cc dd ee ff gg hh' );

		self::assertSame( 6, substr_count( $marked, '<mark>' ) );
	}

	#[Test]
	public function should_not_mark_unicode_words_by_halves(): void {
		self::assertSame( 'Café <mark>naïve</mark> idea', agend_apps_records_resources_highlight( 'Café naïve idea', 'NAÏVE' ) );
	}

	#[Test]
	public function should_only_escape_when_there_is_nothing_to_mark(): void {
		self::assertSame( 'a &amp; b', agend_apps_records_resources_highlight( 'a & b', '' ) );
		self::assertSame( 'a &amp; b', agend_apps_records_resources_highlight( 'a & b', ' ' ) );
	}

	// -----------------------------------------------------------------
	// Excerpt
	// -----------------------------------------------------------------

	/** @return array<string, array{mixed, int, string}> */
	public static function excerpts(): array {
		return array(
			'short text is kept whole'        => array( 'A short line.', 160, 'A short line.' ),
			'markup is dropped'               => array( '<p>Hello <b>world</b></p><script>x()</script>', 160, 'Hello world' ),
			'whitespace is collapsed'         => array( "One\n\n  two   three", 160, 'One two three' ),
			'a long text is cut at a word'    => array( 'The quick brown fox jumps over the lazy dog', 20, 'The quick brown fox…' ),
			'no word boundary near the end'   => array( 'Supercalifragilisticexpialidocious', 10, 'Supercalif…' ),
			'a number is text too'            => array( 12345, 160, '12345' ),
			'a list is not text'              => array( array( 'x' ), 160, '' ),
			'null is empty'                   => array( null, 160, '' ),
		);
	}

	#[Test]
	#[DataProvider( 'excerpts' )]
	public function should_cut_an_excerpt_to_length_at_a_word( $text, int $length, string $expected ): void {
		self::assertSame( $expected, agend_apps_records_resources_excerpt( $text, $length ) );
	}

	// -----------------------------------------------------------------
	// Lists an editor types or picks
	// -----------------------------------------------------------------

	#[Test]
	public function should_clean_a_list_of_ids_and_hold_it_to_a_count(): void {
		self::assertSame( array( 'a-1', 'b_2' ), agend_apps_records_resources_id_list( array( 'a-1', ' b_2 ', 'a-1', '' ), 10 ), 'trimmed, blanks and repeats dropped' );
		self::assertSame( array( 'a', 'b' ), agend_apps_records_resources_id_list( 'a, b ,a', 10 ), 'a comma-separated string' );
		self::assertSame( array( 'a', 'b' ), agend_apps_records_resources_id_list( array( 'a', 'b', 'c' ), 2 ) );
		self::assertSame( array(), agend_apps_records_resources_id_list( 5, 10 ) );
		self::assertSame( array( 'scriptalert1script' ), agend_apps_records_resources_id_list( array( '<script>alert(1)</script>' ), 10 ), 'only id characters survive' );
		self::assertSame( array(), agend_apps_records_resources_id_list( array( array( 'x' ), null ), 10 ) );
	}

	#[Test]
	public function should_split_a_typed_list_trim_it_and_drop_blanks_and_repeats(): void {
		self::assertSame( array( 'Code of conduct', 'Complaints' ), agend_apps_records_resources_list( " Code of conduct , Complaints,,Complaints ", ',', 6 ) );
		self::assertSame( array( 'One', 'Two' ), agend_apps_records_resources_list( "One\r\nTwo\n\n", "\n", 6 ) );
		self::assertSame( array( 'a', 'b' ), agend_apps_records_resources_list( 'a,b,c', ',', 2 ) );
		self::assertSame( array( 'abc' ), agend_apps_records_resources_list( 'abcdef', ',', 6, 3 ), 'each item held to a length' );
		self::assertSame( array( 'bold' ), agend_apps_records_resources_list( '<b>bold</b>', ',', 6 ), 'no markup' );
		self::assertSame( array(), agend_apps_records_resources_list( array( 'x' ), ',', 6 ) );
	}

	#[Test]
	public function should_read_topic_names_from_objects_or_strings(): void {
		self::assertSame(
			array( 'Conduct', 'Benefits', 'Plain' ),
			agend_apps_records_resources_topic_names(
				array(
					agend_res_test_topic( 'Conduct' ),
					agend_res_test_topic( 'Benefits' ),
					'Plain',
					agend_res_test_topic( 'Conduct' ),
					array( 'name' => '' ),
					array( 'slug' => 'no-name' ),
					'',
				)
			)
		);
		self::assertSame( array(), agend_apps_records_resources_topic_names( 'not a list' ) );
		self::assertSame( array( 'x' ), agend_apps_records_resources_topic_names( array( '<i>x</i>' ) ) );
	}

	// -----------------------------------------------------------------
	// Addresses an editor types
	// -----------------------------------------------------------------

	/** @return array<string, array{mixed, string}> */
	public static function urls(): array {
		return array(
			'https'                        => array( 'https://example.test/join', 'https://example.test/join' ),
			'http'                         => array( 'http://example.test/join', 'http://example.test/join' ),
			'a path on this site'          => array( '/membership/', '/membership/' ),
			'elementor url array'          => array( array( 'url' => 'https://example.test/x', 'is_external' => '' ), 'https://example.test/x' ),
			'a script scheme'              => array( 'javascript:alert(1)', '' ),
			'a data scheme'                => array( 'data:text/html;base64,AAAA', '' ),
			'a protocol-relative address'  => array( '//evil.test/x', '' ),
			'a bare word'                  => array( 'membership', '' ),
			'nothing'                      => array( '', '' ),
			'null'                         => array( null, '' ),
		);
	}

	#[Test]
	#[DataProvider( 'urls' )]
	public function should_link_only_to_web_addresses_and_paths_on_the_site( $value, string $expected ): void {
		self::assertSame( $expected, agend_apps_records_resources_url( $value ) );
	}

	#[Test]
	public function should_send_a_sign_in_button_to_the_editors_link_or_the_site_sign_in_page(): void {
		self::assertSame( 'https://example.test/join', agend_apps_records_resources_sign_in_url( 'https://example.test/join' ) );
		self::assertStringStartsWith( 'https://example.test/wp-login.php', agend_apps_records_resources_sign_in_url( '' ) );
	}

	// -----------------------------------------------------------------
	// Markup
	// -----------------------------------------------------------------

	#[Test]
	public function should_draw_icons_that_assistive_technology_skips(): void {
		foreach ( array( 'stack', 'search', 'ask', 'chevron' ) as $name ) {
			$svg = agend_apps_records_resources_icon( $name );

			self::assertStringContainsString( 'aria-hidden="true"', $svg, $name );
			self::assertStringContainsString( 'focusable="false"', $svg, $name );
			self::assertStringContainsString( 'stroke="currentColor"', $svg, $name );
		}

		self::assertSame( '', agend_apps_records_resources_icon( 'nothing' ) );
	}

	#[Test]
	public function should_draw_a_heading_only_when_asked_and_when_there_is_something_to_say(): void {
		self::assertSame( '', agend_apps_records_resources_heading( false, 'Title', 'Sub' ) );
		self::assertSame( '', agend_apps_records_resources_heading( true, '', '' ) );
		self::assertSame( '<div class="agend-res__heading"><h2 class="agend-res__title">A &amp; B</h2></div>', agend_apps_records_resources_heading( true, 'A & B', '' ) );
		self::assertStringContainsString( '<p class="agend-res__subtitle">Sub</p>', agend_apps_records_resources_heading( true, '', 'Sub' ) );
	}

	#[Test]
	public function should_hold_the_visitor_token_to_the_site_and_make_it_stable(): void {
		$token = agend_apps_records_resources_visitor_token();

		self::assertSame( 32, strlen( $token ) );
		self::assertSame( $token, agend_apps_records_resources_visitor_token(), 'stable, so a cached page keeps working' );
		self::assertSame( substr( hash_hmac( 'sha256', 'agend-resources-visitor-search', wp_salt( 'auth' ) ), 0, 32 ), $token, 'from the site\'s own secret' );
	}

	#[Test]
	public function should_write_the_colours_and_corner_as_variables(): void {
		$style = agend_apps_records_resources_style( array( 'heading' => '#111111', 'body' => '#222222', 'accent' => '#333333' ), 99, '--x:1;' );

		self::assertSame( '--agend-res-heading:#111111;--agend-res-body:#222222;--agend-res-accent:#333333;--agend-res-radius:48px;--x:1;', $style );
	}
}
