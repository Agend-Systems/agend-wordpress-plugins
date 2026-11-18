<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\AppsCore;

use Agend_Apps_Endpoint_Scopes;
use Agend_Apps_Logger;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;
use WP_Error;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/logging/class-agend-apps-log-redactor.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/logging/class-agend-apps-log-store.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/logging/class-agend-apps-logger.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/health.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-http-batch.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-key-scopes.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-endpoint-scopes.php';
require_once __DIR__ . '/fixtures/memory-log-store.php';

/**
 * The Community read wrappers: what they ask the gateway for, what they keep,
 * and above all what they refuse to return. Loop's "public" channel is public
 * to the association's members, not to the internet, so the site's allowed list
 * is the only thing that makes a channel's posts fit for a visitor, and every
 * read here is held to it.
 *
 * Each case runs in its own process: the real wrappers are declared here, and
 * the surface tests declare doubles for some of them.
 */
#[RunTestsInSeparateProcesses]
final class LoopPublicReadTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/loop-public.php';
		require_once __DIR__ . '/fixtures/community-stubs.php';
		Agend_Apps_Logger::set_instance( new Agend_Apps_Logger( new MemoryLogStore() ) );
		Agend_Apps_Endpoint_Scopes::set_map( null );
		// Sent one after another through wp_remote_request(), which answers from
		// the queue; the parallel adapter has its own suite.
		Agend_Test_WP::set_filter( 'agend_apps_api_parallel', false );
	}

	protected function tearDown(): void {
		Agend_Apps_Endpoint_Scopes::set_map( null );
		parent::tearDown();
	}

	/** @return string The transients, as text, for "this was never stored" assertions. */
	private function stored(): string {
		return (string) json_encode( Agend_Test_WP::$transients );
	}

	// -----------------------------------------------------------------
	// Fail closed
	// -----------------------------------------------------------------

	#[Test]
	public function should_return_nothing_and_ask_for_nothing_while_no_channel_is_allowed(): void {
		self::assertSame( array(), agend_apps_loop_get_channels() );
		self::assertSame( array(), agend_apps_loop_get_public_announcements() );
		self::assertSame( array(), agend_apps_loop_get_public_activity() );
		self::assertCount( 0, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_return_only_the_channels_the_site_allowed(): void {
		$general  = agend_cm_test_channel( 'general' );
		$jobs     = agend_cm_test_channel( 'job-board' );
		agend_cm_test_allow( array( $general ) );
		agend_cm_test_queue_channels( array( $general, $jobs ) );

		$channels = agend_apps_loop_get_channels();

		self::assertSame( array( 'chan-general' ), array_column( $channels, 'id' ) );
		self::assertStringNotContainsString( 'job-board', $this->stored(), 'a channel that is not allowed is not even stored' );
	}

	#[Test]
	public function should_drop_an_allowed_channel_that_is_not_fit_to_publish(): void {
		$rows = array(
			agend_cm_test_channel( 'ok' ),
			agend_cm_test_channel( 'private-now', array( 'privacy' => 'private' ) ),
			agend_cm_test_channel( 'secret-now', array( 'privacy' => 'secret' ) ),
			agend_cm_test_channel( 'archived', array( 'is_archived' => true ) ),
			agend_cm_test_channel( 'board', array( 'channel_type' => 'committee' ) ),
			agend_cm_test_channel( 'chat', array( 'channel_type' => 'dm' ) ),
			agend_cm_test_channel( 'newer', array( 'channel_type' => 'something-added-later' ) ),
		);
		agend_cm_test_allow( $rows );
		agend_cm_test_queue_channels( $rows );

		self::assertSame( array( 'chan-ok' ), array_column( agend_apps_loop_get_channels(), 'id' ) );
	}

	#[Test]
	public function should_drop_an_allowed_id_that_no_longer_exists_without_an_error(): void {
		Agend_Test_WP::$options[ AGEND_APPS_COMMUNITY_OPTION_CHANNELS ] = array( 'chan-deleted' );
		agend_cm_test_queue_channels( array( agend_cm_test_channel( 'general' ) ) );

		self::assertSame( array(), agend_apps_loop_get_channels() );
	}

	#[Test]
	public function should_keep_a_clean_list_of_ids_whatever_was_stored(): void {
		self::assertSame( array( 'a-1', 'b_2x' ), agend_apps_community_clean_ids( array( 'a-1', ' b_2 <x>', 'a-1', '', array( 'z' ), null ) ) );
		self::assertSame( array( 'a', 'b', 'c' ), agend_apps_community_clean_ids( "a, b\nc  a" ) );
		self::assertSame( array(), agend_apps_community_clean_ids( 5 ) );
		self::assertSame( array( 'abc' ), agend_apps_community_clean_ids( array( 'a b!c' ) ) );
	}

	// -----------------------------------------------------------------
	// Reads
	// -----------------------------------------------------------------

	#[Test]
	public function should_read_the_public_channel_list_as_an_anonymous_visitor_even_for_a_signed_in_member(): void {
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'member-token' );
		$general = agend_cm_test_channel( 'general' );
		agend_cm_test_allow( array( $general ) );
		agend_cm_test_queue_channels( array( $general ) );

		agend_apps_loop_get_channels();

		self::assertCount( 1, Agend_Test_WP::$requests );
		self::assertStringContainsString( '/loop/channels', Agend_Test_WP::$requests[0]['url'] );
		self::assertStringContainsString( 'privacy=public', Agend_Test_WP::$requests[0]['url'] );
		self::assertArrayNotHasKey( 'Authorization', Agend_Test_WP::$requests[0]['headers'], 'no member token goes with a read that is shown to everyone' );
	}

	#[Test]
	public function should_serve_the_second_read_from_the_stored_copy(): void {
		$general = agend_cm_test_channel( 'general' );
		agend_cm_test_allow( array( $general ) );
		agend_cm_test_queue_channels( array( $general ) );

		agend_apps_loop_get_channels();
		$again = agend_apps_loop_get_channels();

		self::assertCount( 1, Agend_Test_WP::$requests );
		self::assertSame( array( 'chan-general' ), array_column( $again, 'id' ) );
	}

	#[Test]
	public function should_not_serve_a_copy_stored_under_a_different_allowed_list(): void {
		$general = agend_cm_test_channel( 'general' );
		$jobs    = agend_cm_test_channel( 'job-board' );
		agend_cm_test_allow( array( $general, $jobs ) );
		agend_cm_test_queue_channels( array( $general, $jobs ) );
		agend_apps_loop_get_channels();

		// The administrator takes a channel off the list.
		agend_cm_test_allow( array( $general ) );
		agend_cm_test_queue_channels( array( $general, $jobs ) );

		self::assertSame( array( 'chan-general' ), array_column( agend_apps_loop_get_channels(), 'id' ) );
		self::assertCount( 2, Agend_Test_WP::$requests, 'the list changed, so the old copy is not used' );
	}

	#[Test]
	public function should_serve_the_longer_lived_copy_when_the_gateway_fails_and_an_error_when_there_is_none(): void {
		$general = agend_cm_test_channel( 'general' );
		agend_cm_test_allow( array( $general ) );

		Agend_Test_WP::queue_response( 500, array( 'error' => array( 'message' => 'Down.' ) ) );
		self::assertInstanceOf( WP_Error::class, agend_apps_loop_get_channels(), 'nothing stored yet' );

		agend_cm_test_queue_channels( array( $general ) );
		agend_apps_loop_get_channels();

		// The fresh copy expires; the longer lived one stays.
		foreach ( array_keys( Agend_Test_WP::$transients ) as $key ) {
			if ( ! str_ends_with( $key, '_stale' ) && str_contains( $key, 'loop_channels' ) ) {
				unset( Agend_Test_WP::$transients[ $key ] );
			}
		}

		Agend_Test_WP::queue_response( 500, array( 'error' => array( 'message' => 'Down.' ) ) );
		self::assertSame( array( 'chan-general' ), array_column( agend_apps_loop_get_channels(), 'id' ) );
	}

	#[Test]
	public function should_stop_calling_the_gateway_at_the_sites_ceiling_and_serve_what_it_has(): void {
		Agend_Test_WP::set_filter( 'agend_apps_community_call_ceiling', 1 );
		$general = agend_cm_test_channel( 'general', array( 'channel_type' => 'feed' ) );
		agend_cm_test_allow( array( $general ) );
		agend_cm_test_queue_channels( array( $general ) );
		agend_cm_test_queue_announcements( array() );

		$posts = agend_apps_loop_get_public_announcements();

		self::assertInstanceOf( WP_Error::class, $posts, 'the one call went to the channel list; the posts have no copy to fall back on' );
		self::assertSame( 'agend_apps_community_busy', $posts->get_error_code() );
		self::assertCount( 1, Agend_Test_WP::$requests, 'the gateway was not asked a second time' );
	}

	// -----------------------------------------------------------------
	// Announcements
	// -----------------------------------------------------------------

	#[Test]
	public function should_return_the_titled_posts_of_allowed_channels_and_nothing_else(): void {
		$news  = agend_cm_test_channel( 'news', array( 'channel_type' => 'feed' ) );
		$other = agend_cm_test_channel( 'staff-room', array( 'channel_type' => 'feed' ) );
		agend_cm_test_allow( array( $news ) );
		agend_cm_test_queue_channels( array( $news, $other ) );
		agend_cm_test_queue_announcements(
			array(
				agend_cm_test_post( 'p1', $news, "## Open day\n\nCome along." ),
				agend_cm_test_post( 'p2', $other, "## Salaries\n\nBoard only." ),
				agend_cm_test_post( 'p3', $news, 'just chatting', array(), '' ),
			)
		);

		$posts = agend_apps_loop_get_public_announcements();

		self::assertSame( array( 'p1' ), array_column( $posts, 'id' ) );
		self::assertSame( 'Open day', $posts[0]['title'] );
		self::assertStringNotContainsString( 'Salaries', $this->stored() );
		self::assertStringNotContainsString( 'just chatting', $this->stored() );
	}

	#[Test]
	public function should_never_store_an_authors_id_or_a_posts_html(): void {
		$news = agend_cm_test_channel( 'news', array( 'channel_type' => 'feed' ) );
		agend_cm_test_allow( array( $news ) );
		agend_cm_test_queue_channels( array( $news ) );
		agend_cm_test_queue_announcements( array( agend_cm_test_post( 'p1', $news, "## Open day\n\nCome along." ) ) );

		$posts = agend_apps_loop_get_public_announcements();

		self::assertArrayNotHasKey( 'content_html', $posts[0] );
		self::assertSame( array( 'name', 'avatar' ), array_keys( $posts[0]['author'] ), 'a name and a photo address for a site that chose to show them, and no id' );
		self::assertStringNotContainsString( 'user-secret-1', $this->stored() );
		self::assertStringNotContainsString( '<p>', $this->stored() );
	}

	#[Test]
	public function should_scrub_mentions_before_anything_is_stored(): void {
		$news = agend_cm_test_channel( 'news', array( 'channel_type' => 'feed' ) );
		agend_cm_test_allow( array( $news ) );
		agend_cm_test_queue_channels( array( $news ) );
		agend_cm_test_queue_announcements( array( agend_cm_test_post( 'p1', $news, "## Thanks @sarah.mitchell\n\nAnd @[8f14e45f-ceea-467f-a8f0-2f3f9a3b6c11]." ) ) );

		$post = agend_apps_loop_get_public_announcements()[0];

		self::assertSame( 'Thanks a member', $post['title'] );
		self::assertSame( 'And a member.', $post['excerpt'] );
		self::assertStringNotContainsString( 'sarah.mitchell', $this->stored() );
		self::assertStringNotContainsString( '8f14e45f', $this->stored() );
	}

	#[Test]
	public function should_drop_system_messages_and_posts_written_by_a_bot(): void {
		$news = agend_cm_test_channel( 'news', array( 'channel_type' => 'feed' ) );
		agend_cm_test_allow( array( $news ) );
		agend_cm_test_queue_channels( array( $news ) );

		$by_bot    = agend_cm_test_post( 'bot', $news, "## Automated\n\nText." );
		$by_bot['author']['display_name'] = 'Agend Workflows Bot';
		$by_system = agend_cm_test_post( 'sys', $news, "## From the system\n\nText." );
		$by_system['author']['id'] = '00000000-0000-0000-0000-000000000001';
		$joined    = agend_cm_test_post( 'joined', $news, 'Members joined the channel.' );

		agend_cm_test_queue_announcements( array( $by_bot, $by_system, $joined, agend_cm_test_post( 'real', $news, "## Real news\n\nBody." ) ) );

		self::assertSame( array( 'real' ), array_column( agend_apps_loop_get_public_announcements(), 'id' ) );
	}

	#[Test]
	public function should_call_a_channel_by_its_current_name_not_the_one_on_the_post(): void {
		$news = agend_cm_test_channel( 'news', array( 'channel_type' => 'feed', 'name' => 'Member News' ) );
		agend_cm_test_allow( array( $news ) );
		agend_cm_test_queue_channels( array( $news ) );
		$post = agend_cm_test_post( 'p1', array( 'id' => $news['id'], 'slug' => 'old-slug', 'name' => 'Old name' ), "## News\n\nBody." );
		agend_cm_test_queue_announcements( array( $post ) );

		$channel = agend_apps_loop_get_public_announcements()[0]['channel'];

		self::assertSame( 'news', $channel['slug'] );
		self::assertSame( 'Member News', $channel['name'] );
	}

	#[Test]
	public function should_keep_a_hidden_post_off_the_list_by_option_and_by_filter(): void {
		$news = agend_cm_test_channel( 'news', array( 'channel_type' => 'feed' ) );
		agend_cm_test_allow( array( $news ) );
		update_option( AGEND_APPS_COMMUNITY_OPTION_HIDDEN, array( 'p1' ) );
		add_filter(
			'agend_apps_community_exclude_post',
			static fn( bool $hidden, array $post ): bool => $hidden || 'Filtered' === $post['title'],
			10,
			2
		);
		agend_cm_test_queue_channels( array( $news ) );
		agend_cm_test_queue_announcements(
			array(
				agend_cm_test_post( 'p1', $news, "## Hidden by option\n\nx" ),
				agend_cm_test_post( 'p2', $news, "## Filtered\n\nx" ),
				agend_cm_test_post( 'p3', $news, "## Shown\n\nx" ),
			)
		);

		self::assertSame( array( 'p3' ), array_column( agend_apps_loop_get_public_announcements(), 'id' ) );
	}

	// -----------------------------------------------------------------
	// Activity
	// -----------------------------------------------------------------

	#[Test]
	public function should_read_each_allowed_channel_on_its_own_so_a_busy_one_cannot_crowd_out_the_rest(): void {
		$a = agend_cm_test_channel( 'alpha' );
		$b = agend_cm_test_channel( 'beta' );
		agend_cm_test_allow( array( $a, $b ) );
		agend_cm_test_queue_channels( array( $a, $b ) );
		agend_cm_test_queue_activity( array( agend_cm_test_post( 'a1', $a, "## Alpha one\n\nx", array( 'created_at' => '2026-11-10T00:00:00+00:00' ) ) ) );
		agend_cm_test_queue_activity( array( agend_cm_test_post( 'b1', $b, "## Beta one\n\nx", array( 'created_at' => '2026-11-12T00:00:00+00:00' ) ) ) );

		$posts = agend_apps_loop_get_public_activity();

		self::assertSame( array( 'b1', 'a1' ), array_column( $posts, 'id' ), 'merged, newest first' );
		self::assertSame( array( '/loop/channels', '/loop/public/activity', '/loop/public/activity' ), agend_cm_test_paths() );
		self::assertStringContainsString( 'channel_slug=alpha', Agend_Test_WP::$requests[1]['url'] );
		self::assertStringContainsString( 'channel_slug=beta', Agend_Test_WP::$requests[2]['url'] );
		self::assertStringContainsString( 'limit=50', Agend_Test_WP::$requests[1]['url'] );
	}

	#[Test]
	public function should_read_at_most_six_channels(): void {
		$channels = array();

		foreach ( range( 1, 8 ) as $n ) {
			$channels[] = agend_cm_test_channel( 'c' . $n );
		}

		agend_cm_test_allow( $channels );
		agend_cm_test_queue_channels( $channels );

		agend_apps_loop_get_public_activity();

		self::assertCount( 1 + AGEND_APPS_COMMUNITY_MAX_CHANNEL_READS, Agend_Test_WP::$requests );
		self::assertSame( 6, AGEND_APPS_COMMUNITY_MAX_CHANNEL_READS );
	}

	#[Test]
	public function should_read_only_the_chosen_channels_and_ignore_one_that_is_not_allowed(): void {
		$a = agend_cm_test_channel( 'alpha' );
		$b = agend_cm_test_channel( 'beta' );
		agend_cm_test_allow( array( $a ) );
		agend_cm_test_queue_channels( array( $a, $b ) );
		agend_cm_test_queue_activity( array( agend_cm_test_post( 'a1', $a, "## Alpha one\n\nx" ) ) );

		$posts = agend_apps_loop_get_public_activity( array( 'channel_ids' => array( 'chan-beta', 'chan-alpha' ) ) );

		self::assertSame( array( 'a1' ), array_column( $posts, 'id' ) );
		self::assertCount( 2, Agend_Test_WP::$requests, 'the channel list and alpha; never beta' );
	}

	#[Test]
	public function should_keep_only_a_channels_own_posts_from_its_own_read(): void {
		$a = agend_cm_test_channel( 'alpha' );
		$b = agend_cm_test_channel( 'beta' );
		agend_cm_test_allow( array( $a ) );
		agend_cm_test_queue_channels( array( $a, $b ) );
		agend_cm_test_queue_activity(
			array(
				agend_cm_test_post( 'a1', $a, "## Alpha one\n\nx" ),
				agend_cm_test_post( 'b1', $b, "## Beta leaked\n\nx" ),
			)
		);

		self::assertSame( array( 'a1' ), array_column( agend_apps_loop_get_public_activity(), 'id' ) );
	}

	#[Test]
	public function should_leave_a_gap_for_a_channel_that_failed_and_fail_only_when_all_do(): void {
		$a = agend_cm_test_channel( 'alpha' );
		$b = agend_cm_test_channel( 'beta' );
		agend_cm_test_allow( array( $a, $b ) );
		agend_cm_test_queue_channels( array( $a, $b ) );
		agend_cm_test_queue_activity( array( agend_cm_test_post( 'a1', $a, "## Alpha one\n\nx" ) ) );
		Agend_Test_WP::queue_response( 500, array( 'error' => array( 'message' => 'Down.' ) ) );

		self::assertSame( array( 'a1' ), array_column( agend_apps_loop_get_public_activity(), 'id' ) );

		Agend_Test_WP::reset();
		Agend_Test_WP::set_filter( 'agend_apps_api_parallel', false );
		agend_cm_test_allow( array( $a, $b ) );
		agend_cm_test_queue_channels( array( $a, $b ) );
		Agend_Test_WP::queue_response( 500, array( 'error' => array( 'message' => 'Down.' ) ) );
		Agend_Test_WP::queue_response( 500, array( 'error' => array( 'message' => 'Down.' ) ) );

		self::assertInstanceOf( WP_Error::class, agend_apps_loop_get_public_activity() );
	}

	// -----------------------------------------------------------------
	// Normalising
	// -----------------------------------------------------------------

	#[Test]
	public function should_tell_a_titled_post_from_chat_by_its_post_type(): void {
		$channel = agend_cm_test_channel( 'general' );

		$update = agend_apps_community_normalise_post( agend_cm_test_post( 'u', $channel, "## Update\n\nx", array(), 'update' ) );
		$chat   = agend_apps_community_normalise_post( agend_cm_test_post( 'c', $channel, 'Hello there everyone.', array(), '' ) );
		$feed   = agend_apps_community_normalise_post( agend_cm_test_post( 'f', $channel, 'Congrats on the certificate!', array( 'type' => 'feed_post', 'post_type' => null ), '' ) );

		self::assertTrue( $update['titled'] );
		self::assertSame( 'update', $update['kind'] );
		self::assertFalse( $chat['titled'] );
		self::assertFalse( $feed['titled'], 'a plain feed post has no post type, so it is not a titled post' );
	}

	#[Test]
	public function should_drop_a_post_with_no_words_or_no_channel(): void {
		$channel = agend_cm_test_channel( 'general' );

		self::assertNull( agend_apps_community_normalise_post( agend_cm_test_post( 'e', $channel, "   \n" ) ) );
		self::assertNull( agend_apps_community_normalise_post( array( 'id' => 'x', 'content' => 'Words.' ) ) );
		self::assertNull( agend_apps_community_normalise_post( 'not a post' ) );
	}

	#[Test]
	public function should_only_keep_a_photo_address_that_is_a_web_address(): void {
		$channel = agend_cm_test_channel( 'general' );
		$post    = agend_cm_test_post( 'p', $channel, "## Hi\n\nx" );

		$post['author']['avatar_url'] = 'javascript:alert(1)';
		self::assertSame( '', agend_apps_community_normalise_post( $post )['author']['avatar'] );

		$post['author']['avatar_url'] = 'https://cdn.example.test/a.png';
		self::assertSame( 'https://cdn.example.test/a.png', agend_apps_community_normalise_post( $post )['author']['avatar'] );
	}

	// -----------------------------------------------------------------
	// Settings screen read, log
	// -----------------------------------------------------------------

	#[Test]
	public function should_list_every_public_channel_for_an_administrator_and_nothing_for_anyone_else(): void {
		$rows = array( agend_cm_test_channel( 'general' ), agend_cm_test_channel( 'board', array( 'channel_type' => 'committee' ) ) );

		$GLOBALS['agend_test_current_user_can']['manage_options'] = false;

		self::assertSame( array(), agend_apps_community_admin_channel_choices() );
		self::assertCount( 0, Agend_Test_WP::$requests );

		$GLOBALS['agend_test_current_user_can']['manage_options'] = true;
		agend_cm_test_queue_channels( $rows );

		self::assertSame( array( 'chan-general' ), array_column( agend_apps_community_admin_channel_choices(), 'id' ), 'a committee is never offered' );
		self::assertStringNotContainsString( 'general', $this->stored(), 'the choice list is never stored' );
	}

	#[Test]
	public function should_keep_what_members_wrote_out_of_the_api_log(): void {
		$paths = Agend_Test_WP::apply_registered( 'agend_apps_log_body_excluded_paths', array() );

		self::assertContains( '/loop/public/activity', $paths );
		self::assertContains( '/loop/public/announcements', $paths );
	}
}
