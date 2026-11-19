<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend_Apps_Key_Scopes;
use Agend_Apps_Logger;
use Agend_Apps_Endpoint_Scopes;
use Agend_Apps_Records_Record_Context;
use Agend_Test_WP;
use Agend\Tests\AppsCore\MemoryLogStore;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/logging/class-agend-apps-log-redactor.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/logging/class-agend-apps-log-store.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/logging/class-agend-apps-logger.php';
require_once __DIR__ . '/fixtures/memory-log-store.php';

/**
 * The three Community surfaces, end to end: the real read wrappers answer from
 * the HTTP double, and what the renderer draws is checked. Everything here is
 * what a visitor can see, so the privacy defaults (no author, no photo, no
 * chat, only allowed channels, only titled posts) are pinned as hard as the
 * markup.
 *
 * Each case runs in its own process: the real wrappers are declared here, and
 * other tests declare doubles for some of them.
 */
#[RunTestsInSeparateProcesses]
final class CommunityRenderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/tests/render-doubles.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/health.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-http-batch.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-key-scopes.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-endpoint-scopes.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/features.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/palette.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/blocks.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/loop-public.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/community-announcements.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/community-channels.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/community-feed.php';
		require_once __DIR__ . '/fixtures/community-stubs.php';
		agend_render_test_reset();
		Agend_Test_WP::set_filter( 'agend_apps_api_parallel', false );
		Agend_Apps_Logger::set_instance( new Agend_Apps_Logger( new MemoryLogStore() ) );
		Agend_Apps_Endpoint_Scopes::set_map( null );
		Agend_Apps_Records_Record_Context::reset();

		// A site in WordPress sign-in mode, on the production platform.
		Agend_Test_WP::$options['agend_apps_account_slug']       = 'wdaa';
		Agend_Test_WP::$options['agend_apps_member_auth_mode']   = 'wordpress';
		Agend_Test_WP::$options['agend_apps_root_url_for_tests'] = 'https://api.agend.com.au';
	}

	/**
	 * A fresh site mid-test: the stub state cleared, then set up as setUp() does,
	 * so a second render in one test starts from empty stores and the same site.
	 */
	private function resetSite(): void {
		Agend_Test_WP::reset();
		Agend_Test_WP::set_filter( 'agend_apps_api_parallel', false );
		Agend_Test_WP::$options['agend_apps_account_slug']       = 'wdaa';
		Agend_Test_WP::$options['agend_apps_member_auth_mode']   = 'wordpress';
		Agend_Test_WP::$options['agend_apps_root_url_for_tests'] = 'https://api.agend.com.au';
	}

	protected function tearDown(): void {
		Agend_Apps_Records_Record_Context::reset();
		Agend_Apps_Endpoint_Scopes::set_map( null );
		parent::tearDown();
	}

	/**
	 * Settings an editor hands a renderer: the schema's defaults in the Elementor
	 * shape, with changes over them.
	 *
	 * @param string $surface   Surface id.
	 * @param array  $overrides Settings to change.
	 * @return array
	 */
	private function settings( string $surface, array $overrides = array() ): array {
		$schema   = agend_apps_records_surface_schema( $surface );
		$defaults = array_map( static fn( array $a ) => $a['default'], agend_apps_records_block_attributes( $schema ) );

		return $overrides + agend_apps_records_settings_from_attributes( $schema, $defaults );
	}

	private function announcements( array $overrides = array(), array $opts = array() ): string {
		return agend_apps_records_render_community_announcements( $this->settings( 'community-announcements', $overrides ), $opts );
	}

	private function channels( array $overrides = array(), array $opts = array() ): string {
		return agend_apps_records_render_community_channels( $this->settings( 'community-channels', $overrides ), $opts );
	}

	private function feed( array $overrides = array(), array $opts = array() ): string {
		return agend_apps_records_render_community_feed( $this->settings( 'community-feed', $overrides ), $opts );
	}

	private function know( array $scopes ): void {
		Agend_Apps_Key_Scopes::store_from_response( array( 'data' => array( 'scopes' => $scopes, 'app_ids' => array() ) ) );
	}

	/**
	 * Allows the channels, queues the channel list and the announcements answer.
	 *
	 * @param array[] $channels Channel rows (all allowed).
	 * @param array[] $posts    Announcement rows.
	 */
	private function seedAnnouncements( array $channels, array $posts ): void {
		agend_cm_test_allow( $channels );
		agend_cm_test_queue_channels( $channels );
		agend_cm_test_queue_announcements( $posts );
	}

	/**
	 * Allows the channels, queues the channel list and one activity answer per
	 * channel, in the order the wrapper reads them.
	 *
	 * @param array[] $channels Channel rows (all allowed).
	 * @param array[] $posts    Activity rows, grouped by channel slug here.
	 */
	private function seedFeed( array $channels, array $posts ): void {
		agend_cm_test_allow( $channels );
		agend_cm_test_queue_channels( $channels );

		foreach ( $channels as $channel ) {
			agend_cm_test_queue_activity( array_values( array_filter( $posts, static fn( array $p ): bool => $p['channel']['id'] === $channel['id'] ) ) );
		}
	}

	private function news(): array {
		return agend_cm_test_channel( 'news', array( 'channel_type' => 'feed', 'name' => 'Member News', 'member_count' => 42 ) );
	}

	// -----------------------------------------------------------------
	// Announcements: what is drawn
	// -----------------------------------------------------------------

	#[Test]
	public function should_draw_announcements_as_a_notice_list_pinned_first_with_a_link_into_the_channel(): void {
		$news = $this->news();
		$this->seedAnnouncements(
			array( $news ),
			array(
				agend_cm_test_post( 'a', $news, "## Newer notice\n\nBody of the newer notice.", array( 'created_at' => '2026-11-14T00:00:00+00:00' ) ),
				agend_cm_test_post( 'b', $news, "## Pinned notice\n\nBody of the pinned one.", array( 'is_pinned' => true, 'created_at' => '2026-09-01T00:00:00+00:00' ) ),
			)
		);

		$html = $this->announcements();

		self::assertSame( array( 'Pinned notice', 'Newer notice' ), $this->headlines( $html ) );
		self::assertStringContainsString( 'agend-community--announcements', $html );
		self::assertStringContainsString( 'agend-community-announcements__item--pinned', $html );
		self::assertStringContainsString( '<h2 class="agend-community__title">Announcements</h2>', $html );
		self::assertStringContainsString( 'agend-community__flag">Pinned', $html );
		self::assertStringContainsString( 'Member News', $html );
		self::assertStringContainsString( 'Body of the pinned one.', $html );
		self::assertStringContainsString( 'href="https://api.agend.com.au/sso/wdaa/loop-channel/news"', $html, 'the channel, through the sign-in link' );
		self::assertStringContainsString( 'exclude-agend-theme-link', $html );
		self::assertStringContainsString( '(opens the Member News channel)', $html );
		self::assertStringContainsString( '<time class="agend-community-announcements__date" datetime="2026-09-01T00:00:00+00:00"', $html );
	}

	/**
	 * @param string $html Markup.
	 * @return string[] The headline texts, in order.
	 */
	private function headlines( string $html ): array {
		preg_match_all( '#<h3 class="agend-community-(?:announcements__headline|feed__headline|feed__title)">(.*?)</h3>#s', $html, $match );

		return array_map(
			static fn( string $h ): string => trim( html_entity_decode( (string) preg_replace( '#<span class="agend-community__sr">.*?</span>#s', '', strip_tags( $h, '<span>' ) ) ) ),
			$match[1]
		);
	}

	#[Test]
	public function should_show_only_announcements_by_default_and_every_titled_post_on_request_but_never_chat(): void {
		$news  = $this->news();
		$posts = array(
			agend_cm_test_post( 'a', $news, "## An announcement\n\nx", array( 'created_at' => '2026-11-16T00:00:00+00:00' ) ),
			agend_cm_test_post( 'u', $news, "## An update\n\nx", array( 'created_at' => '2026-11-15T00:00:00+00:00' ), 'update' ),
			agend_cm_test_post( 'c', $news, 'Just chatting here.', array(), '' ),
		);

		$this->seedAnnouncements( array( $news ), $posts );
		self::assertSame( array( 'An announcement' ), $this->headlines( $this->announcements( array( 'max_age_days' => 0 ) ) ) );

		$this->resetSite();
		$this->seedAnnouncements( array( $news ), $posts );
		self::assertSame( array( 'An announcement', 'An update' ), $this->headlines( $this->announcements( array( 'post_source' => 'titled', 'max_age_days' => 0 ) ) ) );
	}

	#[Test]
	public function should_keep_only_pinned_or_featured_posts_when_asked(): void {
		$news  = $this->news();
		$posts = array(
			agend_cm_test_post( 'plain', $news, "## Plain\n\nx" ),
			agend_cm_test_post( 'pin', $news, "## Pinned\n\nx", array( 'is_pinned' => true ) ),
			agend_cm_test_post( 'feat', $news, "## Featured\n\nx", array( 'is_featured' => true ) ),
		);

		$this->seedAnnouncements( array( $news ), $posts );
		self::assertSame( array( 'Pinned' ), $this->headlines( $this->announcements( array( 'pinned_only' => 'yes' ) ) ) );

		$this->resetSite();
		$this->seedAnnouncements( array( $news ), $posts );
		self::assertSame( array( 'Featured' ), $this->headlines( $this->announcements( array( 'featured_only' => 'yes' ) ) ) );
	}

	#[Test]
	public function should_order_by_date_alone_when_pinned_first_is_off(): void {
		$news = $this->news();
		$this->seedAnnouncements(
			array( $news ),
			array(
				agend_cm_test_post( 'old', $news, "## Old pinned\n\nx", array( 'is_pinned' => true, 'created_at' => '2026-11-01T00:00:00+00:00' ) ),
				agend_cm_test_post( 'new', $news, "## New\n\nx", array( 'created_at' => '2026-11-15T00:00:00+00:00' ) ),
			)
		);

		self::assertSame( array( 'New', 'Old pinned' ), $this->headlines( $this->announcements( array( 'pinned_first' => '', 'max_age_days' => 0 ) ) ) );
	}

	#[Test]
	public function should_hide_old_posts_but_let_a_pinned_one_stay(): void {
		$news = $this->news();
		$this->seedAnnouncements(
			array( $news ),
			array(
				agend_cm_test_post( 'recent', $news, "## Recent\n\nx", array( 'created_at' => gmdate( 'c', time() - 10 * DAY_IN_SECONDS ) ) ),
				agend_cm_test_post( 'old', $news, "## Old\n\nx", array( 'created_at' => gmdate( 'c', time() - 200 * DAY_IN_SECONDS ) ) ),
				agend_cm_test_post( 'oldpin', $news, "## Old but pinned\n\nx", array( 'is_pinned' => true, 'created_at' => gmdate( 'c', time() - 400 * DAY_IN_SECONDS ) ) ),
			)
		);

		self::assertSame( array( 'Old but pinned', 'Recent' ), $this->headlines( $this->announcements() ) );
	}

	#[Test]
	public function should_show_only_the_chosen_channels_and_no_more_than_the_count(): void {
		$news = $this->news();
		$jobs = agend_cm_test_channel( 'jobs', array( 'channel_type' => 'feed', 'name' => 'Jobs' ) );
		$this->seedAnnouncements(
			array( $news, $jobs ),
			array(
				agend_cm_test_post( 'n1', $news, "## News one\n\nx" ),
				agend_cm_test_post( 'n2', $news, "## News two\n\nx" ),
				agend_cm_test_post( 'j1', $jobs, "## Job one\n\nx" ),
			)
		);

		self::assertSame( array( 'Job one' ), $this->headlines( $this->announcements( array( 'channels' => array( 'chan-jobs' ) ) ) ) );

		$this->resetSite();
		$this->seedAnnouncements(
			array( $news, $jobs ),
			array(
				agend_cm_test_post( 'n1', $news, "## News one\n\nx" ),
				agend_cm_test_post( 'n2', $news, "## News two\n\nx" ),
			)
		);

		self::assertCount( 1, $this->headlines( $this->announcements( array( 'count' => 1 ) ) ) );
	}

	#[Test]
	public function should_escape_what_a_member_wrote(): void {
		$news = $this->news();
		$this->seedAnnouncements( array( $news ), array( agend_cm_test_post( 'x', $news, "## Fish & <b>chips</b> \"night\"\n\nBring <script>alert(1)</script>cash & coins." ) ) );

		$html = $this->announcements();

		self::assertStringNotContainsString( '<script', $html );
		self::assertStringNotContainsString( '<b>', $html );
		self::assertStringContainsString( 'Fish &amp; chips', $html );
	}

	// -----------------------------------------------------------------
	// Authors and photos
	// -----------------------------------------------------------------

	#[Test]
	public function should_show_no_author_and_no_photo_by_default(): void {
		$news = $this->news();
		$this->seedAnnouncements( array( $news ), array( agend_cm_test_post( 'x', $news, "## Notice\n\nx" ) ) );

		$html = $this->announcements();

		self::assertStringNotContainsString( 'Ben', $html );
		self::assertStringNotContainsString( 'ben.thompson', $html );
		self::assertStringNotContainsString( 'agend-community__author', $html );
		self::assertStringNotContainsString( '<img', $html );
		self::assertStringNotContainsString( 'avatars.example.test', $html );
		self::assertStringNotContainsString( 'user-secret-1', $html );
	}

	/** @return array<string, array{string, string}> */
	public static function authorModes(): array {
		return array(
			'initials'           => array( 'initials', '>BT<' ),
			'first name'         => array( 'first_name', '>Ben<' ),
			'first name and initial' => array( 'first_name_initial', '>Ben T.<' ),
			'full name'          => array( 'full', '>Ben Thompson<' ),
		);
	}

	#[Test]
	#[DataProvider( 'authorModes' )]
	public function should_show_an_author_as_far_as_the_mode_allows_and_no_photo_unless_switched_on( string $mode, string $needle ): void {
		$news = $this->news();
		$this->seedAnnouncements( array( $news ), array( agend_cm_test_post( 'x', $news, "## Notice\n\nx" ) ) );

		$html = $this->announcements( array( 'author_display' => $mode ) );

		self::assertStringContainsString( $needle, $html );
		self::assertStringNotContainsString( 'ben.thompson', $html );
		self::assertStringNotContainsString( '<img', $html, 'the photo is a separate switch' );
	}

	#[Test]
	public function should_show_the_photo_only_when_an_author_is_shown_and_the_switch_is_on(): void {
		$news  = $this->news();
		$posts = array( agend_cm_test_post( 'x', $news, "## Notice\n\nx" ) );

		$this->seedAnnouncements( array( $news ), $posts );
		$with = $this->announcements( array( 'author_display' => 'first_name', 'show_avatar' => 'yes' ) );

		self::assertStringContainsString( '<img class="agend-community__avatar" src="https://avatars.example.test/ben.png" alt="" width="24" height="24" loading="lazy" decoding="async" referrerpolicy="no-referrer">', $with );

		$this->resetSite();
		$this->seedAnnouncements( array( $news ), $posts );

		self::assertStringNotContainsString( '<img', $this->announcements( array( 'author_display' => 'none', 'show_avatar' => 'yes' ) ), 'a photo without a name is still an author' );
	}

	#[Test]
	public function should_draw_a_letter_in_place_of_a_photo_the_member_has_not_set(): void {
		$news = $this->news();
		$post = agend_cm_test_post( 'x', $news, "## Notice\n\nx" );
		$post['author']['avatar_url'] = '';
		$this->seedAnnouncements( array( $news ), array( $post ) );

		$html = $this->announcements( array( 'author_display' => 'first_name', 'show_avatar' => 'yes' ) );

		self::assertStringContainsString( 'agend-community__avatar agend-community__avatar--letters" aria-hidden="true">BT<', $html );
	}

	// -----------------------------------------------------------------
	// Links and the community link
	// -----------------------------------------------------------------

	#[Test]
	public function should_link_each_item_to_its_channel_by_the_sign_in_mode_and_open_the_community_below(): void {
		$news = $this->news();
		$this->seedAnnouncements( array( $news ), array( agend_cm_test_post( 'x', $news, "## Notice\n\nx" ) ) );

		$html = $this->announcements();

		self::assertStringContainsString( '<p class="agend-community__cta"><a class="agend-community__cta-link exclude-agend-theme-link" href="https://api.agend.com.au/sso/wdaa/loop-home">Open the community', $html );

		$this->resetSite();
		Agend_Test_WP::$options['agend_apps_account_slug']       = 'wdaa';
		Agend_Test_WP::$options['agend_apps_member_auth_mode']   = 'credentials';
		Agend_Test_WP::$options['agend_apps_root_url_for_tests'] = 'https://api.agend.com.au';
		$this->seedAnnouncements( array( $news ), array( agend_cm_test_post( 'x', $news, "## Notice\n\nx" ) ) );

		$direct = $this->announcements();

		self::assertStringContainsString( 'href="https://loop.agend.com.au/home/wdaa/loop/news"', $direct );
		self::assertStringContainsString( 'href="https://loop.agend.com.au/home/wdaa/loop">Open the community', $direct );
	}

	#[Test]
	public function should_send_every_item_to_one_page_or_nowhere_when_asked(): void {
		$news  = $this->news();
		$posts = array( agend_cm_test_post( 'x', $news, "## Notice\n\nx" ) );

		$this->seedAnnouncements( array( $news ), $posts );
		$custom = $this->announcements( array( 'link_mode' => 'custom', 'link_url' => array( 'url' => 'https://example.org/join/' ), 'show_cta' => '' ) );

		self::assertStringContainsString( 'href="https://example.org/join/"', $custom );
		self::assertStringNotContainsString( 'loop-channel', $custom );

		$this->resetSite();
		$this->seedAnnouncements( array( $news ), $posts );
		$none = $this->announcements( array( 'link_mode' => 'none', 'show_cta' => '' ) );

		self::assertStringNotContainsString( '<a ', $none );
		self::assertStringNotContainsString( 'agend-community-announcements__notice--link', $none );
		self::assertStringContainsString( 'Notice', $none );
	}

	#[Test]
	public function should_use_the_editors_community_link_and_refuse_an_address_that_is_not_a_page(): void {
		$news  = $this->news();
		$posts = array( agend_cm_test_post( 'x', $news, "## Notice\n\nx" ) );

		$this->seedAnnouncements( array( $news ), $posts );
		$html = $this->announcements( array( 'cta_label' => 'Join us', 'cta_url' => 'https://example.org/how-to-join/' ) );

		self::assertStringContainsString( 'href="https://example.org/how-to-join/"', $html );
		self::assertStringContainsString( '>Join us', $html );

		$this->resetSite();
		$this->seedAnnouncements( array( $news ), $posts );
		$unsafe = $this->announcements( array( 'cta_url' => 'javascript:alert(1)', 'link_mode' => 'custom', 'link_url' => 'data:text/html,x' ) );

		self::assertStringNotContainsString( 'javascript:', $unsafe );
		self::assertStringNotContainsString( 'data:', $unsafe );
		self::assertStringContainsString( 'loop-home', $unsafe, 'falls back to the community itself' );
	}

	#[Test]
	public function should_open_links_in_a_new_tab_safely_and_say_so(): void {
		$news = $this->news();
		$this->seedAnnouncements( array( $news ), array( agend_cm_test_post( 'x', $news, "## Notice\n\nx" ) ) );

		$html = $this->announcements( array( 'links_new_tab' => 'yes' ) );

		self::assertStringContainsString( 'target="_blank" rel="noopener"', $html );
		self::assertStringContainsString( '(opens in a new tab)', $html );
	}

	#[Test]
	public function should_draw_the_same_page_for_every_visitor(): void {
		$news  = $this->news();
		$posts = array( agend_cm_test_post( 'x', $news, "## Notice\n\nx" ) );

		$this->seedAnnouncements( array( $news ), $posts );
		$visitor = $this->announcements();

		$this->resetSite();
		Agend_Test_WP::$options['agend_apps_account_slug']       = 'wdaa';
		Agend_Test_WP::$options['agend_apps_member_auth_mode']   = 'wordpress';
		Agend_Test_WP::$options['agend_apps_root_url_for_tests'] = 'https://api.agend.com.au';
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'member-token' );
		wp_set_current_user( 7 );
		$this->seedAnnouncements( array( $news ), $posts );

		self::assertSame( $visitor, $this->announcements(), 'a signed-in member sees the same markup' );
		self::assertArrayNotHasKey( 'Authorization', Agend_Test_WP::$requests[0]['headers'], 'and the read carried no member token' );
	}

	// -----------------------------------------------------------------
	// States
	// -----------------------------------------------------------------

	#[Test]
	public function should_say_so_when_there_is_nothing_to_show_and_keep_the_way_in(): void {
		$news = $this->news();
		$this->seedAnnouncements( array( $news ), array() );

		$html = $this->announcements();

		self::assertStringContainsString( 'role="status"', $html );
		self::assertStringContainsString( 'There are no announcements right now.', $html );
		self::assertStringContainsString( 'Announcements</h2>', $html, 'the heading stays above the message' );
		self::assertStringContainsString( 'Open the community', $html );
		self::assertStringNotContainsString( '<ul class="agend-community-announcements__list"', $html );
	}

	#[Test]
	public function should_show_the_message_the_editor_wrote_and_fall_back_when_it_is_blank(): void {
		$news = $this->news();
		$this->seedAnnouncements( array( $news ), array() );
		self::assertStringContainsString( 'Nothing &lt;yet&gt;', $this->announcements( array( 'empty_message' => 'Nothing <yet>' ) ) );

		$this->resetSite();
		$this->seedAnnouncements( array( $news ), array() );
		self::assertStringContainsString( 'There are no announcements right now.', $this->announcements( array( 'empty_message' => '  ' ) ) );
	}

	#[Test]
	public function should_draw_nothing_for_an_empty_list_when_hiding_the_widget_but_not_for_a_failure(): void {
		$news = $this->news();
		$this->seedAnnouncements( array( $news ), array() );
		self::assertSame( '', $this->announcements( array( 'hide_when_empty' => 'yes' ) ) );

		$this->resetSite();
		agend_cm_test_allow( array( $news ) );
		agend_cm_test_queue_channels( array( $news ) );
		Agend_Test_WP::queue_response( 500, array( 'error' => array( 'message' => 'Down.' ) ) );

		$html = $this->announcements( array( 'hide_when_empty' => 'yes' ) );

		self::assertStringContainsString( 'Unable to load announcements.', $html );
		self::assertStringContainsString( 'agend-community__status--error', $html );
		self::assertStringNotContainsString( 'There are no announcements', $html );
	}

	#[Test]
	public function should_tell_a_visitor_nothing_about_settings_and_an_editor_where_to_allow_channels(): void {
		$visitor = $this->announcements();

		self::assertStringContainsString( 'There are no announcements right now.', $visitor );
		self::assertStringNotContainsString( 'Settings', $visitor );
		self::assertCount( 0, Agend_Test_WP::$requests, 'nothing is allowed, so nothing is asked for' );

		$editor = $this->announcements( array(), array( 'preview' => true ) );

		self::assertStringContainsString( 'No channels are allowed on the website yet.', $editor );
		self::assertStringContainsString( 'Settings &gt; Agend Apps &gt; Community', $editor );
		self::assertStringContainsString( 'role="note"', $editor );
	}

	#[Test]
	public function should_not_show_the_editor_note_once_a_channel_is_allowed(): void {
		$news = $this->news();
		$this->seedAnnouncements( array( $news ), array() );

		self::assertStringNotContainsString( 'No channels are allowed', $this->announcements( array(), array( 'preview' => true ) ) );
	}

	#[Test]
	public function should_hold_announcements_to_both_loop_scopes_and_call_nothing_without_them(): void {
		$news = $this->news();
		$this->seedAnnouncements( array( $news ), array( agend_cm_test_post( 'x', $news, "## Notice\n\nx" ) ) );
		$this->know( array( 'loop.activity.read' ) );

		self::assertSame( '', $this->announcements() );
		self::assertCount( 0, Agend_Test_WP::$requests );
		self::assertSame( array( 'loop.activity.read', 'loop.channels.browse' ), agend_apps_records_surface_requirements( 'community-announcements' )['scopes'] );

		$this->know( array( 'loop.activity.read', 'loop.channels.browse' ) );
		self::assertStringContainsString( 'Notice', $this->announcements() );
	}

	#[Test]
	public function should_draw_nothing_inside_a_card_template(): void {
		$news = $this->news();
		$this->seedAnnouncements( array( $news ), array( agend_cm_test_post( 'x', $news, "## Notice\n\nx" ) ) );
		Agend_Apps_Records_Record_Context::push( 'course', array( 'slug' => 'a-course' ) );

		self::assertSame( '', $this->announcements() );
		self::assertCount( 0, Agend_Test_WP::$requests );
	}

	// -----------------------------------------------------------------
	// Channels
	// -----------------------------------------------------------------

	#[Test]
	public function should_draw_channels_as_cards_with_a_clear_way_in(): void {
		$news = $this->news();
		$this->seedChannels( array( $news, agend_cm_test_channel( 'general', array( 'member_count' => 1, 'description' => 'Chat & share.' ) ) ) );

		$html = $this->channels();

		self::assertStringContainsString( 'agend-community--channels', $html );
		self::assertStringContainsString( '<h2 class="agend-community__title">Community channels</h2>', $html );
		self::assertStringContainsString( '<h3 class="agend-community-channels__name">General</h3>', $html );
		self::assertStringContainsString( 'Chat &amp; share.', $html );
		self::assertStringContainsString( '1 member<', $html );
		self::assertStringContainsString( '42 members<', $html );
		self::assertStringContainsString( 'href="https://api.agend.com.au/sso/wdaa/loop-channel/general"', $html );
		self::assertStringContainsString( 'Open channel<span class="agend-community__sr">: General</span>', $html );
		self::assertSame( array( 'General', 'Member News' ), $this->names( $html ), 'by name' );
	}

	/**
	 * @param array[] $channels Channel rows, all allowed.
	 */
	private function seedChannels( array $channels ): void {
		agend_cm_test_allow( $channels );
		agend_cm_test_queue_channels( $channels );
	}

	/**
	 * @param string $html Markup.
	 * @return string[] Channel names, in order.
	 */
	private function names( string $html ): array {
		preg_match_all( '#<h3 class="agend-community-channels__name">(.*?)</h3>#s', $html, $match );

		return array_map( 'html_entity_decode', $match[1] );
	}

	#[Test]
	public function should_filter_sort_and_count_channels(): void {
		$rows = array(
			agend_cm_test_channel( 'a-chat', array( 'channel_type' => 'channel', 'member_count' => 5 ) ),
			agend_cm_test_channel( 'b-feed', array( 'channel_type' => 'feed', 'member_count' => 50 ) ),
			agend_cm_test_channel( 'c-feed', array( 'channel_type' => 'feed', 'member_count' => 20 ) ),
			agend_cm_test_channel( 'd-chat', array( 'channel_type' => 'channel', 'member_count' => 90 ) ),
		);
		$this->seedChannels( $rows );
		self::assertSame( array( 'B Feed', 'C Feed' ), $this->names( $this->channels( array( 'channel_kind' => 'feed' ) ) ) );

		$this->resetSite();
		$this->seedChannels( $rows );
		self::assertSame( array( 'D Chat', 'B Feed' ), $this->names( $this->channels( array( 'sort' => 'members', 'count' => 2 ) ) ) );

		$this->resetSite();
		$this->seedChannels( $rows );
		self::assertSame( array( 'C Feed' ), $this->names( $this->channels( array( 'channels' => array( 'chan-c-feed', 'chan-not-allowed' ) ) ) ) );
	}

	#[Test]
	public function should_let_the_editor_word_the_link_and_switch_parts_off(): void {
		$this->seedChannels( array( agend_cm_test_channel( 'general' ) ) );

		$html = $this->channels( array( 'card_link_label' => 'Join channel', 'show_description' => '', 'show_members' => '' ) );

		self::assertStringContainsString( 'Join channel<span', $html );
		self::assertStringNotContainsString( 'About general.', $html );
		self::assertStringNotContainsString( 'agend-community-channels__members', $html );

		$this->resetSite();
		$this->seedChannels( array( agend_cm_test_channel( 'general' ) ) );

		$plain = $this->channels( array( 'link_mode' => 'none', 'show_cta' => '' ) );

		self::assertStringNotContainsString( '<a ', $plain );
		self::assertStringNotContainsString( 'agend-community-channels__card--link', $plain );
	}

	#[Test]
	public function should_set_the_columns_and_keep_them_in_bounds(): void {
		$this->seedChannels( array( agend_cm_test_channel( 'general' ) ) );

		$html = $this->channels( array( 'columns_desktop' => '9', 'columns_tablet' => '0', 'columns_mobile' => '2', 'card_radius' => 99 ) );

		self::assertStringContainsString( '--agend-cm-cols-desktop:4;--agend-cm-cols-tablet:1;--agend-cm-cols-mobile:2;', $html );
		self::assertStringContainsString( '--agend-cm-card-radius:48px;', $html );
	}

	#[Test]
	public function should_drop_a_channel_the_site_has_not_allowed_even_when_the_editor_chose_it(): void {
		$general = agend_cm_test_channel( 'general' );
		$secret  = agend_cm_test_channel( 'board-only' );
		agend_cm_test_allow( array( $general ) );
		agend_cm_test_queue_channels( array( $general, $secret ) );

		$html = $this->channels( array( 'channels' => array( 'chan-board-only' ) ) );

		self::assertStringNotContainsString( 'Board Only', $html );
		self::assertStringContainsString( 'There are no community channels to show yet.', $html );
	}

	#[Test]
	public function should_need_only_the_channels_scope_for_channels(): void {
		$this->seedChannels( array( agend_cm_test_channel( 'general' ) ) );
		$this->know( array( 'loop.channels.browse' ) );

		self::assertSame( array( 'loop.channels.browse' ), agend_apps_records_surface_requirements( 'community-channels' )['scopes'] );
		self::assertStringContainsString( 'General', $this->channels() );

		$this->know( array( 'loop.activity.read' ) );
		self::assertSame( '', $this->channels() );
	}

	// -----------------------------------------------------------------
	// Popular posts
	// -----------------------------------------------------------------

	#[Test]
	public function should_follow_loops_own_rule_for_popular_posts_featured_first_then_replies(): void {
		$news  = $this->news();
		$posts = array(
			agend_cm_test_post( 'few', $news, "## Few replies\n\nx", array( 'reply_count' => 4 ), 'discussion' ),
			agend_cm_test_post( 'five', $news, "## Five replies\n\nx", array( 'reply_count' => 5 ), 'discussion' ),
			agend_cm_test_post( 'nine', $news, "## Nine replies\n\nx", array( 'reply_count' => 9 ), 'question' ),
			agend_cm_test_post( 'feat0', $news, "## Featured quiet\n\nx", array( 'is_featured' => true ), 'update' ),
			agend_cm_test_post( 'feat3', $news, "## Featured busy\n\nx", array( 'is_featured' => true, 'reply_count' => 3 ), 'update' ),
			agend_cm_test_post( 'chat', $news, 'Chat with lots of replies.', array( 'reply_count' => 30 ), '' ),
		);
		$this->seedFeed( array( $news ), $posts );

		$html = $this->feed();

		self::assertSame( array( 'Featured busy', 'Featured quiet', 'Nine replies', 'Five replies' ), $this->headlines( $html ), 'chat never counts, and four replies is not popular' );
		self::assertStringContainsString( '<h2 class="agend-community__title">Popular posts</h2>', $html );
	}

	#[Test]
	public function should_show_fewer_posts_rather_than_pad_a_popular_list(): void {
		$news = $this->news();
		$this->seedFeed(
			array( $news ),
			array(
				agend_cm_test_post( 'p1', $news, "## Popular\n\nx", array( 'reply_count' => 8 ), 'discussion' ),
				agend_cm_test_post( 'p2', $news, "## Ordinary one\n\nx", array( 'reply_count' => 1 ), 'discussion' ),
				agend_cm_test_post( 'p3', $news, "## Ordinary two\n\nx", array(), 'discussion' ),
			)
		);

		self::assertSame( array( 'Popular' ), $this->headlines( $this->feed( array( 'count' => 5 ) ) ) );
	}

	#[Test]
	public function should_let_the_editor_change_how_many_replies_make_a_post_popular(): void {
		$news  = $this->news();
		$posts = array(
			agend_cm_test_post( 'p1', $news, "## Two replies\n\nx", array( 'reply_count' => 2 ), 'discussion' ),
			agend_cm_test_post( 'p2', $news, "## Eight replies\n\nx", array( 'reply_count' => 8 ), 'discussion' ),
		);

		$this->seedFeed( array( $news ), $posts );
		self::assertSame( array( 'Eight replies', 'Two replies' ), $this->headlines( $this->feed( array( 'popular_min_replies' => 2 ) ) ) );

		$this->resetSite();
		$this->seedFeed( array( $news ), $posts );
		self::assertSame( array( 'Eight replies' ), $this->headlines( $this->feed( array( 'popular_min_replies' => 6 ) ) ) );
	}

	#[Test]
	public function should_list_newest_first_without_chat_unless_chat_is_switched_on(): void {
		$news  = $this->news();
		$posts = array(
			agend_cm_test_post( 'old', $news, "## Older post\n\nx", array( 'created_at' => '2026-11-10T00:00:00+00:00' ), 'update' ),
			agend_cm_test_post( 'new', $news, "## Newer post\n\nx", array( 'created_at' => '2026-11-16T00:00:00+00:00' ), 'update' ),
			agend_cm_test_post( 'chat', $news, 'Chat message here!', array( 'created_at' => '2026-11-17T00:00:00+00:00' ), '' ),
		);

		$this->seedFeed( array( $news ), $posts );
		self::assertSame( array( 'Newer post', 'Older post' ), $this->headlines( $this->feed( array( 'order' => 'recent', 'max_age_days' => 0 ) ) ) );

		$this->resetSite();
		$this->seedFeed( array( $news ), $posts );
		self::assertSame( array( 'Chat message here!', 'Newer post', 'Older post' ), $this->headlines( $this->feed( array( 'order' => 'recent', 'include_untitled' => 'yes', 'max_age_days' => 0 ) ) ) );

		$this->resetSite();
		$this->seedFeed( array( $news ), $posts );
		self::assertSame( array(), $this->headlines( $this->feed( array( 'order' => 'popular', 'include_untitled' => 'yes' ) ) ), 'chat is never popular' );
	}

	#[Test]
	public function should_hide_popular_posts_older_than_the_limit(): void {
		$news = $this->news();
		$this->seedFeed(
			array( $news ),
			array(
				agend_cm_test_post( 'new', $news, "## Fresh\n\nx", array( 'reply_count' => 9, 'created_at' => gmdate( 'c', time() - 5 * DAY_IN_SECONDS ) ), 'discussion' ),
				agend_cm_test_post( 'old', $news, "## Stale\n\nx", array( 'reply_count' => 99, 'is_featured' => true, 'created_at' => gmdate( 'c', time() - 120 * DAY_IN_SECONDS ) ), 'discussion' ),
			)
		);

		self::assertSame( array( 'Fresh' ), $this->headlines( $this->feed() ) );
	}

	#[Test]
	public function should_draw_the_reply_count_the_channel_the_featured_label_and_a_relative_date(): void {
		$news = $this->news();
		$this->seedFeed(
			array( $news ),
			array(
				agend_cm_test_post( 'p1', $news, "## Busy\n\nx", array( 'reply_count' => 12, 'is_featured' => true, 'created_at' => gmdate( 'c', time() - 3 * DAY_IN_SECONDS ) ), 'discussion' ),
				agend_cm_test_post( 'p2', $news, "## Single\n\nx", array( 'reply_count' => 1, 'is_featured' => true, 'created_at' => gmdate( 'c', time() - 4 * DAY_IN_SECONDS ) ), 'discussion' ),
				agend_cm_test_post( 'p3', $news, "## Quiet\n\nx", array( 'is_featured' => true, 'created_at' => gmdate( 'c', time() - 5 * DAY_IN_SECONDS ) ), 'discussion' ),
			)
		);

		$html = $this->feed( array( 'layout' => 'list' ) );

		self::assertStringContainsString( '<span class="agend-community-feed__count">12</span><span class="agend-community-feed__word">replies</span>', $html );
		self::assertStringContainsString( '<span class="agend-community-feed__count">1</span><span class="agend-community-feed__word">reply</span>', $html );
		self::assertStringContainsString( 'agend-community-feed__replies agend-community-feed__replies--none', $html );
		self::assertStringContainsString( 'agend-community__flag">Featured', $html );
		self::assertStringContainsString( 'Member News', $html );
		self::assertMatchesRegularExpression( '#<time class="agend-community__time" datetime="[^"]+" title="[^"]+">3 days ago</time>#', $html );
	}

	#[Test]
	public function should_show_authors_in_the_popular_list_only_as_far_as_the_mode_allows(): void {
		$news  = $this->news();
		$posts = array( agend_cm_test_post( 'p1', $news, "## Busy\n\nx", array( 'reply_count' => 12 ), 'discussion' ) );

		$this->seedFeed( array( $news ), $posts );
		$none = $this->feed();
		self::assertStringNotContainsString( 'Ben', $none );
		self::assertStringNotContainsString( 'agend-community__author', $none );

		$this->resetSite();
		$this->seedFeed( array( $news ), $posts );
		$some = $this->feed( array( 'author_display' => 'first_name_initial' ) );
		self::assertStringContainsString( '>Ben T.<', $some );
		self::assertStringNotContainsString( '<img', $some );
	}

	#[Test]
	public function should_read_only_the_chosen_channels_for_popular_posts(): void {
		$news = $this->news();
		$jobs = agend_cm_test_channel( 'jobs', array( 'name' => 'Jobs' ) );
		agend_cm_test_allow( array( $news, $jobs ) );
		agend_cm_test_queue_channels( array( $news, $jobs ) );
		agend_cm_test_queue_activity( array( agend_cm_test_post( 'j1', $jobs, "## Job post\n\nx", array( 'reply_count' => 7 ), 'discussion' ) ) );

		$html = $this->feed( array( 'channels' => array( 'chan-jobs' ) ) );

		self::assertSame( array( 'Job post' ), $this->headlines( $html ) );
		self::assertCount( 2, Agend_Test_WP::$requests );
		self::assertStringContainsString( 'channel_slug=jobs', Agend_Test_WP::$requests[1]['url'] );
	}

	#[Test]
	public function should_say_what_the_empty_popular_list_means_for_each_order_when_the_editor_left_the_message_blank(): void {
		$news = $this->news();
		$this->seedFeed( array( $news ), array() );
		self::assertStringContainsString( 'There are no popular posts right now.', $this->feed( array( 'empty_message' => '' ) ) );

		$this->resetSite();
		$this->seedFeed( array( $news ), array() );
		self::assertStringContainsString( 'There are no posts right now.', $this->feed( array( 'order' => 'recent', 'empty_message' => '' ) ) );
	}

	#[Test]
	public function should_hold_popular_posts_to_both_loop_scopes(): void {
		self::assertSame( array( 'loop.activity.read', 'loop.channels.browse' ), agend_apps_records_surface_requirements( 'community-feed' )['scopes'] );
	}

	// -----------------------------------------------------------------
	// Popular posts: cards and list
	// -----------------------------------------------------------------

	private function featuredPost( array $news ): array {
		return agend_cm_test_post( 'p1', $news, "## Busy thread\n\nWhat members said about the new conference programme.", array( 'reply_count' => 12, 'is_featured' => true, 'created_at' => gmdate( 'c', time() - 3 * DAY_IN_SECONDS ) ), 'discussion' );
	}

	#[Test]
	public function should_draw_popular_posts_as_cards_by_default(): void {
		$news = $this->news();
		$this->seedFeed( array( $news ), array( $this->featuredPost( $news ) ) );

		$html = $this->feed();

		self::assertStringContainsString( '<ul class="agend-community-feed__grid" role="list">', $html );
		self::assertStringNotContainsString( 'agend-community-feed__list', $html );
		self::assertStringContainsString( '<article class="agend-community-feed__card agend-community-feed__card--link">', $html );
		self::assertMatchesRegularExpression( '#<span class="agend-community-feed__tag">.*?<span>Member News</span></span>#s', $html, 'the channel label' );
		self::assertStringContainsString( 'agend-community-feed__tag agend-community-feed__tag--flag">Featured<', $html );
		self::assertMatchesRegularExpression( '#<h3 class="agend-community-feed__headline"><a class="agend-community-feed__link exclude-agend-theme-link" href="[^"]+loop-channel/news">Busy thread#', $html );
		self::assertStringContainsString( '<p class="agend-community-feed__excerpt">What members said about the new conference programme.</p>', $html, 'cards show the excerpt by default' );
		self::assertMatchesRegularExpression( '#<div class="agend-community-feed__foot"><div class="agend-community-feed__who"><time class="agend-community__time"[^>]*>3 days ago</time></div><p class="agend-community-feed__replies">.*?<span>12 replies</span></p></div>#s', $html, 'date on the left, replies on the right' );
		self::assertStringContainsString( '--agend-cm-cols-desktop:3;--agend-cm-cols-tablet:2;--agend-cm-cols-mobile:1;', $html );
		self::assertSame( 1, substr_count( $html, '<a class="agend-community-feed__link' ), 'one link per card: the whole card is the target' );
	}

	#[Test]
	public function should_say_when_a_card_has_no_replies_and_leave_the_footer_out_when_it_has_nothing_to_say(): void {
		$news = $this->news();
		$post = agend_cm_test_post( 'p1', $news, "## Quiet\n\nx", array( 'is_featured' => true ), 'discussion' );
		$this->seedFeed( array( $news ), array( $post ) );

		self::assertStringContainsString( 'agend-community-feed__replies agend-community-feed__replies--none"', $this->feed() );
		self::assertStringContainsString( '<span>No replies yet</span>', $this->feed() );

		$this->resetSite();
		$this->seedFeed( array( $news ), array( $post ) );

		$bare = $this->feed( array( 'show_reply_count' => '', 'show_date' => '', 'show_channel' => '', 'show_featured_badge' => '', 'show_excerpt' => '' ) );

		self::assertStringNotContainsString( 'agend-community-feed__foot', $bare );
		self::assertStringNotContainsString( 'agend-community-feed__tags', $bare );
		self::assertStringNotContainsString( 'agend-community-feed__excerpt', $bare );
		self::assertStringContainsString( 'Quiet', $bare );
	}

	#[Test]
	public function should_keep_the_list_layout_when_asked_and_leave_the_columns_out_of_it(): void {
		$news = $this->news();
		$this->seedFeed( array( $news ), array( $this->featuredPost( $news ) ) );

		$html = $this->feed( array( 'layout' => 'list' ) );

		self::assertStringContainsString( '<ul class="agend-community-feed__list" role="list">', $html );
		self::assertStringNotContainsString( 'agend-community-feed__grid', $html );
		self::assertStringNotContainsString( 'agend-community-feed__card', $html );
		self::assertStringNotContainsString( '--agend-cm-cols-desktop', $html );
	}

	#[Test]
	public function should_default_to_cards_in_three_two_and_one_columns_and_keep_the_columns_in_bounds(): void {
		$config = agend_apps_records_community_feed_build_config( array() );

		self::assertSame( array( 'mode' => 'cards', 'desktop' => 3, 'tablet' => 2, 'mobile' => 1 ), $config['layout'] );

		$wild = agend_apps_records_community_feed_build_config( array( 'layout' => 'masonry', 'columns_desktop' => '9', 'columns_tablet' => '0', 'columns_mobile' => '7' ) );

		self::assertSame( array( 'mode' => 'cards', 'desktop' => 4, 'tablet' => 1, 'mobile' => 2 ), $wild['layout'] );
		self::assertSame( 'list', agend_apps_records_community_feed_build_config( array( 'layout' => 'list' ) )['layout']['mode'] );
	}

	#[Test]
	public function should_offer_the_columns_only_for_cards_with_the_same_choices_as_the_other_widgets(): void {
		$fields = array();

		foreach ( agend_apps_records_surface_schema( 'community-feed' )['sections'] as $section ) {
			foreach ( $section['fields'] as $field ) {
				$fields[ $field['name'] ] = $field;
			}
		}

		self::assertSame( array( 'cards', 'list' ), array_keys( $fields['layout']['options'] ) );
		self::assertSame( 'cards', $fields['layout']['default'] );
		self::assertSame( array( '1', '2', '3', '4' ), array_map( 'strval', array_keys( $fields['columns_desktop']['options'] ) ) );
		self::assertSame( '3', $fields['columns_desktop']['default'] );
		self::assertSame( '2', $fields['columns_tablet']['default'] );
		self::assertSame( '1', $fields['columns_mobile']['default'] );

		foreach ( array( 'columns_desktop', 'columns_tablet', 'columns_mobile' ) as $name ) {
			self::assertSame( array( 'layout' => 'cards' ), $fields[ $name ]['condition'], $name );
		}
	}

	// -----------------------------------------------------------------
	// Authors in the new shapes
	// -----------------------------------------------------------------

	#[Test]
	public function should_show_no_author_circle_in_a_card_by_default_and_the_date_stands_alone(): void {
		$news = $this->news();
		$this->seedFeed( array( $news ), array( $this->featuredPost( $news ) ) );

		$html = $this->feed();

		self::assertStringNotContainsString( 'agend-community__author', $html );
		self::assertStringNotContainsString( 'Ben', $html );
		self::assertStringNotContainsString( '<img', $html );
		self::assertStringContainsString( '<div class="agend-community-feed__who"><time', $html );
	}

	#[Test]
	public function should_lead_a_card_author_with_their_initials_and_put_the_date_under_the_name(): void {
		$news = $this->news();
		$this->seedFeed( array( $news ), array( $this->featuredPost( $news ) ) );

		$html = $this->feed( array( 'author_display' => 'first_name_initial' ) );

		self::assertMatchesRegularExpression( '#<span class="agend-community__author"><span class="agend-community__avatar agend-community__avatar--letters" aria-hidden="true">BT</span><span class="agend-community__author-text"><span class="agend-community__author-name">Ben T\.</span><time class="agend-community__time"#', $html );
		self::assertStringNotContainsString( '<img', $html, 'initials are not a photo' );
		self::assertStringNotContainsString( 'Ben Thompson', $html );
	}

	#[Test]
	public function should_lead_a_card_author_with_their_photo_only_when_photos_are_on(): void {
		$news = $this->news();
		$post = $this->featuredPost( $news );
		$this->seedFeed( array( $news ), array( $post ) );

		$with = $this->feed( array( 'author_display' => 'first_name', 'show_avatar' => 'yes' ) );

		self::assertStringContainsString( '<img class="agend-community__avatar" src="https://avatars.example.test/ben.png"', $with );
		self::assertStringNotContainsString( 'avatar--letters', $with, 'a photo takes the circle' );
	}

	#[Test]
	public function should_not_draw_a_second_circle_when_the_name_is_only_initials(): void {
		$news = $this->news();
		$this->seedFeed( array( $news ), array( $this->featuredPost( $news ) ) );

		$html = $this->feed( array( 'author_display' => 'initials' ) );

		self::assertStringContainsString( '>BT<', $html );
		self::assertSame( 0, substr_count( $html, 'avatar--letters' ) );
	}

	#[Test]
	public function should_draw_an_announcement_byline_with_a_circle_and_the_name_under_the_text(): void {
		$news = $this->news();
		$this->seedAnnouncements( array( $news ), array( agend_cm_test_post( 'x', $news, "## Notice\n\nBody." ) ) );

		$html = $this->announcements( array( 'author_display' => 'full' ) );

		self::assertMatchesRegularExpression( '#<p class="agend-community-announcements__excerpt">Body\.</p><p class="agend-community-announcements__byline"><span class="agend-community__author"><span class="agend-community__avatar agend-community__avatar--letters" aria-hidden="true">BT</span><span class="agend-community__author-name">Ben Thompson</span></span></p>#', $html );
	}

	#[Test]
	public function should_keep_the_author_defaults_cautious_in_every_new_shape(): void {
		$news = $this->news();
		$this->seedAnnouncements( array( $news ), array( agend_cm_test_post( 'x', $news, "## Notice\n\nBody." ) ) );

		$config = agend_apps_records_community_feed_build_config( array() );

		self::assertSame( 'none', $config['author']['mode'] );
		self::assertFalse( $config['author']['avatar'] );
		self::assertStringNotContainsString( 'agend-community-announcements__byline', $this->announcements() );
	}

	// -----------------------------------------------------------------
	// Automated posts
	// -----------------------------------------------------------------

	/** @return array[] A bot's post, the system's, a join message and a member's post, all in one channel. */
	private function mixedAnnouncements( array $news ): array {
		$bot = agend_cm_test_post( 'bot', $news, "## Event published\n\nWorkshop on Friday." );
		$bot['author']['display_name'] = 'Agend Workflows Bot';
		$system = agend_cm_test_post( 'sys', $news, "## From the system user\n\nText." );
		$system['author']['id'] = '00000000-0000-0000-0000-000000000001';
		$joined = agend_cm_test_post( 'joined', $news, 'Members joined the channel.' );
		$joined['author']['id'] = '00000000-0000-0000-0000-000000000001';

		return array( $bot, $system, $joined, agend_cm_test_post( 'real', $news, "## Real news\n\nBody." ) );
	}

	#[Test]
	public function should_leave_automated_posts_out_unless_asked(): void {
		$news = $this->news();
		$this->seedAnnouncements( array( $news ), $this->mixedAnnouncements( $news ) );

		self::assertSame( array( 'Real news' ), $this->headlines( $this->announcements() ) );
		self::assertFalse( agend_apps_records_community_announcements_build_config( array() )['source']['automated'] );
	}

	#[Test]
	public function should_let_automated_posts_through_when_asked_but_never_a_system_message(): void {
		$news = $this->news();
		$this->seedAnnouncements( array( $news ), $this->mixedAnnouncements( $news ) );

		$headlines = $this->headlines( $this->announcements( array( 'include_automated' => 'yes' ) ) );

		self::assertEqualsCanonicalizing( array( 'Event published', 'From the system user', 'Real news' ), $headlines );
		self::assertStringNotContainsString( 'Members joined', implode( '', $headlines ) );
	}

	#[Test]
	public function should_keep_each_mode_apart_so_one_never_serves_the_other(): void {
		$news  = $this->news();
		$posts = $this->mixedAnnouncements( $news );
		$this->seedAnnouncements( array( $news ), $posts );

		self::assertSame( array( 'Real news' ), $this->headlines( $this->announcements() ) );

		// The other mode is a different stored copy, so it reads again.
		agend_cm_test_queue_announcements( $posts );
		self::assertContains( 'Event published', $this->headlines( $this->announcements( array( 'include_automated' => 'yes' ) ) ) );

		// And back: the first mode's copy never held the automated post.
		agend_cm_test_queue_announcements( $posts );
		self::assertSame( array( 'Real news' ), $this->headlines( $this->announcements() ) );
	}

	#[Test]
	public function should_let_automated_posts_into_popular_posts_only_when_asked_and_only_if_they_qualify(): void {
		$news = $this->news();
		$bot  = agend_cm_test_post( 'bot', $news, "## Event published\n\nWorkshop.", array( 'reply_count' => 8 ), 'update' );
		$bot['author']['display_name'] = 'Agend Workflows Bot';
		$chatty = agend_cm_test_post( 'chatty', $news, 'A bot saying hello.', array( 'reply_count' => 9 ), '' );
		$chatty['author']['display_name'] = 'Agend Workflows Bot';
		$joined = agend_cm_test_post( 'joined', $news, 'Members joined the channel.', array( 'reply_count' => 9 ), 'update' );
		$real   = agend_cm_test_post( 'real', $news, "## Real thread\n\nx", array( 'reply_count' => 6 ), 'discussion' );

		$this->seedFeed( array( $news ), array( $bot, $chatty, $joined, $real ) );
		self::assertSame( array( 'Real thread' ), $this->headlines( $this->feed() ) );

		$this->resetSite();
		$this->seedFeed( array( $news ), array( $bot, $chatty, $joined, $real ) );

		// Popular ranks by replies, so the bot's titled post leads; its chat
		// message is still chat and the join message is still a system message.
		self::assertSame( array( 'Event published', 'Real thread' ), $this->headlines( $this->feed( array( 'include_automated' => 'yes' ) ) ) );
	}

	#[Test]
	public function should_word_the_automated_posts_setting_plainly_and_leave_it_off_for_both_surfaces(): void {
		foreach ( array( 'community-announcements', 'community-feed' ) as $surface ) {
			$field = null;

			foreach ( agend_apps_records_surface_schema( $surface )['sections'] as $section ) {
				foreach ( $section['fields'] as $candidate ) {
					if ( 'include_automated' === $candidate['name'] ) {
						$field = $candidate;
					}
				}
			}

			self::assertNotNull( $field, $surface );
			self::assertSame( 'Include automated posts', $field['label'] );
			self::assertFalse( $field['default'] );
			self::assertStringContainsString( 'Posts created automatically, such as a notice when an event is published.', $field['description'] );
		}

		$channels = array_merge( ...array_column( agend_apps_records_surface_schema( 'community-channels' )['sections'], 'fields' ) );
		self::assertNotContains( 'include_automated', array_column( $channels, 'name' ), 'channels have no posts' );
	}

	// -----------------------------------------------------------------
	// Channel tiles
	// -----------------------------------------------------------------

	#[Test]
	public function should_lead_each_channel_with_an_icon_for_its_kind_by_default(): void {
		$this->seedChannels( array( $this->news(), agend_cm_test_channel( 'general' ) ) );

		$html = $this->channels();

		self::assertMatchesRegularExpression( '#<span class="agend-community-channels__tile agend-community-channels__tile--icon" aria-hidden="true"><svg class="agend-community__icon agend-community__icon--hash"[^>]*stroke="currentColor"[^>]*aria-hidden="true"#', $html, 'a chat channel: a hash' );
		self::assertMatchesRegularExpression( '#<span class="agend-community-channels__tile agend-community-channels__tile--icon" aria-hidden="true"><svg class="agend-community__icon agend-community__icon--layers"#', $html, 'a feed channel: stacked layers' );
		self::assertStringNotContainsString( 'tile" aria-hidden="true">G<', $html );
	}

	#[Test]
	public function should_draw_the_icons_in_the_text_colour_with_the_same_line_as_the_chevron(): void {
		foreach ( array( 'hash', 'layers', 'reply' ) as $name ) {
			$svg = agend_apps_records_community_icon( $name, 16, 1.75 );

			self::assertStringContainsString( 'stroke="currentColor"', $svg, $name );
			self::assertStringContainsString( 'stroke-width="2.63"', $svg, 'drawn on 24 units so a 16px icon shows a 1.75px line, as the chevron does' );
			self::assertStringContainsString( 'stroke-linecap="round" stroke-linejoin="round"', $svg, $name );
			self::assertStringNotContainsString( 'fill="#', $svg, $name );
		}

		self::assertSame( '', agend_apps_records_community_icon( 'unknown' ) );
	}

	#[Test]
	public function should_keep_the_first_letter_tile_when_chosen(): void {
		$this->seedChannels( array( $this->news(), agend_cm_test_channel( 'general' ) ) );

		$html = $this->channels( array( 'tile_style' => 'letter' ) );

		self::assertStringContainsString( '<span class="agend-community-channels__tile" aria-hidden="true">G</span>', $html );
		self::assertStringContainsString( '<span class="agend-community-channels__tile" aria-hidden="true">M</span>', $html );
		self::assertStringNotContainsString( 'agend-community__icon', $html );
	}

	#[Test]
	public function should_default_the_tile_to_an_icon_and_fall_back_to_it_for_an_unknown_choice(): void {
		self::assertSame( 'icon', agend_apps_records_community_channels_build_config( array() )['card']['tile'] );
		self::assertSame( 'icon', agend_apps_records_community_channels_build_config( array( 'tile_style' => 'photo' ) )['card']['tile'] );
		self::assertSame( 'letter', agend_apps_records_community_channels_build_config( array( 'tile_style' => 'letter' ) )['card']['tile'] );

		$field = array_values( array_filter( array_merge( ...array_column( agend_apps_records_surface_schema( 'community-channels' )['sections'], 'fields' ) ), static fn( array $f ): bool => 'tile_style' === $f['name'] ) )[0] ?? null;

		self::assertNotNull( $field );
		self::assertSame( 'Tile', $field['label'] );
		self::assertSame( array( 'icon' => 'Icon', 'letter' => 'First letter' ), $field['options'] );
	}

	// -----------------------------------------------------------------
	// Schema, editor wording, blocks, stylesheet
	// -----------------------------------------------------------------

	/** @return array<string, array{string}> */
	public static function surfaces(): array {
		return array(
			'announcements' => array( 'community-announcements' ),
			'channels'      => array( 'community-channels' ),
			'popular posts' => array( 'community-feed' ),
		);
	}

	#[Test]
	#[DataProvider( 'surfaces' )]
	public function should_word_every_setting_for_an_association_editor( string $surface ): void {
		$words = array();

		foreach ( agend_apps_records_surface_schema( $surface )['sections'] as $section ) {
			$words[] = (string) $section['label'];

			foreach ( $section['fields'] as $field ) {
				$words[] = (string) ( $field['label'] ?? '' );
				$words[] = (string) ( $field['description'] ?? '' );
				$words[] = is_string( $field['default'] ?? null ) ? $field['default'] : '';

				if ( isset( $field['options'] ) && is_array( $field['options'] ) ) {
					$words = array_merge( $words, array_map( 'strval', array_values( $field['options'] ) ) );
				}
			}
		}

		$text = implode( "\n", $words );

		self::assertDoesNotMatchRegularExpression( '/\b(requests?|cach(e|ed|ing)|api|slugs?|ids?|endpoints?|gateway|uuid|markdown)\b/i', $text, 'no developer-speak in what an editor reads' );
		self::assertStringNotContainsString( "\u{2014}", $text, 'no em dash' );
		self::assertStringNotContainsString( 'trending', strtolower( $text ), 'popular, never trending: nothing here ranks by activity' );
	}

	#[Test]
	#[DataProvider( 'surfaces' )]
	public function should_default_to_the_cautious_choice_for_every_privacy_setting( string $surface ): void {
		$fields = array();

		foreach ( agend_apps_records_surface_schema( $surface )['sections'] as $section ) {
			foreach ( $section['fields'] as $field ) {
				$fields[ $field['name'] ] = $field;
			}
		}

		if ( isset( $fields['author_display'] ) ) {
			self::assertSame( 'none', $fields['author_display']['default'] );
			self::assertSame( array( 'none', 'initials', 'first_name', 'first_name_initial', 'full' ), array_keys( $fields['author_display']['options'] ) );
			self::assertFalse( $fields['show_avatar']['default'] );
		}

		if ( isset( $fields['include_untitled'] ) ) {
			self::assertFalse( $fields['include_untitled']['default'] );
		}

		self::assertSame( 'auto', $fields['link_mode']['default'] );
		self::assertSame( 'multiselect', $fields['channels']['type'] );
		self::assertSame( 'agend_apps_records_community_channel_options', $fields['channels']['options'] );
		self::assertStringContainsString( 'Settings > Agend Apps > Community', $fields['channels']['description'] );
	}

	#[Test]
	public function should_offer_the_channel_picker_only_what_the_site_allows_and_only_to_an_editor(): void {
		$general = agend_cm_test_channel( 'general' );
		$jobs    = agend_cm_test_channel( 'jobs' );
		agend_cm_test_allow( array( $general ) );
		agend_cm_test_queue_channels( array( $general, $jobs ) );

		self::assertSame( array(), agend_apps_records_community_channel_options(), 'a visitor request makes no call' );
		self::assertCount( 0, Agend_Test_WP::$requests );

		\Elementor\Plugin::$instance->editor->is_edit_mode = true;

		self::assertSame( array( 'chan-general' => 'General' ), agend_apps_records_community_channel_options() );
	}

	#[Test]
	public function should_ship_each_surface_as_a_block_with_its_own_name(): void {
		$expected = array(
			'community-announcements' => 'Agend Community Announcements',
			'community-channels'      => 'Agend Community Channels',
			'community-feed'          => 'Agend Community Popular Posts',
		);

		foreach ( $expected as $surface => $title ) {
			self::assertContains( $surface, AGEND_APPS_RECORDS_BLOCK_SURFACES );

			foreach ( array( 'src', 'build' ) as $dir ) {
				$file  = AGEND_TESTS_ROOT . '/agend-apps-core/' . $dir . '/blocks/' . $surface . '/block.json';
				$block = json_decode( (string) file_get_contents( $file ), true );

				self::assertSame( 'agend-apps/' . $surface, $block['name'], $file );
				self::assertSame( $title, $block['title'], $file );
				self::assertSame( array( 'agend-apps-records-community' ), $block['style'], $file );
				self::assertStringNotContainsString( 'trending', strtolower( (string) json_encode( $block ) ), $file );
			}

			self::assertStringContainsString( $title, (string) file_get_contents( AGEND_TESTS_ROOT . '/agend-apps-core/build/blocks/' . $surface . '/index.js' ), 'the built editor script is up to date' );
		}
	}

	#[Test]
	public function should_render_a_block_from_its_attributes(): void {
		$news = $this->news();
		$this->seedAnnouncements( array( $news ), array( agend_cm_test_post( 'x', $news, "## Block notice\n\nx" ) ) );

		$schema     = agend_apps_records_surface_schema( 'community-announcements' );
		$attributes = array_map( static fn( array $a ) => $a['default'], agend_apps_records_block_attributes( $schema ) );

		self::assertStringContainsString( 'Block notice', agend_apps_records_render_block( 'community-announcements', $attributes ) );
	}

	#[Test]
	public function should_style_the_surfaces_without_shadows_and_respect_reduced_motion(): void {
		$css = (string) file_get_contents( AGEND_TESTS_ROOT . '/agend-apps-core/assets/css/community.css' );

		self::assertDoesNotMatchRegularExpression( '/box-shadow\s*:/', (string) preg_replace( '#/\*.*?\*/#s', '', $css ), 'the Agend theme removes shadows; depth is borders' );
		self::assertStringContainsString( 'prefers-reduced-motion', $css );
		self::assertStringContainsString( '@container agend-cm', $css );
		self::assertStringContainsString( 'margin-top: 0 !important', $css, 'cancels the theme\'s ID-level li + li margin' );
		self::assertStringNotContainsString( "\u{2014}", $css );
	}

	#[Test]
	public function should_register_the_shared_stylesheet_and_no_script(): void {
		$assets = (string) file_get_contents( AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/assets.php' );

		self::assertStringContainsString( "'agend-apps-records-community'", $assets );
		self::assertStringContainsString( "css/community.css'", $assets );
		self::assertStringNotContainsString( 'js/community', $assets );
		self::assertFileExists( AGEND_TESTS_ROOT . '/agend-apps-core/assets/css/community.css' );
	}
}
