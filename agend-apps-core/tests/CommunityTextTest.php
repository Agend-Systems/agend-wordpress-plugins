<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/community-text.php';

/**
 * What a member's post becomes before a visitor sees it: scrubbed of mentions,
 * stripped of markup, cut to a title and an excerpt, and an author's name in
 * only as much detail as the site chose.
 */
final class CommunityTextTest extends TestCase {

	// -----------------------------------------------------------------
	// Mentions
	// -----------------------------------------------------------------

	/** @return array<string, array{string, string}> */
	public static function mentions(): array {
		return array(
			'a picked mention'            => array( 'Thanks @[8f14e45f-ceea-467f-a8f0-2f3f9a3b6c11] for this.', 'Thanks a member for this.' ),
			'a typed handle'              => array( 'Congrats @sarah.mitchell! Well done.', 'Congrats a member! Well done.' ),
			'a handle at a sentence end'  => array( 'Ask @ben.', 'Ask a member.' ),
			'two in a row'                => array( '@ann and @bob.smith agree.', 'a member and a member agree.' ),
			'everyone is not a member'    => array( '@everyone please read.', 'everyone please read.' ),
			'at channel and at here'      => array( '@channel and @here', 'everyone and everyone' ),
			'an email address is kept'    => array( 'Write to office@example.com today.', 'Write to office@example.com today.' ),
			'a handle with a unicode name' => array( 'Hi @josé.garcía', 'Hi a member' ),
			'no mention'                  => array( 'Plain words.', 'Plain words.' ),
		);
	}

	#[Test]
	#[DataProvider( 'mentions' )]
	public function should_scrub_every_mention_to_a_member( string $text, string $expected ): void {
		self::assertSame( $expected, agend_apps_community_scrub_mentions( $text ) );
	}

	// -----------------------------------------------------------------
	// Plain text, title, excerpt
	// -----------------------------------------------------------------

	#[Test]
	public function should_leave_no_markup_link_or_address_in_plain_text(): void {
		$plain = agend_apps_community_plain( "## Head\n\n**Bold** and [a link](https://evil.test/x) with ![img](https://evil.test/i.png) and https://evil.test/raw and <b>tags</b>\n\n- item one\n> quoted `code`" );

		self::assertSame( 'Head Bold and a link with and and tags item one quoted code', $plain );
		self::assertStringNotContainsString( 'evil.test', $plain );
	}

	#[Test]
	public function should_not_let_an_escaped_tag_come_back_as_a_tag(): void {
		self::assertSame( '', agend_apps_community_plain( '&lt;script&gt;alert(1)&lt;/script&gt;' ) );
		self::assertSame( 'bold', agend_apps_community_plain( '&lt;b onclick=&quot;x&quot;&gt;bold&lt;/b&gt;' ) );
	}

	#[Test]
	public function should_take_the_first_heading_as_the_title(): void {
		self::assertSame( 'Welcome to 2026!', agend_apps_community_title( "Intro line.\n\n## Welcome to 2026!\n\nBody." ) );
	}

	#[Test]
	public function should_take_the_first_sentence_as_the_title_when_there_is_no_heading(): void {
		self::assertSame( 'Counting down the days!', agend_apps_community_title( 'Counting down the days! First time attending, any tips?' ) );
		self::assertSame( 'No stop here', agend_apps_community_title( 'No stop here' ) );
		self::assertSame( '', agend_apps_community_title( "   \n " ) );
	}

	#[Test]
	public function should_cut_a_long_title_at_a_word(): void {
		$title = agend_apps_community_title( str_repeat( 'word ', 60 ), 40 );

		self::assertLessThanOrEqual( 41, mb_strlen( $title ) );
		self::assertStringEndsWith( '…', $title );
	}

	#[Test]
	public function should_leave_the_heading_out_of_the_excerpt(): void {
		$markdown = "## Title here\n\nThe body text follows.";
		$title    = agend_apps_community_title( $markdown );

		self::assertSame( 'The body text follows.', agend_apps_community_excerpt( $markdown, $title ) );
	}

	#[Test]
	public function should_leave_the_title_sentence_out_of_the_excerpt_of_a_post_with_no_heading(): void {
		$markdown = 'Counting down the days! First time attending, any tips?';
		$title    = agend_apps_community_title( $markdown );

		self::assertSame( 'First time attending, any tips?', agend_apps_community_excerpt( $markdown, $title ) );
	}

	// -----------------------------------------------------------------
	// Names
	// -----------------------------------------------------------------

	/** @return array<string, array{string, string}> */
	public static function names(): array {
		return array(
			'a handle'            => array( 'ben.thompson', 'Ben Thompson' ),
			'an underscore handle' => array( 'sarah_mitchell', 'Sarah Mitchell' ),
			'one lowercase word'  => array( 'priya', 'Priya' ),
			'a real name is kept' => array( 'Mary-Jane O\'Neil', 'Mary-Jane O\'Neil' ),
			'capitals are kept'   => array( 'Ann McDonald', 'Ann McDonald' ),
			'an email is cut'     => array( 'someone.name@example.com', 'Someone Name' ),
			'nothing'             => array( '   ', '' ),
		);
	}

	#[Test]
	#[DataProvider( 'names' )]
	public function should_write_a_handle_as_a_name( string $name, string $expected ): void {
		self::assertSame( $expected, agend_apps_community_pretty_name( $name ) );
	}

	/** @return array<string, array{string, string, string}> */
	public static function author_modes(): array {
		return array(
			'none shows nothing'    => array( 'none', 'ben.thompson', '' ),
			'initials'              => array( 'initials', 'ben.thompson', 'BT' ),
			'first name'            => array( 'first_name', 'ben.thompson', 'Ben' ),
			'first name and initial' => array( 'first_name_initial', 'ben.thompson', 'Ben T.' ),
			'full name'             => array( 'full', 'ben.thompson', 'Ben Thompson' ),
			'one name only'         => array( 'first_name_initial', 'priya', 'Priya' ),
			'initials of one name'  => array( 'initials', 'priya', 'P' ),
			'a name with a middle'  => array( 'first_name_initial', 'Ann Marie Lee', 'Ann L.' ),
			'no name'               => array( 'full', '', 'A member' ),
			'an unknown mode is off' => array( 'everything', 'ben.thompson', '' ),
		);
	}

	#[Test]
	#[DataProvider( 'author_modes' )]
	public function should_show_an_author_only_as_far_as_the_mode_allows( string $mode, string $name, string $expected ): void {
		self::assertSame( $expected, agend_apps_community_author_label( $name, $mode ) );
	}

	#[Test]
	public function should_offer_none_first_so_it_is_the_default_an_editor_sees(): void {
		self::assertSame( array( 'none', 'initials', 'first_name', 'first_name_initial', 'full' ), array_keys( agend_apps_community_author_modes() ) );
	}

	// -----------------------------------------------------------------
	// Dates
	// -----------------------------------------------------------------

	#[Test]
	public function should_say_how_long_ago_for_the_last_month_and_give_the_date_after(): void {
		$now = strtotime( '2026-11-18T10:00:00+00:00' );

		self::assertSame( '2 days ago', agend_apps_community_when( '2026-11-16T10:00:00+00:00', $now )['label'] );
		self::assertSame( '7 Oct 2026', agend_apps_community_when( '2026-10-07T05:28:00+00:00', $now )['label'] );
		self::assertSame( '2026-10-07T05:28:00+00:00', agend_apps_community_when( '2026-10-07T05:28:00.847126+00:00', $now )['iso'] );
		self::assertSame( '7 Oct 2026', agend_apps_community_when( '2026-10-07T05:28:00+00:00', $now )['title'] );
	}

	#[Test]
	public function should_give_no_date_for_a_timestamp_it_cannot_read(): void {
		self::assertSame( '', agend_apps_community_when( '' )['label'] );
		self::assertSame( '', agend_apps_community_when( 'not a time' )['label'] );
	}
}
