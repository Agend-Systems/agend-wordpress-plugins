<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\AppsCore;

use Agend_Apps_Community_Admin;
use Agend_Apps_Endpoint_Scopes;
use Agend_Apps_Logger;
use Agend_Test_WP;
use Agend_Test_WP_Die_Exception;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/logging/class-agend-apps-log-redactor.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/logging/class-agend-apps-log-store.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/logging/class-agend-apps-logger.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/health.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-http-batch.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-key-scopes.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-endpoint-scopes.php';
require_once __DIR__ . '/fixtures/memory-log-store.php';

/**
 * The Community section of the Agend Apps settings page: the one place an
 * administrator decides which channels the website may show.
 *
 * Each case runs in its own process: the real read wrappers are declared here.
 */
#[RunTestsInSeparateProcesses]
final class CommunityAdminTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/admin/class-agend-apps-admin.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/admin/class-agend-apps-community-admin.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/loop-public.php';
		require_once __DIR__ . '/fixtures/community-stubs.php';
		Agend_Apps_Logger::set_instance( new Agend_Apps_Logger( new MemoryLogStore() ) );
		Agend_Apps_Endpoint_Scopes::set_map( null );
		Agend_Test_WP::set_filter( 'agend_apps_api_parallel', false );
		unset( $GLOBALS['wp_settings_sections'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wp_settings_sections'] );
		Agend_Apps_Endpoint_Scopes::set_map( null );
		parent::tearDown();
	}

	private function field(): string {
		ob_start();
		( new Agend_Apps_Community_Admin() )->render_channels_field();

		return (string) ob_get_clean();
	}

	#[Test]
	public function should_add_a_community_section_with_its_fields_to_the_agend_apps_page(): void {
		( new Agend_Apps_Community_Admin() )->register_settings();

		$section = Agend_Test_WP::$settings_sections['agend-apps']['agend_apps_community_section'];

		self::assertSame( 'Community', $section['title'] );
		self::assertSame( array( 'agend_apps_community_channels', 'agend_apps_community_sso_connection' ), array_keys( $section['fields'] ) );
		self::assertSame( 'Channels shown on the website', $section['fields']['agend_apps_community_channels']['title'] );
		self::assertSame( 'Single sign-on connection for My Communities', $section['fields']['agend_apps_community_sso_connection']['title'] );
	}

	#[Test]
	public function should_show_the_saved_sso_connection_slug_escaped_in_its_field(): void {
		update_option( 'agend_apps_community_sso_connection', 'wp-saml-abc' );

		ob_start();
		( new Agend_Apps_Community_Admin() )->render_sso_connection_field();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'name="agend_apps_community_sso_connection"', $html );
		self::assertStringContainsString( 'value="wp-saml-abc"', $html );
	}

	#[Test]
	public function should_sit_above_the_cache_settings_rather_than_below_every_cache_timer(): void {
		$GLOBALS['wp_settings_sections']['agend-apps'] = array(
			'agend_apps_api_section'     => array( 'id' => 'agend_apps_api_section' ),
			'agend_apps_captcha_section' => array( 'id' => 'agend_apps_captcha_section' ),
			'agend_apps_cache_section'   => array( 'id' => 'agend_apps_cache_section' ),
			'agend_apps_community_section' => array( 'id' => 'agend_apps_community_section' ),
		);

		( new Agend_Apps_Community_Admin() )->register_settings();

		self::assertSame(
			array( 'agend_apps_api_section', 'agend_apps_captcha_section', 'agend_apps_community_section', 'agend_apps_cache_section' ),
			array_keys( $GLOBALS['wp_settings_sections']['agend-apps'] )
		);
	}

	#[Test]
	public function should_explain_that_allowed_channels_are_public_to_anyone_on_the_internet(): void {
		ob_start();
		( new Agend_Apps_Community_Admin() )->render_section();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Nothing is shown until you allow a channel here.', $html );
		self::assertStringContainsString( 'Anyone who visits your website can read what is in an allowed channel', $html );
		self::assertStringContainsString( 'That includes any names written in the text of posts.', $html );
	}

	#[Test]
	public function should_list_public_channels_to_choose_from_with_the_allowed_ones_ticked(): void {
		$general = agend_cm_test_channel( 'general', array( 'description' => 'Chat <b>here</b> & there' ) );
		$jobs    = agend_cm_test_channel( 'jobs' );
		$board   = agend_cm_test_channel( 'board', array( 'channel_type' => 'committee' ) );
		$private = agend_cm_test_channel( 'staff', array( 'privacy' => 'private' ) );
		update_option( AGEND_APPS_COMMUNITY_OPTION_CHANNELS, array( 'chan-jobs' ) );
		agend_cm_test_queue_channels( array( $jobs, $general, $board, $private ) );

		$html = $this->field();

		self::assertStringContainsString( 'name="agend_apps_community_channels[]" value="chan-jobs" checked=\'checked\'>', $html );
		self::assertStringContainsString( 'name="agend_apps_community_channels[]" value="chan-general">', $html );
		self::assertStringNotContainsString( 'chan-board', $html, 'a committee is never offered' );
		self::assertStringNotContainsString( 'chan-staff', $html, 'a private channel is never offered' );
		self::assertLessThan( strpos( $html, 'value="chan-jobs"' ), strpos( $html, 'value="chan-general"' ), 'by name' );
		self::assertStringContainsString( 'Chat here &amp; there', $html );
		self::assertStringContainsString( 'Only public channels are listed.', $html );
		self::assertStringContainsString( 'Clear community content', $html );
	}

	#[Test]
	public function should_keep_the_saved_choice_when_the_channels_cannot_be_loaded(): void {
		update_option( AGEND_APPS_COMMUNITY_OPTION_CHANNELS, array( 'chan-jobs', 'chan-general' ) );
		Agend_Test_WP::queue_response( 500, array( 'error' => array( 'message' => 'Down.' ) ) );

		$html = $this->field();

		self::assertStringContainsString( 'Your saved choice is kept as it is.', $html );
		self::assertStringNotContainsString( 'type="checkbox"', $html );
		self::assertStringContainsString( '<input type="hidden" name="agend_apps_community_channels[]" value="chan-jobs">', $html );
		self::assertStringContainsString( '<input type="hidden" name="agend_apps_community_channels[]" value="chan-general">', $html );
	}

	#[Test]
	public function should_say_so_when_a_saved_channel_has_gone(): void {
		update_option( AGEND_APPS_COMMUNITY_OPTION_CHANNELS, array( 'chan-jobs', 'chan-deleted' ) );
		agend_cm_test_queue_channels( array( agend_cm_test_channel( 'jobs' ) ) );

		self::assertStringContainsString( '1 saved channel is no longer available', $this->field() );
	}

	#[Test]
	public function should_say_so_when_the_community_has_no_public_channel(): void {
		agend_cm_test_queue_channels( array() );

		$html = $this->field();

		self::assertStringContainsString( 'There are no public channels in your community yet.', $html );
		self::assertStringNotContainsString( 'type="checkbox"', $html );
	}

	#[Test]
	public function should_refuse_to_clear_content_for_anyone_but_an_administrator(): void {
		$GLOBALS['agend_test_current_user_can']['manage_options'] = false;

		$this->expectException( Agend_Test_WP_Die_Exception::class );

		try {
			( new Agend_Apps_Community_Admin() )->handle_clear_cache();
		} finally {
			self::assertSame( array(), Agend_Test_WP::$redirects );
		}
	}

	#[Test]
	public function should_clear_every_community_cache_the_cache_tab_lists(): void {
		$cache = (string) file_get_contents( AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-cache.php' );
		$wrap  = (string) file_get_contents( AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/loop-public.php' );

		foreach ( array( 'loop_channels', 'loop_public_announcements', 'loop_public_activity', 'loop_communities' ) as $key ) {
			self::assertStringContainsString( "'" . $key . "' ", $cache, $key . ' is on the Cache tab' );
			self::assertStringContainsString( "'" . $key . "'", $wrap, $key . ' is cleared by the button' );
		}
	}
}
